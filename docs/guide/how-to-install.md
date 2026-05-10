# How to install (MAMP, local)

These steps get a fresh MIS install up and running on a Mac with MAMP.

## 1. Prerequisites

- macOS with **MAMP** installed (the free version is fine).
  Download: https://www.mamp.info/
- A clone of the MIS project on your Mac.
- Git (if you want to keep the install up to date with `git pull`).

## 2. Start MAMP

1. Open **MAMP**.
2. Click **Start**. Apache should bind to port **8888** and MySQL to port **8889** by default. (Don't change to ports 80/3306 unless you really need to — the rest of this guide assumes the defaults.)
3. Confirm the MySQL socket exists: `/Applications/MAMP/tmp/mysql/mysql.sock`.

## 3. Place the project

Pick **either** option:

- **Option A — symlink (recommended).** Keep the project anywhere you like and symlink the public folder into MAMP's web root:
  ```bash
  cd /path/to/maintenanceIntelligenceSystem
  ln -sfn $(pwd)/public /Applications/MAMP/htdocs/mis
  ```
  Now visiting `http://localhost:8888/mis/` serves `public/index.php`.
- **Option B — move.** Move the project so it lives directly under `/Applications/MAMP/htdocs/`. Less flexible.

## 4. Create the databases

Open Terminal and run:

```bash
MYSQL=/Applications/MAMP/Library/bin/mysql80/bin/mysql
SOCK=/Applications/MAMP/tmp/mysql/mysql.sock

$MYSQL -uroot -proot --socket=$SOCK -e "
  CREATE DATABASE IF NOT EXISTS mis      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE DATABASE IF NOT EXISTS mis_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"
$MYSQL -uroot -proot --socket=$SOCK mis < db/schema.sql
$MYSQL -uroot -proot --socket=$SOCK mis < db/seed.sql
```

The `mis_test` database is used by the test suite. It's recreated automatically every time tests run, so you don't need to load anything into it.

## 5. Configure

```bash
cp config/config.example.php config/config.php
```

Then open `config/config.php` and set:

- `db.password` — only if you changed MAMP's default `root` password.
- `anthropic.api_key` — paste your key here to switch the AI assistant from stub mode to live answers. **Leave it empty if you don't have a key yet** — the UI still works.

## 6. First login

Open **http://localhost:8888/mis/** in Chrome.

The seed users (all password `ChangeMe!123`) are:

| Username | Role | Notes |
|---|---|---|
| `admin` | admin | Full access incl. user management |
| `jsupervisor` | supervisor | Has RTS + inspection authority |
| `mtech1` | maintenance | Has RTS authority (senior tech) |
| `mtech2` | maintenance | Tech without RTS — sign-offs route to a supervisor |
| `mtech3` | maintenance | Tech without RTS — night shift |
| `viewer` | readonly | Generic readonly |
| `rsmith` | readonly | Pilot |
| `qchen` | readonly | Corporate Tech Ops |

**Change the seed passwords as soon as you go beyond local testing.** From the admin gear icon (visible only to admins), open **Manage Users** → **Reset Password**.

## 7. Run the test suite

After every major change, run:

```bash
/Applications/MAMP/bin/php/php8.3.28/bin/php tests/run.php
```

Tests reset `mis_test` from `db/schema.sql` + `db/seed.sql`, so the suite always starts from a known state.

## 8. Install MIS as an app

MIS is a **Progressive Web App** — installable on iOS, Android, and Chrome on a desktop, no app store needed.

- **Chrome (desktop or Android):** click the install icon in the address bar, or click the **Install app** button in the topbar when it appears.
- **iOS Safari:** Share → Add to Home Screen.

Once installed, MIS runs in its own window with the Delta-themed icon. The same login works.

## 9. Backups

```bash
./db/backup.sh          # → db/backups/mis_<UTC>.sql.gz
./db/restore.sh dump.sql.gz
```

For long-term local use, schedule the backup nightly:

```cron
0 3 * * *  /path/to/maintenanceIntelligenceSystem/db/backup.sh --keep 30
```
