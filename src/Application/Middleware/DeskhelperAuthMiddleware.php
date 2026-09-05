<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class DeskhelperAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }

        $userId = (int) ($_SESSION['deskhelper_user_id'] ?? 0);
        if ($userId <= 0) {
            $_SESSION['deskhelper_flash'] = 'Sua sessão expirou. Faça login novamente.';

            return (new SlimResponse())
                ->withHeader('Location', $this->buildAppUrl('/login'))
                ->withStatus(302);
        }

        return $handler->handle($request);
    }

    private function buildAppUrl(string $path): string
    {
        $configuredBasePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''));
        $basePath = $configuredBasePath === '' || $configuredBasePath === '/'
            ? ''
            : '/' . trim($configuredBasePath, '/');

        return ($basePath !== '' ? $basePath : '') . '/' . ltrim($path, '/');
    }
}
