# Changelog

All notable changes to TicketsCAD (NewUI v4) are documented here.
The format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## [4.2.1] - 2026-07-29

Fixes the test suite that 4.2.0 shipped. **Nothing else changed** — no behaviour
change, no schema change, and every 4.2.0 artifact (the SBOM, its signature and
the public key) was correct and still verifies.

**Upgrading:** `git pull`. No migration. Docker: `git pull && docker compose up
-d --build` — but if you are coming from **4.1.x**, do the backup rescue in the
4.2.0 notes below **first**.

### Fixed
- **`php tools/test_all.php` reported two failures on a fresh clone.** Two
  assertions in `tests/test_sbom.php` inspect `tools/release-snapshot.sh`, which
  the release snapshot deliberately excludes from itself — so it is absent from
  every published copy by design. 4.2.0 was the first release to ship that test
  file, so the problem had never appeared outside the development repository.
  You were being told something was wrong when nothing was. Those assertions now
  skip when the release script is not present, and still run where it is.

  Verified in both shapes: 63 passed / 0 failed in the development tree, and
  60 passed / 0 failed with one skip in a fresh clone of the published v4.2.0
  tag — the exact place the failure showed up.

## [4.2.0] - 2026-07-29

Automatic backups now actually run, the Software Bill of Materials is published
signed so you can verify it yourself, and CSRF protection is enforced on six
endpoints where the check silently never ran.

A minor release rather than a patch: backup management is new functionality, not
a bug fix. (4.1.3 was tagged in the development repository on 2026-07-28 and
never published; its security content ships here.)

> ### ⚠ Docker installs: rescue your backups BEFORE you update
>
> Backups were being written inside the container, to a path that was **not** a
> volume. `docker compose up -d --build` — the documented update step — replaces
> the container and discards that layer. Taking a backup and then updating
> destroyed the backup in the same breath.
>
> This release moves backups into a named volume, but a volume is seeded from
> the image, never from a running container, so **existing backups cannot be
> migrated automatically.** Copy them out first:
>
> ```bash
> # 1. Copy the backups out of the running container FIRST:
> docker compose cp app:/var/www/html/backups ./backups-rescued
>
> # 2. Now pull and rebuild (this is the step that would have destroyed them):
> git pull && docker compose up -d --build
>
> # 3. Put them back, into the volume this time:
> docker compose cp ./backups-rescued/. app:/var/www/html/backups
> docker compose exec app chown -R www-data:www-data /var/www/html/backups
> ```
>
> If step 1 reports that the path does not exist, you had no on-container
> backups and there is nothing to migrate — just rebuild. Full procedure in
> [docs/DOCKER.md](docs/DOCKER.md) §4. `uploads/`, `cache/` and `keys/` were
> already volumes and are unaffected.

**Upgrading:** `git pull` then `php sql/run_migrations.php`. Docker: do the
rescue above, then `git pull && docker compose up -d --build`.

After upgrading, check **Settings → Backup / Maintenance**. Automatic backups
may have been switched on for a long time without ever having produced a file.

### Added
- **Automatic backups that run.** The scheduler function had existed since 4.1.0
  and was called from nowhere — an install without cron or Task Scheduler (the
  common case, and the exact case the feature was written for) reported backups
  as ON and produced nothing. Page loads now tick the scheduler, after the
  response is sent, never on a save.
- **Backup controls** in Settings → Backup / Maintenance: enable/disable,
  interval, retention by count/age/size, backup directory, and a **Back up now**
  button. A **Backups card** on Status → System Health goes amber when backups
  are stale and red when one was refused or none has ever succeeded.
- **Backups cannot fill the disk.** A free-space floor (default 1 GB, checked on
  both the backup and temp filesystems, which are often different) and a folder
  ceiling (default 5 GB). On this hardware a full disk is not degradation, it is
  an outage, possibly mid-incident. The newest archive is never deleted, and the
  first backup is never blocked. Retention now matches only files this
  application wrote, so it can no longer delete unrelated archives that happen
  to share the directory.
- **A signed Software Bill of Materials.** `SBOM.cdx.json` (CycloneDX 1.6, 56
  components) ships with a detached signature `SBOM.cdx.json.sig` (ECDSA P-256 /
  SHA-256) and the public key needed to check it,
  `SBOM-signing-key.pub.pem`. Verify it yourself, without contacting us:

  ```bash
  base64 -d SBOM.cdx.json.sig > sbom.sig
  openssl dgst -sha256 -verify SBOM-signing-key.pub.pem -signature sbom.sig SBOM.cdx.json
  # -> Verified OK
  ```

  or `php tools/generate-sbom.php --verify`. This closes the last of the 17 data
  fields in CISA's 2026 SBOM Minimum Elements: TicketsCAD now meets **17 of 17
  data fields and 6 of 6 practices**. `SBOM.txt` is the human-readable
  rendering. See [SECURITY.md](SECURITY.md).
- **A tracked `VERSION` file**, so the version you see is the code you are
  running.

### Security
- **CSRF was not enforced on six code paths.** `api/messaging-send.php`,
  `api/push-admin.php`, `api/talkgroups.php` (its POST branch and, separately,
  its DELETE branch), `api/aprs-watchlist.php` and `api/aprs-license-accept.php`.
  Five wrapped the gate in `if (function_exists('csrf_check'))` — naming a
  function that does not exist, so the check was skipped rather than failed. The
  talkgroups DELETE branch had no CSRF call at all. Verified against a live host:
  every endpoint now rejects a missing and a wrong token with a JSON 403 and
  leaves the row intact, confirmed by SQL rather than by the API's own reply.
- **Marked and Bootstrap are served from this repository, not a CDN.** Groups
  that operate disconnected should not lose page rendering with the uplink. The
  `marked` reference was also unpinned, so the browser ran whatever the CDN
  served that day; it is now 12.0.2, recorded in the SBOM with its hash.

### Fixed
- **Docker: backups were destroyed by the update that followed them.** See the
  warning above. `docker-compose.yml` now mounts a volume for `backups/` and the
  entrypoint creates it writable.
- **The displayed version could never change.** `NEWUI_VERSION` was defined only
  in `config.php`, which is gitignored, so a completely correct `git pull` left
  the About page showing the install-day version — one install reported
  `4.0.0-dev` against 4.1.3 code. The tracked `VERSION` file now wins, with
  `config.php` as a fallback for odd deployments. Asset cache-busters finally
  move on a pull, too.
- **A fatal error in an API returned an empty body.** A PHP `Error` (TypeError,
  ArgumentCountError, a failed `require`, memory exhaustion) escaped
  `catch (Exception)` and, with `display_errors` off, killed the request *after*
  its writes had committed — reported in the field as "Unexpected end of JSON
  input" on an action that had actually worked. `inc/api_guard.php` now converts
  any fatal into a JSON 500 with a log reference.
- **SOP Markdown had never rendered for anyone.** `sop.php` loaded `marked` from
  a CDN that the application's own Content-Security-Policy blocks, so it fell
  back to plain text on every install, online or offline. Nobody reported it
  because the fallback is readable.
- **Five documents told administrators to `chown -R www-data:www-data .`** That
  takes `.git` with it, so the reader's next `git pull` stops with "fatal:
  detected dubious ownership" — and it was never necessary. Corrected: the tree
  stays with whoever runs git; `uploads/` and `cache/` go to the web server;
  `backups/` is shared (mode 2770) because both the CLI and the web server
  write there.
- **Soft-delete columns never reached upgraded installs.**
- **MySQL 8.0 rejected `dashboard_layouts` at install time**, and hid the reason.
- **The mesh bridge delete endpoint returned an empty JSON response.**
- **Settings silently blanked stored secrets** when a masked field was saved
  untouched, and masked boolean toggles as though they were secrets.
- **The SBOM declared CycloneDX 1.6 and did not conform to it.** One component
  (`mysql-connector-python`) carried the licence identifier
  `GPL-2.0-with-FOSS-exception`, which SPDX does not define, so the document
  failed the official schema outright and would have been rejected by
  Dependency-Track, Trivy and anything else that validates. The licence is now
  the SPDX expression `GPL-2.0-only WITH Universal-FOSS-exception-1.0`, taken
  from Oracle's own `LICENSE.txt`. More to the point, **nothing had ever checked
  the claim**: the generator now validates its output against the official
  CycloneDX schema — vendored unmodified at `tools/schema/cyclonedx/` so you can
  check it offline — and refuses to write a document that does not conform, with
  `php tools/generate-sbom.php --validate` enforced in CI and in the release
  script.
- **The prior SBOM contained incorrect entries.** Everything published before
  this release listed `qrcode 1.5.3` by soldair, which this application does not
  use — it ships `qrcode-generator 1.4.4` by Kazuhiko Arase, a different project
  by a different author. It also listed `pymysql` and `meshcore-cli`, neither of
  which is imported anywhere; the real packages are `mysql-connector-python` and
  `meshcore`. **Anyone who matched those entries against vulnerability data was
  checking the wrong software, and would have missed advisories for the software
  they are actually running.** It further listed 20 of 31 Composer packages, all
  at stale versions, and gave every browser library the version string
  `"bundled"`, which matches nothing. It had not been regenerated since
  2026-06-13 and still described `4.0.0-dev`. Rebuilt from the shipped files
  themselves, 32 components to 56. The release script now also verifies the SBOM
  against the tree that is actually **published**, which caught two further
  errors before this release shipped: a component listed that only a
  development-notes file referenced, and a per-file hash that did not match the
  file as shipped.

### Known limitation
The signature is **detached** (`SBOM.cdx.json.sig`). CycloneDX 1.6 also defines
an in-document `signature` property, which this release does not use — so
`cyclonedx-cli verify` reports no signature even though the detached one is
valid. Use the `openssl` command above, or `--verify`. Native in-document
signing is planned for a follow-up release.

### Verified
The signature was checked with the OpenSSL **command line** — a different
implementation from the PHP extension that produced it — from a directory
containing only the three files a recipient receives, and from a fresh clone of
this repository. A one-byte change to `SBOM.cdx.json` is rejected. The SBOM is
byte-reproducible across operating systems, so you can regenerate it with
`php tools/generate-sbom.php` and compare instead of trusting us. Suite: 3880
tests passing.

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
