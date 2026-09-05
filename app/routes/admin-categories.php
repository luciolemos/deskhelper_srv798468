<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperTicketRepository;
use App\Application\Middleware\DeskhelperAuthMiddleware;
use App\Application\Middleware\DeskhelperRoleMiddleware;
use Slim\Views\Twig;

$app->get('/admin/categories', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $deskhelperCurrentUser, $deskhelperFlashPull) {
    $twig = $app->getContainer()->get(Twig::class);
    /** @var DeskhelperTicketRepository $tickets */
    $tickets = $app->getContainer()->get(DeskhelperTicketRepository::class);
    $query = $request->getQueryParams();
    $requestedView = strtolower(trim((string) ($query['view'] ?? '')));
    $allowedViews = ['indicators', 'list'];
    $categoriesView = in_array($requestedView, $allowedViews, true) ? $requestedView : 'indicators';
    $allCategories = $tickets->getAllCategories();
    $search = trim((string) ($query['q'] ?? ''));
    $activeFilter = trim((string) ($query['active'] ?? ''));
    $sort = strtolower(trim((string) ($query['sort'] ?? 'sort_order')));
    $allowedSorts = ['slug', 'label', 'sort_order', 'active'];
    if (!in_array($sort, $allowedSorts, true)) {
        $sort = 'sort_order';
    }
    $direction = strtolower(trim((string) ($query['dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
    $perPageRaw = strtolower(trim((string) ($query['per_page'] ?? '10')));
    $allowedPerPage = ['5', '10', '15', '20', '25', '50', '100', 'all'];
    if (!in_array($perPageRaw, $allowedPerPage, true)) {
        $perPageRaw = '10';
    }
    $page = max(1, (int) ($query['page'] ?? 1));
    $categories = $allCategories;
    if ($search !== '' || $activeFilter !== '') {
        $categories = array_values(array_filter(
            $allCategories,
            static function (array $category) use ($search, $activeFilter): bool {
                if ($activeFilter !== '') {
                    $isActive = !empty($category['active']);
                    if ($activeFilter === '1' && !$isActive) {
                        return false;
                    }
                    if ($activeFilter === '0' && $isActive) {
                        return false;
                    }
                }

                if ($search === '') {
                    return true;
                }

                $needle = mb_strtolower($search);
                $haystacks = [
                    mb_strtolower((string) ($category['slug'] ?? '')),
                    mb_strtolower((string) ($category['label'] ?? '')),
                ];

                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && mb_strpos($haystack, $needle) !== false) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }
    usort($categories, static function (array $left, array $right) use ($sort, $direction): int {
        $leftValue = match ($sort) {
            'slug' => mb_strtolower((string) ($left['slug'] ?? '')),
            'label' => mb_strtolower((string) ($left['label'] ?? '')),
            'active' => !empty($left['active']) ? 1 : 0,
            default => (int) ($left['sort_order'] ?? 9999),
        };
        $rightValue = match ($sort) {
            'slug' => mb_strtolower((string) ($right['slug'] ?? '')),
            'label' => mb_strtolower((string) ($right['label'] ?? '')),
            'active' => !empty($right['active']) ? 1 : 0,
            default => (int) ($right['sort_order'] ?? 9999),
        };

        if ($leftValue === $rightValue) {
            return 0;
        }

        $comparison = $leftValue <=> $rightValue;
        return $direction === 'desc' ? -$comparison : $comparison;
    });
    $totalCategories = count($categories);
    $perPage = $perPageRaw === 'all' ? max(1, $totalCategories) : (int) $perPageRaw;
    $totalPages = $totalCategories === 0 ? 1 : (int) ceil($totalCategories / max(1, $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * max(1, $perPage);
    $pagedCategories = $perPageRaw === 'all' ? $categories : array_slice($categories, $offset, $perPage);
    $categoriesListQuery = array_filter([
        'view' => 'list',
        'q' => $search,
        'active' => $activeFilter,
        'sort' => $sort,
        'dir' => $direction,
        'per_page' => $perPageRaw,
    ], static fn (mixed $value): bool => $value !== '' && $value !== null);
    $activeCount = 0;
    $inactiveCount = 0;
    foreach ($allCategories as $category) {
        if (!empty($category['active'])) {
            $activeCount++;
        } else {
            $inactiveCount++;
        }
    }

    return $twig->render($response, 'deskhelper-admin-categories.twig', [
        'user' => $deskhelperCurrentUser(),
        'categories' => $pagedCategories,
        'categoriesView' => $categoriesView,
        'filters' => [
            'q' => $search,
            'active' => $activeFilter,
        ],
        'categoriesListQuery' => $categoriesListQuery,
        'sort' => $sort,
        'direction' => $direction,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPageRaw,
            'total_items' => $totalCategories,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => max(1, $page - 1),
            'next_page' => min($totalPages, $page + 1),
            'from' => $totalCategories === 0 ? 0 : $offset + 1,
            'to' => $totalCategories === 0 ? 0 : $offset + count($pagedCategories),
        ],
        'categoriesSummary' => [
            'total' => count($allCategories),
            'active' => $activeCount,
            'inactive' => $inactiveCount,
        ],
        'flashMessage' => $deskhelperFlashPull(),
        'csrfToken' => $ensureCsrfToken(),
        'shellTitle' => $categoriesView === 'list' ? 'Lista' : 'Indicadores',
        'shellSubtitle' => $categoriesView === 'list'
            ? 'Tabela completa das categorias fixas e dos parâmetros de uso.'
            : 'Visão resumida das categorias disponíveis para abertura de tickets.',
        'shellBreadcrumbs' => [
            ['label' => 'Deskhelper', 'url' => app_url('app')],
            ['label' => 'Admin', 'url' => app_url('admin/users/pending?view=indicators')],
            ['label' => 'Categorias', 'url' => app_url('admin/categories?view=indicators')],
        ],
    ]);
})->add(new DeskhelperRoleMiddleware(['admin']))->add(new DeskhelperAuthMiddleware());

$app->post('/admin/categories/update', function (Request $request, Response $response) use ($app, $isValidCsrf, $deskhelperSetFlash) {
    if (!$isValidCsrf($request)) {
        $deskhelperSetFlash('Falha de validacao CSRF. Atualize a pagina e tente novamente.');
        return $response->withHeader('Location', app_url('/admin/categories'))->withStatus(302);
    }

    /** @var DeskhelperTicketRepository $tickets */
    $tickets = $app->getContainer()->get(DeskhelperTicketRepository::class);
    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];

    try {
        $tickets->updateCategory(
            (int) ($data['id'] ?? 0),
            (string) ($data['label'] ?? ''),
            (int) ($data['sort_order'] ?? 99),
            ((string) ($data['active'] ?? '0')) === '1'
        );
        $deskhelperSetFlash('Categoria atualizada com sucesso.');
    } catch (\Throwable $throwable) {
        $deskhelperSetFlash($throwable->getMessage());
    }

    return $response->withHeader('Location', app_url('/admin/categories'))->withStatus(302);
})->add(new DeskhelperRoleMiddleware(['admin']))->add(new DeskhelperAuthMiddleware());
