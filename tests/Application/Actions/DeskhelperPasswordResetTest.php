<?php

declare(strict_types=1);

namespace Tests\Application\Actions;

use App\Application\Security\PasswordResetService;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use PDO;
use Tests\TestCase;

final class DeskhelperPasswordResetTest extends TestCase
{
    public function testActiveUserCanUseTokenOnlyOnce(): void
    {
        $app = $this->getAppInstance();
        $service = $app->getContainer()->get(PasswordResetService::class);
        $reset = $service->createToken('admin@deskhelper.local', '192.0.2.20');
        $this->assertIsArray($reset);
        $this->assertTrue($service->tokenIsValid($reset['token']));
        $this->assertTrue($service->resetPassword($reset['token'], 'new-password-123'));
        $this->assertFalse($service->tokenIsValid($reset['token']));
        $this->assertFalse($service->resetPassword($reset['token'], 'another-password'));
        $users = $app->getContainer()->get(DeskhelperUserRepository::class);
        $this->assertNotNull($users->verifyCredentials('admin@deskhelper.local', 'new-password-123'));
    }

    public function testUnknownAndInactiveAccountsDoNotReceiveTokens(): void
    {
        $app = $this->getAppInstance();
        $service = $app->getContainer()->get(PasswordResetService::class);
        $this->assertNull($service->createToken('unknown@example.test', '192.0.2.21'));
        $pdo = $app->getContainer()->get(PDO::class);
        $pdo->exec("UPDATE users SET status = 'disabled' WHERE id = 1");
        $this->assertNull($service->createToken('admin@deskhelper.local', '192.0.2.21'));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testRequestLimitAppliesWithoutRevealingAccount(): void
    {
        $service = $this->getAppInstance()->getContainer()->get(PasswordResetService::class);
        $this->assertIsArray($service->createToken('admin@deskhelper.local', '192.0.2.22'));
        $this->assertIsArray($service->createToken('admin@deskhelper.local', '192.0.2.22'));
        $this->assertIsArray($service->createToken('admin@deskhelper.local', '192.0.2.22'));
        $this->assertNull($service->createToken('admin@deskhelper.local', '192.0.2.22'));
    }

    public function testForgotPasswordResponseShowsClearNeutralNotification(): void
    {
        $_SESSION = ['csrf_token' => 'test-csrf'];
        $response = $this->getAppInstance()->handle(
            $this->createRequest('POST', '/deskhelper/forgot-password', [], [], ['REMOTE_ADDR' => '192.0.2.23'])
                ->withParsedBody(['csrf_token' => 'test-csrf', 'email' => 'unknown@example.test'])
        );
        $body = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Link solicitado', $body);
        $this->assertStringContainsString('Verifique também a pasta de spam', $body);
    }
}
