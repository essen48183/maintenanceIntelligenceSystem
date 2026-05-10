<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';

\MIS\Auth::require();
$action = $_GET['action'] ?? 'list';
$airframeId = isset($_GET['airframe_id']) ? (int) $_GET['airframe_id'] : null;

if ($action === 'kpis') {
    ok(['kpis' => \MIS\Faults::severityCounts($airframeId)]);
}
if ($action === 'list') {
    ok([
        'kpis'   => \MIS\Faults::severityCounts($airframeId),
        'faults' => \MIS\Faults::activeList($airframeId),
    ]);
}
if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) \MIS\Auth::respond(400, ['error' => 'missing_id']);
    $d = \MIS\Faults::detail($id);
    if (!$d) \MIS\Auth::respond(404, ['error' => 'not_found']);
    \MIS\Audit::log((int) $_SESSION['user_id'], 'fault.view', 'fault', (string) $id);
    ok(['fault' => $d]);
}
\MIS\Auth::respond(404, ['error' => 'unknown_action']);
