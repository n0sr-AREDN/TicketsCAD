# Changelog

All notable changes to TicketsCAD (NewUI v4) are documented here.
The format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## [4.2.5] - 2026-08-03

A correctness release. The most important item closes a bug that could tell a
dispatcher every unit was clear while a crew was still assigned.

### Fixed

- **On MySQL 8.0, auto-close reported "all units clear" and closed incidents
  with crews still assigned.** `clear = ''` throws error 1525 on MySQL 8.0 —
  an empty string is not a valid `DATETIME` — and `inc/auto_close.php` caught
  the failure and returned `0`, which is the specific value that authorises a
  close. The audit log then recorded the closure as fact. Nine sites carried
  the same literal, two of them beyond the seven originally reported: one was
  written `` `clear` = '' `` with backticks, so a search for the plain spelling
  missed it and it had no `catch` at all, throwing out of every status change
  through a conditional edge; another had the same fault on `ticket.deleted_at`
  where the fallback query dropped *both* the `deleted_at` and the `status`
  filter, so the incident picker silently listed deleted and closed incidents.
  The count now returns `-1` and logs when it cannot be taken, auto-close
  declines, and the sweep skips **without discarding the schedule**. Reported by
  Ron Jones (@rjonesbsink), who also supplied a fix.
- **The "Test" button under Voice & Speech could never play audio, and live
  Zello voice was blocked by the same gap.** The Content-Security-Policy had no
  `media-src`, which does not fall back to `'self'` for `data:` or `blob:` URIs
  — both are opaque origins. `api/tts.php` returns a `data:` URI and the Zello
  widget binds a `MediaSource` object URL, so both were refused. The Test button
  also announced success *before* calling `play()` and discarded the failure in
  an empty `catch`, which is why this presented as unexplainable rather than
  merely broken. Reported by Ron Jones (@rjonesbsink).
- **Soft-deleted incidents were returned in full by the External API and the
  dispatcher board.** The read paths in `api/external/v1/incidents.php` and
  `api/incidents.php` had no `deleted_at` term; only the write guards did.
  Fixing it surfaced a second defect: the wastebasket's own projection asked
  `ticket` for columns that do not exist, so the query threw, the guard
  swallowed it, and deleted incidents were **invisible in the recovery UI** —
  hidden everywhere *and* unrecoverable. Reported by Ron Jones (@rjonesbsink).
  Note this release fixes the two reported endpoints; a wider pass over the
  remaining incident read paths is tracked separately.
- **Documentation named a menu item that does not exist.** Guidance across the
  runbooks, install guides and security advisories said "Settings → Status".
  The menu item is **System Health**; "Settings → Backup" is **Backup /
  Maintenance**. An operator following a Critical advisory went looking for
  something that was not there. The published advisories have been corrected in
  place, and a gate now derives the real labels from the navigation source and
  fails on any documented path that does not exist.

### Added

- **PAR roll calls can now include units marked unavailable, and do so by
  default.** Previously an `available`/`unavailable` comparison matched by
  substring, so a unit marked *unavailable* was dropped from the roster by
  accident. Matching is now exact. Whether an out-of-service unit belongs in a
  roll call is an agency decision, so it is a setting
  (`par_include_unavailable_units`) documented at the control and in the user
  guide. **The default is to include them**: an assigned unit that goes
  unavailable may mean the apparatus is out of service, or that the crew has
  stopped answering, and those look identical from the console. Including costs
  one extra acknowledgement; excluding lets a roll call report itself complete
  while a crew is unaccounted for.

## [4.2.4] - 2026-08-03

**A security release, and if you run TicketsCAD on Windows it is urgent.
Updating to 4.2.3 is what created two of the problems below — no
misconfiguration was needed and no instruction had to be followed.**

Three advisories accompany this release. Two are new; the third was published
with 4.2.3 and has been corrected, because the one-minute self-check it told you
to run does not prove what it said it proved.

- **[GHSA-p579-pg9g-fvw5](https://github.com/openises/TicketsCAD/security/advisories/GHSA-p579-pg9g-fvw5)**
  — Critical. 4.2.3 moved database backups "above the web root", computed as the
  parent of the install directory. That is correct on Linux (`/var/www/newui` →
  `/var/www`) and **inverted on Windows**: `C:\inetpub\wwwroot\TicketsV4` gives
  `C:\inetpub\wwwroot`, the document root of IIS's Default Web Site on port 80.
  XAMPP does the same thing. So on those hosts the upgrade moved every archive
  out of one published directory into another one.
- **[GHSA-3jmh-c6f6-64jc](https://github.com/openises/TicketsCAD/security/advisories/GHSA-3jmh-c6f6-64jc)**
  — High. The same mistake, one directory over: the RSA field-encryption private
  key and the 2FA encryption key were written to `<install>/../keys`, which is
  outside the web root on Linux and inside a served one on Windows. That
  directory was confirmed reachable — a control file in it returned HTTP 200.
  `private.pem` returned 404 only because IIS ships no MIME mapping for `.pem`,
  which is an accident of the file's name rather than a control, and **Apache
  serves it as plain text**.
- **[GHSA-rrp6-pqhj-w5wj](https://github.com/openises/TicketsCAD/security/advisories/GHSA-rrp6-pqhj-w5wj)**
  — Critical, published with 4.2.3, **now corrected**. It told you to request
  `https://your-site/backups/` and read a `403` as "blocked". On a real install
  the folder answered `403` while the archive inside it answered `200` and
  downloaded in full. Any server with directory listing off and no rule denying
  files behaves that way, and on Apache that is the default.

### If you checked before, check again

The old check was wrong, so a clean result from it means nothing. Ask for **an
actual archive by name** — get a filename from Settings → Backup — and ask every
site and port your server publishes, not only the one TicketsCAD runs on:

```bash
curl -s -o /dev/null -w 'archive %{http_code}\n' \
     https://your-site/backups/ticketscad-20260728-020000.zip
```

`403`, `404` or `401` on a real filename is good. `200` means that archive is
being served. A `403` on the folder proves nothing either way.

### Security

- **Backups and encryption keys now default outside every site root on Windows**
  (`%ProgramData%\TicketsCAD\...`). POSIX defaults are unchanged, because they
  were correct. **Nothing is moved for you** — an interrupted key move is worse
  than the exposure it would fix, and an install whose keys are already in the
  old location keeps using them, so upgrading cannot break field encryption or
  lock every 2FA user out. Settings → Status gains rows that grade both
  directories, prove reachability with a short-lived random-token canary, print
  platform-correct move instructions, and say plainly what they could not see.
- **The exposure check can no longer answer "safe" from a directory request.**
  It names a real archive, or writes a canary and asks for that back, or reports
  a distinct grey **"Not determined"**. An install with no backup yet is
  reported as untested, which is not the same as safe.
- **The IIS `web.config` files shipped in 4.2.3 did not deny anything** — they
  returned HTTP 500.19 on a stock install, so the directory was blocked by the
  file being invalid rather than by the rule working. Three independent defects,
  each fatal alone. If you see 500.19 on a directory after upgrading, **replace
  the file rather than deleting it**; deleting it restores the exposure.
- **Hidden Segments is no longer recommended anywhere.** Our own hardening
  documentation told IIS administrators to add segments for `backups`, `inc`,
  `sql`, `tools`, `tests`, `specs`, `vendor` and `keys`. That rule matches *any*
  path segment, so `vendor` also blocks `assets/vendor/` and serves every page
  unstyled — and it does not protect the directory either. If you applied it,
  remove those entries.

### Fixed

- **Windows system uptime** no longer depends on `wmic`, removed in Windows 11
  24H2. It falls back to PowerShell and, where neither is available, says why
  instead of reporting "Unknown".
- **The routing engine reference documented filter keys the engine never reads.**
  An unrecognised filter key is ignored rather than rejected, so a rule copied
  from that page saved cleanly and then matched as though the condition were
  absent — a route meant to narrow to one incident type fired on all of them.

### Credits

Every security item in this release was reported by **Ron Jones**
([@rjonesbsink](https://github.com/rjonesbsink)), who tested what the shipped
fix actually did on his own server rather than assuming it had worked, and
reported each finding privately with a verified correction.

## [4.2.3] - 2026-08-02

**A security release. Please update, and please run the one-minute self-check
below even if you cannot update today.**

Two security advisories are published alongside this release. The first is the
more serious of the two and affects a default installation:

- **[GHSA-rrp6-pqhj-w5wj](https://github.com/openises/TicketsCAD/security/advisories/GHSA-rrp6-pqhj-w5wj)**
  — *Critical.* Private directories, including database backups, were served
  over HTTP on a default install.
- **[GHSA-984v-rw78-3223](https://github.com/openises/TicketsCAD/security/advisories/GHSA-984v-rw78-3223)**
  — *Moderate.* The External API's "require TLS" setting did not enforce TLS.

**Upgrading:** `git pull` then `php sql/run_migrations.php`. Docker:
`git pull && docker compose up -d --build`.

> ### ⚠ If you run behind a reverse proxy, one setting needs your attention
>
> This applies if something else terminates HTTPS and passes the request to
> TicketsCAD — Cloudflare, Nginx Proxy Manager, IIS ARR, a load balancer.
>
> The TLS fix works by no longer taking a request header's word for whether the
> connection was encrypted, and that header is exactly how your proxy tells
> TicketsCAD the original request was HTTPS. **List your proxy in the
> `trusted_proxies` setting**, or legitimate External API requests will
> correctly be refused with `426`. The default is `127.0.0.1,::1`, which covers
> the same-host case only. The refusal now explains itself rather than failing
> silently, but you still have to make the change.

### Check your own install — one minute

```bash
curl -s -o /dev/null -w 'backups %{http_code}\n' https://your-site/backups/
curl -s -o /dev/null -w 'sql     %{http_code}\n' https://your-site/sql/run_migrations.php
curl -s -o /dev/null -w 'tools   %{http_code}\n' https://your-site/tools/
```

`403` or `404` is good. **`200` means you are affected** — see the advisory.
`301`/`302` is inconclusive; re-run against the address you land on. From this
release onward TicketsCAD runs these same checks against itself and reports the
answer on **Settings → Status**, in the *Web exposure* row.

### Security

- **Your database backups were downloadable from the web, with no login.** The
  install instructions point the web server at the application folder, so every
  directory in it was published unless an administrator had blocked them by
  hand — and nothing that shipped told them to. `backups/` was the worst of it:
  a complete database archive, including every password hash. `sql/` and
  `tools/` were browsable, `inc/db.php` served the database credentials, and
  `sql/run_migrations.php` *executed* when requested over HTTP. Confirmed from
  the public internet against a live install, not inferred. Four independent
  layers now ship: backups moved above the web root, deny rules in the
  repository for Apache and IIS, an nginx snippet plus documentation, and a
  CLI-only guard on every script under `sql/` and `tools/` that works on any
  web server in any configuration. See GHSA-rrp6-pqhj-w5wj, which includes
  what to do if your backups directory was exposed.
- **The External API's "require TLS" setting did not require TLS.** With the
  setting on, a plain-HTTP request carrying a valid token was answered `200`
  with real data instead of `426`. Two independent bypasses: the check trusted
  the caller-supplied `X-Forwarded-Proto` header, which defeats it on **every**
  web server, and on IIS it additionally never fired at all, because IIS
  reports plain HTTP by setting a variable to the string `"off"` and the check
  asked only whether that variable was empty. Reading data still required a
  valid token, so this is not an authentication bypass — but a control the
  operator switched on reported success while doing nothing, so integrations
  were configured over plain HTTP and kept working. Reported privately by
  [@rjonesbsink](https://github.com/rjonesbsink). See GHSA-984v-rw78-3223.
- **Outbound webhook deliveries now carry replay protection, and the
  integrator guide now matches the wire.** Deliveries were signed over the
  request body alone, with no timestamp, nonce or delivery id anywhere in the
  request — so a captured delivery re-sent unchanged at any later time still
  verified as authentic, and nothing in it could justify rejection. Deliveries
  now carry `X-Webhook-Timestamp` inside the signed material and
  `X-Webhook-Delivery` as a stable idempotency key.

  **This does not break existing receivers.** The new scheme arrives as
  `X-Webhook-Signature-V2`; `X-Webhook-Signature` keeps exactly its current
  meaning until you set `webhook_legacy_signature` off. `webhook_replay_
  tolerance_sec` (default 300) sets the advertised freshness window.

  **If you build a receiver, re-read the guide.** It previously described a
  timestamped scheme, a `delivery_id` and a JSON envelope that were never
  implemented, and omitted the `sha256=` prefix the code actually sends — so a
  receiver written from it computed the wrong digest *and* compared it against
  the wrong string, and rejected every genuine delivery. `docs/WEBHOOKS-
  INTEGRATOR-GUIDE.md` now describes what is actually sent.
- **Saving a Settings panel wiped the stored secret it never showed you.** The
  panels mask secrets on display, then wrote the mask back on save, silently
  destroying the stored value.
- **The CAD sent the DMR bridge a token hash instead of the token**, and the
  bridge's Docker control surface never started at all.
- **Subprocesses are now spawned without a shell**, closing a class of command
  injection, and two probes that had never worked were fixed.
- **The geocoder gate was itself reachable over HTTP.**

### Added

- **Map tiles can be proxied by your own server.** `tile_mode` is now real: a
  server-side tile proxy with a per-provider policy, so installs behind a
  restrictive network — or blocked by a tile provider's Referer rules — can
  still show a map.
- **Net check-ins can be captured in one keystroke** and worked entirely from
  the keyboard, matching the rest of the dispatch interface.
- **A Telegram channel adapter**, with a test button and a setup guide.
  Contributed by [@rjonesbsink](https://github.com/rjonesbsink) as a pull
  request against the public repository.
- **The Geocoding Provider setting does something.** It was previously
  presented as a choice that had no effect.
- **Address lookup is reported on the Status page**, emitter and reader
  together.

### Fixed

- **An internet outage stalled every dispatch action for 21 seconds.** Outbound
  calls now have gated timeouts, and the notification sweep no longer pays a
  full timeout per row. What the product does and does not do without an
  internet connection is now documented and measured rather than assumed.
- **Web Push key generation now works on stock Windows PHP**, and Windows/IIS
  has a setup guide.
- **Background jobs never ran on Windows**, and the advice for fixing it said
  to run `systemctl`.
- **The web-server hardening rules denied `assets/vendor/`**, so Bootstrap and
  Leaflet returned 403 and the interface rendered unstyled. If you applied the
  hardening by hand before this release, take the corrected rules.
- **Two buttons on the unit form submitted the page** instead of running their
  handler — a `<button>` with no `type` inside a `<form>`.
- **Deploy no longer takes the operator's backup directory away from them**,
  and a permission repair can no longer abort an otherwise healthy deploy.
- **Zello reconnection backoff never escalated**, because transport-level
  success reset the counter.
- **A channel's destination is bound to its credential**, not to the message.
- **Map layers you turned off are remembered**, not only the ones you turned on.
- **The one label on incident detail that no administrator could translate** is
  now a caption key like every other.
- Two schema columns that only a fresh install ever received, and an
  `owntracks_outbox` column in the same position, are now created on existing
  installs too.
- Four reported defects where the product's own documented remedy was itself a
  dead end.

### Changed

- **New dashboard widgets are held to the interface conventions** by an
  automated gate, so they look and behave like the existing ones.
- **The release process can no longer silently revert public-only changes.** A
  release is a full-tree replace, so a pull request merged only in the public
  repository used to disappear at the next release with nothing to show for it.
  The snapshot now compares against the public repository and refuses to
  publish if it would discard anything.
- The SBOM now covers two packages our own installer installs but the bill of
  materials had missed.
- The README undercounted the test suite by a factor of four.

## [4.2.2] - 2026-07-30

A security and reliability release. **It closes a privilege-escalation hole in
the permissions system — please update.** It also revives two background jobs
that had never once run on a real install (one of them the Personnel
Accountability Report roll-call), fixes a clock bug that made a healthy radio
position feed look dead on any server not set to UTC, and repairs a test suite
that had been quietly counting empty files as passes.

**Upgrading:** `git pull` then `php sql/run_migrations.php`. Docker:
`git pull && docker compose up -d --build`.

> ### ⚠ The migration step is required this release, not optional
>
> Several fixes below *are* migrations. This release no longer guesses a user's
> permissions from the pre-v4 "level" column when their roles cannot be read —
> guessing is what the security hole was made of. An install that has not been
> migrated is therefore **refused at the login screen**, with the exact command
> to run printed on it. Anyone already signed in is not thrown out mid-incident;
> they see a banner instead. Running the migration clears it.

After upgrading, check three things:

1. **Settings → Users & Roles** — confirm every account has the role you expect.
   The one-time migration that assigns roles to accounts carried over from v3
   had never actually worked, so some accounts may have had no role at all.
2. **Settings → Status → System Health** — a new *Scheduled jobs* row reports
   whether the background jobs this install needs have ever run.
3. **Your scheduler.** If you use Personnel Accountability Reports, delayed
   message release, or automatic backups, confirm something on the server is
   really running the tick scripts — see
   [docs/MAINTENANCE-RUNBOOK.md](docs/MAINTENANCE-RUNBOOK.md). Dropping a file
   into `/etc/cron.d` on a machine with no cron daemon installed fails silently,
   and minimal cloud images routinely ship without one.

### Security
- **Privilege escalation: almost any signed-in account could edit roles and
  permissions.** The endpoint that manages the role system was itself guarded by
  the *old* permission system it replaced — and the old check ran first, so an
  account whose legacy "level" was 0 or 1 skipped the role check entirely. Since
  v4 stopped writing that column, every account created since reads as 0. In
  practice a Dispatcher — or anyone else — could grant themselves any
  permission. The endpoint now asks the role system, on the endpoint that
  manages roles. Six other endpoints (audit log, callsign lookup, compliance,
  vehicles, time entries, languages) carried the same "old system OR new system"
  shape and were closed with it.
- **The pre-v4 "level" system is gone, not deprecated.** It kept coming back
  after v4 declared it dead for one reason: the one-time migration that assigns
  roles to carried-over accounts had never run successfully on any install. It
  queried a column name that does not exist, the error was swallowed, the script
  reported success, and a silent fallback answered permission questions from the
  old column instead. Broken migration, hidden by a caught error, concealed by a
  fallback. The migration is fixed, it now re-checks its own result and fails
  loudly if any account was left without a role, and the fallback has been
  deleted. When permissions cannot be read, the answer is now **no**.
- **Duplicate and orphaned administrator grants.** The uniqueness rule meant to
  stop duplicate role grants never applied to organisation-wide grants, so every
  run of the migration pipeline appended another copy — hundreds on
  long-lived installs. Worse, the seed granted Super Admin to user number 1
  whether or not such an account existed, leaving grants addressed to nobody
  that a future account created with that number would silently inherit. Both
  are fixed and existing databases are cleaned up by the migration.

### Fixed
- **Organisation Admins could not run a single report.** The Reports page let
  them in and the reports API turned them away, because the two halves checked
  different permission systems. Reports now use a new `action.view_reports`
  permission, granted to Super Admin and Organisation Admin; the
  organisation-scoped filtering that was written for exactly this case now
  actually runs.
- **Personnel Accountability Report roll-calls never fired on their own.** The
  scheduled task that starts a PAR on cadence, and that marks a unit *missed*
  when its answer window closes, had never executed. PAR worked only if a
  dispatcher pressed **Initiate** by hand, and an unanswered roll-call produced
  silence. Restarting it needed care rather than enthusiasm: an overdue sweep
  with no upper bound would have raised missed-PAR alarms about incidents closed
  weeks earlier, and a life-safety alert about something that is not happening
  now teaches crews to ignore the one that is. Work more than
  `sched_stale_cutoff_min` minutes past due (default 60) is therefore recorded
  as *expired* and not acted on. Nothing is deleted, and an operator can release
  an expired message by setting it back to pending.
- **Turning PAR off froze it instead of quieting it.** Housekeeping was behind
  the same switch as the feature, so cycles in flight when you switched off
  stayed in flight — and switching PAR back on months later could resume a
  month-old roll-call and escalate it. Switching off now expires stale cycles
  quietly and starts nothing; nothing is ever escalated while PAR is off.
- **PAR was looking at closed incidents.** The scheduler and the rest of the
  feature disagreed about which incident statuses count as live, and the half
  that was wrong was the half that had never run.
- **Automatic backups were not being scheduled at all on some servers.** 4.2.0
  made the scheduler tick on page loads; this release documents and ships the
  supported way to schedule it on a server with no cron daemon (a systemd timer,
  with `Persistent=true` so a machine switched off at the scheduled hour backs up
  at next boot rather than skipping the day), plus the check that tells you
  whether a scheduler exists at all instead of assuming one does.
- **The APRS map reported "0 stations" while the receiver was healthy.** Position
  timestamps are stored in the install's local time; the map was comparing them
  against UTC, so on any server not set to UTC the window matched nothing and the
  page also claimed the listener was inactive. Ten more instances of the same
  mistake were found and fixed in the same sweep: mesh packet ages (which could
  read as negative), external API tokens expiring up to a full time-zone offset
  early while the admin panel still showed them active, several "last heard"
  ages in the browser, and the chat widget's own echo of a message you just sent.
  A new check runs on every build so this cannot come back — it is invisible on a
  UTC server and silently wrong everywhere else, which is most volunteer
  installs.
- **A fresh install reported itself as critically broken.** The new
  scheduled-jobs health check treated a security label that ships enabled by
  default as evidence the delayed-message queue was in use, so a brand-new
  deployment went red before an administrator had touched anything. It now looks
  for a message actually waiting in the queue.
- **`php tools/test_all.php` was counting silence as success.** The runner
  decided each file's result from one line of its output, so a file that stopped
  early — or exited cleanly without reporting anything — was printed exactly like
  a clean pass. Fourteen files, roughly 290 real checks, were contributing
  nothing to a headline number used as proof the release was sound. Files that
  report no result are now their own category, they turn the run red on their
  own, and their output is printed so you can see why. The suite reads **4434
  passed, 0 failed** on this release.
- **Documentation told you to check a log file that no longer proves anything.**
  Four places said an empty tick log means the job never ran. That is true of a
  cron line and false of the systemd timers that replaced it, which log to the
  journal — so the advice had inverted itself and now made a perfectly healthy
  job look dead. Replaced with checks that actually distinguish the two.

### Added
- `action.view_reports` permission (Super Admin and Organisation Admin).
- A **Scheduled jobs** row on Settings → Status → System Health, fed by a
  heartbeat the background jobs write themselves — so it cannot report a run that
  did not happen. It goes red only for jobs this install actually needs.
- `sched_stale_cutoff_min` setting: how far past due background work may be
  before it is expired rather than acted on. Default 60 minutes; 0 disables.

### Changed
- Settings pages now require the administrative *manage configuration*
  permission rather than the broader *view settings* one, so an Operator no
  longer reaches them.
- The Software Bill of Materials was regenerated and re-signed for this version
  (it records the application version, so a version bump invalidates the old
  signature). **The signing key has not changed** — the published fingerprint is
  still `XRcJ3AwAm0OzSzjmU8KWkknftutwY36a6z7st2YrU0g=`, and the verification
  steps in [SECURITY.md](SECURITY.md) are unchanged.

### Removed
- The pre-v4 `user.level` permission fallback, its allow-lists, and the writing
  of that value into the session at login. Every gate in the application is now
  a role/permission check. An automated check runs on every build and fails on
  any comparison against the old column outside the short, reviewable migration
  path.

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
