<?php
declare(strict_types=1);

namespace MIS;

final class Users
{
    public static function listAll(bool $includeInactive = true): array
    {
        $pdo = Db::pdo();
        $sql = 'SELECT id, employee_id, username, full_name, email, role, station, shift,
                       rts_authority, inspection_authority, is_active, last_login_at, created_at
                  FROM users';
        if (!$includeInactive) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY is_active DESC, role, full_name';
        return $pdo->query($sql)->fetchAll();
    }

    public static function create(int $actingAdminId, array $input): int
    {
        self::validate($input, true);
        $pdo = Db::pdo();
        $hash = password_hash((string) $input['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare(
            'INSERT INTO users (employee_id, username, password_hash, full_name, email, role, station, shift,
                                rts_authority, inspection_authority, is_active)
             VALUES (:eid, :un, :pw, :fn, :em, :role, :stn, :sh, :rts, :insp, 1)'
        );
        $stmt->execute([
            ':eid' => $input['employee_id'],
            ':un'  => $input['username'],
            ':pw'  => $hash,
            ':fn'  => $input['full_name'],
            ':em'  => $input['email'] ?? null,
            ':role'=> $input['role'],
            ':stn' => $input['station'] ?? null,
            ':sh'  => $input['shift']   ?? null,
            ':rts' => !empty($input['rts_authority']) ? 1 : 0,
            ':insp'=> !empty($input['inspection_authority']) ? 1 : 0,
        ]);
        $id = (int) $pdo->lastInsertId();
        Audit::log($actingAdminId, 'user.create', 'user', (string) $id, [
            'username' => $input['username'],
            'role'     => $input['role'],
            'rts'      => !empty($input['rts_authority']),
            'inspection' => !empty($input['inspection_authority']),
        ]);
        return $id;
    }

    public static function update(int $actingAdminId, int $userId, array $input): array
    {
        $pdo = Db::pdo();
        $cur = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $cur->execute([':id' => $userId]);
        $existing = $cur->fetch();
        if (!$existing) throw new \RuntimeException('user_not_found');

        // Self-protection: an admin cannot strip their own admin role
        if ($actingAdminId === $userId && isset($input['role']) && $input['role'] !== 'admin') {
            throw new \RuntimeException('cannot_demote_self');
        }

        $allowed = ['full_name','email','role','station','shift','rts_authority','inspection_authority','employee_id'];
        $sets = []; $params = [':id' => $userId]; $diff = [];
        foreach ($allowed as $f) {
            if (!array_key_exists($f, $input)) continue;
            $val = $input[$f];
            if ($f === 'rts_authority' || $f === 'inspection_authority') {
                $val = !empty($val) ? 1 : 0;
            }
            if ((string) $existing[$f] === (string) $val) continue;
            $sets[] = "$f = :$f";
            $params[":$f"] = $val;
            $diff[$f] = ['from' => $existing[$f], 'to' => $val];
        }
        if (!$sets) return self::byId($userId);

        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);
        Audit::log($actingAdminId, 'user.update', 'user', (string) $userId, ['diff' => $diff]);
        return self::byId($userId);
    }

    public static function resetPassword(int $actingAdminId, int $userId, string $newPassword): void
    {
        if (mb_strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('password_too_short');
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo = Db::pdo();
        $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
            ->execute([':h' => $hash, ':id' => $userId]);
        // Revoke existing sessions for that user (force re-login).
        $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = :id AND revoked_at IS NULL')
            ->execute([':id' => $userId]);
        Audit::log($actingAdminId, 'user.password_reset', 'user', (string) $userId);
    }

    public static function deactivate(int $actingAdminId, int $userId): array
    {
        if ($actingAdminId === $userId) {
            throw new \RuntimeException('cannot_deactivate_self');
        }
        $pdo = Db::pdo();
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id')->execute([':id' => $userId]);
        $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = :id AND revoked_at IS NULL')
            ->execute([':id' => $userId]);
        Audit::log($actingAdminId, 'user.deactivate', 'user', (string) $userId);
        return self::byId($userId);
    }

    public static function reactivate(int $actingAdminId, int $userId): array
    {
        $pdo = Db::pdo();
        $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :id')->execute([':id' => $userId]);
        Audit::log($actingAdminId, 'user.reactivate', 'user', (string) $userId);
        return self::byId($userId);
    }

    public static function byId(int $userId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, employee_id, username, full_name, email, role, station, shift,
                    rts_authority, inspection_authority, is_active, last_login_at, created_at
               FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $r = $stmt->fetch();
        if (!$r) throw new \RuntimeException('user_not_found');
        return $r;
    }

    private static function validate(array $input, bool $forCreate): void
    {
        $required = $forCreate ? ['employee_id','username','password','full_name','role'] : [];
        foreach ($required as $k) {
            if (empty($input[$k]) || !is_string($input[$k]) || trim($input[$k]) === '') {
                throw new \InvalidArgumentException("missing_$k");
            }
        }
        if (isset($input['role']) && !in_array($input['role'], Auth::ROLES, true)) {
            throw new \InvalidArgumentException('invalid_role');
        }
        if ($forCreate && mb_strlen((string) $input['password']) < 8) {
            throw new \InvalidArgumentException('password_too_short');
        }
        if (!empty($input['username']) && !preg_match('/^[a-z0-9_.\-]{2,64}$/i', $input['username'])) {
            throw new \InvalidArgumentException('invalid_username');
        }
    }
}
