# Security advisory — private directories, including database backups, served over HTTP

**Draft. Not published.** Whether and when to publish this is the project
owner's decision.

| | |
|---|---|
| **Identifier** | TICKETSCAD-2026-07-30 |
| **Date** | 2026-07-30 |
| **Affected** | TicketsCAD NewUI, all versions up to and including 4.2.2 |
| **Fixed in** | 4.2.3 — the next release. The fix is on `main`; confirm the version number before publishing this. |
| **Severity** | Critical — unauthenticated disclosure of the complete database; unauthenticated execution of database migration scripts |
| **Who is affected** | Any install whose web server was not configured, by hand, to deny these directories. That is the default. Installs reachable from the internet are affected most severely; installs on a closed LAN are exposed to anyone already on that network. |

---

## What the problem is, in plain language

TicketsCAD is installed by pointing your web server at the folder you unpacked
it into. Everything in that folder is then reachable from a browser — including
several folders that were never meant to be.

The most serious of these is **`backups/`**. TicketsCAD writes its database
backups there. A backup is a complete copy of everything in your system:
incidents, patient details, addresses, phone numbers, your members' names and
contact details, and the password hashes for every account. Until this fix, that
folder sat inside the part of the tree the web server publishes, so a request
like

```
https://your-site/backups/ticketscad-20260728-020000.zip
```

returned the file. No login. No password. Just the file.

This was confirmed in practice, not in theory. On 2026-07-30, from an ordinary
internet connection with no credentials of any kind, against a live install:

* `GET /backups/<archive>.zip` returned **HTTP 200 and a 110 MB ZIP** — a real,
  complete database dump.
* `GET /backups/` returned a **browsable list** of every archive, so an attacker
  did not even need to guess a filename.
* `GET /sql/` and `GET /tools/` returned browsable lists of 181 and 109 internal
  scripts.
* `GET /sql/run_migrations.php` **ran** — that script applies database schema
  migrations, and it had no authentication check of any kind.
* `GET /inc/db.php` was served. That file contains the database username and
  password.

## Why it happened

Not a bug in any single file. The installation instructions put the web root at
the application root, which is simple to follow and works; but it means the web
server publishes every directory in the tree unless it is explicitly told not
to, and nothing that shipped with TicketsCAD told it. The application's own
private directories — backups, database scripts, maintenance tools, PHP
includes — were published alongside the pages that are meant to be public.

A related gap made it worse: three command-line scripts (`sql/run_migrations.php`,
`tools/install_fresh.php`, `tools/check-schema.php`) did not check that they had
been started from a command line, so they would run to completion when requested
over the web.

## What an attacker could do

With the database archive alone: read every incident, every person's contact
details, every member record, and take away the password hashes for offline
cracking. If your TicketsCAD passwords are reused anywhere else, treat those
accounts as at risk too.

With `inc/db.php`: connect directly to the database, if the database also
accepts connections from where they are.

With `sql/run_migrations.php`: cause schema changes on your database without
logging in.

There is no evidence that any specific installation other than the one tested
was accessed. There is also no way to prove one was not, unless you still have
the web server access logs — see "Was I actually hit?" below.

---

## Check your own install — one minute

Run this from any computer, replacing `your-site`. You can also paste the URLs
into a browser.

```bash
curl -s -o /dev/null -w 'backups %{http_code}\n' https://your-site/backups/
curl -s -o /dev/null -w 'sql     %{http_code}\n' https://your-site/sql/run_migrations.php
curl -s -o /dev/null -w 'tools   %{http_code}\n' https://your-site/tools/
```

**Reading the result:**

* `403` or `404` — good. That path is blocked.
* `200` — **affected.** That path is being served. Go to "Fix it now".
* `301` / `302` — inconclusive; you are being redirected (often HTTP → HTTPS).
  Re-run against the address you are redirected to.

If your site is `http://` rather than `https://`, use that instead.

From **v4.2.3 onward TicketsCAD runs these same three checks against itself**
and reports the answer on **Settings → Status**, in the "Web exposure" row. If
that row is green, you are covered — and it will go red again if a future server
change ever re-opens one of these.

---

## Fix it now, without updating

You do not have to update to close this. Pick the section for your web server.
Any of these can be done in a few minutes and takes effect on reload.

### Apache

Create a file called `.htaccess` in the TicketsCAD folder (or add to the
existing one):

```apache
<IfModule mod_alias.c>
    RedirectMatch 404 (^|/)(apache|backups|coordination|drafts|inc|keys|specs|sql|tests|tools|vendor)(/|$)
</IfModule>
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule (^|/)(apache|backups|coordination|drafts|inc|keys|specs|sql|tests|tools|vendor)(/|$) - [F,L]
</IfModule>
```

Then check that your site's configuration actually reads `.htaccess` — in the
`<Directory>` block for your install, `AllowOverride` must be `All` or
`FileInfo`. On `AllowOverride None`, Apache ignores the file completely and
without any warning. While you are there, set `Options -Indexes` (in the site
configuration, **not** in `.htaccess`) to switch off directory listings.

Reload Apache, then re-run the check above.

### nginx

**nginx does not read `.htaccess` at all.** Nothing you put in that file has any
effect. Inside the `server { … }` block for TicketsCAD, add:

```nginx
location ^~ /backups/ { deny all; }
location ^~ /inc/     { deny all; }
location ^~ /sql/     { deny all; }
location ^~ /tools/   { deny all; }
location ^~ /tests/   { deny all; }
location ^~ /specs/   { deny all; }
location ^~ /vendor/  { deny all; }
location ^~ /keys/    { deny all; }
```

The `^~` matters: without it these lose to your `location ~ \.php$` block and
`run_migrations.php` would still execute.

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### IIS

IIS does not read `.htaccess` either. In IIS Manager, select your site →
**Request Filtering** → **Hidden Segments**, and add `backups`, `inc`, `sql`,
`tools`, `tests`, `specs`, `vendor`, `keys`.

### If you cannot change the web server right now

Move the backups out of the published folder, which removes the worst of it in
one command:

```bash
cd /path/to/your/ticketscad
mkdir -p ../backups
mv backups/ticketscad-* ../backups/
```

That is where v4.2.3 keeps them permanently.

---

## What the 4.2.3 update changes

Four independent layers, because no single one covers every install:

1. **Backups moved above the web root** — the default is now `../backups`, a
   sibling of the install folder, so no web server configuration can serve them.
   Archives already in the old location are left where they are (nothing is
   deleted or moved for you), they stay listed and downloadable in
   Settings → Backup, and the Status page tells you to move them.
2. **Deny rules ship in the repository** — the root `.htaccess`, plus
   `sql/.htaccess`, `tools/.htaccess` and `web.config` files for IIS. They arrive
   with the update rather than having to be added by hand.
3. **Every script under `sql/` and `tools/` refuses to run over HTTP** — 296 of
   them now answer `403 CLI only` and stop before touching the database. This is
   the layer that works on any web server in any configuration, including one
   where no deny rules were ever installed.
4. **The install checks itself** — Settings → Status probes the three paths over
   HTTP on every visit and shows a red banner if any of them answers.

An nginx configuration snippet ships at `docs/nginx/ticketscad-hardening.conf`,
and `docs/WEB-SERVER-HARDENING.md` explains which server needs which file.

---

## If your backups directory was exposed

Assume the archives were readable by anyone who found them, and work through
this list. None of it is urgent to the minute, but none of it should wait a
month either.

**Was I actually hit?** If your web server keeps access logs, search them:

```bash
# Apache
sudo grep -E 'GET /(backups|sql|tools|inc)/' /var/log/apache2/access.log*
# nginx
sudo grep -E 'GET /(backups|sql|tools|inc)/' /var/log/nginx/access.log*
```

A `200` response to any `/backups/…zip` request from an address you do not
recognise means an archive left your server. Requests from search-engine
crawlers count — a crawler that fetched the file may have cached it.

**Then:**

1. **Change every TicketsCAD password.** The archive contains password hashes.
   They are bcrypt, which is slow to crack, but "slow" is not "impossible" for a
   weak password. Have each member set a new one; do not reuse anything from
   another system.
2. **Tell anyone who reused their TicketsCAD password elsewhere to change it
   there too.** This is the most likely real-world harm.
3. **Rotate the database password** in `config.php` and in the database itself,
   if `inc/db.php` was also reachable.
4. **Rotate any integration credentials** stored in settings — SMTP, SMS/Twilio,
   Slack, Zello, APRS-IS passcodes, webhook secrets, API tokens.
5. **Review the audit log** (Settings → Audit) for logins or changes you cannot
   account for.
6. **Consider your notification duties.** The archive may contain personal
   information about members and about the public — names, addresses, phone
   numbers, and in some deployments patient details. Whether a disclosure must
   be reported, and to whom, depends on your jurisdiction and on what your
   agency handles. If your organisation has counsel, a privacy officer, or a
   parent agency, this is a question for them, and it is easier to ask early.

---

## Credit and reporting

Found during routine security review of a beta installation on 2026-07-30 and
fixed the same day.

To report a security concern in TicketsCAD, see [`SECURITY.md`](../../SECURITY.md).
Please report privately rather than in a public issue, and allow time for a fix
before disclosing.
