<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use App\Application\Middleware\DeskhelperAuthMiddleware;
use Slim\Views\Twig;

$app->get('/avatar', function (Request $request, Response $response) use ($deskhelperCurrentUser, $resolveDeskhelperAvatarAbsolutePath) {
    $user = $deskhelperCurrentUser();
    $absolutePath = $resolveDeskhelperAvatarAbsolutePath($user['avatar_path'] ?? '');
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
})->add(new DeskhelperAuthMiddleware());

$app->get('/account', function (Request $request, Response $response) use ($app, $deskhelperCurrentUser, $ensureCsrfToken, $deskhelperFlashPull) {
    $twig = $app->getContainer()->get(Twig::class);
    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    return $twig->render($response, 'deskhelper-account.twig', [
        'user' => $user,
        'accountUser' => $user,
        'accountRoles' => array_values((array) ($user['roles'] ?? [])),
        'accountAvatarUrl' => app_url('/avatar?v=' . rawurlencode(substr(sha1((string) ($user['avatar_path'] ?? '')), 0, 12))),
        'flashMessage' => $deskhelperFlashPull(),
        'csrfToken' => $ensureCsrfToken(),
        'shellTitle' => 'Minha conta',
        'shellSubtitle' => 'Consulte seus dados cadastrais e o perfil de acesso associado ao deskhelper.',
        'shellBreadcrumbs' => [
            ['label' => 'Deskhelper', 'url' => app_url('app')],
            ['label' => 'Minha conta', 'url' => app_url('account')],
        ],
    ]);
})->add(new DeskhelperAuthMiddleware());

$app->post('/account/phone', function (Request $request, Response $response) use ($app, $isValidCsrf, $deskhelperCurrentUser, $deskhelperSetFlash) {
    if (!$isValidCsrf($request)) {
        $deskhelperSetFlash('Sessão expirada. Atualize a página e tente novamente.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);
    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];

    try {
        $users->updatePhone((int) ($user['id'] ?? 0), (string) ($data['phone'] ?? ''));
        $deskhelperSetFlash('Telefone atualizado com sucesso.');
    } catch (\Throwable $throwable) {
        $deskhelperSetFlash($throwable->getMessage());
    }

    return $response->withHeader('Location', app_url('/account'))->withStatus(302);
})->add(new DeskhelperAuthMiddleware());

$app->post('/account/email', function (Request $request, Response $response) use ($app, $isValidCsrf, $deskhelperCurrentUser, $deskhelperSetFlash) {
    if (!$isValidCsrf($request)) {
        $deskhelperSetFlash('Sessão expirada. Atualize a página e tente novamente.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);
    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];
    $currentPassword = (string) ($data['current_password'] ?? '');

    if ($currentPassword === '' || !$users->verifyCredentials((string) ($user['email'] ?? ''), $currentPassword)) {
        $deskhelperSetFlash('A senha atual está incorreta.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    try {
        $updatedEmail = $users->updateEmail((int) ($user['id'] ?? 0), (string) ($data['email'] ?? ''));
        $_SESSION['deskhelper_user_email'] = $updatedEmail;
        $deskhelperSetFlash('E-mail atualizado com sucesso.');
    } catch (\Throwable $throwable) {
        $deskhelperSetFlash($throwable->getMessage());
    }

    return $response->withHeader('Location', app_url('/account'))->withStatus(302);
})->add(new DeskhelperAuthMiddleware());

$app->post('/account/password', function (Request $request, Response $response) use ($app, $isValidCsrf, $deskhelperCurrentUser, $deskhelperSetFlash) {
    if (!$isValidCsrf($request)) {
        $deskhelperSetFlash('Sessão expirada. Atualize a página e tente novamente.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);
    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];
    $newPassword = (string) ($data['new_password'] ?? '');
    $confirmPassword = (string) ($data['confirm_password'] ?? '');

    if ($newPassword !== $confirmPassword) {
        $deskhelperSetFlash('A confirmação da nova senha não confere.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    try {
        $users->updatePassword(
            (int) ($user['id'] ?? 0),
            (string) ($data['current_password'] ?? ''),
            $newPassword
        );
        $deskhelperSetFlash('Senha atualizada com sucesso.');
    } catch (\Throwable $throwable) {
        $deskhelperSetFlash($throwable->getMessage());
    }

    return $response->withHeader('Location', app_url('/account'))->withStatus(302);
})->add(new DeskhelperAuthMiddleware());

$app->post('/account/avatar', function (Request $request, Response $response) use ($app, $isValidCsrf, $deskhelperCurrentUser, $deskhelperSetFlash, $isValidUploadedAvatarPath) {
    if (!$isValidCsrf($request)) {
        $deskhelperSetFlash('Sessão expirada. Atualize a página e tente novamente.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    $user = $deskhelperCurrentUser();
    if ($user === null) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);
    $uploadedFiles = $request->getUploadedFiles();
    $avatarUpload = $uploadedFiles['avatar_upload'] ?? null;
    if (!$avatarUpload instanceof UploadedFileInterface || $avatarUpload->getError() !== UPLOAD_ERR_OK) {
        $deskhelperSetFlash('Selecione uma imagem válida para enviar.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    $maxSize = 2 * 1024 * 1024;
    if ($avatarUpload->getSize() > $maxSize) {
        $deskhelperSetFlash('A foto deve ter no máximo 2MB.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    $clientFilename = strtolower((string) ($avatarUpload->getClientFilename() ?? ''));
    $extension = pathinfo($clientFilename, PATHINFO_EXTENSION);
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        $deskhelperSetFlash('Formato de foto inválido. Use PNG, JPG, JPEG ou WEBP.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    $uploadDir = dirname(__DIR__, 2) . '/public/assets/img/avatar/uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        $deskhelperSetFlash('Falha ao preparar diretório de upload da foto.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    $userName = strtolower((string) preg_replace('/[^a-z0-9._\-]/i', '-', (string) ($user['name'] ?? 'usuario')) ?? 'usuario');
    $userName = trim((string) preg_replace('/-+/', '-', $userName), '-');
    if ($userName === '') {
        $userName = 'usuario';
    }

    $targetFilename = sprintf('%s-%s-%s.%s', $userName, date('YmdHis'), bin2hex(random_bytes(4)), $extension);
    $targetPath = $uploadDir . '/' . $targetFilename;

    try {
        $avatarUpload->moveTo($targetPath);
    } catch (\Throwable $throwable) {
        $deskhelperSetFlash('Falha ao salvar a foto enviada.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    $normalizedAvatar = 'uploads/' . $targetFilename;
    if (!$isValidUploadedAvatarPath($normalizedAvatar)) {
        @unlink($targetPath);
        $deskhelperSetFlash('Falha ao normalizar a foto enviada.');
        return $response->withHeader('Location', app_url('/account'))->withStatus(302);
    }

    $currentAvatar = trim((string) ($user['avatar_path'] ?? ''));
    if ($currentAvatar !== '' && $isValidUploadedAvatarPath($currentAvatar)) {
        $currentPath = dirname(__DIR__, 2) . '/public/assets/img/avatar/' . $currentAvatar;
        if (is_file($currentPath)) {
            @unlink($currentPath);
        }
    }

    try {
        $users->updateAvatarPath((int) ($user['id'] ?? 0), $normalizedAvatar);
        $deskhelperSetFlash('Foto atualizada com sucesso.');
    } catch (\Throwable $throwable) {
        @unlink($targetPath);
        $deskhelperSetFlash('Falha ao atualizar a foto do usuário.');
    }

    return $response->withHeader('Location', app_url('/account'))->withStatus(302);
})->add(new DeskhelperAuthMiddleware());
