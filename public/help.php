<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Bootstrap.php';
\MIS\Bootstrap::init();
$user = \MIS\Auth::user();
if (!$user) {
    header('Location: index.php'); exit;
}

$tabs = [
    'use'     => ['file' => 'how-to-use.md',     'label' => 'How to use'],
    'install' => ['file' => 'how-to-install.md', 'label' => 'Install'],
    'migrate' => ['file' => 'how-to-migrate.md', 'label' => 'Migrate'],
];
$active = $_GET['doc'] ?? 'use';
if (!isset($tabs[$active])) $active = 'use';

$basePath = realpath(dirname(__DIR__) . '/docs/guide');
$file     = $basePath . DIRECTORY_SEPARATOR . $tabs[$active]['file'];
// Defence: ensure the resolved file is inside the guide dir
$resolved = realpath($file);
$content = '';
if ($resolved && $basePath && str_starts_with($resolved, $basePath) && is_file($resolved)) {
    $md = (string) file_get_contents($resolved);
    $content = \MIS\Markdown::render($md);
}
\MIS\Audit::log((int) $user['id'], 'help.view', 'doc', $active);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#001A40">
<title>Help — <?= htmlspecialchars($tabs[$active]['label']) ?> · MIS</title>
<link rel="stylesheet" href="assets/css/app.css">
<link rel="stylesheet" href="assets/css/cmms.css">
<style>
  .help-main{max-width:880px;margin:0 auto;padding:18px 22px 60px;}
  .help-tabs{display:flex;gap:6px;margin:6px 0 18px;flex-wrap:wrap;}
  .help-tab{
    padding:8px 14px;border-radius:8px 8px 0 0;
    background:rgba(0,0,0,0.20);border:1px solid var(--panel-border);
    color:var(--text-muted);text-decoration:none;font-size:13px;
  }
  .help-tab:hover{color:var(--text);background:rgba(0,114,206,0.10);}
  .help-tab.active{
    background:rgba(0,15,40,0.55);color:var(--text);border-bottom-color:rgba(0,15,40,0.55);
  }
  .help-card{
    background:rgba(0,15,40,0.55);
    border:1px solid var(--panel-border);
    border-radius:0 10px 10px 10px;
    padding:24px 28px;
    line-height:1.55;
  }
  .help-card h1{font-size:26px;margin-top:0;letter-spacing:-0.01em;}
  .help-card h2{font-size:18px;margin-top:28px;letter-spacing:0.01em;color:#E1F0FF;}
  .help-card h3{font-size:14px;margin-top:20px;letter-spacing:0.04em;text-transform:uppercase;color:var(--text-muted);}
  .help-card p{margin:10px 0;}
  .help-card a{color:#C8E2F7;}
  .help-card hr{border:0;border-top:1px solid var(--panel-border);margin:24px 0;}
  .help-card code{
    background:rgba(0,0,0,0.30);border:1px solid var(--panel-border);
    padding:1px 6px;border-radius:4px;font-size:0.92em;
  }
  .help-card pre{
    background:rgba(0,0,0,0.40);border:1px solid var(--panel-border);
    padding:12px 14px;border-radius:8px;overflow-x:auto;
    font-size:12.5px;line-height:1.5;
  }
  .help-card pre code{background:transparent;border:0;padding:0;}
  .help-card ul, .help-card ol{padding-left:22px;}
  .help-card li{margin:4px 0;}
  .help-card blockquote{
    margin:14px 0;padding:8px 14px;
    border-left:3px solid var(--delta-wave);
    background:rgba(0,114,206,0.08);
    color:var(--text-muted);
  }
  .help-card table{width:100%;border-collapse:collapse;margin:14px 0;font-size:13px;}
  .help-card th, .help-card td{padding:8px 10px;border-bottom:1px solid var(--panel-border);text-align:left;}
  .help-card th{font-weight:600;color:var(--text-muted);background:rgba(0,0,0,0.20);}
</style>
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
      <span class="cmms-eyebrow">HELP &amp; DOCUMENTATION</span>
      <span class="cmms-issue-id"><?= htmlspecialchars($tabs[$active]['label']) ?></span>
    </div>
  </div>
  <div class="cmms-topbar-right">
    <span class="user-chip"><?= htmlspecialchars($user['full_name']) ?> <span class="role-mini">· <?= htmlspecialchars($user['role']) ?></span></span>
    <a class="btn btn-ghost" href="index.php">↩ Back to app</a>
  </div>
</header>

<main class="help-main">
  <nav class="help-tabs">
    <?php foreach ($tabs as $key => $t): ?>
      <a class="help-tab <?= $key === $active ? 'active' : '' ?>" href="?doc=<?= urlencode($key) ?>"><?= htmlspecialchars($t['label']) ?></a>
    <?php endforeach; ?>
  </nav>
  <article class="help-card">
    <?= $content ?: '<p class="muted">Documentation file not found.</p>' ?>
  </article>
</main>
</body>
</html>
