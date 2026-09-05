<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use App\Application\Middleware\DeskhelperAuthMiddleware;
use App\Application\Middleware\DeskhelperRoleMiddleware;
use Slim\Views\Twig;

$app->get('/admin/users', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $deskhelperCurrentUser, $deskhelperFlashPull) {
    $twig = $app->getContainer()->get(Twig::class);
    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);
    $query = $request->getQueryParams();
    $requestedView = strtolower(trim((string) ($query['view'] ?? '')));
    $allowedViews = ['indicators', 'list'];
    $usersView = in_array($requestedView, $allowedViews, true) ? $requestedView : 'indicators';
    $allUsers = $users->getAllUsers();
    $nameFilter = trim((string) ($query['name'] ?? ''));
    $emailFilter = trim((string) ($query['email'] ?? ''));
    $statusFilter = strtolower(trim((string) ($query['status'] ?? '')));
    $roleFilter = strtolower(trim((string) ($query['role'] ?? '')));
    $sort = strtolower(trim((string) ($query['sort'] ?? 'name')));
    $allowedSorts = ['rank', 'name', 'email', 'status', 'sector', 'roles'];
    if (!in_array($sort, $allowedSorts, true)) {
        $sort = 'name';
    }
    $direction = strtolower(trim((string) ($query['dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
    $perPageRaw = strtolower(trim((string) ($query['per_page'] ?? '10')));
    $allowedPerPage = ['5', '10', '15', '20', '25', '50', '100', 'all'];
    if (!in_array($perPageRaw, $allowedPerPage, true)) {
        $perPageRaw = '10';
    }
    $page = max(1, (int) ($query['page'] ?? 1));
    $managedUsers = $allUsers;
    $managedUsers = array_values(array_filter(
        $allUsers,
        static function (array $managedUser) use ($nameFilter, $emailFilter, $statusFilter, $roleFilter): bool {
            if ($nameFilter !== '') {
                $name = mb_strtolower((string) ($managedUser['name'] ?? ''));
                if ($name === '' || mb_strpos($name, mb_strtolower($nameFilter)) === false) {
                    return false;
                }
            }

            if ($emailFilter !== '') {
                $email = mb_strtolower((string) ($managedUser['email'] ?? ''));
                if ($email === '' || mb_strpos($email, mb_strtolower($emailFilter)) === false) {
                    return false;
                }
            }

            if ($statusFilter !== '') {
                $status = mb_strtolower((string) ($managedUser['status'] ?? ''));
                if ($status !== $statusFilter) {
                    return false;
                }
            }

            if ($roleFilter !== '') {
                $roles = array_map(
                    static fn (mixed $role): string => mb_strtolower((string) $role),
                    (array) ($managedUser['roles'] ?? [])
                );
                if (!in_array($roleFilter, $roles, true)) {
                    return false;
                }
            }

            return true;
        }
    ));
    usort($managedUsers, static function (array $left, array $right) use ($sort, $direction): int {
        $leftValue = match ($sort) {
            'rank' => mb_strtolower((string) ($left['rank'] ?? '')),
            'email' => mb_strtolower((string) ($left['email'] ?? '')),
            'status' => mb_strtolower((string) ($left['status'] ?? '')),
            'sector' => mb_strtolower((string) ($left['sector'] ?? '')),
            'roles' => mb_strtolower(implode(', ', (array) ($left['roles'] ?? []))),
            default => mb_strtolower((string) ($left['name'] ?? '')),
        };
        $rightValue = match ($sort) {
            'rank' => mb_strtolower((string) ($right['rank'] ?? '')),
            'email' => mb_strtolower((string) ($right['email'] ?? '')),
            'status' => mb_strtolower((string) ($right['status'] ?? '')),
            'sector' => mb_strtolower((string) ($right['sector'] ?? '')),
            'roles' => mb_strtolower(implode(', ', (array) ($right['roles'] ?? []))),
            default => mb_strtolower((string) ($right['name'] ?? '')),
        };

        if ($leftValue === $rightValue) {
            return 0;
        }

        $comparison = $leftValue <=> $rightValue;
        return $direction === 'desc' ? -$comparison : $comparison;
    });
    $totalUsers = count($managedUsers);
    $perPage = $perPageRaw === 'all' ? max(1, $totalUsers) : (int) $perPageRaw;
    $totalPages = $totalUsers === 0 ? 1 : (int) ceil($totalUsers / max(1, $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * max(1, $perPage);
    $pagedUsers = $perPageRaw === 'all' ? $managedUsers : array_slice($managedUsers, $offset, $perPage);
    $usersListQuery = array_filter([
        'view' => 'list',
        'name' => $nameFilter,
        'email' => $emailFilter,
        'status' => $statusFilter,
        'role' => $roleFilter,
        'sort' => $sort,
        'dir' => $direction,
        'per_page' => $perPageRaw,
    ], static fn (mixed $value): bool => $value !== '' && $value !== null);
    $roleSummary = $users->getRoleSummary();
    $statusSummary = [
        'active' => 0,
        'disabled' => 0,
        'pending' => 0,
        'rejected' => 0,
    ];
    foreach ($allUsers as $managedUser) {
        $status = strtolower(trim((string) ($managedUser['status'] ?? '')));
        if (array_key_exists($status, $statusSummary)) {
            $statusSummary[$status]++;
        }
    }

    return $twig->render($response, 'deskhelper-admin-users.twig', [
        'user' => $deskhelperCurrentUser(),
        'users' => $pagedUsers,
        'usersView' => $usersView,
        'filters' => [
            'name' => $nameFilter,
            'email' => $emailFilter,
            'status' => $statusFilter,
            'role' => $roleFilter,
        ],
        'usersListQuery' => $usersListQuery,
        'sort' => $sort,
        'direction' => $direction,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPageRaw,
            'total_items' => $totalUsers,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => max(1, $page - 1),
            'next_page' => min($totalPages, $page + 1),
            'from' => $totalUsers === 0 ? 0 : $offset + 1,
            'to' => $totalUsers === 0 ? 0 : $offset + count($pagedUsers),
        ],
        'roleSummary' => $roleSummary,
        'statusSummary' => $statusSummary,
        'flashMessage' => $deskhelperFlashPull(),
        'csrfToken' => $ensureCsrfToken(),
        'shellTitle' => $usersView === 'list' ? 'Lista' : 'Indicadores',
        'shellSubtitle' => $usersView === 'list'
            ? 'Tabela de usuários com status, perfis e ações administrativas.'
            : 'Visão resumida da base de usuários e da distribuição de perfis.',
        'shellBreadcrumbs' => [
            ['label' => 'Deskhelper', 'url' => app_url('app')],
            ['label' => 'Admin', 'url' => app_url('admin/users/pending?view=indicators')],
            ['label' => 'Usuários', 'url' => app_url('admin/users?view=indicators')],
        ],
    ]);
})->add(new DeskhelperRoleMiddleware(['admin']))->add(new DeskhelperAuthMiddleware());

$app->post('/admin/users/update', function (Request $request, Response $response) use ($app, $isValidCsrf, $deskhelperSetFlash) {
    if (!$isValidCsrf($request)) {
        $deskhelperSetFlash('Falha de validacao CSRF. Atualize a pagina e tente novamente.');
        return $response->withHeader('Location', app_url('/admin/users'))->withStatus(302);
    }

    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);
    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];
    $userId = (int) ($data['id'] ?? 0);

    try {
        $users->updateAccess($userId, (string) ($data['status'] ?? 'active'), [(string) ($data['role'] ?? 'user')]);
        $deskhelperSetFlash('Usuário atualizado com sucesso.');
    } catch (\Throwable $throwable) {
        $deskhelperSetFlash($throwable->getMessage());
    }

    return $response->withHeader('Location', app_url('/admin/users'))->withStatus(302);
})->add(new DeskhelperRoleMiddleware(['admin']))->add(new DeskhelperAuthMiddleware());

$app->get('/admin/users/pending', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $deskhelperCurrentUser, $deskhelperFlashPull) {
    $twig = $app->getContainer()->get(Twig::class);
    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);
    $query = $request->getQueryParams();
    $requestedView = strtolower(trim((string) ($query['view'] ?? '')));
    $allowedViews = ['indicators', 'list'];
    $pendingView = in_array($requestedView, $allowedViews, true) ? $requestedView : 'indicators';
    $allPendingUsers = $users->getPendingUsers();
    $nameFilter = trim((string) ($query['name'] ?? ''));
    $emailFilter = trim((string) ($query['email'] ?? ''));
    $sectorFilter = trim((string) ($query['sector'] ?? ''));
    $sort = strtolower(trim((string) ($query['sort'] ?? 'created_at')));
    $allowedSorts = ['rank', 'name', 'email', 'sector', 'phone', 'created_at'];
    if (!in_array($sort, $allowedSorts, true)) {
        $sort = 'created_at';
    }
    $direction = strtolower(trim((string) ($query['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
    $perPageRaw = strtolower(trim((string) ($query['per_page'] ?? '10')));
    $allowedPerPage = ['5', '10', '15', '20', '25', '50', '100', 'all'];
    if (!in_array($perPageRaw, $allowedPerPage, true)) {
        $perPageRaw = '10';
    }
    $page = max(1, (int) ($query['page'] ?? 1));
    $pendingUsers = $allPendingUsers;
    $pendingUsers = array_values(array_filter(
        $allPendingUsers,
        static function (array $pendingUser) use ($nameFilter, $emailFilter, $sectorFilter): bool {
            if ($nameFilter !== '') {
                $name = mb_strtolower((string) ($pendingUser['name'] ?? ''));
                if ($name === '' || mb_strpos($name, mb_strtolower($nameFilter)) === false) {
                    return false;
                }
            }

            if ($emailFilter !== '') {
                $email = mb_strtolower((string) ($pendingUser['email'] ?? ''));
                if ($email === '' || mb_strpos($email, mb_strtolower($emailFilter)) === false) {
                    return false;
                }
            }

            if ($sectorFilter !== '') {
                $sector = trim((string) ($pendingUser['sector'] ?? ''));
                if ($sectorFilter === '__none__') {
                    if ($sector !== '') {
                        return false;
                    }
                } else {
                    $sectorValue = mb_strtolower($sector);
                    if ($sectorValue === '' || mb_strpos($sectorValue, mb_strtolower($sectorFilter)) === false) {
                        return false;
                    }
                }
            }

            return true;
        }
    ));
    usort($pendingUsers, static function (array $left, array $right) use ($sort, $direction): int {
        $leftValue = match ($sort) {
            'rank' => mb_strtolower((string) ($left['rank'] ?? '')),
            'name' => mb_strtolower((string) ($left['name'] ?? '')),
            'email' => mb_strtolower((string) ($left['email'] ?? '')),
            'sector' => mb_strtolower((string) ($left['sector'] ?? '')),
            'phone' => mb_strtolower((string) ($left['phone'] ?? '')),
            default => mb_strtolower((string) ($left['created_at'] ?? '')),
        };
        $rightValue = match ($sort) {
            'rank' => mb_strtolower((string) ($right['rank'] ?? '')),
            'name' => mb_strtolower((string) ($right['name'] ?? '')),
            'email' => mb_strtolower((string) ($right['email'] ?? '')),
            'sector' => mb_strtolower((string) ($right['sector'] ?? '')),
            'phone' => mb_strtolower((string) ($right['phone'] ?? '')),
            default => mb_strtolower((string) ($right['created_at'] ?? '')),
        };

        if ($leftValue === $rightValue) {
            return 0;
        }

        $comparison = $leftValue <=> $rightValue;
        return $direction === 'desc' ? -$comparison : $comparison;
    });
    $totalPendingUsers = count($pendingUsers);
    $perPage = $perPageRaw === 'all' ? max(1, $totalPendingUsers) : (int) $perPageRaw;
    $totalPages = $totalPendingUsers === 0 ? 1 : (int) ceil($totalPendingUsers / max(1, $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * max(1, $perPage);
    $pagedPendingUsers = $perPageRaw === 'all' ? $pendingUsers : array_slice($pendingUsers, $offset, $perPage);
    $pendingUsersListQuery = array_filter([
        'view' => 'list',
        'name' => $nameFilter,
        'email' => $emailFilter,
        'sector' => $sectorFilter,
        'sort' => $sort,
        'dir' => $direction,
        'per_page' => $perPageRaw,
    ], static fn (mixed $value): bool => $value !== '' && $value !== null);
    $sectorSummary = [];
    foreach ($allPendingUsers as $pendingUser) {
        $sector = trim((string) ($pendingUser['sector'] ?? ''));
        $label = $sector !== '' ? $sector : 'Sem SU';
        $sectorSummary[$label] = ($sectorSummary[$label] ?? 0) + 1;
    }
    arsort($sectorSummary);
    $sectorSummaryRows = [];
    foreach (array_slice($sectorSummary, 0, 6, true) as $label => $total) {
        $sectorSummaryRows[] = [
            'label' => $label,
            'total' => $total,
        ];
    }

    return $twig->render($response, 'deskhelper-admin-users-pending.twig', [
        'pendingUsers' => $pagedPendingUsers,
        'pendingView' => $pendingView,
        'filters' => [
            'name' => $nameFilter,
            'email' => $emailFilter,
            'sector' => $sectorFilter,
        ],
        'pendingUsersListQuery' => $pendingUsersListQuery,
        'sort' => $sort,
        'direction' => $direction,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPageRaw,
            'total_items' => $totalPendingUsers,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => max(1, $page - 1),
            'next_page' => min($totalPages, $page + 1),
            'from' => $totalPendingUsers === 0 ? 0 : $offset + 1,
            'to' => $totalPendingUsers === 0 ? 0 : $offset + count($pagedPendingUsers),
        ],
        'pendingSummary' => [
            'total' => count($allPendingUsers),
            'with_sector' => count(array_filter($allPendingUsers, static fn (array $row): bool => trim((string) ($row['sector'] ?? '')) !== '')),
            'without_sector' => count(array_filter($allPendingUsers, static fn (array $row): bool => trim((string) ($row['sector'] ?? '')) === '')),
        ],
        'pendingSectorSummary' => $sectorSummaryRows,
        'user' => $deskhelperCurrentUser(),
        'flashMessage' => $deskhelperFlashPull(),
        'csrfToken' => $ensureCsrfToken(),
        'shellTitle' => $pendingView === 'list' ? 'Lista' : 'Indicadores',
        'shellSubtitle' => $pendingView === 'list'
            ? 'Tabela dos cadastros aguardando aprovação, rejeição e perfil inicial.'
            : 'Visão resumida dos cadastros pendentes e da origem das solicitações.',
        'shellBreadcrumbs' => [
            ['label' => 'Deskhelper', 'url' => app_url('app')],
            ['label' => 'Admin', 'url' => app_url('admin/users/pending?view=indicators')],
            ['label' => 'Usuários pendentes', 'url' => app_url('admin/users/pending?view=indicators')],
        ],
    ]);
})->add(new DeskhelperRoleMiddleware(['admin']))->add(new DeskhelperAuthMiddleware());

$app->post('/admin/users/approve', function (Request $request, Response $response) use ($app, $isValidCsrf, $deskhelperCurrentUser, $deskhelperSetFlash) {
    if (!$isValidCsrf($request)) {
        $deskhelperSetFlash('Falha de validacao CSRF. Atualize a pagina e tente novamente.');
        return $response->withHeader('Location', app_url('/admin/users/pending'))->withStatus(302);
    }

    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);
    $currentUser = $deskhelperCurrentUser();
    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];

    try {
        $users->approveUser(
            (int) ($data['id'] ?? 0),
            (int) ($currentUser['id'] ?? 0),
            [(string) ($data['role'] ?? 'user')]
        );
        $deskhelperSetFlash('Usuario aprovado com sucesso.');
    } catch (\Throwable $throwable) {
        $deskhelperSetFlash($throwable->getMessage());
    }

    return $response->withHeader('Location', app_url('/admin/users/pending'))->withStatus(302);
})->add(new DeskhelperRoleMiddleware(['admin']))->add(new DeskhelperAuthMiddleware());

$app->post('/admin/users/reject', function (Request $request, Response $response) use ($app, $isValidCsrf, $deskhelperCurrentUser, $deskhelperSetFlash) {
    if (!$isValidCsrf($request)) {
        $deskhelperSetFlash('Falha de validacao CSRF. Atualize a pagina e tente novamente.');
        return $response->withHeader('Location', app_url('/admin/users/pending'))->withStatus(302);
    }

    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);
    $currentUser = $deskhelperCurrentUser();
    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];

    try {
        $users->rejectUser(
            (int) ($data['id'] ?? 0),
            (int) ($currentUser['id'] ?? 0)
        );
        $deskhelperSetFlash('Usuario rejeitado com sucesso.');
    } catch (\Throwable $throwable) {
        $deskhelperSetFlash($throwable->getMessage());
    }

    return $response->withHeader('Location', app_url('/admin/users/pending'))->withStatus(302);
})->add(new DeskhelperRoleMiddleware(['admin']))->add(new DeskhelperAuthMiddleware());
