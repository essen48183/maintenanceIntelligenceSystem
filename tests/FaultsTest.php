<?php
declare(strict_types=1);

namespace MIS\Tests;

require_once __DIR__ . '/Harness.php';

final class FaultsTest extends TestCase
{
    public function testSeedHasFiveActiveFaults(): void
    {
        $list = \MIS\Faults::activeList(1);
        $this->assertSame(5, count($list));
    }

    public function testKpisMatchSeed(): void
    {
        $k = \MIS\Faults::severityCounts(1);
        $this->assertSame(1, $k['CRITICAL']);
        $this->assertSame(2, $k['HIGH']);
        $this->assertSame(1, $k['MEDIUM']);
        $this->assertSame(1, $k['LOW']);
        $this->assertSame(5, $k['TOTAL']);
        $this->assertGreaterThan(0, $k['OCCURRENCES_30D']);
    }

    public function testFaultDetailAggregation(): void
    {
        $f = \MIS\Faults::detail(1);
        $this->assertNotNull($f);
        $this->assertSame('CRJ900-AV-001', $f['fault_code']);
        $this->assertGreaterThan(0, count($f['affected_labels']));
        $this->assertGreaterThan(0, count($f['diagnostic_questions']));
        $this->assertGreaterThan(0, count($f['recent_occurrences']));
        $this->assertGreaterThan(0, count($f['reference_documents']));
    }

    public function testSeverityOrdering(): void
    {
        $list = \MIS\Faults::activeList(1);
        $this->assertSame('CRITICAL', $list[0]['severity'], 'CRITICAL should sort first');
    }

    public function testUnknownFaultReturnsNull(): void
    {
        $this->assertNull(\MIS\Faults::detail(999999));
    }
}
