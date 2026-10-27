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

    $fullName = trim((string) ($input['full_name'] ?? ''));
    $studentId = trim((string) ($input['student_id'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    if (
        $fullName === '' ||
        $studentId === '' ||
        $email === '' ||
        $password === ''
    ) {
        respond(422, [
            'success' => false,
            'message' => 'All fields are required.',
        ]);
    }

    if (strlen($fullName) > 120 || strlen($studentId) > 50) {
        respond(422, [
            'success' => false,
            'message' => 'A name or student ID exceeds the allowed length.',
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(422, [
            'success' => false,
            'message' => 'Enter a valid email address.',
        ]);
    }

    if (strlen($password) < 8) {
        respond(422, [
            'success' => false,
            'message' => 'Your password must contain at least 8 characters.',
        ]);
    }

    $result = supabaseAuthRequest('signup', [
        'email' => $email,
        'password' => $password,
        'data' => [
            'full_name' => $fullName,
            'student_id' => $studentId,
        ],
    ]);

    if ($result['status'] < 200 || $result['status'] >= 300) {
    error_log(
        'Supabase registration error. HTTP status: '
        . $result['status']
        . '; response: '
        . json_encode($result['data'])
    );

    $errorMessage = $result['data']['msg']
        ?? $result['data']['message']
        ?? $result['data']['error_description']
        ?? $result['data']['error']
        ?? 'Registration was rejected by the authentication service.';

    respond(400, [
        'success' => false,
        'message' => $errorMessage,
        'supabase_status' => $result['status'],
    ]);
}

    respond(201, [
        'success' => true,
        'message' => 'Registration request received. Check your email if confirmation is required. Your account will remain pending until an administrator approves it.',
    ]);

} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);

} catch (Throwable $e) {
    error_log('Registration endpoint error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Registration is temporarily unavailable.',
    ]);
}