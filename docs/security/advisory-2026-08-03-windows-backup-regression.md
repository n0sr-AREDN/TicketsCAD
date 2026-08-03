# Database backups were moved into a web-served directory on Windows by the 4.2.3 fix

- **Severity:** Critical
- **Affected:** **4.2.3 only**, on Windows hosts (IIS and XAMPP). Also any
  platform where an operator followed 4.2.3's remediation text into a directory
  their web server publishes.
- **Not affected:** Linux, Docker and macOS installs running 4.2.3 that did not
  hand-move their backups. For those, 4.2.3 fixed the problem it claimed to fix.
- **Patched in:** 4.2.4
- **Reported by:** Ron Jones ([@rjonesbsink](https://github.com/rjonesbsink))
- **Related:** GHSA-rrp6-pqhj-w5wj, whose fix this is a regression in.

## Summary

GHSA-rrp6-pqhj-w5wj described database backups being served over HTTP, and
4.2.3 fixed it by moving them "above the web root".

That relocation was computed as *the parent of the application directory*. On a
Linux layout the parent is not published — `/var/www/newui` gives `/var/www`. On
a standard Windows layout it is: `C:\inetpub\wwwroot\TicketsV4` gives
`C:\inetpub\wwwroot`, which is the physical path of **Default Web Site, bound to
port 80**. XAMPP behaves the same way — `C:\xampp\htdocs\newui` gives
`C:\xampp\htdocs`, the DocumentRoot.

So on those hosts the upgrade moved every database archive out of one published
directory and into another one, published by a different site, on a different
port, with no deny rule of any kind.

**Upgrading is what caused the exposure.** No misconfiguration was required and
no instruction had to be followed.

## Why nothing reported it

The Settings → Status exposure check probes only the URL TicketsCAD itself is
served on. An archive published by a *different* site on a *different* port is
outside everything it looks at, so it reported the install healthy — for
precisely the installs that were affected.

An operator who upgraded, read the advisory, checked their status page and saw
green had done everything asked of them and was worse off than before.

## Impact

A complete database export, readable by anyone who requests it:
user accounts and password hashes, two-factor secrets, incident history,
personal details of members and constituents, API tokens.

Password hashes and TFA secrets make this a foothold for onward compromise, not
only a disclosure.

**Directory listing being off is not protection.** Archives are named
`ticketscad-YYYYMMDD-HHMMSS.zip` and are written by a scheduled job that runs on
the hour, so a year of history is roughly nine thousand candidate filenames.
That is trivially enumerable. A `403` on the folder while the file itself
answers `200` is the ordinary behaviour of a server with listings disabled and
no rule denying files — see the corrected self-check in GHSA-rrp6-pqhj-w5wj.

## Am I affected?

You are affected if **all** of these are true:

1. You are running **4.2.3**, and
2. Your server is **Windows** — IIS or XAMPP — and
3. You installed TicketsCAD inside a published directory, which is the
   documented and ordinary arrangement (`C:\inetpub\wwwroot\...`,
   `C:\xampp\htdocs\...`).

You are also affected, on any platform, if you moved your backups by hand into a
directory your web server publishes — 4.2.3's remediation text suggested a
destination that is published on Windows.

### Check it

**Do not test this by requesting the folder.** A `403` there proves nothing.

1. Get a real archive filename from **Settings → Backup**.
2. Request that file from every site and port your server publishes — not only
   the one TicketsCAD runs on. On IIS, `appcmd list site` (from
   `C:\Windows\System32\inetsrv`) lists them with their physical paths.

```
http://localhost/backups/ticketscad-20260728-020000.zip
http://localhost:<your-app-port>/backups/ticketscad-20260728-020000.zip
```

`200` means that archive is being served. Anything else, for that filename, is
fine.

## Fix it now

Move the archives somewhere no site serves, and tell TicketsCAD to write there.

`C:\inetpub` itself is not the physical path of any site, and `C:\ProgramData`
never is — either is safe. `C:\inetpub\wwwroot` is **not**.

```powershell
New-Item -ItemType Directory -Force C:\ProgramData\TicketsCAD\backups
Move-Item .\backups\ticketscad-* C:\ProgramData\TicketsCAD\backups\
```

Then set **Settings → Backup → Backup folder** to that path. Setting it there is
what actually changes where future backups are written, and it needs no shell.

Upgrading to **4.2.4** makes `%ProgramData%\TicketsCAD\backups` the default on
Windows and leaves existing archives where they are — nothing is moved or
deleted for you — while listing them in Settings → Backup and telling you on the
Status page if they are in a published directory.

## If it was reachable

Assume the archives were readable by anyone who found them. Follow the
"If your backups directory was exposed" section of GHSA-rrp6-pqhj-w5wj — rotate
credentials, force password resets, re-enrol two-factor.

Search your logs for `200` responses to any `/backups/...zip` request. Check the
logs of **every site** on the host, not just TicketsCAD's; on IIS these are
per-site under `C:\inetpub\logs\LogFiles\W3SVC<id>\`. Search-engine crawlers
count — a crawler that fetched the file may have cached it.

## What changed in 4.2.4

1. **The Windows default is `%ProgramData%\TicketsCAD\backups`**, which is not a
   site root under IIS, XAMPP or nginx. The POSIX default is unchanged, because
   it was correct there. The platform is a parameter of the function that
   computes it, so both answers are testable from one machine — a test that can
   only see its own platform is how this shipped.
2. **The check verifies the destination, not just the source.** It writes a
   short-lived random token file into the backup directory and requests it back
   on the default ports as well as the application's own, counting a `200` only
   when the response body contains the token. It never requests a real archive:
   an archive URL in a proxy or CDN log is itself a disclosure.
3. **It says what it could not see.** Where the probe cannot settle the
   question — another hostname, another port, a reverse proxy, a directory it
   cannot write to — the row reports that explicitly, including on a passing
   result, instead of implying a clean answer.
4. **Installs already in this state are named.** The 4.2.3 location is tracked
   as a historical directory, so those archives stay listed and are never
   pruned, and the Status page raises a Critical note identifying 4.2.3 as the
   cause rather than a count that reads as operator error.
5. **The remediation text is per-platform** — PowerShell on Windows, POSIX
   elsewhere — and both are led by the platform-neutral instruction to set the
   backup folder in Settings.

## Credit

Found and reported by Ron Jones
([@rjonesbsink](https://github.com/rjonesbsink)), who tested what the shipped
fix actually did on his own server instead of assuming it had worked, listed his
sites and their physical paths beside the destination we had chosen, and
established that the health check could not see the result. He reported it
privately with a verified correction.

The original fix was written and reviewed without access to a Windows host, and
its remediation instructions were POSIX commands with Windows paths. That should
have been the signal that nobody had run them on the platform they were aimed at.

To report a security concern in TicketsCAD, see `SECURITY.md`. Private
vulnerability reporting is enabled on this repository. Please report privately
rather than in a public issue, and allow time for a fix before disclosing.
