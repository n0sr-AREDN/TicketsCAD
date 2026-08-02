# Web server hardening — which server needs which file

TicketsCAD is installed by pointing your web server at the folder you unpacked
it into. That is simple, and it has one consequence worth understanding: **every
folder in that tree is reachable from a browser unless the web server is told
otherwise.** Some of those folders should never be reachable — `backups/` holds
complete database dumps, `inc/` holds the file with your database password,
`sql/` and `tools/` hold command-line scripts.

TicketsCAD ships the rules to close this. **The rules only work on Apache.**
If you run nginx or IIS you must add the equivalent yourself — this page tells
you exactly what to add.

---

## Which do I need?

| Your web server | What protects you | You must… |
|---|---|---|
| **Apache** (XAMPP, Debian/Ubuntu `apache2`, the Docker image) | `.htaccess`, `sql/.htaccess`, `tools/.htaccess` — shipped, arrive with an update | Confirm `AllowOverride` is `All` or `FileInfo` in your vhost. On `AllowOverride None` Apache ignores every `.htaccess` **without warning**. |
| **nginx** | Nothing ships that helps you. nginx never reads `.htaccess`. | Install `docs/nginx/ticketscad-hardening.conf` — see below. |
| **IIS** (Windows) | `sql/web.config` and `tools/web.config` — shipped | Add the equivalent for `backups/` and `inc/` (see below). IIS ignores `.htaccess`. |
| **Caddy** | Nothing ships that helps you. | See below. |
| **Docker (the shipped image)** | Apache inside the container, with the shipped `.htaccess` | Nothing extra. `backups/` also lives outside the web root from v4.2.3. |

Whatever you run, **check it** — the last section of this page is a
three-command test, and TicketsCAD now runs the same test for you on
**Settings → Status → "Web exposure"**.

---

## The list

These folders are not part of the web interface and should return 403 or 404:

| Folder | Why it must not be served |
|---|---|
| `backups/` | Full database dumps. Everything in your system, in one file. |
| `inc/` | `inc/db.php` contains your database username and password. |
| `sql/` | Command-line migration scripts, including `run_migrations.php`, which applies database migrations with no login. |
| `tools/` | Command-line maintenance scripts — installers, schema repair, backup and restore, token minting. |
| `tests/`, `specs/`, `coordination/`, `drafts/`, `apache/` | Test suite, design notes, server config examples. No reason to publish them. |
| `vendor/` | Third-party PHP libraries. |
| `keys/` | Encryption keys, if your install has this folder inside the tree. |
| `services/` | Radio/mesh bridge sources — **with one exception**, below. |
| `.git/` | If you installed by cloning, this reconstructs your whole source tree. |

These folders **must stay reachable** — do not add them to any deny list:

`api/`, `assets/`, `proxy/`, `sw/`, `uploads/`, `cache/`, `documentation/`, and
the `.php` pages at the top level. `proxy/dmr-proxy.php` in particular is loaded
over HTTP by the radio widget, so blocking `proxy/` breaks push-to-talk.

### The one exception inside `services/`

Settings → Mesh gives you a command to install the Meshtastic bridge on your
radio computer, and that command downloads the bridge from your own server:

```
curl -sSfo $HOME/bridge_v2.py 'https://your-site/services/meshtastic/bridge_v2.py'
```

So `services/meshtastic/*.py` and `services/meshcore/*.py` stay downloadable.
Everything else under `services/` does not — a running install keeps
`listener.ini` there (it contains your APRS-IS passcode), possibly `.env` files,
and `services/*/logs/`.

---

## Apache

Nothing to install: `.htaccess`, `sql/.htaccess` and `tools/.htaccess` ship with
TicketsCAD and arrive with a `git pull`. Two things to check.

**1. `AllowOverride` must permit them.** In your vhost (`/etc/apache2/sites-available/…`):

```apache
<Directory /var/www/newui>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

`AllowOverride None` — the Apache default in some distributions — means every
`.htaccess` in the tree is ignored silently. There is no warning in the log.

**2. `Options -Indexes`.** Without it Apache generates a browsable listing for
any folder that has no index page. That is how `GET /backups/` came to show a
list of database archives. `-Indexes` must be set in the **vhost**, not in
`.htaccess`: `Options` needs `AllowOverride Options`, and on a host that grants
only `FileInfo` an `Options` line in `.htaccess` makes Apache return **500 for
the entire site**.

The shipped vhost template `apache/newui.conf.example` now has both, plus
`<DirectoryMatch>` denies that work even if `.htaccess` is ignored. Copying it
is the most robust option.

---

## nginx

**nginx never reads `.htaccess`.** Every `.htaccess` in the TicketsCAD tree is
an inert text file as far as nginx is concerned. On a default
`root /var/www/newui;` server block, `https://your-site/backups/…zip` downloads
your database and `https://your-site/sql/run_migrations.php` is handed to
PHP-FPM and executed.

Install the shipped snippet:

```bash
sudo cp docs/nginx/ticketscad-hardening.conf /etc/nginx/snippets/
```

Then inside the `server { … }` block that serves TicketsCAD:

```nginx
server {
    server_name cad.example.org;
    root /var/www/newui;
    index index.php login.php;

    include snippets/ticketscad-hardening.conf;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

The snippet is written so it does not matter whether the `include` line comes
before or after your `location ~ \.php$` block — the denies use nginx's `^~`
prefix modifier, which beats regular-expression locations regardless of order.
That detail matters: a plain `location /sql/ { deny all; }` would **lose** to
the PHP location and the migration script would still run.

---

## IIS (Windows)

IIS ignores `.htaccess` as completely as nginx does. TicketsCAD ships
`sql/web.config` and `tools/web.config`, which cover the two worst folders. Add
the same file to `backups\` and `inc\`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <authorization><deny users="*" /></authorization>
    <directoryBrowse enabled="false" />
  </system.webServer>
</configuration>
```

Or, better, configure Request Filtering once at the site level (IIS Manager →
your site → Request Filtering → Hidden Segments) and add `backups`, `inc`,
`sql`, `tools`, `tests`, `specs`, `vendor`, `keys`.

---

## Caddy

Add a matcher to your site block:

```caddyfile
cad.example.org {
    root * /var/www/newui

    @blocked path /backups/* /inc/* /sql/* /tools/* /tests/* /specs/* \
                  /coordination/* /drafts/* /apache/* /vendor/* /keys/* \
                  /.git/*
    respond @blocked 404

    @services path /services/*
    @bridge   path /services/meshtastic/*.py /services/meshcore/*.py
    respond @services 404
    file_server @bridge

    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
}
```

Caddy evaluates directives in a fixed order, not file order; if the bridge
download stops working, raise its `file_server` above the `respond` with an
explicit `handle` block.

---

## Check your own install

Run these three from any machine, replacing the host. **Anything that answers
`200` is a problem.** `403`, `404` or a connection refusal are all fine.

```bash
curl -s -o /dev/null -w 'backups %{http_code}\n' https://your-site/backups/
curl -s -o /dev/null -w 'sql     %{http_code}\n' https://your-site/sql/run_migrations.php
curl -s -o /dev/null -w 'tools   %{http_code}\n' https://your-site/tools/
```

TicketsCAD runs the same three probes against itself and reports the result on
**Settings → Status**, in the "Web exposure" row of the File & Code Health card.
It is checked on every visit to that page, so it will tell you if a server
upgrade or a config change ever re-opens one of these.

---

## Belt and braces: why this is not the only defence

Web-server rules are the outer fence, and every install configures its server
differently, so TicketsCAD does not rely on them alone:

* **Every script under `sql/` and `tools/` refuses to run over HTTP.** The first
  line of each is a check that the script was started from a command line; over
  HTTP it answers `403 CLI only` and stops before touching the database. This
  works on any web server with any configuration, including one where the deny
  rules were never installed.
* **Backups are written above the web root.** From v4.2.3 the default backup
  directory is `../backups`, a sibling of the install directory, so no web
  server can serve them however it is configured — the same approach already
  used for the encryption keys in `../keys`.
* **The Status page probes itself** and says so loudly if any of the three paths
  is still reachable.

See also: [`SECURITY.md`](../SECURITY.md), [`docs/SECURITY-POLICY.md`](SECURITY-POLICY.md),
[`docs/security/advisory-2026-07-30-exposed-directories.md`](security/advisory-2026-07-30-exposed-directories.md).
