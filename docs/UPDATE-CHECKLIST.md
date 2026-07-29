# After Every Update (Self-Hosted Installs)

This checklist exists because two classes of post-update breakage keep
biting self-hosted installs that deploy with `git pull`:

1. **File ownership/permissions.** When you run `git pull` as root (or any
   user other than the web server user), *new* files and directories the
   pull creates are owned by that user. Depending on your umask, the web
   server may not be able to read them. The symptom is brutal and silent:
   a new JS file or API endpoint simply 404s. If the unreadable file is
   something like `assets/js/event-bus.js`, ALL real-time updates die with
   no visible error.

2. **Stale opcache.** PHP's opcache caches compiled code. If
   `opcache.validate_timestamps=0` (common on tuned production servers),
   the running server keeps executing the OLD code after a pull until
   apache2/php-fpm is reloaded — so fixes "don't take effect" even though
   the files on disk are correct.

TicketsCAD NewUI **detects and warns about both — it never auto-fixes**.
If you manage your file permissions your own way, keep doing that; the
health check will tell you if something is actually broken.

## The checklist

Run these after every `git pull`, in order. Commands are **examples to
adapt** — substitute your actual web server user (`www-data` on
Debian/Ubuntu Apache, `apache` on RHEL, your php-fpm pool user, etc.) and
your actual install path.

### 1. Fix ownership and permissions (example — adapt to your policy)

> **Do not `chown -R` the whole install directory.** Earlier versions of this
> checklist said to. It is wrong twice over: it hands `.git` to the web server,
> so your next `git pull` stops with
> `fatal: detected dubious ownership in repository at '/var/www/newui'`
> (git ≥ 2.35.2, CVE-2022-24765); and it is unnecessary, because the web server
> only needs to **read** the program files — ownership has nothing to do with
> that, mode `644`/`755` does.

**Who needs to own what**

| Path | Owner | Why |
|---|---|---|
| the install directory itself | **the user who runs `git`** | whoever owns `.git` is who can `git pull`. Pick that user once and keep it. |
| `uploads/` | web server user | attachments + map overlays (`api/upload.php`) |
| `cache/` | web server user | weather tiles, Zello audio |
| `backups/` | **you**, group = web server user, mode `2770` | written by BOTH `php tools/backup_run.php` on the CLI (as you) and Settings → Backup / the cron entry (as the web user). Give it away entirely and the CLI backup fails with `could not write archive`. |
| `../keys/` — e.g. `/var/www/keys` | web server user, mode `700` | 2FA + RSA field-encryption keys. **One level ABOVE the install directory, on purpose** (`inc/field-encrypt.php`: `FE_KEYS_DIR = NEWUI_ROOT . '/../keys'`) so the private key is not HTTP-reachable. git never touches it, so it is not part of a post-pull fix-up — see INSTALLATION-CHECKLIST.md Section 6. |

```bash
# EXAMPLES ONLY — substitute YOUR web server user: www-data (Debian/Ubuntu),
# apache (RHEL/Rocky/Fedora), _www (macOS), or your php-fpm pool user.
cd /var/www/newui

# The two directories PHP writes to inside the tree:
sudo chown -R www-data:www-data uploads/ cache/

# backups/ is gitignored and does NOT exist on a fresh clone — it is created on
# first use. Create it and share it, so both you and the web server can write:
mkdir -p backups
sudo chown -R "$(id -un)":www-data backups/
sudo chmod 2770 backups/          # setgid: new archives inherit the group

# Program files only need to be READABLE (this needs no chown at all):
sudo find . -path ./.git -prune -o -type d -exec chmod 755 {} \;
sudo find . -path ./.git -prune -o -type f -exec chmod 644 {} \;
```

If your tree is *already* owned by the web server (older installs followed the
whole-tree advice), you have two consistent options — pick one:

```bash
# a) keep the web server as the owner, and run git as it:
sudo -u www-data git -C /var/www/newui pull --ff-only

# b) take the tree back, and give the web server only what it writes:
sudo chown -R "$(id -un)":www-data /var/www/newui
sudo chmod -R g+rX /var/www/newui
sudo chown -R www-data:www-data /var/www/newui/uploads /var/www/newui/cache
```

If you manage permissions your own way (ACLs, a deploy user in the
web group, setgid directories, ...), **keep doing that** — skip this
step. The health check (step 4) will tell you if something is broken.

### 2. Reload the web server (clears opcache)

Always do this after a pull — it is cheap and it is the only reliable way
to make sure the new PHP code is actually what's running:

```bash
sudo systemctl reload apache2
# or, if you serve PHP through php-fpm:
sudo systemctl reload php8.2-fpm
```

A *reload* is graceful (no dropped connections); you do not need a full
restart.

### 3. Apply database migrations

```bash
php sql/run_migrations.php
```

Idempotent — safe to run every time. Admins also get an in-app banner
when migrations are pending.

### 4. Run the health check

```bash
php tools/check-health.php
```

Prints `[OK]` / `[WARN]` / `[CRIT]` lines and, for every problem, the
suggested fix command (echoed, never executed). Exit codes: `0` all ok,
`1` warnings, `2` critical.

**CLI caveat:** on the command line, writability answers reflect the
*CLI* user, not the web server user. The **authoritative** check runs as
the web user:

- **API:** `GET /api/health-check.php` (admin-gated JSON), or
- **UI:** **Settings → System Health** (`status.php#health`) — the
  "File & Code Health" card shows the directories table, any unreadable
  files, the opcache configuration, and the stale-code detector.

The CLI's unreadable-files scan is still valid — it catches root-owned
`0600`/`0700` files left behind by a root `git pull`.

## What the health check looks at

| Check | What it catches | Severity |
|---|---|---|
| Required-writable dirs (`uploads/`, `uploads/overlays/`, `cache/`, `cache/weather/`, `cache/zello-audio/`) | Uploads, map overlays, weather tiles, and Zello voice recordings failing to write | Missing-but-creatable = warn; exists-but-unwritable = **critical** |
| Unreadable files in `assets/js/` and `api/`, plus the 20 most-recently-modified `.php`/`.js` files | New files from a root `git pull` that the web server cannot read (silent 404s) | **critical** |
| opcache `validate_timestamps=0` | Code changes on disk not taking effect until reload | warn |
| `inc/health-check.php`'s compiled build stamp vs the same file on disk | The server executing stale opcache'd code right now | **critical** — reload apache2/php-fpm |
| A `define('NEWUI_VERSION', …)` left in `config.php` | A dead line from before the version moved to the tracked `VERSION` file — the reported version is correct either way | informational |

When any **critical** issue exists, admins see a red banner on every page
linking to `status.php#health`.

## If something is flagged

The tool tells you the suggested command for each finding, for example:

```
sudo chown -R www-data:www-data /var/www/newui/uploads   # adjust 'www-data' to YOUR web server user
sudo systemctl reload apache2   # or: sudo systemctl reload php8.2-fpm
```

Nothing is ever executed for you. Review, adapt, run, then re-check.

## 5. Confirm the version actually moved

Open the user menu (top right) → **About**. The version there is read from the
git-tracked `VERSION` file, so after a successful `git pull` **it changes on its
own** — no config edit needed. If it did not change, the pull did not land or
the web server is still serving stale code (step 2).

> Installs created before 2026-07 have a `define('NEWUI_VERSION', …)` in their
> `config.php`. It is ignored now and the About page is still correct; the health
> check mentions the line so you can delete it. (Before that change the version
> lived *only* in `config.php` — which git never touches — so About showed the
> install-day version forever, and "check About to prove the update worked" was
> advice that could never work.)
