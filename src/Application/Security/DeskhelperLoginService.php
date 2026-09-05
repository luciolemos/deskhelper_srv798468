<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Infrastructure\Persistence\Deskhelper\DeskhelperUserRepository;
use App\Infrastructure\Persistence\SqliteTransaction;
use PDO;

final class DeskhelperLoginService
{
    private const ACCOUNT_LIMIT = 5;
    private const IP_LIMIT = 30;
    private const WINDOW_SECONDS = 300;
    private const LOCK_SECONDS = 600;

    public function __construct(private PDO $pdo, private DeskhelperUserRepository $users)
    {
    }

    /** @return array{user: ?array, retry_after: int} */
    public function authenticate(string $email, string $password, string $clientAddress): array
    {
        $email = mb_strtolower(trim($email));
        $address = filter_var($clientAddress, FILTER_VALIDATE_IP) !== false
            ? (string) inet_ntop(inet_pton($clientAddress)) : 'unknown';
        $accountKey = 'account:' . hash('sha256', $email);
        $buckets = [$accountKey => self::ACCOUNT_LIMIT, 'ip:' . hash('sha256', $address) => self::IP_LIMIT];

        // Checking the password under the same lock prevents parallel requests bypassing the limit.
        return SqliteTransaction::write($this->pdo, function () use ($email, $password, $accountKey, $buckets): array {
            $now = time();
            $cleanup = $this->pdo->prepare('DELETE FROM deskhelper_login_attempts WHERE expires_at <= :now');
            $cleanup->execute(['now' => $now]);
            $read = $this->pdo->prepare('SELECT * FROM deskhelper_login_attempts WHERE bucket_key = :key');
            $states = [];
            $retryAfter = 0;
            foreach ($buckets as $key => $limit) {
                $read->execute(['key' => $key]);
                $row = $read->fetch(PDO::FETCH_ASSOC);
                $states[$key] = is_array($row) ? $row : [
                    'failures' => 0, 'window_started_at' => $now, 'blocked_until' => 0,
                ];
                $retryAfter = max($retryAfter, (int) $states[$key]['blocked_until'] - $now);
            }
            $read->closeCursor();
            if ($retryAfter > 0) {
                return ['user' => null, 'retry_after' => $retryAfter];
            }

            $user = $this->users->verifyCredentials($email, $password);
            if ($user !== null) {
                $clear = $this->pdo->prepare('DELETE FROM deskhelper_login_attempts WHERE bucket_key = :key');
                $clear->execute(['key' => $accountKey]);
                // A valid account must not reset failures against other accounts from this IP.
                return ['user' => $user, 'retry_after' => 0];
            }

            $write = $this->pdo->prepare('INSERT INTO deskhelper_login_attempts
                (bucket_key, failures, window_started_at, blocked_until, expires_at)
                VALUES (:key, :failures, :started, :blocked, :expires)
                ON CONFLICT(bucket_key) DO UPDATE SET failures = excluded.failures,
                    window_started_at = excluded.window_started_at, blocked_until = excluded.blocked_until,
                    expires_at = excluded.expires_at');
            foreach ($buckets as $key => $limit) {
                $failures = (int) $states[$key]['failures'] + 1;
                $started = (int) $states[$key]['window_started_at'];
                $blocked = $failures >= $limit ? $now + self::LOCK_SECONDS : 0;
                $write->execute([
                    'key' => $key, 'failures' => $failures, 'started' => $started,
                    'blocked' => $blocked, 'expires' => max($started + self::WINDOW_SECONDS, $blocked),
                ]);
                $retryAfter = max($retryAfter, $blocked - $now);
            }
            return ['user' => null, 'retry_after' => $retryAfter];
        });
    }
}
