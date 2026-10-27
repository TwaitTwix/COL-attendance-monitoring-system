<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $data): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    respond(405, [
        'success' => false,
        'message' => 'Only GET requests are allowed.',
    ]);
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/authorization.php';

    $admin = requireAuthorizedUser($pdo, ['administrator', 'event_officer']);
    $eventId = trim((string) ($_GET['event_id'] ?? ''));
    $sessionId = trim((string) ($_GET['session_id'] ?? ''));

    $baseWhere = [];
    $params = [];

    if ($eventId !== '') {
        $baseWhere[] = 'e.id = :event_id';
        $params['event_id'] = $eventId;
    }

    if ($sessionId !== '') {
        $baseWhere[] = 'es.id = :session_id';
        $params['session_id'] = $sessionId;
    }

    $whereClause = $baseWhere === [] ? '' : ' WHERE ' . implode(' AND ', $baseWhere);

    $summarySql = "
        SELECT
            COUNT(DISTINCT e.id) AS total_events,
            COUNT(DISTINCT es.id) AS total_sessions,
            COUNT(DISTINCT ar.student_id) AS total_students_with_records,
            COUNT(ar.id) AS total_attendance_records
        FROM public.events e
        LEFT JOIN public.event_sessions es ON es.event_id = e.id
        LEFT JOIN public.attendance_records ar ON ar.session_id = es.id
        {$whereClause}
    ";

    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $statusSql = "
        SELECT
            ar.status,
            COUNT(*) AS total
        FROM public.attendance_records ar
        INNER JOIN public.event_sessions es ON es.id = ar.session_id
        INNER JOIN public.events e ON e.id = es.event_id
        {$whereClause}
        GROUP BY ar.status
        ORDER BY total DESC, ar.status ASC
    ";

    $statusStmt = $pdo->prepare($statusSql);
    $statusStmt->execute($params);
    $statusBreakdown = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    $eventSql = "
        SELECT
            e.id,
            e.title,
            e.status,
            COUNT(DISTINCT es.id) AS session_count,
            COUNT(DISTINCT ar.student_id) AS student_count,
            COUNT(ar.id) AS record_count
        FROM public.events e
        LEFT JOIN public.event_sessions es ON es.event_id = e.id
        LEFT JOIN public.attendance_records ar ON ar.session_id = es.id
        {$whereClause}
        GROUP BY e.id, e.title, e.status
        ORDER BY e.created_at DESC
    ";

    $eventStmt = $pdo->prepare($eventSql);
    $eventStmt->execute($params);
    $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

    $payload = [
        'success' => true,
        'filters' => [
            'event_id' => $eventId !== '' ? $eventId : null,
            'session_id' => $sessionId !== '' ? $sessionId : null,
        ],
        'summary' => [
            'total_events' => (int) ($summary['total_events'] ?? 0),
            'total_sessions' => (int) ($summary['total_sessions'] ?? 0),
            'total_students_with_records' => (int) ($summary['total_students_with_records'] ?? 0),
            'total_attendance_records' => (int) ($summary['total_attendance_records'] ?? 0),
        ],
        'status_breakdown' => $statusBreakdown,
        'events' => $events,
    ];

    respond(200, $payload);
} catch (RuntimeException $e) {
    error_log('Attendance summary authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Attendance summary error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The attendance summary could not be generated.',
    ]);
}
