<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Bootstrap.php';
\MIS\Bootstrap::init();
$user = \MIS\Auth::user();
if (!$user) {
    header('Location: index.php'); exit;
}
if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Forbidden</title>';
    echo '<style>body{background:#001A40;color:#fff;font-family:-apple-system,Inter,sans-serif;padding:48px;text-align:center;}</style>';
    echo '<h1>403 — admin access required</h1><p><a href="index.php" style="color:#C8E2F7">← back</a></p>';
    exit;
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#001A40">
<title>Manage Users · MIS</title>
<link rel="stylesheet" href="assets/css/app.css">
<link rel="stylesheet" href="assets/css/cmms.css">
<style>
  /* Admin-page layout extras */
  .admin-main{padding:18px 22px 32px; max-width:1300px; margin:0 auto;}
  .admin-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
  .admin-bar h1{margin:0;font-size:22px;}
  .admin-table{width:100%;border-collapse:collapse;font-size:13px;background:rgba(0,15,40,0.55);border:1px solid var(--panel-border);border-radius:10px;overflow:hidden;}
  .admin-table th,.admin-table td{padding:10px 12px;border-bottom:1px solid var(--panel-border);text-align:left;}
  .admin-table th{font-size:10px;letter-spacing:0.10em;color:var(--text-muted);text-transform:uppercase;background:rgba(0,0,0,0.20);}
  .admin-table tr.inactive td{opacity:0.55;}
  .toggle{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--text-muted);}
  .toggle input{width:14px;height:14px;}
  .row-actions{display:flex;gap:6px;flex-wrap:wrap;}
  .role-pill{font-size:10px;letter-spacing:0.10em;font-weight:700;text-transform:uppercase;padding:3px 8px;border-radius:999px;border:1px solid transparent;}
  .role-pill.admin       {background:rgba(229,25,55,0.18);color:#FFB6C2;border-color:rgba(229,25,55,0.55);}
  .role-pill.supervisor  {background:rgba(0,114,206,0.18);color:#C8E2F7;border-color:var(--panel-border-strong);}
  .role-pill.maintenance {background:rgba(61,220,151,0.16);color:#A4F2D0;border-color:rgba(61,220,151,0.45);}
  .role-pill.readonly    {background:rgba(255,255,255,0.06);color:var(--text-muted);border-color:var(--panel-border);}
  .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end;background:rgba(0,15,40,0.55);border:1px solid var(--panel-border);padding:14px;border-radius:10px;margin-bottom:16px;}
  .form-grid label{display:flex;flex-direction:column;gap:4px;font-size:11px;letter-spacing:0.04em;color:var(--text-muted);}
  .form-grid input, .form-grid select{padding:8px 10px;border-radius:6px;background:rgba(0,0,0,0.30);border:1px solid var(--panel-border);color:var(--text);font-size:13px;}
  .form-grid input:focus, .form-grid select:focus{outline:none;border-color:var(--delta-wave);}
  .form-grid .toggles{display:flex;align-items:center;gap:14px;}
  .toast{position:fixed;bottom:18px;right:18px;background:rgba(0,30,72,0.95);border:1px solid var(--panel-border-strong);padding:10px 14px;border-radius:8px;font-size:13px;}
</style>
<script>
  window.MIS_ADMIN_BOOT = <?= json_encode(['user' => $user], JSON_UNESCAPED_SLASHES) ?>;
</script>
</head>
<body class="cmms-body" data-role="admin">
<header class="cmms-topbar">
  <div class="cmms-topbar-left">
    <span class="brand-mark"></span>
    <div class="brand-text">
      <span class="brand-line-1">ENDEAVOR</span>
      <span class="brand-line-2">AIR</span>
    </div>
    <div class="cmms-title">
      <span class="cmms-eyebrow">ADMIN · USER MANAGEMENT</span>
      <span class="cmms-issue-id">Manage users &amp; access</span>
    </div>
  </div>
  <div class="cmms-topbar-right">
    <span class="user-chip"><?= htmlspecialchars($user['full_name']) ?> <span class="role-mini">· <?= htmlspecialchars($user['role']) ?></span></span>
    <a class="btn btn-ghost" href="index.php">↩ Back to app</a>
  </div>
</header>

<main class="admin-main">
  <div class="admin-bar">
    <h1>Manage users</h1>
    <span class="muted small">All actions are audited.</span>
  </div>

  <!-- Add user form -->
  <form id="add-form" class="form-grid">
    <label>Employee ID <input type="text" name="employee_id" required maxlength="32" placeholder="E1234"></label>
    <label>Username    <input type="text" name="username"    required maxlength="64" placeholder="jdoe"></label>
    <label>Full name   <input type="text" name="full_name"   required maxlength="128"></label>
    <label>Email       <input type="email" name="email" maxlength="190"></label>
    <label>Role
      <select name="role" required>
        <option value="maintenance">maintenance</option>
        <option value="supervisor">supervisor</option>
        <option value="admin">admin</option>
        <option value="readonly">readonly</option>
      </select>
    </label>
    <label>Station   <input type="text" name="station" maxlength="8" placeholder="ATL"></label>
    <label>Shift
      <select name="shift">
        <option value="">—</option>
        <option value="day">day</option>
        <option value="swing">swing</option>
        <option value="night">night</option>
      </select>
    </label>
    <label>Initial password <input type="text" name="password" required minlength="8" placeholder="≥ 8 chars"></label>
    <label class="toggles">
      <span class="toggle"><input type="checkbox" name="rts_authority"> RTS authority</span>
      <span class="toggle"><input type="checkbox" name="inspection_authority"> Inspection authority</span>
    </label>
    <label style="align-self:flex-end;">&nbsp;<button type="submit" class="btn-cmms primary">＋ Add user</button></label>
  </form>

  <table class="admin-table" id="user-table">
    <thead>
      <tr>
        <th>Employee</th>
        <th>Username</th>
        <th>Name</th>
        <th>Role</th>
        <th>Station</th>
        <th>Shift</th>
        <th>RTS</th>
        <th>Inspection</th>
        <th>Last login</th>
        <th>Active</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="user-tbody"><tr><td colspan="11" class="muted small">Loading…</td></tr></tbody>
  </table>
</main>

<div id="toast" class="toast" hidden></div>

<script>
(() => {
  'use strict';
  const $ = s => document.querySelector(s);
  const $$ = s => Array.from(document.querySelectorAll(s));
  const ME = window.MIS_ADMIN_BOOT.user;

  async function jfetch(p, opts={}){
    const headers = Object.assign({'Accept':'application/json'}, opts.body?{'Content-Type':'application/json'}:{});
    const res = await fetch(p, Object.assign({credentials:'same-origin'}, opts, {headers}));
    const t = await res.text();
    let d; try{ d = t? JSON.parse(t): {}; } catch{ d={error:'bad_json',raw:t}; }
    if (!res.ok){ const e=new Error(d.error||('http_'+res.status)); e.payload=d; throw e; }
    return d;
  }
  function toast(msg){
    const t = $('#toast'); t.textContent = msg; t.hidden = false;
    clearTimeout(toast._t); toast._t = setTimeout(()=>{t.hidden=true;}, 3000);
  }
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));

  async function load(){
    const { users } = await jfetch('api/users.php?action=list');
    const tbody = $('#user-tbody');
    tbody.innerHTML = users.map(u => {
      const isMe = Number(u.id) === Number(ME.id);
      const dis = isMe ? 'disabled title="That\'s you"' : '';
      const last = u.last_login_at ? u.last_login_at.replace(' ', '<br>') : '<span class="muted small">—</span>';
      return `
      <tr data-id="${u.id}" class="${u.is_active==1?'':'inactive'}">
        <td><strong>${esc(u.employee_id)}</strong></td>
        <td>${esc(u.username)}</td>
        <td>${esc(u.full_name)}<div class="muted small">${esc(u.email||'')}</div></td>
        <td>
          <select class="cell-role" ${isMe?'disabled title="Cannot demote self"':''}>
            ${['admin','supervisor','maintenance','readonly'].map(r => `<option value="${r}" ${u.role===r?'selected':''}>${r}</option>`).join('')}
          </select>
        </td>
        <td><input class="cell-station" maxlength="8" value="${esc(u.station||'')}" style="width:60px;"></td>
        <td>
          <select class="cell-shift">
            ${['','day','swing','night'].map(s => `<option value="${s}" ${u.shift===s?'selected':''}>${s||'—'}</option>`).join('')}
          </select>
        </td>
        <td><input type="checkbox" class="cell-rts"  ${Number(u.rts_authority)===1?'checked':''}></td>
        <td><input type="checkbox" class="cell-insp" ${Number(u.inspection_authority)===1?'checked':''}></td>
        <td class="muted small">${last}</td>
        <td>${Number(u.is_active)===1 ? '<span class="meter ok">active</span>' : '<span class="meter neutral">disabled</span>'}</td>
        <td>
          <div class="row-actions">
            <button class="btn-tiny" data-act="save"  ${dis}>Save</button>
            <button class="btn-tiny" data-act="reset">Reset PW</button>
            ${Number(u.is_active)===1
              ? `<button class="btn-tiny pm-action danger" data-act="deactivate" ${dis}>Deactivate</button>`
              : `<button class="btn-tiny" data-act="reactivate">Reactivate</button>`}
          </div>
        </td>
      </tr>`;
    }).join('');
    $$('#user-tbody [data-act]').forEach(b => b.addEventListener('click', onAction));
  }

  async function onAction(e){
    const tr = e.target.closest('tr');
    const id = Number(tr.dataset.id);
    const act = e.target.dataset.act;
    e.target.disabled = true;
    try {
      if (act === 'save') {
        await jfetch('api/users.php?action=update', {method:'POST', body: JSON.stringify({
          user_id: id,
          role:    tr.querySelector('.cell-role').value,
          station: tr.querySelector('.cell-station').value || null,
          shift:   tr.querySelector('.cell-shift').value || null,
          rts_authority:        tr.querySelector('.cell-rts').checked,
          inspection_authority: tr.querySelector('.cell-insp').checked,
        })});
        toast('Saved.');
      } else if (act === 'reset') {
        const pw = prompt('New password (min 8 chars):');
        if (!pw) { e.target.disabled = false; return; }
        await jfetch('api/users.php?action=reset_password', {method:'POST', body: JSON.stringify({user_id:id, password: pw})});
        toast('Password reset. Their sessions are now revoked.');
      } else if (act === 'deactivate') {
        if (!confirm('Deactivate this user? Their audit history is preserved.')) { e.target.disabled=false; return; }
        await jfetch('api/users.php?action=deactivate', {method:'POST', body: JSON.stringify({user_id:id})});
        toast('User deactivated.');
        load();
      } else if (act === 'reactivate') {
        await jfetch('api/users.php?action=reactivate', {method:'POST', body: JSON.stringify({user_id:id})});
        toast('User reactivated.');
        load();
      }
    } catch (err) {
      alert('Failed: ' + (err.payload?.error || err.message));
    } finally { e.target.disabled = false; }
  }

  $('#add-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = Object.fromEntries(fd.entries());
    body.rts_authority        = !!body.rts_authority;
    body.inspection_authority = !!body.inspection_authority;
    try {
      await jfetch('api/users.php?action=create', {method:'POST', body: JSON.stringify(body)});
      e.target.reset();
      toast('User created.');
      load();
    } catch (err) {
      alert('Create failed: ' + (err.payload?.error || err.message));
    }
  });

  load();
})();
</script>
</body>
</html>
