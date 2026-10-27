<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);

    exit;
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/authorization.php';

try {
    // Only authenticated administrators can access this endpoint.
    requireAuthorizedUser($pdo, ['administrator']);

    // Retrieve student accounts waiting for approval.
    $sql = "
        SELECT
            id,
            student_id,
            full_name,
            email,
            department,
            program,
            year_level,
            section,
            created_at
        FROM public.profiles
        WHERE approval_status = 'pending'
        ORDER BY created_at ASC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);

    echo json_encode([
        'success' => true,
        'count' => count($users),
        'users' => $users
    ], JSON_PRETTY_PRINT);

} catch (RuntimeException $e) {
    error_log('Pending users endpoint authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('Pending users endpoint error: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while retrieving pending users.'
    ]);
}