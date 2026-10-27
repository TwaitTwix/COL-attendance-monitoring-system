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

function normalizeOptionalDate(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $normalized = trim($value);

    try {
        $date = new DateTimeImmutable($normalized);
        return $date->format('Y-m-d\TH:i:sP');
    } catch (Throwable $e) {
        throw new InvalidArgumentException('The date value is not valid ISO 8601 format.');
    }
}

function validateCoordinate($value, string $fieldName): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException($fieldName . ' must be numeric.');
    }

    $number = (float) $value;

    if ($fieldName === 'location_latitude' && ($number < -90 || $number > 90)) {
        throw new InvalidArgumentException('location_latitude must be between -90 and 90.');
    }

    if ($fieldName === 'location_longitude' && ($number < -180 || $number > 180)) {
        throw new InvalidArgumentException('location_longitude must be between -180 and 180.');
    }

    return $number;
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

    $pdo = $pdo ?? null;
    $admin = requireAuthorizedUser($pdo, ['administrator']);
    $adminId = (string) ($admin['id'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sql = "
            SELECT
                id,
                title,
                description,
                event_code,
                location_name,
                location_latitude,
                location_longitude,
                geofence_radius_m,
                status,
                eligibility_mode,
                timezone,
                registration_opens_at,
                registration_closes_at,
                created_by,
                published_at,
                created_at,
                updated_at
            FROM public.events
            ORDER BY created_at DESC
        ";

        $stmt = $pdo->query($sql);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond(200, [
            'success' => true,
            'count' => count($events),
            'events' => $events,
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

    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        respond(422, [
            'success' => false,
            'message' => 'Event title is required.',
        ]);
    }

    $description = trim((string) ($input['description'] ?? ''));
    $eventCode = trim((string) ($input['event_code'] ?? ''));
    $locationName = trim((string) ($input['location_name'] ?? ''));
    $status = strtolower(trim((string) ($input['status'] ?? 'draft')));
    $timezone = trim((string) ($input['timezone'] ?? 'Asia/Manila'));
    $eligibilityMode = trim((string) ($input['eligibility_mode'] ?? 'all'));

    $allowedStatuses = ['draft', 'published', 'cancelled', 'completed', 'archived'];
    if (!in_array($status, $allowedStatuses, true)) {
        respond(422, [
            'success' => false,
            'message' => 'Event status must be one of: ' . implode(', ', $allowedStatuses) . '.',
        ]);
    }

    if ($timezone === '') {
        $timezone = 'Asia/Manila';
    }

    try {
        new DateTimeZone($timezone);
    } catch (Throwable $e) {
        respond(422, [
            'success' => false,
            'message' => 'The supplied timezone is invalid.',
        ]);
    }

    $registrationOpensAt = normalizeOptionalDate((string) ($input['registration_opens_at'] ?? ''));
    $registrationClosesAt = normalizeOptionalDate((string) ($input['registration_closes_at'] ?? ''));

    if ($registrationOpensAt !== null && $registrationClosesAt !== null) {
        $opens = new DateTimeImmutable($registrationOpensAt);
        $closes = new DateTimeImmutable($registrationClosesAt);

        if ($closes <= $opens) {
            respond(422, [
                'success' => false,
                'message' => 'registration_closes_at must be later than registration_opens_at.',
            ]);
        }
    }

    $lat = validateCoordinate($input['location_latitude'] ?? null, 'location_latitude');
    $lng = validateCoordinate($input['location_longitude'] ?? null, 'location_longitude');
    $radius = $input['geofence_radius_m'] ?? 0;

    if (!is_numeric($radius) || (float) $radius < 0) {
        respond(422, [
            'success' => false,
            'message' => 'geofence_radius_m must be a non-negative number.',
        ]);
    }

    $geofenceRadius = (int) round((float) $radius);

    if ($geofenceRadius > 0 && ($lat === null || $lng === null)) {
        respond(422, [
            'success' => false,
            'message' => 'location_latitude and location_longitude are required when geofence_radius_m is greater than 0.',
        ]);
    }

    if ($eventCode === '') {
        $eventCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    $eventId = $pdo->query('SELECT gen_random_uuid()')->fetchColumn();

    $insertSql = "
        INSERT INTO public.events (
            id,
            title,
            description,
            event_code,
            location_name,
            location_latitude,
            location_longitude,
            geofence_radius_m,
            status,
            eligibility_mode,
            timezone,
            registration_opens_at,
            registration_closes_at,
            created_by,
            published_at,
            created_at,
            updated_at
        ) VALUES (
            :id,
            :title,
            :description,
            :event_code,
            :location_name,
            :location_latitude,
            :location_longitude,
            :geofence_radius_m,
            :status,
            :eligibility_mode,
            :timezone,
            :registration_opens_at,
            :registration_closes_at,
            :created_by,
            :published_at,
            NOW(),
            NOW()
        )
    ";

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        'id' => $eventId,
        'title' => $title,
        'description' => $description !== '' ? $description : null,
        'event_code' => $eventCode,
        'location_name' => $locationName !== '' ? $locationName : null,
        'location_latitude' => $lat,
        'location_longitude' => $lng,
        'geofence_radius_m' => $geofenceRadius,
        'status' => $status,
        'eligibility_mode' => $eligibilityMode !== '' ? $eligibilityMode : 'all',
        'timezone' => $timezone,
        'registration_opens_at' => $registrationOpensAt,
        'registration_closes_at' => $registrationClosesAt,
        'created_by' => $adminId,
        'published_at' => $status === 'published' ? gmdate('Y-m-d\TH:i:sP') : null,
    ]);

    $createdEventSql = "
        SELECT
            id,
            title,
            description,
            event_code,
            location_name,
            location_latitude,
            location_longitude,
            geofence_radius_m,
            status,
            eligibility_mode,
            timezone,
            registration_opens_at,
            registration_closes_at,
            created_by,
            published_at,
            created_at,
            updated_at
        FROM public.events
        WHERE id = :id
        LIMIT 1
    ";

    $createdStmt = $pdo->prepare($createdEventSql);
    $createdStmt->execute(['id' => $eventId]);
    $createdEvent = $createdStmt->fetch(PDO::FETCH_ASSOC);

    respond(201, [
        'success' => true,
        'message' => 'Event created successfully.',
        'event' => $createdEvent,
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
    error_log('Admin events endpoint authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Admin events endpoint error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The event operation could not be completed.',
    ]);
}
