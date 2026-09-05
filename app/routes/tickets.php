<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperTicketRepository;
use App\Application\Middleware\DeskhelperAuthMiddleware;
use App\Application\Middleware\DeskhelperRoleMiddleware;
use Slim\Views\Twig;

$app->get('/tickets', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $deskhelperCurrentUser, $deskhelperFlashPull) {
    $twig = $app->getContainer()->get(Twig::class);
    /** @var DeskhelperTicketRepository $tickets */
    $tickets = $app->getContainer()->get(DeskhelperTicketRepository::class);

    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    $roles = (array) ($user['roles'] ?? []);
    $isAdmin = in_array('admin', $roles, true);
    $isOperator = in_array('operator', $roles, true);
    $query = $request->getQueryParams();
    $requestedView = strtolower(trim((string) ($query['view'] ?? '')));
    $requestSectorOptions = [
        '1ª Seção',
        '2ª Seção',
        '3ª Seção',
        '4ª Seção',
        'Fisc Adm',
        'ComSoc',
        'RP',
        'Gabinete Cmdo',
        'SFPC',
        'ALMOX',
        'APROV',
        'Pel Mnt',
        'Pel Obras',
        'ProFEsp',
        'Op Pipa',
        'SSSU',
        '1ª Cia Fuz',
        '2ª Cia Fuz',
        'Cia C Ap',
        'NPOR',
        'CFGS',
        'Banda de Música',
    ];
    $filters = [
        'q' => trim((string) ($query['q'] ?? '')),
        'status' => trim((string) ($query['status'] ?? '')),
        'priority' => trim((string) ($query['priority'] ?? '')),
        'category_id' => (int) ($query['category_id'] ?? 0),
        'request_sector' => trim((string) ($query['request_sector'] ?? '')),
        'assigned' => trim((string) ($query['assigned'] ?? '')),
        'scope' => trim((string) ($query['scope'] ?? '')),
    ];
    $ticketRows = $tickets->getTicketsForUser((int) ($user['id'] ?? 0), $roles, $filters);
    $sort = strtolower(trim((string) ($query['sort'] ?? 'updated_at')));
    $direction = strtolower(trim((string) ($query['dir'] ?? 'desc')));
    $allowedSorts = ['id', 'title', 'category', 'sector', 'status', 'priority', 'created_by', 'assigned_to', 'updated_at', 'resolved_at'];
    if (!in_array($sort, $allowedSorts, true)) {
        $sort = 'updated_at';
    }
    if (!in_array($direction, ['asc', 'desc'], true)) {
        $direction = 'desc';
    }
    $compareValue = static function (array $ticketRow, string $sortKey): mixed {
        return match ($sortKey) {
            'id' => (int) ($ticketRow['id'] ?? 0),
            'title' => mb_strtolower((string) ($ticketRow['title'] ?? '')),
            'category' => mb_strtolower((string) ($ticketRow['category_label'] ?? '')),
            'sector' => mb_strtolower((string) ($ticketRow['request_sector'] ?? '')),
            'status' => mb_strtolower((string) ($ticketRow['status'] ?? '')),
            'priority' => match (strtolower((string) ($ticketRow['priority'] ?? ''))) {
                'high' => 3,
                'medium' => 2,
                'low' => 1,
                default => 0,
            },
            'created_by' => mb_strtolower((string) ($ticketRow['created_by_name'] ?? '')),
            'assigned_to' => mb_strtolower((string) ($ticketRow['assigned_to_name'] ?? '')),
            'updated_at' => (string) ($ticketRow['updated_at'] ?? ''),
            'resolved_at' => (string) ($ticketRow['resolved_at'] ?? ''),
            default => (string) ($ticketRow['updated_at'] ?? ''),
        };
    };
    usort($ticketRows, static function (array $left, array $right) use ($sort, $direction, $compareValue): int {
        $leftValue = $compareValue($left, $sort);
        $rightValue = $compareValue($right, $sort);

        if ($leftValue === $rightValue) {
            return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
        }

        $comparison = $leftValue <=> $rightValue;

        return $direction === 'asc' ? $comparison : -$comparison;
    });
    $queueSummary = [
        'total' => count($ticketRows),
        'open' => 0,
        'in_progress' => 0,
        'resolved' => 0,
        'closed' => 0,
        'cancelled' => 0,
        'high' => 0,
        'assigned_to_me' => 0,
    ];

    foreach ($ticketRows as $ticketRow) {
        $status = (string) ($ticketRow['status'] ?? '');
        if (array_key_exists($status, $queueSummary)) {
            $queueSummary[$status]++;
        }

        if (strtolower((string) ($ticketRow['priority'] ?? '')) === 'high') {
            $queueSummary['high']++;
        }

        $assignedTo = (int) ($ticketRow['assigned_to_user_id'] ?? 0);
        if ($assignedTo === (int) ($user['id'] ?? 0)) {
            $queueSummary['assigned_to_me']++;
        }
    }

    $template = 'deskhelper-tickets.twig';
    if ($isOperator && !$isAdmin) {
        $template = 'deskhelper-tickets-operator.twig';
    } elseif ($isAdmin) {
        $template = 'deskhelper-tickets-admin.twig';
    }

    $allowedTicketViews = ['indicators', 'list'];
    $ticketsView = in_array($requestedView, $allowedTicketViews, true) ? $requestedView : 'indicators';
    $perPageRaw = strtolower(trim((string) ($query['per_page'] ?? '10')));
    $allowedPerPage = ['5', '10', '15', '20', '25', '50', '100', 'all'];
    if (!in_array($perPageRaw, $allowedPerPage, true)) {
        $perPageRaw = '10';
    }
    $currentPage = max(1, (int) ($query['page'] ?? 1));
    $totalTickets = count($ticketRows);
    $pagination = [
        'page' => 1,
        'per_page' => $perPageRaw,
        'total_items' => $totalTickets,
        'total_pages' => 1,
        'has_prev' => false,
        'has_next' => false,
        'prev_page' => 1,
        'next_page' => 1,
        'from' => $totalTickets > 0 ? 1 : 0,
        'to' => $totalTickets,
    ];
    if ($ticketsView === 'list') {
        if ($perPageRaw === 'all') {
            $currentPage = 1;
        } else {
            $perPage = (int) $perPageRaw;
            $totalPages = max(1, (int) ceil($totalTickets / max(1, $perPage)));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $perPage;
            $ticketRows = array_slice($ticketRows, $offset, $perPage);
            $pagination = [
                'page' => $currentPage,
                'per_page' => $perPageRaw,
                'total_items' => $totalTickets,
                'total_pages' => $totalPages,
                'has_prev' => $currentPage > 1,
                'has_next' => $currentPage < $totalPages,
                'prev_page' => max(1, $currentPage - 1),
                'next_page' => min($totalPages, $currentPage + 1),
                'from' => $totalTickets > 0 ? $offset + 1 : 0,
                'to' => min($totalTickets, $offset + $perPage),
            ];
        }
    }
    $ticketsListQuery = array_filter([
        'view' => 'list',
        'q' => $filters['q'],
        'status' => $filters['status'],
        'priority' => $filters['priority'],
        'category_id' => $filters['category_id'] > 0 ? (string) $filters['category_id'] : '',
        'request_sector' => $filters['request_sector'],
        'assigned' => $filters['assigned'],
        'scope' => $filters['scope'],
        'sort' => $sort,
        'dir' => $direction,
        'per_page' => $perPageRaw,
    ], static fn (mixed $value): bool => $value !== '' && $value !== null);

    $ticketsModuleLabel = 'Tickets';
    $ticketsViewMeta = [
        'indicators' => ['Indicadores', $isOperator
            ? 'Visão resumida dos tickets próprios e atribuídos ao operador.'
            : 'Visão resumida da fila e dos principais volumes.'],
        'list' => ['Lista', $isAdmin
            ? 'Tabela completa das ocorrências com filtros e gestão operacional.'
            : ($isOperator
                ? 'Tabela dos tickets próprios e atribuídos ao operador.'
                : 'Tabela das suas ocorrências com filtros e acompanhamento.'),
        ],
    ];
    [$shellTitle, $shellSubtitle] = $ticketsViewMeta[$ticketsView];

    if ($ticketsView === 'list' && $filters['status'] === 'open') {
        $shellTitle = 'Fila aberta';
        $shellSubtitle = $isAdmin
            ? 'Ocorrências abertas aguardando tratamento inicial.'
            : ($isOperator
                ? 'Tickets abertos dentro da sua visibilidade operacional.'
                : 'Ocorrências abertas que aguardam andamento.');
    }

    return $twig->render($response, $template, [
        'user' => $user,
        'tickets' => $ticketRows,
        'pageTitle' => $isAdmin ? 'Todos os tickets' : ($isOperator ? 'Tickets visíveis ao operador' : 'Meus tickets'),
        'pageSubtitle' => $isAdmin
            ? 'Visão completa das ocorrências.'
            : ($isOperator ? 'Tickets criados por você e tickets atribuídos ao seu perfil operacional.' : 'Acompanhe suas ocorrências abertas.'),
        'flashMessage' => $deskhelperFlashPull(),
        'csrfToken' => $ensureCsrfToken(),
        'filters' => $filters,
        'categories' => $tickets->getCategories(),
        'requestSectorOptions' => $requestSectorOptions,
        'queueSummary' => $queueSummary,
        'ticketsView' => $ticketsView,
        'pagination' => $pagination,
        'ticketsListQuery' => $ticketsListQuery,
        'sort' => $sort,
        'direction' => $direction,
        'shellTitle' => $shellTitle,
        'shellSubtitle' => $shellSubtitle,
        'shellBreadcrumbs' => [
            ['label' => 'Deskhelper', 'url' => app_url('app')],
            ['label' => $ticketsModuleLabel, 'url' => app_url('tickets?view=indicators')],
        ],
    ]);
})->add(new DeskhelperAuthMiddleware());

$app->get('/tickets/new', function (Request $request, Response $response) use ($app, $ensureCsrfToken) {
    $twig = $app->getContainer()->get(Twig::class);
    /** @var DeskhelperTicketRepository $tickets */
    $tickets = $app->getContainer()->get(DeskhelperTicketRepository::class);
    $requestSectorOptions = [
        '1ª Seção',
        '2ª Seção',
        '3ª Seção',
        '4ª Seção',
        'Fisc Adm',
        'ComSoc',
        'RP',
        'Gabinete Cmdo',
        'SFPC',
        'ALMOX',
        'APROV',
        'Pel Mnt',
        'Pel Obras',
        'ProFEsp',
        'Op Pipa',
        'SSSU',
        '1ª Cia Fuz',
        '2ª Cia Fuz',
        'Cia C Ap',
        'NPOR',
        'CFGS',
        'Banda de Música',
    ];

    return $twig->render($response, 'deskhelper-ticket-form.twig', [
        'categories' => $tickets->getCategories(),
        'requestSectorOptions' => $requestSectorOptions,
        'csrfToken' => $ensureCsrfToken(),
        'form' => [
            'priority' => 'medium',
        ],
    ]);
})->add(new DeskhelperAuthMiddleware());

$app->post('/tickets/new', function (Request $request, Response $response) use ($app, $isValidCsrf, $ensureCsrfToken, $deskhelperCurrentUser) {
    $twig = $app->getContainer()->get(Twig::class);
    /** @var DeskhelperTicketRepository $tickets */
    $tickets = $app->getContainer()->get(DeskhelperTicketRepository::class);
    $requestSectorOptions = [
        '1ª Seção',
        '2ª Seção',
        '3ª Seção',
        '4ª Seção',
        'Fisc Adm',
        'ComSoc',
        'RP',
        'Gabinete Cmdo',
        'SFPC',
        'ALMOX',
        'APROV',
        'Pel Mnt',
        'Pel Obras',
        'ProFEsp',
        'Op Pipa',
        'SSSU',
        '1ª Cia Fuz',
        '2ª Cia Fuz',
        'Cia C Ap',
        'NPOR',
        'CFGS',
        'Banda de Música',
    ];

    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];
    $form = [
        'title' => trim((string) ($data['title'] ?? '')),
        'category_id' => (string) ($data['category_id'] ?? ''),
        'request_sector' => trim((string) ($data['request_sector'] ?? '')),
        'priority' => trim((string) ($data['priority'] ?? 'medium')),
        'description' => trim((string) ($data['description'] ?? '')),
    ];

    $render = static function (?string $error) use ($twig, $response, $tickets, $ensureCsrfToken, $form, $requestSectorOptions) {
        return $twig->render($response, 'deskhelper-ticket-form.twig', [
            'categories' => $tickets->getCategories(),
            'requestSectorOptions' => $requestSectorOptions,
            'csrfToken' => $ensureCsrfToken(),
            'formError' => $error,
            'form' => $form,
        ]);
    };

    if (!$isValidCsrf($request)) {
        return $render('Sessao expirada. Atualize a pagina e tente novamente.');
    }

    try {
        $ticketId = $tickets->createTicket((int) ($user['id'] ?? 0), $data);
    } catch (\Throwable $throwable) {
        return $render($throwable->getMessage());
    }

    $_SESSION['deskhelper_flash'] = 'Ticket criado com sucesso.';

    return $response->withHeader('Location', app_url('/tickets/' . $ticketId))->withStatus(302);
})->add(new DeskhelperAuthMiddleware());

$app->get('/tickets/{id}', function (Request $request, Response $response, array $args) use ($app, $ensureCsrfToken, $deskhelperCurrentUser, $deskhelperFlashPull) {
    $twig = $app->getContainer()->get(Twig::class);
    /** @var DeskhelperTicketRepository $tickets */
    $tickets = $app->getContainer()->get(DeskhelperTicketRepository::class);

    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    $ticket = $tickets->findTicketForUser((int) ($args['id'] ?? 0), (int) ($user['id'] ?? 0), (array) ($user['roles'] ?? []));
    if ($ticket === null) {
        $_SESSION['deskhelper_flash'] = 'Ticket não encontrado ou sem acesso.';
        return $response->withHeader('Location', app_url('/tickets'))->withStatus(302);
    }

    $roles = (array) ($user['roles'] ?? []);
    $canManage = in_array('admin', $roles, true) || in_array('operator', $roles, true);
    $template = 'deskhelper-ticket-detail.twig';
    if (in_array('operator', $roles, true) && !in_array('admin', $roles, true)) {
        $template = 'deskhelper-ticket-detail-operator.twig';
    } elseif (in_array('admin', $roles, true)) {
        $template = 'deskhelper-ticket-detail-admin.twig';
    }

    return $twig->render($response, $template, [
        'user' => $user,
        'ticket' => $ticket,
        'assignableUsers' => in_array('admin', $roles, true) ? $tickets->getAssignableUsers() : [],
        'canManage' => $canManage,
        'canAssignTicket' => in_array('admin', $roles, true),
        'canComment' => true,
        'csrfToken' => $ensureCsrfToken(),
        'flashMessage' => $deskhelperFlashPull(),
    ]);
})->add(new DeskhelperAuthMiddleware());

$app->post('/tickets/{id}/comment', function (Request $request, Response $response, array $args) use ($app, $isValidCsrf, $deskhelperCurrentUser) {
    if (!$isValidCsrf($request)) {
        $_SESSION['deskhelper_flash'] = 'Falha de validacao CSRF.';
        return $response->withHeader('Location', app_url('/tickets/' . (int) ($args['id'] ?? 0)))->withStatus(302);
    }

    /** @var DeskhelperTicketRepository $tickets */
    $tickets = $app->getContainer()->get(DeskhelperTicketRepository::class);
    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    $ticketId = (int) ($args['id'] ?? 0);
    $visibleTicket = $tickets->findTicketForUser($ticketId, (int) ($user['id'] ?? 0), (array) ($user['roles'] ?? []));
    if ($visibleTicket === null) {
        $_SESSION['deskhelper_flash'] = 'Ticket não encontrado ou sem acesso.';
        return $response->withHeader('Location', app_url('/tickets'))->withStatus(302);
    }

    try {
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];
        $tickets->addComment($ticketId, (int) ($user['id'] ?? 0), (string) ($data['body'] ?? ''));
        $_SESSION['deskhelper_flash'] = 'Comentário adicionado.';
    } catch (\Throwable $throwable) {
        $_SESSION['deskhelper_flash'] = $throwable->getMessage();
    }

    return $response->withHeader('Location', app_url('/tickets/' . $ticketId))->withStatus(302);
})->add(new DeskhelperAuthMiddleware());

$app->post('/tickets/{id}/workflow', function (Request $request, Response $response, array $args) use ($app, $isValidCsrf, $deskhelperCurrentUser, $deskhelperCanManageTicket) {
    if (!$isValidCsrf($request)) {
        $_SESSION['deskhelper_flash'] = 'Falha de validacao CSRF.';
        return $response->withHeader('Location', app_url('/tickets/' . (int) ($args['id'] ?? 0)))->withStatus(302);
    }

    /** @var DeskhelperTicketRepository $tickets */
    $tickets = $app->getContainer()->get(DeskhelperTicketRepository::class);
    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    $ticketId = (int) ($args['id'] ?? 0);
    $visibleTicket = $tickets->findTicketForUser($ticketId, (int) ($user['id'] ?? 0), (array) ($user['roles'] ?? []));
    if (!$deskhelperCanManageTicket($user, $visibleTicket)) {
        $_SESSION['deskhelper_flash'] = 'Você não pode alterar este ticket.';
        return $response->withHeader('Location', app_url('/tickets'))->withStatus(302);
    }

    try {
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];
        $roles = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($user['roles'] ?? [])
        );
        if (in_array('operator', $roles, true) && !in_array('admin', $roles, true)) {
            unset($data['assigned_to_user_id']);
        }
        $tickets->updateTicketWorkflow($ticketId, (int) ($user['id'] ?? 0), $data);
        $_SESSION['deskhelper_flash'] = 'Fluxo do ticket atualizado.';
        $returnTo = trim((string) ($data['return_to'] ?? ''));
        if ($returnTo === app_url('tickets?view=list')) {
            return $response->withHeader('Location', $returnTo)->withStatus(302);
        }
    } catch (\Throwable $throwable) {
        $_SESSION['deskhelper_flash'] = $throwable->getMessage();
    }

    return $response->withHeader('Location', app_url('/tickets/' . $ticketId))->withStatus(302);
})->add(new DeskhelperRoleMiddleware(['operator', 'admin']))->add(new DeskhelperAuthMiddleware());
