<?php

declare(strict_types=1);

// Legt fehlende Standardbenutzer/-Tags an (früher: FastAPI-Startup-Event). Idempotent.
// Aufruf: php bin/seed.php

require __DIR__ . '/../src/bootstrap.php';

use Smv\Seeder;

try {
    $config = smv_config();
    (new Seeder(smv_store($config), static function (string $message): void {
        fwrite(STDOUT, $message . PHP_EOL);
    }))->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed fehlgeschlagen: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
