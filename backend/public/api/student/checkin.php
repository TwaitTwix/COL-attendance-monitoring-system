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

function haversineDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371.0;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadiusKm * $c;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    respond(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.',
    ]);
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/authorization.php';

    $student = requireAuthorizedUser($pdo, ['student']);
    $studentId = (string) ($student['id'] ?? '');

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

    $sessionId = trim((string) ($input['session_id'] ?? ''));
    $token = trim((string) ($input['token'] ?? ''));
    $lat = isset($input['latitude']) && $input['latitude'] !== '' ? (float) $input['latitude'] : null;
    $lng = isset($input['longitude']) && $input['longitude'] !== '' ? (float) $input['longitude'] : null;

    if ($sessionId === '' || $token === '') {
        respond(422, [
            'success' => false,
            'message' => 'session_id and token are required.',
        ]);
    }

    $sessionStmt = $pdo->prepare(
        "SELECT es.id, es.session_name, es.starts_at, es.ends_at, es.late_after_minutes, e.id AS event_id, e.location_latitude, e.location_longitude, e.geofence_radius_m
         FROM public.event_sessions es
         JOIN public.events e ON e.id = es.event_id
         WHERE es.id = :session_id
         LIMIT 1"
    );
    $sessionStmt->execute(['session_id' => $sessionId]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        respond(404, [
            'success' => false,
            'message' => 'No session was found for the supplied session_id.',
        ]);
    }

    $challengeStmt = $pdo->prepare(
        "SELECT id, token_hash, expires_at, revoked_at
         FROM public.qr_challenges
         WHERE session_id = :session_id
           AND revoked_at IS NULL
           AND expires_at > NOW()
         ORDER BY issued_at DESC
         LIMIT 1"
    );
    $challengeStmt->execute(['session_id' => $sessionId]);
    $challenge = $challengeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$challenge) {
        respond(401, [
            'success' => false,
            'message' => 'No valid QR challenge is available for this session.',
        ]);
    }

    $providedHash = hash('sha256', $token);
    if (!hash_equals((string) $challenge['token_hash'], $providedHash)) {
        respond(401, [
            'success' => false,
            'message' => 'The QR token is invalid.',
        ]);
    }

    $attemptId = $pdo->query('SELECT gen_random_uuid()')->fetchColumn();
    $locationVerified = false;
    $distanceKm = null;
    $distanceFromEventM = null;

    if ($lat !== null && $lng !== null && $session['location_latitude'] !== null && $session['location_longitude'] !== null) {
        $distanceKm = haversineDistanceKm((float) $session['location_latitude'], (float) $session['location_longitude'], $lat, $lng);
        $distanceFromEventM = (float) ($distanceKm * 1000.0);

        $locationVerified = (int) $session['geofence_radius_m'] >= 0 && $distanceFromEventM <= (float) $session['geofence_radius_m'];
    }

    $status = 'pending_review';
    $resultCode = 'CHECKIN_PENDING';
    $resultMessage = 'Attendance request recorded for review.';

    if ($locationVerified) {
        $startsAt = new DateTimeImmutable((string) $session['starts_at']);
        $lateAfterMinutes = (int) ($session['late_after_minutes'] ?? 0);
        $currentTime = new DateTimeImmutable();

        $status = $currentTime > $startsAt->modify('+' . $lateAfterMinutes . ' minutes') ? 'late' : 'present';
        $resultCode = 'CHECKIN_OK';
        $resultMessage = $status === 'late' ? 'Checked in late but within geofence.' : 'Checked in successfully.';
    }

    $attemptSql = "
        INSERT INTO public.attendance_attempts (
            id,
            session_id,
            student_id,
            attempted_at,
            result_code,
            result_message,
            location_verified,
            distance_from_event_m,
            request_id,
            metadata
        ) VALUES (
            :id,
            :session_id,
            :student_id,
            NOW(),
            :result_code,
            :result_message,
            :location_verified,
            :distance_from_event_m,
            :request_id,
            :metadata
        )
    ";

    $attemptStmt = $pdo->prepare($attemptSql);
    $attemptStmt->execute([
        'id' => $attemptId,
        'session_id' => $sessionId,
        'student_id' => $studentId,
        'result_code' => $resultCode,
        'result_message' => $resultMessage,
        'location_verified' => $locationVerified ? 1 : 0,
        'distance_from_event_m' => $distanceFromEventM !== null ? $distanceFromEventM : null,
        'request_id' => $challenge['id'],
        'metadata' => json_encode([
            'token_hash' => $providedHash,
            'event_id' => $session['event_id'],
            'session_name' => $session['session_name'],
        ], JSON_THROW_ON_ERROR),
    ]);

    if ($locationVerified) {
        $recordSql = 'SELECT id FROM public.attendance_records WHERE session_id = :session_id AND student_id = :student_id LIMIT 1';
        $recordStmt = $pdo->prepare($recordSql);
        $recordStmt->execute(['session_id' => $sessionId, 'student_id' => $studentId]);
        $attendanceRecord = $recordStmt->fetch(PDO::FETCH_ASSOC);

        if ($attendanceRecord) {
            $updateSql = "
                UPDATE public.attendance_records
                SET
                    status = :status,
                    checkin_method = 'qr_geofence',
                    checked_in_at = NOW(),
                    location_verified = TRUE,
                    distance_from_event_m = :distance_from_event_m,
                    qr_challenge_id = :challenge_id,
                    updated_at = NOW()
                WHERE id = :id
            ";

            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                'status' => $status,
                'distance_from_event_m' => $distanceFromEventM,
                'challenge_id' => $challenge['id'],
                'id' => $attendanceRecord['id'],
            ]);
        } else {
            $recordId = $pdo->query('SELECT gen_random_uuid()')->fetchColumn();

            $insertRecordSql = "
                INSERT INTO public.attendance_records (
                    id,
                    session_id,
                    student_id,
                    status,
                    checkin_method,
                    checked_in_at,
                    location_verified,
                    distance_from_event_m,
                    verified_by,
                    manual_reason,
                    qr_challenge_id,
                    source_request_id,
                    officer_notes,
                    created_at,
                    updated_at
                ) VALUES (
                    :id,
                    :session_id,
                    :student_id,
                    :status,
                    'qr_geofence',
                    NOW(),
                    TRUE,
                    :distance_from_event_m,
                    NULL,
                    NULL,
                    :challenge_id,
                    NULL,
                    NULL,
                    NOW(),
                    NOW()
                )
            ";

            $insertRecordStmt = $pdo->prepare($insertRecordSql);
            $insertRecordStmt->execute([
                'id' => $recordId,
                'session_id' => $sessionId,
                'student_id' => $studentId,
                'status' => $status,
                'distance_from_event_m' => $distanceFromEventM,
                'challenge_id' => $challenge['id'],
            ]);
        }
    }

    respond(200, [
        'success' => true,
        'message' => $resultMessage,
        'result_code' => $resultCode,
        'location_verified' => $locationVerified,
        'distance_from_event_m' => $distanceFromEventM,
        'status' => $status,
        'session_id' => $sessionId,
    ]);

} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
} catch (RuntimeException $e) {
    error_log('Student check-in endpoint authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Student check-in endpoint error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The attendance check-in could not be completed.',
    ]);
}
