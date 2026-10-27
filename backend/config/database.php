<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

$host = envValue('DB_HOST');
$port = envValue('DB_PORT', '5432', true);
$database = envValue('DB_DATABASE', 'postgres', true);
$username = envValue('DB_USERNAME');
$password = envValue('DB_PASSWORD');
$sslMode = strtolower(trim((string) envValue('DB_SSLMODE', 'require', true)));

if ($sslMode === '') {
    $sslMode = in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? 'prefer' : 'require';
}

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
    $host,
    $port,
    $database,
    $sslMode
);

try {
    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 30,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());

    throw new RuntimeException(
        'Unable to connect to the database. Check your connection settings.'
    );
}

return $pdo;