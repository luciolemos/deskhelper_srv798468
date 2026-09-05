<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use Slim\Views\Twig;

$app->get('/admin/login', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $buildAdminLoginViewData) {
    if (!empty($_SESSION['is_admin'])) {
        return $response->withHeader('Location', app_url('/admin'))->withStatus(302);
    }

    /** @var DashboardRepository $repo */
    $repo = $app->getContainer()->get(DashboardRepository::class);
    $twig = $app->getContainer()->get(Twig::class);

    return $twig->render(
        $response,
        'admin-login.twig',
        $buildAdminLoginViewData($repo, $ensureCsrfToken(), null)
    );
});

$app->post('/admin/login', function (Request $request, Response $response) use ($app, $isValidCsrf, $ensureCsrfToken, $buildAdminLoginViewData) {
    /** @var DashboardRepository $repo */
    $repo = $app->getContainer()->get(DashboardRepository::class);
    $twig = $app->getContainer()->get(Twig::class);

    if (!$isValidCsrf($request)) {
        return $twig->render(
            $response,
            'admin-login.twig',
            $buildAdminLoginViewData($repo, $ensureCsrfToken(), 'Sessão expirada. Atualize a página e tente novamente.')
        );
    }

    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    $now = time();
    $windowSeconds = 300;
    $maxAttempts = 5;
    $lockSeconds = 600;

    $attempts = $_SESSION['admin_login_attempts'] ?? [
        'count' => 0,
        'first' => $now,
        'locked_until' => 0,
    ];

    if (!is_array($attempts)) {
        $attempts = [
            'count' => 0,
            'first' => $now,
            'locked_until' => 0,
        ];
    }

    $lockedUntil = (int) ($attempts['locked_until'] ?? 0);
    if ($lockedUntil > $now) {
        $remaining = $lockedUntil - $now;
        return $twig->render(
            $response,
            'admin-login.twig',
            $buildAdminLoginViewData(
                $repo,
                $ensureCsrfToken(),
                sprintf('Muitas tentativas. Tente novamente em %d segundos.', $remaining)
            )
        );
    }

    $firstAttemptTs = (int) ($attempts['first'] ?? $now);
    if (($now - $firstAttemptTs) > $windowSeconds) {
        $attempts = [
            'count' => 0,
            'first' => $now,
            'locked_until' => 0,
        ];
    }

    if ($repo->verifyAdmin($username, $password)) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_user'] = $username;
        $_SESSION['admin_login_attempts'] = [
            'count' => 0,
            'first' => $now,
            'locked_until' => 0,
        ];
        return $response->withHeader('Location', app_url('/admin'))->withStatus(302);
    }

    $attempts['count'] = (int) ($attempts['count'] ?? 0) + 1;
    if ($attempts['count'] >= $maxAttempts) {
        $attempts['locked_until'] = $now + $lockSeconds;
        $attempts['count'] = 0;
        $attempts['first'] = $now;
        $_SESSION['admin_login_attempts'] = $attempts;

        return $twig->render(
            $response,
            'admin-login.twig',
            $buildAdminLoginViewData(
                $repo,
                $ensureCsrfToken(),
                sprintf('Muitas tentativas. Acesso bloqueado por %d minutos.', (int) ($lockSeconds / 60))
            )
        );
    }

    $_SESSION['admin_login_attempts'] = $attempts;

    return $twig->render(
        $response,
        'admin-login.twig',
        $buildAdminLoginViewData($repo, $ensureCsrfToken(), 'Usuário ou senha inválidos.')
    );
});

$app->post('/admin/logout', function (Request $request, Response $response) use ($isValidCsrf) {
    if (!$isValidCsrf($request)) {
        return $response->withHeader('Location', app_url('/admin'))->withStatus(302);
    }

    unset($_SESSION['is_admin'], $_SESSION['admin_user']);

    return $response->withHeader('Location', app_url('/admin/login'))->withStatus(302);
});

$app->get('/admin/avatar', function (Request $request, Response $response) use ($resolveAdminAvatarStoredValue, $normalizeAdminAvatarFile, $isValidUploadedAvatarPath) {
    $adminUsername = (string) ($_SESSION['admin_user'] ?? 'admin');
    $stored = $resolveAdminAvatarStoredValue($adminUsername);

    $relativePath = 'face6_620_620.png';
    if ($isValidUploadedAvatarPath($stored)) {
        $relativePath = $stored;
    } else {
        $relativePath = $normalizeAdminAvatarFile($stored);
    }

    $absolutePath = dirname(__DIR__, 2) . '/public/assets/img/avatar/' . $relativePath;
    if (!is_file($absolutePath)) {
        $absolutePath = dirname(__DIR__, 2) . '/public/assets/img/avatar/face6_620_620.png';
    }

    $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $mimeType = match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => 'image/png',
    };

    $response->getBody()->write((string) file_get_contents($absolutePath));

    return $response
        ->withHeader('Content-Type', $mimeType)
        ->withHeader('Cache-Control', 'private, max-age=60')
        ->withStatus(200);
});
