<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->options('/{routes:.*}', function (Request $request, Response $response) {
    return $response;
});

$app->get('/index.php', function (Request $request, Response $response) use ($app) {
    return $response
        ->withHeader('Location', app_url(''))
        ->withStatus(302);
});

$app->get('/', function (Request $request, Response $response) {
    $hasDeskhelperUser = (int) ($_SESSION['deskhelper_user_id'] ?? 0) > 0;

    return $response
        ->withHeader('Location', app_url($hasDeskhelperUser ? '/app' : '/login'))
        ->withStatus(302);
});

$app->get('/legacy-home', function (Request $request, Response $response) {
    return $response
        ->withHeader('Location', app_url(''))
        ->withStatus(302);
});
