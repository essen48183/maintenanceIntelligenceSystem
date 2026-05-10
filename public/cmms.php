<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Bootstrap.php';
\MIS\Bootstrap::init();
$user = \MIS\Auth::user();
if (!$user) {
    header('Location: index.php');
    exit;
}
$faultId  = (int) ($_GET['fault_id']  ?? 0);
$ticketId = isset($_GET['ticket_id']) ? (int) $_GET['ticket_id'] : null;
if ($faultId < 1) {
    http_response_code(400);
    echo 'Missing fault_id'; exit;
}
$fault = \MIS\Faults::detail($faultId);
if (!$fault) {
    http_response_code(404);
    echo 'Fault not found'; exit;
}
\MIS\Audit::log((int) $user['id'], 'cmms.open', 'fault', (string) $faultId, ['ticket_id' => $ticketId]);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#001A40">
<title>Maintenance Portal — <?= htmlspecialchars($fault['fault_code']) ?> · CMMS</title>
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/app.css">
<link rel="stylesheet" href="assets/css/cmms.css">
<script>
  window.MIS_CMMS_BOOT = <?= json_encode([
    'fault_id'  => $faultId,
    'ticket_id' => $ticketId,
    'user'      => $user,
  ], JSON_UNESCAPED_SLASHES) ?>;
</script>
</head>
<body class="cmms-body" data-role="<?= htmlspecialchars($user['role']) ?>">
<header class="cmms-topbar">
  <div class="cmms-topbar-left">
    <span class="brand-mark"></span>
    <div class="brand-text">
      <span class="brand-line-1">DELTA</span>
      <span class="brand-line-2">TECH OPS</span>
    </div>
    <div class="cmms-title">
      <span class="cmms-eyebrow">MAINTENANCE PORTAL · CMMS</span>
      <span class="cmms-issue-id">Issue <?= htmlspecialchars($fault['fault_code']) ?></span>
    </div>
  </div>
  <div class="cmms-topbar-right">
    <span class="status-pill"><span class="dot live"></span> LIVE</span>
    <span class="clock" id="clock"></span>
    <?php if ($user['role'] === 'readonly'): ?>
      <span class="readonly-pill">READ ONLY</span>
    <?php endif; ?>
    <span class="user-chip"><?= htmlspecialchars($user['full_name']) ?> <span class="role-mini">· <?= htmlspecialchars($user['role']) ?></span></span>
    <a class="btn btn-ghost" href="index.php" target="_self" title="Back to fault tracker">↩ Back</a>
  </div>
</header>

<main class="cmms-main">
  <!-- Hero -->
  <section class="cmms-hero">
    <div class="cmms-hero-left">
      <span class="severity-pill <?= htmlspecialchars($fault['severity']) ?>"><?= htmlspecialchars($fault['severity']) ?></span>
      <span class="cmms-eyebrow"><?= htmlspecialchars($fault['airframe_code']) ?> · <?= htmlspecialchars($fault['airframe_model']) ?> · ATA <?= htmlspecialchars($fault['ata_chapter'] ?? '—') ?></span>
      <h1 class="cmms-hero-title"><?= htmlspecialchars($fault['title']) ?></h1>
      <p class="cmms-hero-desc"><?= htmlspecialchars($fault['description']) ?></p>
    </div>
    <div class="cmms-hero-right">
      <div class="cmms-quick-actions">
        <button class="btn-cmms primary" data-requires-write disabled title="Skeleton — wiring in a later phase">＋ New Work Order</button>
        <button class="btn-cmms"          data-requires-write disabled title="Skeleton — wiring in a later phase">📷 Add Photo</button>
        <button class="btn-cmms"          data-requires-write disabled title="Skeleton — wiring in a later phase">✎ Sign Off</button>
        <button class="btn-cmms"          data-requires-write disabled title="Skeleton — wiring in a later phase">⏱ Start Timer</button>
      </div>
    </div>
  </section>

  <!-- KPI strip -->
  <section class="cmms-kpis" id="cmms-kpis">
    <div class="cmms-kpi"><div class="lbl">Open Work Orders</div><div class="num" id="kpi-wo-open">—</div><div class="sub" id="kpi-wo-total">—</div></div>
    <div class="cmms-kpi"><div class="lbl">MTTR</div><div class="num" id="kpi-mttr">—</div><div class="sub">avg time to closed</div></div>
    <div class="cmms-kpi"><div class="lbl">Downtime</div><div class="num" id="kpi-downtime">—</div><div class="sub">est. hours, recent</div></div>
    <div class="cmms-kpi"><div class="lbl">Parts Cost</div><div class="num skeleton-num">— </div><div class="sub skeleton-mark">skeleton</div></div>
    <div class="cmms-kpi"><div class="lbl">PM Compliance</div><div class="num skeleton-num">— </div><div class="sub skeleton-mark">skeleton</div></div>
    <div class="cmms-kpi"><div class="lbl">Last Incident</div><div class="num" id="kpi-last">—</div><div class="sub" id="kpi-last-tail">—</div></div>
  </section>

  <!-- Two-column workspace -->
  <div class="cmms-grid">
    <!-- Left column -->
    <div class="cmms-col">
      <!-- Tasks (full interaction lives here) -->
      <section class="cmms-card">
        <header class="cmms-card-head">
          <h3>TASKLIST <span class="muted small" id="task-summary"></span></h3>
          <div class="cmms-card-actions">
            <button class="btn-cmms primary" id="ai-suggest-btn" data-requires-write title="Ask the AI to propose tasks for this issue">✦ AI suggest</button>
          </div>
        </header>
        <div class="cmms-card-body">
          <div class="task-list" id="task-list"><div class="muted small">Loading…</div></div>
          <form class="task-add" id="task-add" data-requires-write>
            <input type="text" id="task-add-input" placeholder="+ Add task (e.g., Pull MDC fault history)" maxlength="500">
            <button type="submit" class="btn-tiny">Add</button>
          </form>
        </div>
      </section>

      <!-- Work orders -->
      <section class="cmms-card">
        <header class="cmms-card-head">
          <h3>WORK ORDERS <span class="muted small" id="wo-count"></span></h3>
          <div class="cmms-card-actions">
            <button class="btn-cmms" data-requires-write disabled title="Skeleton — wiring in a later phase">＋ Create</button>
          </div>
        </header>
        <div class="cmms-card-body" id="wo-list"><div class="muted small">Loading…</div></div>
      </section>

      <!-- Preventive maintenance (real) -->
      <section class="cmms-card">
        <header class="cmms-card-head">
          <h3>PREVENTIVE MAINTENANCE <span class="muted small" id="pm-summary"></span></h3>
          <span class="muted small">tail + component scope</span>
        </header>
        <div class="cmms-card-body" id="pm-block"><div class="muted small">Loading…</div></div>
      </section>

      <!-- Sign-off queue (only for users with inspection authority) -->
      <section class="cmms-card" id="signoff-card" hidden>
        <header class="cmms-card-head">
          <h3>SIGN-OFF QUEUE <span class="muted small" id="signoff-count"></span></h3>
          <span class="muted small">items awaiting your sign-off</span>
        </header>
        <div class="cmms-card-body" id="signoff-block"></div>
      </section>

      <!-- Parts & Inventory (skeleton) -->
      <section class="cmms-card cmms-skeleton">
        <header class="cmms-card-head">
          <h3>PARTS &amp; INVENTORY</h3>
          <span class="skeleton-tag">SKELETON · wiring in a later phase</span>
        </header>
        <div class="cmms-card-body">
          <table class="cmms-table">
            <thead><tr><th>Part</th><th>P/N</th><th>On Hand</th><th>On Order</th><th>Location</th><th>Last Used</th></tr></thead>
            <tbody>
              <tr><td>Flight Control Computer (FCC)</td><td>BAE 7012-A22</td><td>1</td><td>1 · ETA 48h</td><td>ATL · Bin H-04</td><td>2026-04-30</td></tr>
              <tr><td>Pitch/Roll Servo Assy</td><td>BMB 22-31-28-A</td><td>2</td><td>0</td><td>ATL · Bin H-12</td><td>2026-03-18</td></tr>
              <tr><td>Coax Cable, FCC ↔ Servo</td><td>END-CAB-2231</td><td>5</td><td>0</td><td>ATL · Bin C-21</td><td>2026-02-02</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Time & Labor (skeleton) -->
      <section class="cmms-card cmms-skeleton">
        <header class="cmms-card-head">
          <h3>TIME &amp; LABOR</h3>
          <span class="skeleton-tag">SKELETON · wiring in a later phase</span>
        </header>
        <div class="cmms-card-body">
          <table class="cmms-table">
            <thead><tr><th>Tech</th><th>Shift</th><th>Hours</th><th>Last Activity</th><th>Status</th></tr></thead>
            <tbody>
              <tr><td>Devon Park</td><td>Day</td><td>2.5</td><td>Pulled MDC log</td><td><span class="meter ok">closed</span></td></tr>
              <tr><td>Kira Holden</td><td>Swing</td><td>1.8</td><td>Verifying SB compliance</td><td><span class="meter warn">in progress</span></td></tr>
              <tr><td>Marcus Webb</td><td>Night</td><td>0.0</td><td>—</td><td><span class="meter neutral">queued</span></td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- Right column -->
    <div class="cmms-col">
      <!-- Asset snapshot -->
      <section class="cmms-card">
        <header class="cmms-card-head">
          <h3>ASSET SNAPSHOT <span class="muted small" id="asset-count"></span></h3>
          <span class="skeleton-tag soft">utilization values are placeholders until fleet-data integration</span>
        </header>
        <div class="cmms-card-body" id="asset-grid"><div class="muted small">Loading…</div></div>
      </section>

      <!-- Downtime / Reliability (real) -->
      <section class="cmms-card">
        <header class="cmms-card-head">
          <h3>DOWNTIME / RELIABILITY</h3>
          <span class="muted small">last 14 days</span>
        </header>
        <div class="cmms-card-body" id="downtime-block"></div>
      </section>

      <!-- Cost summary (skeleton) -->
      <section class="cmms-card cmms-skeleton">
        <header class="cmms-card-head">
          <h3>COST SUMMARY</h3>
          <span class="skeleton-tag">SKELETON · wiring in a later phase</span>
        </header>
        <div class="cmms-card-body">
          <ul class="cmms-cost">
            <li><span>Labor</span> <strong>$ 482.00</strong></li>
            <li><span>Parts</span> <strong>$ 14,200.00</strong></li>
            <li><span>Downtime impact</span> <strong>$ 28,400.00</strong></li>
            <li class="cmms-cost-total"><span>Issue-to-date</span> <strong>$ 43,082.00</strong></li>
          </ul>
        </div>
      </section>

      <!-- Documents (real) -->
      <section class="cmms-card">
        <header class="cmms-card-head">
          <h3>DOCUMENTS</h3>
          <span class="muted small">PDF opens in own window</span>
        </header>
        <div class="cmms-card-body" id="docs-block"></div>
      </section>

      <!-- Activity timeline -->
      <section class="cmms-card">
        <header class="cmms-card-head">
          <h3>ACTIVITY</h3>
          <span class="muted small">audit + ticket events</span>
        </header>
        <div class="cmms-card-body" id="activity-block"><div class="muted small">Loading…</div></div>
      </section>

      <!-- Photos / Signatures (skeleton) -->
      <section class="cmms-card cmms-skeleton">
        <header class="cmms-card-head">
          <h3>PHOTOS &amp; SIGNATURES</h3>
          <span class="skeleton-tag">SKELETON · wiring in a later phase</span>
        </header>
        <div class="cmms-card-body">
          <div class="cmms-photo-grid">
            <div class="cmms-photo-tile"><span>📷</span><br><small>Capture from iPad</small></div>
            <div class="cmms-photo-tile"><span>📷</span><br><small>Annotated AMM page</small></div>
            <div class="cmms-photo-tile"><span>✎</span><br><small>Sign-off signature</small></div>
            <div class="cmms-photo-tile"><span>＋</span><br><small>Add</small></div>
          </div>
        </div>
      </section>
    </div>
  </div>

  <footer class="cmms-footer">
    <span class="footer-mark"></span>
    <span class="footer-tag">DELTA TECH OPS · MAINTENANCE PORTAL</span>
    <span class="footer-sep">•</span>
    <span>CONFIDENTIAL</span>
    <span class="footer-sep">•</span>
    <span>FOR AUTHORIZED PERSONNEL ONLY</span>
    <span class="footer-spacer"></span>
    <span class="muted small" id="last-refresh"></span>
  </footer>
</main>

<script src="assets/js/cmms.js" defer></script>
</body>
</html>
