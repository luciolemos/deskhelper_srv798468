<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/** Refresh authorization before route guards and templates use the session. */
class DeskhelperSessionMiddleware implements MiddlewareInterface
{
    public function __construct(private DeskhelperUserRepository $users)
    {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $userId = (int) ($_SESSION['deskhelper_user_id'] ?? 0);
        if ($userId > 0) {
            $user = $this->users->findById($userId);
            if ($user === null || ($user['status'] ?? '') !== 'active') {
                unset(
                    $_SESSION['deskhelper_user_id'],
                    $_SESSION['deskhelper_user_email'],
                    $_SESSION['deskhelper_user_name'],
                    $_SESSION['deskhelper_user_roles']
                );
            } else {
                $_SESSION['deskhelper_user_email'] = (string) $user['email'];
                $_SESSION['deskhelper_user_name'] = (string) $user['name'];
                $_SESSION['deskhelper_user_roles'] = $user['roles'];
            }
        }

        return $handler->handle($request);
    }
}
