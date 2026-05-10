<?php
declare(strict_types=1);

namespace MIS\Tests;

require_once __DIR__ . '/Harness.php';

final class TicketsTest extends TestCase
{
    public function testCreateTicketAndEvent(): void
    {
        $id = \MIS\Tickets::create(3, [
            'title' => 'Test ticket',
            'severity' => 'HIGH',
            'fault_id' => 1,
            'tail_id'  => 1,
            'initial_comment' => 'Opened by mtech1.',
        ]);
        $this->assertGreaterThan(0, $id);
        $t = \MIS\Tickets::detail($id);
        $this->assertNotNull($t);
        $this->assertSame('open', $t['status']);
        $this->assertSame('HIGH', $t['severity']);
        $this->assertSame(1, count($t['events']), 'ticket should have a single created-event');
        $this->assertSame('created', $t['events'][0]['event_type']);
    }

    public function testShiftHandoff(): void
    {
        // mtech1 (id 3) creates → reassigns to mtech2 (id 4, swing shift)
        $id = \MIS\Tickets::create(3, ['title' => 'Handoff test']);
        \MIS\Tickets::addEvent($id, 3, 'comment', 'Did diag step A.');
        \MIS\Tickets::reassign($id, 3, 4, 'Handing off to swing — shift change.');
        \MIS\Tickets::addEvent($id, 4, 'comment', 'Picking it up — will continue diag step B.');

        $t = \MIS\Tickets::detail($id);
        $this->assertSame(4, (int) $t['assigned_to'], 'reassigned to mtech2');

        $eventTypes = array_column($t['events'], 'event_type');
        $this->assertSame(['created', 'comment', 'reassigned', 'comment'], $eventTypes);

        $reassign = $t['events'][2];
        $this->assertSame('reassigned', $reassign['event_type']);
        $meta = json_decode($reassign['metadata'], true);
        $this->assertSame(true, $meta['shift_handoff'] ?? null);
        $this->assertSame(3, (int) $meta['from_user']);
        $this->assertSame(4, (int) $meta['to_user']);
    }

    public function testStatusChangeWritesAuditAndEvent(): void
    {
        $id = \MIS\Tickets::create(3, ['title' => 'Status test']);
        $mtech2 = \MIS\Db::pdo()->query("SELECT * FROM users WHERE username='mtech2'")->fetch();
        \MIS\Tickets::setStatus($id, $mtech2, 'in_progress', 'Starting work.');
        \MIS\Tickets::setStatus($id, $mtech2, 'closed', 'All checks pass.');

        $t = \MIS\Tickets::detail($id);
        $this->assertSame('closed', $t['status']);
        $this->assertSame(4, (int) $t['closed_by']);

        // Audit log should contain at least 2 entries for status changes
        $rows = \MIS\Audit::recentForTarget('ticket', (string) $id, 50);
        $actions = array_column($rows, 'action');
        $this->assertContains('ticket.event.status_changed', $actions);
        $this->assertContains('ticket.create', $actions);
    }

    public function testRejectsInvalidStatus(): void
    {
        $id = \MIS\Tickets::create(3, ['title' => 'Bad status']);
        $admin = \MIS\Db::pdo()->query("SELECT * FROM users WHERE username='admin'")->fetch();
        $this->expectException(\InvalidArgumentException::class, function () use ($id, $admin) {
            \MIS\Tickets::setStatus($id, $admin, 'banana');
        });
    }

    public function testParentCannotCloseUntilChildrenClosed(): void
    {
        $admin = \MIS\Db::pdo()->query("SELECT * FROM users WHERE username='admin'")->fetch();
        // Seed has TKT-2026-0001 (id=1) as parent with two children id=10, 11
        $this->expectException(\RuntimeException::class, function () use ($admin) {
            \MIS\Tickets::setStatus(1, $admin, 'closed', 'Try to close early');
        });
    }

    public function testParentClosesOnceAllChildrenClosed(): void
    {
        $admin = \MIS\Db::pdo()->query("SELECT * FROM users WHERE username='admin'")->fetch();
        \MIS\Tickets::setStatus(10, $admin, 'closed', 'N901XX done');
        \MIS\Tickets::setStatus(11, $admin, 'closed', 'N902XX done');
        \MIS\Tickets::setStatus(1,  $admin, 'closed', 'Roll-up complete');
        $parent = \MIS\Tickets::detail(1);
        $this->assertSame('closed', $parent['status']);
        $this->assertSame(2, $parent['children_total']);
        $this->assertSame(2, $parent['children_closed']);
    }

    public function testAiCannotCloseTicket(): void
    {
        // Foundational: Auth::assertHumanActor must reject any non-human caller.
        $this->expectException(\RuntimeException::class, function () {
            \MIS\Tickets::setStatus(2, ['id' => 0, 'role' => 'ai'], 'closed');
        });
    }

    public function testTicketNumberIsUniqueAndYearScoped(): void
    {
        $a = \MIS\Tickets::create(3, ['title' => 'A']);
        $b = \MIS\Tickets::create(3, ['title' => 'B']);
        $da = \MIS\Tickets::detail($a);
        $db = \MIS\Tickets::detail($b);
        $this->assertTrue($da['ticket_number'] !== $db['ticket_number']);
        $this->assertTrue(str_starts_with($da['ticket_number'], 'TKT-' . date('Y') . '-'));
    }
}
