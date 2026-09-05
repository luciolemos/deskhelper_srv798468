<?php

declare(strict_types=1);

namespace App\Application\Notification;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

final class ContactMailer
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function send(string $name, string $replyTo, string $subject, string $message): bool
    {
        $dsn = trim((string) ($_ENV['MAILER_DSN'] ?? ''));
        $from = trim((string) ($_ENV['MAIL_FROM'] ?? ''));
        $recipient = trim((string) ($_ENV['CONTACT_TO'] ?? ''));

        if ($dsn === '' || $from === '' || $recipient === '') {
            $this->logger->error('Contact email is not configured.');
            return false;
        }

        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReplyTo = htmlspecialchars($replyTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        try {
            $email = (new Email())
                ->from($from)
                ->to($recipient)
                ->replyTo(new Address($replyTo, $name))
                ->subject('[Deskhelper] ' . $subject)
                ->text(sprintf("Nome: %s\nE-mail: %s\n\n%s", $name, $replyTo, $message))
                ->html(sprintf(
                    '<h1>Nova mensagem pelo Deskhelper</h1><p><strong>Nome:</strong> %s</p><p><strong>E-mail:</strong> %s</p><p><strong>Mensagem:</strong></p><p>%s</p>',
                    $safeName,
                    $safeReplyTo,
                    $safeMessage
                ));

            (new Mailer(Transport::fromDsn($dsn)))->send($email);
            return true;
        } catch (Throwable $exception) {
            $this->logger->error('Contact email delivery failed.', ['exception' => $exception->getMessage()]);
            return false;
        }
    }
}
