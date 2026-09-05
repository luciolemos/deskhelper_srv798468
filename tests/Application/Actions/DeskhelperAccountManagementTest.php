<?php

declare(strict_types=1);

namespace Tests\Application\Actions;

use App\Infrastructure\Persistence\Deskhelper\DeskhelperSchemaManager;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use PDO;
use Tests\TestCase;

class DeskhelperAccountManagementTest extends TestCase
{
    private function users(): DeskhelperUserRepository
    {
        return $this->getAppInstance()->getContainer()->get(DeskhelperUserRepository::class);
    }

    private function createUser(string $role): int
    {
        $users = $this->users();
        $id = $users->createPendingUser(['name' => 'Teste', 'email' => uniqid() . '@example.test', 'password' => 'test-password']);
        $users->approveUser($id, 1, [$role]);
        return $id;
    }

    private function adminPost(string $path, array $data): \Psr\Http\Message\ResponseInterface
    {
        $_SESSION = ['deskhelper_user_id' => 1, 'deskhelper_user_roles' => ['admin'], 'csrf_token' => 'test-csrf'];
        return $this->getAppInstance()->handle(
            $this->createRequest('POST', '/deskhelper/admin/users/' . $path)
                ->withParsedBody(array_merge(['csrf_token' => 'test-csrf', 'id' => 1], $data))
        );
    }

    /** @dataProvider lastAdminChanges */
    public function testDirectPostsCannotRemoveLastActiveAdmin(string $path, array $data): void
    {
        $users = $this->users();
        $before = $users->findById(1);
        $response = $this->adminPost($path, $data);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('É necessário manter pelo menos um administrador ativo.', $_SESSION['deskhelper_flash']);
        $this->assertSame($before, $users->findById(1));
    }

    public function lastAdminChanges(): array
    {
        return [
            ['update', ['status' => 'disabled', 'role' => 'admin']],
            ['update', ['status' => 'pending', 'role' => 'admin']],
            ['update', ['status' => 'rejected', 'role' => 'admin']],
            ['update', ['status' => 'active', 'role' => 'user']],
            ['approve', ['role' => 'operator']],
            ['reject', []],
        ];
    }

    public function testInactiveAdminDoesNotAllowRemovingTheOnlyActiveOne(): void
    {
        $id = $this->createUser('admin');
        $users = $this->users();
        $users->updateUserStatus($id, 'disabled');
        $this->adminPost('update', ['status' => 'active', 'role' => 'operator']);
        $this->assertSame(['admin'], $users->findById(1)['roles']);
        $this->assertSame('É necessário manter pelo menos um administrador ativo.', $_SESSION['deskhelper_flash']);
    }

    public function testOtherActiveAdminAllowsStatusAndRoleChange(): void
    {
        $this->createUser('admin');
        $users = $this->users();
        $this->adminPost('update', ['status' => 'disabled', 'role' => 'operator']);
        $user = $users->findById(1);
        $this->assertSame('disabled', $user['status']);
        $this->assertSame(['operator'], $user['roles']);
        $response = $this->getAppInstance()->handle($this->createRequest('GET', '/deskhelper/admin/users'));
        $this->assertSame('/deskhelper/login', $response->getHeaderLine('Location'));
    }

    public function testInvalidRoleDoesNotPartiallyChangeStatus(): void
    {
        $id = $this->createUser('user');
        $users = $this->users();
        $before = $users->findById($id);
        $this->adminPost('update', ['id' => $id, 'status' => 'disabled', 'role' => 'invalid']);
        $this->assertSame($before, $users->findById($id));
        $this->assertSame('Perfis inválidos.', $_SESSION['deskhelper_flash']);
    }

    public function testDatabaseFailureRollsBackBothStatusAndRoles(): void
    {
        $id = $this->createUser('user');
        $app = $this->getAppInstance();
        $pdo = $app->getContainer()->get(PDO::class);
        $users = $app->getContainer()->get(DeskhelperUserRepository::class);
        $before = $users->findById($id);
        $pdo->exec("CREATE TRIGGER fail_role_insert BEFORE INSERT ON user_roles BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        try {
            $users->updateAccess($id, 'disabled', ['operator']);
            $this->fail('The injected database failure must reject the update.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('fixture failure', $error->getMessage());
        }
        $this->assertSame($before, $users->findById($id));
        $pdo->exec('DROP TRIGGER fail_role_insert');
        $users->updateAccess($id, 'disabled', ['operator']);
        $this->assertSame('disabled', $users->findById($id)['status']);
        $this->assertSame(['operator'], $users->findById($id)['roles']);
    }

    public function testBootstrapDoesNotRecreateAnAdminInAnExistingUserBase(): void
    {
        $app = $this->getAppInstance();
        $pdo = $app->getContainer()->get(PDO::class);
        $pdo->exec('DELETE FROM user_roles');
        $before = $pdo->query('SELECT * FROM users')->fetchAll();
        $schema = new DeskhelperSchemaManager($_ENV['APP_DB_PATH'], dirname(__DIR__, 3) . '/docs/deskhelper-schema.sql');
        $schema->bootstrap();
        $this->assertSame($before, $pdo->query('SELECT * FROM users')->fetchAll());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM user_roles')->fetchColumn());
    }

    public function testConcurrentDemotionsLeaveOneActiveAdmin(): void
    {
        $otherId = $this->createUser('admin');
        $processes = [];
        foreach ([1, $otherId] as $id) {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/demote-admin.php', $_ENV['APP_DB_PATH'], (string) $id],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
            );
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }
        $outcomes = [];
        foreach ($processes as [$process, $pipes]) {
            $outcomes[] = trim(stream_get_contents($pipes[1]));
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stderr);
        }
        sort($outcomes);
        $this->assertSame(['protected', 'updated'], $outcomes);
        $pdo = $this->getAppInstance()->getContainer()->get(PDO::class);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id
            WHERE u.status = "active" AND r.slug = "admin"')->fetchColumn());
    }
}
