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
