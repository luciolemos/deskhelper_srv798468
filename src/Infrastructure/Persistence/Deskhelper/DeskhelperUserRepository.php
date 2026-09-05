<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Deskhelper;

use App\Infrastructure\Persistence\SqliteTransaction;
use PDO;

class DeskhelperUserRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function countAll(): int
    {
        return (int) ($this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() ?: 0);
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE status = :status');
        $stmt->execute(['status' => 'pending']);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email COLLATE NOCASE LIMIT 1');
        $stmt->execute(['email' => trim($email)]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['roles'] = $this->getUserRoles((int) $row['id']);

        return $row;
    }

    public function createPendingUser(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            throw new \RuntimeException('Dados obrigatorios nao informados.');
        }

        if ($this->findByEmail($email) !== null) {
            throw new \RuntimeException('Ja existe um usuario com este e-mail.');
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, rank, sector, phone, status, created_at, updated_at)
            VALUES (:name, :email, :password_hash, :rank, :sector, :phone, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'rank' => trim((string) ($data['rank'] ?? '')),
            'sector' => trim((string) ($data['sector'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $userId = (int) $this->pdo->lastInsertId();
        $this->logEvent(null, $userId, 'user_registered', [
            'email' => $email,
        ]);

        return $userId;
    }

    public function verifyCredentials(string $email, string $password): ?array
    {
        $user = $this->findByEmail($email);
        if ($user === null) {
            return null;
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }

        $user['roles'] = $this->getUserRoles((int) ($user['id'] ?? 0));

        return $user;
    }

    public function getPendingUsers(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, email, rank, sector, phone, status, created_at
            FROM users
            WHERE status = "pending"
            ORDER BY created_at ASC, id ASC');

        return $stmt->fetchAll() ?: [];
    }

    public function getAllUsers(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, email, rank, sector, phone, avatar_path, status, approved_at, created_at, updated_at
            FROM users
            ORDER BY created_at DESC, id DESC');

        $users = $stmt->fetchAll() ?: [];
        foreach ($users as &$user) {
            $user['roles'] = $this->getUserRoles((int) ($user['id'] ?? 0));
        }

        return $users;
    }

    public function approveUser(int $userId, int $approvedByUserId, array $roleSlugs): void
    {
        $roles = $this->normalizeRoleSlugs($roleSlugs === [] ? ['user'] : $roleSlugs);
        SqliteTransaction::write($this->pdo, function () use ($userId, $approvedByUserId, $roles): void {
            $this->changeAccess($userId, 'active', $roles);
            $stmt = $this->pdo->prepare(
                'UPDATE users
                SET approved_at = :approved_at, approved_by_user_id = :approved_by_user_id
                WHERE id = :id'
            );
            $stmt->execute([
                'approved_at' => date('Y-m-d H:i:s'),
                'approved_by_user_id' => $approvedByUserId > 0 ? $approvedByUserId : null,
                'id' => $userId,
            ]);
            $this->logEvent(null, $approvedByUserId, 'user_approved', [
                'approved_user_id' => $userId,
                'roles' => $roles,
            ]);
        });
    }

    public function rejectUser(int $userId, int $rejectedByUserId, string $reason = ''): void
    {
        SqliteTransaction::write($this->pdo, function () use ($userId, $rejectedByUserId, $reason): void {
            $this->changeAccess($userId, 'rejected', []);
            $this->logEvent(null, $rejectedByUserId, 'user_rejected', [
                'rejected_user_id' => $userId,
                'reason' => trim($reason),
            ]);
        });
    }

    public function getUserRoles(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT r.slug
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
            ORDER BY r.slug ASC');
        $stmt->execute(['user_id' => $userId]);

        return array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
        ));
    }

    public function countByRole(string $roleSlug): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*)
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE r.slug = :slug');
        $stmt->execute(['slug' => trim($roleSlug)]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function countByStatus(string $status): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE status = :status');
        $stmt->execute(['status' => trim($status)]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function getRoleSummary(): array
    {
        $rows = $this->pdo->query('SELECT r.slug, COUNT(ur.user_id) AS total
            FROM roles r
            LEFT JOIN user_roles ur ON ur.role_id = r.id
            GROUP BY r.id, r.slug
            ORDER BY r.slug ASC')->fetchAll() ?: [];

        $summary = [
            'admin' => 0,
            'operator' => 0,
            'user' => 0,
        ];

        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if (array_key_exists($slug, $summary)) {
                $summary[$slug] = (int) ($row['total'] ?? 0);
            }
        }

        return $summary;
    }

    public function updateUserStatus(int $userId, string $status): void
    {
        SqliteTransaction::write($this->pdo, fn () => $this->changeAccess($userId, $status, null));
    }

    public function replaceRoles(int $userId, array $roleSlugs): void
    {
        $roles = $this->normalizeRoleSlugs($roleSlugs);
        if ($userId <= 0 || $roles === []) {
            throw new \RuntimeException('Perfis inválidos.');
        }

        SqliteTransaction::write($this->pdo, fn () => $this->changeAccess($userId, null, $roles));
    }

    public function updateAccess(int $userId, string $status, array $roleSlugs): void
    {
        $roles = $this->normalizeRoleSlugs($roleSlugs);
        if ($roles === []) {
            throw new \RuntimeException('Perfis inválidos.');
        }
        SqliteTransaction::write($this->pdo, fn () => $this->changeAccess($userId, $status, $roles));
    }

    /** Called only inside a write transaction, including by approval and rejection. */
    private function changeAccess(int $userId, ?string $status, ?array $roles): void
    {
        $user = $this->findById($userId);
        if ($user === null) {
            throw new \RuntimeException('Usuário não encontrado.');
        }
        $status = strtolower(trim($status ?? (string) $user['status']));
        $roles = $roles ?? (array) $user['roles'];
        if (!in_array($status, ['active', 'disabled', 'rejected', 'pending'], true)) {
            throw new \RuntimeException('Status de usuário inválido.');
        }

        if ($user['status'] === 'active' && in_array('admin', $user['roles'], true)
            && ($status !== 'active' || !in_array('admin', $roles, true))) {
            $others = $this->pdo->prepare('SELECT COUNT(*) FROM users u
                WHERE u.status = "active" AND u.id <> :id AND EXISTS (
                    SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = u.id AND r.slug = "admin"
                )');
            $others->execute(['id' => $userId]);
            if ((int) $others->fetchColumn() === 0) {
                throw new \RuntimeException('É necessário manter pelo menos um administrador ativo.');
            }
        }

        $stmt = $this->pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id');
        $stmt->execute(['status' => $status, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $userId]);
        $this->replaceUserRoles($userId, $roles);
    }

    public function updatePhone(int $userId, string $phone): void
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Usuário inválido.');
        }

        $normalizedPhone = trim($phone);
        if ($normalizedPhone === '') {
            throw new \RuntimeException('Informe o telefone.');
        }

        if (!preg_match('/^[\d\s()+-]{10,40}$/', $normalizedPhone)) {
            throw new \RuntimeException('Informe um telefone válido.');
        }

        $digitsOnly = preg_replace('/\D+/', '', $normalizedPhone) ?? '';
        $digitsLength = strlen($digitsOnly);
        if ($digitsLength < 10 || $digitsLength > 15) {
            throw new \RuntimeException('Informe um telefone válido.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users
            SET phone = :phone, updated_at = :updated_at
            WHERE id = :id'
        );
        $stmt->execute([
            'phone' => $normalizedPhone,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $userId,
        ]);
    }

    public function updateEmail(int $userId, string $email): string
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Usuário inválido.');
        }

        $normalizedEmail = mb_strtolower(trim($email));
        if ($normalizedEmail === '' || mb_strlen($normalizedEmail) > 160 || filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Informe um e-mail válido.');
        }

        $existing = $this->findByEmail($normalizedEmail);
        if ($existing !== null && (int) ($existing['id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Já existe um usuário com este e-mail.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users
            SET email = :email, updated_at = :updated_at
            WHERE id = :id'
        );
        $stmt->execute([
            'email' => $normalizedEmail,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $userId,
        ]);

        return $normalizedEmail;
    }

    public function updatePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Usuário inválido.');
        }

        $user = $this->findById($userId);
        if ($user === null) {
            throw new \RuntimeException('Usuário não encontrado.');
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            throw new \RuntimeException('A senha atual está incorreta.');
        }

        $normalizedPassword = (string) $newPassword;
        if (strlen($normalizedPassword) < 8) {
            throw new \RuntimeException('A nova senha deve ter pelo menos 8 caracteres.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users
            SET password_hash = :password_hash, updated_at = :updated_at
            WHERE id = :id'
        );
        $stmt->execute([
            'password_hash' => password_hash($normalizedPassword, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $userId,
        ]);
    }

    public function updateAvatarPath(int $userId, string $avatarPath): void
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Usuário inválido.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users
            SET avatar_path = :avatar_path, updated_at = :updated_at
            WHERE id = :id'
        );
        $stmt->execute([
            'avatar_path' => trim($avatarPath),
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $userId,
        ]);
    }

    private function replaceUserRoles(int $userId, array $roleSlugs): void
    {
        $this->clearUserRoles($userId);

        $insert = $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
        foreach ($roleSlugs as $slug) {
            $roleId = $this->findRoleId($slug);
            if ($roleId <= 0) {
                throw new \RuntimeException('Perfil não encontrado.');
            }

            $insert->execute([
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);
        }
    }

    private function clearUserRoles(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    private function findRoleId(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => trim($slug)]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function normalizeRoleSlugs(array $roleSlugs): array
    {
        $allowed = ['user', 'operator', 'admin'];
        $normalized = [];

        foreach ($roleSlugs as $slug) {
            $value = strtolower(trim((string) $slug));
            if ($value === '' || !in_array($value, $allowed, true)) {
                throw new \RuntimeException('Perfis inválidos.');
            }

            if (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    private function logEvent(?int $ticketId, int $userId, string $eventType, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_events (ticket_id, user_id, event_type, event_data_json, created_at)
            VALUES (:ticket_id, :user_id, :event_type, :event_data_json, :created_at)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'user_id' => $userId > 0 ? $userId : null,
            'event_type' => $eventType,
            'event_data_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
