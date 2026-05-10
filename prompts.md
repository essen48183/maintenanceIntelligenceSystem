# Project Prompt & Decision Log

This file captures the original user prompts and the architectural decisions
made in response to each, so future teammates can understand *why* the project
is shaped the way it is. Add new entries to the bottom as the project evolves.

---

## Session 1 — 2026-05-10

### Prompt 1 (initial scope) — Essen Davis

> i would like to create a website that can run as an app on both ios and
> android without app store. i want access levels admin, supervisor,
> maintenance, readonly. the backend should be such that it is php and mysql.
> it isnt meant to be used as a website but more of an app for maintenance
> personell to have access to all faa and manufactruer reference documents and
> to have an ai assistant to help guide maintenacne throught diagnosis and
> documentation of tasks. the html should have an install icon to install the
> progressive web app if it isn't already. it should run on chrome wihtout
> needing that also. there should be a login for more than read only access
> and that login should track what any employee did to assist with running a
> problem across multiple shifts. it a support ticket might be created by one
> person and picked up by another employee or supervisor and seamlessly
> continue documenting the tasks. this is just a photo of how i want it to
> appear. it is for delta and it's subsidiary Endeavor Air. the first
> airframe will be crj-900 but it will later expand to different boeing and
> airbus airframes. there should be unit tests built and run at any major
> change to confirm changes and improvmeents dont break prior function. and
> it will be run in mamp as long as possible locally, but at some point will
> migrate to a host, so all database should have backup and restore scripts.

(Attached image: dark-themed `CRJ FAULT TRACKER — MAINTENANCE INTELLIGENCE
SYSTEM` mockup with Delta branding, fleet sidebar, severity/system filters,
KPI cards, active issues list, fault detail panel, and AI diagnostic
assistant side panel.)

**Decisions captured from this prompt:**

| Topic | Decision | Why |
|---|---|---|
| Platform | Progressive Web App (PWA) — installable on iOS and Android, runnable in Chrome without install | Avoids app stores; one codebase serves all platforms |
| Backend | PHP + MySQL | User-specified; familiar stack |
| Roles | `admin`, `supervisor`, `maintenance`, `readonly` | User-specified |
| Auth | Login required for anything beyond read-only | User-specified |
| Audit | Every action attributed to a user; ticket events form a chronological log | Required for shift-handoff continuity ("started by one employee, continued by another") |
| Tickets | Support transferring between users / shifts without losing history | User-specified |
| First airframe | CRJ-900 (Bombardier CL-600-2D24); schema must allow expansion to Boeing / Airbus | User-specified |
| Branding | Delta Air Lines / Endeavor Air, dark theme, Delta red accent | From mockup |
| Testing | PHPUnit suite to be run on every major change | User-specified |
| Local dev | MAMP for as long as possible | User-specified |
| Future | Migration to a host; DB must have backup + restore scripts | User-specified |

### Clarifying questions asked

| Question | User's answer |
|---|---|
| Which AI backend should the diagnostic assistant use? | **Claude API (Anthropic)** |
| Where should the project live so MAMP can serve it? | **Symlink into MAMP htdocs** — code stays at `/Users/essendavis/Development/maintenanceIntelligenceSystem`, symlinked into `/Applications/MAMP/htdocs/mis` |
| How should reference documents (FAA / manufacturer PDFs) be stored? | **Filesystem + DB metadata** — PDFs live under `docs/library/`, MySQL stores metadata (title, ATA chapter, airframe, version) |
| How real does the data model need to be for v1? | **Full skeleton with seed data** — all tables, realistic CRJ-900 sample matching the mockup, working PWA, login, ticket flow, AI chat, unit tests, backup scripts |

### Prompt 2 — Essen Davis

> you can clear out anything in mamp right now from a prior project if it is
> in there

**Action taken:** prior MAMP content (`/Applications/MAMP/htdocs/index.php`,
`/Applications/MAMP/htdocs/pension-calc-saas/`) was *moved* (not deleted) to
`/Users/essendavis/Development/_mamp_backup_<date>/` so the cleanup is
reversible.

### Prompt 3 — Essen Davis

> save all my prompts to a prompts.md file so that a second teammember later
> can see the decsions made as the project develops.

**Action taken:** created this file. **Convention going forward:** every new
user prompt that changes scope, priorities, or architecture should be added
here verbatim, with the resulting decision recorded below it.

### Prompt 4 — Essen Davis

> look and feel shoud come from these colors. but i want the background to
> be this blue shade gradient with the white text and this sort of look and
> feel. a pdf should upen separately such that one could have a stack of
> them open and dragged to different screens or saved or printed seaprateely

### Prompt 4 (clarification) — Essen Davis

> look and feel shoud come from these colors. https://www.delta.com/ but i
> want the background to be this blue shade gradient with the white text
> and this sort of look and feel. a pdf should upen separately such that
> one could have a stack of them open and dragged to different screens or
> saved or printed seaprateely

(Note: WebFetch could not extract the live CSS from delta.com, so the
palette below was sourced from Delta's published brand identity.)

**Decisions captured:**

| Topic | Decision |
|---|---|
| Brand palette | Delta navy `#002F6C` (Pantone 2756) · Delta red `#C8102E` (Pantone 187) · Wave Blue `#0072CE` · White `#FFFFFF` |
| Background gradient | Vertical gradient `#001A40` (top) → `#002F6C` (mid) → `#003E89` (bottom). Subtle radial highlight near the brand mark to echo Delta's hero treatments. |
| Primary text | `#FFFFFF` |
| Secondary text | `#B8C5D9` (cool light-blue tint) |
| Severity colors | CRITICAL `#E51937` · HIGH `#FF8C00` · MEDIUM `#FFC72C` (Delta yellow) · LOW `#4FA3DC` |
| Cards / panels | Translucent navy (`rgba(0,30,72,0.55)`) with a 1px Wave-Blue border at low opacity |
| PDF viewer | PDFs open in **separate browser windows via `window.open(url,'_blank', 'popup,…')`**. Users can stack multiple, drag windows to other monitors, save/print each independently. Each open call uses a unique window name so each PDF gets its own window. |

### Prompt 5 — Essen Davis

> i envision most maintenance techs to interact at thier desk on laptop and
> at aircraft side with ipad. they should be able to be logged in to both
> at the same time and not have that cause any issues. and muliple techs
> may be on the same support ticket that a maintance or supervisor or admin
> has started.

**Decisions captured:**

| Topic | Decision |
|---|---|
| Multi-device sessions | A single user can hold **independent active sessions on laptop AND iPad simultaneously**. Each device gets its own session row in `user_sessions` with its own `user_agent` recorded; logging in on one does not invalidate the other. Confirmed already supported by the existing schema and `Auth::login()` flow (no session-uniqueness constraint, regenerate-id is per-browser). |
| Concurrency | Last-write-wins for ticket field changes (status, assignee). All comments and events are append-only, so two techs typing simultaneously won't lose work. The audit log preserves the full sequence. |
| Multi-tech collaboration | A ticket has *one* primary `assigned_to` (for sorting/notifications), but **any user with a write role** (admin / supervisor / maintenance) can add comments, change status, or consult the AI on *any* ticket — no assignee gate. The ticket event timeline shows who did what. Future work: add a `watchers` table once we wire notifications. |
| Audit attribution | `audit_log.user_agent` and `user_sessions.user_agent` make it possible to retroactively tell which device a tech used (laptop vs iPad) for any given action. |
| Future (v2) | Realtime "who's viewing this ticket right now" presence indicator and live-refresh on event-write. Not in v1. |

### Prompt 6 — Essen Davis

> a pilot or corprate suite person may be the readonly user. so that is
> not an afterthought. but some peopel need the data but arent the ones
> changing the data.

**Decisions captured:**

| Topic | Decision |
|---|---|
| Readonly is first-class | Pilots, dispatch, and corporate (Tech Ops leadership, quality, reliability) are legitimate readonly users. They need polished, complete read access — not a stripped-down view. |
| Read scope for readonly | Full access to: dashboards, KPIs, fault details, occurrence history, trend charts, reference documents (PDFs open in their own windows same as everyone), and the AI diagnostic assistant for *understanding* a fault. |
| Blocked for readonly | Create/edit/comment on tickets, change ticket status, reassign tickets, upload documents, modify users. Server-side gate is `Auth::canWrite()` which excludes readonly; the UI also hides these affordances. |
| AI for readonly | Allowed. A pilot reading about a recurring fault on the line can ask the assistant to explain it. Their queries are still attributed and audited. |
| UX cue | Topbar shows the user's role; readonly users see a "READ ONLY" pill so they understand why mutation buttons are absent. |
| Seed data | Two real readonly personas added to seed: a CRJ-900 line pilot and a Tech Ops corporate director, alongside the generic `viewer` placeholder. |

### Prompt 7 — Essen Davis

> when you click on an individual issue i want there to be a tasklist. this
> tasklist is created blank for each issue by default and both ai and the
> user with appropriate permissions can add to or check off completed
> tasks. it should be easy to have a way to complete some tasks and
> indicate who siged them off and also to add what is a holdup (awaiting a
> part, awaing inspection, etc) maybe have that tasklist entry point above
> the diagnostic questions and reference documents.

**Decisions captured:**

| Topic | Decision |
|---|---|
| Tasklist scope | Per-fault (per "issue"). Created implicitly — every fault has a tasklist that starts empty; rows are created on demand. Optional `ticket_id` linkage so tasks can be associated with a specific aircraft event when one exists. |
| Statuses | `pending` · `in_progress` · `blocked` · `complete`. A `blocked` task carries a free-text `holdup_reason` (e.g., "awaiting part", "awaiting inspection"). |
| Sign-off | Completing a task records `completed_by` + `completed_at` on the row. The signer is shown next to the task in the UI; the audit log keeps a separate immutable record. |
| Source | Each task is tagged `user` or `ai` so the team can tell at a glance which steps came from the diagnostic assistant. |
| AI integration | A "Suggest tasks" button on each fault asks Claude to produce 3–5 fault-specific troubleshooting steps and inserts them as `source='ai'`, `status='pending'` tasks ready for a tech to claim. |
| Permissions | View: all authenticated users (incl. readonly — pilots and corporate can see progress). Add / check-off / block / change: write roles only (admin, supervisor, maintenance). |
| Position in UI | Right column of the fault detail panel, **above** Diagnostic Questions and Reference Documents (per the prompt). |
| Audit & handoff | Every task mutation writes an `audit_log` row and, if the task is linked to a ticket, a `ticket_events` entry — so a supervisor reviewing the ticket on the next shift sees exactly what was done. |

### Prompt 8 — Essen Davis

> the open in maintenance portal button is a dummy for now. expected. but
> lets make that open a CMMS second page that is focused on just that
> issue. it should have full cmms level appearance though we are not yet
> ready to implement that. it is itslef just a skeleton placeholder. it
> should have features that you think would be helpful from gneeric cmms
> systems like https://upkeep.com/mobile-cmms-maintenance-app/ scrape that
> website to find capabilities

**Decisions captured:**

| Topic | Decision |
|---|---|
| Behavior | Button opens a **separate window** (`window.open` with a unique window name) scoped to the current fault. Like PDFs, multiple CMMS windows can be open and dragged across screens. |
| Page | New file `public/cmms.php?fault_id=…&ticket_id=…` — full-page Maintenance Portal layout, not a modal. |
| Posture | **Skeleton placeholder** with full CMMS appearance. Sections backed by real data where we already have it (work orders / tickets, aircraft tails, downtime from occurrences, documents, audit timeline). Sections without real backing are clearly marked `Skeleton — wiring in a later phase` so nothing reads as fake. |
| Capabilities scraped from UpKeep's mobile CMMS page | **Work orders** (open / in_progress / on_hold / closed), work requests, **asset & equipment management** (full maintenance history, asset utilization, downtime, depreciation, cost), **preventive maintenance** (recurring schedule, meter-based triggers, compliance), **parts & inventory** (parts tracking, consumption, check-in, multi-location stock, purchasing records), **time & cost tracking** (timer from WO open, labor cost, parts cost, cost-per-WO, budget alerts), **field service** (offline access, mobile-first), **comms** (in-app chat, technician-to-requester comments, push notifications), **analytics** (KPI dashboards, completion metrics, compliance reporting, reliability insights), **platform** (2FA, encryption, desktop-mobile sync, iOS/Android apps). |
| KPI strip on CMMS page | Open work orders · MTTR · Downtime hours (period) · Parts cost (period) · PM compliance · Last incident |
| Panels | Asset Snapshot (real: affected tails) · Work Orders (real: tickets linked to this fault) · Preventive Maintenance (skeleton) · Parts & Inventory (skeleton) · Time & Labor (skeleton) · Downtime / Reliability (real: from `fault_occurrences`) · Cost Summary (skeleton) · Documents (real) · Activity Timeline (real: from `audit_log` and `ticket_events`) |
| Authentication | Same session as the main app — opens because the user is already signed in. Pilots / corporate readonly users can view; mutation controls are hidden and the (placeholder) actions are no-ops anyway in v1. |

### Prompt 9 — Essen Davis

> that tasklist should essentially come from that maintenance portal. i
> just want it to be visible and expandable on that front page like you
> have it. to fully interact with it, you gotta go to the maintenance
> portal

**Decisions captured:**

| Topic | Decision |
|---|---|
| Tasks home | The tasklist now **lives in the Maintenance Portal**. The fault detail front page shows a read-only *summary* view that's expandable/collapsible. |
| Front-page tasks | Visible by default, collapse-toggle on the section header. Each row shows title, source (user/AI), status pill, sign-off attribution, and holdup reason — but no add field, no AI-suggest button, and no per-row action buttons. Counts are shown in the header (e.g., "3 in progress, 1 blocked"). |
| Front-page CTA | A "Manage in Maintenance Portal →" link sits at the bottom of the tasks block; clicking it (or the existing OPEN IN MAINTENANCE PORTAL button) opens the CMMS window. |
| Portal tasks | The Maintenance Portal carries the **full** task interaction: add, AI-suggest, complete (sign-off), block (with holdup reason), reopen, delete. Server-side mutations remain gated on write roles, so readonly users (pilots / corporate) see the portal but can't change anything. |

### Prompt 10 — Essen Davis (PM tracking + return-to-service workflow)

> there are alot of preventative maintenance tasks on airplanes that are
> both calandar time based and hourse in service time based. so those
> should be tracked. for example engines are swapped and have a time in
> service and a time since a task is performmed. airfrace might have a
> bunch of things that need to be inspected on a timeframe calendar or hour
> based. this should be integral to the system. it is for preventative
> maintenance tracking and inspection just as much as it is for fixing
> reported anomolies and fixing broken things to return to service. in
> some cases the maintencne worker themselves can approve a return to
> service, but in other cases, they need to say it is done and ready to
> be inspected. so each user needs to be able to flag something that
> comes up to thier supervisor such that they know it is complete or
> awaiting their inspection/signoff.

**Decisions captured:**

| Topic | Decision |
|---|---|
| PM is integral, not optional | PM tracking is a **co-equal pillar** with fault triage. The system serves both: returning broken aircraft to service AND keeping airworthy aircraft compliant with scheduled maintenance. |
| Tracked components | Aircraft tails *and* serial-numbered components (engines, APUs, landing gear, etc.). Engines especially: when an engine is swapped, its time-in-service follows the engine, not the airframe. PM items on the engine reset only on engine swap. New table `components` carries `installed_at`, `hours_at_install`, `total_hours`, `total_cycles`, `in_service`. |
| PM plan model | `pm_plans` define the *what*: title, applicable scope (airframe / engine / apu / landing_gear), trigger type (`calendar_days` / `flight_hours` / `flight_cycles` / `component_hours`), interval value, tolerance, and `requires_inspection` flag (whether a supervisor sign-off is mandatory regardless of who completes the work). |
| PM compliance model | `pm_items` are the *instances*: one per (plan, target). Carry `last_done_at`, `last_done_hours`, `last_done_cycles`, `next_due_at`, `next_due_hours`, `next_due_cycles`, current status (`current` / `due_soon` / `overdue` / `in_progress` / `awaiting_inspection` / `complete`), assignee, and full sign-off attribution (who, when). |
| RTS authority | Two new boolean attributes on users: `rts_authority` (can self-approve return to service) and `inspection_authority` (can sign off another tech's work). Admins and supervisors get both by default; maintenance techs can be granted `rts_authority` per individual; readonly users get neither. |
| Status flow | Tasks gain an `awaiting_inspection` state. Completing a task: if the task `requires_inspection` OR the acting user lacks `rts_authority`, the task transitions to `awaiting_inspection` (NOT `complete`) and the supervisor's sign-off queue lights up. From `awaiting_inspection`, a user with `inspection_authority` signs off → `complete`. The audit log records both events with both users named. |
| Flag-to-supervisor | The "complete" button shows different copy based on the user's authority: techs without RTS see "Mark complete · awaiting inspection"; techs with RTS see "Sign off & close". A supervisor's CMMS portal shows a "SIGN-OFF QUEUE" panel listing every item awaiting them across all tails and faults. |
| Seed data | All 5 CRJ-900 tails get 2 engines + 1 APU. ~7 representative PM plans (engine borescope @ FH, FCC software audit @ calendar, AFCS servo inspection @ FH, MEL currency @ calendar, etc.). PM items spread across compliance states (overdue, due-soon, current, in-progress, awaiting-inspection) so the dashboard demos meaningfully on first open. |
| CMMS portal | The PM and Time/Labor cards on the Maintenance Portal stop being skeletons and become real, scoped to the issue's affected tails and their engines/APUs. A supervisor-only "SIGN-OFF QUEUE" card replaces a skeleton card when the logged-in user has `inspection_authority`. |

### Prompt 11 — Essen Davis (admin user management)

> lets make an admin only ability to manage users and add or remove users
> and grant access levels and RTS ability

**Decisions captured:**

| Topic | Decision |
|---|---|
| Surface | Dedicated admin page `public/admin.php` linked from a gear icon in the topbar that is **visible only to users with role=admin**. Accessible only over the admin's authenticated session. |
| Capabilities | Add user · update user fields (role, station, shift, rts_authority, inspection_authority, email, full_name) · reset password · deactivate / reactivate (soft delete via `is_active=0`) · list all users with current authority flags · audit history of every admin action |
| Hard delete | NOT supported. Users with audit history must remain in the table to preserve attribution. Deactivation hides them from picklists and prevents login but keeps their past actions intact. |
| Endpoint | New API `public/api/users.php` with `list / create / update / reset_password / deactivate / reactivate` actions. Every endpoint enforces `Auth::require(['admin'])`; non-admins (including supervisors) get 403. |
| Self-protection | Admins cannot deactivate their own account or strip their own admin role through the API (prevents lockout). |
| Audit | Every admin mutation writes to `audit_log` with action like `user.create`, `user.update`, `user.password_reset`, `user.deactivate`, `user.reactivate` and the target user_id, plus a JSON diff of the changed fields. |

### Prompt 12 — Essen Davis (end-user docs)

> make a subfolder in docs which houses a how to use this website
> document. how to install, how to migrate and how to use for now as
> things to cover

**Decisions captured:**

| Topic | Decision |
|---|---|
| Location | `docs/guide/` (peer of `docs/library/`). The `library/` subfolder remains for FAA / manufacturer PDFs; the `guide/` subfolder is for human-readable end-user docs. |
| Files | `docs/guide/how-to-install.md` · `docs/guide/how-to-migrate.md` · `docs/guide/how-to-use.md` (plus an index `docs/guide/README.md`). |
| Audience | `how-to-install` and `how-to-migrate` target the IT person setting up the system; `how-to-use` targets line maintenance, supervisors, and pilots/corporate readers. |
| Versioning | Living docs — committed to git so changes track with the code. |

### Prompt 13 — Essen Davis (in-app help)

> have that how to use document be accessible from a help icon on the
> website

**Decisions captured:**

| Topic | Decision |
|---|---|
| Surface | A `?` (help) icon in the topbar, visible to **every** authenticated user. Opens `public/help.php` in its own window (same `window.open` pattern as PDFs and the CMMS portal — drag to a second screen, keep open while you work). |
| Content | Renders the existing `docs/guide/*.md` files. The page has a small tab strip — **Use** (default, what the prompt asked for), **Install**, **Migrate** — so the IT-leaning docs are discoverable too without crowding the user-facing page. |
| Rendering | A tiny custom Markdown→HTML converter bundled in `src/Markdown.php` (no composer dependency). Handles ATX headings, paragraphs, fenced code, inline code, **bold** / *italic*, links, ordered/unordered lists, GFM tables, blockquotes, and `---` rules — which is what the existing docs use. |
| Authentication | Help is gated on login. There's no reason to expose it anonymously, and gating keeps the help page consistent with the rest of the app. |

### Prompt 14 — Essen Davis (UpKeep integration map)

> what decisions were made to skip or integrate where used when scraping
> the cmms website from another implementation for a nother industry?

UpKeep is a general-facilities CMMS (manufacturing, property, hospitality);
aviation maintenance has stricter regulation, different asset types, and
component-aware time tracking. Here is the explicit map of which UpKeep
capabilities were integrated, integrated-with-an-aviation-twist, left as
visible skeleton, or deliberately skipped.

#### Integrated as-is

| UpKeep capability | MIS implementation |
|---|---|
| Work orders (create / manage / complete) | `tickets` table + `ticket_events` timeline |
| Status updates and comments | `ticket_events` (comment / status_changed / reassigned / handoff) |
| Asset tracking | `aircraft_tails` + `components` |
| Maintenance history accessible on mobile | Audit log + ticket events; full read-only access on iPad / Chrome PWA |
| Asset downtime monitoring | Computed from `fault_occurrences` and surfaced on the CMMS portal |
| Asset reliability insights | 14-day occurrence trend chart |
| Preventive maintenance scheduling | `pm_plans` + `pm_items` (first-class pillar, not a side feature) |
| Recurring work order setup | Each PM plan generates an item per target with `next_due_at / next_due_hours / next_due_cycles` |
| PM compliance tracking | `pm_items.status` (current / due_soon / overdue / in_progress / awaiting_inspection / complete) + KPI counts |
| KPI dashboards | KPI strips on both the main app and the CMMS portal |
| Completion metrics | MTTR (mean time to closed), occurrence counts |
| Compliance reporting | PM status counts on the portal; per-tail snapshot |
| Mobile-first / iOS / Android | PWA — installable from Chrome on Android and Safari "Add to Home Screen" on iOS; same install button on desktop Chrome |
| Desktop-mobile sync | Single PHP+MySQL backend; multi-device sessions on the same user (laptop + iPad simultaneously) |
| Encryption / data security | HTTPS-ready, `cookie_secure`, hash-based passwords (bcrypt cost 12) |
| Unlimited "requesters" (read-only users) | Readonly role is first-class; pilots and corporate users covered |
| Technician-to-requester comments | Ticket comments visible to all roles (readonly users see them) |

#### Integrated with an aviation-specific twist

| UpKeep capability | MIS twist |
|---|---|
| Meter-reading-based triggers | Split into four aviation-specific trigger types: `calendar_days`, `flight_hours`, `flight_cycles`, `component_hours`. Component-hours are critical because engines/APUs follow the part, not the airframe, on swap. |
| Asset utilization tracking | Component records carry their own `total_hours` / `total_cycles` separate from the tail's, so a swapped engine's PM history doesn't reset. |
| Status-update notifications | Replaced with **RTS authority + sign-off queue** — a regulatory necessity, not just a comms feature. Tasks/PM items completed by techs without `rts_authority` route to `awaiting_inspection`; supervisors with `inspection_authority` sign off or reject. UpKeep has nothing equivalent. |
| Team alignment | Shift handoff is structured: ticket events tag `shift_handoff: true` with from/to user IDs so the next shift sees the full chronology when they open the ticket. |
| Asset categorization | ATA chapters carried on faults, plans, and documents — universal aviation taxonomy (UpKeep doesn't ship with aviation taxonomies). |

#### Visible skeleton — wiring planned but not v1

These are present in the CMMS portal so the layout matches a real CMMS,
but clearly tagged `SKELETON · wiring in a later phase`:

| Capability | Why deferred |
|---|---|
| Parts & Inventory | Aviation parts are lifed and serialized; the schema can't be a copy of UpKeep's. Needs its own design (life limits, traceability tags, certs) before implementation. |
| Time & Labor (timer, hours logged) | Real labor recording requires payroll/dispatch integration; placeholder is fine for v1. |
| Cost summary | Depends on parts + labor being real. |
| Photos & signatures | Mobile capture is straightforward but needs storage policy and signature canvas — meaningful chunk of work, deferred. |

#### Deliberately skipped (not the right fit)

| UpKeep capability | Why skipped |
|---|---|
| Asset depreciation / financial tracking | Airline finance lives in a separate system (M&E or ERP). Belongs there, not in a maintenance-tech app. |
| Budget monitoring and alerts | Same reason — financial scope, not a tech's flow. |
| Real-time IoT data integration | The aviation analogue (ACARS, FOQA, CMS streams) is a major integration effort. v1 leaves placeholder columns and ingestion is a separate project. |
| In-app team chat | Slack / Teams already serve this need at most airlines; a new chat surface adds friction. Ticket comments cover async coordination. |
| Push notifications | Requires service-worker push + per-user subscription management + a delivery service. Deferred until users ask for it. |
| Location-based field service (GPS routing) | A tail number plus station is the operational coordinate; lat/long routing is overkill. |
| Hard delete of assets / users | Audit-log integrity matters. Soft-delete (`is_active=0`) only. |
| Two-factor auth | Real production deploy will sit behind the airline's SSO / MFA; baking in our own 2FA before then is wasted work. |

#### Added beyond UpKeep (aviation-specific, not in their scrape)

- AI diagnostic assistant tied to fault context (Anthropic Messages API, prompt-cached system + airframe context).
- Multi-airframe schema (CRJ-900 first, designed for Boeing/Airbus expansion without a rewrite).
- Component-swap-aware time-in-service.
- ATA chapter taxonomy on every fault, document, and plan.
- RTS workflow with `rts_authority` and `inspection_authority` user attributes.
- Reference-document opening in independent windows so techs can stack PDFs across screens.

### Prompt 15 — Essen Davis (closing-day constraints, 2026-05-10)

> ai should never be able to complate tasks by itself. you can't hold ai
> accountable for errors. this is a foundational constraint. and yes, we
> work in delta mainenance and this is our work. it is to be shown to
> superiors and nowehere else if we go ahead. and a ticket that is per
> model should exend out and be completed per airframe.

Three closing decisions captured for tomorrow:

#### 1. AI accountability — foundational constraint

**Rule:** the AI assistant **never** completes a task, signs off a PM
item, returns an aircraft to service, or closes a ticket. It can only:

- *suggest* tasks (currently tagged `source='ai'`, always created in
  `status='pending'`)
- *answer* diagnostic questions
- *propose* documentation language for a tech to review

A human is always the actor of record on any state change that affects
airworthiness. **Why:** the AI cannot be held accountable for an error;
a certified tech / supervisor can. This is not a UX preference — it is
a regulatory and ethical floor.

**Current code already complies** (AI suggestions land as pending, no
endpoint lets the AI call mutating actions), but this constraint
should be re-checked on every future feature that involves the AI.
Adding it here so it's load-bearing in the prompts log.

#### 2. Project context — authorized Delta work

This is real Delta Air Lines maintenance work being built by Delta
maintenance personnel. Distribution is **internal to leadership only**
until a go/no-go decision. Branding (Delta Air Lines, Endeavor Air,
Tech Ops marks) is therefore appropriate, not a placeholder. Removes
the earlier "swap the marks before showing it around" caveat.

#### 3. Ticket model — per-model with per-airframe execution

**Rethink for next session.** Today's schema has `tickets.tail_id`
pointing at a single aircraft. The user wants a ticket to live at the
**fault-model level** and *extend out* to be completed per affected
airframe.

Two viable shapes to discuss tomorrow before coding:

| Option | Shape | Pros | Cons |
|---|---|---|---|
| A — parent ticket + per-tail children | `tickets.parent_id`; one parent per fault model, child rows per affected tail | Each tail can have its own assignee, status, signoff; clean reporting | Doubles the row count; UI has to show parent vs child |
| B — one ticket + `ticket_tails` join | Single ticket with a join table `(ticket_id, tail_id, status, assigned_to, signed_off_by, signed_off_at)` | Smaller schema delta; one parent UI | Harder to assign per-tail and harder to express per-tail history |

Lean toward A — child tickets reuse all existing per-ticket behavior
(events, AI chat, timeline) without special-casing. The parent
becomes a roll-up view with a per-tail status grid.

**Implications to chew on:**
- Tasks today are per-fault. Under the new model, do tasks belong to
  the parent (shared across all tails) or to each child (per-tail
  execution checklist)? Mixed (parent = strategy, child = execution
  checklist) is probably the truth.
- "Ticket closed" only when **every** child is signed off.
- Parent ticket auto-creates a child the first time a tail is added
  to the affected list (from a new occurrence) — saves manual work.
- The dashboard's "ACTIVE ISSUES" list keeps showing the parent;
  expanding it shows per-tail rollup.

Not implementing tonight — this is a real schema change and deserves
a sit-down decision. Captured here so we open with it in the morning.

---

## How to use this file

- **Append-only.** Don't rewrite past entries — they are history.
- **Quote prompts verbatim** (block-quoted). Don't paraphrase.
- **Record the decision** as a short table or bullet list under each prompt.
- **Note any reversible/irreversible actions** taken in response (e.g., file
  deletions, schema migrations).
- For purely conversational prompts ("how does this work?", "explain X"), no
  entry is needed — only record prompts that change *what gets built* or
  *how*.
