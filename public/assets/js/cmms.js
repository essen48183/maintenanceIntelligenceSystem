(() => {
  'use strict';
  const boot = window.MIS_CMMS_BOOT || {};
  const $ = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));
  const FAULT_ID = boot.fault_id;
  const TICKET_ID = boot.ticket_id || null;

  // ---------- API ----------
  async function jfetch(path, opts={}){
    const headers = Object.assign({'Accept':'application/json'}, opts.body?{'Content-Type':'application/json'}:{}, opts.headers||{});
    const res = await fetch(path, Object.assign({credentials:'same-origin'}, opts, {headers}));
    const text = await res.text();
    let data; try{ data = text? JSON.parse(text): {}; }catch{ data={error:'bad_json',raw:text}; }
    if (!res.ok){ const e = new Error(data.error||('http_'+res.status)); e.payload=data; e.status=res.status; throw e; }
    return data;
  }
  const api = {
    summary:    () => jfetch(`api/cmms.php?fault_id=${FAULT_ID}`),
    tasksList:  () => jfetch(`api/tasks.php?action=list&fault_id=${FAULT_ID}`),
    taskAdd:    (title) => jfetch('api/tasks.php?action=add',     {method:'POST', body: JSON.stringify({fault_id:FAULT_ID, title, ticket_id: TICKET_ID})}),
    taskStatus: (taskId, status, holdup) => jfetch('api/tasks.php?action=status', {method:'POST', body: JSON.stringify({task_id:taskId, status, holdup: holdup||null})}),
    taskDelete: (taskId) => jfetch('api/tasks.php?action=delete', {method:'POST', body: JSON.stringify({task_id:taskId})}),
    taskSuggest:() => jfetch('api/tasks.php?action=suggest',      {method:'POST', body: JSON.stringify({fault_id:FAULT_ID})}),
    taskApproveProposal: (proposal) => jfetch('api/tasks.php?action=add_proposed', {method:'POST', body: JSON.stringify({fault_id:FAULT_ID, title: proposal.title, rationale: proposal.rationale || null, ticket_id: TICKET_ID})}),
    pmList:     () => jfetch(`api/pm.php?action=list&fault_id=${FAULT_ID}`),
    pmAwaiting: () => jfetch('api/pm.php?action=awaiting'),
    pmStart:    (id) => jfetch('api/pm.php?action=start',    {method:'POST', body: JSON.stringify({item_id:id})}),
    pmComplete: (id, notes) => jfetch('api/pm.php?action=complete', {method:'POST', body: JSON.stringify({item_id:id, notes})}),
    pmSignoff:  (id, notes) => jfetch('api/pm.php?action=signoff',  {method:'POST', body: JSON.stringify({item_id:id, notes})}),
    pmReject:   (id, reason) => jfetch('api/pm.php?action=reject',  {method:'POST', body: JSON.stringify({item_id:id, reason})}),
  };

  // ---------- HELPERS ----------
  const escHTML = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
  const escAttr = s => escHTML(s).replace(/`/g,'&#96;');
  function fmtDate(ts){
    if (!ts) return '—';
    const d = new Date(String(ts).replace(' ','T')+'Z');
    const m = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
    return `${m[d.getUTCMonth()]} ${d.getUTCDate()}, ${d.getUTCFullYear()}`;
  }
  function fmtTime(ts){
    if (!ts) return '';
    const d = new Date(String(ts).replace(' ','T')+'Z');
    return `${String(d.getUTCHours()).padStart(2,'0')}:${String(d.getUTCMinutes()).padStart(2,'0')}`;
  }
  function fmtDateTime(ts){ return ts ? `${fmtDate(ts)} ${fmtTime(ts)}` : '—'; }

  // ---------- CLOCK ----------
  function tickClock(){
    const d = new Date();
    const m = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
    const utc = `${m[d.getUTCMonth()]} ${d.getUTCDate()}, ${d.getUTCFullYear()}  ${String(d.getUTCHours()).padStart(2,'0')}:${String(d.getUTCMinutes()).padStart(2,'0')} UTC`;
    const el = $('#clock'); if (el) el.textContent = utc;
  }
  setInterval(tickClock, 1000); tickClock();

  // ---------- KPIs / SUMMARY ----------
  let summary = null;
  async function loadAll(){
    summary = await api.summary();
    renderKpis(summary);
    renderAssets(summary.tails);
    renderWorkOrders(summary.work_orders);
    renderDowntime(summary.fault.trend);
    renderDocs(summary.fault.reference_documents);
    renderActivity(summary);
    await loadTasks();
    await loadPM();
    await loadSignoffQueueIfInspector();
    $('#last-refresh').textContent = 'updated ' + fmtTime(summary.now);
  }

  function renderKpis(s){
    const open = s.work_orders.filter(w => w.status==='open' || w.status==='in_progress' || w.status==='on_hold').length;
    $('#kpi-wo-open').textContent  = open;
    $('#kpi-wo-total').textContent = `of ${s.work_orders.length} total`;
    $('#kpi-mttr').textContent     = s.mttr_hours != null ? `${s.mttr_hours} h` : '—';
    $('#kpi-downtime').textContent = `${s.downtime_hours} h`;
    const last = s.fault.recent_occurrences[0];
    $('#kpi-last').textContent     = last ? fmtDate(last.occurred_at) : '—';
    $('#kpi-last-tail').textContent= last ? `${last.tail_number} · ${last.flight_number||''}` : '—';
  }

  function renderAssets(tails){
    const el = $('#asset-grid');
    $('#asset-count').textContent = tails.length ? `(${tails.length} tails)` : '';
    if (!tails.length){ el.innerHTML = '<div class="muted small">No tails affected.</div>'; return; }
    el.innerHTML = '<div class="asset-grid">' + tails.map(t => `
      <div class="asset-card">
        <h5>✈ ${escHTML(t.tail_number)}</h5>
        <div class="muted small">${escHTML(t.operator)} · ${t.occurrences} occurrence${t.occurrences===1?'':'s'}</div>
        <div class="asset-meta">
          <div>Flight Hours <strong>${t.flight_hours.toLocaleString()}</strong></div>
          <div>Cycles <strong>${t.flight_cycles.toLocaleString()}</strong></div>
          <div>Last A-Check <strong>${escHTML(t.last_a_check)}</strong></div>
          <div>Next PM <strong>${escHTML(t.next_pm_due)}</strong></div>
        </div>
      </div>
    `).join('') + '</div>';
  }

  function renderWorkOrders(rows){
    $('#wo-count').textContent = rows.length ? `(${rows.length})` : '(0)';
    const el = $('#wo-list');
    if (!rows.length){ el.innerHTML = '<div class="muted small">No work orders linked to this issue yet.</div>'; return; }
    el.innerHTML = rows.map(w => {
      const sev  = `<span class="severity-pill ${w.severity}">${w.severity}</span>`;
      const stat = `<span class="meter ${woMeter(w.status)}">${w.status.replace('_',' ')}</span>`;
      return `
        <div class="wo-row">
          <span class="wo-num">${escHTML(w.ticket_number)}</span>
          <div>
            <div class="wo-title">${escHTML(w.title)}</div>
            <div class="wo-meta">${escHTML(w.tail_number || '—')} · opened ${fmtDate(w.opened_at)} · assigned ${escHTML(w.assigned_to_name || '—')}</div>
          </div>
          ${sev}
          ${stat}
        </div>`;
    }).join('');
  }
  function woMeter(s){
    return ({ open:'warn', in_progress:'warn', on_hold:'neutral', closed:'ok', cancelled:'neutral' })[s] || 'neutral';
  }

  function renderDowntime(trend){
    const el = $('#downtime-block');
    if (!trend || !trend.length){ el.innerHTML = '<div class="muted small">No downtime data.</div>'; return; }
    const max = Math.max(...trend.map(p => Number(p.n)), 1);
    el.innerHTML = trend.map(p => {
      const pct = (Number(p.n) / max) * 100;
      return `<div class="dt-row">
        <span class="muted">${escHTML(p.d.slice(5))}</span>
        <div class="dt-bar"><div class="dt-bar-fill" style="width:${pct}%;"></div></div>
        <span>${p.n}</span>
      </div>`;
    }).join('');
  }

  function renderDocs(docs){
    const el = $('#docs-block');
    if (!docs || !docs.length){ el.innerHTML = '<div class="muted small">No reference documents linked.</div>'; return; }
    el.innerHTML = docs.map(d => `
      <a class="refdoc" href="api/documents.php?action=download&id=${d.id}" data-doc-id="${d.id}" data-doc-title="${escAttr(d.title)}">
        <span class="refdoc-mark"></span>
        <span>
          <div class="refdoc-title">${escHTML(d.title)}</div>
          <div class="refdoc-sub">${escHTML(d.subtitle || '')}</div>
        </span>
      </a>`).join('');
    $$('#docs-block .refdoc').forEach(a => a.addEventListener('click', e => {
      e.preventDefault();
      const name = `mis-pdf-${a.dataset.docId}-${Date.now()}`;
      const w = window.open(a.getAttribute('href'), name, 'popup=yes,resizable=yes,scrollbars=yes,width=900,height=1100');
      if (!w) alert('Pop-up blocked. Allow pop-ups to open documents in their own window.');
      else { try { w.document.title = a.dataset.docTitle; } catch {} w.focus(); }
    }));
  }

  function renderActivity(s){
    const events = (s.ticket_events || []).map(e => ({
      ts: e.created_at, actor: e.full_name, role: e.role, kind: 'ticket', label: e.event_type, body: e.body || ''
    }));
    const audit = (s.audit || []).map(a => ({
      ts: a.created_at, actor: a.full_name, role: a.role, kind: 'audit', label: a.action, body: ''
    }));
    const all = events.concat(audit).sort((a,b) => new Date(b.ts) - new Date(a.ts)).slice(0, 30);
    const el = $('#activity-block');
    if (!all.length){ el.innerHTML = '<div class="muted small">No activity yet for this issue.</div>'; return; }
    el.innerHTML = '<div class="activity-list">' + all.map(e => `
      <div class="activity-row">
        <span class="activity-when">${fmtDateTime(e.ts)}</span>
        <div>
          <div class="activity-action"><strong>${escHTML(e.label)}</strong>${e.body ? ` — ${escHTML(e.body.slice(0,180))}` : ''}</div>
          <div class="activity-actor">${escHTML(e.actor || 'system')}${e.role ? ' · ' + escHTML(e.role) : ''} · ${e.kind}</div>
        </div>
        <span class="muted small">${escHTML(e.kind)}</span>
      </div>`).join('') + '</div>';
  }

  // ---------- TASKS (full interaction) ----------
  async function loadTasks(){
    try {
      const { tasks } = await api.tasksList();
      renderTasks(tasks);
    } catch (e) {
      $('#task-list').innerHTML = `<div class="muted small">Tasks unavailable: ${escHTML(e.message)}</div>`;
    }
  }

  function renderTasks(tasks){
    const list = $('#task-list');
    const counts = tasks.reduce((acc,t) => (acc[t.status]=(acc[t.status]||0)+1, acc), {});
    $('#task-summary').textContent = tasks.length
      ? `(${tasks.length} · ${counts.complete||0} done · ${counts.in_progress||0} in progress · ${counts.blocked||0} blocked)`
      : '(empty)';
    if (!tasks.length){
      list.innerHTML = '<div class="muted small task-empty">No tasks yet. Add one below or click <strong>✦ AI suggest</strong> for a starting list.</div>';
      return;
    }
    list.innerHTML = tasks.map(renderRow).join('');
    $$('.task-row', list).forEach(row => {
      const id = Number(row.dataset.id);
      $('.task-check', row)?.addEventListener('click', () => {
        const target = row.classList.contains('status-complete') ? 'pending' : 'complete';
        change(id, target);
      });
      $$('.task-action', row).forEach(btn => btn.addEventListener('click', e => {
        e.stopPropagation();
        const next = btn.dataset.status;
        if (next === 'blocked'){
          const reason = prompt('What is the holdup? (e.g., awaiting part, awaiting inspection, awaiting NDT)');
          if (reason === null) return;
          change(id, 'blocked', reason);
        } else if (next === 'delete'){
          if (!confirm('Remove this task?')) return;
          del(id);
        } else change(id, next);
      }));
    });
  }
  function renderRow(t){
    const checked = t.status === 'complete';
    const sourceTag = t.source==='ai' ? '<span class="task-source ai" title="Suggested by AI">✦ AI</span>' : '';
    const signoff = (t.status==='complete' && t.completed_by_name)
      ? `<span class="task-signoff">Signed off by <strong>${escHTML(t.completed_by_name)}</strong>${t.completed_by_role?' · '+escHTML(t.completed_by_role):''} · ${fmtDate(t.completed_at)} ${fmtTime(t.completed_at)}</span>`
      : '';
    const holdup = (t.status==='blocked' && t.holdup_reason)
      ? `<span class="task-holdup">⚠ Holdup: <strong>${escHTML(t.holdup_reason)}</strong></span>` : '';
    const actions = `
      <div class="task-actions">
        ${t.status!=='in_progress' && t.status!=='complete' ? '<button class="task-action" data-status="in_progress" title="Mark in progress">▶</button>' : ''}
        ${t.status!=='blocked'    && t.status!=='complete' ? '<button class="task-action" data-status="blocked"     title="Mark blocked / holdup">⚠</button>' : ''}
        ${t.status==='blocked'    ? '<button class="task-action" data-status="pending"  title="Clear holdup">↺</button>' : ''}
        <button class="task-action danger" data-status="delete" title="Remove task">✕</button>
      </div>`;
    return `
      <div class="task-row status-${t.status}" data-id="${t.id}">
        <button class="task-check" aria-label="${checked?'Mark incomplete':'Mark complete'}">${checked?'✓':''}</button>
        <div class="task-body">
          <div class="task-title-row">
            <span class="task-title">${escHTML(t.title)}</span>
            ${sourceTag}
            <span class="task-status-pill task-${t.status}">${t.status.replace('_',' ')}</span>
          </div>
          ${t.description ? `<div class="task-desc">${escHTML(t.description)}</div>` : ''}
          ${signoff}
          ${holdup}
        </div>
        ${actions}
      </div>`;
  }
  async function change(id, status, holdup){
    try { const { tasks } = await api.taskStatus(id, status, holdup); renderTasks(tasks); }
    catch (e){ alert('Failed: ' + (e.payload?.error || e.message)); }
  }
  async function del(id){
    try { await api.taskDelete(id); loadTasks(); }
    catch (e){ alert('Failed: ' + (e.payload?.error || e.message)); }
  }

  document.addEventListener('submit', async e => {
    if (e.target?.id === 'task-add'){
      e.preventDefault();
      const inp = $('#task-add-input');
      const title = inp.value.trim();
      if (!title) return;
      inp.disabled = true;
      try { const { tasks } = await api.taskAdd(title); inp.value=''; renderTasks(tasks); }
      catch (e2){ alert('Failed: ' + (e2.payload?.error || e2.message)); }
      finally { inp.disabled = false; inp.focus(); }
    }
  });
  document.addEventListener('click', async e => {
    if (e.target?.id === 'ai-suggest-btn'){
      const btn = e.target;
      btn.disabled = true; btn.textContent = '✦ thinking…';
      try {
        const { proposals } = await api.taskSuggest();
        renderProposals(proposals || []);
      }
      catch (e2){ alert('AI suggest failed: ' + (e2.payload?.error || e2.message)); }
      finally { btn.disabled = false; btn.textContent = '✦ AI suggest'; }
    }
    // Approve a single AI proposal
    if (e.target?.classList.contains('proposal-approve')){
      const card = e.target.closest('.proposal-row');
      const proposal = JSON.parse(card.dataset.proposal);
      e.target.disabled = true;
      try {
        const { tasks } = await api.taskApproveProposal(proposal);
        renderTasks(tasks);
        card.remove();
        const remaining = document.querySelectorAll('.proposal-row').length;
        if (remaining === 0) document.getElementById('proposal-panel')?.remove();
      } catch (e2) {
        alert('Approve failed: ' + (e2.payload?.error || e2.message));
      } finally { e.target.disabled = false; }
    }
    if (e.target?.classList.contains('proposal-dismiss')){
      const card = e.target.closest('.proposal-row');
      card.remove();
      const remaining = document.querySelectorAll('.proposal-row').length;
      if (remaining === 0) document.getElementById('proposal-panel')?.remove();
    }
    if (e.target?.id === 'proposal-dismiss-all'){
      document.getElementById('proposal-panel')?.remove();
    }
    if (e.target?.id === 'proposal-approve-all'){
      const cards = Array.from(document.querySelectorAll('.proposal-row'));
      e.target.disabled = true; e.target.textContent = 'approving…';
      try {
        for (const card of cards) {
          const proposal = JSON.parse(card.dataset.proposal);
          const { tasks } = await api.taskApproveProposal(proposal);
          renderTasks(tasks);
        }
        document.getElementById('proposal-panel')?.remove();
      } catch (e2) {
        alert('Approve-all failed: ' + (e2.payload?.error || e2.message));
      } finally { if (e.target) { e.target.disabled = false; e.target.textContent = 'Approve all'; } }
    }
  });

  function renderProposals(proposals){
    document.getElementById('proposal-panel')?.remove();
    if (!proposals.length) { alert('AI returned no proposals.'); return; }
    const panel = document.createElement('div');
    panel.id = 'proposal-panel';
    panel.className = 'proposal-panel';
    panel.innerHTML = `
      <div class="proposal-head">
        <strong>✦ AI proposals — review &amp; approve before they become real tasks</strong>
        <span class="muted small">Advisory only · nothing is added until you approve</span>
        <div class="proposal-bulk">
          <button class="btn-tiny" id="proposal-approve-all">Approve all</button>
          <button class="btn-tiny" id="proposal-dismiss-all">Dismiss all</button>
        </div>
      </div>
      ${proposals.map(p => `
        <div class="proposal-row" data-proposal='${escAttr(JSON.stringify(p))}'>
          <div class="proposal-body">
            <div class="proposal-title">${escHTML(p.title || '')}</div>
            ${p.rationale ? `<div class="proposal-rationale">${escHTML(p.rationale)}</div>` : ''}
          </div>
          <div class="proposal-actions">
            <button class="btn-tiny proposal-approve" title="Add this task">＋ Add</button>
            <button class="btn-tiny proposal-dismiss" title="Dismiss this proposal">✕</button>
          </div>
        </div>
      `).join('')}
    `;
    const tasksCard = document.querySelector('#task-list')?.closest('.cmms-card-body');
    if (tasksCard) tasksCard.insertBefore(panel, tasksCard.firstChild);
  }

  // ---------- PM ----------
  async function loadPM(){
    try {
      const { items, counts } = await api.pmList();
      renderPM(items, counts);
    } catch (e) {
      $('#pm-block').innerHTML = `<div class="muted small">PM unavailable: ${escHTML(e.message)}</div>`;
    }
  }

  function renderPM(items, counts){
    const sum = $('#pm-summary');
    if (counts) {
      const parts = [];
      if (counts.overdue)             parts.push(`<span class="meter bad">${counts.overdue} overdue</span>`);
      if (counts.due_soon)            parts.push(`<span class="meter warn">${counts.due_soon} due soon</span>`);
      if (counts.in_progress)         parts.push(`<span class="meter neutral">${counts.in_progress} in progress</span>`);
      if (counts.awaiting_inspection) parts.push(`<span class="meter warn">${counts.awaiting_inspection} awaiting</span>`);
      if (counts.current)             parts.push(`<span class="meter ok">${counts.current} current</span>`);
      sum.innerHTML = parts.join(' ');
    }
    const el = $('#pm-block');
    if (!items.length){ el.innerHTML = '<div class="muted small">No PM items for this issue\'s tails.</div>'; return; }
    el.innerHTML = `
      <table class="cmms-table pm-table">
        <thead><tr>
          <th>Plan</th><th>Target</th><th>Trigger</th><th>Last</th><th>Next due</th><th>Status</th><th>Action</th>
        </tr></thead>
        <tbody>
          ${items.map(renderPmRow).join('')}
        </tbody>
      </table>`;
    $$('.pm-action', el).forEach(b => b.addEventListener('click', e => onPmAction(e, b)));
  }

  function renderPmRow(i){
    const target = i.target_kind === 'tail'
      ? `<strong>${escHTML(i.tail_number)}</strong>`
      : `<strong>${escHTML(i.component_type)} · ${escHTML(i.position||'')}</strong><br><span class="muted small">${escHTML(i.serial_number)} · ${escHTML(i.comp_tail_number||'')}</span>`;
    let trigger = '';
    switch (i.plan_trigger_type) {
      case 'calendar_days':    trigger = `Calendar · ${i.plan_interval_value}d`; break;
      case 'flight_hours':     trigger = `Hours · ${i.plan_interval_value} FH`;   break;
      case 'flight_cycles':    trigger = `Cycles · ${i.plan_interval_value} FC`;  break;
      case 'component_hours':  trigger = `Component · ${i.plan_interval_value} h`;break;
    }
    let last = '—';
    if (i.last_done_at)    last = fmtDate(i.last_done_at);
    if (i.last_done_hours) last += ` <span class="muted small">@ ${Number(i.last_done_hours).toLocaleString()} FH</span>`;
    let next = '—';
    if (i.next_due_at)     next = fmtDate(i.next_due_at);
    if (i.next_due_hours)  next = `FH ${Number(i.next_due_hours).toLocaleString()}`;
    if (i.next_due_cycles) next = `FC ${Number(i.next_due_cycles).toLocaleString()}`;
    const status = `<span class="meter ${pmMeter(i.status)}">${i.status.replace('_',' ')}</span>`;
    let aiNote = '';
    if (i.status === 'awaiting_inspection' && i.awaiting_inspection_by_name) {
      aiNote = `<div class="muted small">submitted by ${escHTML(i.awaiting_inspection_by_name)}</div>`;
    }
    if (i.plan_requires_inspection && i.status !== 'complete') {
      aiNote += '<div class="muted small">requires supervisor sign-off</div>';
    }
    const actions = pmActions(i);
    return `
      <tr data-id="${i.id}">
        <td><strong>${escHTML(i.plan_title)}</strong>${i.plan_ata ? `<div class="muted small">ATA ${escHTML(i.plan_ata)}</div>` : ''}</td>
        <td>${target}</td>
        <td>${trigger}</td>
        <td>${last}</td>
        <td>${next}</td>
        <td>${status}${aiNote}</td>
        <td>${actions}</td>
      </tr>`;
  }
  function pmMeter(s){ return ({current:'ok', due_soon:'warn', overdue:'bad', in_progress:'neutral', awaiting_inspection:'warn', complete:'ok'})[s] || 'neutral'; }
  function pmActions(i){
    const buttons = [];
    if (i.status === 'current' || i.status === 'due_soon' || i.status === 'overdue') {
      buttons.push(`<button class="btn-tiny pm-action" data-act="start" data-id="${i.id}">Start</button>`);
    }
    if (i.status === 'in_progress') {
      const cl = i.plan_requires_inspection ? 'btn-tiny pm-action' : 'btn-tiny pm-action';
      const lbl = i.plan_requires_inspection ? 'Done · for inspection' : 'Complete';
      buttons.push(`<button class="${cl}" data-act="complete" data-id="${i.id}">${lbl}</button>`);
    }
    if (i.status === 'awaiting_inspection') {
      buttons.push(`<button class="btn-tiny pm-action" data-act="signoff" data-id="${i.id}" title="Sign off (requires inspection authority)">✓ Sign off</button>`);
      buttons.push(`<button class="btn-tiny pm-action danger" data-act="reject" data-id="${i.id}" title="Send back for rework">✗ Reject</button>`);
    }
    if (!buttons.length) buttons.push('<span class="muted small">—</span>');
    return `<div class="pm-actions">${buttons.join('')}</div>`;
  }
  async function onPmAction(e, btn){
    e.stopPropagation();
    const id = Number(btn.dataset.id);
    const act = btn.dataset.act;
    btn.disabled = true;
    try {
      if (act === 'start')    await api.pmStart(id);
      if (act === 'complete') await api.pmComplete(id, null);
      if (act === 'signoff')  await api.pmSignoff(id, null);
      if (act === 'reject')   {
        const reason = prompt('Reject — what is the rework reason?');
        if (reason === null) { btn.disabled = false; return; }
        await api.pmReject(id, reason);
      }
      await loadPM();
      await loadSignoffQueueIfInspector();
    } catch (err) {
      alert('Action failed: ' + (err.payload?.error || err.message));
    } finally { btn.disabled = false; }
  }

  // ---------- SIGN-OFF QUEUE (visible if inspection_authority OR admin role) ----------
  async function loadSignoffQueueIfInspector(){
    const u = boot.user || {};
    const isInspector = u.role === 'admin' || Number(u.inspection_authority) === 1;
    const card = $('#signoff-card');
    if (!isInspector || !card) { if (card) card.hidden = true; return; }
    try {
      const { items } = await api.pmAwaiting();
      $('#signoff-count').textContent = items.length ? `(${items.length})` : '(0)';
      if (!items.length) {
        $('#signoff-block').innerHTML = '<div class="muted small">Queue clear. Nothing waiting on you.</div>';
      } else {
        $('#signoff-block').innerHTML = items.map(renderSignoffRow).join('');
        $$('.so-action', $('#signoff-block')).forEach(b => b.addEventListener('click', e => onSignoffAction(e, b)));
      }
      card.hidden = false;
    } catch (e) {
      $('#signoff-block').innerHTML = `<div class="muted small">Queue unavailable: ${escHTML(e.message)}</div>`;
      card.hidden = false;
    }
  }
  function renderSignoffRow(it){
    const where = it.tail_number
      ? escHTML(it.tail_number)
      : `${escHTML(it.component_type)} ${escHTML(it.position||'')} · ${escHTML(it.comp_tail_number||'')}`;
    return `
      <div class="signoff-row">
        <div>
          <div><strong>${escHTML(it.plan_title)}</strong> · ${where}</div>
          <div class="muted small">submitted by ${escHTML(it.submitted_by||'system')} · ${fmtDateTime(it.awaiting_inspection_at)}</div>
        </div>
        <div class="pm-actions">
          <button class="btn-tiny so-action" data-act="signoff" data-id="${it.id}">✓ Sign off</button>
          <button class="btn-tiny so-action danger" data-act="reject" data-id="${it.id}">✗ Reject</button>
        </div>
      </div>`;
  }
  async function onSignoffAction(e, btn){
    e.stopPropagation();
    const id = Number(btn.dataset.id);
    const act = btn.dataset.act;
    btn.disabled = true;
    try {
      if (act === 'signoff') await api.pmSignoff(id, null);
      if (act === 'reject')  {
        const reason = prompt('Reject — what is the rework reason?');
        if (reason === null) { btn.disabled = false; return; }
        await api.pmReject(id, reason);
      }
      await loadPM();
      await loadSignoffQueueIfInspector();
    } catch (err) {
      alert('Action failed: ' + (err.payload?.error || err.message));
    } finally { btn.disabled = false; }
  }

  document.getElementById('cmms-help-btn')?.addEventListener('click', () => {
    const w = window.open('help.php?doc=use', 'mis-help',
      'popup=yes,resizable=yes,scrollbars=yes,width=920,height=900,left=160,top=40');
    if (!w) alert('Pop-up blocked.');
    else w.focus();
  });

  loadAll().catch(e => alert('Load failed: ' + e.message));
})();
