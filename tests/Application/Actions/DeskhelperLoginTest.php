<?php

declare(strict_types=1);

namespace Tests\Application\Actions;

use PDO;
use Tests\TestCase;

class DeskhelperLoginTest extends TestCase
{
    private function login(string $email, string $password, string $ip = '192.0.2.10', bool $validCsrf = true): \Psr\Http\Message\ResponseInterface
    {
        $_SESSION = ['csrf_token' => 'test-csrf'];
        return $this->getAppInstance()->handle(
            $this->createRequest('POST', '/deskhelper/login', ['X-Forwarded-For' => '198.51.100.' . random_int(1, 200)], [], ['REMOTE_ADDR' => $ip])
                ->withParsedBody(['email' => $email, 'password' => $password, 'csrf_token' => $validCsrf ? 'test-csrf' : 'invalid'])
        );
    }

    private function database(): PDO
    {
        return $this->getAppInstance()->getContainer()->get(PDO::class);
    }

    public function testFiveFailuresBlockEvenCorrectPasswordFromAnotherSessionAndIp(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $response = $this->login(' ADMIN@DESKHELPER.LOCAL ', 'wrong');
            $this->assertSame(200, $response->getStatusCode());
        }
        $response = $this->login('admin@deskhelper.local', 'wrong');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(599, (int) $response->getHeaderLine('Retry-After'));
        $this->assertStringContainsString('Muitas tentativas de acesso.', (string) $response->getBody());
        $response = $this->login('admin@deskhelper.local', 'admin123', '192.0.2.11');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertArrayNotHasKey('deskhelper_user_id', $_SESSION);
    }

    public function testSuccessfulLoginClearsAccountFailuresButNotTheIpFailures(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->login('admin@deskhelper.local', 'wrong');
        }
        $response = $this->login('ADMIN@DESKHELPER.LOCAL', 'admin123');
        $this->assertSame('/deskhelper/app', $response->getHeaderLine('Location'));
        $this->assertSame(1, $_SESSION['deskhelper_user_id']);
        $pdo = $this->database();
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM deskhelper_login_attempts WHERE bucket_key LIKE 'account:%'")->fetchColumn());
        $this->assertSame(4, (int) $pdo->query("SELECT failures FROM deskhelper_login_attempts WHERE bucket_key LIKE 'ip:%'")->fetchColumn());
        $response = $this->login('admin@deskhelper.local', 'wrong');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testExpiredLockAllowsLoginAndExpiredWindowStartsAgain(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('admin@deskhelper.local', 'wrong');
        }
        $pdo = $this->database();
        $pdo->exec('UPDATE deskhelper_login_attempts SET expires_at = 1, blocked_until = 1');
        $response = $this->login('admin@deskhelper.local', 'admin123');
        $this->assertSame(302, $response->getStatusCode());
        for ($i = 0; $i < 4; $i++) {
            $this->login('admin@deskhelper.local', 'wrong');
        }
        $pdo->exec('UPDATE deskhelper_login_attempts SET expires_at = 1, window_started_at = 1');
        $response = $this->login('admin@deskhelper.local', 'wrong');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, (int) $pdo->query("SELECT failures FROM deskhelper_login_attempts WHERE bucket_key LIKE 'account:%'")->fetchColumn());
    }

    public function testBlockedRequestsDoNotExtendTheLock(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('absent@example.test', 'wrong');
        }
        $pdo = $this->database();
        $before = $pdo->query('SELECT * FROM deskhelper_login_attempts ORDER BY bucket_key')->fetchAll();
        $this->login('absent@example.test', 'wrong');
        $this->assertSame($before, $pdo->query('SELECT * FROM deskhelper_login_attempts ORDER BY bucket_key')->fetchAll());
    }

    public function testIpLimitAlsoCoversDifferentUnknownAccountsAndIgnoresForwardedHeaders(): void
    {
        for ($i = 1; $i < 30; $i++) {
            $this->assertSame(200, $this->login('absent-' . $i . '@example.test', 'wrong')->getStatusCode());
        }
        $response = $this->login('absent-30@example.test', 'wrong');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(429, $this->login('admin@deskhelper.local', 'admin123')->getStatusCode());
        $this->assertSame(302, $this->login('admin@deskhelper.local', 'admin123', '192.0.2.11')->getStatusCode());
    }

    public function testInvalidCsrfDoesNotConsumeLoginAttempts(): void
    {
        $response = $this->login('admin@deskhelper.local', 'wrong', '192.0.2.10', false);
        $this->assertStringContainsString('Sessao expirada.', (string) $response->getBody());
        $this->assertSame(0, (int) $this->database()->query('SELECT COUNT(*) FROM deskhelper_login_attempts')->fetchColumn());
    }
}
