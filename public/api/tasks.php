<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';

$user = \MIS\Auth::require();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $faultId = (int) ($_GET['fault_id'] ?? 0);
    if ($faultId < 1) \MIS\Auth::respond(400, ['error' => 'missing_fault_id']);
    ok(['tasks' => \MIS\Tasks::listForFault($faultId)]);
}

// Mutating actions require write role
if (!\MIS\Auth::canWrite($user)) {
    \MIS\Auth::respond(403, ['error' => 'forbidden_role', 'role' => $user['role']]);
}

if ($action === 'add') {
    require_method('POST');
    $body = json_input();
    $faultId = (int) ($body['fault_id'] ?? 0);
    $title   = (string) ($body['title'] ?? '');
    if ($faultId < 1 || trim($title) === '') {
        \MIS\Auth::respond(400, ['error' => 'missing_fields']);
    }
    try {
        $id = \MIS\Tasks::add($faultId, (int) $user['id'], $title, [
            'description' => $body['description'] ?? null,
            'ticket_id'   => $body['ticket_id']   ?? null,
            'source'      => 'user',
        ]);
    } catch (\InvalidArgumentException $e) {
        \MIS\Auth::respond(400, ['error' => $e->getMessage()]);
    }
    ok(['task_id' => $id, 'tasks' => \MIS\Tasks::listForFault($faultId)]);
}

if ($action === 'status') {
    require_method('POST');
    $body = json_input();
    $taskId = (int) ($body['task_id'] ?? 0);
    $status = (string) ($body['status'] ?? '');
    if ($taskId < 1 || $status === '') {
        \MIS\Auth::respond(400, ['error' => 'missing_fields']);
    }
    try {
        $task = \MIS\Tasks::setStatus($taskId, $user, $status, $body['holdup'] ?? null);
    } catch (\InvalidArgumentException $e) {
        \MIS\Auth::respond(400, ['error' => $e->getMessage()]);
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'inspection_authority_required') {
            \MIS\Auth::respond(403, ['error' => $msg]);
        }
        \MIS\Auth::respond(404, ['error' => $msg]);
    }
    ok(['task' => $task, 'tasks' => \MIS\Tasks::listForFault((int) $task['fault_id'])]);
}

if ($action === 'update') {
    require_method('POST');
    $body = json_input();
    $taskId = (int) ($body['task_id'] ?? 0);
    if ($taskId < 1) \MIS\Auth::respond(400, ['error' => 'missing_task_id']);
    try {
        $task = \MIS\Tasks::update($taskId, (int) $user['id'], $body);
    } catch (\InvalidArgumentException $e) {
        \MIS\Auth::respond(400, ['error' => $e->getMessage()]);
    }
    ok(['task' => $task]);
}

if ($action === 'delete') {
    require_method('POST');
    $body = json_input();
    $taskId = (int) ($body['task_id'] ?? 0);
    if ($taskId < 1) \MIS\Auth::respond(400, ['error' => 'missing_task_id']);
    \MIS\Tasks::delete($taskId, (int) $user['id']);
    ok(['ok' => true]);
}

if ($action === 'suggest') {
    require_method('POST');
    $body = json_input();
    $faultId = (int) ($body['fault_id'] ?? 0);
    if ($faultId < 1) \MIS\Auth::respond(400, ['error' => 'missing_fault_id']);

    $fault = \MIS\Faults::detail($faultId);
    if (!$fault) \MIS\Auth::respond(404, ['error' => 'fault_not_found']);

    $cfg = \MIS\Bootstrap::$config;
    $client = new \MIS\AiClient($cfg['anthropic'] ?? []);

    $airframeContext  = "Airframe: {$fault['airframe_code']} ({$fault['airframe_model']}).\n";
    $airframeContext .= "Active fault: [{$fault['fault_code']}] {$fault['title']} (severity: {$fault['severity']}).\n";
    $airframeContext .= "ATA: {$fault['ata_chapter']}. System: {$fault['system_name']}.\n";
    $airframeContext .= "Description: {$fault['description']}\n";
    if (!empty($fault['affected_labels'])) {
        $airframeContext .= "Affected sub-systems: " . implode(', ', $fault['affected_labels']) . "\n";
    }
    if (!empty($fault['reference_documents'])) {
        $airframeContext .= "Reference docs: " . implode('; ', array_map(fn($d) => "[{$d['doc_type']}] {$d['title']}", $fault['reference_documents'])) . "\n";
    }

    $sys = <<<SYS
You are the diagnostic assistant for a regional-airline maintenance team. The
user is asking you to propose a tasklist for working through this fault.

Output STRICT JSON ONLY: a top-level array of objects with the keys:
  - "title":  short, line-actionable task (under 120 chars)
  - "rationale": 1-line reason / which document or check this corresponds to
Output 4–6 tasks. No prose before or after the JSON. No code fences. Just JSON.
Each task must be verifiable on the line by a certified tech and reference
either an ATA chapter, MDC check, manufacturer SB, or AMM step where relevant.
SYS;

    $messages = [['role' => 'user', 'content' => 'Generate a tasklist for the active fault above.']];
    $resp = $client->ask($sys, $airframeContext, $messages);
    if (!empty($resp['error'])) {
        \MIS\Auth::respond(502, ['error' => $resp['error'], 'detail' => $resp['detail'] ?? null]);
    }

    $text = (string) ($resp['text'] ?? '');
    $tasks = parseTaskJson($text);

    // Stub fallback: if AI is offline, generate sensible tasks deterministically
    if (!$tasks) {
        $tasks = stubTasks($fault);
    }

    $created = [];
    foreach ($tasks as $t) {
        $title = trim((string) ($t['title'] ?? ''));
        if ($title === '') continue;
        $created[] = \MIS\Tasks::add($faultId, (int) $user['id'], $title, [
            'description' => $t['rationale'] ?? null,
            'source'      => 'ai',
        ]);
    }
    \MIS\Audit::log((int) $user['id'], 'task.suggest', 'fault', (string) $faultId, [
        'count' => count($created),
        'live'  => $client->isLive(),
    ]);
    ok([
        'created' => $created,
        'live'    => $client->isLive(),
        'tasks'   => \MIS\Tasks::listForFault($faultId),
    ]);
}

\MIS\Auth::respond(404, ['error' => 'unknown_action']);

// ---------- helpers ----------
function parseTaskJson(string $text): array {
    $text = trim($text);
    // Strip code fences if model added them despite instructions
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    // Find first [...] block
    if (preg_match('/\[.*\]/s', $text, $m)) {
        $json = $m[0];
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, fn($x) => is_array($x) && isset($x['title'])));
        }
    }
    return [];
}

function stubTasks(array $fault): array {
    $sys = $fault['system_name'] ?? 'system';
    $ata = $fault['ata_chapter'] ?? '—';
    return [
        ['title' => "Pull MDC fault history for $sys (ATA $ata) over the last 30 days",
         'rationale' => 'Establishes recurrence baseline and tail-by-tail spread.'],
        ['title' => "Verify reference documents are at current revision (AMM, applicable SBs)",
         'rationale' => 'Prevents working from a superseded procedure.'],
        ['title' => "Walk the diagnostic questions and document findings in the ticket",
         'rationale' => 'Captures answers for shift handoff continuity.'],
        ['title' => "Inspect for the most-likely-cause LRU listed in the AMM troubleshooting matrix",
         'rationale' => 'Direct AMM-driven step before parts ordering.'],
        ['title' => "Coordinate with stores on lead time for the candidate replacement LRU",
         'rationale' => 'Identifies any holdup early so the ticket can move to blocked status if needed.'],
    ];
}
