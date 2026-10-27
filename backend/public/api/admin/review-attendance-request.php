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

    $reviewer = requireAuthorizedUser($pdo, ['administrator', 'event_officer']);
    $reviewerId = (string) ($reviewer['id'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $statusFilter = trim((string) ($_GET['status'] ?? 'submitted'));
        $allowedStatuses = ['submitted', 'under_review', 'approved', 'rejected', 'withdrawn'];

        if (!in_array($statusFilter, $allowedStatuses, true)) {
            respond(422, [
                'success' => false,
                'message' => 'status must be one of: ' . implode(', ', $allowedStatuses) . '.',
            ]);
        }

        $sql = "
            SELECT
                ar.id,
                ar.event_id,
                ar.session_id,
                ar.student_id,
                ar.request_type,
                ar.status,
                ar.reason,
                ar.excuse_category,
                ar.requested_attendance_status,
                ar.submitted_at,
                ar.reviewed_by,
                ar.reviewed_at,
                ar.reviewer_note,
                ar.decision_reason,
                p.full_name AS student_name,
                p.email AS student_email
            FROM public.attendance_requests ar
            LEFT JOIN public.profiles p ON p.id = ar.student_id
            WHERE ar.status = :status
            ORDER BY ar.submitted_at ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['status' => $statusFilter]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond(200, [
            'success' => true,
            'count' => count($requests),
            'requests' => $requests,
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

    $requestId = trim((string) ($input['request_id'] ?? ''));
    $decision = strtolower(trim((string) ($input['decision'] ?? '')));
    $reviewerNote = trim((string) ($input['reviewer_note'] ?? ''));
    $decisionReason = trim((string) ($input['decision_reason'] ?? ''));

    if ($requestId === '') {
        respond(422, [
            'success' => false,
            'message' => 'request_id is required.',
        ]);
    }

    if (!in_array($decision, ['approve', 'reject'], true)) {
        respond(422, [
            'success' => false,
            'message' => 'decision must be either approve or reject.',
        ]);
    }

    if ($decision === 'reject' && $decisionReason === '') {
        respond(422, [
            'success' => false,
            'message' => 'decision_reason is required when rejecting a request.',
        ]);
    }

    $requestSql = "
        SELECT
            ar.id,
            ar.event_id,
            ar.session_id,
            ar.student_id,
            ar.request_type,
            ar.status,
            ar.reason,
            ar.excuse_category,
            ar.requested_attendance_status,
            ar.submitted_at,
            p.full_name,
            p.email
        FROM public.attendance_requests ar
        LEFT JOIN public.profiles p ON p.id = ar.student_id
        WHERE ar.id = :request_id
        LIMIT 1
    ";

    $requestStmt = $pdo->prepare($requestSql);
    $requestStmt->execute(['request_id' => $requestId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        respond(404, [
            'success' => false,
            'message' => 'No attendance request was found for the supplied request_id.',
        ]);
    }

    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
    $reviewNote = $reviewerNote !== '' ? $reviewerNote : null;
    $finalReason = $decisionReason !== '' ? $decisionReason : null;

    $updateSql = "
        UPDATE public.attendance_requests
        SET
            status = :status,
            reviewed_by = :reviewer_id,
            reviewed_at = NOW(),
            reviewer_note = :reviewer_note,
            decision_reason = :decision_reason,
            updated_at = NOW()
        WHERE id = :request_id
    ";

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
        'status' => $newStatus,
        'reviewer_id' => $reviewerId,
        'reviewer_note' => $reviewNote,
        'decision_reason' => $finalReason,
        'request_id' => $requestId,
    ]);

    if ($decision === 'approve') {
        $targetStatus = strtolower(trim((string) ($request['requested_attendance_status'] ?? 'present')));
        $validTargetStatuses = ['present', 'late', 'pending_review', 'rejected'];

        if (!in_array($targetStatus, $validTargetStatuses, true)) {
            $targetStatus = 'present';
        }

        $recordSql = "
            SELECT id
            FROM public.attendance_records
            WHERE session_id = :session_id AND student_id = :student_id
            LIMIT 1
        ";

        $recordStmt = $pdo->prepare($recordSql);
        $recordStmt->execute([
            'session_id' => $request['session_id'],
            'student_id' => $request['student_id'],
        ]);
        $record = $recordStmt->fetch(PDO::FETCH_ASSOC);

        if ($record) {
            $recordUpdateSql = "
                UPDATE public.attendance_records
                SET
                    status = :status,
                    checkin_method = 'manual',
                    verified_by = :reviewer_id,
                    officer_notes = :reviewer_note,
                    manual_reason = :decision_reason,
                    updated_at = NOW()
                WHERE id = :record_id
            ";

            $recordUpdateStmt = $pdo->prepare($recordUpdateSql);
            $recordUpdateStmt->execute([
                'status' => $targetStatus,
                'reviewer_id' => $reviewerId,
                'reviewer_note' => $reviewerNote !== '' ? $reviewerNote : null,
                'decision_reason' => $finalReason,
                'record_id' => $record['id'],
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
                    'manual',
                    NOW(),
                    FALSE,
                    NULL,
                    :reviewer_id,
                    :decision_reason,
                    NULL,
                    :request_id,
                    :reviewer_note,
                    NOW(),
                    NOW()
                )
            ";

            $insertRecordStmt = $pdo->prepare($insertRecordSql);
            $insertRecordStmt->execute([
                'id' => $recordId,
                'session_id' => $request['session_id'],
                'student_id' => $request['student_id'],
                'status' => $targetStatus,
                'reviewer_id' => $reviewerId,
                'decision_reason' => $finalReason,
                'request_id' => $requestId,
                'reviewer_note' => $reviewerNote !== '' ? $reviewerNote : null,
            ]);
        }
    }

    $notificationSql = "
        INSERT INTO public.notifications (
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
        ) VALUES (
            :id,
            :recipient_id,
            :event_id,
            :notification_type,
            :title,
            :message,
            :action_url,
            NULL,
            NOW(),
            :metadata
        )
    ";

    $notificationId = $pdo->query('SELECT gen_random_uuid()')->fetchColumn();
    $notificationStmt = $pdo->prepare($notificationSql);
    $notificationStmt->execute([
        'id' => $notificationId,
        'recipient_id' => $request['student_id'],
        'event_id' => $request['event_id'],
        'notification_type' => 'attendance_request_reviewed',
        'title' => $decision === 'approve' ? 'Attendance request approved' : 'Attendance request rejected',
        'message' => $decision === 'approve'
            ? 'Your attendance request was approved by the reviewer.'
            : 'Your attendance request was reviewed and not approved.',
        'action_url' => '/student/requests',
        'metadata' => json_encode([
            'request_id' => $requestId,
            'decision' => $decision,
            'status' => $newStatus,
        ], JSON_THROW_ON_ERROR),
    ]);

    respond(200, [
        'success' => true,
        'message' => $decision === 'approve'
            ? 'Attendance request approved successfully.'
            : 'Attendance request rejected successfully.',
        'request_id' => $requestId,
        'decision' => $decision,
        'status' => $newStatus,
    ]);

} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
} catch (RuntimeException $e) {
    error_log('Review attendance request authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Review attendance request error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The attendance request review could not be completed.',
    ]);
}
