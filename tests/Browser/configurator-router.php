<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

// Only `php -S 127.0.0.1:8196 tests/Browser/configurator-router.php` uses this file.
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
require __DIR__.'/configurator-support.php';
[$app, , $manifest] = configuratorQaApplication();
if (! $manifest) {
    http_response_code(503);
    exit('QA fixture not initialized.');
}
$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Configurator-QA-Database', 'upgrade_test');
$response->send();
$kernel->terminate($request, $response);
