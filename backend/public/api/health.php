<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = require dirname(__DIR__, 2) . '/config/database.php';

    $statement = $pdo->query('SELECT 1');
    $databaseConnected = $statement->fetchColumn() == 1;

    http_response_code($databaseConnected ? 200 : 503);

    echo json_encode([
        'success' => $databaseConnected,
        'application' => 'University Attendance Monitoring System',
        'api_status' => 'running',
        'database_status' => $databaseConnected ? 'connected' : 'unavailable',
        'timestamp' => gmdate('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('Health check failed: ' . $e->getMessage());

    http_response_code(503);

    echo json_encode([
        'success' => false,
        'application' => 'University Attendance Monitoring System',
        'api_status' => 'running',
        'database_status' => 'unavailable',
        'message' => 'The database connection could not be verified.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}