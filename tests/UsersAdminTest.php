<?php
declare(strict_types=1);

namespace MIS\Tests;

require_once __DIR__ . '/Harness.php';

final class UsersAdminTest extends TestCase
{
    private function user(string $username): array
    {
        $stmt = \MIS\Db::pdo()->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        return $stmt->fetch() ?: [];
    }

    public function testCreateUserHashesPassword(): void
    {
        $admin = $this->user('admin');
        $id = \MIS\Users::create((int) $admin['id'], [
            'employee_id' => 'E9001',
            'username'    => 'newtech1',
            'password'    => 'StartPass!1',
            'full_name'   => 'New Hire',
            'role'        => 'maintenance',
            'rts_authority' => 0,
        ]);
        $this->assertGreaterThan(0, $id);
        $row = \MIS\Db::pdo()->query("SELECT password_hash FROM users WHERE id = $id")->fetch();
        $this->assertTrue(password_verify('StartPass!1', $row['password_hash']));
        $this->assertFalse(password_verify('wrong', $row['password_hash']));
    }

    public function testCannotCreateWithShortPassword(): void
    {
        $admin = $this->user('admin');
        $this->expectException(\InvalidArgumentException::class, function () use ($admin) {
            \MIS\Users::create((int) $admin['id'], [
                'employee_id' => 'E9002', 'username' => 'short', 'password' => 'short',
                'full_name' => 'X', 'role' => 'maintenance',
            ]);
        });
    }

    public function testCannotCreateWithInvalidRole(): void
    {
        $admin = $this->user('admin');
        $this->expectException(\InvalidArgumentException::class, function () use ($admin) {
            \MIS\Users::create((int) $admin['id'], [
                'employee_id' => 'E9003', 'username' => 'bad', 'password' => 'LongEnough!1',
                'full_name' => 'X', 'role' => 'wizard',
            ]);
        });
    }

    public function testUpdateUserAuthorityFlags(): void
    {
        $admin = $this->user('admin');
        $tech = $this->user('mtech2');
        $r = \MIS\Users::update((int) $admin['id'], (int) $tech['id'], [
            'rts_authority' => 1,
            'inspection_authority' => 1,
        ]);
        $this->assertSame(1, (int) $r['rts_authority']);
        $this->assertSame(1, (int) $r['inspection_authority']);
    }

    public function testAdminCannotDemoteSelf(): void
    {
        $admin = $this->user('admin');
        $this->expectException(\RuntimeException::class, function () use ($admin) {
            \MIS\Users::update((int) $admin['id'], (int) $admin['id'], ['role' => 'supervisor']);
        });
    }

    public function testAdminCannotDeactivateSelf(): void
    {
        $admin = $this->user('admin');
        $this->expectException(\RuntimeException::class, function () use ($admin) {
            \MIS\Users::deactivate((int) $admin['id'], (int) $admin['id']);
        });
    }

    public function testDeactivateAndReactivate(): void
    {
        $admin = $this->user('admin');
        $tech = $this->user('mtech3');
        $r = \MIS\Users::deactivate((int) $admin['id'], (int) $tech['id']);
        $this->assertSame(0, (int) $r['is_active']);
        $r = \MIS\Users::reactivate((int) $admin['id'], (int) $tech['id']);
        $this->assertSame(1, (int) $r['is_active']);
    }

    public function testResetPasswordRevokesSessions(): void
    {
        $admin = $this->user('admin');
        $tech = $this->user('mtech1');
        // simulate an active session
        $pdo = \MIS\Db::pdo();
        $sid = bin2hex(random_bytes(16));
        $pdo->prepare(
            'INSERT INTO user_sessions (id, user_id, expires_at) VALUES (:sid, :u, DATE_ADD(NOW(), INTERVAL 8 HOUR))'
        )->execute([':sid' => $sid, ':u' => (int) $tech['id']]);

        \MIS\Users::resetPassword((int) $admin['id'], (int) $tech['id'], 'NewPass!1234');
        $row = $pdo->query("SELECT revoked_at FROM user_sessions WHERE id = '$sid'")->fetch();
        $this->assertNotNull($row['revoked_at'], 'existing session should be revoked');
    }
}
