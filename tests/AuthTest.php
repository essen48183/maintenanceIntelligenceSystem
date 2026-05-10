<?php
declare(strict_types=1);

namespace MIS\Tests;

require_once __DIR__ . '/Harness.php';

final class AuthTest extends TestCase
{
    public function testSeedUsersExist(): void
    {
        $rows = \MIS\Db::pdo()->query('SELECT username, role FROM users ORDER BY id')->fetchAll();
        $usernames = array_column($rows, 'username');
        $this->assertContains('admin', $usernames);
        $this->assertContains('jsupervisor', $usernames);
        $this->assertContains('mtech1', $usernames);
        $this->assertContains('rsmith', $usernames, 'pilot readonly user must exist');
        $this->assertContains('qchen', $usernames, 'corporate readonly user must exist');
    }

    public function testCanWriteRoles(): void
    {
        $this->assertTrue(\MIS\Auth::canWrite(['role' => 'admin']));
        $this->assertTrue(\MIS\Auth::canWrite(['role' => 'supervisor']));
        $this->assertTrue(\MIS\Auth::canWrite(['role' => 'maintenance']));
        $this->assertFalse(\MIS\Auth::canWrite(['role' => 'readonly']));
    }

    public function testPasswordHashesVerifyForSeedAccount(): void
    {
        $row = \MIS\Db::pdo()->query("SELECT password_hash FROM users WHERE username='mtech1'")->fetch();
        $this->assertNotNull($row, 'mtech1 row');
        $this->assertTrue(password_verify('ChangeMe!123', $row['password_hash']));
        $this->assertFalse(password_verify('wrong', $row['password_hash']));
    }
}
