<?php

declare(strict_types=1);

namespace App\Application\Security;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final class PasswordResetMailer
{
    public function send(string $recipient, string $name, string $rank, string $token): void
    {
        $dsn = trim((string) ($_ENV['MAILER_DSN'] ?? ''));
        $from = trim((string) ($_ENV['MAIL_FROM'] ?? ''));
        $appUrl = rtrim(trim((string) ($_ENV['APP_URL'] ?? '')), '/');
        if ($dsn === '' || $from === '' || $appUrl === '') {
            throw new \RuntimeException('Envio de e-mail não configurado.');
        }

        $resetUrl = $appUrl . '/reset-password?token=' . rawurlencode($token);
        $identity = trim($this->shortRank($rank) . ' ' . trim($name));
        $identity = $identity !== '' ? $identity : 'usuário';
        $safeIdentity = htmlspecialchars($identity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLogo = htmlspecialchars($appUrl . '/assets/img/om.png', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $requestedAt = date('d/m/Y \à\s H:i');

        $text = "16º BI Mtz\nSEÇÃO DE INFORMÁTICA\nSERVICE DESK INTERNO\n\n"
            . "Olá, {$identity}.\n\n"
            . "Recebemos uma solicitação para redefinir sua senha de acesso ao Deskhelper.\n\n"
            . "REDEFINIR SENHA:\n{$resetUrl}\n\n"
            . "Solicitação registrada em {$requestedAt}.\n"
            . "Este link expira em 30 minutos e pode ser usado uma única vez.\n\n"
            . "Se você não solicitou esta alteração, ignore esta mensagem. Sua senha atual permanecerá válida.\n\n"
            . "Deskhelper\n16º BI Mtz";

        $html = <<<HTML
<!doctype html>
<html lang="pt-BR">
<body style="margin:0;padding:0;background:#f3f6ef;font-family:Arial,sans-serif;color:#26351f">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6ef;padding:28px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border:1px solid #dde4d7;border-radius:18px;overflow:hidden">
<tr><td style="padding:24px 28px;background:#344827;color:#fff"><table role="presentation" cellspacing="0" cellpadding="0"><tr>
<td style="padding-right:16px;vertical-align:middle"><img src="{$safeLogo}" width="50" height="69" alt="Escudo do 16º BI Mtz" style="display:block;width:50px;height:auto;border:0;border-radius:0"></td>
<td style="vertical-align:middle"><strong style="display:block;font-size:18px;line-height:1.2">16º BI Mtz</strong><span style="display:block;margin-top:3px;font-size:12px;line-height:1.3;letter-spacing:.06em;text-transform:uppercase;color:#eef4e9">Seção de Informática</span><span style="display:block;margin-top:2px;font-size:11px;line-height:1.3;letter-spacing:.08em;text-transform:uppercase;color:#cbd8c4">Service Desk Interno</span></td>
</tr></table></td></tr>
<tr><td style="padding:30px 28px 12px">
<h1 style="margin:0 0 18px;font-size:24px;line-height:1.2;color:#26351f">Redefinição de senha</h1>
<p style="margin:0 0 14px;font-size:16px;line-height:1.55">Olá, <strong>{$safeIdentity}</strong>.</p>
<p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#52604d">Recebemos uma solicitação para redefinir sua senha de acesso ao Deskhelper.</p>
<p style="margin:0 0 24px"><a href="{$safeUrl}" style="display:inline-block;padding:13px 20px;border-radius:10px;background:#a56f11;color:#fff;text-decoration:none;font-weight:bold">Redefinir minha senha</a></p>
<div style="padding:14px 16px;border-radius:12px;background:#f6f3e9;color:#5f583f;font-size:13px;line-height:1.55">Solicitação registrada em <strong>{$requestedAt}</strong>.<br>O link expira em <strong>30 minutos</strong> e pode ser usado uma única vez.</div>
<p style="margin:22px 0 0;font-size:13px;line-height:1.55;color:#6b7567">Se você não solicitou esta alteração, ignore esta mensagem. Sua senha atual permanecerá válida.</p>
</td></tr>
<tr><td style="padding:18px 28px 24px;font-size:12px;line-height:1.5;color:#788274;border-top:1px solid #edf0e9">Mensagem automática do Deskhelper · 16º Batalhão de Infantaria Motorizado</td></tr>
</table></td></tr></table>
</body></html>
HTML;

        $email = (new Email())
            ->from($from)
            ->to($recipient)
            ->subject('Redefinição de senha | Deskhelper 16º BI Mtz')
            ->text($text)
            ->html($html);

        (new Mailer(Transport::fromDsn($dsn)))->send($email);
    }

    private function shortRank(string $rank): string
    {
        return [
            'General de Exército' => 'Gen Ex', 'General de Divisão' => 'Gen Div',
            'General de Brigada' => 'Gen Bda', 'Coronel' => 'Cel',
            'Tenente-Coronel' => 'Ten Cel', 'Major' => 'Maj', 'Capitão' => 'Cap',
            '1º Tenente' => '1º Ten', '2º Tenente' => '2º Ten',
            'Aspirante a Oficial' => 'Asp Of', 'Subtenente' => 'ST',
            '1º Sargento' => '1º Sgt', '2º Sargento' => '2º Sgt',
            '3º Sargento' => '3º Sgt', 'Cabo' => 'Cb', 'Soldado' => 'Sd',
        ][$rank] ?? trim($rank);
    }
}
