# Amendment for GHSA-rrp6-pqhj-w5wj

Replace the **"Check your own install — one minute"** section of the published
advisory with everything below the line. Nothing else in the advisory changes.

Reported by Ron Jones (@rjonesbsink).

---

## Check your own install — one minute

> **Corrected 2026-08-02 — the backups check in the original version of this
> advisory was wrong.** It told you to request `https://your-site/backups/` and
> read a `403` as "that path is blocked". On a real install the folder answered
> `403` while the archive inside it answered `200` and downloaded in full — the
> complete database export. Any web server with directory listing turned off
> but no rule denying the files behaves that way, and on Apache that is the
> ordinary default. **If you ran the old check and saw `403`, your backups have
> not been checked. Run the corrected check below.** Reported by
> @rjonesbsink.

Run these from any computer, replacing `your-site`. You can also paste the URLs
into a browser.

**1. The two script directories.** These paths always exist, so the answer
means what it says:

```bash
curl -s -o /dev/null -w 'sql   %{http_code}\n' https://your-site/sql/run_migrations.php
curl -s -o /dev/null -w 'tools %{http_code}\n' https://your-site/tools/
```

**2. Your backups — ask for an actual archive, by name.** Get a filename from
**Settings → Backup / Maintenance**, which lists every archive this install has written (or
list the backup folder on the server). Then request that file:

```bash
curl -s -o /dev/null -w 'archive %{http_code}\n' \
     https://your-site/backups/ticketscad-20260728-020000.zip
```

Substitute a real filename. **Do not shorten this to a request for
`/backups/`.** Only a request for a file tells you whether files are served.

If you have never taken a backup there is nothing to ask for. Take one
(Settings → Backup / Maintenance → "Back up now") and then run the check. Until you do, this
is **untested** — which is not the same as safe.

**Reading the result:**

* `403`, `404` or `401` on a request for **a real archive filename** — good.
  That file is not being served.
* `403` on `/backups/` — **means nothing.** See the correction above.
* `200` — **affected.** That path is being served. Go to "Fix it now".
* `301` / `302` — inconclusive; you are being redirected (often HTTP → HTTPS).
  Re-run against the address you are redirected to.
* On IIS, `500` is **not** a pass. It means the `web.config` in that folder is
  invalid, so nothing is denying anything — it is being blocked by an error
  rather than by a rule, and the next person to "fix" the error re-opens it.
  You want `404`.

If your site is `http://` rather than `https://`, use that instead.

TicketsCAD runs these checks against itself and reports the answer on
**Settings → System Health**, in the "Web exposure" row. From **v4.2.4** the backups
probe asks for a named archive — or, when there is no archive yet, writes a
small random self-test file into the folder and asks for that back. If it can
do neither, the row reads grey **"Not determined"** instead of green. Earlier
versions could fall back to requesting the directory and report a `403` as a
pass; that is the same mistake this section made, and it is fixed in the same
release.
