<?php
declare(strict_types=1);

namespace MIS\Tests;

require_once __DIR__ . '/Harness.php';

final class TasksTest extends TestCase
{
    /** Fetch a seed user by username. */
    private function user(string $username): array
    {
        $stmt = \MIS\Db::pdo()->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        return $stmt->fetch() ?: [];
    }

    public function testSeedTasksLoaded(): void
    {
        $tasks = \MIS\Tasks::listForFault(1);
        $this->assertSame(5, count($tasks));
        $statuses = array_column($tasks, 'status');
        $this->assertContains('complete', $statuses);
        $this->assertContains('in_progress', $statuses);
        $this->assertContains('blocked', $statuses);
        $this->assertContains('pending', $statuses);
    }

    public function testCanAddUserTaskAndAppearsAtEnd(): void
    {
        $beforeCount = count(\MIS\Tasks::listForFault(2));
        $id = \MIS\Tasks::add(2, 3, 'Verify ITT probe resistance');
        $list = \MIS\Tasks::listForFault(2);
        $this->assertSame($beforeCount + 1, count($list));
        $last = end($list);
        $this->assertSame($id, (int) $last['id']);
        $this->assertSame('user', $last['source']);
        $this->assertSame('pending', $last['status']);
    }

    public function testAddRejectsEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class, function () {
            \MIS\Tasks::add(1, 3, '   ');
        });
    }

    public function testRtsTechCanSelfSignoff(): void
    {
        // mtech1 has rts_authority=1; completing should land in 'complete'.
        $rtsTech = $this->user('mtech1');
        $id = \MIS\Tasks::add(2, (int) $rtsTech['id'], 'Step to be signed off');
        $task = \MIS\Tasks::setStatus($id, $rtsTech, 'complete');
        $this->assertSame('complete', $task['status']);
        $this->assertSame((int) $rtsTech['id'], (int) $task['completed_by']);
        $this->assertNotNull($task['completed_at']);
    }

    public function testTechWithoutRtsRoutesToInspection(): void
    {
        // mtech2 has rts_authority=0; completing should re-route to awaiting_inspection.
        $tech = $this->user('mtech2');
        $id = \MIS\Tasks::add(2, (int) $tech['id'], 'Step needs sup signoff');
        $task = \MIS\Tasks::setStatus($id, $tech, 'complete');
        $this->assertSame('awaiting_inspection', $task['status'], 'no RTS authority → flagged for inspection');
        $this->assertSame((int) $tech['id'], (int) $task['awaiting_inspection_by']);
        $this->assertNotNull($task['awaiting_inspection_at']);
        $this->assertNull($task['completed_by']);
    }

    public function testSupervisorSignsOffAwaitingInspection(): void
    {
        $tech = $this->user('mtech2');
        $sup  = $this->user('jsupervisor'); // inspection_authority=1
        $id = \MIS\Tasks::add(2, (int) $tech['id'], 'Awaiting sup signoff');
        \MIS\Tasks::setStatus($id, $tech, 'complete'); // → awaiting_inspection
        $task = \MIS\Tasks::setStatus($id, $sup, 'complete'); // sup signs off
        $this->assertSame('complete', $task['status']);
        $this->assertSame((int) $sup['id'], (int) $task['completed_by']);
    }

    public function testTechCannotSignOffSomeoneElsesWork(): void
    {
        $tech1 = $this->user('mtech2');
        $tech2 = $this->user('mtech3'); // also no inspection_authority
        $id = \MIS\Tasks::add(2, (int) $tech1['id'], 'X');
        \MIS\Tasks::setStatus($id, $tech1, 'complete'); // → awaiting_inspection
        $this->expectException(\RuntimeException::class, function () use ($id, $tech2) {
            \MIS\Tasks::setStatus($id, $tech2, 'complete');
        });
    }

    public function testReopeningClearsSignoff(): void
    {
        $rtsTech = $this->user('mtech1');
        $id = \MIS\Tasks::add(2, (int) $rtsTech['id'], 'Step to reopen');
        \MIS\Tasks::setStatus($id, $rtsTech, 'complete');
        $task = \MIS\Tasks::setStatus($id, $rtsTech, 'pending');
        $this->assertSame('pending', $task['status']);
        $this->assertNull($task['completed_by']);
        $this->assertNull($task['completed_at']);
        $this->assertNull($task['awaiting_inspection_by']);
    }

    public function testBlockedCarriesHoldupReason(): void
    {
        $tech = $this->user('mtech1');
        $id = \MIS\Tasks::add(2, (int) $tech['id'], 'Order replacement');
        $task = \MIS\Tasks::setStatus($id, $tech, 'blocked', 'Awaiting part — backorder ETA 72h');
        $this->assertSame('blocked', $task['status']);
        $this->assertSame('Awaiting part — backorder ETA 72h', $task['holdup_reason']);
    }

    public function testUnblockingClearsHoldup(): void
    {
        $tech = $this->user('mtech1');
        $id = \MIS\Tasks::add(2, (int) $tech['id'], 'Order replacement');
        \MIS\Tasks::setStatus($id, $tech, 'blocked', 'Awaiting inspection');
        $task = \MIS\Tasks::setStatus($id, $tech, 'in_progress');
        $this->assertSame('in_progress', $task['status']);
        $this->assertNull($task['holdup_reason']);
    }

    public function testRejectsInvalidStatus(): void
    {
        $tech = $this->user('mtech1');
        $id = \MIS\Tasks::add(2, (int) $tech['id'], 'X');
        $this->expectException(\InvalidArgumentException::class, function () use ($id, $tech) {
            \MIS\Tasks::setStatus($id, $tech, 'banana');
        });
    }

    public function testStatusChangeWritesAudit(): void
    {
        $tech = $this->user('mtech1');
        $id = \MIS\Tasks::add(2, (int) $tech['id'], 'Auditable task');
        \MIS\Tasks::setStatus($id, $tech, 'complete');
        $rows = \MIS\Audit::recentForTarget('task', (string) $id, 10);
        $actions = array_column($rows, 'action');
        $this->assertContains('task.create', $actions);
        $this->assertContains('task.status', $actions);
    }

    public function testTaskTiedToTicketRecordsTicketEvent(): void
    {
        $tech = $this->user('mtech1');
        $id = \MIS\Tasks::add(1, (int) $tech['id'], 'Bench-test FCC L', ['ticket_id' => 1]);
        \MIS\Tasks::setStatus($id, $tech, 'complete');
        $events = \MIS\Tickets::detail(1)['events'];
        $bodies = implode(' || ', array_column($events, 'body'));
        $this->assertTrue(str_contains($bodies, 'Bench-test FCC L'), 'task creation should appear in ticket timeline');
        $this->assertTrue(str_contains($bodies, 'completed'), 'task completion should appear in ticket timeline');
    }

    public function testAwaitingInspectionQueueListsFlagged(): void
    {
        $tech = $this->user('mtech2');
        $id = \MIS\Tasks::add(2, (int) $tech['id'], 'Item that should appear in queue');
        \MIS\Tasks::setStatus($id, $tech, 'complete'); // → awaiting_inspection
        $queue = \MIS\Tasks::awaitingInspection();
        $ids = array_map('intval', array_column($queue, 'id'));
        $this->assertContains($id, $ids);
    }
}
