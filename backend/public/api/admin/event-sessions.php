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

function normalizeDateTime(?string $value, string $fieldName): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable(trim($value));
        return $dt->format('Y-m-d\TH:i:sP');
    } catch (Throwable $e) {
        throw new InvalidArgumentException($fieldName . ' must be a valid ISO 8601 datetime.');
    }
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    header('Allow: GET, POST');

    respond(405, [
        'success' => false,
        'message' => 'Only GET and POST requests are allowed.',
    ]);
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/authorization.php';

    $admin = requireAuthorizedUser($pdo, ['administrator']);
    $adminId = (string) ($admin['id'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $eventId = trim((string) ($_GET['event_id'] ?? ''));

        if ($eventId === '') {
            respond(422, [
                'success' => false,
                'message' => 'The event_id query parameter is required.',
            ]);
        }

        $eventStmt = $pdo->prepare('SELECT id FROM public.events WHERE id = :event_id LIMIT 1');
        $eventStmt->execute(['event_id' => $eventId]);

        if (!$eventStmt->fetch(PDO::FETCH_ASSOC)) {
            respond(404, [
                'success' => false,
                'message' => 'No event was found for the supplied event_id.',
            ]);
        }

        $sql = "
            SELECT
                id,
                event_id,
                session_name,
                session_order,
                starts_at,
                ends_at,
                checkin_opens_at,
                checkin_closes_at,
                status,
                late_after_minutes,
                created_by,
                opened_by,
                opened_at,
                closed_by,
                closed_at,
                created_at,
                updated_at
            FROM public.event_sessions
            WHERE event_id = :event_id
            ORDER BY session_order ASC, starts_at ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['event_id' => $eventId]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond(200, [
            'success' => true,
            'count' => count($sessions),
            'sessions' => $sessions,
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

    $eventId = trim((string) ($input['event_id'] ?? ''));
    $sessionName = trim((string) ($input['session_name'] ?? ''));
    $status = strtolower(trim((string) ($input['status'] ?? 'scheduled')));
    $sessionOrder = (int) ($input['session_order'] ?? 1);
    $lateAfterMinutes = (int) ($input['late_after_minutes'] ?? 15);

    if ($eventId === '') {
        respond(422, [
            'success' => false,
            'message' => 'event_id is required.',
        ]);
    }

    if ($sessionName === '') {
        respond(422, [
            'success' => false,
            'message' => 'session_name is required.',
        ]);
    }

    $eventStmt = $pdo->prepare('SELECT id FROM public.events WHERE id = :event_id LIMIT 1');
    $eventStmt->execute(['event_id' => $eventId]);

    if (!$eventStmt->fetch(PDO::FETCH_ASSOC)) {
        respond(404, [
            'success' => false,
            'message' => 'No event was found for the supplied event_id.',
        ]);
    }

    $allowedStatuses = ['scheduled', 'open', 'paused', 'closed', 'cancelled'];
    if (!in_array($status, $allowedStatuses, true)) {
        respond(422, [
            'success' => false,
            'message' => 'status must be one of: ' . implode(', ', $allowedStatuses) . '.',
        ]);
    }

    if ($sessionOrder < 1) {
        respond(422, [
            'success' => false,
            'message' => 'session_order must be at least 1.',
        ]);
    }

    if ($lateAfterMinutes < 0) {
        respond(422, [
            'success' => false,
            'message' => 'late_after_minutes must be zero or greater.',
        ]);
    }

    $startsAt = normalizeDateTime((string) ($input['starts_at'] ?? ''), 'starts_at');
    $endsAt = normalizeDateTime((string) ($input['ends_at'] ?? ''), 'ends_at');
    $checkinOpensAt = normalizeDateTime((string) ($input['checkin_opens_at'] ?? null), 'checkin_opens_at');
    $checkinClosesAt = normalizeDateTime((string) ($input['checkin_closes_at'] ?? null), 'checkin_closes_at');

    if ($startsAt === null || $endsAt === null) {
        respond(422, [
            'success' => false,
            'message' => 'starts_at and ends_at are required.',
        ]);
    }

    $starts = new DateTimeImmutable($startsAt);
    $ends = new DateTimeImmutable($endsAt);

    if ($ends <= $starts) {
        respond(422, [
            'success' => false,
            'message' => 'ends_at must be later than starts_at.',
        ]);
    }

    if ($checkinOpensAt !== null && $checkinClosesAt !== null) {
        $checkinOpen = new DateTimeImmutable($checkinOpensAt);
        $checkinClose = new DateTimeImmutable($checkinClosesAt);

        if ($checkinClose <= $checkinOpen) {
            respond(422, [
                'success' => false,
                'message' => 'checkin_closes_at must be later than checkin_opens_at.',
            ]);
        }
    }

    $sessionId = $pdo->query('SELECT gen_random_uuid()')->fetchColumn();

    $insertSql = "
        INSERT INTO public.event_sessions (
            id,
            event_id,
            session_name,
            session_order,
            starts_at,
            ends_at,
            checkin_opens_at,
            checkin_closes_at,
            status,
            late_after_minutes,
            created_by,
            opened_by,
            opened_at,
            closed_by,
            closed_at,
            created_at,
            updated_at
        ) VALUES (
            :id,
            :event_id,
            :session_name,
            :session_order,
            :starts_at,
            :ends_at,
            :checkin_opens_at,
            :checkin_closes_at,
            :status,
            :late_after_minutes,
            :created_by,
            NULL,
            NULL,
            NULL,
            NULL,
            NOW(),
            NOW()
        )
    ";

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        'id' => $sessionId,
        'event_id' => $eventId,
        'session_name' => $sessionName,
        'session_order' => $sessionOrder,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'checkin_opens_at' => $checkinOpensAt,
        'checkin_closes_at' => $checkinClosesAt,
        'status' => $status,
        'late_after_minutes' => $lateAfterMinutes,
        'created_by' => $adminId,
    ]);

    $createdSql = "
        SELECT
            id,
            event_id,
            session_name,
            session_order,
            starts_at,
            ends_at,
            checkin_opens_at,
            checkin_closes_at,
            status,
            late_after_minutes,
            created_by,
            opened_by,
            opened_at,
            closed_by,
            closed_at,
            created_at,
            updated_at
        FROM public.event_sessions
        WHERE id = :id
        LIMIT 1
    ";

    $createdStmt = $pdo->prepare($createdSql);
    $createdStmt->execute(['id' => $sessionId]);
    $createdSession = $createdStmt->fetch(PDO::FETCH_ASSOC);

    respond(201, [
        'success' => true,
        'message' => 'Session created successfully.',
        'session' => $createdSession,
    ]);

} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
} catch (InvalidArgumentException $e) {
    respond(422, [
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (RuntimeException $e) {
    error_log('Admin event sessions endpoint authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Admin event sessions endpoint error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The session operation could not be completed.',
    ]);
}
