<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperTicketRepository;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use Slim\Views\Twig;

$app->get('/app', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $deskhelperCurrentUser, $deskhelperFlashPull, $deskhelperHasRole) {
    $twig = $app->getContainer()->get(Twig::class);
    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);
    /** @var DeskhelperTicketRepository $tickets */
    $tickets = $app->getContainer()->get(DeskhelperTicketRepository::class);

    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    $userId = (int) ($user['id'] ?? 0);
    $roles = array_map(
        static fn (mixed $value): string => strtolower(trim((string) $value)),
        (array) ($user['roles'] ?? [])
    );
    $isAdmin = in_array('admin', $roles, true);
    $isOperator = in_array('operator', $roles, true);
    $query = $request->getQueryParams();
    $requestedPanel = strtolower(trim((string) ($query['panel'] ?? '')));
    $dashboardSearch = trim((string) ($query['q'] ?? ''));
    $backlogFilters = [
        'q' => $dashboardSearch,
        'status' => trim((string) ($query['status'] ?? '')),
        'category_id' => (int) ($query['category_id'] ?? 0),
        'assigned_to_user_id' => (int) ($query['assigned_to_user_id'] ?? 0),
    ];

    $summary = [
        'pendingUsers' => $isAdmin ? $users->countPending() : 0,
        'activeUsers' => $isAdmin ? $users->countByStatus('active') : 0,
        'disabledUsers' => $isAdmin ? $users->countByStatus('disabled') : 0,
        'myTickets' => $tickets->countCreatedBy($userId),
        'assignedTickets' => ($isOperator || $isAdmin) ? $tickets->countAssignedTo($userId) : 0,
        'totalTickets' => $isAdmin ? $tickets->countAll() : $tickets->countCreatedBy($userId),
        'status' => $isAdmin ? $tickets->getStatusSummary() : $tickets->getStatusSummaryForCreator($userId),
        'unassignedTickets' => $isAdmin ? $tickets->countUnassigned() : 0,
        'roleSummary' => $isAdmin ? $users->getRoleSummary() : [],
        'categorySummary' => $isAdmin ? $tickets->getCategorySummary(5) : [],
        'totalCategories' => $isAdmin ? count($tickets->getAllCategories()) : 0,
        'assignmentSummary' => $isAdmin ? $tickets->getAssignmentSummary(5) : [],
        'recentTickets' => ($isAdmin || $isOperator) ? $tickets->getRecentTickets(6) : [],
    ];

    $template = 'deskhelper-app.twig';
    $dashboardPanel = 'overview';
    $shellTitle = 'Dashboard';
    $shellSubtitle = 'Acompanhe suas solicitações e abra novos tickets quando necessário.';
    $shellBreadcrumbs = [
        ['label' => 'Deskhelper', 'url' => app_url('app')],
        ['label' => 'Dashboard'],
    ];

    if ($isOperator && !$isAdmin) {
        $allowedPanels = ['overview', 'actions', 'category_summary', 'recent'];
        $dashboardPanel = in_array($requestedPanel, $allowedPanels, true) ? $requestedPanel : 'overview';
        $visibleTickets = $tickets->getTicketsForUser($userId, $roles);
        $operatorStatus = [
            'open' => 0,
            'in_progress' => 0,
            'resolved' => 0,
            'closed' => 0,
            'cancelled' => 0,
        ];
        $operatorCategories = [];
        $highPriorityOpen = 0;

        foreach ($visibleTickets as $ticket) {
            $status = (string) ($ticket['status'] ?? '');
            if (array_key_exists($status, $operatorStatus)) {
                $operatorStatus[$status]++;
            }

            $categoryLabel = trim((string) ($ticket['category_label'] ?? 'Sem categoria'));
            $operatorCategories[$categoryLabel] = ($operatorCategories[$categoryLabel] ?? 0) + 1;

            $priority = strtolower(trim((string) ($ticket['priority'] ?? '')));
            if (
                $priority === 'high'
                && in_array($status, ['open', 'in_progress'], true)
            ) {
                $highPriorityOpen++;
            }
        }

        arsort($operatorCategories);
        $categorySummary = [];
        foreach (array_slice($operatorCategories, 0, 5, true) as $label => $total) {
            $categorySummary[] = [
                'label' => $label,
                'total' => $total,
            ];
        }

        $summary['totalTickets'] = count($visibleTickets);
        $summary['status'] = $operatorStatus;
        $summary['categorySummary'] = $categorySummary;
        $summary['recentTickets'] = array_slice($visibleTickets, 0, 6);
        $summary['highPriorityOpen'] = $highPriorityOpen;
        $summary['recentPagination'] = [
            'page' => 1,
            'per_page' => '10',
            'total_items' => count($summary['recentTickets']),
            'total_pages' => 1,
            'has_prev' => false,
            'has_next' => false,
            'prev_page' => 1,
            'next_page' => 1,
            'from' => count($summary['recentTickets']) > 0 ? 1 : 0,
            'to' => count($summary['recentTickets']),
        ];
        $summary['recentSort'] = 'updated_at';
        $summary['recentDirection'] = 'desc';
        $summary['recentQuery'] = ['panel' => 'recent'];
        if ($dashboardPanel === 'recent') {
            $recentFilters = [
                'q' => $dashboardSearch,
                'status' => trim((string) ($query['status'] ?? '')),
                'priority' => trim((string) ($query['priority'] ?? '')),
            ];
            $recentSort = strtolower(trim((string) ($query['sort'] ?? 'updated_at')));
            $recentDirection = strtolower(trim((string) ($query['dir'] ?? 'desc')));
            $allowedRecentSorts = ['id', 'title', 'status', 'priority', 'updated_at'];
            if (!in_array($recentSort, $allowedRecentSorts, true)) {
                $recentSort = 'updated_at';
            }
            if (!in_array($recentDirection, ['asc', 'desc'], true)) {
                $recentDirection = 'desc';
            }
            $recentRows = array_values(array_filter(
                $visibleTickets,
                static function (array $ticket) use ($recentFilters): bool {
                    $search = mb_strtolower($recentFilters['q']);
                    if ($search !== '') {
                        $haystacks = [
                            mb_strtolower((string) ($ticket['title'] ?? '')),
                            mb_strtolower((string) ($ticket['status'] ?? '')),
                            mb_strtolower((string) ($ticket['priority'] ?? '')),
                            mb_strtolower((string) ($ticket['category_label'] ?? '')),
                            (string) ($ticket['id'] ?? ''),
                        ];

                        $matched = false;
                        foreach ($haystacks as $haystack) {
                            if ($haystack !== '' && mb_strpos($haystack, $search) !== false) {
                                $matched = true;
                                break;
                            }
                        }

                        if (!$matched) {
                            return false;
                        }
                    }

                    if ($recentFilters['status'] !== '' && (string) ($ticket['status'] ?? '') !== $recentFilters['status']) {
                        return false;
                    }

                    if ($recentFilters['priority'] !== '' && (string) ($ticket['priority'] ?? '') !== $recentFilters['priority']) {
                        return false;
                    }

                    return true;
                }
            ));
            $recentCompareValue = static function (array $ticketRow, string $sortKey): mixed {
                return match ($sortKey) {
                    'id' => (int) ($ticketRow['id'] ?? 0),
                    'title' => mb_strtolower((string) ($ticketRow['title'] ?? '')),
                    'status' => mb_strtolower((string) ($ticketRow['status'] ?? '')),
                    'priority' => match (strtolower((string) ($ticketRow['priority'] ?? ''))) {
                        'high' => 3,
                        'medium' => 2,
                        'low' => 1,
                        default => 0,
                    },
                    default => (string) ($ticketRow['updated_at'] ?? ''),
                };
            };
            usort($recentRows, static function (array $left, array $right) use ($recentSort, $recentDirection, $recentCompareValue): int {
                $leftValue = $recentCompareValue($left, $recentSort);
                $rightValue = $recentCompareValue($right, $recentSort);
                if ($leftValue === $rightValue) {
                    return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
                }

                $comparison = $leftValue <=> $rightValue;
                return $recentDirection === 'asc' ? $comparison : -$comparison;
            });
            $recentPerPageRaw = strtolower(trim((string) ($query['per_page'] ?? '10')));
            $allowedPerPage = ['5', '10', '15', '20', '25', '50', '100', 'all'];
            if (!in_array($recentPerPageRaw, $allowedPerPage, true)) {
                $recentPerPageRaw = '10';
            }
            $recentPage = max(1, (int) ($query['page'] ?? 1));
            $recentTotal = count($recentRows);
            $recentPagination = [
                'page' => 1,
                'per_page' => $recentPerPageRaw,
                'total_items' => $recentTotal,
                'total_pages' => 1,
                'has_prev' => false,
                'has_next' => false,
                'prev_page' => 1,
                'next_page' => 1,
                'from' => $recentTotal > 0 ? 1 : 0,
                'to' => $recentTotal,
            ];
            if ($recentPerPageRaw !== 'all') {
                $recentPerPage = (int) $recentPerPageRaw;
                $recentTotalPages = max(1, (int) ceil($recentTotal / max(1, $recentPerPage)));
                $recentPage = min($recentPage, $recentTotalPages);
                $recentOffset = ($recentPage - 1) * $recentPerPage;
                $recentRows = array_slice($recentRows, $recentOffset, $recentPerPage);
                $recentPagination = [
                    'page' => $recentPage,
                    'per_page' => $recentPerPageRaw,
                    'total_items' => $recentTotal,
                    'total_pages' => $recentTotalPages,
                    'has_prev' => $recentPage > 1,
                    'has_next' => $recentPage < $recentTotalPages,
                    'prev_page' => max(1, $recentPage - 1),
                    'next_page' => min($recentTotalPages, $recentPage + 1),
                    'from' => $recentTotal > 0 ? $recentOffset + 1 : 0,
                    'to' => min($recentTotal, $recentOffset + $recentPerPage),
                ];
            }
            $summary['recentTickets'] = $recentRows;
            $summary['recentPagination'] = $recentPagination;
            $summary['recentSort'] = $recentSort;
            $summary['recentDirection'] = $recentDirection;
            $summary['recentFilters'] = $recentFilters;
            $summary['recentQuery'] = array_filter([
                'panel' => 'recent',
                'q' => $recentFilters['q'],
                'status' => $recentFilters['status'],
                'priority' => $recentFilters['priority'],
                'sort' => $recentSort,
                'dir' => $recentDirection,
                'per_page' => $recentPerPageRaw,
            ], static fn (mixed $value): bool => $value !== '' && $value !== null);
        }
        $template = 'deskhelper-app-operator.twig';
        $operatorPanelMeta = [
            'overview' => ['Indicadores', 'Indicadores operacionais dos tickets próprios e atribuídos a você.'],
            'actions' => ['Ações', 'Atalhos operacionais para conduzir os tickets sob sua responsabilidade.'],
            'category_summary' => ['Categorias', 'Distribuição dos seus tickets por categoria.'],
            'recent' => ['Recentes', 'Tickets recentes próprios ou atribuídos a você.'],
        ];
        [$shellTitle, $shellSubtitle] = $operatorPanelMeta[$dashboardPanel];
        $shellBreadcrumbs = [
            ['label' => 'Deskhelper', 'url' => app_url('app')],
            ['label' => 'Dashboard', 'url' => app_url('app')],
        ];
    } elseif ($isAdmin) {
        $allowedPanels = ['overview', 'actions', 'role_summary', 'category_summary', 'assignment_summary', 'backlog'];
        $dashboardPanel = in_array($requestedPanel, $allowedPanels, true) ? $requestedPanel : 'overview';
        $template = 'deskhelper-app-admin.twig';
        $adminPanelMeta = [
            'overview' => ['Indicadores', 'Indicadores consolidados do ambiente e do service desk.'],
            'actions' => ['Ações', 'Ações gerenciais rápidas para administração do ambiente.'],
            'role_summary' => ['Perfis', 'Distribuição atual de usuários por perfil de acesso.'],
            'category_summary' => ['Tickets por categoria', 'Distribuição de tickets agrupada por categoria.'],
            'assignment_summary' => ['Responsáveis', 'Carga atual distribuída por responsável.'],
            'backlog' => ['Backlog', 'Tickets recentes e pendências ativas do service desk.'],
        ];
        [$shellTitle, $shellSubtitle] = $adminPanelMeta[$dashboardPanel];
        $backlogRows = (array) ($summary['recentTickets'] ?? []);
        $backlogSort = 'updated_at';
        $backlogDirection = 'desc';
        $backlogPagination = [
            'page' => 1,
            'per_page' => '10',
            'total_items' => count($backlogRows),
            'total_pages' => 1,
            'has_prev' => false,
            'has_next' => false,
            'prev_page' => 1,
            'next_page' => 1,
            'from' => count($backlogRows) > 0 ? 1 : 0,
            'to' => count($backlogRows),
        ];
        $backlogQueryState = [
            'panel' => 'backlog',
            'q' => $backlogFilters['q'],
        ];
        if ($dashboardPanel === 'backlog' && $dashboardSearch !== '') {
            $needle = mb_strtolower($dashboardSearch);
            $backlogRows = array_values(array_filter(
                $backlogRows,
                static function (array $ticket) use ($needle): bool {
                    $haystacks = [
                        mb_strtolower((string) ($ticket['title'] ?? '')),
                        mb_strtolower((string) ($ticket['status'] ?? '')),
                        mb_strtolower((string) ($ticket['priority'] ?? '')),
                        mb_strtolower((string) ($ticket['category_label'] ?? '')),
                        (string) ($ticket['id'] ?? ''),
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
        if ($dashboardPanel === 'backlog') {
            $backlogRows = $tickets->getTicketsForUser($userId, $roles);
            if ($dashboardSearch !== '') {
                $needle = mb_strtolower($dashboardSearch);
                $backlogRows = array_values(array_filter(
                    $backlogRows,
                    static function (array $ticket) use ($needle): bool {
                        $haystacks = [
                            mb_strtolower((string) ($ticket['title'] ?? '')),
                            mb_strtolower((string) ($ticket['status'] ?? '')),
                            mb_strtolower((string) ($ticket['priority'] ?? '')),
                            mb_strtolower((string) ($ticket['category_label'] ?? '')),
                            (string) ($ticket['id'] ?? ''),
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
            if ($backlogFilters['status'] !== '') {
                $statusFilter = strtolower(trim((string) $backlogFilters['status']));
                $backlogRows = array_values(array_filter(
                    $backlogRows,
                    static fn (array $ticket): bool => strtolower(trim((string) ($ticket['status'] ?? ''))) === $statusFilter
                ));
            }
            if ($backlogFilters['category_id'] > 0) {
                $categories = $tickets->getAllCategories();
                $categoryLabel = '';
                foreach ($categories as $category) {
                    if ((int) ($category['id'] ?? 0) === $backlogFilters['category_id']) {
                        $categoryLabel = trim((string) ($category['label'] ?? ''));
                        break;
                    }
                }
                if ($categoryLabel !== '') {
                    $backlogRows = array_values(array_filter(
                        $backlogRows,
                        static fn (array $ticket): bool => trim((string) ($ticket['category_label'] ?? '')) === $categoryLabel
                    ));
                }
            }
            if ($backlogFilters['assigned_to_user_id'] > 0) {
                $assignedToUserId = $backlogFilters['assigned_to_user_id'];
                $backlogRows = array_values(array_filter(
                    $backlogRows,
                    static fn (array $ticket): bool => (int) ($ticket['assigned_to_user_id'] ?? 0) === $assignedToUserId
                ));
            }

            $backlogRows = array_map(static function (array $ticketRow): array {
                $createdAtRaw = (string) ($ticketRow['created_at'] ?? '');
                $createdAtTs = $createdAtRaw !== '' ? strtotime($createdAtRaw) : false;
                $ageLabel = '-';
                if ($createdAtTs !== false) {
                    $ageSeconds = max(0, time() - $createdAtTs);
                    if ($ageSeconds >= 86400) {
                        $days = (int) floor($ageSeconds / 86400);
                        $hours = (int) floor(($ageSeconds % 86400) / 3600);
                        $ageLabel = sprintf('%dd %dh', $days, $hours);
                    } elseif ($ageSeconds >= 3600) {
                        $hours = (int) floor($ageSeconds / 3600);
                        $minutes = (int) floor(($ageSeconds % 3600) / 60);
                        $ageLabel = sprintf('%dh %dm', $hours, $minutes);
                    } else {
                        $minutes = max(1, (int) floor($ageSeconds / 60));
                        $ageLabel = sprintf('%dm', $minutes);
                    }
                }

                $ticketRow['age_label'] = $ageLabel;
                return $ticketRow;
            }, $backlogRows);

            $backlogSort = strtolower(trim((string) ($query['sort'] ?? 'updated_at')));
            $backlogDirection = strtolower(trim((string) ($query['dir'] ?? 'desc')));
            $allowedBacklogSorts = ['id', 'title', 'sector', 'status', 'category', 'assigned_to', 'created_at', 'updated_at'];
            if (!in_array($backlogSort, $allowedBacklogSorts, true)) {
                $backlogSort = 'updated_at';
            }
            if (!in_array($backlogDirection, ['asc', 'desc'], true)) {
                $backlogDirection = 'desc';
            }

            $compareBacklogValue = static function (array $ticketRow, string $sortKey): mixed {
                return match ($sortKey) {
                    'id' => (int) ($ticketRow['id'] ?? 0),
                    'title' => mb_strtolower((string) ($ticketRow['title'] ?? '')),
                    'sector' => mb_strtolower((string) ($ticketRow['request_sector'] ?? '')),
                    'status' => mb_strtolower((string) ($ticketRow['status'] ?? '')),
                    'category' => mb_strtolower((string) ($ticketRow['category_label'] ?? '')),
                    'assigned_to' => mb_strtolower((string) ($ticketRow['assigned_to_name'] ?? '')),
                    'created_at' => (string) ($ticketRow['created_at'] ?? ''),
                    'updated_at' => (string) ($ticketRow['updated_at'] ?? ''),
                    default => (string) ($ticketRow['updated_at'] ?? ''),
                };
            };
            usort($backlogRows, static function (array $left, array $right) use ($backlogSort, $backlogDirection, $compareBacklogValue): int {
                $leftValue = $compareBacklogValue($left, $backlogSort);
                $rightValue = $compareBacklogValue($right, $backlogSort);

                if ($leftValue === $rightValue) {
                    return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
                }

                $comparison = $leftValue <=> $rightValue;

                return $backlogDirection === 'asc' ? $comparison : -$comparison;
            });

            $perPageRaw = strtolower(trim((string) ($query['per_page'] ?? '10')));
            $allowedPerPage = ['5', '10', '15', '20', '25', '50', '100', 'all'];
            if (!in_array($perPageRaw, $allowedPerPage, true)) {
                $perPageRaw = '10';
            }
            $currentPage = max(1, (int) ($query['page'] ?? 1));
            $totalBacklog = count($backlogRows);
            $backlogPagination = [
                'page' => 1,
                'per_page' => $perPageRaw,
                'total_items' => $totalBacklog,
                'total_pages' => 1,
                'has_prev' => false,
                'has_next' => false,
                'prev_page' => 1,
                'next_page' => 1,
                'from' => $totalBacklog > 0 ? 1 : 0,
                'to' => $totalBacklog,
            ];
            if ($perPageRaw !== 'all') {
                $perPage = (int) $perPageRaw;
                $totalPages = max(1, (int) ceil($totalBacklog / max(1, $perPage)));
                $currentPage = min($currentPage, $totalPages);
                $offset = ($currentPage - 1) * $perPage;
                $backlogRows = array_slice($backlogRows, $offset, $perPage);
                $backlogPagination = [
                    'page' => $currentPage,
                    'per_page' => $perPageRaw,
                    'total_items' => $totalBacklog,
                    'total_pages' => $totalPages,
                    'has_prev' => $currentPage > 1,
                    'has_next' => $currentPage < $totalPages,
                    'prev_page' => max(1, $currentPage - 1),
                    'next_page' => min($totalPages, $currentPage + 1),
                    'from' => $totalBacklog > 0 ? $offset + 1 : 0,
                    'to' => min($totalBacklog, $offset + $perPage),
                ];
            }

            $backlogQueryState = array_filter([
                'panel' => 'backlog',
                'q' => $backlogFilters['q'],
                'status' => $backlogFilters['status'],
                'category_id' => $backlogFilters['category_id'] > 0 ? (string) $backlogFilters['category_id'] : '',
                'assigned_to_user_id' => $backlogFilters['assigned_to_user_id'] > 0 ? (string) $backlogFilters['assigned_to_user_id'] : '',
                'sort' => $backlogSort,
                'dir' => $backlogDirection,
                'per_page' => $perPageRaw,
            ], static fn (mixed $value): bool => $value !== '' && $value !== null);
        }
        $shellBreadcrumbs = [
            ['label' => 'Deskhelper', 'url' => app_url('app')],
            ['label' => 'Dashboard', 'url' => app_url('app')],
        ];
    }

    return $twig->render($response, $template, [
        'user' => $user,
        'summary' => $summary,
        'dashboardPanel' => $dashboardPanel,
        'shellTitle' => $shellTitle,
        'shellSubtitle' => $shellSubtitle,
        'shellBreadcrumbs' => $shellBreadcrumbs,
        'filters' => [
            'q' => $backlogFilters['q'],
            'status' => $backlogFilters['status'],
            'category_id' => $backlogFilters['category_id'],
            'assigned_to_user_id' => $backlogFilters['assigned_to_user_id'],
        ],
        'categories' => $tickets->getCategories(),
        'assignableUsers' => $tickets->getAssignableUsers(),
        'backlogTickets' => $backlogRows ?? [],
        'backlogPagination' => $backlogPagination ?? null,
        'backlogSort' => $backlogSort ?? 'updated_at',
        'backlogDirection' => $backlogDirection ?? 'desc',
        'backlogQuery' => $backlogQueryState ?? ['panel' => 'backlog'],
        'flashMessage' => $deskhelperFlashPull(),
        'csrfToken' => $ensureCsrfToken(),
    ]);
});
