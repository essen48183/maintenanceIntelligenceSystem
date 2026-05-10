<?php
declare(strict_types=1);

namespace MIS;

final class Tickets
{
    public static function list(array $filter = []): array
    {
        $pdo = Db::pdo();
        $where = 'WHERE 1=1';
        $params = [];
        if (!empty($filter['status'])) {
            $where .= ' AND t.status = :status';
            $params[':status'] = $filter['status'];
        }
        if (!empty($filter['assigned_to'])) {
            $where .= ' AND t.assigned_to = :a';
            $params[':a'] = $filter['assigned_to'];
        }
        $sql = "SELECT t.*, u1.full_name AS created_by_name, u2.full_name AS assigned_to_name,
                       at.tail_number, fc.fault_code, fc.title AS fault_title
                  FROM tickets t
                  LEFT JOIN users u1 ON u1.id = t.created_by
                  LEFT JOIN users u2 ON u2.id = t.assigned_to
                  LEFT JOIN aircraft_tails at ON at.id = t.tail_id
                  LEFT JOIN fault_catalog fc ON fc.id = t.fault_id
                  $where
                  ORDER BY FIELD(t.severity,'CRITICAL','HIGH','MEDIUM','LOW'), t.opened_at DESC
                  LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function detail(int $id): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT t.*, u1.full_name AS created_by_name, u2.full_name AS assigned_to_name,
                    at.tail_number, fc.fault_code, fc.title AS fault_title, fc.severity AS fault_severity
               FROM tickets t
               LEFT JOIN users u1 ON u1.id = t.created_by
               LEFT JOIN users u2 ON u2.id = t.assigned_to
               LEFT JOIN aircraft_tails at ON at.id = t.tail_id
               LEFT JOIN fault_catalog fc ON fc.id = t.fault_id
              WHERE t.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            return null;
        }
        $events = $pdo->prepare(
            'SELECT te.*, u.full_name, u.role, u.shift
               FROM ticket_events te
               JOIN users u ON u.id = te.user_id
              WHERE te.ticket_id = :id
              ORDER BY te.created_at ASC'
        );
        $events->execute([':id' => $id]);
        $ticket['events'] = $events->fetchAll();
        return $ticket;
    }

    public static function create(int $userId, array $input): int
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $year = (int) date('Y');
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE :prefix");
            $countStmt->execute([':prefix' => "TKT-$year-%"]);
            $next = ((int) $countStmt->fetchColumn()) + 1;
            $tn = sprintf('TKT-%d-%04d', $year, $next);

            $ins = $pdo->prepare(
                'INSERT INTO tickets (ticket_number, fault_id, tail_id, title, status, severity, created_by, assigned_to)
                 VALUES (:tn, :fid, :tail, :title, :status, :sev, :uid, :assignee)'
            );
            $ins->execute([
                ':tn'       => $tn,
                ':fid'      => $input['fault_id'] ?? null,
                ':tail'     => $input['tail_id']  ?? null,
                ':title'    => $input['title'],
                ':status'   => $input['status']   ?? 'open',
                ':sev'      => $input['severity'] ?? 'MEDIUM',
                ':uid'      => $userId,
                ':assignee' => $input['assigned_to'] ?? $userId,
            ]);
            $ticketId = (int) $pdo->lastInsertId();

            self::addEvent($ticketId, $userId, 'created', $input['initial_comment'] ?? 'Ticket opened.', null);
            $pdo->commit();
            Audit::log($userId, 'ticket.create', 'ticket', (string) $ticketId, ['ticket_number' => $tn]);
            return $ticketId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function addEvent(int $ticketId, int $userId, string $type, ?string $body, ?array $metadata = null): int
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO ticket_events (ticket_id, user_id, event_type, body, metadata)
             VALUES (:tid, :uid, :type, :body, :meta)'
        );
        $stmt->execute([
            ':tid' => $ticketId,
            ':uid' => $userId,
            ':type' => $type,
            ':body' => $body,
            ':meta' => $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);
        $id = (int) $pdo->lastInsertId();
        Audit::log($userId, 'ticket.event.' . $type, 'ticket', (string) $ticketId, $metadata);
        return $id;
    }

    public static function reassign(int $ticketId, int $actingUserId, int $newAssignee, ?string $note = null): void
    {
        $pdo = Db::pdo();
        $cur = $pdo->prepare('SELECT assigned_to FROM tickets WHERE id = :id');
        $cur->execute([':id' => $ticketId]);
        $from = $cur->fetchColumn();
        if ($from === false) {
            throw new \RuntimeException('Ticket not found');
        }
        $upd = $pdo->prepare('UPDATE tickets SET assigned_to = :a, updated_at = NOW() WHERE id = :id');
        $upd->execute([':a' => $newAssignee, ':id' => $ticketId]);
        self::addEvent($ticketId, $actingUserId, 'reassigned', $note, [
            'from_user' => $from === null ? null : (int) $from,
            'to_user'   => $newAssignee,
            'shift_handoff' => true,
        ]);
    }

    public static function setStatus(int $ticketId, int $actingUserId, string $newStatus, ?string $note = null): void
    {
        $valid = ['open','in_progress','on_hold','closed','cancelled'];
        if (!in_array($newStatus, $valid, true)) {
            throw new \InvalidArgumentException('invalid status');
        }
        $pdo = Db::pdo();
        $cur = $pdo->prepare('SELECT status FROM tickets WHERE id = :id');
        $cur->execute([':id' => $ticketId]);
        $oldStatus = $cur->fetchColumn();
        if ($oldStatus === false) {
            throw new \RuntimeException('Ticket not found');
        }
        $sets = ['status = :s'];
        $params = [':s' => $newStatus, ':id' => $ticketId];
        if ($newStatus === 'closed') {
            $sets[] = 'closed_at = NOW()';
            $sets[] = 'closed_by = :cb';
            $params[':cb'] = $actingUserId;
        }
        $sql = 'UPDATE tickets SET ' . implode(',', $sets) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);
        self::addEvent($ticketId, $actingUserId, 'status_changed', $note, [
            'from' => $oldStatus,
            'to'   => $newStatus,
        ]);
    }
}
