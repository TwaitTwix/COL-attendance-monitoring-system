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

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST', 'PATCH'], true)) {
    header('Allow: GET, POST, PATCH');

    respond(405, [
        'success' => false,
        'message' => 'Only GET, POST, and PATCH requests are allowed.',
    ]);
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/authorization.php';

    $user = requireAuthorizedUser($pdo, ['student', 'administrator', 'event_officer']);
    $userId = (string) ($user['id'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $unreadOnly = filter_var($_GET['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $sql = "
            SELECT
                id,
                recipient_id,
                event_id,
                notification_type,
                title,
                message,
                action_url,
                read_at,
                created_at,
                metadata
            FROM public.notifications
            WHERE recipient_id = :recipient_id
        ";

        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['recipient_id' => $userId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond(200, [
            'success' => true,
            'count' => count($notifications),
            'notifications' => $notifications,
        ]);
    }

    $input = json_decode(
        file_get_contents('php://input'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($input)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON request body.',
        ]);
    }

    $notificationId = trim((string) ($input['notification_id'] ?? ''));
    $markAllRead = (bool) ($input['mark_all_read'] ?? false);

    if (!$markAllRead && $notificationId === '') {
        respond(422, [
            'success' => false,
            'message' => 'notification_id is required unless mark_all_read is true.',
        ]);
    }

    if ($markAllRead) {
        $updateSql = "
            UPDATE public.notifications
            SET read_at = NOW()
            WHERE recipient_id = :recipient_id AND read_at IS NULL
        ";

        $stmt = $pdo->prepare($updateSql);
        $stmt->execute(['recipient_id' => $userId]);

        respond(200, [
            'success' => true,
            'message' => 'All notifications were marked as read.',
        ]);
    }

    $checkSql = "
        SELECT id
        FROM public.notifications
        WHERE id = :notification_id AND recipient_id = :recipient_id
        LIMIT 1
    ";

    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([
        'notification_id' => $notificationId,
        'recipient_id' => $userId,
    ]);

    if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
        respond(404, [
            'success' => false,
            'message' => 'No notification was found for the current user.',
        ]);
    }

    $updateSql = "
        UPDATE public.notifications
        SET read_at = NOW()
        WHERE id = :notification_id AND recipient_id = :recipient_id
    ";

    $stmt = $pdo->prepare($updateSql);
    $stmt->execute([
        'notification_id' => $notificationId,
        'recipient_id' => $userId,
    ]);

    respond(200, [
        'success' => true,
        'message' => 'Notification marked as read.',
        'notification_id' => $notificationId,
    ]);

} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
} catch (RuntimeException $e) {
    error_log('Notification endpoint authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Notification endpoint error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The notification operation could not be completed.',
    ]);
}
