<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use App\Application\Security\DeskhelperLoginService;
use App\Application\Security\PasswordResetMailer;
use App\Application\Security\PasswordResetService;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

$app->get('/register', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $deskhelperFlashPull, $buildDeskhelperAuthViewData) {
    $twig = $app->getContainer()->get(Twig::class);

    return $twig->render($response, 'deskhelper-auth.twig', $buildDeskhelperAuthViewData([
        'pageTitle' => 'Solicitar acesso',
        'pageSubtitle' => 'Cadastre-se para acessar o Deskhelper. Abertura, acompanhamento e tratamento de ocorrências em um único ambiente. Seu acesso será liberado após aprovação.',
        'formAction' => app_url('/register'),
        'formMode' => 'register',
        'submitLabel' => 'Cadastrar',
        'secondaryHref' => app_url('/login'),
        'secondaryLabel' => 'Já tenho acesso',
        'csrfToken' => $ensureCsrfToken(),
        'flashMessage' => $deskhelperFlashPull(),
        'activeSection' => 'register',
    ]));
});

$app->post('/register', function (Request $request, Response $response) use ($app, $isValidCsrf, $ensureCsrfToken, $buildDeskhelperAuthViewData) {
    $twig = $app->getContainer()->get(Twig::class);
    /** @var DeskhelperUserRepository $users */
    $users = $app->getContainer()->get(DeskhelperUserRepository::class);

    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];
    $form = [
        'name' => trim((string) ($data['name'] ?? '')),
        'email' => trim((string) ($data['email'] ?? '')),
        'rank' => trim((string) ($data['rank'] ?? '')),
        'sector' => trim((string) ($data['sector'] ?? '')),
        'phone' => trim((string) ($data['phone'] ?? '')),
    ];

    $render = static function (?string $error) use ($twig, $response, $buildDeskhelperAuthViewData, $ensureCsrfToken, $form) {
        return $twig->render($response, 'deskhelper-auth.twig', $buildDeskhelperAuthViewData([
            'pageTitle' => 'Solicitar acesso',
            'pageSubtitle' => 'Cadastre-se para acessar o Deskhelper. Abertura, acompanhamento e tratamento de ocorrências em um único ambiente. Seu acesso será liberado após aprovação.',
            'formAction' => app_url('/register'),
            'formMode' => 'register',
            'submitLabel' => 'Cadastrar',
            'secondaryHref' => app_url('/login'),
            'secondaryLabel' => 'Já tenho acesso',
            'csrfToken' => $ensureCsrfToken(),
            'formError' => $error,
            'form' => $form,
            'activeSection' => 'register',
        ]));
    };

    if (!$isValidCsrf($request)) {
        return $render('Sessao expirada. Atualize a pagina e tente novamente.');
    }

    $password = (string) ($data['password'] ?? '');
    $passwordConfirm = (string) ($data['password_confirm'] ?? '');

    if (
        $form['rank'] === ''
        || $form['name'] === ''
        || $form['sector'] === ''
        || $form['phone'] === ''
        || $form['email'] === ''
        || $password === ''
        || $passwordConfirm === ''
    ) {
        return $render('Preencha os campos obrigatórios.');
    }

    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        return $render('Informe um e-mail valido.');
    }

    $phoneDigits = preg_replace('/\D+/', '', $form['phone']) ?? '';
    if (!preg_match('/^[\d\s()+-]+$/', $form['phone'])) {
        return $render('Informe um telefone valido.');
    }

    if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) {
        return $render('Informe um telefone valido.');
    }

    if (mb_strlen($password) < 6) {
        return $render('A senha precisa ter pelo menos 6 caracteres.');
    }

    if ($password !== $passwordConfirm) {
        return $render('As senhas nao conferem.');
    }

    try {
        $users->createPendingUser([
            'name' => $form['name'],
            'email' => $form['email'],
            'rank' => $form['rank'],
            'sector' => $form['sector'],
            'phone' => $form['phone'],
            'password' => $password,
        ]);
    } catch (\Throwable $throwable) {
        return $render($throwable->getMessage());
    }

    $_SESSION['deskhelper_flash'] = 'Cadastro enviado com sucesso. Aguarde a aprovacao do administrador.';

    return $response->withHeader('Location', app_url('/register/pending'))->withStatus(302);
});

$app->get('/register/pending', function (Request $request, Response $response) use ($app, $deskhelperFlashPull) {
    $twig = $app->getContainer()->get(Twig::class);

    return $twig->render($response, 'deskhelper-pending.twig', [
        'flashMessage' => $deskhelperFlashPull(),
    ]);
});

$app->get('/forgot-password', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $buildDeskhelperAuthViewData) {
    return $app->getContainer()->get(Twig::class)->render($response, 'deskhelper-auth.twig', $buildDeskhelperAuthViewData([
        'pageTitle' => 'Redefinir senha',
        'pageSubtitle' => 'Informe seu e-mail de acesso. Se a conta estiver ativa, enviaremos um link válido por 30 minutos.',
        'formAction' => app_url('/forgot-password'),
        'formMode' => 'forgot',
        'submitLabel' => 'Enviar link',
        'secondaryHref' => app_url('/login'),
        'secondaryLabel' => 'Voltar ao login',
        'csrfToken' => $ensureCsrfToken(),
    ]));
});

$app->post('/forgot-password', function (Request $request, Response $response) use ($app, $isValidCsrf, $ensureCsrfToken, $buildDeskhelperAuthViewData) {
    $twig = $app->getContainer()->get(Twig::class);
    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];
    $email = trim((string) ($data['email'] ?? ''));
    $view = static fn (?string $error = null, ?string $message = null) => $buildDeskhelperAuthViewData([
        'pageTitle' => 'Redefinir senha',
        'pageSubtitle' => 'Informe seu e-mail de acesso. Se a conta estiver ativa, enviaremos um link válido por 30 minutos.',
        'formAction' => app_url('/forgot-password'),
        'formMode' => 'forgot',
        'submitLabel' => 'Enviar link',
        'secondaryHref' => app_url('/login'),
        'secondaryLabel' => 'Voltar ao login',
        'csrfToken' => $ensureCsrfToken(),
        'formError' => $error,
        'flashMessage' => $message,
        'form' => ['email' => $email],
    ]);
    if (!$isValidCsrf($request)) {
        return $twig->render($response, 'deskhelper-auth.twig', $view('Sessão expirada. Atualize a página e tente novamente.'));
    }

    /** @var PasswordResetService $resets */
    $resets = $app->getContainer()->get(PasswordResetService::class);
    $reset = $resets->createToken($email, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'));
    if (is_array($reset)) {
        try {
            $app->getContainer()->get(PasswordResetMailer::class)->send($reset['email'], $reset['name'], $reset['rank'], $reset['token']);
        } catch (\Throwable $throwable) {
            $resets->invalidateToken($reset['token']);
            $app->getContainer()->get(LoggerInterface::class)->error('Password reset e-mail failed', ['exception' => $throwable]);
        }
    }

    return $twig->render($response, 'deskhelper-auth.twig', $view(null, 'Se o e-mail corresponder a uma conta ativa, você receberá as instruções em alguns minutos.'));
});

$app->get('/reset-password', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $buildDeskhelperAuthViewData) {
    $token = trim((string) (($request->getQueryParams()['token'] ?? '')));
    $valid = $app->getContainer()->get(PasswordResetService::class)->tokenIsValid($token);
    return $app->getContainer()->get(Twig::class)->render($response, 'deskhelper-auth.twig', $buildDeskhelperAuthViewData([
        'pageTitle' => 'Criar nova senha',
        'pageSubtitle' => 'Defina uma nova senha com pelo menos 8 caracteres.',
        'formAction' => app_url('/reset-password'),
        'formMode' => 'reset',
        'submitLabel' => 'Salvar nova senha',
        'secondaryHref' => app_url('/login'),
        'secondaryLabel' => 'Voltar ao login',
        'csrfToken' => $ensureCsrfToken(),
        'resetToken' => $token,
        'resetTokenValid' => $valid,
        'formError' => $valid ? null : 'Este link é inválido, expirou ou já foi utilizado.',
    ]));
});

$app->post('/reset-password', function (Request $request, Response $response) use ($app, $isValidCsrf, $ensureCsrfToken, $buildDeskhelperAuthViewData) {
    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];
    $token = trim((string) ($data['token'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $confirm = (string) ($data['password_confirm'] ?? '');
    /** @var PasswordResetService $resets */
    $resets = $app->getContainer()->get(PasswordResetService::class);
    $error = null;
    if (!$isValidCsrf($request)) {
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif (mb_strlen($password) < 8) {
        $error = 'A nova senha deve ter pelo menos 8 caracteres.';
    } elseif ($password !== $confirm) {
        $error = 'A confirmação da nova senha não confere.';
    } elseif (!$resets->resetPassword($token, $password)) {
        $error = 'Este link é inválido, expirou ou já foi utilizado.';
    }

    if ($error === null) {
        $_SESSION['deskhelper_flash'] = 'Senha redefinida com sucesso. Entre com sua nova senha.';
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    }

    return $app->getContainer()->get(Twig::class)->render($response, 'deskhelper-auth.twig', $buildDeskhelperAuthViewData([
        'pageTitle' => 'Criar nova senha',
        'pageSubtitle' => 'Defina uma nova senha com pelo menos 8 caracteres.',
        'formAction' => app_url('/reset-password'),
        'formMode' => 'reset',
        'submitLabel' => 'Salvar nova senha',
        'secondaryHref' => app_url('/login'),
        'secondaryLabel' => 'Voltar ao login',
        'csrfToken' => $ensureCsrfToken(),
        'resetToken' => $token,
        'resetTokenValid' => $resets->tokenIsValid($token),
        'formError' => $error,
    ]));
});

$app->get('/login', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $deskhelperCurrentUser, $deskhelperFlashPull, $buildDeskhelperAuthViewData) {
    if ($deskhelperCurrentUser() !== null) {
        return $response->withHeader('Location', app_url('/app'))->withStatus(302);
    }

    $twig = $app->getContainer()->get(Twig::class);

    return $twig->render($response, 'deskhelper-auth.twig', $buildDeskhelperAuthViewData([
        'badge' => 'Área restrita',
        'pageTitle' => 'Acesso administrativo',
        'pageSubtitle' => 'Entre para acessar e gerenciar os chamados do Deskhelper.',
        'formAction' => app_url('/login'),
        'submitLabel' => 'Entrar',
        'secondaryHref' => app_url('/register'),
        'secondaryLabel' => 'Solicitar acesso',
        'csrfToken' => $ensureCsrfToken(),
        'flashMessage' => $deskhelperFlashPull(),
        'activeSection' => 'login',
    ]));
});

$app->post('/login', function (Request $request, Response $response) use ($app, $isValidCsrf, $ensureCsrfToken, $buildDeskhelperAuthViewData, $deskhelperStoreAuth) {
    $twig = $app->getContainer()->get(Twig::class);

    $payload = $request->getParsedBody();
    $data = is_array($payload) ? $payload : [];
    $email = trim((string) ($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $render = static function (?string $error) use ($twig, $response, $buildDeskhelperAuthViewData, $ensureCsrfToken, $email) {
        return $twig->render($response, 'deskhelper-auth.twig', $buildDeskhelperAuthViewData([
            'badge' => 'Área restrita',
            'pageTitle' => 'Acesso administrativo',
            'pageSubtitle' => 'Entre para acessar e gerenciar os chamados do Deskhelper.',
            'formAction' => app_url('/login'),
            'submitLabel' => 'Entrar',
            'secondaryHref' => app_url('/register'),
            'secondaryLabel' => 'Solicitar acesso',
            'csrfToken' => $ensureCsrfToken(),
            'formError' => $error,
            'form' => ['email' => $email],
            'activeSection' => 'login',
        ]));
    };

    if (!$isValidCsrf($request)) {
        return $render('Sessao expirada. Atualize a pagina e tente novamente.');
    }

    $login = $app->getContainer()->get(DeskhelperLoginService::class);
    $result = $login->authenticate($email, $password, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
    if ($result['retry_after'] > 0) {
        return $render(sprintf('Muitas tentativas de acesso. Tente novamente em %d segundos.', $result['retry_after']))
            ->withStatus(429)
            ->withHeader('Retry-After', (string) $result['retry_after']);
    }
    $user = $result['user'];
    if ($user === null) {
        return $render('E-mail ou senha invalidos.');
    }

    $status = strtolower(trim((string) ($user['status'] ?? 'pending')));
    if ($status === 'pending') {
        $_SESSION['deskhelper_flash'] = 'Seu cadastro ainda aguarda aprovacao.';
        return $response->withHeader('Location', app_url('/register/pending'))->withStatus(302);
    }

    if ($status !== 'active') {
        return $render('Seu acesso nao esta liberado no momento.');
    }
    $deskhelperStoreAuth($user);

    return $response->withHeader('Location', app_url('/app'))->withStatus(302);
});

$app->post('/logout', function (Request $request, Response $response) use ($isValidCsrf, $deskhelperClearAuth) {
    if (!$isValidCsrf($request)) {
        return $response->withHeader('Location', app_url('/app'))->withStatus(302);
    }

    $deskhelperClearAuth();
    unset($_SESSION['is_admin'], $_SESSION['admin_user']);

    return $response->withHeader('Location', app_url('/login'))->withStatus(302);
});
