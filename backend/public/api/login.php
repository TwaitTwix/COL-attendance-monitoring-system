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
    require_once dirname(__DIR__, 2) . '/config/supabase-auth.php';

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

    $email = trim((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        $password === ''
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Enter a valid email address and password.',
        ]);
    }

    $auth = supabaseAuthRequest(
        'token?grant_type=password',
        [
            'email' => $email,
            'password' => $password,
        ]
    );

    if (
        $auth['status'] < 200 ||
        $auth['status'] >= 300 ||
        empty($auth['data']['access_token']) ||
        empty($auth['data']['user']['id'])
    ) {
        respond(401, [
            'success' => false,
            'message' => 'Login failed. Check your credentials and email confirmation status.',
        ]);
    }

    $userId = $auth['data']['user']['id'];

    $pdo = require dirname(__DIR__, 2) . '/config/database.php';

    $statement = $pdo->prepare(
        'SELECT
            id,
            student_id,
            full_name,
            email,
            department,
            program,
            year_level,
            section,
            approval_status,
            account_status
         FROM public.profiles
         WHERE id = :id
         LIMIT 1'
    );

    $statement->execute([
        'id' => $userId,
    ]);

    $profile = $statement->fetch();

    if (!$profile) {
        respond(403, [
            'success' => false,
            'message' => 'No application profile was found for this account.',
        ]);
    }

    if ($profile['account_status'] !== 'active') {
    respond(403, [
        'success' => false,
        'message' => 'This account is currently restricted. Contact an administrator.',
    ]);
}

if ($profile['approval_status'] !== 'approved') {
    respond(403, [
        'success' => false,
        'status' => $profile['approval_status'],
        'message' => $profile['approval_status'] === 'pending'
            ? 'Your account is awaiting administrator approval.'
            : 'Your account has not been approved. Contact an administrator.',
    ]);
}

    respond(200, [
        'success' => true,
        'message' => 'Authentication successful.',
        'profile' => $profile,
        'access_token' => $auth['data']['access_token'],
        'refresh_token' => $auth['data']['refresh_token'] ?? null,
        'expires_in' => $auth['data']['expires_in'] ?? null,
    ]);

} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);

} catch (Throwable $e) {
    error_log('Login endpoint error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Login is temporarily unavailable.',
    ]);
}