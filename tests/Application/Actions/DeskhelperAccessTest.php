<?php

declare(strict_types=1);

namespace Tests\Application\Actions;

use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use App\Infrastructure\Persistence\Deskhelper\DeskhelperTicketRepository;
use Tests\TestCase;

class DeskhelperAccessTest extends TestCase
{
    private function createUser(string $email, string $role = 'user'): array
    {
        $users = $this->getAppInstance()->getContainer()->get(DeskhelperUserRepository::class);
        $id = $users->createPendingUser([
            'name' => 'Pessoa de teste', 'email' => $email, 'password' => 'test-password', 'sector' => 'TI',
        ]);
        $users->approveUser($id, 1, [$role]);
        return $users->findById($id);
    }

    private function signIn(array $user): void
    {
        $_SESSION = [
            'deskhelper_user_id' => $user['id'],
            'deskhelper_user_roles' => $user['roles'],
            'csrf_token' => 'test-csrf',
        ];
    }

    public function testDisabledAccountLosesAccessOnNextRequest(): void
    {
        $user = $this->createUser('disabled@example.test');
        $this->signIn($user);
        $users = $this->getAppInstance()->getContainer()->get(DeskhelperUserRepository::class);
        $users->updateUserStatus($user['id'], 'disabled');

        $app = $this->getAppInstance();
        $response = $app->handle($this->createRequest('GET', '/deskhelper/tickets/new'));
        $this->assertSame('/deskhelper/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('deskhelper_user_id', $_SESSION);
        $response = $this->getAppInstance()->handle($this->createRequest('GET', '/deskhelper/login'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminDemotionBlocksDirectPostWithOldSession(): void
    {
        $admin = $this->createUser('demoted@example.test', 'admin');
        $this->signIn($admin);
        $users = $this->getAppInstance()->getContainer()->get(DeskhelperUserRepository::class);
        $users->replaceRoles($admin['id'], ['user']);

        $response = $this->getAppInstance()->handle(
            $this->createRequest('POST', '/deskhelper/admin/users/update')->withParsedBody([
                'csrf_token' => 'test-csrf', 'id' => 1, 'status' => 'disabled', 'role' => 'user',
            ])
        );
        $this->assertSame('/deskhelper/app', $response->getHeaderLine('Location'));
        $this->assertSame(['user'], $_SESSION['deskhelper_user_roles']);
        $this->assertSame('active', $users->findById(1)['status']);
        $this->assertContains('admin', $users->findById(1)['roles']);
    }

    public function testPromotionIsAppliedWithoutAnotherLogin(): void
    {
        $user = $this->createUser('promoted@example.test');
        $this->signIn($user);
        $users = $this->getAppInstance()->getContainer()->get(DeskhelperUserRepository::class);
        $users->replaceRoles($user['id'], ['admin']);
        $response = $this->getAppInstance()->handle($this->createRequest('GET', '/deskhelper/admin/users'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['admin'], $_SESSION['deskhelper_user_roles']);
    }

    public function testUserCannotReadOrCommentOnAnotherUsersTicket(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');
        $tickets = $this->getAppInstance()->getContainer()->get(DeskhelperTicketRepository::class);
        $id = $tickets->createTicket($owner['id'], [
            'title' => 'Chamado privado', 'description' => 'Conteúdo restrito',
            'category_id' => $tickets->getCategories()[0]['id'], 'request_sector' => 'TI',
        ]);
        $this->signIn($other);
        foreach (['GET' => '', 'POST' => '/comment'] as $method => $suffix) {
            $response = $this->getAppInstance()->handle(
                $this->createRequest($method, '/deskhelper/tickets/' . $id . $suffix)
                    ->withParsedBody(['csrf_token' => 'test-csrf', 'body' => 'Não autorizado'])
            );
            $this->assertSame('/deskhelper/tickets', $response->getHeaderLine('Location'));
            $this->assertStringNotContainsString('Conteúdo restrito', (string) $response->getBody());
        }
        $pdo = $this->getAppInstance()->getContainer()->get(\PDO::class);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM ticket_comments')->fetchColumn());
    }

    public function testAdminMutationRequiresCsrf(): void
    {
        $user = $this->createUser('csrf@example.test', 'admin');
        $this->signIn($user);
        $response = $this->getAppInstance()->handle(
            $this->createRequest('POST', '/deskhelper/admin/users/update')->withParsedBody([
                'csrf_token' => 'invalid', 'id' => $user['id'], 'status' => 'disabled',
            ])
        );
        $this->assertSame('/deskhelper/admin/users', $response->getHeaderLine('Location'));
        $users = $this->getAppInstance()->getContainer()->get(DeskhelperUserRepository::class);
        $this->assertSame('active', $users->findById($user['id'])['status']);
    }

    public function testResolutionDateIsPreservedUntilTicketIsReopened(): void
    {
        $app = $this->getAppInstance();
        $tickets = $app->getContainer()->get(DeskhelperTicketRepository::class);
        $pdo = $app->getContainer()->get(\PDO::class);
        $ticketId = $tickets->createTicket(1, [
            'title' => 'Controle de resolução',
            'description' => 'Validação da data e hora de resolução.',
            'category_id' => $tickets->getCategories()[0]['id'],
            'request_sector' => 'TI',
        ]);

        $tickets->updateTicketWorkflow($ticketId, 1, ['status' => 'resolved']);
        $originalResolution = '2000-01-02 03:04:05';
        $pdo->prepare('UPDATE tickets SET resolved_at = :resolved_at WHERE id = :id')->execute([
            'resolved_at' => $originalResolution,
            'id' => $ticketId,
        ]);

        $tickets->updateTicketWorkflow($ticketId, 1, ['status' => 'resolved']);
        $tickets->updateTicketWorkflow($ticketId, 1, ['status' => 'closed']);
        $ticket = $tickets->findTicketForUser($ticketId, 1, ['admin']);
        $this->assertSame($originalResolution, $ticket['resolved_at']);
        $this->assertNotEmpty($ticket['closed_at']);

        $tickets->updateTicketWorkflow($ticketId, 1, ['status' => 'open']);
        $ticket = $tickets->findTicketForUser($ticketId, 1, ['admin']);
        $this->assertNull($ticket['resolved_at']);

        $tickets->updateTicketWorkflow($ticketId, 1, ['status' => 'resolved']);
        $ticket = $tickets->findTicketForUser($ticketId, 1, ['admin']);
        $this->assertNotEmpty($ticket['resolved_at']);
        $this->assertNotSame($originalResolution, $ticket['resolved_at']);
    }
}
