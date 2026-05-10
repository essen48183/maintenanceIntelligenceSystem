<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';

$user = \MIS\Auth::require();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $tailIds = [];
    if (!empty($_GET['tail_ids'])) {
        $tailIds = array_filter(array_map('intval', explode(',', (string) $_GET['tail_ids'])));
    } elseif (!empty($_GET['fault_id'])) {
        // Resolve tails affected by this fault from occurrences
        $pdo = \MIS\Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT tail_id FROM fault_occurrences WHERE fault_id = :fid'
        );
        $stmt->execute([':fid' => (int) $_GET['fault_id']]);
        $tailIds = array_map('intval', array_column($stmt->fetchAll(), 'tail_id'));
    }
    ok([
        'items'  => \MIS\PM::forTails($tailIds),
        'counts' => \MIS\PM::statusCounts($tailIds ?: null),
    ]);
}

if ($action === 'awaiting') {
    ok(['items' => \MIS\PM::awaitingInspection()]);
}

// Mutating actions require write role
if (!\MIS\Auth::canWrite($user)) {
    \MIS\Auth::respond(403, ['error' => 'forbidden_role', 'role' => $user['role']]);
}

if ($action === 'start') {
    require_method('POST');
    $body = json_input();
    $itemId = (int) ($body['item_id'] ?? 0);
    if ($itemId < 1) \MIS\Auth::respond(400, ['error' => 'missing_item_id']);
    $r = \MIS\PM::start($itemId, (int) $user['id'], $body['ticket_id'] ?? null);
    ok(['item' => $r]);
}

if ($action === 'complete') {
    require_method('POST');
    $body = json_input();
    $itemId = (int) ($body['item_id'] ?? 0);
    if ($itemId < 1) \MIS\Auth::respond(400, ['error' => 'missing_item_id']);
    try {
        $r = \MIS\PM::complete($itemId, $user, $body['notes'] ?? null);
    } catch (\RuntimeException $e) {
        \MIS\Auth::respond(404, ['error' => $e->getMessage()]);
    }
    ok(['item' => $r]);
}

if ($action === 'signoff') {
    require_method('POST');
    $body = json_input();
    $itemId = (int) ($body['item_id'] ?? 0);
    if ($itemId < 1) \MIS\Auth::respond(400, ['error' => 'missing_item_id']);
    try {
        $r = \MIS\PM::signOff($itemId, $user, $body['notes'] ?? null);
    } catch (\RuntimeException $e) {
        $code = $e->getMessage() === 'inspection_authority_required' ? 403 : 404;
        \MIS\Auth::respond($code, ['error' => $e->getMessage()]);
    }
    ok(['item' => $r]);
}

if ($action === 'reject') {
    require_method('POST');
    $body = json_input();
    $itemId = (int) ($body['item_id'] ?? 0);
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($itemId < 1 || $reason === '') {
        \MIS\Auth::respond(400, ['error' => 'missing_fields']);
    }
    try {
        $r = \MIS\PM::reject($itemId, $user, $reason);
    } catch (\RuntimeException $e) {
        $code = $e->getMessage() === 'inspection_authority_required' ? 403 : 404;
        \MIS\Auth::respond($code, ['error' => $e->getMessage()]);
    }
    ok(['item' => $r]);
}

\MIS\Auth::respond(404, ['error' => 'unknown_action']);
