<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Infrastructure\Persistence\SqliteTransaction;
use PDO;

final class PasswordResetService
{
    private const TOKEN_TTL = 1800;
    private const WINDOW_TTL = 3600;

    public function __construct(private PDO $pdo)
    {
    }

    public function createToken(string $email, string $clientAddress): ?array
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $now = time();

        return SqliteTransaction::write($this->pdo, function () use ($normalizedEmail, $clientAddress, $now): ?array {
            $this->pdo->prepare('DELETE FROM password_reset_attempts WHERE expires_at <= :now')->execute(['now' => $now]);
            $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE expires_at <= :now OR used_at IS NOT NULL')->execute(['now' => $now]);

            $emailAllowed = $this->consumeLimit('email:' . hash('sha256', $normalizedEmail), 3, $now);
            $ipAllowed = $this->consumeLimit('ip:' . hash('sha256', trim($clientAddress)), 10, $now);
            if (!$emailAllowed || !$ipAllowed || filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }

            $stmt = $this->pdo->prepare('SELECT id, name, email, rank FROM users WHERE email = :email COLLATE NOCASE AND status = "active" LIMIT 1');
            $stmt->execute(['email' => $normalizedEmail]);
            $user = $stmt->fetch();
            if (!is_array($user)) {
                return null;
            }

            $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = :now WHERE user_id = :user_id AND used_at IS NULL')
                ->execute(['now' => $now, 'user_id' => (int) $user['id']]);

            $token = bin2hex(random_bytes(32));
            $this->pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, used_at, created_at)
                VALUES (:user_id, :token_hash, :expires_at, NULL, :created_at)')->execute([
                    'user_id' => (int) $user['id'],
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => $now + self::TOKEN_TTL,
                    'created_at' => $now,
                ]);

            return [
                'token' => $token,
                'name' => (string) $user['name'],
                'rank' => (string) $user['rank'],
                'email' => (string) $user['email'],
            ];
        });
    }

    public function tokenIsValid(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM password_reset_tokens WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > :now LIMIT 1');
        $stmt->execute(['token_hash' => hash('sha256', $token), 'now' => time()]);
        return (bool) $stmt->fetchColumn();
    }

    public function invalidateToken(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return;
        }
        $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE token_hash = :token_hash AND used_at IS NULL')
            ->execute(['used_at' => time(), 'token_hash' => hash('sha256', $token)]);
    }

    public function resetPassword(string $token, string $password): bool
    {
        if (mb_strlen($password) < 8 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        return SqliteTransaction::write($this->pdo, function () use ($token, $password): bool {
            $now = time();
            $stmt = $this->pdo->prepare('SELECT id, user_id FROM password_reset_tokens
                WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > :now LIMIT 1');
            $stmt->execute(['token_hash' => hash('sha256', $token), 'now' => $now]);
            $reset = $stmt->fetch();
            if (!is_array($reset)) {
                return false;
            }

            $this->pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id')->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => (int) $reset['user_id'],
            ]);
            $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL')
                ->execute(['used_at' => $now, 'user_id' => (int) $reset['user_id']]);
            $this->pdo->prepare('INSERT INTO ticket_events (ticket_id, user_id, event_type, event_data_json, created_at)
                VALUES (NULL, :user_id, "password_reset", "{}", :created_at)')->execute([
                    'user_id' => (int) $reset['user_id'],
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            $this->pdo->prepare('DELETE FROM deskhelper_login_attempts WHERE bucket_key = :bucket_key')->execute([
                'bucket_key' => 'account:' . hash('sha256', mb_strtolower(trim((string) $this->userEmail((int) $reset['user_id'])))),
            ]);
            return true;
        });
    }

    private function consumeLimit(string $bucketKey, int $limit, int $now): bool
    {
        $stmt = $this->pdo->prepare('SELECT requests, window_started_at FROM password_reset_attempts WHERE bucket_key = :bucket_key');
        $stmt->execute(['bucket_key' => $bucketKey]);
        $bucket = $stmt->fetch();
        if (!is_array($bucket) || (int) $bucket['window_started_at'] + self::WINDOW_TTL <= $now) {
            $this->pdo->prepare('INSERT INTO password_reset_attempts (bucket_key, requests, window_started_at, expires_at)
                VALUES (:bucket_key, 1, :now, :expires_at)
                ON CONFLICT(bucket_key) DO UPDATE SET requests = 1, window_started_at = excluded.window_started_at, expires_at = excluded.expires_at')
                ->execute(['bucket_key' => $bucketKey, 'now' => $now, 'expires_at' => $now + self::WINDOW_TTL]);
            return true;
        }
        if ((int) $bucket['requests'] >= $limit) {
            return false;
        }
        $this->pdo->prepare('UPDATE password_reset_attempts SET requests = requests + 1 WHERE bucket_key = :bucket_key')
            ->execute(['bucket_key' => $bucketKey]);
        return true;
    }

    private function userEmail(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT email FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
