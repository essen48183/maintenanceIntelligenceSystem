<?php
declare(strict_types=1);

namespace MIS\Tests;

require_once __DIR__ . '/Harness.php';

final class TasksTest extends TestCase
{
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

    public function testCompleteRecordsSignoff(): void
    {
        $id = \MIS\Tasks::add(2, 3, 'Step to be signed off');
        $task = \MIS\Tasks::setStatus($id, 4, 'complete');
        $this->assertSame('complete', $task['status']);
        $this->assertSame(4, (int) $task['completed_by']);
        $this->assertNotNull($task['completed_at']);
    }

    public function testReopeningClearsSignoff(): void
    {
        $id = \MIS\Tasks::add(2, 3, 'Step to reopen');
        \MIS\Tasks::setStatus($id, 4, 'complete');
        $task = \MIS\Tasks::setStatus($id, 4, 'pending');
        $this->assertSame('pending', $task['status']);
        $this->assertNull($task['completed_by']);
        $this->assertNull($task['completed_at']);
    }

    public function testBlockedCarriesHoldupReason(): void
    {
        $id = \MIS\Tasks::add(2, 3, 'Order replacement');
        $task = \MIS\Tasks::setStatus($id, 3, 'blocked', 'Awaiting part — backorder ETA 72h');
        $this->assertSame('blocked', $task['status']);
        $this->assertSame('Awaiting part — backorder ETA 72h', $task['holdup_reason']);
    }

    public function testUnblockingClearsHoldup(): void
    {
        $id = \MIS\Tasks::add(2, 3, 'Order replacement');
        \MIS\Tasks::setStatus($id, 3, 'blocked', 'Awaiting inspection');
        $task = \MIS\Tasks::setStatus($id, 3, 'in_progress');
        $this->assertSame('in_progress', $task['status']);
        $this->assertNull($task['holdup_reason']);
    }

    public function testRejectsInvalidStatus(): void
    {
        $id = \MIS\Tasks::add(2, 3, 'X');
        $this->expectException(\InvalidArgumentException::class, function () use ($id) {
            \MIS\Tasks::setStatus($id, 3, 'banana');
        });
    }

    public function testStatusChangeWritesAudit(): void
    {
        $id = \MIS\Tasks::add(2, 3, 'Auditable task');
        \MIS\Tasks::setStatus($id, 4, 'complete');
        $rows = \MIS\Audit::recentForTarget('task', (string) $id, 10);
        $actions = array_column($rows, 'action');
        $this->assertContains('task.create', $actions);
        $this->assertContains('task.status', $actions);
    }

    public function testTaskTiedToTicketRecordsTicketEvent(): void
    {
        // Ticket 1 in seed is linked to fault 1
        $id = \MIS\Tasks::add(1, 4, 'Bench-test FCC L', ['ticket_id' => 1]);
        \MIS\Tasks::setStatus($id, 4, 'complete');
        $events = \MIS\Tickets::detail(1)['events'];
        // The most recent events should include task add (comment) and task complete (comment)
        $bodies = implode(' || ', array_column($events, 'body'));
        $this->assertTrue(str_contains($bodies, 'Bench-test FCC L'), 'task creation should appear in ticket timeline');
        $this->assertTrue(str_contains($bodies, 'completed'), 'task completion should appear in ticket timeline');
    }
}
