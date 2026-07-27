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
minute, and from then on updating really is just `git pull`.

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
| **Your database** — every incident, unit, person, note | **never touched at all** |

Only the program files get replaced, which is the whole point of updating.

---

## Before you start

Take a backup. **Include `--force`** — without it this command only backs up if
one is *due* on the schedule, and if it isn't, it prints "No backup due yet" and
exits successfully. That looks like it worked:

```bash
php tools/backup_run.php --force
```

You want to see `Starting backup…` and then `OK — …/backups/ticketscad-….zip`
followed by `verified: readable archive containing schema`. If you see anything
else, stop and sort that out before continuing.

If your install predates v4.1 the file won't exist (`Could not open input file`).
Take the backup from inside TicketsCAD instead: **Settings → Backup → Back up
now**. Either way, get a backup before you continue.

Also copy your config somewhere outside the folder, purely as a belt-and-braces
measure:

```bash
cp config.php ~/ticketscad-config-backup.php
```

### Two checks before you start

```bash
git --version
php --version
```

If either says **command not found**, that program isn't installed or isn't on
your PATH. On Windows with XAMPP, `php` usually isn't on the PATH — it lives at
`C:/xampp/php/php.exe`, and you can type that full path wherever this page says
`php`. For git, the two walkthrough videos cover installing it:

- Windows (Git Bash): <https://youtu.be/uZl3teJMMHM>
- Linux & macOS (Terminal): <https://youtu.be/Zczb4ypmDc8>

**On Windows, use Git Bash** — right-click inside your TicketsCAD folder and
choose "Open Git Bash here". Not Command Prompt: the commands on this page
(`ls`, `cp`, `~/`) are written for a bash shell and don't exist in cmd.

**This whole procedure needs shell access** — SSH, or a terminal on the machine
itself. If your web host only gives you a file manager in a browser, none of
this will work; open an issue and we'll help you work out the options.

---

## The conversion (four commands)

Open a terminal **in your TicketsCAD folder** — the one that contains
`config.php` and `index.php`. Check you are in the right place:

```bash
ls config.php index.php
```

If that lists both files, you are in the right folder. Now:

```bash
git init
git remote add origin https://github.com/openises/TicketsCAD.git
git fetch origin
git checkout -f -B main origin/main
```

What each line does:

- **`git init`** — start tracking this folder with git. Creates the `.git` folder
  that was missing.
- **`git remote add origin …`** — tell git where TicketsCAD lives.
- **`git fetch origin`** — download the project's history. This does not change
  any of your files yet.
- **`git checkout -f -B main origin/main`** — switch your folder to the current
  released code. The `-f` is needed because your ZIP files are not yet known to
  git; it replaces those program files with the official versions.

**`git init` may print a long hint about `master` and `init.defaultBranch`.**
Ignore it — it is git being chatty about naming, not an error. The fourth
command sets the branch correctly regardless.

`git fetch` then prints a list of branches and tags it downloaded. That is
normal and means it worked.

What you are looking for at the end is:

```
Switched to a new branch 'main'
branch 'main' set up to track 'origin/main'.
```

---

## Finish the update

The code is new; now bring the database in line with it:

```bash
php sql/run_migrations.php
php tools/check-schema.php
```

`check-schema.php` should end with **"Schema OK."** If it reports missing
columns or tables, run `php tools/check-schema.php --repair`. If it *still*
reports something missing after that, stop and open an issue rather than
guessing.

### Then reload the web server — do not skip this

The checkout above replaced essentially every program file at once, and PHP
keeps serving the **old** compiled code until the web server is reloaded. Skip
this and the site behaves as though nothing changed, which reads exactly like
"the update didn't work":

```bash
sudo systemctl reload apache2      # or: sudo systemctl reload php8.2-fpm
```

On Windows with XAMPP, stop and start Apache from the XAMPP Control Panel.

If you ran the git commands as a different user than the web server runs as
(`root` vs `www-data`, say), also fix ownership — otherwise new files can be
unreadable to the web server and simply 404 with no error:

```bash
sudo chown -R www-data:www-data .
```

Both of these, and why they bite, are covered in
[docs/UPDATE-CHECKLIST.md](UPDATE-CHECKLIST.md) — worth one read.

**Docker installs** replace the reload with a rebuild, because the code is baked
into the image when it is built:

```bash
docker compose up -d --build
```

Note that on Docker the `php` commands above run **inside** the container —
prefix each with `docker compose exec web` (substitute your service name).

### Finally, look at it

Open TicketsCAD in your browser and log in. Your incidents, units, people and
settings are all still there — none of that lives in the files that were
replaced. This is the step that tells you it actually worked.

---

## From now on

Updating is a short routine:

```bash
git pull
php sql/run_migrations.php
sudo systemctl reload apache2      # or your php-fpm service
```

(Docker: `git pull` then `docker compose up -d --build`.)

You can confirm git's side worked:

```bash
git status
```

It should say `On branch main` and `Your branch is up to date with 'origin/main'`.
You may also see an **"Untracked files"** list — leftovers from your old ZIP that
the project no longer ships. They are harmless; the two lines that matter are the
branch line and the up-to-date line.

---

## If something looks wrong afterwards

**"Your local changes would be overwritten"** on a later `git pull` — you (or an
editor) modified a program file. If you did not intend to change it:

```bash
git checkout -- <the file it named>
git pull
```

**The site shows an error after updating** — the code is newer than the
database. Run `php sql/run_migrations.php`, then `php tools/check-schema.php`.

**You want to go back** — you have a backup from the first step. Find its name,
then restore it (the filename goes after `--file`; a bare path is ignored):

```bash
php tools/restore.php --list
php tools/restore.php --file backups/ticketscad-YYYYMMDD-HHMMSS.zip --yes
```

Without `--yes` it only tells you what it *would* do and changes nothing. It also
takes a safety copy of the current database before it writes, so restoring the
wrong file is itself undoable.

**Still stuck?** Open an issue with what you typed and what came back:
<https://github.com/openises/TicketsCAD/issues>

---

## Why bother

TicketsCAD is developed in the open, and fixes land continuously — often the
same day somebody reports them. With git you get them with `git pull`, instead
of waiting for a numbered release and re-downloading a ZIP over the top of your
install. That second approach is also how people accidentally overwrite their
own `config.php`.
