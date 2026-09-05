<?php

declare(strict_types=1);

use App\Application\Settings\SettingsInterface;
use App\Application\Notification\ContactMailer;
use App\Application\Security\PasswordResetMailer;
use App\Application\Security\PasswordResetService;
use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperSchemaManager;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperTicketRepository;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use Twig\TwigFilter;
use Twig\TwigFunction;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get(SettingsInterface::class);

            $loggerSettings = $settings->get('logger');
            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },
        ContactMailer::class => function (ContainerInterface $c) {
            return new ContactMailer($c->get(LoggerInterface::class));
        },
        Twig::class => function (ContainerInterface $c) {
            $twig = Twig::create(__DIR__ . '/../templates', [
                'cache' => false,
            ]);

            $deskhelperRoles = array_map(
                static fn (mixed $value): string => strtolower(trim((string) $value)),
                (array) ($_SESSION['deskhelper_user_roles'] ?? [])
            );
            $deskhelperSessionUser = [
                'authenticated' => (int) ($_SESSION['deskhelper_user_id'] ?? 0) > 0,
                'id' => (int) ($_SESSION['deskhelper_user_id'] ?? 0),
                'name' => trim((string) ($_SESSION['deskhelper_user_name'] ?? '')),
                'email' => trim((string) ($_SESSION['deskhelper_user_email'] ?? '')),
                'rank' => '',
                'roles' => $deskhelperRoles,
                'is_admin' => in_array('admin', $deskhelperRoles, true),
                'is_operator' => in_array('operator', $deskhelperRoles, true),
                'avatar_path' => '',
                'avatar_url' => '',
            ];

            $currentPath = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
            $currentPath = $currentPath !== '' ? $currentPath : '/';
            $currentQuery = [];
            parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $currentQuery);
            $navActive = '';
            $navPanel = trim((string) ($currentQuery['panel'] ?? ''));
            $navTicketsView = trim((string) ($currentQuery['view'] ?? ''));
            $navTicketsStatus = trim((string) ($currentQuery['status'] ?? ''));
            $navAdminView = trim((string) ($currentQuery['view'] ?? ''));
            $navTicketDetail = preg_match('#/tickets/\d+$#', $currentPath) === 1;
            if (preg_match('#/admin/users/pending$#', $currentPath) === 1) {
                $navActive = 'admin_pending';
            } elseif (preg_match('#/admin/users$#', $currentPath) === 1) {
                $navActive = 'admin_users';
            } elseif (preg_match('#/admin/categories$#', $currentPath) === 1) {
                $navActive = 'admin_categories';
            } elseif (preg_match('#/guide$#', $currentPath) === 1) {
                $navActive = 'guide';
            } elseif (preg_match('#/help$#', $currentPath) === 1) {
                $navActive = 'faq';
            } elseif (preg_match('#/contato$#', $currentPath) === 1) {
                $navActive = 'contact';
            } elseif (preg_match('#/tickets/new$#', $currentPath) === 1) {
                $navActive = 'tickets_new';
            } elseif (preg_match('#/tickets(?:/\\d+)?$#', $currentPath) === 1) {
                $navActive = 'tickets';
            } elseif (preg_match('#/app$#', $currentPath) === 1) {
                $navActive = 'app';
            }

            $twig->getEnvironment()->addFilter(new TwigFilter('label_case', static function (?string $value): string {
                $label = trim((string) $value);
                if ($label === '') {
                    return '';
                }

                $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
                $parts = explode(' ', mb_strtolower($label, 'UTF-8'));
                $stopWords = ['da', 'das', 'de', 'do', 'dos', 'e'];
                $acronyms = ['om', 'sfpc'];

                foreach ($parts as $index => $part) {
                    if (in_array($part, $acronyms, true)) {
                        $parts[$index] = mb_strtoupper($part, 'UTF-8');
                        continue;
                    }

                    if ($index > 0 && in_array($part, $stopWords, true)) {
                        continue;
                    }

                    $parts[$index] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
                }

                return implode(' ', $parts);
            }));

            $resolveBasePath = static function (): string {
                $configuredBasePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''));
                if ($configuredBasePath !== '' && $configuredBasePath !== '/') {
                    return '/' . trim($configuredBasePath, '/');
                }

                if (PHP_SAPI === 'cli-server') {
                    return '';
                }

                $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
                $scriptDir = trim(str_replace('\\', '/', dirname($scriptName)));

                if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '/') {
                    return '';
                }

                return '/' . trim($scriptDir, '/');
            };

            $assetBasePath = $resolveBasePath();
            if ($deskhelperSessionUser['authenticated']) {
                try {
                    /** @var DeskhelperUserRepository $deskhelperUserRepo */
                    $deskhelperUserRepo = $c->get(DeskhelperUserRepository::class);
                    $deskhelperUser = $deskhelperUserRepo->findById($deskhelperSessionUser['id']);
                    $deskhelperSessionUser['rank'] = trim((string) ($deskhelperUser['rank'] ?? ''));
                    $avatarPath = trim((string) ($deskhelperUser['avatar_path'] ?? ''));
                    if ($avatarPath !== '') {
                        $deskhelperSessionUser['avatar_path'] = $avatarPath;
                        $deskhelperSessionUser['avatar_url'] = ($assetBasePath !== '' ? $assetBasePath : '') . '/avatar?v=' . rawurlencode(substr(sha1($avatarPath), 0, 12));
                    }
                } catch (\Throwable $throwable) {
                }
            }

            $allowedThemes = ['blue', 'red', 'green', 'violet', 'amber'];
            $allowedModes = ['light', 'dark'];
            $allowedIntensities = ['neutral', 'vivid'];

            $defaultTheme = strtolower((string) ($_ENV['APP_DEFAULT_THEME'] ?? 'amber'));
            $defaultMode = strtolower((string) ($_ENV['APP_DEFAULT_MODE'] ?? 'light'));
            $defaultDarkIntensity = strtolower((string) ($_ENV['APP_DEFAULT_DARK_INTENSITY'] ?? 'neutral'));

            if (!in_array($defaultTheme, $allowedThemes, true)) {
                $defaultTheme = 'amber';
            }
            if (!in_array($defaultMode, $allowedModes, true)) {
                $defaultMode = 'light';
            }
            if (!in_array($defaultDarkIntensity, $allowedIntensities, true)) {
                $defaultDarkIntensity = 'neutral';
            }

            $twig->getEnvironment()->addGlobal('default_theme', $defaultTheme);
            $twig->getEnvironment()->addGlobal('default_mode', $defaultMode);
            $twig->getEnvironment()->addGlobal('default_dark_intensity', $defaultDarkIntensity);
            $twig->getEnvironment()->addGlobal('app_base_path', $assetBasePath);
            $twig->getEnvironment()->addGlobal('asset_base_path', $assetBasePath);
            $twig->getEnvironment()->addGlobal('deskhelper_session_user', $deskhelperSessionUser);
            $twig->getEnvironment()->addGlobal('deskhelper_nav', [
                'active' => $navActive,
                'panel' => $navPanel,
                'tickets_view' => $navTicketsView,
                'tickets_status' => $navTicketsStatus,
                'ticket_detail' => $navTicketDetail,
                'admin_view' => $navAdminView,
            ]);
            $adminNotifications = [
                'total' => 0,
                'items' => [],
            ];
            if ($deskhelperSessionUser['is_admin']) {
                /** @var DeskhelperUserRepository $userRepo */
                $userRepo = $c->get(DeskhelperUserRepository::class);
                /** @var DeskhelperTicketRepository $ticketRepo */
                $ticketRepo = $c->get(DeskhelperTicketRepository::class);

                $pendingUsers = $userRepo->countPending();
                $openTickets = $ticketRepo->countByStatus('open');
                $unassignedTickets = $ticketRepo->countUnassigned();
                $highPriorityTickets = $ticketRepo->countHighPriorityActive();

                $adminNotifications['items'] = [
                    [
                        'title' => 'Usuários pendentes',
                        'count' => $pendingUsers,
                        'meta' => $pendingUsers > 0 ? 'Cadastros aguardando aprovação administrativa.' : 'Sem cadastros pendentes.',
                        'url' => ($assetBasePath !== '' ? $assetBasePath : '') . '/admin/users/pending?view=list',
                    ],
                    [
                        'title' => 'Tickets em aberto',
                        'count' => $openTickets,
                        'meta' => $openTickets > 0 ? 'Ocorrências abertas aguardando início de tratamento.' : 'Nenhum ticket em aberto.',
                        'url' => ($assetBasePath !== '' ? $assetBasePath : '') . '/tickets?view=list&status=open',
                    ],
                    [
                        'title' => 'Tickets sem atribuição',
                        'count' => $unassignedTickets,
                        'meta' => $unassignedTickets > 0 ? 'Ocorrências abertas ainda sem responsável.' : 'Nenhum ticket sem responsável.',
                        'url' => ($assetBasePath !== '' ? $assetBasePath : '') . '/tickets?view=list&assigned=unassigned',
                    ],
                    [
                        'title' => 'Tickets de prioridade alta',
                        'count' => $highPriorityTickets,
                        'meta' => $highPriorityTickets > 0 ? 'Ocorrências ativas que exigem atenção imediata.' : 'Nenhuma ocorrência crítica no momento.',
                        'url' => ($assetBasePath !== '' ? $assetBasePath : '') . '/tickets?view=list&priority=high',
                    ],
                ];
                $adminNotifications['total'] = $pendingUsers + $openTickets + $unassignedTickets + $highPriorityTickets;
            }
            $twig->getEnvironment()->addGlobal('deskhelper_admin_notifications', $adminNotifications);
            $twig->getEnvironment()->addFunction(new TwigFunction('app_url', static function (string $path = '') use ($assetBasePath): string {
                $normalizedPath = trim($path);
                if ($normalizedPath === '' || $normalizedPath === '/') {
                    return $assetBasePath !== '' ? $assetBasePath : '/';
                }

                return ($assetBasePath !== '' ? $assetBasePath : '') . '/' . ltrim($normalizedPath, '/');
            }));

            return $twig;
        },
        DashboardRepository::class => function () {
            $configuredBasePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''));
            $appSlug = trim($configuredBasePath, '/');
            if ($appSlug === '') {
                $appSlug = basename(dirname(__DIR__));
            }

            $dbPath = trim((string) ($_ENV['APP_DB_PATH'] ?? ''));
            if ($dbPath === '') {
                $dbPath = __DIR__ . '/../var/data/' . $appSlug . '.sqlite';
            }

            return new DashboardRepository(
                $dbPath,
                __DIR__ . '/content/dashboard.php'
            );
        },
        PDO::class => function () {
            $dbPath = trim((string) ($_ENV['APP_DB_PATH'] ?? ''));
            if ($dbPath === '') {
                $configuredBasePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''));
                $appSlug = trim($configuredBasePath, '/');
                if ($appSlug === '') {
                    $appSlug = basename(dirname(__DIR__));
                }

                $dbPath = __DIR__ . '/../var/data/' . $appSlug . '.sqlite';
            }

            $schemaManager = new DeskhelperSchemaManager(
                $dbPath,
                __DIR__ . '/../docs/deskhelper-schema.sql'
            );
            $schemaManager->bootstrap();

            return new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        },
        DeskhelperUserRepository::class => static fn (ContainerInterface $c) => new DeskhelperUserRepository($c->get(PDO::class)),
        DeskhelperTicketRepository::class => static fn (ContainerInterface $c) => new DeskhelperTicketRepository($c->get(PDO::class)),
        PasswordResetService::class => static fn (ContainerInterface $c) => new PasswordResetService($c->get(PDO::class)),
        PasswordResetMailer::class => static fn () => new PasswordResetMailer(),
    ]);
};
