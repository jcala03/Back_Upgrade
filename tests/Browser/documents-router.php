<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/documents-support.php';
[$app] = documentQaApplication();
$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Documents-QA-Database', 'upgrade_test');
$response->send();
$kernel->terminate($request, $response);
