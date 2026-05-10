<?php
declare(strict_types=1);

namespace MIS;

final class Audit
{
    public static function log(
        ?int $userId,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $details = null
    ): void {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, target_type, target_id, ip_address, user_agent, details)
             VALUES (:uid, :act, :ttype, :tid, :ip, :ua, :details)'
        );
        $stmt->execute([
            ':uid'     => $userId,
            ':act'     => $action,
            ':ttype'   => $targetType,
            ':tid'     => $targetId,
            ':ip'      => $_SERVER['REMOTE_ADDR']     ?? null,
            ':ua'      => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ':details' => $details === null ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** Recent audit entries for a target. Used to show activity for a ticket/fault. */
    public static function recentForTarget(string $targetType, string $targetId, int $limit = 50): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT al.*, u.full_name, u.role
             FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
             WHERE al.target_type = :t AND al.target_id = :id
             ORDER BY al.created_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([':t' => $targetType, ':id' => $targetId]);
        return $stmt->fetchAll();
    }
}
