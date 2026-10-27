<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

function supabaseAuthRequest(string $endpoint, array $payload): array
{
    $baseUrl = rtrim(envValue('SUPABASE_URL'), '/');
    $apiKey = envValue('SUPABASE_PUBLISHABLE_KEY');

    $url = $baseUrl . '/auth/v1/' . ltrim($endpoint, '/');

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode(
            $payload,
            JSON_THROW_ON_ERROR
        ),
    ]);

    $response = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);

        error_log('Supabase Auth request failed: ' . $error);

        throw new RuntimeException(
            'Unable to contact the authentication service.'
        );
    }

    curl_close($curl);

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'The authentication service returned an invalid response.'
        );
    }

    return [
        'status' => $httpStatus,
        'data' => $data,
    ];
}