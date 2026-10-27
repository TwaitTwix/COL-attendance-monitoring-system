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

    $student = requireAuthorizedUser($pdo, ['student']);
    $studentId = (string) ($student['id'] ?? '');

    $statsSql = "
        SELECT
            COUNT(DISTINCT ar.session_id) AS attended_sessions,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN ar.status = 'pending_review' THEN 1 ELSE 0 END) AS pending_review_count
        FROM public.attendance_records ar
        WHERE ar.student_id = :student_id
    ";

    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute(['student_id' => $studentId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $recentSql = "
        SELECT
            ar.id,
            e.title AS event_title,
            es.session_name,
            ar.status,
            ar.checkin_method,
            ar.checked_in_at
        FROM public.attendance_records ar
        INNER JOIN public.event_sessions es ON es.id = ar.session_id
        INNER JOIN public.events e ON e.id = es.event_id
        WHERE ar.student_id = :student_id
        ORDER BY ar.checked_in_at DESC NULLS LAST
        LIMIT 10
    ";

    $recentStmt = $pdo->prepare($recentSql);
    $recentStmt->execute(['student_id' => $studentId]);
    $recentRecords = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'stats' => [
            'attended_sessions' => (int) ($stats['attended_sessions'] ?? 0),
            'present_count' => (int) ($stats['present_count'] ?? 0),
            'late_count' => (int) ($stats['late_count'] ?? 0),
            'absent_count' => (int) ($stats['absent_count'] ?? 0),
            'pending_review_count' => (int) ($stats['pending_review_count'] ?? 0),
        ],
        'recent_records' => $recentRecords,
    ]);
} catch (RuntimeException $e) {
    error_log('Student dashboard authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Student dashboard error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The student dashboard could not be loaded.',
    ]);
}
