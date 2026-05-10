<?php
declare(strict_types=1);

// Load app
require_once dirname(__DIR__) . '/src/Bootstrap.php';
require_once __DIR__ . '/Harness.php';

// Re-bootstrap against the *test* database
\MIS\Bootstrap::init('db_test');

// Reset schema + seed before every run so tests start from a known state
$cfg = \MIS\Bootstrap::$config['db_test'];
$mysql = $_ENV['MYSQL'] ?? '/Applications/MAMP/Library/bin/mysql80/bin/mysql';
if (!is_executable($mysql)) {
    foreach (['/Applications/MAMP/Library/bin/mysql57/bin/mysql', trim((string) shell_exec('command -v mysql 2>/dev/null'))] as $alt) {
        if ($alt && is_executable($alt)) { $mysql = $alt; break; }
    }
}
$conn = ['-u' . escapeshellarg($cfg['user']), '-p' . escapeshellarg($cfg['password'])];
if (!empty($cfg['socket']) && file_exists($cfg['socket'])) {
    $conn[] = '--socket=' . escapeshellarg($cfg['socket']);
} else {
    $conn[] = '-h' . escapeshellarg($cfg['host']) . ' -P' . escapeshellarg((string) $cfg['port']);
}
$connStr = implode(' ', $conn) . ' ' . escapeshellarg($cfg['name']);
$schemaSql = dirname(__DIR__) . '/db/schema.sql';
$seedSql   = dirname(__DIR__) . '/db/seed.sql';
$cmd = "$mysql $connStr < " . escapeshellarg($schemaSql) . " 2>&1; $mysql $connStr < " . escapeshellarg($seedSql) . " 2>&1";
$out = shell_exec($cmd);
$out = (string) $out;
// Strip benign warning lines so test output stays clean
$out = preg_replace('/^.*Using a password on the command line interface.*$/m', '', $out);
if (trim((string) $out) !== '') {
    fwrite(STDERR, "[bootstrap] DB reset output:\n$out\n");
}
\MIS\Db::reset();
