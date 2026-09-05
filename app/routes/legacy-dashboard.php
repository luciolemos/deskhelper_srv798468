<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use Slim\Views\Twig;

$app->get('/admin', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $flashPull, $navbarAuthContext, $buildGroupedSections) {
    if (empty($_SESSION['is_admin'])) {
        return $response->withHeader('Location', app_url('/admin/login'))->withStatus(302);
    }

    /** @var DashboardRepository $repo */
    $repo = $app->getContainer()->get(DashboardRepository::class);
    $twig = $app->getContainer()->get(Twig::class);
    $meta = $repo->getMeta();
    $groups = $repo->getAllGroups();
    $groupsBySlug = $repo->getGroupsBySlug();
    $sections = $repo->getSections();
    $cards = $repo->getAllCards();
    $sectionCardsCount = [];
    foreach ($cards as $card) {
        $sectionSlug = (string) ($card['section_slug'] ?? '');
        if ($sectionSlug === '') {
            continue;
        }

        if (!isset($sectionCardsCount[$sectionSlug])) {
            $sectionCardsCount[$sectionSlug] = 0;
        }

        $sectionCardsCount[$sectionSlug]++;
    }

    $cardRequests = $repo->getCardRequests();

    $query = $request->getQueryParams();
    $entity = (string) ($query['entity'] ?? 'groups');
    if ($entity === 'sections') {
        $entity = 'subgroups';
    }
    if (!in_array($entity, ['groups', 'subgroups', 'cards', 'requests'], true)) {
        $entity = 'groups';
    }

    $mode = (string) ($query['mode'] ?? 'list');
    if (!in_array($mode, ['list', 'new', 'edit'], true)) {
        $mode = 'list';
    }
    if ($entity === 'requests') {
        $mode = 'list';
    }

    $editingGroup = null;
    if ($entity === 'groups' && $mode === 'edit') {
        $groupSlug = strtolower(trim((string) ($query['slug'] ?? '')));
        if ($groupSlug !== '' && isset($groupsBySlug[$groupSlug])) {
            $editingGroup = $groupsBySlug[$groupSlug];
        } else {
            $mode = 'list';
        }
    }

    $editingSection = null;
    if ($entity === 'subgroups' && $mode === 'edit') {
        $slug = strtolower(trim((string) ($query['slug'] ?? '')));
        if ($slug !== '' && isset($sections[$slug])) {
            $editingSection = [
                'slug' => $slug,
                'label' => (string) ($sections[$slug]['label'] ?? ''),
                'description' => (string) ($sections[$slug]['description'] ?? ''),
                'group' => (string) ($sections[$slug]['group'] ?? 'Geral'),
                'group_slug' => (string) ($sections[$slug]['group_slug'] ?? 'geral'),
                'order' => (int) ($sections[$slug]['order'] ?? 99),
            ];
        } else {
            $mode = 'list';
        }
    }

    $editingCard = null;
    if ($entity === 'cards' && $mode === 'edit') {
        $cardId = (int) ($query['id'] ?? 0);
        foreach ($cards as $card) {
            if ((int) ($card['id'] ?? 0) === $cardId) {
                $editingCard = $card;
                break;
            }
        }

        if (!is_array($editingCard)) {
            $mode = 'list';
        }
    }

    $cardFilters = [
        'q' => trim((string) ($query['card_q'] ?? '')),
        'group' => strtolower(trim((string) ($query['card_group'] ?? ''))),
        'section' => strtolower(trim((string) ($query['card_section'] ?? ''))),
        'status' => trim((string) ($query['card_status'] ?? '')),
    ];

    $requestFilters = [
        'q' => trim((string) ($query['request_q'] ?? '')),
        'status' => strtolower(trim((string) ($query['request_status'] ?? ''))),
    ];
    $requestSort = strtolower(trim((string) ($query['request_sort'] ?? 'id')));
    $allowedRequestSorts = ['id', 'requester_rank', 'requester_name', 'title', 'group_label', 'subgroup_label', 'status'];
    if (!in_array($requestSort, $allowedRequestSorts, true)) {
        $requestSort = 'id';
    }
    $requestDirection = strtolower(trim((string) ($query['request_dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';

    $allowedRequestStatuses = ['pending', 'approved', 'rejected'];
    if ($requestFilters['status'] !== '' && !in_array($requestFilters['status'], $allowedRequestStatuses, true)) {
        $requestFilters['status'] = '';
    }

    $allowedStatuses = ['Interno', 'Externo', 'Sistema'];
    if ($cardFilters['status'] !== '' && !in_array($cardFilters['status'], $allowedStatuses, true)) {
        $cardFilters['status'] = '';
    }

    if ($cardFilters['group'] !== '' && !isset($groupsBySlug[$cardFilters['group']])) {
        $cardFilters['group'] = '';
    }

    if ($cardFilters['section'] !== '' && !isset($sections[$cardFilters['section']])) {
        $cardFilters['section'] = '';
    }

    if (
        $cardFilters['group'] !== ''
        && $cardFilters['section'] !== ''
        && (string) ($sections[$cardFilters['section']]['group_slug'] ?? '') !== $cardFilters['group']
    ) {
        $cardFilters['section'] = '';
    }

    $filteredCards = $cards;
    if ($entity === 'cards' && $mode === 'list') {
        $searchTerm = function_exists('mb_strtolower')
            ? mb_strtolower($cardFilters['q'])
            : strtolower($cardFilters['q']);

        $filteredCards = array_values(array_filter($cards, static function (array $card) use ($cardFilters, $searchTerm): bool {
            if ($cardFilters['group'] !== '' && (string) ($card['group_slug'] ?? '') !== $cardFilters['group']) {
                return false;
            }

            if ($cardFilters['section'] !== '' && (string) ($card['section_slug'] ?? '') !== $cardFilters['section']) {
                return false;
            }

            if ($cardFilters['status'] !== '' && (string) ($card['status'] ?? '') !== $cardFilters['status']) {
                return false;
            }

            if ($searchTerm !== '') {
                $title = (string) ($card['title'] ?? '');
                $description = (string) ($card['description'] ?? '');
                $haystack = function_exists('mb_strtolower')
                    ? mb_strtolower($title . ' ' . $description)
                    : strtolower($title . ' ' . $description);

                if (strpos($haystack, $searchTerm) === false) {
                    return false;
                }
            }

            return true;
        }));
    }

    $filteredRequests = $cardRequests;
    if ($entity === 'requests' && $mode === 'list') {
        $requestSearchTerm = function_exists('mb_strtolower')
            ? mb_strtolower($requestFilters['q'])
            : strtolower($requestFilters['q']);

        $filteredRequests = array_values(array_filter($cardRequests, static function (array $requestRow) use ($requestFilters, $requestSearchTerm): bool {
            $status = strtolower(trim((string) ($requestRow['status'] ?? '')));
            if ($requestFilters['status'] !== '' && $status !== $requestFilters['status']) {
                return false;
            }

            if ($requestSearchTerm !== '') {
                $haystack = implode(' ', [
                    (string) ($requestRow['requester_rank'] ?? ''),
                    (string) ($requestRow['requester_name'] ?? ''),
                    (string) ($requestRow['requester_contact'] ?? ''),
                    (string) ($requestRow['title'] ?? ''),
                    (string) ($requestRow['justification'] ?? ''),
                    (string) ($requestRow['href'] ?? ''),
                    (string) ($requestRow['group_label'] ?? ''),
                    (string) ($requestRow['subgroup_label'] ?? ''),
                ]);

                $normalizedHaystack = function_exists('mb_strtolower')
                    ? mb_strtolower($haystack)
                    : strtolower($haystack);

                if (strpos($normalizedHaystack, $requestSearchTerm) === false) {
                    return false;
                }
            }

            return true;
        }));

        usort($filteredRequests, static function (array $left, array $right) use ($requestSort, $requestDirection): int {
            $leftValue = match ($requestSort) {
                'requester_rank' => mb_strtolower((string) ($left['requester_rank'] ?? '')),
                'requester_name' => mb_strtolower((string) ($left['requester_name'] ?? '')),
                'title' => mb_strtolower((string) ($left['title'] ?? '')),
                'group_label' => mb_strtolower((string) (($left['group_label'] ?? $left['group_slug']) ?? '')),
                'subgroup_label' => mb_strtolower((string) (($left['subgroup_label'] ?? $left['subgroup_slug']) ?? '')),
                'status' => mb_strtolower((string) ($left['status'] ?? '')),
                default => (int) ($left['id'] ?? 0),
            };
            $rightValue = match ($requestSort) {
                'requester_rank' => mb_strtolower((string) ($right['requester_rank'] ?? '')),
                'requester_name' => mb_strtolower((string) ($right['requester_name'] ?? '')),
                'title' => mb_strtolower((string) ($right['title'] ?? '')),
                'group_label' => mb_strtolower((string) (($right['group_label'] ?? $right['group_slug']) ?? '')),
                'subgroup_label' => mb_strtolower((string) (($right['subgroup_label'] ?? $right['subgroup_slug']) ?? '')),
                'status' => mb_strtolower((string) ($right['status'] ?? '')),
                default => (int) ($right['id'] ?? 0),
            };

            if ($leftValue === $rightValue) {
                return 0;
            }

            $comparison = $leftValue <=> $rightValue;
            return $requestDirection === 'desc' ? -$comparison : $comparison;
        });
    }

    $allowedPerPage = [5, 10, 15, 20, 25, 50, 100];
    $perPage = (int) ($query['per_page'] ?? 10);
    if (!in_array($perPage, $allowedPerPage, true)) {
        $perPage = 10;
    }
    $requestedPage = max(1, (int) ($query['page'] ?? 1));

    $listedGroups = $groups;
    $listedSections = $sections;
    $listedCards = $filteredCards;
    $listedRequests = $filteredRequests;

    $groupsPagination = [
        'page' => 1,
        'totalPages' => 1,
        'totalItems' => count($groups),
    ];

    $sectionsPagination = [
        'page' => 1,
        'totalPages' => 1,
        'totalItems' => count($sections),
    ];

    $cardsPagination = [
        'page' => 1,
        'totalPages' => 1,
        'totalItems' => count($filteredCards),
    ];

    $requestsPagination = [
        'page' => 1,
        'totalPages' => 1,
        'totalItems' => count($filteredRequests),
    ];

    $appendPaginationRange = static function (array $pagination, int $itemsPerPage): array {
        $totalItems = max(0, (int) ($pagination['totalItems'] ?? 0));
        $page = max(1, (int) ($pagination['page'] ?? 1));

        if ($totalItems === 0) {
            $pagination['startItem'] = 0;
            $pagination['endItem'] = 0;
            return $pagination;
        }

        $startItem = (($page - 1) * $itemsPerPage) + 1;
        $endItem = min($totalItems, $startItem + $itemsPerPage - 1);

        $pagination['startItem'] = $startItem;
        $pagination['endItem'] = $endItem;

        return $pagination;
    };

    if ($entity === 'groups' && $mode === 'list') {
        $totalItems = count($groups);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($requestedPage, $totalPages);
        $offset = ($page - 1) * $perPage;

        $listedGroups = array_slice($groups, $offset, $perPage);
        $groupsPagination = [
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
        ];
    }

    if ($entity === 'subgroups' && $mode === 'list') {
        $totalItems = count($sections);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($requestedPage, $totalPages);
        $offset = ($page - 1) * $perPage;

        $listedSections = array_slice($sections, $offset, $perPage, true);
        $sectionsPagination = [
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
        ];
    }

    if ($entity === 'cards' && $mode === 'list') {
        $totalItems = count($filteredCards);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($requestedPage, $totalPages);
        $offset = ($page - 1) * $perPage;

        $listedCards = array_slice($filteredCards, $offset, $perPage);
        $cardsPagination = [
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
        ];
    }

    if ($entity === 'requests' && $mode === 'list') {
        $totalItems = count($filteredRequests);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($requestedPage, $totalPages);
        $offset = ($page - 1) * $perPage;

        $listedRequests = array_slice($filteredRequests, $offset, $perPage);
        $requestsPagination = [
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
        ];
    }

    $groupsPagination = $appendPaginationRange($groupsPagination, $perPage);
    $sectionsPagination = $appendPaginationRange($sectionsPagination, $perPage);
    $cardsPagination = $appendPaginationRange($cardsPagination, $perPage);
    $requestsPagination = $appendPaginationRange($requestsPagination, $perPage);

    $groupsPaginationQuery = [
        'entity' => 'groups',
        'mode' => 'list',
        'per_page' => $perPage,
    ];

    $sectionsPaginationQuery = [
        'entity' => 'subgroups',
        'mode' => 'list',
        'per_page' => $perPage,
    ];

    $cardsPaginationQuery = [
        'entity' => 'cards',
        'mode' => 'list',
        'per_page' => $perPage,
        'card_q' => $cardFilters['q'],
        'card_group' => $cardFilters['group'],
        'card_section' => $cardFilters['section'],
        'card_status' => $cardFilters['status'],
    ];

    $requestsPaginationQuery = [
        'entity' => 'requests',
        'mode' => 'list',
        'per_page' => $perPage,
        'request_q' => $requestFilters['q'],
        'request_status' => $requestFilters['status'],
        'request_sort' => $requestSort,
        'request_dir' => $requestDirection,
    ];

    return $twig->render($response, 'admin-dashboard.twig', array_merge([
        'groups' => $groups,
        'groupsBySlug' => $groupsBySlug,
        'sections' => $sections,
        'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
        'cards' => $cards,
        'sectionCardsCount' => $sectionCardsCount,
        'listedGroups' => $listedGroups,
        'listedSections' => $listedSections,
        'listedCards' => $listedCards,
        'listedRequests' => $listedRequests,
        'filteredCards' => $filteredCards,
        'filteredRequests' => $filteredRequests,
        'cardFilters' => $cardFilters,
        'requestFilters' => $requestFilters,
        'requestSort' => $requestSort,
        'requestDirection' => $requestDirection,
        'groupsPagination' => $groupsPagination,
        'sectionsPagination' => $sectionsPagination,
        'cardsPagination' => $cardsPagination,
        'requestsPagination' => $requestsPagination,
        'groupsPaginationQuery' => $groupsPaginationQuery,
        'sectionsPaginationQuery' => $sectionsPaginationQuery,
        'cardsPaginationQuery' => $cardsPaginationQuery,
        'requestsPaginationQuery' => $requestsPaginationQuery,
        'allowedPerPage' => $allowedPerPage,
        'currentPerPage' => $perPage,
        'flashMessage' => $flashPull(),
        'csrfToken' => $ensureCsrfToken(),
        'lastUpdated' => date('d/m/Y H:i'),
        'activeSection' => 'admin',
        'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
        'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
        'isAdminView' => true,
        'adminEntity' => $entity,
        'adminMode' => $mode,
        'editingGroup' => $editingGroup,
        'editingSection' => $editingSection,
        'editingCard' => $editingCard,
        'cardRequests' => $cardRequests,
    ], $navbarAuthContext()));
});

$app->post('/admin/card-requests/approve', function (Request $request, Response $response) use ($app, $isValidCsrf, $normalizeHref) {
    if (empty($_SESSION['is_admin'])) {
        return $response->withHeader('Location', app_url('/admin/login'))->withStatus(302);
    }

    if (!$isValidCsrf($request)) {
        $_SESSION['admin_flash'] = 'Falha ao aprovar solicitação: token CSRF inválido.';
        return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
    }

    /** @var DashboardRepository $repo */
    $repo = $app->getContainer()->get(DashboardRepository::class);
    $data = (array) ($request->getParsedBody() ?? []);
    $id = (int) ($data['id'] ?? 0);

    $requestRow = $repo->getCardRequestById($id);
    if (!$requestRow || (string) ($requestRow['status'] ?? '') !== 'pending') {
        $_SESSION['admin_flash'] = 'Solicitação inválida ou já processada.';
        return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
    }

    $sectionSlug = strtolower(trim((string) ($requestRow['subgroup_slug'] ?? '')));
    $title = trim((string) ($requestRow['title'] ?? ''));
    $href = $normalizeHref((string) ($requestRow['href'] ?? ''));
    $description = trim((string) ($requestRow['justification'] ?? ''));

    if ($sectionSlug === '' || $title === '' || preg_match('/^https:\/\//i', $href) !== 1) {
        $_SESSION['admin_flash'] = 'Falha ao aprovar: dados da solicitação estão incompletos.';
        return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
    }

    try {
        $createdCardId = $repo->createCard([
            'section_slug' => $sectionSlug,
            'title' => $title,
            'href' => $href,
            'external' => true,
            'icon' => 'bi-globe2',
            'status' => 'Externo',
            'description' => $description,
            'order' => 99,
        ]);

        $repo->updateCardRequestStatus($id, 'approved', (string) ($_SESSION['admin_user'] ?? 'admin'), $createdCardId);
        $_SESSION['admin_flash'] = 'Solicitação aprovada e card criado com sucesso.';
    } catch (\Throwable $throwable) {
        $_SESSION['admin_flash'] = 'Falha ao aprovar solicitação: ' . $throwable->getMessage();
    }

    return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
});

$app->post('/admin/card-requests/reject', function (Request $request, Response $response) use ($app, $isValidCsrf) {
    if (empty($_SESSION['is_admin'])) {
        return $response->withHeader('Location', app_url('/admin/login'))->withStatus(302);
    }

    if (!$isValidCsrf($request)) {
        $_SESSION['admin_flash'] = 'Falha ao rejeitar solicitação: token CSRF inválido.';
        return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
    }

    /** @var DashboardRepository $repo */
    $repo = $app->getContainer()->get(DashboardRepository::class);
    $data = (array) ($request->getParsedBody() ?? []);
    $id = (int) ($data['id'] ?? 0);
    $note = trim((string) ($data['admin_note'] ?? ''));

    $requestRow = $repo->getCardRequestById($id);
    if (!$requestRow || (string) ($requestRow['status'] ?? '') !== 'pending') {
        $_SESSION['admin_flash'] = 'Solicitação inválida ou já processada.';
        return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
    }

    $repo->updateCardRequestStatus($id, 'rejected', (string) ($_SESSION['admin_user'] ?? 'admin'), null, $note);
    $_SESSION['admin_flash'] = 'Solicitação rejeitada.';

    return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
});
