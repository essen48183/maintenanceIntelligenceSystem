<?php
declare(strict_types=1);

namespace MIS;

/**
 * Preventive maintenance: query PM items with the joins the UI needs,
 * advance lifecycle states (start, complete, sign-off), and surface the
 * supervisor sign-off queue.
 */
final class PM
{
    public const STATUSES = ['current','due_soon','overdue','in_progress','awaiting_inspection','complete'];

    /** PM items relevant to a list of tail IDs (covers tail-targeted plans AND
     *  component-targeted plans for components installed on those tails). */
    public static function forTails(array $tailIds): array
    {
        if (!$tailIds) return [];
        $place = implode(',', array_fill(0, count($tailIds), '?'));
        $pdo = Db::pdo();
        $sql = "
            SELECT i.*,
                   p.title         AS plan_title,
                   p.description   AS plan_description,
                   p.applies_to    AS plan_applies_to,
                   p.trigger_type  AS plan_trigger_type,
                   p.interval_value AS plan_interval_value,
                   p.tolerance_value AS plan_tolerance,
                   p.requires_inspection AS plan_requires_inspection,
                   p.ata_chapter   AS plan_ata,
                   t.tail_number,
                   c.component_type, c.serial_number, c.position, c.total_hours, c.total_cycles, c.tail_id AS comp_tail_id,
                   ct.tail_number  AS comp_tail_number,
                   au.full_name    AS assigned_to_name,
                   au.role         AS assigned_to_role,
                   wb.full_name    AS awaiting_inspection_by_name,
                   sb.full_name    AS signed_off_by_name,
                   sb.role         AS signed_off_by_role
              FROM pm_items i
              JOIN pm_plans p              ON p.id = i.plan_id
              LEFT JOIN aircraft_tails t   ON t.id = i.tail_id
              LEFT JOIN components c       ON c.id = i.component_id
              LEFT JOIN aircraft_tails ct  ON ct.id = c.tail_id
              LEFT JOIN users au           ON au.id = i.assigned_to
              LEFT JOIN users wb           ON wb.id = i.awaiting_inspection_by
              LEFT JOIN users sb           ON sb.id = i.signed_off_by
             WHERE (i.target_kind = 'tail' AND i.tail_id IN ($place))
                OR (i.target_kind = 'component' AND c.tail_id IN ($place))
             ORDER BY FIELD(i.status,'overdue','awaiting_inspection','in_progress','due_soon','current','complete'),
                      i.next_due_at ASC, i.next_due_hours ASC";
        $stmt = $pdo->prepare($sql);
        // bind twice (once per IN clause)
        $params = array_merge($tailIds, $tailIds);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Count by status across the (sub-)fleet, for KPI cards. */
    public static function statusCounts(?array $tailIds = null): array
    {
        $pdo = Db::pdo();
        $where = '';
        $params = [];
        if ($tailIds) {
            $place = implode(',', array_fill(0, count($tailIds), '?'));
            $where = "WHERE (i.target_kind='tail' AND i.tail_id IN ($place))
                       OR (i.target_kind='component' AND c.tail_id IN ($place))";
            $params = array_merge($tailIds, $tailIds);
        }
        $sql = "SELECT i.status, COUNT(*) AS n
                  FROM pm_items i
                  LEFT JOIN components c ON c.id = i.component_id
                  $where
                  GROUP BY i.status";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = ['current'=>0,'due_soon'=>0,'overdue'=>0,'in_progress'=>0,'awaiting_inspection'=>0,'complete'=>0];
        foreach ($stmt->fetchAll() as $r) { $out[$r['status']] = (int) $r['n']; }
        return $out;
    }

    /** Items that are currently waiting on inspection sign-off (supervisor queue). */
    public static function awaitingInspection(): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->query(
            "SELECT i.id, i.plan_id, i.tail_id, i.component_id, i.awaiting_inspection_at,
                    p.title       AS plan_title, p.requires_inspection,
                    t.tail_number,
                    c.component_type, c.serial_number, c.position, ct.tail_number AS comp_tail_number,
                    u.full_name   AS submitted_by, u.role AS submitted_by_role
              FROM pm_items i
              JOIN pm_plans p           ON p.id = i.plan_id
              LEFT JOIN aircraft_tails t ON t.id = i.tail_id
              LEFT JOIN components c    ON c.id = i.component_id
              LEFT JOIN aircraft_tails ct ON ct.id = c.tail_id
              LEFT JOIN users u         ON u.id = i.awaiting_inspection_by
             WHERE i.status = 'awaiting_inspection'
             ORDER BY i.awaiting_inspection_at ASC"
        );
        return $stmt->fetchAll();
    }

    public static function start(int $itemId, int $userId, ?int $ticketId = null): array
    {
        $pdo = Db::pdo();
        $pdo->prepare(
            'UPDATE pm_items SET status = "in_progress", assigned_to = :u, ticket_id = :t WHERE id = :id'
        )->execute([':u' => $userId, ':t' => $ticketId, ':id' => $itemId]);
        Audit::log($userId, 'pm.start', 'pm_item', (string) $itemId, ['ticket_id' => $ticketId]);
        return self::byId($itemId);
    }

    /**
     * Mark a PM item complete. RTS rules:
     *   - if plan.requires_inspection OR user lacks rts_authority → status='awaiting_inspection'
     *     (records who submitted it for review, when)
     *   - else → status='complete' with full sign-off in the same call
     */
    public static function complete(int $itemId, array $user, ?string $notes = null): array
    {
        $pdo = Db::pdo();
        $row = self::byId($itemId);
        if (!$row) throw new \RuntimeException('pm_item_not_found');
        $requires = (int) $row['plan_requires_inspection'] === 1;
        $hasRts   = (int) ($user['rts_authority'] ?? 0) === 1;

        if ($requires || !$hasRts) {
            $pdo->prepare(
                'UPDATE pm_items
                    SET status = "awaiting_inspection",
                        awaiting_inspection_at = NOW(),
                        awaiting_inspection_by = :u,
                        notes = COALESCE(:notes, notes)
                  WHERE id = :id'
            )->execute([':u' => $user['id'], ':notes' => $notes, ':id' => $itemId]);
            Audit::log((int) $user['id'], 'pm.flag_inspection', 'pm_item', (string) $itemId, [
                'reason' => $requires ? 'plan_requires_inspection' : 'tech_lacks_rts_authority',
            ]);
        } else {
            $pdo->prepare(
                'UPDATE pm_items
                    SET status = "complete",
                        signed_off_by = :u,
                        signed_off_at = NOW(),
                        last_done_at  = NOW(),
                        notes = COALESCE(:notes, notes)
                  WHERE id = :id'
            )->execute([':u' => $user['id'], ':notes' => $notes, ':id' => $itemId]);
            Audit::log((int) $user['id'], 'pm.self_signoff', 'pm_item', (string) $itemId, ['rts' => true]);
        }
        return self::byId($itemId);
    }

    /** Supervisor approves a PM item that was awaiting inspection. */
    public static function signOff(int $itemId, array $user, ?string $notes = null): array
    {
        if ((int) ($user['inspection_authority'] ?? 0) !== 1 && $user['role'] !== 'admin') {
            throw new \RuntimeException('inspection_authority_required');
        }
        $pdo = Db::pdo();
        $row = self::byId($itemId);
        if (!$row) throw new \RuntimeException('pm_item_not_found');
        if ($row['status'] !== 'awaiting_inspection') {
            throw new \RuntimeException('not_awaiting_inspection');
        }
        $pdo->prepare(
            'UPDATE pm_items
                SET status = "complete",
                    signed_off_by = :u,
                    signed_off_at = NOW(),
                    last_done_at  = NOW(),
                    notes = COALESCE(:notes, notes)
              WHERE id = :id'
        )->execute([':u' => $user['id'], ':notes' => $notes, ':id' => $itemId]);
        Audit::log((int) $user['id'], 'pm.signoff', 'pm_item', (string) $itemId, [
            'submitted_by' => (int) $row['awaiting_inspection_by'],
        ]);
        return self::byId($itemId);
    }

    /** Reject an awaiting-inspection submission and send it back. */
    public static function reject(int $itemId, array $user, string $reason): array
    {
        if ((int) ($user['inspection_authority'] ?? 0) !== 1 && $user['role'] !== 'admin') {
            throw new \RuntimeException('inspection_authority_required');
        }
        $pdo = Db::pdo();
        $pdo->prepare(
            'UPDATE pm_items
                SET status = "in_progress",
                    awaiting_inspection_at = NULL,
                    awaiting_inspection_by = NULL,
                    notes = :reason
              WHERE id = :id'
        )->execute([':reason' => mb_substr($reason, 0, 500), ':id' => $itemId]);
        Audit::log((int) $user['id'], 'pm.reject', 'pm_item', (string) $itemId, ['reason' => $reason]);
        return self::byId($itemId);
    }

    public static function byId(int $itemId): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT i.*,
                    p.title AS plan_title, p.description AS plan_description,
                    p.applies_to AS plan_applies_to, p.trigger_type AS plan_trigger_type,
                    p.interval_value AS plan_interval_value, p.tolerance_value AS plan_tolerance,
                    p.requires_inspection AS plan_requires_inspection, p.ata_chapter AS plan_ata,
                    t.tail_number,
                    c.component_type, c.serial_number, c.position, c.total_hours, c.total_cycles, c.tail_id AS comp_tail_id,
                    ct.tail_number AS comp_tail_number,
                    au.full_name AS assigned_to_name, au.role AS assigned_to_role,
                    wb.full_name AS awaiting_inspection_by_name,
                    sb.full_name AS signed_off_by_name, sb.role AS signed_off_by_role
               FROM pm_items i
               JOIN pm_plans p ON p.id = i.plan_id
               LEFT JOIN aircraft_tails t   ON t.id = i.tail_id
               LEFT JOIN components c       ON c.id = i.component_id
               LEFT JOIN aircraft_tails ct  ON ct.id = c.tail_id
               LEFT JOIN users au           ON au.id = i.assigned_to
               LEFT JOIN users wb           ON wb.id = i.awaiting_inspection_by
               LEFT JOIN users sb           ON sb.id = i.signed_off_by
              WHERE i.id = :id'
        );
        $stmt->execute([':id' => $itemId]);
        $r = $stmt->fetch();
        return $r ?: null;
    }
}
