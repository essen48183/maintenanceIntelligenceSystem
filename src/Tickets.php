<?php
declare(strict_types=1);

namespace MIS;

/**
 * FOUNDATIONAL CONSTRAINT — DO NOT REMOVE:
 *
 *   The AI assistant NEVER closes a ticket. Every Tickets::setStatus call
 *   asserts a real authenticated human actor.
 *   See prompts.md, Prompts 15 & 16.
 *
 * Parent/child ticket model (prompts.md, Prompt 16):
 *   - PARENT ticket = model-level work item. parent_id IS NULL,
 *     tail_id IS NULL.
 *   - CHILD ticket = per-tail execution. parent_id IS NOT NULL,
 *     tail_id IS NOT NULL.
 *   - A parent CANNOT be closed until every child is `closed` or
 *     `cancelled` (enforced in setStatus).
 */
final class Tickets
{
    public const AI_CANNOT_COMPLETE = true;

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

        // Children rollup (only meaningful for parents, but cheap to compute always)
        $ticket['children'] = self::children((int) $id);
        $ticket['children_total']  = count($ticket['children']);
        $ticket['children_closed'] = count(array_filter($ticket['children'], fn($c) => in_array($c['status'], ['closed','cancelled'], true)));
        $ticket['progress'] = $ticket['children_total'] > 0
            ? round(($ticket['children_closed'] / $ticket['children_total']) * 100)
            : null;
        return $ticket;
    }

    /** Direct children of a parent ticket (per-tail rows). */
    public static function children(int $parentId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT t.id, t.ticket_number, t.status, t.severity, t.assigned_to, t.opened_at, t.closed_at,
                    at.tail_number, u.full_name AS assigned_to_name
               FROM tickets t
               LEFT JOIN aircraft_tails at ON at.id = t.tail_id
               LEFT JOIN users u ON u.id = t.assigned_to
              WHERE t.parent_id = :pid
              ORDER BY at.tail_number'
        );
        $stmt->execute([':pid' => $parentId]);
        return $stmt->fetchAll();
    }

    public static function isParent(array $ticket): bool
    {
        return empty($ticket['parent_id']) && empty($ticket['tail_id']);
    }

    public static function create(int $userId, array $input): int
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $year = (int) date('Y');
            $parentId = isset($input['parent_id']) ? (int) $input['parent_id'] : null;
            $tn = self::nextNumber($year, $parentId, $input['tail_id'] ?? null);

            $ins = $pdo->prepare(
                'INSERT INTO tickets (ticket_number, parent_id, fault_id, tail_id, title, status, severity, created_by, assigned_to)
                 VALUES (:tn, :pid, :fid, :tail, :title, :status, :sev, :uid, :assignee)'
            );
            $ins->execute([
                ':tn'       => $tn,
                ':pid'      => $parentId,
                ':fid'      => $input['fault_id'] ?? null,
                ':tail'     => $input['tail_id']  ?? null,
                ':title'    => $input['title'],
                ':status'   => $input['status']   ?? 'open',
                ':sev'      => $input['severity'] ?? 'MEDIUM',
                ':uid'      => $userId,
                ':assignee' => array_key_exists('assigned_to', $input) ? $input['assigned_to'] : $userId,
            ]);
            $ticketId = (int) $pdo->lastInsertId();

            self::addEvent($ticketId, $userId, 'created', $input['initial_comment'] ?? 'Ticket opened.', null);
            $pdo->commit();
            Audit::log($userId, 'ticket.create', 'ticket', (string) $ticketId, ['ticket_number' => $tn, 'parent_id' => $parentId]);
            return $ticketId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function nextNumber(int $year, ?int $parentId, $tailId): string
    {
        $pdo = Db::pdo();
        if ($parentId === null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number REGEXP :rx");
            $stmt->execute([':rx' => "^TKT-$year-[0-9]{4}$"]);
            $next = ((int) $stmt->fetchColumn()) + 1;
            return sprintf('TKT-%d-%04d', $year, $next);
        }
        // Child ticket: append the tail number for readability
        $tail = '';
        if ($tailId) {
            $t = $pdo->prepare('SELECT tail_number FROM aircraft_tails WHERE id = :id');
            $t->execute([':id' => $tailId]);
            $tail = (string) $t->fetchColumn();
        }
        $parent = $pdo->prepare('SELECT ticket_number FROM tickets WHERE id = :id');
        $parent->execute([':id' => $parentId]);
        $parentTn = (string) $parent->fetchColumn();
        $suffix = $tail !== '' ? $tail : ('C' . random_int(1000, 9999));
        return $parentTn . '-' . $suffix;
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

    /**
     * Set a ticket's status. Accepts the actor as a user array so we can
     * enforce Auth::assertHumanActor — the AI cannot move ticket state.
     *
     * Parent-ticket constraint: a parent (no tail_id, no parent_id) may
     * only be closed when every child is `closed` or `cancelled`. Trying
     * to close early raises `parent_has_open_children`.
     */
    public static function setStatus(int $ticketId, $actor, string $newStatus, ?string $note = null): void
    {
        // Backwards-compat shim: older callers passed an int userId
        $user = is_array($actor) ? $actor : ['id' => (int) $actor, 'role' => 'admin'];
        Auth::assertHumanActor($user);
        $actingUserId = (int) $user['id'];

        $valid = ['open','in_progress','on_hold','closed','cancelled'];
        if (!in_array($newStatus, $valid, true)) {
            throw new \InvalidArgumentException('invalid status');
        }
        $pdo = Db::pdo();
        $cur = $pdo->prepare('SELECT id, status, parent_id, tail_id FROM tickets WHERE id = :id');
        $cur->execute([':id' => $ticketId]);
        $row = $cur->fetch();
        if (!$row) throw new \RuntimeException('Ticket not found');
        $oldStatus = $row['status'];

        // Parent-ticket close gate: only when all children are closed/cancelled
        $isParent = empty($row['parent_id']) && empty($row['tail_id']);
        if ($isParent && $newStatus === 'closed') {
            $kids = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE parent_id = :pid AND status NOT IN ('closed','cancelled')");
            $kids->execute([':pid' => $ticketId]);
            $openKids = (int) $kids->fetchColumn();
            if ($openKids > 0) {
                throw new \RuntimeException('parent_has_open_children');
            }
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
