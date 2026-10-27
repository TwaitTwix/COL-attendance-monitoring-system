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

    $admin = requireAuthorizedUser($pdo, ['administrator', 'event_officer']);
    $adminId = (string) ($admin['id'] ?? '');

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
    $minutesValid = (int) ($input['minutes_valid'] ?? 10);

    if ($sessionId === '') {
        respond(422, [
            'success' => false,
            'message' => 'session_id is required.',
        ]);
    }

    if ($minutesValid < 1 || $minutesValid > 180) {
        respond(422, [
            'success' => false,
            'message' => 'minutes_valid must be between 1 and 180.',
        ]);
    }

    $sessionStmt = $pdo->prepare(
        'SELECT id, event_id, session_name, starts_at, ends_at FROM public.event_sessions WHERE id = :session_id LIMIT 1'
    );
    $sessionStmt->execute(['session_id' => $sessionId]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        respond(404, [
            'success' => false,
            'message' => 'No session was found for the supplied session_id.',
        ]);
    }

    $token = bin2hex(random_bytes(24));
    $tokenHash = hash('sha256', $token);
    $issuedAt = new DateTimeImmutable();
    $expiresAt = $issuedAt->modify('+' . $minutesValid . ' minutes');

    $challengeId = $pdo->query('SELECT gen_random_uuid()')->fetchColumn();

    $insertSql = "
        INSERT INTO public.qr_challenges (
            id,
            session_id,
            token_hash,
            issued_by,
            issued_at,
            expires_at,
            revoked_at
        ) VALUES (
            :id,
            :session_id,
            :token_hash,
            :issued_by,
            NOW(),
            :expires_at,
            NULL
        )
    ";

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        'id' => $challengeId,
        'session_id' => $sessionId,
        'token_hash' => $tokenHash,
        'issued_by' => $adminId,
        'expires_at' => $expiresAt->format('Y-m-d\TH:i:sP'),
    ]);

    respond(201, [
        'success' => true,
        'message' => 'QR challenge issued successfully.',
        'challenge' => [
            'id' => $challengeId,
            'session_id' => $sessionId,
            'token' => $token,
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:sP'),
            'issued_by' => $adminId,
            'issued_at' => $issuedAt->format('Y-m-d\TH:i:sP'),
        ],
    ]);

} catch (JsonException $e) {
    respond(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
} catch (RuntimeException $e) {
    error_log('Session QR endpoint authorization error: ' . $e->getMessage());

    http_response_code($e->getCode() > 0 ? (int) $e->getCode() : 401);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('Session QR endpoint error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'The QR challenge could not be issued.',
    ]);
}
