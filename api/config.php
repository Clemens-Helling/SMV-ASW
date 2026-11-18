<?php
// Konfiguration aus Server-Umgebung oder einer lokalen .env-Datei laden.
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === $value[strlen($value) - 1]
            && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

function app_env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    return $_ENV[$key] ?? $default;
}

define('DB_HOST',     app_env('DB_HOST', 'localhost'));
define('DB_PORT',     app_env('DB_PORT', '3306'));
define('DB_NAME',     app_env('DB_NAME', 'smv'));
define('DB_USER',     app_env('DB_USER', 'root'));
define('DB_PASSWORD', app_env('DB_PASSWORD', ''));

$secretKey = app_env('SECRET_KEY', '');
if (strlen($secretKey) < 32) {
    throw new RuntimeException('SECRET_KEY muss gesetzt sein und mindestens 32 Zeichen enthalten.');
}

$initialAdminUsername = app_env('INITIAL_ADMIN_USERNAME', '');
$initialAdminPassword = app_env('INITIAL_ADMIN_PASSWORD', '');
if (($initialAdminUsername === '') !== ($initialAdminPassword === '')) {
    throw new RuntimeException('INITIAL_ADMIN_USERNAME und INITIAL_ADMIN_PASSWORD müssen gemeinsam gesetzt werden.');
}
define('SECRET_KEY', $secretKey);
define('INITIAL_ADMIN_USERNAME', $initialAdminUsername);
define('INITIAL_ADMIN_PASSWORD', $initialAdminPassword);

define('TOKEN_EXPIRE_MINUTES', 30);
