# The RSA private key and the 2FA key were written into a web-served directory on Windows

- **Severity:** High. (See "About the severity" below — the reporter assessed
  this as Low, on the strength of what his own IIS host actually served, and
  that reasoning is set out there too.)
- **Affected:** every version up to and including 4.2.3, on Windows hosts where
  TicketsCAD is installed inside a served document root — `C:\inetpub\wwwroot\…`
  under IIS, `C:\xampp\htdocs\…` under XAMPP. That is the documented and
  ordinary arrangement on Windows.
- **Not affected:** Linux, macOS and Docker installs. There the directory is a
  sibling of the install directory and no web server publishes it, which is
  exactly what the old code assumed everywhere.
- **Patched in:** 4.2.4
- **Reported by:** Ron Jones ([@rjonesbsink](https://github.com/rjonesbsink))
- **Related:** GHSA-rrp6-pqhj-w5wj and the 4.2.3 Windows backup regression — the
  same root cause, in a different directory, for the third time. If you were
  affected by that one, read "The two together" below.

## Summary

TicketsCAD keeps three files outside its own directory:

| File | What it does |
|---|---|
| `private.pem` | RSA private key; decrypts the form fields the browser encrypts when the site is served over plain HTTP. In 4.2.x exactly one field carries that marking: **the login password** |
| `public.pem` | its public half |
| `tfa.key` | AES-256 key; decrypts every enrolled TOTP secret and every set of backup codes |

Until 4.2.4 the location was computed as *the parent of the application
directory*, on every platform:

```php
define('FE_KEYS_DIR', NEWUI_ROOT . '/../keys');
```

with the intent stated plainly in `docs/UPDATE-CHECKLIST.md`: *"one level ABOVE
the install directory, on purpose … so the private key is not HTTP-reachable."*

On Linux the parent is not published — `/var/www/newui` gives `/var/www`. On
Windows it is. IIS sites are subdirectories of a **served** `C:\inetpub\wwwroot`,
so `C:\inetpub\wwwroot\TicketsV4\..\keys` is `C:\inetpub\wwwroot\keys`, inside
Default Web Site, bound to `*:80`. XAMPP has the identical shape:
`C:\xampp\htdocs\newui` gives `C:\xampp\htdocs`, the DocumentRoot.

The directory was confirmed served, from the browser, with no credentials:

```
GET http://localhost/keys/_probe.txt    ->  200      "control-file"
GET http://localhost/keys/private.pem   ->  404.3    (MIME type restriction)
```

It carried no `web.config` and no `.htaccess`.

**Installing or upgrading is what created the exposure.** No misconfiguration
was required and no instruction had to be followed.

## The 404 on the private key is not a control

This is the part worth reading slowly, because it is what separates "the key was
not downloadable on that host on that day" from "the key was safe".

IIS serves static files through an allow-list of file-name extensions. There is
no MIME mapping for `.pem` or `.key` in the default set, so those two requests
are refused with **404.3** — the same answer IIS gives for a file type it does
not recognise anywhere on the server. Nothing about TicketsCAD, the directory,
or its permissions produced that refusal.

Consequently:

- **Any mapped extension in that directory is served.** The `.txt` above is the
  proof, and it was served from the same folder as the key.
- **One `staticContent` entry removes the refusal.** Adding a `.pem` mapping is
  an ordinary administrative act — publishing a certificate, an ACME workflow —
  and the widely-copied "make IIS serve my file type" fix is a wildcard
  `mimeMap` for `.*`, which opens every extension in one line, server-wide.
- **Apache has no such allow-list at all.** On a XAMPP host with the same layout
  — a supported and documented Windows arrangement — `GET /keys/private.pem`
  returns the private key as plain text, and `GET /keys/` returns a directory
  listing of all three files. There is no incidental refusal to rely on there,
  and no `.htaccess` was present.

A defence the product neither owns, nor checks, nor documents, and which a
routine configuration change removes, is not a defence. It is a coincidence that
had been holding.

## It also broke 2FA, and the obvious remedy was the dangerous one

On an affected IIS install, **Settings → Two-Factor Auth → Migrate to
Dedicated Key** failed with:

```
Migration failed: failed to generate key file. Check directory permissions.
```

The application pool identity has only `ReadAndExecute` on `C:\inetpub\wwwroot`,
so the write failed. The message was accurate, and it pointed the administrator
directly at granting write access to a directory published on port 80. The
permission failure was the only thing that had kept `tfa.key` out of it. Nothing
in the interface or the error named the path.

## Impact

An unauthenticated request, from anywhere that can reach the host, for long-lived
private key material:

- **`private.pem`** decrypts the field encryption TicketsCAD applies when a site
  is served over plain HTTP. Checked rather than assumed: in 4.2.x the only field
  marked `data-sensitive` anywhere in the application is the **login password**
  on `login.php`, and `login.php` is the only place the server decrypts one. So
  the control this key protects is "the password does not cross a non-TLS network
  in cleartext" — which describes many internal Windows installs. With the key,
  anyone who can observe that traffic recovers the password.
- **`tfa.key`** decrypts `user_tfa.secret_encrypted` and the stored backup codes.
  On its own it discloses nothing: those values live in the database, which this
  issue does not expose. Its whole purpose was to be *separate* from the database
  credentials, so that a database-only compromise could not yield TOTP secrets.
  Publishing it on port 80 undoes that separation in advance, permanently, for
  any future database disclosure.

No integrity or availability impact: this is a read of files the server was
willing to hand out.

### The two together

Every Windows 4.2.3 install had **both** this and the backup regression
(`advisory-2026-08-03-windows-backup-regression.md`) — the archives and the keys
landed in the same published directory tree, by the same reasoning, at the same
time. `tfa.key` plus a downloadable database archive is every enrolled
authenticator and every backup code in plaintext. If you were running 4.2.3 on
Windows, treat that combination as the case to plan for.

## About the severity

The reporter rated this **Low**, and the reasoning behind that is sound: he
measured his own stock IIS 10 host, and on it none of the three key files could
actually be retrieved. A finding should be reported at what the reporter can
demonstrate, and he was careful not to claim more.

This project rates it **High**, for three reasons.

1. **A product advisory has to cover the supported configurations, not one
   host.** On Windows + Apache/XAMPP — documented, and already named as affected
   in the backup advisory — this is an unauthenticated `GET` that returns an RSA
   private key, with a directory listing to find it. `AV:N/AC:L/PR:N/UI:N/S:U/
   C:H/I:N/A:N`.
2. **The only thing stopping it on IIS is outside the product.** Scoring an
   incidental MIME allow-list as a mitigation credits the fix to something that
   was never designed to be one and that a single `mimeMap` line removes. On the
   same host, in the same folder, a `.txt` was served.
3. **The asset is not a snapshot, and rotating it is not free.** A leaked backup
   is data as of a moment. A leaked key decrypts what has not happened yet, and
   `tfa.key` cannot be replaced without re-encrypting every enrolment or
   re-enrolling every user.

Against that: on a stock IIS install as shipped, exploitation does require a
server configuration change, and `tfa.key` alone yields nothing without the
database. An assessment of **Moderate** for the IIS-only case is defensible, and
if you run only IIS with a default `staticContent` section, that is a fair way to
read your own risk. The advisory is published at High because the action it asks
of the reader — treat the key as disclosed if the folder was reachable — is the
same either way.

## Am I affected?

You are affected if **all** of these are true:

1. You are running **4.2.3 or earlier**, and
2. Your server is **Windows** — IIS or XAMPP — and
3. TicketsCAD is installed inside a published directory, which is the ordinary
   arrangement (`C:\inetpub\wwwroot\…`, `C:\xampp\htdocs\…`).

### Check it

```
http://localhost/keys/
http://localhost/keys/private.pem
http://localhost/keys/_probe.txt          (create the file first, then delete it)
```

Try every site and port your server publishes, not only the one TicketsCAD
answers on. On IIS, `appcmd list site` (from `C:\Windows\System32\inetsrv`)
lists them with their physical paths.

**A 404 on `private.pem` does not clear you.** Put a small text file in that
folder and request it — if the `.txt` comes back, the folder is published and the
key is one MIME entry away. That is exactly the experiment in the report.

After upgrading, **Settings → System Health** answers this for you: there is an
"Encryption key location" row that grades the directory, and TicketsCAD writes a
random token file into it and asks this host for the token back on the default
ports. A `200` whose body contains the token is proof.

## Fix it now

Upgrade to **4.2.4**. On Windows the default becomes
`%ProgramData%\TicketsCAD\keys`, which is not a site root under IIS, XAMPP or
nginx. The POSIX default is unchanged, because it was correct there.

**Nothing is moved for you.** If the old directory still holds `private.pem`,
`public.pem` or `tfa.key`, 4.2.4 keeps reading them from there — an upgrade must
not be able to break field encryption or lock every 2FA user out of the system —
and the Status page reports the directory, names it, and prints the exact
commands. Move them yourself, when nobody is signing in:

```powershell
New-Item -ItemType Directory -Force -Path 'C:\ProgramData\TicketsCAD\keys'
icacls 'C:\ProgramData\TicketsCAD\keys' /grant 'IIS AppPool\<YourPool>:(OI)(CI)M'
Copy-Item -Path 'C:\inetpub\wwwroot\keys\*.pem','C:\inetpub\wwwroot\keys\tfa.key' `
          -Destination 'C:\ProgramData\TicketsCAD\keys\'
# sign in with 2FA to confirm it still works, THEN:
Remove-Item -Path 'C:\inetpub\wwwroot\keys\*.pem','C:\inetpub\wwwroot\keys\tfa.key'
```

Copy, verify, then delete — in that order. TicketsCAD switches to the new
directory by itself as soon as the old one no longer holds key files; no
configuration change is needed. To keep them somewhere else instead, add
`define('FE_KEYS_DIR', 'C:\\your\\path');` to `config.php` — that override is
honoured as of 4.2.4, and was silently ignored before it.

## If it was reachable

Treat both keys as known to whoever found the folder.

**`private.pem` — rotate it. This is safe and takes seconds.** Nothing is stored
encrypted under this key; it protects form fields in transit only. Delete
`private.pem` and `public.pem` from the keys directory and load any page —
TicketsCAD generates a new pair and serves the new public key to browsers
immediately. (The old pair is archived alongside; delete that too.)

**`tfa.key` — decide deliberately.** No TOTP secret was disclosed by this issue
on its own, because the secrets are in the database. The exposure is that a
*future* database compromise would now come with the key already in someone's
hands. There is no one-click rotation for this key in 4.2.4; the two honest
options are:

- **Re-enrol.** Reset two-factor for each user (Settings → User Accounts) and have them
  scan a fresh code. This invalidates every secret encrypted under the leaked
  key. It is disruptive and it is the complete fix.
- **Accept and monitor**, if your database has never been exposed and is not
  reachable from the network. Record the decision; the leaked key stays valid
  against any old backup archive an attacker may already hold.

If you were running 4.2.3 on Windows, your backups were published in the same
place at the same time — see the backup advisory, and read those two facts
together before choosing.

Search your logs for `200` responses to any `/keys/…` request. Check the logs of
**every site** on the host, not just TicketsCAD's; on IIS these are per-site
under `C:\inetpub\logs\LogFiles\W3SVC<id>\`. Search-engine crawlers count.

## What changed in 4.2.4

1. **The Windows default is `%ProgramData%\TicketsCAD\keys`** — the same base the
   backup fix chose, so there is one place to look. The POSIX default is
   unchanged. The platform is a parameter of the function that computes it, so
   both answers are testable from one machine; a test that can only see its own
   platform is how this shipped twice.
2. **Existing keys are found where they are.** The historical location is
   checked first, and if it holds any of the three files that is the directory
   this install keeps using — in place, with no operator action, permanently if
   need be. The new default governs where keys are *created*.
3. **`define('FE_KEYS_DIR', …)` in `config.php` works.** The application's own
   `define()` was unguarded, so the documented escape hatch did not exist: PHP
   cannot redefine a constant and the application always got there first.
4. **The Status page reports it.** An "Encryption key location" row grades the
   directory from local evidence, proves reachability with a random-token canary
   on the default ports, lists the key files it found there, prints the move
   instructions for your platform — and states what it could *not* see, on a
   passing result too.
5. **Deny rules are written beside the keys**, wherever they are — a `web.config`
   using IIS Request Filtering and a `.htaccess` for Apache, the shape
   standardised across this project in cb2db27. A mitigation, not a reason to
   leave a private key in a published folder. The text-to-speech API-key
   directory gets the same treatment.
6. **The 2FA error names the directory**, and says so when it is one nothing
   should be written to, instead of asking for the write access that would have
   completed the exposure.
7. **The three helpers that answer "is this directory published, and can we
   fence it?" are now shared** (`inc/served-dir.php`). This assumption has now
   been implemented independently three times and been wrong in the same way
   three times; there is one copy of it to be right.

## Credit

Found and reported by Ron Jones
([@rjonesbsink](https://github.com/rjonesbsink)), who — having already found the
same mistake in the backup directory — went and looked at the directory next to
it, established that it was published by putting a control file in it rather
than by trusting a 404 on the key itself, and reported it privately with the
evidence and a correct diagnosis of why the 404 was not protection.

His closing observation was that this was the third appearance of one
assumption. That is the change in item 7, and it is the more valuable half of
the report.

To report a security concern in TicketsCAD, see `SECURITY.md`. Private
vulnerability reporting is enabled on this repository. Please report privately
rather than in a public issue, and allow time for a fix before disclosing.
