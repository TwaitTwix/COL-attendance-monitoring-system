<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

function getBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!is_string($header) || trim($header) === '') {
        return null;
    }

    if (preg_match('/^Bearer\s+(.*)$/i', trim($header), $matches) !== 1) {
        return null;
    }

    $token = trim($matches[1]);

    return $token !== '' ? $token : null;
}

function getSupabaseUserFromToken(string $token): ?array
{
    $baseUrl = rtrim(envValue('SUPABASE_URL'), '/');
    $apiKey = envValue('SUPABASE_PUBLISHABLE_KEY');

    $url = $baseUrl . '/auth/v1/user';
    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'apikey: ' . $apiKey,
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException(
            'Unable to validate the bearer token with Supabase: ' . $curlError,
            401
        );
    }

    $payload = json_decode($response, true);

    if (!is_array($payload) || $httpStatus < 200 || $httpStatus >= 300) {
        $message = $payload['message'] ?? 'Authentication failed.';

        throw new RuntimeException(
            'Invalid or expired access token: ' . $message,
            401
        );
    }

    $userId = $payload['id'] ?? null;

    return is_string($userId) && $userId !== '' ? $payload : null;
}

function requireAuthorizedUser(PDO $pdo, array $requiredRoles): array
{
    $token = getBearerToken();

    if ($token === null || $token === '') {
        throw new RuntimeException(
            'Missing bearer token in the Authorization header.',
            401
        );
    }

    $user = getSupabaseUserFromToken($token);

    if ($user === null || empty($user['id'])) {
        throw new RuntimeException(
            'The access token is invalid or expired.',
            401
        );
    }

    $userId = (string) $user['id'];

    $profileSql = "
        SELECT
            id,
            email,
            full_name,
            approval_status,
            account_status
        FROM public.profiles
        WHERE id = :user_id
        LIMIT 1
    ";

    $profileStmt = $pdo->prepare($profileSql);
    $profileStmt->execute(['user_id' => $userId]);
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        throw new RuntimeException(
            'No profile was found for the authenticated user.',
            403
        );
    }

    if (($profile['account_status'] ?? '') !== 'active') {
        throw new RuntimeException(
            'This account is not active.',
            403
        );
    }

    if (($profile['approval_status'] ?? '') !== 'approved') {
        throw new RuntimeException(
            'This account is not approved.',
            403
        );
    }

    if ($requiredRoles === []) {
        return $profile;
    }

    $placeholders = [];
    foreach ($requiredRoles as $index => $role) {
        $placeholders[] = ':role_' . $index;
    }

    $roleSql = "
        SELECT COUNT(*) AS allowed
        FROM public.user_roles ur
        INNER JOIN public.roles r ON r.id = ur.role_id
        WHERE ur.user_id = :user_id
          AND r.role_key IN (" . implode(', ', $placeholders) . ")
    ";

    $roleStmt = $pdo->prepare($roleSql);
    $params = ['user_id' => $userId];

    foreach ($requiredRoles as $index => $role) {
        $params['role_' . $index] = $role;
    }

    $roleStmt->execute($params);
    $result = $roleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$result || (int) ($result['allowed'] ?? 0) < 1) {
        throw new RuntimeException(
            'Access denied. You do not have permission to access this resource.',
            403
        );
    }

    return $profile;
}
