<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Bootstrap.php';
\MIS\Bootstrap::init();
$user = \MIS\Auth::user();
$cfg = \MIS\Bootstrap::$config;
$base = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#001A40">
<title>Endeavor Air — Maintenance Intelligence System</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MIS">
<link rel="stylesheet" href="assets/css/app.css">
<script>
  window.MIS_BOOT = <?php echo json_encode([
    'authenticated' => $user !== null,
    'user' => $user,
    'base_url' => $base,
  ], JSON_UNESCAPED_SLASHES); ?>;
</script>
</head>
<body>
<div id="app" data-state="<?= $user ? 'app' : 'login' ?>">
  <!-- Login screen -->
  <section id="login-screen" class="login-screen">
    <form id="login-form" class="login-card" autocomplete="on">
      <div class="brand">
        <span class="brand-mark" aria-hidden="true"></span>
        <div class="brand-text">
          <span class="brand-line-1">DELTA</span>
          <span class="brand-line-2">TECH OPS</span>
        </div>
      </div>
      <h1>Maintenance Intelligence System</h1>
      <p class="muted">Sign in with your employee credentials.</p>
      <label>Username
        <input type="text" name="username" autocomplete="username" required>
      </label>
      <label>Password
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit" class="btn btn-primary">Sign in</button>
      <p class="login-error" id="login-error" hidden></p>
      <p class="muted small">Default seed accounts (password <code>ChangeMe!123</code>): <code>admin</code>, <code>jsupervisor</code>, <code>mtech1</code>, <code>mtech2</code>, <code>mtech3</code>, <code>viewer</code>.</p>
    </form>
  </section>

  <!-- App shell -->
  <header id="topbar" class="topbar" hidden>
    <div class="topbar-left">
      <span class="brand-mark"></span>
      <div class="brand-text">
        <span class="brand-line-1">DELTA</span>
        <span class="brand-line-2">TECH OPS</span>
      </div>
      <div class="app-title">
        <span class="title-main">CRJ FAULT TRACKER</span>
        <span class="title-sub">MAINTENANCE INTELLIGENCE SYSTEM</span>
      </div>
    </div>
    <div class="topbar-right">
      <span class="status-pill"><span class="dot live"></span> LIVE</span>
      <span class="clock" id="clock"></span>
      <span class="readonly-pill" id="readonly-pill" hidden>READ ONLY</span>
      <button id="install-btn" class="btn btn-ghost" hidden>Install app</button>
      <span class="user-chip" id="user-chip"></span>
      <button id="logout-btn" class="btn btn-ghost">Sign out</button>
    </div>
  </header>

  <main id="layout" class="layout" hidden>
    <!-- Left: fleet & filters -->
    <aside class="sidebar">
      <h3>Fleet</h3>
      <ul id="fleet-list" class="fleet-list"></ul>
      <h3>Filters</h3>
      <div class="filter-group">
        <span class="filter-label">Severity</span>
        <ul id="severity-filter" class="chip-list">
          <li data-sev="ALL"      class="chip active"><span class="dot all"></span> All</li>
          <li data-sev="CRITICAL" class="chip"><span class="dot crit"></span> Critical</li>
          <li data-sev="HIGH"     class="chip"><span class="dot high"></span> High</li>
          <li data-sev="MEDIUM"   class="chip"><span class="dot med"></span> Medium</li>
          <li data-sev="LOW"      class="chip"><span class="dot low"></span> Low</li>
        </ul>
      </div>
      <div class="filter-group">
        <span class="filter-label">System</span>
        <ul id="system-filter" class="chip-list"></ul>
      </div>
      <div class="data-connector">
        <div class="dc-label"><span class="dot pending"></span> DATA CONNECTOR</div>
        <div class="dc-status">PENDING</div>
        <div class="dc-note">Awaiting data access approval</div>
      </div>
    </aside>

    <!-- Center: dashboard / fault detail -->
    <section class="center">
      <div class="center-header">
        <div>
          <h1 id="airframe-title">CRJ-900</h1>
          <p id="airframe-sub" class="muted">Bombardier CRJ-900 (CL-600-2D24)</p>
        </div>
        <div class="search-bar">
          <input id="search-box" type="search" placeholder="Search issues…">
        </div>
      </div>

      <div class="kpi-row" id="kpi-row"></div>

      <div class="dashboard-row">
        <div class="card list-card">
          <div class="card-head">
            <h2>ACTIVE ISSUES <span id="active-count" class="muted"></span></h2>
            <div class="card-head-meta muted">Sort: Last Seen</div>
          </div>
          <ul class="issue-list" id="issue-list"></ul>
        </div>
        <div class="card detail-card" id="detail-card">
          <div class="muted center-pad">Select an issue to view details.</div>
        </div>
      </div>
    </section>

    <!-- Right: AI assistant -->
    <aside class="ai-panel" id="ai-panel">
      <div class="ai-head">
        <span class="ai-spark" aria-hidden="true">✦</span>
        <span class="ai-title">AI DIAGNOSTIC ASSISTANT</span>
        <button class="ai-collapse" id="ai-collapse" title="Collapse">—</button>
      </div>
      <div class="ai-body" id="ai-body">
        <div class="ai-context muted" id="ai-context">
          Diagnostic AI is idle. Open a fault to begin.
        </div>
        <div class="ai-thread" id="ai-thread"></div>
      </div>
      <div class="ai-suggestions" id="ai-suggestions"></div>
      <form class="ai-input" id="ai-form">
        <input type="text" id="ai-input" placeholder="Ask a diagnostic question…" autocomplete="off">
        <button type="submit" class="btn btn-primary ai-send" title="Send">➤</button>
      </form>
    </aside>
  </main>

  <footer class="footer" hidden>
    <span class="footer-mark"></span>
    <span class="footer-tag">DELTA TECH OPS</span>
    <span class="footer-sep">•</span>
    <span>CONFIDENTIAL</span>
    <span class="footer-sep">•</span>
    <span>FOR AUTHORIZED PERSONNEL ONLY</span>
    <span class="footer-spacer"></span>
    <span class="status-pill"><span class="dot live"></span> System Status: All Systems Operational</span>
  </footer>
</div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
