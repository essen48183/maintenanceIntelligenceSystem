# How to use MIS

A guide for the four user types: maintenance technicians, supervisors,
admins, and pilots / corporate readers.

## Signing in

Open the app on your laptop or iPad. You can be signed in on **both
devices at the same time** — laptop at the desk, iPad at aircraft side.
Each device has its own session; nothing is invalidated when you log in
on the second device.

If a teammate creates a ticket on their device and hands it off to you,
you'll see the same ticket and the full event timeline on yours.

If the **Install app** button shows up in the topbar, click it — MIS
becomes a real app on your device with its own icon and window.

## The fault tracker (front page)

After login, you land on the **CRJ Fault Tracker**. Three columns:

1. **Left rail** — fleet, severity filter, system filter (Avionics,
   Engines, Hydraulics, etc.).
2. **Center** — KPI strip and the active issues for the selected
   airframe. Click an issue to see its detail panel.
3. **Right rail** — the **AI Diagnostic Assistant**. Tap a suggested
   query, ask your own question, or click any *Diagnostic Question* on
   the issue detail to populate the input.

The fault detail shows the description, affected sub-systems, a 14-day
trend, recent occurrences across the fleet, the diagnostic questions,
and the linked reference documents.

### Tasks (read-only here)

Each issue has its own **TASKLIST**. On the fault tracker, the tasklist
is **read-only** — you see the count, status pills, who signed off
each completed task, and the holdup reason for any blocked task.

To add tasks, mark progress, request inspection, or sign off — open the
**Maintenance Portal** (the red button at the bottom of the issue, or
the "Manage in portal" link at the top of the tasklist).

> **AI is advisory only.** The AI assistant can *propose* a tasklist
> but **never** creates live tasks on its own. When you click ✦ AI
> suggest, you'll see proposals in a review panel — each one needs your
> explicit **＋ Add** before it becomes a real task. The AI also never
> marks a task complete, signs off PM, or closes a ticket. Only a
> certified human is the actor of record on anything that affects
> airworthiness.

### Reference documents (PDFs)

Click any reference document. It opens in **its own browser window** so
you can:

- Stack multiple documents.
- Drag them to a second monitor.
- Save or print each independently.

## The Maintenance Portal (CMMS)

The portal opens in a separate window and is scoped to a single issue.
This is where you do real work.

### KPI strip

- **Open Work Orders** for this issue.
- **MTTR** — average time to closed across past work orders.
- **Downtime** — estimated hours.
- **Last Incident** — most recent occurrence and tail.

(**Parts Cost** and **PM Compliance** are skeleton placeholders for now.)

### Tasklist (full interaction)

This is where you manage tasks:

- **+ Add task** at the bottom.
- **✦ AI suggest** at the top right — asks the AI to *propose* 4–6
  diagnostic / corrective tasks specific to this fault. **Proposals
  appear in a review panel above the tasklist** with **＋ Add** and
  **✕** buttons per item, plus **Approve all** / **Dismiss all**.
  **Nothing becomes a real task until you click Add.** Each accepted
  task is tagged `✦ AI` so you know where it came from, but you (the
  human) are the `created_by` of record.
- **Checkbox** to complete a task (see *Sign-off rules* below).
- **▶** to mark in-progress.
- **⚠** to mark blocked. You'll be prompted for the holdup reason
  (e.g., "awaiting part — backorder ETA 48h"). Anyone watching the
  ticket sees that reason on the next refresh.
- **↺** clears a holdup.
- **✕** removes a task.

> The AI never moves a task on its own. Every status change requires
> a human click. The system enforces this server-side — even a bug or
> a future AI tool call cannot bypass it.

### Sign-off rules (RTS workflow)

Whether your "complete" actually closes a task depends on:

| Task / Plan property | Your authority | Result |
|---|---|---|
| `requires_inspection = false` | RTS authority **YES** | → **complete** (you self-sign-off) |
| `requires_inspection = false` | RTS authority **NO** | → **awaiting inspection** (a supervisor signs off) |
| `requires_inspection = true` | any | → **awaiting inspection** (always) |

The button label adapts: techs without RTS see "Mark complete · awaiting inspection"; techs with RTS see "Sign off & close".

### Sign-off queue (supervisors and admins)

If you have **inspection authority**, the portal shows a **SIGN-OFF
QUEUE** card listing every PM item and task across the system that's
waiting on you. From there you can:

- **Sign off** — closes the item, your name on the record.
- **Reject** — bumps it back to *in progress* with the rework reason.
  The original tech sees the reason next time they refresh.

### Tickets — parent (model) and children (per tail)

A ticket is **per fault model** (e.g., "AFCS Autopilot Disconnect on
CRJ-900") and **extends out per affected airframe** (e.g., one child
for N901XX, another for N902XX). The structure:

- **Parent ticket** — model-level coordination. No specific tail.
  Shows the full child rollup at a glance (`✓ N901XX  ⏳ N902XX  ○ N903XX`).
- **Child ticket** — one per affected tail. Has its own assignee, its
  own task progress, its own sign-off. Different shifts and techs can
  be on different children of the same parent simultaneously.

**Closing rule:** a parent ticket **cannot** be closed until **every**
child is closed (or cancelled). The system blocks early closure and
returns a `parent_has_open_children` error. Each child must be signed
off by a tech with RTS authority (or moved through awaiting-inspection
to a supervisor) before the parent can be retired.

### Other portal panels

- **Work Orders** — every ticket linked to this issue. Parents are
  shown with their child rollup; children show under their parent.
- **Asset Snapshot** — affected tails with flight hours and cycles.
- **Preventive Maintenance** — every PM item touching the affected
  tails or their components (engines, APUs). Shows trigger type
  (calendar / FH / FC / component-hours), last performed, next due,
  current status (current / due-soon / overdue / in-progress / awaiting
  inspection / complete).
- **Downtime / Reliability** — the fault's 14-day occurrence trend.
- **Documents** — same library as the front page; opens in own window.
- **Activity** — combined audit log + ticket events for full attribution.

## Preventive maintenance is integral

MIS treats PM as **co-equal with anomaly fixing**:

- Scheduled inspections, calendar-based and hours-based, live in the
  same system as fault triage.
- Engines, APUs, and other tracked components have **their own
  time-in-service** that follows the component when it's swapped — so
  a 1,000-hour borescope on engine `CF34-AAB-N901XX-R` doesn't reset
  when the engine is moved between airframes.
- The **PM panel** in the portal shows due-soon (amber) and overdue
  (red) items first, so the "what should I touch next" view is in
  front of you when you open the portal.

## Pilots and corporate readers (readonly)

If you're a pilot pulling up a recurring fault on the line, or a Tech
Ops director reviewing reliability, you have **full read access**:

- All dashboards, fault detail, KPIs, trends.
- The complete document library (PDFs open in their own windows for
  printing).
- The **AI assistant** — useful for understanding a fault you didn't
  work on.
- Tasklists and PM compliance for situational awareness.

What you can't do: create, comment on, or close a ticket; add or
complete tasks; modify any data. Buttons are simply hidden in the UI;
the server also enforces this server-side, so nothing leaks through.

## Admin: managing users

Admins see a **gear icon** in the topbar. Click it to open the
**Manage Users** screen. From there:

- **Add user** — assign role, station, shift, and authorities.
- **Edit user** — change role, grant or revoke `rts_authority` or
  `inspection_authority`, fix typos.
- **Reset password** — sets a new password and invalidates that user's
  active sessions on every device.
- **Deactivate / Reactivate** — soft-disables a user. Their past audit
  history stays intact (you can never hard-delete a user).

Admins **cannot** demote or deactivate themselves through this UI — a
guardrail to prevent locking yourself out.

## Tips

- Use **filters** in the left rail to narrow the issue list (severity
  + system).
- The **clock** in the topbar is UTC — operationally aligned with
  ACARS / dispatch.
- The "**LIVE**" pill is green when the system is reachable; if the
  service worker is doing offline fallback, the data the page shows is
  the last cached copy. (API calls themselves never serve stale data.)
- All **sign-off buttons keep your name on the record**. The audit log
  is immutable — you can always see who did what when.
