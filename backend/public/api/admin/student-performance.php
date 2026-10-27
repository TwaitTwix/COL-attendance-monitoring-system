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
    $studentId = trim((string) ($_GET['student_id'] ?? ''));

    $whereClauses = [];
    $params = [];

    if ($eventId !== '') {
        $whereClauses[] = 'e.id = :event_id';
        $params['event_id'] = $eventId;
    }

    if ($studentId !== '') {
        $whereClauses[] = 'ar.student_id = :student_id';
        $params['student_id'] = $studentId;
    }

    $whereSql = $whereClauses === [] ? '' : ' WHERE ' . implode(' AND ', $whereClauses);

    $sql = "
        SELECT
            p.id AS student_id,
            p.full_name,
            p.email,
            p.program,
            p.year_level,
            p.section,
            e.id AS event_id,
            e.title AS event_title,
            COUNT(ar.id) AS attendance_count,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN ar.status = 'pending_review' THEN 1 ELSE 0 END) AS pending_review_count
        FROM public.profiles p
        LEFT JOIN public.attendance_records ar ON ar.student_id = p.id
        LEFT JOIN public.event_sessions es ON es.id = ar.session_id
        LEFT JOIN public.events e ON e.id = es.event_id
        {$whereSql}
        GROUP BY p.id, p.full_name, p.email, p.program, p.year_level, p.section, e.id, e.title
        ORDER BY p.full_name ASC, e.title ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'count' => count($rows),
        'rows' => $rows,
    ]);
} catch (RuntimeException $e) {
    error_log('Student performance authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Student performance error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The student performance report could not be generated.',
    ]);
}
