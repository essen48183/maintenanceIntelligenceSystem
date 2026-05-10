<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';

// Admin only — no other role is permitted any action here.
$user = \MIS\Auth::require(['admin']);
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    ok(['users' => \MIS\Users::listAll(true)]);
}

if ($action === 'create') {
    require_method('POST');
    $body = json_input();
    try {
        $id = \MIS\Users::create((int) $user['id'], $body);
    } catch (\InvalidArgumentException $e) {
        \MIS\Auth::respond(400, ['error' => $e->getMessage()]);
    } catch (\PDOException $e) {
        // Likely a unique-key collision on username or employee_id
        $msg = $e->getMessage();
        $code = (str_contains($msg, 'Duplicate entry') ? 'duplicate' : 'db_error');
        \MIS\Auth::respond(409, ['error' => $code, 'detail' => $msg]);
    }
    ok(['user_id' => $id, 'user' => \MIS\Users::byId($id)]);
}

if ($action === 'update') {
    require_method('POST');
    $body = json_input();
    $userId = (int) ($body['user_id'] ?? 0);
    if ($userId < 1) \MIS\Auth::respond(400, ['error' => 'missing_user_id']);
    try {
        $r = \MIS\Users::update((int) $user['id'], $userId, $body);
    } catch (\InvalidArgumentException $e) {
        \MIS\Auth::respond(400, ['error' => $e->getMessage()]);
    } catch (\RuntimeException $e) {
        $code = $e->getMessage() === 'cannot_demote_self' ? 403 : 404;
        \MIS\Auth::respond($code, ['error' => $e->getMessage()]);
    }
    ok(['user' => $r]);
}

if ($action === 'reset_password') {
    require_method('POST');
    $body = json_input();
    $userId = (int) ($body['user_id'] ?? 0);
    $pw = (string) ($body['password'] ?? '');
    if ($userId < 1 || $pw === '') \MIS\Auth::respond(400, ['error' => 'missing_fields']);
    try {
        \MIS\Users::resetPassword((int) $user['id'], $userId, $pw);
    } catch (\InvalidArgumentException $e) {
        \MIS\Auth::respond(400, ['error' => $e->getMessage()]);
    }
    ok(['ok' => true]);
}

if ($action === 'deactivate') {
    require_method('POST');
    $body = json_input();
    $userId = (int) ($body['user_id'] ?? 0);
    if ($userId < 1) \MIS\Auth::respond(400, ['error' => 'missing_user_id']);
    try {
        $r = \MIS\Users::deactivate((int) $user['id'], $userId);
    } catch (\RuntimeException $e) {
        \MIS\Auth::respond(403, ['error' => $e->getMessage()]);
    }
    ok(['user' => $r]);
}

if ($action === 'reactivate') {
    require_method('POST');
    $body = json_input();
    $userId = (int) ($body['user_id'] ?? 0);
    if ($userId < 1) \MIS\Auth::respond(400, ['error' => 'missing_user_id']);
    $r = \MIS\Users::reactivate((int) $user['id'], $userId);
    ok(['user' => $r]);
}

\MIS\Auth::respond(404, ['error' => 'unknown_action']);
