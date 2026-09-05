<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$app = \Slim\Factory\AppFactory::create();
$request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/')
    ->withHeader('Accept', $argv[1] ?? 'application/json');
$errorHandler = new \App\Application\Handlers\HttpErrorHandler(
    $app->getCallableResolver(), $app->getResponseFactory()
);
register_shutdown_function(new \App\Application\Handlers\ShutdownHandler($request, $errorHandler, false));

undefined_function_for_fatal_shutdown_regression();
