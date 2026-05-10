<?php
declare(strict_types=1);

namespace MIS\Tests;

require_once __DIR__ . '/Harness.php';

final class PMTest extends TestCase
{
    private function user(string $username): array
    {
        $stmt = \MIS\Db::pdo()->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        return $stmt->fetch() ?: [];
    }

    public function testSeedHasMixedComplianceStates(): void
    {
        $counts = \MIS\PM::statusCounts();
        $this->assertGreaterThan(0, $counts['overdue']);
        $this->assertGreaterThan(0, $counts['due_soon']);
        $this->assertGreaterThan(0, $counts['current']);
        $this->assertGreaterThan(0, $counts['in_progress']);
        $this->assertGreaterThan(0, $counts['awaiting_inspection']);
    }

    public function testForTailsIncludesComponentItems(): void
    {
        // Tail 1 has tail-targeted plans (FCC, AFCS, etc.) AND component-targeted
        // plans (engines L+R, APU). Both should come back.
        $rows = \MIS\PM::forTails([1]);
        $this->assertGreaterThan(3, count($rows), 'tail 1 should have multiple PM items');
        $kinds = array_unique(array_column($rows, 'target_kind'));
        $this->assertContains('tail', $kinds);
        $this->assertContains('component', $kinds);
    }

    public function testRtsTechSelfSignsOffPlanWithoutInspection(): void
    {
        // Plan 2 (oil sample) does NOT require_inspection; mtech1 has rts_authority=1.
        $rtsTech = $this->user('mtech1');
        $pdo = \MIS\Db::pdo();
        $itemId = (int) $pdo->query("SELECT id FROM pm_items WHERE plan_id=2 ORDER BY id LIMIT 1")->fetchColumn();
        $r = \MIS\PM::complete($itemId, $rtsTech, 'Sample shipped to lab');
        $this->assertSame('complete', $r['status']);
        $this->assertSame((int) $rtsTech['id'], (int) $r['signed_off_by']);
    }

    public function testRequiresInspectionAlwaysRoutesToInspection(): void
    {
        // Plan 1 (engine borescope) requires inspection — even an RTS tech is routed.
        $rtsTech = $this->user('mtech1');
        $pdo = \MIS\Db::pdo();
        $itemId = (int) $pdo->query("SELECT id FROM pm_items WHERE plan_id=1 AND status='current' ORDER BY id LIMIT 1")->fetchColumn();
        $r = \MIS\PM::complete($itemId, $rtsTech, null);
        $this->assertSame('awaiting_inspection', $r['status']);
        $this->assertSame((int) $rtsTech['id'], (int) $r['awaiting_inspection_by']);
    }

    public function testSupervisorSignsOffPmInspectionQueue(): void
    {
        // The seed has one item already awaiting_inspection (engine borescope on N903XX R)
        $awaiting = \MIS\PM::awaitingInspection();
        $this->assertGreaterThan(0, count($awaiting));
        $sup = $this->user('jsupervisor');
        $first = $awaiting[0];
        $r = \MIS\PM::signOff((int) $first['id'], $sup, 'Findings reviewed and approved');
        $this->assertSame('complete', $r['status']);
        $this->assertSame((int) $sup['id'], (int) $r['signed_off_by']);
    }

    public function testNonInspectorCannotSignOff(): void
    {
        $awaiting = \MIS\PM::awaitingInspection();
        $first = $awaiting[0];
        $tech = $this->user('mtech2');
        $this->expectException(\RuntimeException::class, function () use ($first, $tech) {
            \MIS\PM::signOff((int) $first['id'], $tech);
        });
    }

    public function testRejectSendsBackToInProgress(): void
    {
        $awaiting = \MIS\PM::awaitingInspection();
        $first = $awaiting[0];
        $sup = $this->user('jsupervisor');
        $r = \MIS\PM::reject((int) $first['id'], $sup, 'Rework — torque values not documented');
        $this->assertSame('in_progress', $r['status']);
        $this->assertNull($r['awaiting_inspection_at']);
    }
}
