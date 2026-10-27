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

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'PATCH'], true)) {
    header('Allow: GET, PATCH');

    respond(405, [
        'success' => false,
        'message' => 'Only GET and PATCH requests are allowed.',
    ]);
}

try {
    require_once __DIR__ . '/../../../config/database.php';
    require_once __DIR__ . '/../../../config/authorization.php';

    $user = requireAuthorizedUser($pdo, ['student', 'administrator', 'event_officer']);
    $userId = (string) ($user['id'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sql = "
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
                p.created_at,
                p.updated_at
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        respond(200, [
            'success' => true,
            'profile' => $profile,
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

    $allowedFields = [
        'full_name',
        'department',
        'program',
        'year_level',
        'section',
        'student_id',
    ];

    $setClauses = [];
    $params = ['user_id' => $userId];

    foreach ($allowedFields as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }

        $value = trim((string) ($input[$field] ?? ''));
        if ($field === 'student_id' && $value === '') {
            respond(422, [
                'success' => false,
                'message' => 'student_id cannot be empty.',
            ]);
        }

        $setClauses[] = $field . ' = :' . $field;
        $params[$field] = $value;
    }

    if ($setClauses === []) {
        respond(422, [
            'success' => false,
            'message' => 'No profile fields were provided to update.',
        ]);
    }

    $sql = 'UPDATE public.profiles SET ' . implode(', ', $setClauses) . ', updated_at = NOW() WHERE id = :user_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $fetchSql = "
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
            p.created_at,
            p.updated_at
        LIMIT 1
    ";

    $fetchStmt = $pdo->prepare($fetchSql);
    $fetchStmt->execute(['user_id' => $userId]);
    $profile = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'message' => 'Profile updated successfully.',
        'profile' => $profile,
    ]);
} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
} catch (RuntimeException $e) {
    error_log('Student profile authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Student profile error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The profile operation could not be completed.',
    ]);
}
