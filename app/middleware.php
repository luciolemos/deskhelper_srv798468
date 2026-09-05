<?php

declare(strict_types=1);

use App\Application\Middleware\SessionMiddleware;
use App\Application\Middleware\DeskhelperSessionMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;

return function (App $app) {
    $app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app) {
        if ((int) ($_SESSION['deskhelper_user_id'] ?? 0) <= 0) {
            return $handler->handle($request);
        }

        return $app->getContainer()->get(DeskhelperSessionMiddleware::class)->process($request, $handler);
    });
    $app->add(SessionMiddleware::class);
};
