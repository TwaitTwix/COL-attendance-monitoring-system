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

    $eventId = trim((string) ($input['event_id'] ?? ''));
    $sessionId = trim((string) ($input['session_id'] ?? ''));
    $requestType = strtolower(trim((string) ($input['request_type'] ?? '')));
    $reason = trim((string) ($input['reason'] ?? ''));
    $excuseCategory = trim((string) ($input['excuse_category'] ?? ''));
    $requestedAttendanceStatus = strtolower(trim((string) ($input['requested_attendance_status'] ?? '')));

    if ($eventId === '' && $sessionId === '') {
        respond(422, [
            'success' => false,
            'message' => 'Either event_id or session_id is required.',
        ]);
    }

    if (!in_array($requestType, ['correction', 'excuse'], true)) {
        respond(422, [
            'success' => false,
            'message' => 'request_type must be either correction or excuse.',
        ]);
    }

    if ($reason === '') {
        respond(422, [
            'success' => false,
            'message' => 'reason is required.',
        ]);
    }

    if ($requestType === 'excuse' && $excuseCategory === '') {
        respond(422, [
            'success' => false,
            'message' => 'excuse_category is required for excuse requests.',
        ]);
    }

    if ($requestedAttendanceStatus === '') {
        $requestedAttendanceStatus = 'present';
    }

    if (!in_array($requestedAttendanceStatus, ['present', 'late', 'pending_review', 'rejected'], true)) {
        respond(422, [
            'success' => false,
            'message' => 'requested_attendance_status must be one of: present, late, pending_review, rejected.',
        ]);
    }

    if ($sessionId !== '') {
        $sessionSql = "
            SELECT id, event_id
            FROM public.event_sessions
            WHERE id = :session_id
            LIMIT 1
        ";

        $sessionStmt = $pdo->prepare($sessionSql);
        $sessionStmt->execute(['session_id' => $sessionId]);
        $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            respond(404, [
                'success' => false,
                'message' => 'No session was found for the supplied session_id.',
            ]);
        }

        $eventId = (string) ($session['event_id'] ?? $eventId);
    } elseif ($eventId !== '') {
        $eventStmt = $pdo->prepare('SELECT id FROM public.events WHERE id = :event_id LIMIT 1');
        $eventStmt->execute(['event_id' => $eventId]);

        if (!$eventStmt->fetch(PDO::FETCH_ASSOC)) {
            respond(404, [
                'success' => false,
                'message' => 'No event was found for the supplied event_id.',
            ]);
        }
    }

    $requestId = $pdo->query('SELECT gen_random_uuid()')->fetchColumn();

    $insertSql = "
        INSERT INTO public.attendance_requests (
            id,
            event_id,
            session_id,
            student_id,
            request_type,
            status,
            reason,
            excuse_category,
            requested_attendance_status,
            submitted_at,
            reviewed_by,
            reviewed_at,
            reviewer_note,
            decision_reason,
            updated_at
        ) VALUES (
            :id,
            :event_id,
            :session_id,
            :student_id,
            :request_type,
            'submitted',
            :reason,
            :excuse_category,
            :requested_attendance_status,
            NOW(),
            NULL,
            NULL,
            NULL,
            NULL,
            NOW()
        )
    ";

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        'id' => $requestId,
        'event_id' => $eventId !== '' ? $eventId : null,
        'session_id' => $sessionId !== '' ? $sessionId : null,
        'student_id' => $studentId,
        'request_type' => $requestType,
        'reason' => $reason,
        'excuse_category' => $excuseCategory !== '' ? $excuseCategory : null,
        'requested_attendance_status' => $requestedAttendanceStatus,
    ]);

    respond(201, [
        'success' => true,
        'message' => 'Attendance request submitted successfully.',
        'request' => [
            'id' => $requestId,
            'event_id' => $eventId !== '' ? $eventId : null,
            'session_id' => $sessionId !== '' ? $sessionId : null,
            'student_id' => $studentId,
            'request_type' => $requestType,
            'status' => 'submitted',
            'reason' => $reason,
            'excuse_category' => $excuseCategory !== '' ? $excuseCategory : null,
            'requested_attendance_status' => $requestedAttendanceStatus,
        ],
    ]);

} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
} catch (RuntimeException $e) {
    error_log('Attendance request authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Attendance request error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The attendance request could not be submitted.',
    ]);
}
