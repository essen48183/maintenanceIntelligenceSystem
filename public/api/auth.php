<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';

$action = $_GET['action'] ?? '';

if ($action === 'login') {
    require_method('POST');
    $body = json_input();
    $u = trim((string) ($body['username'] ?? ''));
    $p = (string) ($body['password'] ?? '');
    if ($u === '' || $p === '') {
        \MIS\Auth::respond(400, ['error' => 'missing_credentials']);
    }
    $user = \MIS\Auth::login($u, $p);
    if (!$user) {
        \MIS\Auth::respond(401, ['error' => 'invalid_credentials']);
    }
    ok(['user' => $user]);
}

if ($action === 'logout') {
    require_method('POST');
    \MIS\Auth::logout();
    ok(['ok' => true]);
}

if ($action === 'me' || $action === '') {
    $user = \MIS\Auth::user();
    ok([
        'authenticated' => $user !== null,
        'user' => $user,
    ]);
}

\MIS\Auth::respond(404, ['error' => 'unknown_action', 'action' => $action]);
