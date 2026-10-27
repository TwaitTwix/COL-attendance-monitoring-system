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

    $whereClauses = [];
    $params = [];

    if ($eventId !== '') {
        $whereClauses[] = 'e.id = :event_id';
        $params['event_id'] = $eventId;
    }

    if ($sessionId !== '') {
        $whereClauses[] = 'es.id = :session_id';
        $params['session_id'] = $sessionId;
    }

    $whereSql = $whereClauses === [] ? '' : ' WHERE ' . implode(' AND ', $whereClauses);

    $activeStatsSql = "
        SELECT
            (SELECT COUNT(*) FROM public.events e {$whereSql}) AS total_events,
            (SELECT COUNT(*) FROM public.event_sessions es
             INNER JOIN public.events e ON e.id = es.event_id
             {$whereSql}) AS total_sessions,
            (SELECT COUNT(*) FROM public.profiles p WHERE p.approval_status = 'approved' AND p.account_status = 'active') AS total_active_students,
            (SELECT COUNT(*) FROM public.user_roles ur INNER JOIN public.roles r ON r.id = ur.role_id WHERE r.role_key = 'administrator') AS total_admins
    ";

    $activeStatsStmt = $pdo->prepare($activeStatsSql);
    $activeStatsStmt->execute($params);
    $stats = $activeStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $attendanceSql = "
        SELECT
            ar.status,
            COUNT(*) AS total
        FROM public.attendance_records ar
        INNER JOIN public.event_sessions es ON es.id = ar.session_id
        INNER JOIN public.events e ON e.id = es.event_id
        {$whereSql}
        GROUP BY ar.status
        ORDER BY ar.status ASC
    ";

    $attendanceStmt = $pdo->prepare($attendanceSql);
    $attendanceStmt->execute($params);
    $attendanceSeries = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);

    $recentEventsSql = "
        SELECT
            e.id,
            e.title,
            e.status,
            e.location_name,
            e.created_at,
            COUNT(DISTINCT es.id) AS session_count,
            COUNT(DISTINCT ar.student_id) AS student_count
        FROM public.events e
        LEFT JOIN public.event_sessions es ON es.event_id = e.id
        LEFT JOIN public.attendance_records ar ON ar.session_id = es.id
        {$whereSql}
        GROUP BY e.id, e.title, e.status, e.location_name, e.created_at
        ORDER BY e.created_at DESC
        LIMIT 10
    ";

    $recentEventsStmt = $pdo->prepare($recentEventsSql);
    $recentEventsStmt->execute($params);
    $recentEvents = $recentEventsStmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'filters' => [
            'event_id' => $eventId !== '' ? $eventId : null,
            'session_id' => $sessionId !== '' ? $sessionId : null,
        ],
        'stats' => [
            'total_events' => (int) ($stats['total_events'] ?? 0),
            'total_sessions' => (int) ($stats['total_sessions'] ?? 0),
            'total_active_students' => (int) ($stats['total_active_students'] ?? 0),
            'total_admins' => (int) ($stats['total_admins'] ?? 0),
        ],
        'attendance_status_breakdown' => $attendanceSeries,
        'recent_events' => $recentEvents,
    ]);
} catch (RuntimeException $e) {
    error_log('Dashboard overview authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Dashboard overview error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The dashboard overview could not be loaded.',
    ]);
}
