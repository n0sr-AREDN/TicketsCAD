# Changelog

All notable changes to TicketsCAD (NewUI v4) are documented here.
The format loosely follows [Keep a Changelog](https://keepachangelog.com/).

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
