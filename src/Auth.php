<?php
declare(strict_types=1);

namespace MIS;

final class Auth
{
    public const ROLES = ['admin', 'supervisor', 'maintenance', 'readonly'];

    /** Roles permitted to do *write* operations. Readonly is excluded. */
    public const WRITE_ROLES = ['admin', 'supervisor', 'maintenance'];

    public static function start(array $authConfig): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name($authConfig['session_name'] ?? 'mis_session');
        session_set_cookie_params([
            'lifetime' => $authConfig['session_lifetime'] ?? 28800,
            'path'     => '/',
            'secure'   => (bool) ($authConfig['cookie_secure'] ?? false),
            'httponly' => true,
            'samesite' => $authConfig['cookie_samesite'] ?? 'Lax',
        ]);
        session_start();
    }

    /**
     * Verify credentials and create a session. Returns user array on success, null on failure.
     */
    public static function login(string $username, string $password): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();
        if (!$user) {
            Audit::log(null, 'login.failed', 'user', $username, ['reason' => 'no_such_user']);
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            Audit::log((int) $user['id'], 'login.failed', 'user', (string) $user['id'], ['reason' => 'bad_password']);
            return null;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $up = $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
            $up->execute([':h' => $newHash, ':id' => $user['id']]);
        }
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute([':id' => $user['id']]);

        // Regenerate session id; rotate to prevent fixation
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role']    = $user['role'];
        $_SESSION['name']    = $user['full_name'];

        // Persistent server-side session record
        $sid = session_id();
        $exp = (new \DateTimeImmutable('+8 hours'))->format('Y-m-d H:i:s');
        $sess = $pdo->prepare(
            'INSERT INTO user_sessions (id, user_id, ip_address, user_agent, expires_at)
             VALUES (:sid, :uid, :ip, :ua, :exp)'
        );
        $sess->execute([
            ':sid' => $sid,
            ':uid' => $user['id'],
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ':exp' => $exp,
        ]);

        Audit::log((int) $user['id'], 'login.success', 'user', (string) $user['id']);
        unset($user['password_hash']);
        return $user;
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $uid = $_SESSION['user_id'] ?? null;
        $sid = session_id();
        if ($sid) {
            try {
                $pdo = Db::pdo();
                $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE id = :sid')
                    ->execute([':sid' => $sid]);
            } catch (\Throwable $e) {
                // tolerate — logging out should never error
            }
        }
        if ($uid) {
            Audit::log((int) $uid, 'logout', 'user', (string) $uid);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, employee_id, username, full_name, email, role, station, shift,
                    rts_authority, inspection_authority
               FROM users WHERE id = :id AND is_active = 1'
        );
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $u = $stmt->fetch();
        return $u ?: null;
    }

    public static function require(?array $rolesAllowed = null): array
    {
        $u = self::user();
        if (!$u) {
            self::respond(401, ['error' => 'authentication_required']);
        }
        if ($rolesAllowed !== null && !in_array($u['role'], $rolesAllowed, true)) {
            Audit::log((int) $u['id'], 'access.denied', 'role', $u['role']);
            self::respond(403, ['error' => 'forbidden', 'role' => $u['role']]);
        }
        return $u;
    }

    public static function canWrite(array $user): bool
    {
        return in_array($user['role'], self::WRITE_ROLES, true);
    }

    /**
     * Enforce that the actor on a state-changing operation is a real,
     * authenticated human user. Foundational constraint:
     *
     *   The AI assistant NEVER completes a task, signs off a PM item,
     *   returns an aircraft to service, or closes a ticket. Only humans
     *   can be held accountable for state changes that affect
     *   airworthiness.
     *
     * Every Tasks/PM/Tickets state-change method calls this before
     * mutating. If a future change introduces a code path that mutates
     * without a real user (autonomous job, AI tool call, system process),
     * this check stops it cold.
     */
    public static function assertHumanActor(?array $user): void
    {
        if (!$user || empty($user['id']) || (int) $user['id'] < 1) {
            throw new \RuntimeException('human_actor_required');
        }
        // Defence in depth: refuse roles that aren't in the registered set.
        if (empty($user['role']) || !in_array($user['role'], self::ROLES, true)) {
            throw new \RuntimeException('human_actor_required');
        }
    }

    /** Convenience JSON responder (used by API endpoints). */
    public static function respond(int $code, array $payload): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
