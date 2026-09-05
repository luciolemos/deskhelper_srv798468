<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Application\Middleware\DeskhelperAuthMiddleware;
use Slim\Views\Twig;

$app->get('/guide', function (Request $request, Response $response) use ($app) {
    $twig = $app->getContainer()->get(Twig::class);

    return $twig->render($response, 'deskhelper-doc-page.twig', [
        'pageType' => 'guide',
        'pageTitle' => 'Guia do usuário',
        'pageSubtitle' => 'Visão geral do fluxo de uso do Deskhelper para abertura, acompanhamento e tratamento de ocorrências.',
        'shellTitle' => 'Guia do usuário',
        'shellSubtitle' => 'Orientações rápidas para operar o Deskhelper com clareza.',
        'shellBreadcrumbs' => [
            ['label' => 'Deskhelper', 'url' => app_url('app')],
            ['label' => 'Guia do usuário'],
        ],
    ]);
})->add(new DeskhelperAuthMiddleware());

$app->get('/help', function (Request $request, Response $response) use ($app) {
    $twig = $app->getContainer()->get(Twig::class);

    return $twig->render($response, 'deskhelper-doc-page.twig', [
        'pageType' => 'faq',
        'pageTitle' => 'FAQ',
        'pageSubtitle' => 'Perguntas frequentes sobre uso, perfis e acompanhamento de tickets no Deskhelper.',
        'shellTitle' => 'FAQ',
        'shellSubtitle' => 'Respostas rápidas para dúvidas recorrentes do ambiente.',
        'shellBreadcrumbs' => [
            ['label' => 'Deskhelper', 'url' => app_url('app')],
            ['label' => 'FAQ'],
        ],
    ]);
})->add(new DeskhelperAuthMiddleware());
