<?php
declare(strict_types=1);

namespace MIS;

final class Tasks
{
    public const STATUSES = ['pending','in_progress','blocked','awaiting_inspection','complete'];

    public static function listForFault(int $faultId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT t.*,
                    cu.full_name AS created_by_name,
                    su.full_name AS completed_by_name,
                    su.role      AS completed_by_role,
                    aiu.full_name AS awaiting_inspection_by_name,
                    aiu.role      AS awaiting_inspection_by_role
               FROM fault_tasks t
               LEFT JOIN users cu  ON cu.id  = t.created_by
               LEFT JOIN users su  ON su.id  = t.completed_by
               LEFT JOIN users aiu ON aiu.id = t.awaiting_inspection_by
              WHERE t.fault_id = :fid
              ORDER BY t.task_order ASC, t.id ASC'
        );
        $stmt->execute([':fid' => $faultId]);
        return $stmt->fetchAll();
    }

    /** Tasks awaiting inspection across the system — supervisor sign-off queue. */
    public static function awaitingInspection(): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->query(
            'SELECT t.*,
                    fc.fault_code, fc.title AS fault_title,
                    aiu.full_name AS awaiting_inspection_by_name,
                    aiu.role      AS awaiting_inspection_by_role
               FROM fault_tasks t
               JOIN fault_catalog fc ON fc.id = t.fault_id
               LEFT JOIN users aiu ON aiu.id = t.awaiting_inspection_by
              WHERE t.status = "awaiting_inspection"
              ORDER BY t.awaiting_inspection_at ASC'
        );
        return $stmt->fetchAll();
    }

    public static function add(int $faultId, ?int $userId, string $title, array $opts = []): int
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('title_required');
        }
        $pdo = Db::pdo();
        $nextOrder = (int) $pdo->query("SELECT COALESCE(MAX(task_order),0)+10 FROM fault_tasks WHERE fault_id = " . (int) $faultId)->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO fault_tasks (fault_id, ticket_id, title, description, status, source, task_order, created_by, updated_by)
             VALUES (:fid, :tid, :title, :desc, :status, :src, :ord, :cb, :ub)'
        );
        $stmt->execute([
            ':fid'   => $faultId,
            ':tid'   => $opts['ticket_id'] ?? null,
            ':title' => mb_substr($title, 0, 500),
            ':desc'  => $opts['description'] ?? null,
            ':status'=> $opts['status'] ?? 'pending',
            ':src'   => $opts['source'] ?? 'user',
            ':ord'   => $nextOrder,
            ':cb'    => $userId,
            ':ub'    => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        Audit::log($userId, 'task.create', 'task', (string) $id, ['fault_id' => $faultId, 'source' => $opts['source'] ?? 'user']);
        if (!empty($opts['ticket_id'])) {
            Tickets::addEvent((int) $opts['ticket_id'], $userId ?? 0, 'comment', 'Task added: ' . mb_substr($title, 0, 200), [
                'task_id' => $id, 'task_status' => $opts['status'] ?? 'pending', 'source' => $opts['source'] ?? 'user',
            ]);
        }
        return $id;
    }

    /**
     * Update task status with RTS-aware completion routing.
     * If $newStatus = 'complete' AND (task.requires_inspection OR user lacks rts_authority),
     * the task is sent to 'awaiting_inspection' instead and the actor recorded.
     * Going from 'awaiting_inspection' → 'complete' requires inspection_authority.
     */
    public static function setStatus(int $taskId, array $user, string $newStatus, ?string $holdup = null): array
    {
        if (!in_array($newStatus, self::STATUSES, true)) {
            throw new \InvalidArgumentException('invalid_status');
        }
        $userId = (int) $user['id'];
        $pdo = Db::pdo();
        $cur = $pdo->prepare('SELECT * FROM fault_tasks WHERE id = :id');
        $cur->execute([':id' => $taskId]);
        $task = $cur->fetch();
        if (!$task) throw new \RuntimeException('task_not_found');
        $oldStatus = $task['status'];

        // RTS routing: tech without rts_authority asking for 'complete' → flagged for inspection
        $requires = (int) $task['requires_inspection'] === 1;
        $hasRts   = (int) ($user['rts_authority'] ?? 0) === 1;
        $hasInsp  = (int) ($user['inspection_authority'] ?? 0) === 1 || ($user['role'] ?? '') === 'admin';

        if ($newStatus === 'complete') {
            // Allowed paths to 'complete':
            //   1) tech has RTS authority and task does not require inspection
            //   2) coming from 'awaiting_inspection' AND user has inspection_authority
            $fromQueue = ($oldStatus === 'awaiting_inspection');
            if ($fromQueue && !$hasInsp) {
                throw new \RuntimeException('inspection_authority_required');
            }
            if (!$fromQueue && ($requires || !$hasRts)) {
                // Re-route to awaiting_inspection
                $newStatus = 'awaiting_inspection';
            }
        }
        if ($newStatus === 'awaiting_inspection') {
            // any write user can flag for inspection; record submitter
        }

        $sets   = ['status = :s', 'updated_by = :u'];
        $params = [':s' => $newStatus, ':u' => $userId, ':id' => $taskId];

        if ($newStatus === 'complete') {
            $sets[] = 'completed_by = :cb';
            $sets[] = 'completed_at = NOW()';
            $sets[] = 'holdup_reason = NULL';
            $params[':cb'] = $userId;
        } elseif ($newStatus === 'awaiting_inspection') {
            $sets[] = 'awaiting_inspection_by = :ab';
            $sets[] = 'awaiting_inspection_at = NOW()';
            $sets[] = 'holdup_reason = NULL';
            $params[':ab'] = $userId;
        } elseif ($newStatus === 'blocked') {
            $sets[] = 'holdup_reason = :h';
            $sets[] = 'awaiting_inspection_by = NULL';
            $sets[] = 'awaiting_inspection_at = NULL';
            $params[':h'] = $holdup === null ? null : mb_substr($holdup, 0, 255);
        } else {
            // Reopening clears all completion / holdup / inspection state
            $sets[] = 'completed_by = NULL';
            $sets[] = 'completed_at = NULL';
            $sets[] = 'awaiting_inspection_by = NULL';
            $sets[] = 'awaiting_inspection_at = NULL';
            $sets[] = 'holdup_reason = NULL';
        }

        $sql = 'UPDATE fault_tasks SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        Audit::log($userId, 'task.status', 'task', (string) $taskId, [
            'from'   => $oldStatus,
            'to'     => $newStatus,
            'fault_id' => $task['fault_id'],
            'holdup' => $holdup,
            'rerouted_to_inspection' => ($newStatus === 'awaiting_inspection'),
        ]);

        if (!empty($task['ticket_id'])) {
            $body = "Task " . self::shortLabel($newStatus) . ": " . mb_substr($task['title'], 0, 200);
            if ($newStatus === 'blocked' && $holdup) {
                $body .= " — holdup: " . mb_substr($holdup, 0, 200);
            }
            if ($newStatus === 'awaiting_inspection') {
                $body .= " — flagged for supervisor sign-off";
            }
            Tickets::addEvent((int) $task['ticket_id'], $userId, 'comment', $body, [
                'task_id' => $taskId, 'from' => $oldStatus, 'to' => $newStatus, 'holdup' => $holdup,
            ]);
        }

        $cur->execute([':id' => $taskId]);
        return $cur->fetch();
    }

    public static function update(int $taskId, int $userId, array $fields): array
    {
        $allowed = ['title', 'description', 'task_order', 'ticket_id'];
        $sets = ['updated_by = :u']; $params = [':u' => $userId, ':id' => $taskId];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $fields)) {
                $sets[] = "$f = :$f";
                $params[":$f"] = $fields[$f];
            }
        }
        if (count($sets) === 1) {
            throw new \InvalidArgumentException('no_fields');
        }
        $pdo = Db::pdo();
        $sql = 'UPDATE fault_tasks SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);
        Audit::log($userId, 'task.update', 'task', (string) $taskId, $fields);
        $cur = $pdo->prepare('SELECT * FROM fault_tasks WHERE id = :id');
        $cur->execute([':id' => $taskId]);
        return $cur->fetch();
    }

    public static function delete(int $taskId, int $userId): void
    {
        $pdo = Db::pdo();
        $cur = $pdo->prepare('SELECT fault_id, ticket_id, title FROM fault_tasks WHERE id = :id');
        $cur->execute([':id' => $taskId]);
        $task = $cur->fetch();
        if (!$task) return;
        $pdo->prepare('DELETE FROM fault_tasks WHERE id = :id')->execute([':id' => $taskId]);
        Audit::log($userId, 'task.delete', 'task', (string) $taskId, ['fault_id' => $task['fault_id']]);
    }

    private static function shortLabel(string $status): string
    {
        return [
            'pending'     => 'reopened',
            'in_progress' => 'started',
            'blocked'     => 'blocked',
            'complete'    => 'completed',
        ][$status] ?? $status;
    }
}
