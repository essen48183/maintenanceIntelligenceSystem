<?php
declare(strict_types=1);

namespace MIS\Tests;

/**
 * Minimal zero-dependency test runner.
 *  - Resets the test database (schema + seed) once per run.
 *  - Each test method is a public method on a TestCase subclass starting with "test".
 *  - Run with: /Applications/MAMP/bin/php/php8.3.28/bin/php tests/run.php
 *
 * Why custom (vs PHPUnit)? Avoids a composer dependency for a project that
 * runs in MAMP locally. Tests are still readable and assertion-style.
 */

abstract class TestCase
{
    public int $assertionCount = 0;

    public function setUp(): void {}
    public function tearDown(): void {}

    public function assertSame($expected, $actual, string $msg = ''): void
    {
        $this->assertionCount++;
        if ($expected !== $actual) {
            throw new AssertionFailed(
                "assertSame failed" . ($msg ? " ($msg)" : '')
                . "\n  expected: " . self::describe($expected)
                . "\n  actual:   " . self::describe($actual)
            );
        }
    }
    public function assertTrue($cond, string $msg = ''): void { $this->assertSame(true, (bool) $cond, $msg); }
    public function assertFalse($cond, string $msg = ''): void { $this->assertSame(false, (bool) $cond, $msg); }
    public function assertNull($v, string $msg = ''): void { $this->assertSame(null, $v, $msg); }
    public function assertNotNull($v, string $msg = ''): void
    {
        $this->assertionCount++;
        if ($v === null) throw new AssertionFailed("assertNotNull failed" . ($msg ? " ($msg)" : ''));
    }
    public function assertGreaterThan($floor, $v, string $msg = ''): void
    {
        $this->assertionCount++;
        if (!($v > $floor)) {
            throw new AssertionFailed("assertGreaterThan($floor) failed; got " . self::describe($v) . ($msg ? " ($msg)" : ''));
        }
    }
    public function assertContains($needle, array $haystack, string $msg = ''): void
    {
        $this->assertionCount++;
        if (!in_array($needle, $haystack, true)) {
            throw new AssertionFailed("assertContains failed" . ($msg ? " ($msg)" : ''));
        }
    }
    public function expectException(string $class, callable $fn): void
    {
        $this->assertionCount++;
        try { $fn(); } catch (\Throwable $t) {
            if ($t instanceof $class) return;
            throw new AssertionFailed("expectException $class but got " . get_class($t) . ': ' . $t->getMessage());
        }
        throw new AssertionFailed("expectException $class but no exception was thrown");
    }
    private static function describe($v): string
    {
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
        if (is_object($v)) return get_class($v);
        return var_export($v, true);
    }
}

class AssertionFailed extends \RuntimeException {}

final class Runner
{
    public static function run(array $testClasses): int
    {
        $passed = 0; $failed = 0; $totalAssertions = 0;
        $start = microtime(true);
        foreach ($testClasses as $class) {
            echo "\n\033[1;36m▸ $class\033[0m\n";
            $methods = array_filter(get_class_methods($class), fn($m) => str_starts_with($m, 'test'));
            foreach ($methods as $m) {
                /** @var TestCase $instance */
                $instance = new $class();
                try {
                    $instance->setUp();
                    $instance->$m();
                    $instance->tearDown();
                    echo "  ✓ $m  ({$instance->assertionCount} asserts)\n";
                    $passed++;
                } catch (\Throwable $e) {
                    echo "  \033[1;31m✗ $m\033[0m\n";
                    echo "    " . get_class($e) . ': ' . $e->getMessage() . "\n";
                    if (!($e instanceof AssertionFailed)) {
                        echo "    " . substr($e->getTraceAsString(), 0, 600) . "\n";
                    }
                    $failed++;
                }
                $totalAssertions += $instance->assertionCount;
            }
        }
        $dur = number_format(microtime(true) - $start, 2);
        echo "\n";
        if ($failed === 0) {
            echo "\033[1;32mOK\033[0m  passed=$passed  asserts=$totalAssertions  time={$dur}s\n";
            return 0;
        }
        echo "\033[1;31mFAIL\033[0m  passed=$passed  failed=$failed  asserts=$totalAssertions  time={$dur}s\n";
        return 1;
    }
}
