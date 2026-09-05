<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class DeskhelperRoleMiddleware implements MiddlewareInterface
{
    /**
     * @param string[] $allowedRoles
     */
    public function __construct(private array $allowedRoles)
    {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }

        $sessionRoles = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($_SESSION['deskhelper_user_roles'] ?? [])
        );

        foreach ($this->allowedRoles as $allowedRole) {
            if (in_array(strtolower(trim($allowedRole)), $sessionRoles, true)) {
                return $handler->handle($request);
            }
        }

        return (new SlimResponse())
            ->withHeader('Location', $this->buildAppUrl('/app'))
            ->withStatus(302);
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
