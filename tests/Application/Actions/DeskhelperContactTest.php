<?php

declare(strict_types=1);

namespace Tests\Application\Actions;

use Tests\TestCase;

final class DeskhelperContactTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['MAILER_DSN'] = 'null://null';
        $_ENV['MAIL_FROM'] = 'Deskhelper <deskhelper@example.test>';
        $_ENV['CONTACT_TO'] = 'support@example.test';
    }

    public function testContactPageProvidesCsrfProtectedForm(): void
    {
        $response = $this->getAppInstance()->handle($this->createRequest('GET', '/deskhelper/contato'));
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('name="csrf_token"', $body);
        $this->assertStringContainsString('Fale com a equipe responsável pelo Deskhelper', $body);
    }

    public function testValidContactMessageIsAccepted(): void
    {
        $_SESSION = ['csrf_token' => 'contact-csrf'];
        $response = $this->getAppInstance()->handle(
            $this->createRequest('POST', '/deskhelper/contato')->withParsedBody([
                'csrf_token' => 'contact-csrf', 'name' => 'Usuário de teste',
                'email' => 'user@example.test', 'subject' => 'Ajuda com um ticket',
                'message' => 'Preciso de orientação sobre o atendimento.', 'website' => '',
            ])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Mensagem enviada com sucesso.', (string) $response->getBody());
    }

    public function testExpiredFormSessionDoesNotSendMessage(): void
    {
        $_SESSION = ['csrf_token' => 'expected-token'];
        $response = $this->getAppInstance()->handle(
            $this->createRequest('POST', '/deskhelper/contato')->withParsedBody([
                'csrf_token' => 'wrong-token', 'name' => 'Usuário de teste',
                'email' => 'user@example.test', 'subject' => 'Ajuda',
                'message' => 'Mensagem válida.', 'website' => '',
            ])
        );

        $this->assertStringContainsString('A sessão do formulário expirou.', (string) $response->getBody());
        $this->assertStringNotContainsString('Mensagem enviada com sucesso.', (string) $response->getBody());
    }
}
