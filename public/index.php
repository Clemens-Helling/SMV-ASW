<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Smv\App;
use Smv\Http\HttpException;
use Smv\Http\Request;

try {
    $config = smv_config();
    $app = new App($config, static fn () => smv_store($config));
    $app->handle(Request::fromGlobals())->send();
} catch (HttpException $e) {
    $e->toResponse()->withHeaders(['Access-Control-Allow-Origin' => '*'])->send();
} catch (Throwable $e) {
    error_log('[smv] ' . $e::class . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"detail":"Internal Server Error"}';
}
