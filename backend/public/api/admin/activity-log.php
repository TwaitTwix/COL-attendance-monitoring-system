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

    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $entityType = trim((string) ($_GET['entity_type'] ?? ''));
    $actorId = trim((string) ($_GET['actor_id'] ?? ''));
    $eventId = trim((string) ($_GET['event_id'] ?? ''));

    $whereClauses = [];
    $params = [];

    if ($entityType !== '') {
        $whereClauses[] = 'al.entity_type = :entity_type';
        $params['entity_type'] = $entityType;
    }

    if ($actorId !== '') {
        $whereClauses[] = 'al.actor_id = :actor_id';
        $params['actor_id'] = $actorId;
    }

    if ($eventId !== '') {
        $whereClauses[] = 'al.event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    $whereSql = $whereClauses === [] ? '' : ' WHERE ' . implode(' AND ', $whereClauses);

    $sql = "
        SELECT
            al.id,
            al.entity_type,
            al.entity_id,
            al.event_id,
            al.actor_id,
            al.action,
            al.action_detail,
            al.created_at,
            p.full_name AS actor_name,
            p.email AS actor_email
        FROM public.audit_logs al
        LEFT JOIN public.profiles p ON p.id = al.actor_id
        {$whereSql}
        ORDER BY al.created_at DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'limit' => $limit,
        'count' => count($rows),
        'rows' => $rows,
    ]);
} catch (RuntimeException $e) {
    error_log('Activity log authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Activity log error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The activity log could not be loaded.',
    ]);
}
