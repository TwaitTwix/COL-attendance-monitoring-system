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
    $recipientId = trim((string) ($_GET['recipient_id'] ?? ''));
    $unreadOnly = filter_var($_GET['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $whereClauses = [];
    $params = [];

    if ($eventId !== '') {
        $whereClauses[] = 'n.event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    if ($recipientId !== '') {
        $whereClauses[] = 'n.recipient_id = :recipient_id';
        $params['recipient_id'] = $recipientId;
    }

    if ($unreadOnly) {
        $whereClauses[] = 'n.read_at IS NULL';
    }

    $whereSql = $whereClauses === [] ? '' : ' WHERE ' . implode(' AND ', $whereClauses);

    $sql = "
        SELECT
            n.id,
            n.recipient_id,
            n.event_id,
            n.notification_type,
            n.title,
            n.message,
            n.action_url,
            n.read_at,
            n.created_at,
            n.metadata
        FROM public.notifications n
        {$whereSql}
        ORDER BY n.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'count' => count($rows),
        'rows' => $rows,
    ]);
} catch (RuntimeException $e) {
    error_log('Admin notifications authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Admin notifications error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The notifications list could not be loaded.',
    ]);
}
