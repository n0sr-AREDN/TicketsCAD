# Changelog

All notable changes to TicketsCAD (NewUI v4) are documented here.
The format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## [4.1.2] - 2026-07-26

Fixes three things a **brand-new install** was missing. Found by doing something
that should have been routine and was not: cloning the public tag onto an empty
database and running the documented install steps. On its first run, the
self-check added in 4.1.1 reported that a fresh install did not satisfy its own
schema.

**Upgrading:** `git pull` then `php sql/run_migrations.php` (Docker:
`git pull && docker compose up -d --build`).

### Fixed
- **`responder_notes` was created by no migration.** Two endpoints created it
  on the fly just before writing, so *saving* a unit note worked — but three
  others read it, so on an install where no note had been written yet the Notes
  Log report queried a table that did not exist. It is now created at install
  time like any other table.
- **`permission_review_dismissals` had the same problem**, created on demand by
  the RBAC code. It is also one of the tables a user lost to crash recovery —
  and because nothing ever created it, no repair could put it back.
- **`user_tfa.last_used_counter`** (two-factor replay protection) only appeared
  the first time somebody enrolled in 2FA. It is now part of the schema from the
  start.
- **The schema check missed tables it only reads.** It covered tables written
  with an explicit column list, so a table the code merely reads from could be
  dropped without anything noticing — of four tables one user actually lost,
  only two would have been named. Coverage now includes every table the code
  touches: **169 tables, 1011 columns**, up from 128.

### Verified
On a genuine fresh install — public tag, empty database, documented steps — the
self-check passes. Dropping all four of the tables that user lost and running
the ordinary `php sql/run_migrations.php` (no flags) names all four, repairs
them, and passes the re-check; a team saves afterwards through the real save
path; and the Notes Log query works before any note exists.

## [4.1.1] - 2026-07-26

TicketsCAD can now check — and repair — its own database structure.

Every health check up to now was about *files*: permissions, stale code,
missing libraries. None of them could see the failure that actually costs
self-hosters their evening: a database whose **structure** has fallen behind the
code, so a screen loads fine and then refuses to save with a bare
`HTTP 400`.

**Upgrading:** `git pull` then `php sql/run_migrations.php` (Docker:
`git pull && docker compose up -d --build`).

### Added
- **`php tools/check-schema.php`** — reports exactly which tables and columns
  your database is missing, and changes nothing. **`--repair`** re-applies the
  schema migrations and re-checks in a fresh process. The migrations are
  idempotent and delete nothing.
- **A "Database schema vs this version" row** on Status → File & Code Health, so
  drift is visible before someone hits it.
- **A save that fails on a missing column now says so** — which column, that
  your data is intact, and the command to fix it — instead of an unexplained
  `HTTP 400`.
- [docs/TROUBLESHOOTING.md#schema-out-of-date](docs/TROUBLESHOOTING.md#schema-out-of-date).

### Fixed
- **The migration runner no longer reports health it has not verified.** It
  decided "already applied" from its own tracker table, which records whether a
  migration *script ran* — not whether the schema that script produced still
  exists. So if a table was dropped during crash recovery, or the database was
  restored from an older backup, every script still read as applied: the runner
  did nothing and reported everything up to date while the app was broken.
  Recovering required `--force`, and nothing suggested it. It now asks the
  database, and re-applies automatically when the two disagree.
- **The commit-time schema gate could not see any of the writers.** It examined
  each SQL string in isolation, but the code builds queries by concatenation —
  so the `INSERT` keyword and the column list never appeared together and all 89
  writer statements were skipped. That is why last release's `teams` problem
  reached a user instead of being caught. The gate now reads concatenated SQL,
  and a generated manifest of every column the code writes to (128 tables, 1008
  columns) is checked against your live database.

## [4.1.0] - 2026-07-26

A resilience release. Everything here comes from real installs run by real
people this week — a power loss that looked like total data loss, a Docker CAD
with no way to run the radio bridge, and a database table that existed in two
incompatible shapes at once.

**Upgrading:** back up first, then run the migrations as usual
(`php sql/run_migrations.php`). Two schema-normalizing migrations are included;
both are idempotent, neither deletes a row, and both report anything they cannot
safely decide rather than guessing.

### Added
- **Automatic backups, on by default.** A daily backup runs on its own — no
  setup, no scheduler required (it also works from cron or Windows Task
  Scheduler via `tools/backup_run.php`). Interval, retention and destination are
  configurable, and a warning appears if there has been no recent verified
  backup.
- **Backups are verified, not assumed.** Every archive is reopened and checked
  to contain a real database dump before it counts as a success.
- **A restore tool — `tools/restore.php`.** There previously was no way to
  restore a backup. It is dry-run by default, verifies the archive before
  touching anything, and takes a safety backup of the current database first, so
  restoring the wrong file is itself undoable.
- **`restore.php --drill` — prove a backup restores.** Restores a backup into a
  throwaway database, reports how many tables and rows came back next to what is
  live, then drops it. Your real database is only read.
- **Docker deployment for the DMR bridge** — `services/dvswitch/docker/` and
  [docs/RADIO-DMR-DOCKER.md](docs/RADIO-DMR-DOCKER.md). Runs the bridge and its
  AMBE vocoder together, configured entirely by environment variables, and
  refuses to start if the vocoder is not answering (a bridge with a dead vocoder
  otherwise connects normally and passes silence).
- **[A getting-started guide for beginners](docs/GETTING-STARTED-FOR-BEGINNERS.md)**
  — what TicketsCAD is, how to open it, the address-versus-folder gotcha, and
  free links for learning the command line, Docker and git.

### Fixed
- **A damaged database table no longer looks like an empty list.** After an
  unclean shutdown a single unreadable table could make a whole screen render
  empty — which reads as "my data is gone" when the records are safe. Affected
  screens now say which table is damaged, that the data is likely recoverable,
  and where the repair steps are.
- **Teams could exist with no name, and on some installs the Teams screen would
  not load or save.** The `teams` table had two competing definitions, so the
  columns an install ended up with depended on the order the setup scripts ran,
  and the built-in seed wrote to columns that later became read-only — leaving
  four unnamed teams. There is now one canonical definition, and
  `sql/run_teams_schema_normalize.php` brings any install onto it.
- **The same hazard is now impossible.** `member`, `member_types`,
  `member_status` and `constituents` were each defined by two different files as
  well; all are consolidated, and a test now fails the build if any table is
  ever defined twice again.
- **MySQL troubleshooting** for two situations that cost users an evening each:
  MySQL not starting or not staying running, and recovering a crashed table
  after a power loss. See [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md).
- Docker: the guide now states plainly that updating requires
  `git pull` **and** `docker compose up -d --build` — a pull alone does not
  update a running container.

## [4.0.6] - 2026-07-25

### Fixed
- After a crash or power loss with MySQL running, the dashboard could spin
  forever and look like a fresh install — because MySQL hadn't finished
  recovering and the incident tables couldn't be read yet. The data was never
  lost, but nothing said so. The dashboard now detects "database reachable but
  its tables can't be read" and shows a calm **"Your data is not lost"** page —
  with what to check and a link to recovery steps — instead of an endless
  spinner. A genuine, readable empty database (a real fresh install) is
  unaffected.
- Added a TROUBLESHOOTING.md section, "App looks empty / fresh install after a
  crash or power loss," with the safe recovery procedure (back up the data
  folder first, `innodb_force_recovery`, export, reimport) and prevention.

## [4.0.5] - 2026-07-24

### Fixed
- [docs/TRACCAR-SETUP.md](docs/TRACCAR-SETUP.md): documented that installs serving
  NewUI from a subdirectory (a "dual mode" setup where NewUI runs alongside the
  legacy app under `/newui/`) must include that prefix in the position-forwarder
  URL — e.g. `https://<host>/newui/api/location.php?provider=traccar`. A missing
  prefix is the usual cause of an HTTP 404 from Traccar's forwarder.

## [4.0.4] - 2026-07-24

### Added
- **[docs/HTTPS-SETUP.md](docs/HTTPS-SETUP.md)** — a step-by-step guide to putting
  HTTPS in front of TicketsCAD, with recipes for four situations: a public domain
  (Caddy + automatic Let's Encrypt), no open ports (Cloudflare Tunnel), a LAN with
  a domain (Caddy + DNS validation), and a LAN with no domain (mkcert).
- For installs that deliberately run on plain HTTP, an **administrator can now
  acknowledge** the "not encrypted" reminder. Acknowledging quiets it for 7 days,
  after which it returns on the next admin sign-in and must be re-acknowledged
  (each acknowledgment is audit-logged) — so the reminder can be quieted without
  ever being permanently forgotten. Non-admins and the login page keep the gentle
  dismissible note.
- Diagnostics now shows a "Connection encrypted (HTTPS): yes/no" row.

### Fixed
- Docker on small hosts (Raspberry Pi, low-RAM VMs): added troubleshooting for
  `container ticketscad_db is unhealthy` — the database container exiting before it
  becomes healthy, usually from out-of-memory (build + database competing for RAM),
  a 32-bit OS (MariaDB 11 is 64-bit only), or a half-initialized data volume. See
  [docs/DOCKER.md](docs/DOCKER.md).
- The "skip to content" accessibility link no longer lands on a warning banner
  (it now targets the page's real content).

## [4.0.3] - 2026-07-24

### Fixed
- Map overlays: renaming a map markup (marker, line, circle, or polygon) no
  longer erases its shape. A rename now updates only the name and leaves the
  geometry and colour intact. (GH #3)
- Location ingest (Traccar / OwnTracks / OpenGTS): opening the ingest URL in a
  web browser to test it used to return `{"error":"Not authenticated"}`, sending
  people to chase a non-existent authentication problem. The endpoint now answers
  a browser with a clear "this URL is correct — it accepts POST only, and this is
  not an auth failure" message. Position forwarding itself was always POST and is
  unaffected.
- Upgrade orchestrator (`tools/upgrade/run.php`): the one-command legacy → v4
  upgrade could fail two ways — the pre-upgrade database backup silently produced
  an empty file, and the schema-migration steps aborted with
  "Cannot redeclare step()". Both are fixed: the backup falls back to the built-in
  PDO dump when `mysqldump` can't authenticate, and each migration step now runs
  as an isolated subprocess.

## [4.0.2] - 2026-07-21

### Added
- Call-sign lookup: a new **OpenCallbook** provider ([opencallbook.com](https://opencallbook.com))
  that resolves both amateur radio **and GMRS** call signs in a single query, and
  is now the default provider. Configurable under Settings → FCC Lookup, alongside
  the existing local-database, callook.info (amateur-only), and self-hosted
  FCC-ULS-API options.
- A configurable **lookup identity (User-Agent)** for internet call-sign lookups:
  send this site's name along with the software name and version (full), or the
  software name and version only (minimal).

### Fixed
- GMRS call-sign lookups returned "No Record Found" because the previous default
  (callook.info) only covers the amateur database. Installs still on that default
  are automatically migrated to OpenCallbook; deliberate offline choices (local
  database / self-hosted FCC-ULS-API / disabled) are left unchanged.

### Changed
- docs/DOCKER.md: expanded the "Upgrading" section — back up first, pin to a
  release tag, verify migrations ran, and how to roll back.

## [4.0.1] - 2026-07-20

### Added
- Docker: an optional `voice` compose profile that runs the Zello + DMR
  push-to-talk relays alongside the app — `docker compose --profile voice up -d`
  — reusing the app image (nothing extra to build). The app's Apache
  reverse-proxies the browser WebSocket paths (`/zello-ws`, `/dmr-ws`) to the
  relay containers. See docs/DOCKER.md section 8a. (The hardware DMR/AMBE bridge
  and Meshtastic still run on the host — they need a physical radio.)

## [4.0.0] - 2026-07-19

First public release of the NewUI v4 rewrite of TicketsCAD — a from-scratch,
keyboard-first dashboard rewrite of the legacy
[TicketsCAD](https://github.com/openises/tickets) Computer-Aided Dispatch system
(v3.44.x), keeping the same MariaDB schema so existing installs can upgrade in
place. See the README for the feature set and install instructions.

### Added
- Per-unit OwnTracks device tracking: a unit/vehicle can carry its own tracked
  device, provisioned from the unit's Location Sources.

### Fixed
- Mass-casualty bed counts: two units transporting to two different hospitals now
  decrement each facility independently. A receiving facility set on a unit's
  status is always that unit's per-unit destination.
- Incidents are referenced by their case number (not the internal database id)
  throughout close/note/create prompts, report exports, and the activity feed.

### Security
- The Settings API no longer returns stored secret values (SMTP / SMS / Slack /
  etc.) to the browser; secret fields report only whether a value is set.
