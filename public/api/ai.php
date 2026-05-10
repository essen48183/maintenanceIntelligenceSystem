<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';

$user = \MIS\Auth::require();

$action = $_GET['action'] ?? 'ask';
$cfg = \MIS\Bootstrap::$config;
$client = new \MIS\AiClient($cfg['anthropic'] ?? []);

if ($action === 'history') {
    $faultId  = isset($_GET['fault_id'])  ? (int) $_GET['fault_id']  : null;
    $ticketId = isset($_GET['ticket_id']) ? (int) $_GET['ticket_id'] : null;
    $pdo = \MIS\Db::pdo();
    if ($ticketId) {
        $stmt = $pdo->prepare(
            'SELECT id, role, content, created_at, user_id FROM ai_messages
             WHERE ticket_id = :id ORDER BY created_at ASC, id ASC LIMIT 200'
        );
        $stmt->execute([':id' => $ticketId]);
    } elseif ($faultId) {
        $stmt = $pdo->prepare(
            'SELECT id, role, content, created_at, user_id FROM ai_messages
             WHERE fault_id = :id AND ticket_id IS NULL ORDER BY created_at ASC, id ASC LIMIT 200'
        );
        $stmt->execute([':id' => $faultId]);
    } else {
        ok(['messages' => []]);
    }
    ok(['messages' => $stmt->fetchAll()]);
}

if ($action === 'ask') {
    require_method('POST');
    $body = json_input();
    $message  = trim((string) ($body['message'] ?? ''));
    $faultId  = isset($body['fault_id'])  ? (int) $body['fault_id']  : null;
    $ticketId = isset($body['ticket_id']) ? (int) $body['ticket_id'] : null;
    if ($message === '') {
        \MIS\Auth::respond(400, ['error' => 'message_required']);
    }

    // Build airframe / fault context
    $airframeContext = '';
    $faultDetail = null;
    if ($faultId) {
        $faultDetail = \MIS\Faults::detail($faultId);
        if ($faultDetail) {
            $airframeContext  = "Airframe: {$faultDetail['airframe_code']} ({$faultDetail['airframe_model']}).\n";
            $airframeContext .= "Active fault: [{$faultDetail['fault_code']}] {$faultDetail['title']} (severity: {$faultDetail['severity']}).\n";
            $airframeContext .= "ATA: {$faultDetail['ata_chapter']}. System: {$faultDetail['system_name']}.\n";
            $airframeContext .= "Description: {$faultDetail['description']}\n";
            if (!empty($faultDetail['affected_labels'])) {
                $airframeContext .= "Affected sub-systems: " . implode(', ', $faultDetail['affected_labels']) . "\n";
            }
            $airframeContext .= "Recent occurrences (latest 10):\n";
            foreach (array_slice($faultDetail['recent_occurrences'], 0, 10) as $o) {
                $airframeContext .= " - {$o['occurred_at']} {$o['tail_number']} {$o['flight_number']} {$o['phase']}";
                if (!empty($o['notes'])) $airframeContext .= " ({$o['notes']})";
                $airframeContext .= "\n";
            }
            if (!empty($faultDetail['reference_documents'])) {
                $airframeContext .= "Reference documents available:\n";
                foreach ($faultDetail['reference_documents'] as $d) {
                    $airframeContext .= " - [{$d['doc_type']}] {$d['title']} — {$d['subtitle']}\n";
                }
            }
        }
    }

    $systemPrompt = <<<SYS
You are the diagnostic assistant inside the Maintenance Intelligence System used by
Endeavor Air maintenance personnel. Your role is to guide certified maintenance
technicians and supervisors through *diagnosis* and *documentation* of aircraft
faults — not to perform or authorize maintenance actions, and not to replace
manufacturer or FAA documentation.

Rules of engagement:
 1. Always defer to the manufacturer AMM/SB and the FAA-approved procedures. When you
    suggest a step, cite the relevant ATA chapter or document title from the context.
 2. Be concise: short bulleted diagnostic steps, each one verifiable on the line.
 3. Never speculate about safety-of-flight outcomes. Stay within fault-finding,
    documentation, and reference-pointing.
 4. If the technician's question is ambiguous, ask one focused clarifying question
    rather than guessing.
 5. End complex answers with a one-line "Documentation note:" suggesting what to
    record in the ticket so the next shift has continuity.
SYS;

    // Load prior conversation for this ticket/fault scope
    $pdo = \MIS\Db::pdo();
    $history = [];
    if ($ticketId) {
        $stmt = $pdo->prepare("SELECT role, content FROM ai_messages WHERE ticket_id = :id AND role IN ('user','assistant') ORDER BY created_at ASC, id ASC LIMIT 40");
        $stmt->execute([':id' => $ticketId]);
        $history = $stmt->fetchAll();
    } elseif ($faultId) {
        $stmt = $pdo->prepare("SELECT role, content FROM ai_messages WHERE fault_id = :id AND ticket_id IS NULL AND role IN ('user','assistant') ORDER BY created_at ASC, id ASC LIMIT 40");
        $stmt->execute([':id' => $faultId]);
        $history = $stmt->fetchAll();
    }
    $history[] = ['role' => 'user', 'content' => $message];

    // Persist the user message
    $pdo->prepare(
        'INSERT INTO ai_messages (ticket_id, fault_id, user_id, role, content) VALUES (:t, :f, :u, "user", :c)'
    )->execute([':t' => $ticketId, ':f' => $faultId, ':u' => $user['id'], ':c' => $message]);

    $resp = $client->ask($systemPrompt, $airframeContext, $history);
    if (!empty($resp['error'])) {
        \MIS\Auth::respond(502, ['error' => $resp['error'], 'detail' => $resp['detail'] ?? null]);
    }
    $assistantText = (string) ($resp['text'] ?? '');
    $usage = $resp['usage'] ?? null;
    $pdo->prepare(
        'INSERT INTO ai_messages (ticket_id, fault_id, user_id, role, content, tokens_in, tokens_out)
         VALUES (:t, :f, :u, "assistant", :c, :in, :out)'
    )->execute([
        ':t' => $ticketId, ':f' => $faultId, ':u' => $user['id'], ':c' => $assistantText,
        ':in'  => $usage['input_tokens']  ?? null,
        ':out' => $usage['output_tokens'] ?? null,
    ]);
    \MIS\Audit::log((int) $user['id'], 'ai.query', $ticketId ? 'ticket' : 'fault', (string) ($ticketId ?? $faultId), [
        'model' => $resp['model'] ?? null,
        'live'  => $client->isLive(),
    ]);
    if ($ticketId) {
        \MIS\Tickets::addEvent($ticketId, (int) $user['id'], 'ai_consulted', mb_substr($message, 0, 500));
    }
    ok([
        'text'  => $assistantText,
        'live'  => $client->isLive(),
        'model' => $resp['model'] ?? null,
        'usage' => $usage,
    ]);
}

\MIS\Auth::respond(404, ['error' => 'unknown_action']);
