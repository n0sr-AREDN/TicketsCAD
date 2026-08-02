# Installing TicketsCAD on Windows + IIS

`docs/INSTALL.md` is written for Debian/Ubuntu and Apache. Everything in it
applies here in principle — the same database, the same migrations, the same
first-admin step — but five things behave differently enough on Windows/IIS to
lose you a day each, and until now they were only recorded in issue comments.

This page covers those five, then points back at the main guide for the rest.

Every item below was found on a real Windows 11 / IIS / PHP 8.4.22 / MySQL 8.0
install by **a beta tester (@rjonesbsink)**, who traced each one to its mechanism and
verified the fixes through actual HTTP requests rather than only the command
line. Reported as [openises/TicketsCAD#5][i5], [#8][i8] and [#18][i18].

[i5]: https://github.com/openises/TicketsCAD/issues/5
[i8]: https://github.com/openises/TicketsCAD/issues/8
[i18]: https://github.com/openises/TicketsCAD/issues/18

---

## The short version

| Thing | Why it bites | Fix |
|---|---|---|
| `OPENSSL_CONF` is not set | Windows PHP never creates OpenSSL's default config file, so any EC key generation fails | TicketsCAD now works around it. Set it anyway — see [1](#1-openssl_conf-web-push-and-anything-else-that-makes-a-key) |
| `disable_functions` includes `exec` | `@` does **not** suppress "call to undefined function"; the response body comes back empty | Nothing to do — fixed in the app. See [2](#2-disable_functions-and-empty-responses) |
| MySQL 8.0 ≠ MariaDB | Both are listed as supported and they differ in ways that used to fail silently | See [3](#3-mysql-80-versus-mariadb) |
| IIS ignores `.htaccess` | The shipped directory denies are Apache-only | Install the `web.config` files — see [4](#4-iis-ignores-htaccess) |
| Nothing runs the background jobs | Windows has no systemd, so the two timers the Linux guide relies on simply do not exist. PAR checks never time out and notifications never leave the queue | Create one Task Scheduler entry — see [5](#5-the-background-jobs-need-task-scheduler) |

---

## 1. `OPENSSL_CONF`, Web Push, and anything else that makes a key

### What you would see

Settings → Web Push → **Generate new key pair**:

```
Keypair generation failed: VAPID keypair generation failed: Unable to create the key
```

True, and it tells you nothing. Two layers down, OpenSSL is being specific:

```
error:07000072:configuration file routines::no such file
```

### Why

Generating any elliptic-curve key by **named curve** — which is every Web Push
VAPID key — requires OpenSSL's configuration file. OpenSSL looks at its
compiled-in default:

```
C:\Program Files\Common Files\SSL\openssl.cnf
```

The Windows PHP distribution never creates that file. It *does* ship a perfectly
good copy at `<PHP_DIR>\extras\ssl\openssl.cnf` — it is just never wired up.

This is not a TicketsCAD problem, or a Web Push problem. It affects any PHP code
on Windows that calls `openssl_pkey_new()` with a `curve_name`.

### What TicketsCAD does about it now

As of the fix for [#8][i8], TicketsCAD locates PHP's own `openssl.cnf` and
generates the key with it explicitly. **Web Push key generation works on a stock
Windows PHP install with no environment changes**, from both Settings and
`php tools/generate_vapid_keys.php`. When the fallback is what produced the key,
it says so, because your host still has an unconfigured OpenSSL and other things
may trip on it.

### Setting it properly anyway

Recommended, since it fixes the underlying condition rather than one symptom of
it.

**Either of the two locations below works on its own**, for both the CLI and the
web UI, and neither requires a reboot. Setting both is defensible belt-and-braces
— it is not a requirement.

**a) The machine environment:**

```powershell
setx OPENSSL_CONF "C:\PHP84\extras\ssl\openssl.cnf" /M
```

**b) IIS FastCGI's own environment collection:**

```powershell
& "$env:windir\system32\inetsrv\appcmd.exe" set config -section:system.webServer/fastCgi `
  "/+[fullPath='C:\PHP84\php-cgi.exe'].environmentVariables.[name='OPENSSL_CONF',value='C:\PHP84\extras\ssl\openssl.cnf']" `
  /commit:apphost
```

Adjust both paths to your actual PHP install location.

### The part that will actually cost you a day

**`php-cgi.exe` processes are pooled, and they survive `iisreset` and a `W3SVC`
restart.** A pooled worker keeps the environment block it was spawned with, so an
environment or `applicationHost.config` change can appear to have had *no effect
whatsoever* — the worker keeps reporting the old value, and stock key generation
keeps failing — long after you have set the variable correctly.

This is the failure mode this page exists to prevent, because its shape is
misleading: you set the variable, you test, nothing changes, and the reasonable
conclusion is that the variable was not the problem. It was. The processes
holding the old environment were simply never replaced.

To make a change take effect, replace the workers:

```powershell
taskkill /F /IM php-cgi.exe
```

An application-pool recycle also spawns fresh children. A reboot certainly does.
`iisreset` alone may not.

> Historical note, since an earlier version of this page said otherwise: this
> guide previously claimed that FastCGI's `environmentVariables` collection
> *replaces* the inherited environment rather than merging with it, and that a
> machine-wide `setx` therefore could not reach the web UI. That is not what
> happens. a beta tester subsequently tested the full matrix — each location alone,
> with and without a reboot, each run after killing `php-cgi.exe` — and the
> machine variable alone works in the FastCGI worker. The pooled-worker behaviour
> above is the better explanation for what both of us originally observed, and
> the claim it replaced has been removed rather than left standing because it
> sounded plausible.

### Confirming it

```powershell
php -r "var_dump(getenv('OPENSSL_CONF'));"
php -r "var_dump(openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC]) !== false);"
```

The second must print `bool(true)`.

Then confirm the **web** side separately, by generating a key from Settings → Web
Push rather than from the shell. The CLI and the FastCGI worker are different
processes with different environment blocks, and — per the pooling note above —
the worker can be running with a stale one. If the web side disagrees with the
command line, kill `php-cgi.exe` before concluding anything.

### If you are testing the keys by hand

Only relevant if you are verifying the generated keypair yourself rather than
trusting the round-trip test. `minishlink/web-push` takes **two different
encodings** of the same keys:

| Call | Wants |
|---|---|
| `VAPID::validate()` | the base64url **stored form**, as saved in settings |
| `VAPID::getVapidHeaders()` | **raw binary** for both keys — it base64url-encodes them itself |

Passing the stored form to `getVapidHeaders()` fails with `Invalid data: only
uncompressed keys are supported`, which reads like a malformed key rather than a
double-encoding, and sends you looking in the wrong place.

---

## 2. `disable_functions` and empty responses

`disable_functions = shell_exec, exec, system, passthru, popen` is a common
hardening default on Windows/IIS PHP. It is a reasonable thing to have set, and
you should not need to undo it.

The trap: PHP's `@` error-suppression operator does **not** suppress the fatal
"Call to undefined function" that a disabled function raises. With
`display_errors` off — the documented production posture — the failure mode is a
completely empty HTTP response body, which surfaces in the browser as:

```
Unexpected end of JSON input
```

...with nothing anywhere naming the cause.

**This is fixed.** Every affected call site now uses the argv-array form of
`proc_open()`, which is not usually included in the hardening presets that remove
`exec`/`shell_exec`. Converted in commit `8a9ec2a`, covering:

| File | What was breaking |
|---|---|
| `sql/run_migrations.php` | the whole migration runner |
| `tools/install_fresh.php` | fresh install |
| `tools/check-schema.php` | `--repair`, twice |
| `api/health.php` | `/api/health.php`, taking the System Status page with it |
| `inc/tts/engine.php` | text-to-speech binary detection |
| `proxy/ZelloProxyApp.php` | the same, in the Zello proxy |

There is a suite gate (`tests/test_no_shell_command_execution.php`) that fails if
any of them comes back.

**One Windows-specific bonus:** `wmic` does not exist on Windows 11 24H2 or
later. `api/health.php` now falls back to
`Get-CimInstance Win32_OperatingSystem` for the uptime figure.

If you would rather allow subprocesses outright, remove `exec` and `shell_exec`
from `disable_functions` — but you do not need to, and leaving them disabled is
the better posture.

---

## 3. MySQL 8.0 versus MariaDB

The README lists both as supported, and they differ in ways that matter.

**`TEXT` columns cannot have a literal `DEFAULT` in MySQL 8.0.** MariaDB permits
it. `sql/dashboard_tables.sql` had one, so on MySQL 8.0 the
`dashboard_layouts` table was never created, every dashboard-layout save failed,
and nothing said why — the installer counted the failed statement and moved on.
Fixed, and the installer now classifies per-statement failures instead of
aggregating them into a number: "already exists" stays quiet, anything else
prints the SQLSTATE, the statement and the driver's message.

**After any install or upgrade on MySQL, run:**

```powershell
php tools\check-schema.php
```

It compares the live schema against `sql\schema_manifest.json` and names anything
missing. `--repair` re-runs the migrations.

Note the limit, because it has caught people out: `--repair` re-runs
`sql\run_*.php` migrations, so it cannot fix a foundational `.sql` file imported
only by `install_fresh.php`. If `check-schema.php` reports a missing **table**
that no migration creates, import that file directly.

---

## 4. IIS ignores `.htaccess`

**The web root is the application root**, so IIS publishes every directory in the
tree unless told otherwise — including `backups/` (complete database dumps),
`inc/` (holds your database password), and `sql/` + `tools/`.

TicketsCAD ships `.htaccess` denies, and **IIS does not read them**, as
completely as nginx does not.

What ships for IIS: `sql\web.config` and `tools\web.config`, covering the two
worst directories. You should add the equivalent for `backups\` and `inc\`.

Full instructions and a three-command test to prove it worked are in
[`WEB-SERVER-HARDENING.md`](WEB-SERVER-HARDENING.md#iis-windows). Do not skip the
test — this is the class of problem where everything looks fine right up until it
is found by someone else.

TicketsCAD also probes its own public URLs and reports the result on
**Settings → Status**, so a later `applicationHost.config` edit that re-opens one
of these gets noticed.

There is a second layer regardless of web server: every script under `sql\` and
`tools\` refuses to run under a non-CLI SAPI. That is the only protection that
works in any configuration, and it is why an exposed `sql\run_migrations.php`
cannot be triggered over HTTP even if your `web.config` is missing.

---

## 5. The background jobs need Task Scheduler

**This one is not cosmetic and it does not announce itself.** TicketsCAD has two
background jobs. On Linux they are driven by systemd timers. Windows has no
systemd, so unless you create a scheduled task **they never run at all** — and
everything else keeps working, so there is nothing to notice.

| Job | What stops happening if it never runs |
|---|---|
| `par_tick` | PAR checks are initiated but never time out. A unit that fails to answer is never marked missed — the check appears to run and silently never completes. |
| `pending_messages_tick` | Queued notifications — push, webhooks, SMS, e-mail, Slack — stay queued instead of going out, along with messages held for a security label's kill window. |

On the install that reported this, the first manual run cleared a backlog that
had been accumulating since install day:

```
par_tick: cycles_started=0 units_missed=0 units_expired=19 cycles_expired=16
```

Nineteen units and sixteen cycles, none of which had ever timed out.

### Create the task

One entry, every minute, running both ticks. One minute is Windows' minimum
repeat interval and it matches both jobs' interval. From an **elevated** prompt,
with the path adjusted to your install:

```powershell
schtasks /Create /TN "TicketsCAD Background Jobs" /SC MINUTE /MO 1 `
  /RU SYSTEM /RL HIGHEST /F `
  /TR "C:\inetpub\wwwroot\TicketsCAD\tools\run-scheduled-jobs.bat"
```

`tools\run-scheduled-jobs.bat` ships with TicketsCAD. It uses `php` from `PATH`;
set `TICKETSCAD_PHP` to a full path if PHP is not on `PATH`. It must be
**`php.exe`, not `php-cgi.exe`** — both scripts refuse to run under a non-CLI
SAPI.

### Verify it is *firing*, not merely registered

These look identical in the Task Scheduler UI, and a registered task that never
fires is the failure this whole section exists to prevent. Watch the run counter
actually move:

1. Settings → **Status** → **Scheduled background jobs**, note the **Runs** column
2. Wait ~75 seconds
3. Reload — the count must have gone up, and **Last success** must be recent

Or from the shell:

```powershell
schtasks /Query /TN "TicketsCAD Background Jobs" /V /FO LIST
```

If the job has still never run, Settings → Status says so and tells you what to
check. Prior to v4.2.3 it told you to run `systemctl`, which does not exist here
— if you see that, your install predates this page.

### The Zello proxy has the same gap

`proxy/newui-zello-proxy.service.example` is a systemd unit with
`Restart=on-failure` and log redirection; there was no Windows equivalent, and
`proxy/start-proxy.bat` ends in `pause`, so it is interactive-only and cannot
survive a logoff.

`proxy\start-proxy-service.bat` now ships alongside it — same restart loop, same
log redirection, no `pause`. Register it to start with the machine:

```powershell
schtasks /Create /TN "TicketsCAD Zello Proxy" /SC ONSTART `
  /RU SYSTEM /RL HIGHEST /F `
  /TR "C:\inetpub\wwwroot\TicketsCAD\proxy\start-proxy-service.bat"

schtasks /Run /TN "TicketsCAD Zello Proxy"      # start it now, without rebooting
```

`/SC ONSTART` is the part that matters: a proxy started by hand in a console
window does not come back after a reboot.

---

## Everything else

Follow [`INSTALL.md`](INSTALL.md) for the parts that are the same:

- Creating the database and user
- `config.php`
- `php sql\run_migrations.php`
- `php tools\create_admin.php`
- First login and post-install settings

Two notes on translating it:

- **Ownership.** The Unix `chown`/`chmod` steps have no direct equivalent. What
  matters is the same: the application pool identity (typically
  `IIS AppPool\<PoolName>`) needs **write** access to `uploads\`, `cache\` and
  `backups\`, and **read** everywhere else. Do not grant write access to the
  whole tree.

  A trap worth knowing about, because it does not present as a permissions
  problem: if the webroot loses its explicit user ACL, `BUILTIN\Users` still
  inherits `ReadAndExecute` from `wwwroot`, so **IIS keeps serving the site
  perfectly while you can no longer edit or `git pull` anything in it**. Nothing
  reports an error until you try to write. Restore it from an elevated prompt:

  ```powershell
  icacls "C:\inetpub\wwwroot\TicketsCAD" /grant "<user>:(OI)(CI)M" /T
  ```
- **Scheduled jobs.** Where the Linux guide uses systemd timers, use **Task
  Scheduler** — see [5](#5-the-background-jobs-need-task-scheduler) for the two
  jobs that TicketsCAD itself requires.

---

## If something else on Windows/IIS bites you

Open an issue at <https://github.com/openises/TicketsCAD/issues>. This page
exists because one administrator wrote up what he hit instead of working around
it privately, and every item on it now either fixes itself or is documented.
