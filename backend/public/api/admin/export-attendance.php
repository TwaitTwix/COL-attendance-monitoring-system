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
    $format = strtolower(trim((string) ($_GET['format'] ?? 'json')));

    if (!in_array($format, ['json', 'csv'], true)) {
        respond(422, [
            'success' => false,
            'message' => 'format must be either json or csv.',
        ]);
    }

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

    $sql = "
        SELECT
            p.full_name,
            p.email,
            e.title AS event_title,
            es.session_name,
            es.starts_at AS session_starts_at,
            es.ends_at AS session_ends_at,
            ar.status AS attendance_status,
            ar.checkin_method,
            ar.checked_in_at,
            ar.location_verified,
            ar.distance_from_event_m,
            ar.verified_by,
            ar.manual_reason,
            ar.officer_notes
        FROM public.attendance_records ar
        INNER JOIN public.event_sessions es ON es.id = ar.session_id
        INNER JOIN public.events e ON e.id = es.event_id
        INNER JOIN public.profiles p ON p.id = ar.student_id
        {$whereSql}
        ORDER BY e.created_at DESC, es.starts_at ASC, p.full_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        $headers = [
            'full_name',
            'email',
            'event_title',
            'session_name',
            'session_starts_at',
            'session_ends_at',
            'attendance_status',
            'checkin_method',
            'checked_in_at',
            'location_verified',
            'distance_from_event_m',
            'verified_by',
            'manual_reason',
            'officer_notes',
        ];

        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);

        foreach ($rows as $row) {
            $record = [];
            foreach ($headers as $header) {
                $record[] = $row[$header] ?? '';
            }
            fputcsv($output, $record);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="attendance-export.csv"');

        echo $csv;
        exit;
    }

    respond(200, [
        'success' => true,
        'count' => count($rows),
        'rows' => $rows,
    ]);
} catch (RuntimeException $e) {
    error_log('Attendance export authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Attendance export error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The attendance export could not be generated.',
    ]);
}
