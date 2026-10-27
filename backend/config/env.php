<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$envDirectory = dirname(__DIR__);
$envFile = $envDirectory . DIRECTORY_SEPARATOR . '.env';

if (is_file($envFile)) {
    Dotenv::createImmutable($envDirectory)->safeLoad();
}

function envValue(string $key, ?string $default = null, bool $allowEmpty = false): string
{
    $value = $_ENV[$key] ?? null;

    if ($value === null || $value === '') {
        $value = $_SERVER[$key] ?? null;
    }

    if ($value === null || $value === '') {
        $value = getenv($key);
    }

    if ($value === false) {
        $value = '';
    }

    if ($value === '' && $default !== null) {
        $value = $default;
    }

    if ((string) $value === '' && !$allowEmpty) {
        throw new RuntimeException(
            sprintf('Required environment variable "%s" is missing or empty.', $key)
        );
    }

    return trim((string) $value);
}

$appEnvironment = envValue('APP_ENV', 'production', true);
$allowedEnvironments = ['local', 'staging', 'production'];

if (!in_array($appEnvironment, $allowedEnvironments, true)) {
    throw new RuntimeException(
        sprintf(
            'Unsupported APP_ENV value "%s". Use one of: %s.',
            $appEnvironment,
            implode(', ', $allowedEnvironments)
        )
    );
}

$_ENV['APP_ENV'] = $appEnvironment;
$_SERVER['APP_ENV'] = $appEnvironment;
