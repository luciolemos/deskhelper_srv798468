<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use Slim\Views\Twig;

$app->get('/{section}', function (Request $request, Response $response, array $args) use ($app, $navbarAuthContext, $buildGroupedSections) {
    /** @var DashboardRepository $repo */
    $repo = $app->getContainer()->get(DashboardRepository::class);
    $twig = $app->getContainer()->get(Twig::class);
    $meta = $repo->getMeta();
    $sections = $repo->getSections();
    $lastUpdated = date('d/m/Y H:i');
    $activeSection = strtolower((string) ($args['section'] ?? 'secao-1'));

    if (!isset($sections[$activeSection])) {
        return $response
            ->withHeader('Location', app_url(''))
            ->withStatus(302);
    }

    $activeSectionMeta = $sections[$activeSection];
    $activeCards = $repo->getCardsForSection($activeSection);

    return $twig->render($response, 'dashboard.twig', array_merge([
        'sections' => $sections,
        'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
        'activeSection' => $activeSection,
        'activeSectionMeta' => $activeSectionMeta,
        'cards' => $activeCards,
        'lastUpdated' => $lastUpdated,
        'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
        'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
    ], $navbarAuthContext()));
});
