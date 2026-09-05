<?php

declare(strict_types=1);

date_default_timezone_set('America/Fortaleza');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use Slim\App;

if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        $configuredBasePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''));
        if ($configuredBasePath !== '' && $configuredBasePath !== '/') {
            return '/' . trim($configuredBasePath, '/');
        }

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = trim(str_replace('\\', '/', dirname($scriptName)));

        if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '/') {
            return '';
        }

        return '/' . trim($scriptDir, '/');
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $basePath = app_base_path();
        $normalizedPath = trim($path);

        if ($normalizedPath === '' || $normalizedPath === '/') {
            return $basePath !== '' ? $basePath : '/';
        }

        return ($basePath !== '' ? $basePath : '') . '/' . ltrim($normalizedPath, '/');
    }
}

return function (App $app) {
    $flashPull = static function (): ?string {
        $message = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);
        return is_string($message) && $message !== '' ? $message : null;
    };

    $ensureCsrfToken = static function (): string {
        $token = $_SESSION['csrf_token'] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $token;
        }

        return $token;
    };

    $isValidCsrf = static function (Request $request): bool {
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];
        $providedToken = (string) ($data['csrf_token'] ?? '');

        return $providedToken !== '' && hash_equals($sessionToken, $providedToken);
    };

    $deskhelperFlashPull = static function (): ?string {
        $message = $_SESSION['deskhelper_flash'] ?? null;
        unset($_SESSION['deskhelper_flash']);

        return is_string($message) && $message !== '' ? $message : null;
    };

    $deskhelperSetFlash = static function (string $message): void {
        $_SESSION['deskhelper_flash'] = trim($message);
    };

    $deskhelperClearAuth = static function (): void {
        unset(
            $_SESSION['deskhelper_user_id'],
            $_SESSION['deskhelper_user_email'],
            $_SESSION['deskhelper_user_name'],
            $_SESSION['deskhelper_user_roles'],
            $_SESSION['deskhelper_login_attempts']
        );
    };

    $deskhelperStoreAuth = static function (array $user) use ($deskhelperClearAuth): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }

        $deskhelperClearAuth();
        $_SESSION['deskhelper_user_id'] = (int) ($user['id'] ?? 0);
        $_SESSION['deskhelper_user_email'] = (string) ($user['email'] ?? '');
        $_SESSION['deskhelper_user_name'] = (string) ($user['name'] ?? '');
        $_SESSION['deskhelper_user_roles'] = array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            (array) ($user['roles'] ?? [])
        ));
    };

    $deskhelperCurrentUser = static function () use ($app): ?array {
        $userId = (int) ($_SESSION['deskhelper_user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        /** @var DeskhelperUserRepository $users */
        $users = $app->getContainer()->get(DeskhelperUserRepository::class);

        return $users->findById($userId);
    };

    $deskhelperHasRole = static function (string $role): bool {
        $roles = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($_SESSION['deskhelper_user_roles'] ?? [])
        );

        return in_array(strtolower(trim($role)), $roles, true);
    };

    $deskhelperCanManageTicket = static function (array $user, ?array $ticket): bool {
        if ($ticket === null) {
            return false;
        }

        $roles = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($user['roles'] ?? [])
        );

        if (in_array('admin', $roles, true)) {
            return true;
        }

        if (in_array('operator', $roles, true)) {
            $userId = (int) ($user['id'] ?? 0);
            $assignedTo = (int) ($ticket['assigned_to_user_id'] ?? 0);

            return $assignedTo === $userId;
        }

        return false;
    };

    $buildDeskhelperAuthViewData = static function (array $data = []): array {
        return array_merge([
            'dashboardTitle' => 'Deskhelper',
            'dashboardSubtitle' => 'Cadastro, acesso e atendimento de ocorrencias.',
            'lastUpdated' => date('d/m/Y H:i'),
            'activeSection' => 'login',
            'badge' => 'Deskhelper',
            'flashMessage' => null,
            'formError' => null,
            'form' => [],
            'formMode' => 'login',
            'secondaryHref' => app_url(''),
            'secondaryLabel' => 'Voltar ao início',
        ], $data);
    };

    $resolveOriginalSlugFromReferer = static function (Request $request, string $expectedEntity): string {
        $referer = trim($request->getHeaderLine('Referer'));
        if ($referer === '') {
            return '';
        }

        $query = parse_url($referer, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return '';
        }

        parse_str($query, $params);
        if (!is_array($params)) {
            return '';
        }

        $entity = strtolower(trim((string) ($params['entity'] ?? '')));
        $mode = strtolower(trim((string) ($params['mode'] ?? '')));
        $slug = strtolower(trim((string) ($params['slug'] ?? '')));

        if ($expectedEntity === 'subgroups' && $entity === 'sections') {
            $entity = 'subgroups';
        }

        if ($entity !== $expectedEntity || $mode !== 'edit' || $slug === '') {
            return '';
        }

        return $slug;
    };

    $allowedAdminAvatarFiles = [
        'face2_620_620.png',
        'face6_620_620.png',
        'face7_620_620.png',
        'face8_620_620.png',
        'face9_620_620.png',
        'face10_620_620.png',
    ];

    $normalizeAdminAvatarFile = static function (?string $value) use ($allowedAdminAvatarFiles): string {
        $raw = trim((string) $value);
        if (!in_array($raw, $allowedAdminAvatarFiles, true)) {
            return 'face6_620_620.png';
        }

        return $raw;
    };

    $isValidUploadedAvatarPath = static function (?string $value): bool {
        $raw = trim((string) $value);

        return preg_match('/^uploads\/[a-z0-9._\-]+\.(png|jpe?g|webp)$/i', $raw) === 1;
    };

    $resolveDeskhelperAvatarAbsolutePath = static function (?string $storedAvatar) use ($isValidUploadedAvatarPath): string {
        $relativePath = trim((string) $storedAvatar);
        if (!$isValidUploadedAvatarPath($relativePath)) {
            return dirname(__DIR__) . '/public/assets/img/avatar/face6_620_620.png';
        }

        $absolutePath = dirname(__DIR__) . '/public/assets/img/avatar/' . $relativePath;
        if (!is_file($absolutePath)) {
            return dirname(__DIR__) . '/public/assets/img/avatar/face6_620_620.png';
        }

        return $absolutePath;
    };

    $adminAvatarConfigKey = static function (string $username): string {
        $normalized = strtolower(trim($username));
        if ($normalized === '') {
            return 'admin.avatar.default';
        }

        return 'admin.avatar.' . $normalized;
    };

    $resolveAdminAvatarStoredValue = static function (?string $username) use ($app, $adminAvatarConfigKey): string {
        $normalizedUser = trim((string) $username);
        if ($normalizedUser === '') {
            return 'face6_620_620.png';
        }

        try {
            /** @var DashboardRepository $repo */
            $repo = $app->getContainer()->get(DashboardRepository::class);
            return trim($repo->getConfigValue($adminAvatarConfigKey($normalizedUser), 'face6_620_620.png'));
        } catch (\Throwable $throwable) {
            return 'face6_620_620.png';
        }
    };

    $normalizeAdminUsername = static function (?string $value): string {
        $username = trim((string) $value);
        $username = preg_replace('/\s+/', '', $username) ?? $username;

        return $username;
    };

    $resolveAdminAvatarUrl = static function (?string $username) use ($resolveAdminAvatarStoredValue): string {
        $stored = $resolveAdminAvatarStoredValue($username);
        $version = substr(sha1($stored), 0, 12);

        return app_url('/admin/avatar?v=' . rawurlencode($version));
    };

    $navbarAuthContext = static function () use ($app, $ensureCsrfToken, $resolveAdminAvatarUrl): array {
        $adminUsername = (string) ($_SESSION['admin_user'] ?? '');
        $isAdminLogged = !empty($_SESSION['is_admin']);
        $adminPendingRequestsCount = 0;
        $adminPendingRequests = [];

        if ($isAdminLogged) {
            try {
                /** @var DashboardRepository $repo */
                $repo = $app->getContainer()->get(DashboardRepository::class);
                $adminPendingRequestsCount = $repo->countPendingCardRequests();
                $adminPendingRequests = $repo->getRecentPendingCardRequests(5);
            } catch (\Throwable $throwable) {
                $adminPendingRequestsCount = 0;
                $adminPendingRequests = [];
            }
        }

        return [
            'isAdminLogged' => $isAdminLogged,
            'adminUsername' => $adminUsername,
            'adminAvatarUrl' => $resolveAdminAvatarUrl($adminUsername),
            'csrfToken' => $ensureCsrfToken(),
            'adminPendingRequestsCount' => $adminPendingRequestsCount,
            'adminPendingRequests' => $adminPendingRequests,
        ];
    };

    $normalizeGroupLabel = static function (?string $rawLabel): string {
        $label = trim((string) $rawLabel);
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        if ($label === '') {
            return 'Geral';
        }

        return mb_substr($label, 0, 60);
    };

    $buildGroupedSections = static function (array $sections, array $groups = []) use ($normalizeGroupLabel): array {
        $grouped = [];

        foreach ($groups as $group) {
            $groupSlug = strtolower(trim((string) ($group['slug'] ?? '')));
            if ($groupSlug === '') {
                continue;
            }

            $groupLabel = $normalizeGroupLabel((string) ($group['label'] ?? $groupSlug));
            $grouped[$groupSlug] = [
                'label' => $groupLabel,
                'items' => [],
            ];
        }

        foreach ($sections as $slug => $section) {
            $groupLabel = $normalizeGroupLabel((string) ($section['group'] ?? ''));

            $groupSlug = strtolower(trim((string) ($section['group_slug'] ?? '')));
            $groupKey = $groupSlug !== ''
                ? $groupSlug
                : (function_exists('mb_strtolower') ? mb_strtolower($groupLabel) : strtolower($groupLabel));

            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'label' => $groupLabel,
                    'items' => [],
                ];
            }

            $grouped[$groupKey]['items'][(string) $slug] = $section;
        }

        return array_values($grouped);
    };

    $collectSectionGroups = static function (array $sections) use ($normalizeGroupLabel): array {
        $groups = [];

        foreach ($sections as $section) {
            $groupLabel = $normalizeGroupLabel((string) ($section['group'] ?? ''));

            if (!in_array($groupLabel, $groups, true)) {
                $groups[] = $groupLabel;
            }
        }

        sort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return $groups;
    };

    $normalizeHref = static function (string $rawHref): string {
        $href = trim($rawHref);
        if ($href === '') {
            return '#';
        }

        if (preg_match('/^https?:\s*\/\//i', $href) === 1) {
            $href = preg_replace('/\s+/', '', $href) ?? $href;
        }

        return $href;
    };

    $requesterRanks = [
        'General de Exército',
        'General de Divisão',
        'General de Brigada',
        'Coronel',
        'Tenente-Coronel',
        'Major',
        'Capitão',
        '1º Tenente',
        '2º Tenente',
        'Aspirante-a-Oficial',
        'Subtenente',
        '1º Sargento',
        '2º Sargento',
        '3º Sargento',
        'Cabo (Cb)',
        'Soldado (Sd)',
    ];
    $requesterRanksMap = array_fill_keys($requesterRanks, true);

    $buildAdminLoginViewData = static function (
        DashboardRepository $repo,
        string $csrfToken,
        ?string $loginError
    ) use ($buildGroupedSections): array {
        $meta = $repo->getMeta();
        $sections = $repo->getSections();

        return [
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => 'login',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => date('d/m/Y H:i'),
            'csrfToken' => $csrfToken,
            'loginError' => $loginError,
            'isAdminLogged' => !empty($_SESSION['is_admin']),
        ];
    };

    // Shared helpers above remain in scope for each route module.
    require __DIR__ . '/routes/entry.php';
    require __DIR__ . '/routes/legacy-public.php';
    require __DIR__ . '/routes/auth.php';
    require __DIR__ . '/routes/account.php';
    require __DIR__ . '/routes/documentation.php';
    require __DIR__ . '/routes/dashboard.php';
    require __DIR__ . '/routes/tickets.php';
    require __DIR__ . '/routes/admin-categories.php';
    require __DIR__ . '/routes/admin-users.php';
    require __DIR__ . '/routes/legacy-auth.php';
    require __DIR__ . '/routes/legacy-dashboard.php';
    require __DIR__ . '/routes/legacy-account.php';
    require __DIR__ . '/routes/legacy-crud.php';
    require __DIR__ . '/routes/legacy-section.php';
};
