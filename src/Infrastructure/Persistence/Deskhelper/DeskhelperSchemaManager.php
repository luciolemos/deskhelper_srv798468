<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Deskhelper;

use App\Infrastructure\Persistence\SqliteTransaction;
use PDO;

class DeskhelperSchemaManager
{
    public function __construct(
        private string $dbPath,
        private string $schemaPath
    ) {
    }

    public function bootstrap(): void
    {
        $directory = dirname($this->dbPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $pdo = $this->createConnection();
        $pdo->exec('PRAGMA foreign_keys = ON');

        if (is_file($this->schemaPath)) {
            $sql = trim((string) file_get_contents($this->schemaPath));
            if ($sql !== '') {
                $this->applySchema($pdo, $sql);
            }
        }

        $this->ensureAvatarColumn($pdo);
        $this->ensureRequestSectorColumn($pdo);

        $this->seedRoles($pdo);
        $this->seedCategories($pdo);
        $this->seedDefaultAdmin($pdo);
    }

    private function createConnection(): PDO
    {
        return new PDO('sqlite:' . $this->dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function applySchema(PDO $pdo, string $sql): void
    {
        $statements = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];

        foreach ($statements as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '') {
                continue;
            }

            if ($trimmed === 'BEGIN TRANSACTION' || $trimmed === 'COMMIT') {
                continue;
            }

            $pdo->exec($trimmed);
        }
    }

    private function seedRoles(PDO $pdo): void
    {
        $roles = [
            ['slug' => 'user', 'label' => 'Usuario'],
            ['slug' => 'operator', 'label' => 'Operador'],
            ['slug' => 'admin', 'label' => 'Administrador'],
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO roles (slug, label) VALUES (:slug, :label)
            ON CONFLICT(slug) DO UPDATE SET label = excluded.label'
        );

        foreach ($roles as $role) {
            $stmt->execute($role);
        }
    }

    private function seedCategories(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $categories = [
            ['slug' => 'acesso', 'label' => 'Acesso', 'sort_order' => 10],
            ['slug' => 'impressao', 'label' => 'Impressão', 'sort_order' => 15],
            ['slug' => 'email', 'label' => 'E-mail', 'sort_order' => 18],
            ['slug' => 'sistemas', 'label' => 'Sistemas', 'sort_order' => 20],
            ['slug' => 'rede', 'label' => 'Rede', 'sort_order' => 30],
            ['slug' => 'seguranca', 'label' => 'Segurança', 'sort_order' => 35],
            ['slug' => 'hardware', 'label' => 'Hardware', 'sort_order' => 40],
            ['slug' => 'solicitacoes', 'label' => 'Solicitações', 'sort_order' => 45],
            ['slug' => 'esqueci-a-senha', 'label' => 'Esqueci a senha', 'sort_order' => 48],
            ['slug' => 'outros', 'label' => 'Outros', 'sort_order' => 50],
            ['slug' => 'conta-bloqueada', 'label' => 'Conta bloqueada', 'sort_order' => 55],
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO ticket_categories (slug, label, active, sort_order, created_at, updated_at)
            VALUES (:slug, :label, 1, :sort_order, :created_at, :updated_at)
            ON CONFLICT(slug) DO NOTHING'
        );

        foreach ($categories as $category) {
            $stmt->execute([
                'slug' => $category['slug'],
                'label' => $category['label'],
                'sort_order' => $category['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedDefaultAdmin(PDO $pdo): void
    {
        SqliteTransaction::write($pdo, function () use ($pdo): void {
            $adminRoleId = $this->findRoleId($pdo, 'admin');
            if ($adminRoleId <= 0) {
                return;
            }

            // Initial installation only: never recreate privileges in an existing user base.
            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                return;
            }

            $username = trim((string) ($_ENV['ADMIN_USER'] ?? 'admin'));
            $email = trim((string) ($_ENV['DESKHELPER_ADMIN_EMAIL'] ?? 'admin@deskhelper.local'));
            $password = (string) ($_ENV['ADMIN_PASS'] ?? 'admin123');
            $name = trim((string) ($_ENV['DESKHELPER_ADMIN_NAME'] ?? $username));
            $now = date('Y-m-d H:i:s');

            $insertUser = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, rank, sector, phone, avatar_path, status, approved_at, created_at, updated_at)
                VALUES (:name, :email, :password_hash, "", "Administracao", "", "", "active", :approved_at, :created_at, :updated_at)'
            );
            $insertUser->execute([
                'name' => $name !== '' ? $name : 'admin',
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'approved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $userId = (int) $pdo->lastInsertId();
            if ($userId <= 0) {
                return;
            }

            $assignRole = $pdo->prepare(
                'INSERT OR IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)'
            );
            $assignRole->execute([
                'user_id' => $userId,
                'role_id' => $adminRoleId,
            ]);

            $insertEvent = $pdo->prepare(
                'INSERT INTO ticket_events (ticket_id, user_id, event_type, event_data_json, created_at)
                VALUES (NULL, :user_id, :event_type, :event_data_json, :created_at)'
            );
            $insertEvent->execute([
                'user_id' => $userId,
                'event_type' => 'deskhelper_admin_seeded',
                'event_data_json' => json_encode(['email' => $email], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'created_at' => $now,
            ]);
        });
    }

    private function ensureAvatarColumn(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll() ?: [];
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === 'avatar_path') {
                return;
            }
        }

        $pdo->exec('ALTER TABLE users ADD COLUMN avatar_path TEXT NOT NULL DEFAULT ""');
    }

    private function ensureRequestSectorColumn(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(tickets)')->fetchAll() ?: [];
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === 'request_sector') {
                return;
            }
        }

        $pdo->exec('ALTER TABLE tickets ADD COLUMN request_sector TEXT NOT NULL DEFAULT ""');
    }

    private function findRoleId(PDO $pdo, string $slug): int
    {
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
