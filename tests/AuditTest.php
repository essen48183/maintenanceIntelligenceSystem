<?php
declare(strict_types=1);

namespace MIS\Tests;

require_once __DIR__ . '/Harness.php';

final class AuditTest extends TestCase
{
    public function testAuditWriteRoundtrip(): void
    {
        \MIS\Audit::log(3, 'test.action', 'thing', '42', ['k' => 'v']);
        $rows = \MIS\Audit::recentForTarget('thing', '42', 5);
        $this->assertSame(1, count($rows));
        $this->assertSame('test.action', $rows[0]['action']);
        $details = json_decode($rows[0]['details'], true);
        $this->assertSame('v', $details['k']);
    }

    public function testTicketEventsWriteAuditEntries(): void
    {
        $id = \MIS\Tickets::create(3, ['title' => 'Audit chain test']);
        \MIS\Tickets::addEvent($id, 3, 'comment', 'note 1');
        \MIS\Tickets::reassign($id, 3, 4, 'handing off');

        $rows = \MIS\Audit::recentForTarget('ticket', (string) $id, 50);
        $actions = array_column($rows, 'action');
        $this->assertContains('ticket.create', $actions);
        $this->assertContains('ticket.event.comment', $actions);
        $this->assertContains('ticket.event.reassigned', $actions);
    }
}
