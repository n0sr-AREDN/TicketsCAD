<?php
/**
 * Phase 119 (2026-07-25) — database health gate.
 *
 * Distinguishes a genuinely-running install from a database that is reachable
 * but whose core tables can't be read — which almost always means MySQL is
 * still recovering after an unclean shutdown (a power loss with MySQL running).
 * In that state the InnoDB tables that hold the incident data (`ticket`,
 * `action`, `assigns`, `user`, `dashboard_layouts`) are briefly unreadable, and
 * without this gate the dashboard just spins and reads like a fresh install —
 * terrifying a user into thinking their data is gone when it is sitting on disk.
 *
 * Beta report: a new user hard-powered-off a Windows/XAMPP laptop, lost nothing,
 * but saw an endless spinner + "looks like a fresh install" and thought 3 hours
 * of work were lost (2026-07-25).
 *
 * `db_gate_classify()` is a PURE function (no DB) so the decision is unit-tested.
 * `db_health_gate()` does the probing and, if not healthy, renders a calm
 * standalone page and exits — the dashboard never gets a chance to spin.
 */

/**
 * Decide the health state from three cheap observations. Pure — no I/O.
 *
 * @param bool      $connected    did the DB connection succeed?
 * @param bool|null $coreReadable could a core table be read? (null = not probed)
 * @param int       $coreListed   how many core tables information_schema lists
 *                                (-1 = information_schema itself failed)
 * @return string 'ok' | 'noconnect' | 'recovering' | 'empty' | 'unknown'
 */
function db_gate_classify(bool $connected, ?bool $coreReadable, int $coreListed): string {
    if (!$connected)      return 'noconnect';
    if ($coreReadable)    return 'ok';
    // Connected, but a core table would not read.
    if ($coreListed >= 2) return 'recovering'; // schema present → tables exist but unreadable
    if ($coreListed === 0) return 'empty';      // schema truly empty → not installed
    return 'unknown';                            // 1 table, or information_schema also failed
}

/**
 * Probe the database and, if it is not healthy, render a calm explanation and
 * exit. On a healthy install this returns quickly (one indexed LIMIT 1 read) and
 * the caller proceeds. Call it early on the dashboard, after auth.
 */
function db_health_gate(): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // 0. Is the server reachable at all? db() throws RuntimeException on failure.
    $connected = true;
    try {
        db();
    } catch (Throwable $e) {
        _db_gate_render(db_gate_classify(false, null, -1), $e->getMessage());
        exit;
    }

    // 1. Can we read a core table? `ticket` is InnoDB — the first thing an
    //    unclean-shutdown InnoDB failure takes offline. An empty-but-readable
    //    table (genuine fresh install, no incidents yet) returns null WITHOUT
    //    throwing → healthy, not a false positive.
    try {
        db_fetch_value("SELECT id FROM `{$prefix}ticket` LIMIT 1");
        return; // healthy
    } catch (Throwable $e) {
        $readErr = $e->getMessage();
    }

    // 2. Unreadable. Is the schema present (tables listed) or genuinely empty?
    $coreListed = -1;
    try {
        $coreListed = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (?, ?, ?, ?, ?)",
            ["{$prefix}ticket", "{$prefix}user", "{$prefix}action",
             "{$prefix}responder", "{$prefix}in_types"]
        );
    } catch (Throwable $e) {
        $coreListed = -1;
    }

    _db_gate_render(db_gate_classify(true, false, $coreListed), $readErr);
    exit;
}

/**
 * Render a self-contained (no DB, no external assets) explanation page. Kept
 * minimal on purpose: it has to render even when much of the app can't.
 */
function _db_gate_render(string $mode, string $detail = ''): void {
    $recoveryDoc = 'docs/TROUBLESHOOTING.md';

    $copy = [
        'noconnect' => [
            'icon'  => '&#128268;', // plug
            'title' => "Can't reach the database",
            'lead'  => "TicketsCAD can't connect to its database (MySQL/MariaDB).",
            'body'  => "In the XAMPP Control Panel, make sure <b>MySQL</b> is started (it should show green). "
                     . "If it starts and then stops on its own, MySQL may need recovery after an unclean shutdown — "
                     . "see the recovery steps below. <b>Your data is not deleted</b> by this; it is on disk.",
        ],
        'recovering' => [
            'icon'  => '&#128190;', // floppy/disk
            'title' => 'Your data is not lost',
            'lead'  => "The database is reachable, but TicketsCAD's tables can't be read right now.",
            'body'  => "This almost always means MySQL is still recovering after an <b>unclean shutdown</b> "
                     . "(for example, a power loss or a hard shutdown with MySQL running). Everything you entered "
                     . "is still on disk. To recover it: in the XAMPP Control Panel <b>Stop</b> MySQL, wait a few "
                     . "seconds, then <b>Start</b> it again and reload this page. If it's still not readable, open "
                     . "MySQL's <b>Logs &rarr; mysql_error.log</b> and look for <code>InnoDB</code> errors, then "
                     . "follow the recovery guide below.",
        ],
        'empty' => [
            'icon'  => '&#128230;', // box
            'title' => 'The database looks empty',
            'lead'  => "The database is reachable but has none of TicketsCAD's tables.",
            'body'  => "This usually means the install step didn't finish. If this is a brand-new install, run the "
                     . "installer (or the schema import) per the install guide. If you had data before, do <b>not</b> "
                     . "re-run the installer &mdash; check that MySQL is pointed at the right data folder and see the "
                     . "recovery guide below first.",
        ],
        'unknown' => [
            'icon'  => '&#9888;', // warning
            'title' => 'The database is not responding normally',
            'lead'  => "TicketsCAD reached the database, but a basic read did not succeed.",
            'body'  => "This is often MySQL recovering after an unclean shutdown. <b>Your data is on disk.</b> "
                     . "Try stopping and starting MySQL in the XAMPP Control Panel, then reload. If it persists, "
                     . "open MySQL's <b>Logs &rarr; mysql_error.log</b> and follow the recovery guide below.",
        ],
    ];
    $c = $copy[$mode] ?? $copy['unknown'];
    $detailSafe = htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8');

    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 30');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>TicketsCAD — database not ready</title><style>'
       . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:#0b1b2b;color:#e9eef4;font-family:system-ui,Segoe UI,Arial,sans-serif;padding:24px}'
       . '.card{max-width:640px;background:#12263a;border:1px solid #22405c;border-radius:12px;padding:28px 30px;'
       . 'box-shadow:0 10px 40px rgba(0,0,0,.35)}'
       . '.ico{font-size:40px;line-height:1}.h{font-size:22px;font-weight:700;margin:10px 0 6px}'
       . '.lead{font-size:16px;color:#cfe0f0;margin:0 0 14px}.body{font-size:15px;line-height:1.55;color:#c3d3e2}'
       . 'code{background:#0b1b2b;border:1px solid #22405c;border-radius:4px;padding:1px 5px;font-size:13px}'
       . 'a{color:#7fb2ff}.actions{margin-top:20px;display:flex;gap:10px;flex-wrap:wrap}'
       . '.btn{display:inline-block;background:#1e6fd0;color:#fff;text-decoration:none;padding:9px 16px;border-radius:8px;'
       . 'font-size:14px;font-weight:600;border:0;cursor:pointer}.btn.sec{background:transparent;border:1px solid #2f557a;color:#cfe0f0}'
       . '.det{margin-top:18px}.det summary{cursor:pointer;color:#89a7c4;font-size:13px}'
       . '.det pre{white-space:pre-wrap;word-break:break-word;background:#0b1b2b;border:1px solid #22405c;'
       . 'border-radius:6px;padding:10px;font-size:12px;color:#9fb6cc;margin:8px 0 0}</style></head><body>'
       . '<div class="card"><div class="ico">' . $c['icon'] . '</div>'
       . '<div class="h">' . $c['title'] . '</div>'
       . '<p class="lead">' . $c['lead'] . '</p>'
       . '<div class="body">' . $c['body'] . '</div>'
       . '<div class="actions">'
       . '<button class="btn" onclick="location.reload()">Reload</button>'
       . '<a class="btn sec" href="' . $recoveryDoc . '" target="_blank" rel="noopener">Recovery guide</a>'
       . '</div>';
    if ($detailSafe !== '') {
        echo '<details class="det"><summary>Technical detail (for support)</summary><pre>' . $detailSafe . '</pre></details>';
    }
    echo '</div></body></html>';
}
