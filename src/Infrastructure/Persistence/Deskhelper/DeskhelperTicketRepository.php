<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Deskhelper;

use PDO;

class DeskhelperTicketRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function countAll(): int
    {
        return (int) ($this->pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() ?: 0);
    }

    public function countByStatus(string $status): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tickets WHERE status = :status');
        $stmt->execute(['status' => trim($status)]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function countAssignedTo(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tickets WHERE assigned_to_user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function countCreatedBy(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tickets WHERE created_by_user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function getCategories(): array
    {
        $stmt = $this->pdo->query('SELECT id, slug, label, active, sort_order
            FROM ticket_categories
            WHERE active = 1
            ORDER BY sort_order ASC, label ASC');

        return $stmt->fetchAll() ?: [];
    }

    public function getAllCategories(): array
    {
        $stmt = $this->pdo->query('SELECT id, slug, label, active, sort_order, created_at, updated_at
            FROM ticket_categories
            ORDER BY sort_order ASC, label ASC');

        return $stmt->fetchAll() ?: [];
    }

    public function createTicket(int $createdByUserId, array $data): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $requestSector = trim((string) ($data['request_sector'] ?? ''));
        $priority = strtolower(trim((string) ($data['priority'] ?? 'medium')));
        $categoryId = (int) ($data['category_id'] ?? 0);

        if ($createdByUserId <= 0 || $title === '' || $description === '' || $requestSector === '' || $categoryId <= 0) {
            throw new \RuntimeException('Dados obrigatorios do ticket nao informados.');
        }

        if (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $priority = 'medium';
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO tickets (created_by_user_id, assigned_to_user_id, category_id, request_sector, title, description, priority, status, created_at, updated_at)
            VALUES (:created_by_user_id, NULL, :category_id, :request_sector, :title, :description, :priority, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            'created_by_user_id' => $createdByUserId,
            'category_id' => $categoryId,
            'request_sector' => $requestSector,
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $ticketId = (int) $this->pdo->lastInsertId();
        $this->logEvent($ticketId, $createdByUserId, 'ticket_created', [
            'priority' => $priority,
            'category_id' => $categoryId,
        ]);

        return $ticketId;
    }

    public function getTicketsForUser(int $viewerUserId, array $viewerRoles = [], array $filters = []): array
    {
        $roles = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), $viewerRoles);

        $sql = 'SELECT t.id, t.title, t.description, t.priority, t.status, t.created_at, t.updated_at, t.resolved_at, t.request_sector,
                t.created_by_user_id, t.assigned_to_user_id,
                c.label AS category_label,
                cu.rank AS created_by_rank,
                cu.name AS created_by_name,
                cu.sector AS created_by_sector,
                au.rank AS assigned_to_rank,
                au.name AS assigned_to_name
            FROM tickets t
            INNER JOIN ticket_categories c ON c.id = t.category_id
            INNER JOIN users cu ON cu.id = t.created_by_user_id
            LEFT JOIN users au ON au.id = t.assigned_to_user_id';

        $params = [];
        $where = [];
        if (in_array('admin', $roles, true)) {
            $where = [];
        } elseif (in_array('operator', $roles, true)) {
            $where[] = '(t.assigned_to_user_id = :viewer_user_id OR t.created_by_user_id = :viewer_user_id)';
            $params['viewer_user_id'] = $viewerUserId;
        } else {
            $where[] = 't.created_by_user_id = :viewer_user_id';
            $params['viewer_user_id'] = $viewerUserId;
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(t.title LIKE :q OR t.description LIKE :q OR CAST(t.id AS TEXT) LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '') {
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }

        $priority = strtolower(trim((string) ($filters['priority'] ?? '')));
        if ($priority !== '') {
            $where[] = 't.priority = :priority';
            $params['priority'] = $priority;
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $where[] = 't.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $requestSector = trim((string) ($filters['request_sector'] ?? ''));
        if ($requestSector !== '') {
            $where[] = 't.request_sector = :request_sector';
            $params['request_sector'] = $requestSector;
        }

        $assigned = strtolower(trim((string) ($filters['assigned'] ?? '')));
        if ($assigned === 'unassigned') {
            $where[] = 't.assigned_to_user_id IS NULL';
        } elseif ($assigned === 'mine') {
            $where[] = 't.assigned_to_user_id = :assigned_user_id';
            $params['assigned_user_id'] = $viewerUserId;
        }

        $scope = strtolower(trim((string) ($filters['scope'] ?? '')));
        if ($scope === 'created') {
            $where[] = 't.created_by_user_id = :scope_created_user_id';
            $params['scope_created_user_id'] = $viewerUserId;
        } elseif ($scope === 'assigned') {
            $where[] = 't.assigned_to_user_id = :scope_assigned_user_id';
            $params['scope_assigned_user_id'] = $viewerUserId;
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY t.updated_at DESC, t.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function findTicketForUser(int $ticketId, int $viewerUserId, array $viewerRoles = []): ?array
    {
        $roles = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), $viewerRoles);
        $sql = 'SELECT t.*, c.label AS category_label,
                cu.rank AS created_by_rank, cu.name AS created_by_name, cu.email AS created_by_email, cu.sector AS created_by_sector,
                au.rank AS assigned_to_rank, au.name AS assigned_to_name
            FROM tickets t
            INNER JOIN ticket_categories c ON c.id = t.category_id
            INNER JOIN users cu ON cu.id = t.created_by_user_id
            LEFT JOIN users au ON au.id = t.assigned_to_user_id
            WHERE t.id = :ticket_id';
        $params = ['ticket_id' => $ticketId];

        if (!in_array('admin', $roles, true) && !in_array('operator', $roles, true)) {
            $sql .= ' AND t.created_by_user_id = :viewer_user_id';
            $params['viewer_user_id'] = $viewerUserId;
        } elseif (in_array('operator', $roles, true) && !in_array('admin', $roles, true)) {
            $sql .= ' AND (t.created_by_user_id = :viewer_user_id OR t.assigned_to_user_id = :viewer_user_id)';
            $params['viewer_user_id'] = $viewerUserId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $ticket = $stmt->fetch();

        if (!is_array($ticket)) {
            return null;
        }

        $ticket['comments'] = $this->getComments($ticketId);
        $ticket['events'] = $this->getEvents($ticketId);

        return $ticket;
    }

    public function getAssignableUsers(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT u.id, u.name, u.email
            FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE u.status = "active" AND r.slug IN ("operator", "admin")
            ORDER BY u.name ASC, u.email ASC');

        return $stmt->fetchAll() ?: [];
    }

    public function getStatusSummary(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS total
            FROM tickets
            GROUP BY status')->fetchAll() ?: [];

        $summary = [
            'open' => 0,
            'in_progress' => 0,
            'resolved' => 0,
            'closed' => 0,
            'cancelled' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $summary)) {
                $summary[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return $summary;
    }

    public function getStatusSummaryForCreator(int $userId): array
    {
        $summary = [
            'open' => 0,
            'in_progress' => 0,
            'resolved' => 0,
            'closed' => 0,
            'cancelled' => 0,
        ];

        if ($userId <= 0) {
            return $summary;
        }

        $stmt = $this->pdo->prepare('SELECT status, COUNT(*) AS total
            FROM tickets
            WHERE created_by_user_id = :user_id
            GROUP BY status');
        $stmt->execute(['user_id' => $userId]);

        foreach (($stmt->fetchAll() ?: []) as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $summary)) {
                $summary[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return $summary;
    }

    public function countUnassigned(): int
    {
        return (int) ($this->pdo->query('SELECT COUNT(*) FROM tickets WHERE assigned_to_user_id IS NULL')->fetchColumn() ?: 0);
    }

    public function countHighPriorityActive(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*)
            FROM tickets
            WHERE priority = :priority
              AND status IN ("open", "in_progress")');
        $stmt->execute(['priority' => 'high']);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function getCategorySummary(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare('SELECT c.id, c.label, COUNT(t.id) AS total
            FROM ticket_categories c
            LEFT JOIN tickets t ON t.category_id = c.id
            GROUP BY c.id, c.label
            ORDER BY total DESC, c.label ASC
            LIMIT :limit');
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function getAssignmentSummary(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare('SELECT
                u.id AS assignee_id,
                COALESCE(u.name, "Não atribuído") AS assignee_name,
                COUNT(t.id) AS total
            FROM tickets t
            LEFT JOIN users u ON u.id = t.assigned_to_user_id
            GROUP BY u.id, assignee_name
            ORDER BY total DESC, assignee_name ASC
            LIMIT :limit');
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function getRecentTickets(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare('SELECT
                t.id,
                t.title,
                t.status,
                t.priority,
                t.updated_at,
                c.label AS category_label,
                cu.name AS created_by_name
            FROM tickets t
            INNER JOIN ticket_categories c ON c.id = t.category_id
            INNER JOIN users cu ON cu.id = t.created_by_user_id
            ORDER BY t.updated_at DESC, t.id DESC
            LIMIT :limit');
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }


    public function updateCategory(int $categoryId, string $label, int $sortOrder, bool $active): void
    {
        $label = trim($label);
        if ($categoryId <= 0 || $label === '') {
            throw new \RuntimeException('Categoria invalida.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ticket_categories
            SET label = :label,
                active = :active,
                sort_order = :sort_order,
                updated_at = :updated_at
            WHERE id = :id'
        );
        $stmt->execute([
            'label' => $label,
            'active' => $active ? 1 : 0,
            'sort_order' => max(1, $sortOrder),
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $categoryId,
        ]);
    }

    public function addComment(int $ticketId, int $userId, string $body): void
    {
        $comment = trim($body);
        if ($ticketId <= 0 || $userId <= 0 || $comment === '') {
            throw new \RuntimeException('Comentario invalido.');
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal, created_at)
            VALUES (:ticket_id, :user_id, :body, 0, :created_at)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'body' => $comment,
            'created_at' => $now,
        ]);

        $this->touchTicket($ticketId, $now);
        $this->logEvent($ticketId, $userId, 'comment_added', [
            'length' => mb_strlen($comment),
        ]);
    }

    public function updateTicketWorkflow(int $ticketId, int $actorUserId, array $data): void
    {
        $ticket = $this->findTicketById($ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket nao encontrado.');
        }

        $status = strtolower(trim((string) ($data['status'] ?? $ticket['status'] ?? 'open')));
        $assignedToUserId = (int) ($data['assigned_to_user_id'] ?? ($ticket['assigned_to_user_id'] ?? 0));
        if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed', 'cancelled'], true)) {
            throw new \RuntimeException('Status invalido.');
        }

        $now = date('Y-m-d H:i:s');
        $previousStatus = strtolower(trim((string) ($ticket['status'] ?? '')));
        $wasCompleted = in_array($previousStatus, ['resolved', 'closed'], true);
        $isCompleted = in_array($status, ['resolved', 'closed'], true);
        $previousResolvedAt = trim((string) ($ticket['resolved_at'] ?? ''));
        $previousClosedAt = trim((string) ($ticket['closed_at'] ?? ''));
        $resolvedAt = $isCompleted
            ? ($wasCompleted && $previousResolvedAt !== '' ? $previousResolvedAt : $now)
            : null;
        $closedAt = $status === 'closed'
            ? ($previousStatus === 'closed' && $previousClosedAt !== '' ? $previousClosedAt : $now)
            : null;

        $stmt = $this->pdo->prepare(
            'UPDATE tickets
            SET assigned_to_user_id = :assigned_to_user_id,
                status = :status,
                updated_at = :updated_at,
                resolved_at = :resolved_at,
                closed_at = :closed_at
            WHERE id = :id'
        );
        $stmt->execute([
            'assigned_to_user_id' => $assignedToUserId > 0 ? $assignedToUserId : null,
            'status' => $status,
            'updated_at' => $now,
            'resolved_at' => $resolvedAt,
            'closed_at' => $closedAt,
            'id' => $ticketId,
        ]);

        if ((int) ($ticket['assigned_to_user_id'] ?? 0) !== $assignedToUserId) {
            $this->logEvent($ticketId, $actorUserId, 'ticket_assigned', [
                'assigned_to_user_id' => $assignedToUserId,
            ]);
        }

        if ((string) ($ticket['status'] ?? '') !== $status) {
            $this->logEvent($ticketId, $actorUserId, 'status_changed', [
                'from' => (string) ($ticket['status'] ?? ''),
                'to' => $status,
            ]);
        }
    }

    private function findTicketById(int $ticketId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tickets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $ticketId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function getComments(int $ticketId): array
    {
        $stmt = $this->pdo->prepare('SELECT tc.id, tc.body, tc.created_at, tc.user_id, u.name AS user_name, u.rank AS user_rank, u.avatar_path,
                (
                    SELECT r.slug
                    FROM user_roles ur
                    INNER JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = u.id
                    ORDER BY CASE r.slug
                        WHEN \'admin\' THEN 1
                        WHEN \'operator\' THEN 2
                        WHEN \'user\' THEN 3
                        ELSE 4
                    END
                    LIMIT 1
                ) AS user_role
            FROM ticket_comments tc
            INNER JOIN users u ON u.id = tc.user_id
            WHERE tc.ticket_id = :ticket_id
            ORDER BY tc.created_at ASC, tc.id ASC');
        $stmt->execute(['ticket_id' => $ticketId]);

        return $stmt->fetchAll() ?: [];
    }

    private function getEvents(int $ticketId): array
    {
        $stmt = $this->pdo->prepare('SELECT te.id, te.event_type, te.event_data_json, te.created_at, te.user_id, u.name AS user_name, u.rank AS user_rank
            FROM ticket_events te
            LEFT JOIN users u ON u.id = te.user_id
            WHERE te.ticket_id = :ticket_id
            ORDER BY te.created_at ASC, te.id ASC');
        $stmt->execute(['ticket_id' => $ticketId]);

        return $stmt->fetchAll() ?: [];
    }

    private function touchTicket(int $ticketId, string $timestamp): void
    {
        $stmt = $this->pdo->prepare('UPDATE tickets SET updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'updated_at' => $timestamp,
            'id' => $ticketId,
        ]);
    }

    private function logEvent(int $ticketId, int $userId, string $eventType, array $data): void
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
