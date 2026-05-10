<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';

$user = \MIS\Auth::require();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    ok(['tickets' => \MIS\Tickets::list([
        'status' => $_GET['status'] ?? null,
        'assigned_to' => isset($_GET['assigned_to']) ? (int) $_GET['assigned_to'] : null,
    ])]);
}

if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) \MIS\Auth::respond(400, ['error' => 'missing_id']);
    $t = \MIS\Tickets::detail($id);
    if (!$t) \MIS\Auth::respond(404, ['error' => 'not_found']);
    \MIS\Audit::log((int) $user['id'], 'ticket.view', 'ticket', (string) $id);
    ok(['ticket' => $t]);
}

// Mutating actions — readonly is blocked
if (!\MIS\Auth::canWrite($user)) {
    \MIS\Auth::respond(403, ['error' => 'forbidden_role', 'role' => $user['role']]);
}

if ($action === 'create') {
    require_method('POST');
    $body = json_input();
    if (empty($body['title'])) \MIS\Auth::respond(400, ['error' => 'title_required']);
    $id = \MIS\Tickets::create((int) $user['id'], $body);
    ok(['ticket_id' => $id]);
}

if ($action === 'comment') {
    require_method('POST');
    $body = json_input();
    $id = (int) ($body['ticket_id'] ?? 0);
    if ($id < 1) \MIS\Auth::respond(400, ['error' => 'missing_ticket_id']);
    $eid = \MIS\Tickets::addEvent($id, (int) $user['id'], 'comment', (string) ($body['body'] ?? ''));
    ok(['event_id' => $eid]);
}

if ($action === 'reassign') {
    require_method('POST');
    $body = json_input();
    $id = (int) ($body['ticket_id'] ?? 0);
    $to = (int) ($body['to_user'] ?? 0);
    if ($id < 1 || $to < 1) \MIS\Auth::respond(400, ['error' => 'missing_args']);
    \MIS\Tickets::reassign($id, (int) $user['id'], $to, $body['note'] ?? null);
    ok(['ok' => true]);
}

if ($action === 'status') {
    require_method('POST');
    $body = json_input();
    $id = (int) ($body['ticket_id'] ?? 0);
    $st = (string) ($body['status'] ?? '');
    if ($id < 1 || $st === '') \MIS\Auth::respond(400, ['error' => 'missing_args']);
    try {
        \MIS\Tickets::setStatus($id, $user, $st, $body['note'] ?? null);
    } catch (\RuntimeException $e) {
        $code = $e->getMessage() === 'parent_has_open_children' ? 409 : 404;
        \MIS\Auth::respond($code, ['error' => $e->getMessage()]);
    }
    ok(['ok' => true]);
}

if ($action === 'children') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) \MIS\Auth::respond(400, ['error' => 'missing_id']);
    ok(['children' => \MIS\Tickets::children($id)]);
}

\MIS\Auth::respond(404, ['error' => 'unknown_action']);
