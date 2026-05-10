<?php
declare(strict_types=1);

namespace MIS;

final class Tasks
{
    public const STATUSES = ['pending','in_progress','blocked','complete'];

    public static function listForFault(int $faultId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT t.*,
                    cu.full_name AS created_by_name,
                    su.full_name AS completed_by_name,
                    su.role      AS completed_by_role
               FROM fault_tasks t
               LEFT JOIN users cu ON cu.id = t.created_by
               LEFT JOIN users su ON su.id = t.completed_by
              WHERE t.fault_id = :fid
              ORDER BY t.task_order ASC, t.id ASC'
        );
        $stmt->execute([':fid' => $faultId]);
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

    public static function setStatus(int $taskId, int $userId, string $newStatus, ?string $holdup = null): array
    {
        if (!in_array($newStatus, self::STATUSES, true)) {
            throw new \InvalidArgumentException('invalid_status');
        }
        $pdo = Db::pdo();
        $cur = $pdo->prepare('SELECT * FROM fault_tasks WHERE id = :id');
        $cur->execute([':id' => $taskId]);
        $task = $cur->fetch();
        if (!$task) {
            throw new \RuntimeException('task_not_found');
        }
        $oldStatus = $task['status'];

        $sets   = ['status = :s', 'updated_by = :u'];
        $params = [':s' => $newStatus, ':u' => $userId, ':id' => $taskId];

        if ($newStatus === 'complete') {
            $sets[] = 'completed_by = :cb';
            $sets[] = 'completed_at = NOW()';
            $sets[] = 'holdup_reason = NULL';
            $params[':cb'] = $userId;
        } elseif ($newStatus === 'blocked') {
            $sets[] = 'holdup_reason = :h';
            $params[':h'] = $holdup === null ? null : mb_substr($holdup, 0, 255);
        } else {
            // Reopening or moving back to pending/in_progress clears completion + holdup
            $sets[] = 'completed_by = NULL';
            $sets[] = 'completed_at = NULL';
            $sets[] = 'holdup_reason = NULL';
        }

        $sql = 'UPDATE fault_tasks SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        Audit::log($userId, 'task.status', 'task', (string) $taskId, [
            'from'    => $oldStatus,
            'to'      => $newStatus,
            'fault_id'=> $task['fault_id'],
            'holdup'  => $holdup,
        ]);

        if (!empty($task['ticket_id'])) {
            $body = "Task " . self::shortLabel($newStatus) . ": " . mb_substr($task['title'], 0, 200);
            if ($newStatus === 'blocked' && $holdup) {
                $body .= " — holdup: " . mb_substr($holdup, 0, 200);
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
