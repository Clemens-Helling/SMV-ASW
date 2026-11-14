<?php

declare(strict_types=1);

use Smv\Config;
use Smv\Store\MemoryStore;
use Smv\Store\MongoStore;
use Smv\Store\Store;

const SMV_ROOT = __DIR__ . '/..';

date_default_timezone_set('UTC');

// Eigene Klassen (PSR-4 "Smv\") – funktioniert auch ohne Composer.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Smv\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// MongoDB-Bibliothek und weitere Composer-Pakete.
if (is_file(SMV_ROOT . '/vendor/autoload.php')) {
    require SMV_ROOT . '/vendor/autoload.php';
}

/** Liest eine lokale .env (nur für die Entwicklung); echte Umgebungsvariablen haben Vorrang. */
function smv_load_env(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

smv_load_env(SMV_ROOT . '/.env');

function smv_config(): Config
{
    return Config::fromEnv(SMV_ROOT);
}

/** Verbindet erst beim ersten Datenbankzugriff – Frontend und /health laufen auch ohne DB. */
function smv_store(Config $config): Store
{
    if ($config->useMemoryStore) {
        return new MemoryStore();
    }
    if (!class_exists(\MongoDB\Client::class)) {
        throw new RuntimeException('MongoDB-Treiber fehlt (ext-mongodb und "composer install" nötig).');
    }
    return new MongoStore($config->mongodbUrl(), Config::DATABASE_NAME);
}
