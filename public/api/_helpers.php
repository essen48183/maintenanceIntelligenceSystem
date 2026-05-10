<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Bootstrap.php';
\MIS\Bootstrap::init();

function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function ok(array $data): void
{
    \MIS\Auth::respond(200, $data);
}

function require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        \MIS\Auth::respond(405, ['error' => 'method_not_allowed', 'expected' => $method]);
    }
}
