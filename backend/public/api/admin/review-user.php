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

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST', 'PATCH'], true)) {
    header('Allow: POST, PATCH');

    respond(405, [
        'success' => false,
        'message' => 'Only POST and PATCH requests are allowed.',
    ]);
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/authorization.php';

    $input = json_decode(
        file_get_contents('php://input'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($input)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid request body.',
        ]);
    }

    $reviewer = requireAuthorizedUser($pdo, ['administrator']);
    $reviewerId = (string) ($reviewer['id'] ?? '');

    if ($reviewerId === '') {
        respond(403, [
            'success' => false,
            'message' => 'The reviewer identity could not be verified.',
        ]);
    }

    $userId = trim((string) ($input['user_id'] ?? ''));
    $decision = strtolower(trim((string) ($input['decision'] ?? $input['action'] ?? '')));
    $rejectionReason = trim((string) ($input['rejection_reason'] ?? ''));

    if ($userId === '') {
        respond(422, [
            'success' => false,
            'message' => 'A target user_id is required.',
        ]);
    }

    if (!in_array($decision, ['approve', 'reject'], true)) {
        respond(422, [
            'success' => false,
            'message' => 'Decision must be either approve or reject.',
        ]);
    }

    if ($decision === 'reject' && $rejectionReason === '') {
        respond(422, [
            'success' => false,
            'message' => 'A rejection reason is required when rejecting an application.',
        ]);
    }

    $profileSql = "
        SELECT
            id,
            full_name,
            email,
            approval_status,
            account_status,
            rejection_reason
        FROM public.profiles
        WHERE id = :user_id
        LIMIT 1
    ";

    $profileStmt = $pdo->prepare($profileSql);
    $profileStmt->execute(['user_id' => $userId]);
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        respond(404, [
            'success' => false,
            'message' => 'No profile was found for the selected user.',
        ]);
    }

    if ($decision === 'approve') {
        $updateSql = "
            UPDATE public.profiles
            SET
                approval_status = 'approved',
                account_status = 'active',
                approved_by = :reviewer_id,
                approved_at = NOW(),
                rejection_reason = NULL
            WHERE id = :user_id
        ";

        $message = 'Application approved successfully.';
    } else {
        $updateSql = "
            UPDATE public.profiles
            SET
                approval_status = 'rejected',
                account_status = 'inactive',
                approved_by = NULL,
                approved_at = NULL,
                rejection_reason = :rejection_reason
            WHERE id = :user_id
        ";

        $message = 'Application rejected successfully.';
    }

    $updateStmt = $pdo->prepare($updateSql);

    if ($decision === 'approve') {
        $updateStmt->execute([
            'reviewer_id' => $reviewerId,
            'user_id' => $userId,
        ]);
    } else {
        $updateStmt->execute([
            'rejection_reason' => $rejectionReason,
            'user_id' => $userId,
        ]);
    }

    $finalProfileSql = "
        SELECT
            id,
            full_name,
            email,
            approval_status,
            account_status,
            rejection_reason,
            approved_by,
            approved_at,
            created_at
        FROM public.profiles
        WHERE id = :user_id
        LIMIT 1
    ";

    $finalStmt = $pdo->prepare($finalProfileSql);
    $finalStmt->execute(['user_id' => $userId]);
    $updatedProfile = $finalStmt->fetch(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'message' => $message,
        'user' => $updatedProfile,
    ]);

} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
} catch (RuntimeException $e) {
    error_log('Review user endpoint authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Review user endpoint error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The review action could not be completed.',
    ]);
}
