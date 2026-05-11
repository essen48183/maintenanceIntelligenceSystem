(() => {
  'use strict';

  const boot = window.MIS_BOOT || { authenticated: false, user: null };
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const state = {
    user: boot.user,
    airframeId: 1,
    airframes: [],
    systems: [],
    severityFilter: 'ALL',
    systemFilter: 'ALL',
    search: '',
    activeFault: null,
    issues: [],
  };

  // ---------- API ----------
  const api = {
    async login(username, password) {
      return jfetch('api/auth.php?action=login', {
        method: 'POST',
        body: JSON.stringify({ username, password }),
      });
    },
    async logout() { return jfetch('api/auth.php?action=logout', { method: 'POST' }); },
    async me()      { return jfetch('api/auth.php?action=me'); },
    async fleet()   { return jfetch('api/fleet.php'); },
    async faults(airframeId) { return jfetch(`api/faults.php?action=list&airframe_id=${airframeId}`); },
    async fault(id) { return jfetch(`api/faults.php?action=detail&id=${id}`); },
    async ai(faultId, message) {
      return jfetch('api/ai.php?action=ask', {
        method: 'POST',
        body: JSON.stringify({ fault_id: faultId, message }),
      });
    },
    async aiHistory(faultId) { return jfetch(`api/ai.php?action=history&fault_id=${faultId}`); },
    async tasksList(faultId) { return jfetch(`api/tasks.php?action=list&fault_id=${faultId}`); },
    async taskAdd(faultId, title, ticketId) {
      return jfetch('api/tasks.php?action=add', {
        method: 'POST',
        body: JSON.stringify({ fault_id: faultId, title, ticket_id: ticketId || null }),
      });
    },
    async taskStatus(taskId, status, holdup) {
      return jfetch('api/tasks.php?action=status', {
        method: 'POST',
        body: JSON.stringify({ task_id: taskId, status, holdup: holdup || null }),
      });
    },
    async taskDelete(taskId) {
      return jfetch('api/tasks.php?action=delete', {
        method: 'POST',
        body: JSON.stringify({ task_id: taskId }),
      });
    },
    async taskSuggest(faultId) {
      return jfetch('api/tasks.php?action=suggest', {
        method: 'POST',
        body: JSON.stringify({ fault_id: faultId }),
      });
    },
  };

  async function jfetch(path, opts = {}) {
    const headers = Object.assign(
      { 'Accept': 'application/json' },
      opts.body ? { 'Content-Type': 'application/json' } : {},
      opts.headers || {}
    );
    const res = await fetch(path, Object.assign({ credentials: 'same-origin' }, opts, { headers }));
    const text = await res.text();
    let data;
    try { data = text ? JSON.parse(text) : {}; } catch { data = { error: 'bad_json', raw: text }; }
    if (!res.ok) {
      const err = new Error(data.error || ('http_' + res.status));
      err.payload = data;
      err.status = res.status;
      throw err;
    }
    return data;
  }

  // ---------- LOGIN ----------
  function showLogin() {
    $('#app').dataset.state = 'login';
    $('#login-screen').hidden = false;
    $('#topbar').hidden = true;
    $('#layout').hidden = true;
    $$('.footer').forEach(f => f.hidden = true);
  }

  function showApp() {
    $('#app').dataset.state = 'app';
    $('#login-screen').hidden = true;
    $('#topbar').hidden = false;
    $('#layout').hidden = false;
    $$('.footer').forEach(f => f.hidden = false);
    const chip = $('#user-chip');
    chip.innerHTML = `${escapeHTML(state.user.full_name)} <span class="role-mini">· ${escapeHTML(state.user.role)}</span>`;
    document.body.dataset.role = state.user.role;
    const ro = $('#readonly-pill');
    if (ro) ro.hidden = state.user.role !== 'readonly';
    const gear = $('#admin-gear');
    if (gear) gear.hidden = state.user.role !== 'admin';
  }

  $('#login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const err = $('#login-error');
    err.hidden = true;
    try {
      const r = await api.login(fd.get('username'), fd.get('password'));
      state.user = r.user;
      showApp();
      bootApp();
    } catch (e) {
      err.textContent = e.payload?.error === 'invalid_credentials'
        ? 'Username or password is incorrect.'
        : 'Sign-in failed: ' + (e.payload?.error || e.message);
      err.hidden = false;
    }
  });

  $('#logout-btn').addEventListener('click', async () => {
    try { await api.logout(); } catch {}
    state.user = null;
    showLogin();
  });

  // ---------- CLOCK ----------
  function tickClock() {
    const d = new Date();
    const months = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
    const utc = `${months[d.getUTCMonth()]} ${d.getUTCDate()}, ${d.getUTCFullYear()}  ${String(d.getUTCHours()).padStart(2,'0')}:${String(d.getUTCMinutes()).padStart(2,'0')} UTC`;
    const el = $('#clock'); if (el) el.textContent = utc;
  }
  setInterval(tickClock, 1000);
  tickClock();

  // ---------- BOOT ----------
  async function bootApp() {
    const fleet = await api.fleet();
    state.airframes = fleet.airframes;
    state.systems = fleet.systems;
    renderFleet();
    renderSystemFilter();
    await refreshFaults();
  }

  function renderFleet() {
    const ul = $('#fleet-list');
    ul.innerHTML = '';
    state.airframes.forEach(a => {
      const li = document.createElement('li');
      li.dataset.id = a.id;
      if (a.id === state.airframeId) li.classList.add('active');
      li.innerHTML = `
        <span class="ic">✈</span>
        <span class="name">
          <strong>${a.code}</strong>
          <div class="muted small">${a.manufacturer.toUpperCase()}</div>
        </span>
        <span class="badge">${a.active_faults}</span>`;
      li.addEventListener('click', () => {
        state.airframeId = Number(a.id);
        renderFleet();
        $('#airframe-title').textContent = a.code;
        $('#airframe-sub').textContent = a.model;
        refreshFaults();
      });
      ul.appendChild(li);
    });
    const cur = state.airframes.find(a => a.id === state.airframeId);
    if (cur) {
      $('#airframe-title').textContent = cur.code;
      $('#airframe-sub').textContent = cur.model;
    }
  }

  function renderSystemFilter() {
    const ul = $('#system-filter');
    ul.innerHTML = '';
    const all = document.createElement('li');
    all.className = 'chip active';
    all.dataset.sys = 'ALL';
    all.innerHTML = '<span class="dot all"></span> All';
    ul.appendChild(all);
    state.systems.forEach(s => {
      const li = document.createElement('li');
      li.className = 'chip';
      li.dataset.sys = s.code;
      li.innerHTML = `<span class="dot all"></span> ${s.name}`;
      ul.appendChild(li);
    });
    ul.addEventListener('click', e => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#system-filter .chip').forEach(x => x.classList.remove('active'));
      c.classList.add('active');
      state.systemFilter = c.dataset.sys;
      renderIssues();
    });
  }

  $('#severity-filter').addEventListener('click', e => {
    const c = e.target.closest('.chip'); if (!c) return;
    $$('#severity-filter .chip').forEach(x => x.classList.remove('active'));
    c.classList.add('active');
    state.severityFilter = c.dataset.sev;
    renderIssues();
  });

  $('#search-box').addEventListener('input', e => {
    state.search = e.target.value.trim().toLowerCase();
    renderIssues();
  });

  // ---------- FAULTS ----------
  async function refreshFaults() {
    const r = await api.faults(state.airframeId);
    state.kpis = r.kpis;
    state.issues = r.faults;
    renderKpis();
    renderIssues();
    if (state.issues.length) loadFault(state.issues[0].id);
    else $('#detail-card').innerHTML = '<div class="muted center-pad">No active issues for this airframe.</div>';
  }

  function renderKpis() {
    const k = state.kpis || {};
    const tile = (cls, num, lbl, sub, icon) => `
      <div class="kpi ${cls}">
        <div class="lbl">${lbl}</div>
        <div class="num">${num ?? 0}</div>
        <div class="sub">${sub ?? ''}</div>
        <div class="ic">${icon}</div>
      </div>`;
    $('#kpi-row').innerHTML =
      tile('crit',  k.CRITICAL,        'CRITICAL',         'Active Issues',    '⚠') +
      tile('high',  k.HIGH,            'HIGH',             'Active Issues',    '📈') +
      tile('total', k.TOTAL,           'TOTAL ACTIVE FAULTS', 'Across All Systems', '📋') +
      tile('occ',   k.OCCURRENCES_30D, 'TOTAL OCCURRENCES', 'This Period (30d)', '↗');
  }

  function filteredIssues() {
    return state.issues.filter(i => {
      if (state.severityFilter !== 'ALL' && i.severity !== state.severityFilter) return false;
      if (state.systemFilter   !== 'ALL') {
        const sys = state.systems.find(s => s.code === state.systemFilter);
        if (sys && i.system_name !== sys.name) return false;
      }
      if (state.search && !(`${i.title} ${i.fault_code}`).toLowerCase().includes(state.search)) return false;
      return true;
    });
  }

  function renderIssues() {
    const list = filteredIssues();
    $('#active-count').textContent = `(${list.length})`;
    const ul = $('#issue-list');
    ul.innerHTML = '';
    list.forEach((i, idx) => {
      const li = document.createElement('li');
      li.className = 'issue';
      li.dataset.id = i.id;
      if (state.activeFault === i.id) li.classList.add('active');
      const lastSeen = i.last_seen ? formatDate(i.last_seen) : '—';
      li.innerHTML = `
        <div class="issue-head">
          <span class="severity-pill ${i.severity}">${i.severity}</span>
          <span class="issue-when">${lastSeen}</span>
        </div>
        <div class="issue-title">${idx + 1}. ${escapeHTML(i.title)}</div>
        <div class="issue-meta">
          <span><span class="dot all"></span> ${escapeHTML(i.system_name)} · ${i.occurrences} occurrence${i.occurrences === 1 ? '' : 's'}</span>
          <span>Tail: ${escapeHTML(i.affected_tails || '—')}</span>
        </div>`;
      li.addEventListener('click', () => loadFault(i.id));
      ul.appendChild(li);
    });
  }

  async function loadFault(id) {
    state.activeFault = id;
    renderIssues();
    const { fault } = await api.fault(id);
    renderDetail(fault);
    renderAiContext(fault);
    loadAiHistory(id);
    loadTasks(id);
  }

  // ---------- TASKS (read-only summary; full interaction lives in CMMS portal) ----------
  async function loadTasks(faultId) {
    try {
      const { tasks } = await api.tasksList(faultId);
      renderTasks(tasks);
    } catch (e) {
      const list = $('#task-list');
      if (list) list.innerHTML = `<div class="muted small">Tasks unavailable: ${escapeHTML(e.message)}</div>`;
    }
  }

  function renderTasks(tasks) {
    const list = $('#task-list');
    if (!list) return;
    const summary = $('#tasks-summary');
    if (summary) {
      const counts = (tasks || []).reduce((a, t) => (a[t.status] = (a[t.status] || 0) + 1, a), {});
      summary.textContent = (tasks && tasks.length)
        ? `(${tasks.length} · ${counts.complete || 0} done · ${counts.in_progress || 0} in progress · ${counts.blocked || 0} blocked)`
        : '(empty)';
    }
    if (!tasks || !tasks.length) {
      list.innerHTML = '<div class="muted small task-empty">No tasks yet. Open the <a href="#" id="tasks-portal-link-empty">Maintenance Portal</a> to add tasks or generate an AI starter list.</div>';
      $('#tasks-portal-link-empty')?.addEventListener('click', e => { e.preventDefault(); openCmmsWindow(); });
      return;
    }
    list.innerHTML = tasks.map(renderTaskRowReadOnly).join('');
  }

  function renderTaskRowReadOnly(t) {
    const checked = t.status === 'complete';
    const statusClass = `status-${t.status}`;
    const sourceTag = t.source === 'ai'
      ? '<span class="task-source ai" title="Suggested by AI">✦ AI</span>'
      : '';
    let signoff = '';
    if (t.status === 'complete' && t.completed_by_name) {
      signoff = `<span class="task-signoff">Signed off by <strong>${escapeHTML(t.completed_by_name)}</strong>${t.completed_by_role ? ' · ' + escapeHTML(t.completed_by_role) : ''} · ${formatDate(t.completed_at)} ${formatTime(t.completed_at)}</span>`;
    }
    let holdup = '';
    if (t.status === 'blocked' && t.holdup_reason) {
      holdup = `<span class="task-holdup">⚠ Holdup: <strong>${escapeHTML(t.holdup_reason)}</strong></span>`;
    }
    return `
      <div class="task-row readonly ${statusClass}" data-id="${t.id}">
        <span class="task-check static" aria-hidden="true">${checked ? '✓' : ''}</span>
        <div class="task-body">
          <div class="task-title-row">
            <span class="task-title">${escapeHTML(t.title)}</span>
            ${sourceTag}
            <span class="task-status-pill task-${t.status}">${t.status.replace('_',' ')}</span>
          </div>
          ${signoff}
          ${holdup}
        </div>
      </div>`;
  }

  // Collapse / expand
  document.addEventListener('click', (e) => {
    const t = e.target;
    if (t && t.id === 'tasks-toggle') {
      const block = document.querySelector('#tasks-block');
      const open = t.getAttribute('aria-expanded') === 'true';
      t.setAttribute('aria-expanded', open ? 'false' : 'true');
      t.textContent = open ? '▸' : '▾';
      block.classList.toggle('collapsed', open);
    }
    if (t && (t.id === 'tasks-portal-link' || t.id === 'tasks-portal-link-2')) {
      e.preventDefault();
      openCmmsWindow();
    }
    if (t && t.id === 'open-portal-btn') {
      openCmmsWindow();
    }
  });

  function openCmmsWindow() {
    if (!state.activeFault) return;
    const url = `cmms.php?fault_id=${state.activeFault}`;
    const name = `mis-cmms-${state.activeFault}`;
    const features = 'popup=yes,resizable=yes,scrollbars=yes,width=1280,height=900,left=80,top=40';
    const w = window.open(url, name, features);
    if (!w) {
      alert('Pop-up blocked. Allow pop-ups for this site to open the Maintenance Portal.');
      return;
    }
    w.focus();
  }

  function renderDetail(f) {
    const lastSeen = f.recent_occurrences[0]?.occurred_at
      ? `LAST SEEN: ${formatDate(f.recent_occurrences[0].occurred_at).toUpperCase()}` : '';
    const trendDir = trendDirection(f.trend);
    const html = `
      <div class="detail-head">
        <span class="severity-pill ${f.severity}">${f.severity}</span>
        <span class="detail-id">ISSUE ID: ${escapeHTML(f.fault_code)}</span>
        <span class="footer-spacer"></span>
        <span class="detail-last-seen">${lastSeen}</span>
      </div>
      <h2 class="detail-title">${escapeHTML(f.title)}</h2>
      <div class="detail-meta-row">
        <span>◆ System: <strong>${escapeHTML(f.system_name)}</strong></span>
        <span>◇ ${f.recent_occurrences.length} Occurrences</span>
        <span>↗ Trend: <strong style="color:${trendDir.color}">${trendDir.label}</strong></span>
      </div>
      <div class="detail-meta-row">
        <span>Affected Tails: <strong>${escapeHTML((f.affected_tail_numbers || []).join(', ') || '—')}</strong></span>
        <span>ATA: <strong>${escapeHTML(f.ata_chapter || '—')}</strong></span>
      </div>
      <div class="detail-grid">
        <div>
          <div class="detail-block">
            <h4>DESCRIPTION</h4>
            <p style="margin:0 0 14px;line-height:1.5;">${escapeHTML(f.description || '')}</p>
          </div>
          <div class="detail-block">
            <h4>AFFECTED SYSTEMS</h4>
            <div class="affected-chips">${(f.affected_labels || []).map(l => `<span class="chip2">${escapeHTML(l)}</span>`).join('') || '<span class="muted">—</span>'}</div>
          </div>
          <div class="detail-block" style="margin-top:14px;">
            <h4>TREND (14d)</h4>
            ${renderTrend(f.trend)}
          </div>
          <div class="detail-block" style="margin-top:14px;">
            <h4>RECENT OCCURRENCES</h4>
            ${renderRecent(f.recent_occurrences)}
          </div>
        </div>
        <div>
          <div class="detail-block" id="tasks-block">
            <div class="block-head">
              <h4>
                <button class="tasks-toggle" id="tasks-toggle" aria-expanded="true" title="Show / hide tasklist">▾</button>
                TASKS
                <span class="muted small" id="tasks-summary"></span>
              </h4>
              <a class="tasks-portal-link" id="tasks-portal-link" href="#" title="Open the Maintenance Portal to manage tasks">Manage in portal ↗</a>
            </div>
            <div class="task-list readonly" id="task-list"><div class="muted small">Loading…</div></div>
            <div class="tasks-portal-hint muted small">
              Tasks are managed in the <a href="#" id="tasks-portal-link-2">Maintenance Portal</a> — open the portal to add, complete, block, or delete.
            </div>
          </div>
          <div class="detail-block" style="margin-top:14px;">
            <h4>REFERENCE DOCUMENTS</h4>
            <div class="refdocs">
              ${(f.reference_documents || []).map(d => `
                <a class="refdoc" href="api/documents.php?action=download&id=${d.id}" data-doc-id="${d.id}" data-doc-title="${escapeAttr(d.title)}">
                  <span class="refdoc-mark"></span>
                  <span>
                    <div class="refdoc-title">${escapeHTML(d.title)}</div>
                    <div class="refdoc-sub">${escapeHTML(d.subtitle || '')}</div>
                  </span>
                </a>
              `).join('') || '<span class="muted small">No reference documents linked.</span>'}
            </div>
            <button class="open-portal" id="open-portal-btn">OPEN IN MAINTENANCE PORTAL ↗</button>
          </div>
        </div>
      </div>`;
    $('#detail-card').innerHTML = html;

    // Reference docs → open in their own window so you can stack them across screens
    $$('#detail-card .refdoc').forEach(a => {
      a.addEventListener('click', e => {
        e.preventDefault();
        openPdfInOwnWindow(a.getAttribute('href'), a.dataset.docTitle, a.dataset.docId);
      });
    });
  }

  function renderTrend(points) {
    if (!points || !points.length) return '<div class="muted small">No data</div>';
    const w = 380, h = 140, pad = 24;
    const max = Math.max(...points.map(p => Number(p.n)), 1);
    const xs = (i) => pad + i * ((w - pad*2) / Math.max(points.length - 1, 1));
    const ys = (n) => h - pad - (Number(n) / max) * (h - pad*2);
    const path = points.map((p,i) => `${i?'L':'M'}${xs(i).toFixed(1)},${ys(p.n).toFixed(1)}`).join(' ');
    const dots = points.map((p,i) => `<circle cx="${xs(i).toFixed(1)}" cy="${ys(p.n).toFixed(1)}" r="3" fill="#E51937"/>`).join('');
    const labels = points.map((p,i) => i % Math.ceil(points.length/6) === 0
      ? `<text x="${xs(i)}" y="${h-6}" font-size="10" fill="#7C8DA8" text-anchor="middle">${shortDate(p.d)}</text>` : '').join('');
    return `<svg class="trend-svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
      <path d="${path}" fill="none" stroke="#E51937" stroke-width="2"/>
      ${dots}
      ${labels}
    </svg>`;
  }

  function renderRecent(rows) {
    if (!rows || !rows.length) return '<div class="muted small">No occurrences recorded.</div>';
    return `<table class="recent-table">
      <thead><tr><th>DATE / TIME (UTC)</th><th>TAIL NUMBER</th><th>FLIGHT #</th><th>PHASE</th><th>REPORT TYPE</th></tr></thead>
      <tbody>
        ${rows.slice(0,8).map(r => `<tr>
          <td>${formatDate(r.occurred_at)} ${formatTime(r.occurred_at)}</td>
          <td>${escapeHTML(r.tail_number)}</td>
          <td>${escapeHTML(r.flight_number || '')}</td>
          <td>${escapeHTML(r.phase || '')}</td>
          <td>${escapeHTML(r.report_type || '')}</td>
        </tr>`).join('')}
      </tbody>
    </table>`;
  }

  function trendDirection(points) {
    if (!points || points.length < 2) return { label: 'Stable', color: '#B8C5D9' };
    const first = Number(points[0].n), last = Number(points[points.length - 1].n);
    if (last > first) return { label: 'Increasing', color: '#FF8C00' };
    if (last < first) return { label: 'Decreasing', color: '#3DDC97' };
    return { label: 'Stable', color: '#B8C5D9' };
  }

  // ---------- AI ----------
  async function loadAiHistory(faultId) {
    $('#ai-thread').innerHTML = '';
    try {
      const { messages } = await api.aiHistory(faultId);
      messages.forEach(m => appendMsg(m.role, m.content, m.created_at));
    } catch {}
  }

  function renderAiContext(f) {
    const ctx = $('#ai-context');
    ctx.innerHTML = `
      <strong>Diagnostic AI online for ${escapeHTML(f.airframe_code)}</strong><br>
      Fault: <strong>${escapeHTML(f.title)}</strong><br>
      Severity: <span class="severity-pill ${f.severity}" style="margin:0 6px 0 0">${f.severity}</span>
      Occurrences: <strong>${f.recent_occurrences.length}</strong> ·
      Trend: <strong>${trendDirection(f.trend).label}</strong>
      <br><span class="muted small">Ask me anything about this fault, or tap a diagnostic question.</span>`;
    const sug = $('#ai-suggestions');
    sug.innerHTML = '';
    (f.diagnostic_questions || []).slice(0, 3).forEach(q => {
      const b = document.createElement('button');
      b.className = 'ai-suggestion';
      b.textContent = q.question;
      b.addEventListener('click', () => { $('#ai-input').value = q.question; $('#ai-input').focus(); });
      sug.appendChild(b);
    });
  }

  function appendMsg(role, content, ts) {
    const div = document.createElement('div');
    div.className = 'msg ' + (role === 'user' ? 'user' : 'assistant');
    div.innerHTML = escapeHTML(content || '').replace(/\n/g, '<br>');
    if (ts) {
      const t = document.createElement('span'); t.className = 'ts';
      t.textContent = formatTime(ts);
      div.appendChild(t);
    }
    $('#ai-thread').appendChild(div);
    $('#ai-body').scrollTop = $('#ai-body').scrollHeight;
  }

  $('#ai-form').addEventListener('submit', async e => {
    e.preventDefault();
    const inp = $('#ai-input');
    const msg = inp.value.trim();
    if (!msg || !state.activeFault) return;
    appendMsg('user', msg, new Date());
    inp.value = '';
    inp.disabled = true;
    try {
      const r = await api.ai(state.activeFault, msg);
      appendMsg('assistant', r.text, new Date());
    } catch (err) {
      appendMsg('assistant', '⚠ ' + (err.payload?.error || err.message), new Date());
    } finally {
      inp.disabled = false; inp.focus();
    }
  });

  $('#ai-collapse').addEventListener('click', () => {
    const body = $('#ai-body'), sug = $('#ai-suggestions'), form = $('#ai-form');
    const collapsed = body.hidden;
    body.hidden = !collapsed; sug.hidden = !collapsed; form.hidden = !collapsed;
    $('#ai-collapse').textContent = collapsed ? '—' : '+';
  });

  // ---------- PDF in own window ----------
  function openPdfInOwnWindow(url, title, docId) {
    const name = `mis-pdf-${docId || Math.random().toString(36).slice(2,8)}-${Date.now()}`;
    const features = 'popup=yes,resizable=yes,scrollbars=yes,width=900,height=1100,left=120,top=60';
    const w = window.open(url, name, features);
    if (!w) {
      alert('Pop-up blocked. Allow pop-ups for this site to open documents in their own window.');
      return;
    }
    w.focus();
    if (title) {
      try { w.document.title = title; } catch {}
    }
  }

  // ---------- HELPERS ----------
  function escapeHTML(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[c])); }
  function escapeAttr(s){ return escapeHTML(s).replace(/`/g, '&#96;'); }
  function formatDate(ts){
    if (!ts) return '';
    const d = new Date(String(ts).replace(' ', 'T') + 'Z');
    const m = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
    return `${m[d.getUTCMonth()]} ${d.getUTCDate()}, ${d.getUTCFullYear()}`;
  }
  function formatTime(ts){
    if (!ts) return '';
    const d = new Date(String(ts).replace(' ', 'T') + 'Z');
    return `${String(d.getUTCHours()).padStart(2,'0')}:${String(d.getUTCMinutes()).padStart(2,'0')}`;
  }
  function shortDate(ts){
    const d = new Date(String(ts).replace(' ', 'T') + 'Z');
    const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${m[d.getUTCMonth()]} ${d.getUTCDate()}`;
  }

  // ---------- PWA ----------
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js').catch(()=>{});
  }
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferredPrompt = e;
    $('#install-btn').hidden = false;
  });
  $('#help-btn')?.addEventListener('click', () => {
    const w = window.open('help.php?doc=use', 'mis-help',
      'popup=yes,resizable=yes,scrollbars=yes,width=920,height=900,left=160,top=40');
    if (!w) alert('Pop-up blocked. Allow pop-ups for this site to open the help window.');
    else w.focus();
  });

  $('#install-btn').addEventListener('click', async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    await deferredPrompt.userChoice;
    deferredPrompt = null;
    $('#install-btn').hidden = true;
  });

  // ---------- BOOT FLOW ----------
  if (boot.authenticated) {
    showApp();
    bootApp();
  } else {
    showLogin();
  }
})();
