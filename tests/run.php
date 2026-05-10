<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/AuthTest.php';
require_once __DIR__ . '/FaultsTest.php';
require_once __DIR__ . '/TicketsTest.php';
require_once __DIR__ . '/AuditTest.php';

exit(\MIS\Tests\Runner::run([
    \MIS\Tests\AuthTest::class,
    \MIS\Tests\FaultsTest::class,
    \MIS\Tests\TicketsTest::class,
    \MIS\Tests\AuditTest::class,
]));
