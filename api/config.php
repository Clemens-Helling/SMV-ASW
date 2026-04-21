<?php
// Konfiguration – Werte können über Umgebungsvariablen oder eine .env-Datei gesetzt werden.

// Lade .env aus dem Projekt-Root, falls vorhanden
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

define('DB_HOST',     $_ENV['DB_HOST']     ?? 'localhost');
define('DB_PORT',     $_ENV['DB_PORT']     ?? '3306');
define('DB_NAME',     $_ENV['DB_NAME']     ?? 'smv');
define('DB_USER',     $_ENV['DB_USER']     ?? 'root');
define('DB_PASSWORD', $_ENV['DB_PASSWORD'] ?? '');
define('SECRET_KEY',  $_ENV['SECRET_KEY']  ?? 'your-secret-key-hier-ersetzen');
define('TOKEN_EXPIRE_MINUTES', 30);
