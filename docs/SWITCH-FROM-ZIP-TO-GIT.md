# I installed from a ZIP — how do I switch to git?

If you installed TicketsCAD by downloading a ZIP file, and you try to update
with `git pull`, you get this:

```
fatal: not a git repository (or any parent up to mount point /)
```

**That is expected, and nothing is wrong with your install.** A ZIP download is
just the files. It does not include the hidden `.git` folder that records where
the code came from, so git has nothing to pull *from*. The update videos assume
you cloned with git, which is why "skip ahead to the update" does not work for
you. Sorry — that is our documentation's fault, not yours.

The good news: you do **not** need to reinstall, and you do **not** lose
anything. You can adopt your existing folder as a git checkout in about a
minute, and from then on updating really is `git pull`.

> **Windows users:** this page is written for you as well as for Linux/macOS.
> Wherever the two differ there is a **Windows** box. Read those and ignore the
> Linux/macOS lines — you do not need `sudo`, and you must not use Command
> Prompt or PowerShell.

---

## What survives (all of it)

Everything that is *yours* is deliberately excluded from the repository, so git
never touches it:

| Your stuff | Kept? |
|---|---|
| `config.php` — your database password and settings | kept |
| `keys/` — encryption keys, 2FA key | kept |
| `uploads/` — attachments, photos, map overlays | kept |
| `cache/` | kept |
| Any file you created that isn't part of TicketsCAD | kept |
| **Your database** — every incident, unit, person, note | **never touched at all** |

**The one exception, and it matters:** if you have *edited a TicketsCAD program
file* — most commonly `.htaccess`, or `docker-compose.yml` on a Docker install —
that edit **will be reverted** to the official version. Those files are part of
the project, so git manages them. The database backup does **not** contain
program files, so it cannot bring your edit back. Step 1 below takes a copy of
the whole folder, which does.

---

## Step 0 — open the right terminal, in the right folder

Everything on this page is typed into a terminal that is sitting **inside your
TicketsCAD folder** — the folder that contains `index.php`.

> ### Windows
>
> **Use Git Bash. Not Command Prompt, not PowerShell.** Every command here
> (`ls`, `cp`, `~`) is a Unix command; `cmd` does not have them and will fail on
> the first line.
>
> 1. Open File Explorer and find your TicketsCAD folder. With XAMPP it is
>    usually **`C:\xampp\htdocs\newui`** (or whatever folder name you unzipped
>    into, under `htdocs`).
> 2. **Right-click inside that folder** (on empty space, not on a file) and
>    choose **"Open Git Bash here"**.
>    On Windows 11 you may need to click **"Show more options"** first to see it.
>    If the entry isn't there at all, git isn't installed — see the Windows video
>    linked below.
> 3. Git Bash shows Windows paths in Unix form: `C:\xampp\htdocs\newui` appears
>    as `/c/xampp/htdocs/newui`. That is normal and correct.
> 4. `~` means `C:\Users\<your name>`.
>
> You will **not** use `sudo` anywhere. There is no `sudo` on Windows and you do
> not need it.

> ### Linux / macOS
>
> Open a terminal and `cd` into the folder, e.g.
> `cd /var/www/newui`. If TicketsCAD is on another machine, SSH in first.

**Confirm you are in the right place** — both of these must list a file:

```bash
ls index.php
ls sql/run_migrations.php
```

> ### ⚠ Stop here if the second one is missing
>
> `index.php` alone is **not** enough. TicketsCAD v3.44 — the older interface —
> also has an `index.php` and a `config.php`, so it passes that check too. But
> v3 is a **different application in a different repository**, and the commands
> on this page would replace its program files with v4's. That would break it.
>
> `sql/run_migrations.php` exists only in **v4 (NewUI)**. If that second command
> says *No such file or directory*:
>
> - You are either in the wrong folder, **or** you are running v3.44.
> - Check which you have: open TicketsCAD, click your name at the top right and
>   choose **About** (Help and About are siblings in that menu, not nested), or
>   look for a folder called `api` (v4 has one; v3 does not).
> - **If you are on v3.44, stop.** This page does not apply to you. Upgrading
>   v3 to v4 is a separate, larger job — open an issue and we will point you at
>   the right steps.

If `ls index.php` says *No such file or directory*, you are simply in the wrong
folder. Do not continue until both commands list a file.

**One more thing before you start:** do this when your group is **not actively
dispatching**. The site is briefly inconsistent while the program files are
being replaced, and you will restart the web server at the end.

> On a **Docker** install both checks above still work — `index.php` and
> `sql/run_migrations.php` are part of the source you check out, not something
> the container generates. You will also see a `docker-compose.yml` here; that
> is the file that tells you this is a Docker install:
> ```bash
> ls docker-compose.yml
> ```
> Note that `config.php` is generated *inside* the container on Docker installs,
> so it is not on your host — its absence does not mean you are in the wrong
> folder.

### Two quick checks

```bash
git --version
php --version
```

If either says **command not found**:

- **git** — not installed. The two walkthrough videos cover installing it:
  - Windows (Git Bash): <https://youtu.be/uZl3teJMMHM>
  - Linux & macOS (Terminal): <https://youtu.be/Zczb4ypmDc8>
- **php** — on **Windows with XAMPP this is normal**; PHP is installed but not on
  your PATH. Use the full path instead, everywhere this page says `php`:
  ```bash
  /c/xampp/php/php.exe --version
  ```
  (If XAMPP is somewhere else, adjust the path.)

  Or tell Git Bash where PHP lives, **once**, so plain `php` works on this page
  and every month afterwards. This is the line the Windows video shows and tells
  you to paste rather than type — copy it from here:
  ```bash
  echo "alias php='/c/xampp/php/php.exe'" >> ~/.bashrc
  ```
  Then **close Git Bash and open it again.** Two things can happen on that first
  reopen, and neither is a fault:
  - A red `WARNING: Found ~/.bashrc but no ~/.bash_profile…  This looks like an
    incorrect setup.` — that is Git Bash tidying up after itself, and it is the
    sign the line took effect.
  - `php: command not found` again — the path is wrong for your machine. Run
    `ls /c/xampp` and use what is actually there; a versioned install keeps PHP
    at `/c/xampp/8.2.4/php/php.exe`, and an install on another drive starts with
    a different letter. Re-run the `echo` line with that path and reopen. (The
    old line stays in the file, harmlessly — the newer one wins.)
  - If the path was right and it *still* fails, your Git Bash already has its own
    startup file (`~/.bash_profile`, `~/.bash_login` or `~/.profile`), so it
    never reads `~/.bashrc` at all. Put the same line in `~/.bash_profile`
    instead and reopen.

  On a **Docker** install there is usually no PHP on the host at all — that is
  expected; see the Docker notes.

**This procedure needs shell access.** If your web host only gives you a file
manager in a browser and no SSH, none of this will work — open an issue and
we'll help you work out the options.

---

## Step 1 — back up (two things, both quick)

### 1a. The database

```bash
php tools/backup_run.php --force
```

**The `--force` matters.** Without it, this command only backs up if one is
*due* on the schedule — otherwise it prints `No backup due yet` and exits
**successfully**, having backed up nothing. That looks exactly like it worked.

You want to see:

```
[16:12:03] Starting backup…
[16:12:19] OK — …/backups/ticketscad-20260726-161203.zip
[16:12:19] verified: readable archive containing schema
```

If instead you get **`Could not open input file: tools/backup_run.php`**, your
install predates v4.1 and that tool doesn't exist yet — which is very likely if
you're reading this page. Take the backup from inside TicketsCAD instead:
**Settings → Backup / Maintenance → Download Full Backup** (there is also a
**Save to Server** button, which writes it into the `backups/` folder instead of
downloading it). Either way, get a backup before continuing.

> **Docker installs** have no PHP on the host, so the command above will not run
> there either. Use the web UI as described, or go through the container:
> ```bash
> docker compose exec app php tools/backup_run.php --force
> ```
> `app` is the service name in the `docker-compose.yml` this project ships. If
> you wrote your own compose file, use whatever you named the PHP service —
> `docker compose ps` lists them.
>
> **Check that your compose file mounts a volume at `/var/www/html/backups`.**
> `docker compose up -d --build` — the Docker update command — *replaces* the
> container, and anything not on a volume goes with it. The shipped
> `docker-compose.yml` has mounted `app_backups` there since 2026-07; if yours
> is older, add it (or bind-mount `./backups`) before you rely on this backup.
> Either way, copy the archive off the host afterwards:
> ```bash
> docker compose cp app:/var/www/html/backups ./ticketscad-backups
> ```
> See [docs/DOCKER.md](DOCKER.md) §4 for the one-time migration.

### 1b. The folder

This is the copy that protects any edits you made to program files, which the
database backup does not cover:

**Copy it somewhere OUTSIDE your web root.** Do not put it beside your TicketsCAD
folder: on XAMPP that is `htdocs`, on Linux usually `/var/www` — either way the
copy would be served to the internet, and it contains a `backups/` folder with a
full database dump in it.

```bash
cp -a . ~/ticketscad-before-git
```

That copies the folder you are standing in, so there is no folder name to get
wrong. Check it worked before going on — this must list a file:

```bash
ls ~/ticketscad-before-git/index.php
```

If that errors, **stop** and sort it out. This copy is the only thing that can
bring back an edited program file.

> ### Windows
>
> `~` is your user folder (`C:\Users\<your name>`), so that command puts the copy
> safely outside `htdocs`. You can equally do it in File Explorer: copy the
> TicketsCAD folder and paste it into your Documents folder. **Not** into
> `htdocs`.

---

## Step 2 — the conversion (four commands)

```bash
git init
git remote add origin https://github.com/openises/TicketsCAD.git
git fetch origin
git checkout -f -B main origin/main
```

What each does:

- **`git init`** — starts tracking this folder with git; creates the `.git`
  folder the ZIP didn't have.
- **`git remote add origin …`** — tells git where TicketsCAD lives.
- **`git fetch origin`** — downloads the project history. Your files are not
  changed by this.
- **`git checkout -f -B main origin/main`** — switches your folder to the
  current released code. The `-f` is required because your ZIP's files aren't
  known to git yet; it replaces the program files with the official ones (and,
  as noted above, reverts any edits you made to them).

**`git init` may print a long hint about `master` and `init.defaultBranch`.**
Ignore it — git is being chatty about naming, not reporting an error. (If your
git is already configured with `init.defaultBranch=main`, you won't see the hint
at all. Also fine.)

### How to tell it worked

The last command prints one of these, depending on your git version and config:

```
Switched to a new branch 'main'
```
or
```
Reset branch 'main'
```

**Both mean success.** Rather than matching that text, confirm it properly:

```bash
git status
```

You want `On branch main` and `Your branch is up to date with 'origin/main'`.

You may **also** see an `Untracked files:` list — leftovers from your old ZIP
that the project no longer ships. They are harmless. The two lines above are the
ones that matter.

---

## Step 3 — finish the update

```bash
php sql/run_migrations.php
php tools/check-schema.php
```

`check-schema.php` should end with **`Schema OK.`** If it reports missing
columns or tables, run `php tools/check-schema.php --repair`. If it *still*
reports something missing after that, stop and open an issue rather than
guessing.

> **Docker installs: skip both of those.** They need PHP and a database
> connection on the host, which a Docker install doesn't have — and they're
> unnecessary, because the container applies migrations itself on startup. Do
> this instead:
> ```bash
> docker compose up -d --build
> ```
> The **`--build` is required**: the code is baked into the image when it's
> built, so a plain `git pull` does not update a container that's already
> running. That single command replaces Step 3 *and* the reload below.

### Then reload the web server — do not skip this

The checkout replaced essentially every program file at once, and PHP keeps
serving the **old** compiled code until the web server is reloaded. Skip this
and the site behaves as though nothing changed — which reads exactly like "the
update didn't work".

> ### Windows (XAMPP)
>
> Open the **XAMPP Control Panel**, click **Stop** next to Apache, wait for it
> to go grey, then click **Start**. That's it — no commands, no `sudo`.

> ### Linux / macOS
>
> ```bash
> sudo systemctl reload apache2      # or: sudo systemctl reload php8.2-fpm
> ```
>
> If you ran the git commands as a *different user* than the web server runs as
> (`root` vs `www-data`, say), new files can be unreadable to the web server and
> simply 404 with no error. Fix that by making them **readable** — not by giving
> the whole folder away:
>
> ```bash
> sudo find . -path ./.git -prune -o -type d -exec chmod 755 {} \;
> sudo find . -path ./.git -prune -o -type f -exec chmod 644 {} \;
> ```
>
> **Do NOT run `sudo chown -R www-data:www-data .`** — the dot takes `.git` with
> it, and your next `git pull` stops with
> `fatal: detected dubious ownership in repository at '/var/www/newui'`. Whoever
> owns `.git` is who runs `git pull` from now on; keep it that way.
>
> The web server does need to **own** the two directories it writes to, plus a
> share of `backups/`:
>
> ```bash
> # www-data on Debian/Ubuntu; apache on RHEL/Rocky/Fedora; _www on macOS
> sudo chown -R www-data:www-data uploads/ cache/
>
> mkdir -p backups                       # gitignored — absent on a fresh clone
> sudo chown -R "$(id -un)":www-data backups/
> sudo chmod 2770 backups/               # you AND the web server can write
> ```
>
> `backups/` is deliberately *not* handed to the web server outright: you run
> `php tools/backup_run.php` as yourself, and giving the folder away is exactly
> what makes that print `FAILED — could not write archive`.
>
> The encryption keys are **not** in this list. They live one level *above* the
> install folder (`/var/www/keys` for an app in `/var/www/newui`), so git never
> touches them — see [docs/INSTALLATION-CHECKLIST.md](INSTALLATION-CHECKLIST.md)
> Section 6.
>
> **If you already see `fatal: detected dubious ownership`** — your folder was
> chowned to the web server by an earlier install guide. Either run git as that
> user (`sudo -u www-data git pull --ff-only`) or take the tree back:
> `sudo chown -R "$(id -un)":www-data /var/www/newui`.
>
> **None of this ownership section applies on Windows.**

Both of these, and why they bite, are covered in
[docs/UPDATE-CHECKLIST.md](UPDATE-CHECKLIST.md) — worth one read.

### Finally, look at it

Open TicketsCAD in your browser and log in. Your incidents, units, people and
settings are all still there — none of that lives in the files that were
replaced. This is the step that tells you it actually worked.

To confirm *which* version you are now running, click your name at the top right
and choose **About**. That number comes from the git-tracked `VERSION` file, so
it moves on its own when you pull — nothing to edit.

> Installs created before 2026-07 also have a `define('NEWUI_VERSION', …)` line
> in their `config.php`. It is ignored now (the About page is still right), and
> `php tools/check-health.php` will point at the dead line so you can delete it.

---

## From now on

Updating is a short routine:

```bash
git pull
php sql/run_migrations.php
```

…then reload the web server (XAMPP Control Panel on Windows; `systemctl reload`
on Linux/macOS).

**Docker:** `git pull` then `docker compose up -d --build` — nothing else.

---

## If something looks wrong afterwards

**"Your local changes would be overwritten"** on a later `git pull` — you (or an
editor) modified a program file. If you did not intend to change it:

```bash
git checkout -- <the file it named>
git pull
```

**The site shows an error after updating** — the code is newer than the
database. Run `php sql/run_migrations.php`, then `php tools/check-schema.php`,
then reload the web server.

**The site looks unchanged** — you almost certainly skipped the web-server
reload. Do that first before anything else.

**Something you customised came back to default** — that's the tracked-file
revert described at the top. Your copy is in the folder you made in Step 1b;
copy the file back, then reload.

**You want to undo the database side** — you have the backup from Step 1a:

```bash
php tools/restore.php --list
php tools/restore.php --file backups/ticketscad-YYYYMMDD-HHMMSS.zip --yes
```

The filename goes after `--file`; a bare path on its own is ignored. Without
`--yes` it only reports what it *would* do. It also takes a safety copy of the
current database before writing, so restoring the wrong file is itself
undoable.

**Still stuck?** Open an issue with what you typed and what came back:
<https://github.com/openises/TicketsCAD/issues>

---

## One security note

Because the app folder is usually also your web root, `git init` creates a
`.git/` directory that your web server may serve — meaning
`https://your-site/.git/config` becomes readable. The source is public so this
leaks no passwords, but `.git/` is enough to reconstruct the whole tree and it
advertises exactly what you're running.

**TicketsCAD now ships the rule that blocks this**, in the `.htaccess` that
comes with the project. The `git checkout` you ran in Step 2 installed it, so
you are already covered — there is nothing to add.

To confirm, open this in a browser:

```
https://your-site/.git/config
```

You want **404 Not Found** or **403 Forbidden**. If a file comes back instead,
your web server is ignoring `.htaccess` (`AllowOverride None`, or nginx, which
does not read `.htaccess` at all) — block `.git` in the server config instead,
or add this to an `.htaccess` the server does honour:

```apache
RedirectMatch 404 (^|/)\.git(/|$)
```

> Earlier versions of this guide told you to add that line by hand. Don't, unless
> the check above actually fails: `.htaccess` is a file the project ships, so a
> hand-added line becomes a local edit that every future `git pull` has to fight
> over — the exact friction shipping the rule was meant to remove.

---

## Why bother

TicketsCAD is developed in the open, and fixes land continuously — often the
same day somebody reports them. With git you get them with `git pull`, instead
of waiting for a numbered release and re-downloading a ZIP over the top of your
install. That second approach is also how people accidentally overwrite their
own `config.php`.
