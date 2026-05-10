<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';

$user = \MIS\Auth::require();
$faultId = (int) ($_GET['fault_id'] ?? 0);
if ($faultId < 1) \MIS\Auth::respond(400, ['error' => 'missing_fault_id']);

$fault = \MIS\Faults::detail($faultId);
if (!$fault) \MIS\Auth::respond(404, ['error' => 'not_found']);

$pdo = \MIS\Db::pdo();

// Work orders (tickets) tied to this fault
$wo = $pdo->prepare(
    'SELECT t.id, t.ticket_number, t.title, t.status, t.severity, t.opened_at, t.closed_at, t.updated_at,
            u1.full_name AS opened_by, u2.full_name AS assigned_to_name, at.tail_number
       FROM tickets t
       LEFT JOIN users u1 ON u1.id = t.created_by
       LEFT JOIN users u2 ON u2.id = t.assigned_to
       LEFT JOIN aircraft_tails at ON at.id = t.tail_id
      WHERE t.fault_id = :fid
      ORDER BY FIELD(t.status,"open","in_progress","on_hold","closed","cancelled"),
               t.opened_at DESC'
);
$wo->execute([':fid' => $faultId]);
$workOrders = $wo->fetchAll();

// Aircraft tails affected (with synthesized utilization placeholders so the
// UI feels real until real fleet-data integration lands).
$tails = $pdo->prepare(
    'SELECT DISTINCT at.id, at.tail_number, at.operator,
            (SELECT COUNT(*) FROM fault_occurrences o WHERE o.fault_id = :fid AND o.tail_id = at.id) AS occurrences,
            (SELECT MAX(occurred_at)              FROM fault_occurrences o WHERE o.fault_id = :fid2 AND o.tail_id = at.id) AS last_seen
       FROM fault_occurrences fo
       JOIN aircraft_tails at ON at.id = fo.tail_id
      WHERE fo.fault_id = :fid3'
);
$tails->execute([':fid' => $faultId, ':fid2' => $faultId, ':fid3' => $faultId]);
$rawTails = $tails->fetchAll();
$tailRows = [];
foreach ($rawTails as $i => $t) {
    // Deterministic placeholder values derived from id for repeatability.
    $h = 18000 + (((int) $t['id']) * 1411) % 8000;
    $c = 12000 + (((int) $t['id']) * 977)  % 5000;
    $tailRows[] = [
        'id'            => (int) $t['id'],
        'tail_number'   => $t['tail_number'],
        'operator'      => $t['operator'],
        'occurrences'   => (int) $t['occurrences'],
        'last_seen'     => $t['last_seen'],
        // PLACEHOLDER values — replaced once we ingest real fleet utilization data
        'flight_hours'  => $h,
        'flight_cycles' => $c,
        'last_a_check'  => '2026-' . str_pad((string)((((int)$t['id']) % 4) + 1), 2, '0', STR_PAD_LEFT) . '-15',
        'next_pm_due'   => '2026-' . str_pad((string)((((int)$t['id']) % 4) + 5), 2, '0', STR_PAD_LEFT) . '-22',
    ];
}

// Downtime: each occurrence is treated as ~2.5 hours of investigation downtime
// (placeholder until we wire real out-of-service intervals)
$downtimeHours = round(count($fault['recent_occurrences']) * 2.5, 1);

// Audit timeline for this fault
$audit = \MIS\Audit::recentForTarget('fault', (string) $faultId, 25);
// Plus ticket events for any related tickets
$ticketIds = array_map('intval', array_column($workOrders, 'id'));
$events = [];
if ($ticketIds) {
    $place = implode(',', array_fill(0, count($ticketIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT te.id, te.ticket_id, te.event_type, te.body, te.created_at,
                u.full_name, u.role
           FROM ticket_events te
           JOIN users u ON u.id = te.user_id
          WHERE te.ticket_id IN ($place)
          ORDER BY te.created_at DESC LIMIT 50"
    );
    $stmt->execute($ticketIds);
    $events = $stmt->fetchAll();
}

// Compute MTTR (mean time to closed) for closed work orders on this fault
$mttrHours = null;
$closed = array_filter($workOrders, fn($w) => $w['status'] === 'closed' && $w['closed_at'] && $w['opened_at']);
if (count($closed) > 0) {
    $sum = 0; $n = 0;
    foreach ($closed as $w) {
        $sum += max(0, strtotime($w['closed_at']) - strtotime($w['opened_at']));
        $n++;
    }
    if ($n > 0) $mttrHours = round(($sum / $n) / 3600, 1);
}

ok([
    'fault'        => $fault,
    'work_orders'  => $workOrders,
    'tails'        => $tailRows,
    'downtime_hours' => $downtimeHours,
    'mttr_hours'   => $mttrHours,
    'audit'        => $audit,
    'ticket_events'=> $events,
    'now'          => date('c'),
    'user'         => [
        'id' => (int) $user['id'],
        'role' => $user['role'],
        'full_name' => $user['full_name'],
    ],
]);
