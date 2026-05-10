# Maintenance Intelligence System (MIS)

Endeavor Air maintenance app — a Progressive Web App (PWA) for tracking
faults, browsing FAA / manufacturer reference documents, and consulting an
AI diagnostic assistant. Backend is PHP + MySQL.

> **First airframe:** CRJ-900 (Bombardier CL-600-2D24).
> Schema is multi-airframe; CRJ-700, Boeing, and Airbus types fold in by
> adding rows to `airframes`, `aircraft_tails`, `systems`, `fault_catalog`,
> and `documents`.

---

## Quick start (MAMP, local)

```bash
# 1. MAMP must be running with MySQL (port 8889) and Apache (port 8888).

# 2. Symlink the public/ tree into MAMP htdocs (already done by the setup):
ln -sfn $(pwd)/public /Applications/MAMP/htdocs/mis

# 3. Create the databases and load schema + seed:
MYSQL=/Applications/MAMP/Library/bin/mysql80/bin/mysql
SOCK=/Applications/MAMP/tmp/mysql/mysql.sock
$MYSQL -uroot -proot --socket=$SOCK -e \
  "CREATE DATABASE IF NOT EXISTS mis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE IF NOT EXISTS mis_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$MYSQL -uroot -proot --socket=$SOCK mis < db/schema.sql
$MYSQL -uroot -proot --socket=$SOCK mis < db/seed.sql

# 4. Configure (defaults already work for MAMP):
cp config/config.example.php config/config.php
# (Edit config/config.php to add anthropic.api_key for live AI answers.)

# 5. Open the app:
open http://localhost:8888/mis/
```

## Default seed accounts

All passwords: **`ChangeMe!123`**

| Username      | Role         | Notes                          |
|---------------|--------------|--------------------------------|
| `admin`       | admin        | Full access                    |
| `jsupervisor` | supervisor   | Jamie Reyes — day shift        |
| `mtech1`      | maintenance  | Devon Park — day               |
| `mtech2`      | maintenance  | Kira Holden — swing            |
| `mtech3`      | maintenance  | Marcus Webb — night            |
| `viewer`      | readonly     | Generic readonly               |
| `rsmith`      | readonly     | Capt. R. Smith — line pilot    |
| `qchen`       | readonly     | Quincy Chen — Tech Ops corp.   |

Readonly is **first-class** — pilots and corporate users get full read access
to dashboards, fault detail, the document library, and the AI assistant.
Mutation actions (create / comment / reassign / status / upload) are blocked
both server-side (by `Auth::canWrite()`) and in the UI.

---

## Project layout

```
.
├── config/
│   ├── config.example.php   # template
│   └── config.php           # local config (gitignored)
├── db/
│   ├── schema.sql           # full schema (idempotent — drops + recreates)
│   ├── seed.sql             # users, fleet, faults, occurrences, sample tickets
│   ├── backup.sh            # mysqldump → gzip with retention
│   ├── restore.sh           # restore from .sql or .sql.gz
│   └── backups/             # output directory (gitignored)
├── docs/
│   └── library/             # PDFs (one per row in `documents`)
├── public/                  # web root (symlinked into MAMP htdocs)
│   ├── index.php            # SPA shell
│   ├── manifest.webmanifest # PWA manifest
│   ├── service-worker.js    # offline shell + API pass-through
│   ├── icons/               # PWA icons (regen via _generate.php)
│   ├── assets/              # css + js
│   └── api/                 # PHP endpoints
├── src/                     # MIS\* PHP classes (PSR-4-ish)
├── tests/                   # custom PHPUnit-style harness, no composer needed
├── prompts.md               # decision log — every requirement-shaping prompt
└── README.md
```

---

## Running tests

```bash
/Applications/MAMP/bin/php/php8.3.28/bin/php tests/run.php
```

Tests reset `mis_test` from `db/schema.sql` + `db/seed.sql` before every run,
so the suite always starts from a known state. Run after every major change.

15 tests / 52 assertions covering:

- Auth: seed users, role membership, password hashing
- Faults: severity counts, active list, detail aggregation, ordering
- Tickets: create, comments, **shift handoff** (multi-user, multi-shift),
  status transitions, ticket-number uniqueness
- Audit: every mutation writes an audit entry

---

## Backup & restore

```bash
./db/backup.sh                 # dumps `mis` to db/backups/mis_<UTC>.sql.gz
./db/backup.sh --db mis_test   # dump the test DB
./db/backup.sh --keep 14       # keep latest 14 dumps; older ones are pruned

./db/restore.sh db/backups/mis_20260510T160122Z.sql.gz
./db/restore.sh dump.sql.gz --db mis_test --no-drop
```

Both scripts honor environment overrides (`MIS_DB_HOST`, `MIS_DB_PORT`,
`MIS_DB_USER`, `MIS_DB_PASS`, `MIS_DB_SOCKET`, `MYSQL`, `MYSQLDUMP`) so the
same scripts work when the app moves to a managed host.

A nightly cron job is recommended once the app is on a host:
```
0 3 * * *  /path/to/mis/db/backup.sh --keep 30 >> /var/log/mis-backup.log 2>&1
```

---

## Architecture notes

### PWA / installable
- `manifest.webmanifest` declares the app as standalone with Delta-themed
  icons; the topbar shows an **Install app** button when the browser fires
  `beforeinstallprompt` (Chrome on Android, desktop Chrome).
- iOS users install via Safari → Share → *Add to Home Screen*; the
  `apple-touch-icon` and `apple-mobile-web-app-*` meta tags handle styling.
- A service worker caches the app shell for offline-first navigation; `/api/*`
  is always network-first so data is never stale.

### Multi-device sessions
- The same user can be logged in on a laptop AND iPad simultaneously without
  conflict. Each device has its own `user_sessions` row (with its own
  `user_agent`), so logging in on one does not invalidate the other.
- Concurrency: ticket comments and events are append-only. Ticket field
  changes (status, assignee) are last-write-wins; the audit log preserves
  every step so nothing is lost.

### Multi-tech collaboration
- A ticket has a single primary `assigned_to` for sorting, but **any** user
  with a write role (admin, supervisor, maintenance) can comment on, change
  status of, or consult the AI on **any** ticket.
- Shift handoff is just a reassignment with `metadata.shift_handoff = true`;
  the next shift sees the full chronological event log on opening the
  ticket.

### AI assistant
- Backed by the Anthropic Messages API (`claude-opus-4-7`), with prompt
  caching on the system prompt + airframe context block (5-minute cache TTL)
  so repeated questions on the same fault don't re-bill those tokens.
- Falls back to a deterministic stub response when no API key is set, so the
  UI works out of the box.
- Conversations are persisted to `ai_messages` (scoped to a fault and / or
  ticket), so when a tech hands off, the next tech sees the prior chat.

### PDF documents in their own windows
- Reference documents open via `window.open(url, '<unique-name>', 'popup,…')`
  so each PDF gets its own browser window. Users can stack multiple, drag
  windows to a different monitor, and save / print each independently.
- The PHP endpoint (`api/documents.php?action=download&id=…`) authenticates,
  audits the open, and streams the file with `Content-Disposition: inline`.
  Until a real PDF has been uploaded for a row, a small placeholder PDF is
  served so the UI flow remains testable.
- Files live under `docs/library/` (outside the web root); the storage path
  in the DB is relative. The endpoint validates the path stays inside the
  library dir to prevent traversal.

### Audit & shift handoff
- `audit_log` records every significant action: login (success and failure),
  fault and ticket views, ticket mutations, AI queries, document opens.
- `ticket_events` is the user-visible timeline shown on each ticket — built
  from comments, status changes, reassignments, AI consults, and document
  references. New shifts pick up exactly where the previous shift left off.

---

## Migrating off MAMP later

1. Provision MySQL 8 on the host, create the `mis` database.
2. Copy the project to the host (or deploy via your preferred method).
3. Set the host's web root to `<project>/public/`, OR symlink it into the
   server's existing webroot.
4. Update `config/config.php`:
   - `db.host` → host name
   - `db.port` → 3306
   - `db.socket` → `null`
   - `app.base_url` → the production URL
   - `auth.cookie_secure` → `true` (assuming HTTPS)
   - `anthropic.api_key` → real production key
5. Restore the latest backup with `./db/restore.sh`.
6. Set up the cron job above for nightly backups.

The schema is portable; nothing assumes MAMP at runtime.

---

## License & data sensitivity

- Reference documents (FAA / manufacturer) live under `docs/library/` and are
  gitignored. Do not commit copyrighted material.
- `config/config.php` is gitignored — never commit the Anthropic API key.
- The `audit_log` is sensitive; back it up and protect it.

---

## See also

- `prompts.md` — running log of user prompts and the resulting design
  decisions. Read it for the *why* behind the architecture.
- `docs/guide/` — end-user documentation:
  - [`how-to-install.md`](docs/guide/how-to-install.md) — first-time MAMP setup
  - [`how-to-migrate.md`](docs/guide/how-to-migrate.md) — moving off MAMP onto a host
  - [`how-to-use.md`](docs/guide/how-to-use.md) — for techs, supervisors, admins, pilots, corporate
