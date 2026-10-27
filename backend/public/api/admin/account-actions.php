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

    $admin = requireAuthorizedUser($pdo, ['administrator']);

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

    $userId = trim((string) ($input['user_id'] ?? ''));
    if ($userId === '') {
        respond(422, [
            'success' => false,
            'message' => 'A user_id is required.',
        ]);
    }

    $allowedAccountStates = ['active', 'inactive', 'suspended'];
    $allowedApprovalStates = ['pending', 'approved', 'rejected'];

    $profileUpdate = [];
    $params = ['user_id' => $userId];

    if (array_key_exists('account_status', $input)) {
        $accountStatus = strtolower(trim((string) ($input['account_status'] ?? '')));
        if (!in_array($accountStatus, $allowedAccountStates, true)) {
            respond(422, [
                'success' => false,
                'message' => 'Invalid account_status. Use active, inactive, or suspended.',
            ]);
        }

        $profileUpdate[] = 'account_status = :account_status';
        $params['account_status'] = $accountStatus;
    }

    if (array_key_exists('approval_status', $input)) {
        $approvalStatus = strtolower(trim((string) ($input['approval_status'] ?? '')));
        if (!in_array($approvalStatus, $allowedApprovalStates, true)) {
            respond(422, [
                'success' => false,
                'message' => 'Invalid approval_status. Use pending, approved, or rejected.',
            ]);
        }

        $profileUpdate[] = 'approval_status = :approval_status';
        $params['approval_status'] = $approvalStatus;
    }

    if (array_key_exists('rejection_reason', $input)) {
        $rejectionReason = trim((string) ($input['rejection_reason'] ?? ''));
        $profileUpdate[] = 'rejection_reason = :rejection_reason';
        $params['rejection_reason'] = $rejectionReason === '' ? null : $rejectionReason;
    }

    if ($profileUpdate !== []) {
        $sql = 'UPDATE public.profiles SET ' . implode(', ', $profileUpdate) . ' WHERE id = :user_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    if (array_key_exists('role', $input) || array_key_exists('roles', $input)) {
        $roleInput = $input['role'] ?? $input['roles'] ?? null;
        $rolesToSet = is_array($roleInput) ? $roleInput : [$roleInput];
        $rolesToSet = array_values(array_filter(array_map('strtolower', array_map('trim', $rolesToSet)), static fn ($value) => $value !== ''));

        $roleSql = 'SELECT id, role_key FROM public.roles WHERE role_key = :role_key LIMIT 1';
        $insertRows = [];

        foreach ($rolesToSet as $roleName) {
            if (!in_array($roleName, ['student', 'administrator', 'event_officer'], true)) {
                respond(422, [
                    'success' => false,
                    'message' => 'Unsupported role: ' . $roleName,
                ]);
            }

            $roleStmt = $pdo->prepare($roleSql);
            $roleStmt->execute(['role_key' => $roleName]);
            $role = $roleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$role) {
                respond(404, [
                    'success' => false,
                    'message' => 'Role not found: ' . $roleName,
                ]);
            }

            $insertRows[] = [
                'user_id' => $userId,
                'role_id' => $role['id'],
            ];
        }

        $pdo->beginTransaction();

        try {
            $deleteStmt = $pdo->prepare('DELETE FROM public.user_roles WHERE user_id = :user_id');
            $deleteStmt->execute(['user_id' => $userId]);

            if ($insertRows !== []) {
                $insertSql = 'INSERT INTO public.user_roles (user_id, role_id) VALUES (:user_id, :role_id)';
                $insertStmt = $pdo->prepare($insertSql);

                foreach ($insertRows as $row) {
                    $insertStmt->execute([
                        'user_id' => $row['user_id'],
                        'role_id' => $row['role_id'],
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    $resultSql = "
        SELECT
            p.id,
            p.student_id,
            p.full_name,
            p.email,
            p.department,
            p.program,
            p.year_level,
            p.section,
            p.approval_status,
            p.account_status,
            p.rejection_reason,
            p.created_at,
            p.updated_at,
            COALESCE(string_agg(DISTINCT r.role_key, ', ' ORDER BY r.role_key), 'none') AS roles
        FROM public.profiles p
        LEFT JOIN public.user_roles ur ON ur.user_id = p.id
        LEFT JOIN public.roles r ON r.id = ur.role_id
        WHERE p.id = :user_id
        GROUP BY
            p.id,
            p.student_id,
            p.full_name,
            p.email,
            p.department,
            p.program,
            p.year_level,
            p.section,
            p.approval_status,
            p.account_status,
            p.rejection_reason,
            p.created_at,
            p.updated_at
        LIMIT 1
    ";

    $resultStmt = $pdo->prepare($resultSql);
    $resultStmt->execute(['user_id' => $userId]);
    $updatedUser = $resultStmt->fetch(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'message' => 'Account was updated successfully.',
        'user' => $updatedUser,
    ]);
} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
} catch (RuntimeException $e) {
    error_log('Account actions authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Account actions error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The account update could not be completed.',
    ]);
}
