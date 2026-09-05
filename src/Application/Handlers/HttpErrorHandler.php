<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Actions\ActionError;
use App\Application\Actions\ActionPayload;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpNotImplementedException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;
use Throwable;

class HttpErrorHandler extends SlimErrorHandler
{
    /**
     * @inheritdoc
     */
    protected function respond(): Response
    {
        $exception = $this->exception;
        $statusCode = 500;
        $error = new ActionError(
            ActionError::SERVER_ERROR,
            'An internal error has occurred while processing your request.'
        );

        if ($exception instanceof HttpException) {
            $statusCode = $exception->getCode();
            $error->setDescription($exception->getMessage());

            if ($exception instanceof HttpNotFoundException) {
                $error->setType(ActionError::RESOURCE_NOT_FOUND);
            } elseif ($exception instanceof HttpMethodNotAllowedException) {
                $error->setType(ActionError::NOT_ALLOWED);
            } elseif ($exception instanceof HttpUnauthorizedException) {
                $error->setType(ActionError::UNAUTHENTICATED);
            } elseif ($exception instanceof HttpForbiddenException) {
                $error->setType(ActionError::INSUFFICIENT_PRIVILEGES);
            } elseif ($exception instanceof HttpBadRequestException) {
                $error->setType(ActionError::BAD_REQUEST);
            } elseif ($exception instanceof HttpNotImplementedException) {
                $error->setType(ActionError::NOT_IMPLEMENTED);
            }
        }

        if (
            !($exception instanceof HttpException)
            && $exception instanceof Throwable
            && $this->displayErrorDetails
        ) {
            $error->setDescription($exception->getMessage());
        }

        if ($this->wantsHtmlResponse($this->request)) {
            return $this->respondWithHtml($statusCode, $error);
        }

        $payload = new ActionPayload($statusCode, null, $error);
        $encodedPayload = json_encode($payload, JSON_PRETTY_PRINT);

        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write($encodedPayload);

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function wantsHtmlResponse(Request $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));

        if ($accept === '') {
            return true;
        }

        if (str_contains($accept, 'text/html')) {
            return true;
        }

        if (str_contains($accept, 'application/json')) {
            return false;
        }

        return str_contains($accept, '*/*');
    }

    private function respondWithHtml(int $statusCode, ActionError $error): Response
    {
        $basePath = $this->resolveBasePath();
        $loginUrl = ($basePath !== '' ? $basePath : '') . '/login';
        $appUrl = ($basePath !== '' ? $basePath : '') . '/app';

        $title = 'Nao foi possivel concluir sua solicitacao';
        $message = 'A pagina nao pode ser carregada agora. Se ela ficou aberta por muito tempo, sua sessao pode ter expirado.';

        if ($statusCode === 404) {
            $title = 'Pagina nao encontrada';
            $message = 'O endereco acessado nao existe mais ou foi alterado.';
        } elseif ($statusCode === 401) {
            $title = 'Sua sessao expirou';
            $message = 'Entre novamente para continuar usando o Deskhelper.';
        } elseif ($statusCode === 403) {
            $title = 'Acesso nao permitido';
            $message = 'Voce nao tem permissao para acessar esta area com o perfil atual.';
        }

        if ($this->displayErrorDetails && $error->getDescription() !== '') {
            $message .= ' Detalhe tecnico: ' . $error->getDescription();
        }

        $html = sprintf(
            <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>%s | Deskhelper</title>
  <style>
    :root {
      --bg: #eef2e7;
      --surface: #ffffff;
      --text: #1b2618;
      --muted: #586953;
      --accent: #4f672f;
      --border: #d6decb;
      --shadow: 0 18px 34px rgba(33, 48, 28, 0.14);
      --radius: 20px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: "Inter", "Segoe UI", Roboto, Arial, sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(165, 111, 17, 0.12), transparent 30%%),
        linear-gradient(180deg, rgba(250, 247, 239, 0.98), rgba(243, 246, 239, 0.98));
      display: grid;
      place-items: center;
      padding: 24px;
    }
    .dh-error-shell {
      width: min(720px, 100%%);
      display: grid;
      gap: 20px;
      padding: 28px;
      border-radius: var(--radius);
      background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(247,248,243,.98));
      border: 1px solid rgba(0, 0, 0, 0.06);
      box-shadow: var(--shadow);
    }
    .dh-error-brand {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .dh-error-brand img {
      width: 52px;
      height: 68px;
      object-fit: contain;
    }
    .dh-error-brand-copy {
      display: grid;
      gap: 4px;
    }
    .dh-error-brand-copy strong {
      font-size: 1rem;
    }
    .dh-error-brand-copy span {
      color: var(--muted);
      font-size: 0.92rem;
    }
    .dh-error-copy {
      display: grid;
      gap: 10px;
    }
    .dh-error-copy h1 {
      margin: 0;
      font-size: clamp(1.9rem, 4vw, 2.8rem);
      line-height: 0.98;
      letter-spacing: -0.04em;
    }
    .dh-error-copy p {
      margin: 0;
      color: var(--muted);
      font-size: 1rem;
      line-height: 1.6;
    }
    .dh-error-status {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      width: fit-content;
      padding: 7px 12px;
      border-radius: 999px;
      background: rgba(79, 103, 47, 0.09);
      color: var(--accent);
      font-size: 0.8rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .dh-error-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }
    .dh-error-actions a {
      min-height: 42px;
      padding: 0 16px;
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      font-weight: 700;
      font-size: 0.92rem;
      transition: transform 140ms ease, box-shadow 140ms ease, background-color 140ms ease;
    }
    .dh-error-actions a:hover {
      transform: translateY(-1px);
    }
    .dh-error-actions .is-primary {
      background: var(--accent);
      color: #fff;
      box-shadow: 0 10px 20px rgba(79, 103, 47, 0.18);
    }
    .dh-error-actions .is-secondary {
      background: rgba(79, 103, 47, 0.08);
      color: var(--text);
      border: 1px solid rgba(79, 103, 47, 0.12);
    }
  </style>
</head>
<body>
  <main class="dh-error-shell">
    <div class="dh-error-brand">
      <img src="%s/assets/img/om.png" alt="Deskhelper">
      <div class="dh-error-brand-copy">
        <strong>Deskhelper</strong>
        <span>Service Desk Interno</span>
      </div>
    </div>
    <div class="dh-error-copy">
      <span class="dh-error-status">Erro %d</span>
      <h1>%s</h1>
      <p>%s</p>
    </div>
    <div class="dh-error-actions">
      <a class="is-primary" href="%s">Entrar novamente</a>
      <a class="is-secondary" href="%s">Voltar ao painel</a>
    </div>
  </main>
</body>
</html>
HTML,
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($basePath !== '' ? $basePath : '', ENT_QUOTES, 'UTF-8'),
            $statusCode,
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8')
        );

        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    private function resolveBasePath(): string
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
