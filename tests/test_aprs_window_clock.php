<?php
/**
 * APRS map station window — the reader must use the writer's clock.
 *
 * ORIGIN (2026-07-29, Eric beta on your-server.example.com). The APRS map
 * showed "0 stations in window" under a banner reading "APRS-IS receive
 * listener is not active. Last position received 5h ago" — while the listener
 * was healthy and had written 1,288 rows in the previous hour. Measured live:
 *
 *     now_local          = 2026-07-29 20:20:35
 *     now_utc            = 2026-07-30 01:20:35
 *     newest reported_at = 2026-07-29 20:20:32
 *     age vs UTC_TIMESTAMP() = 18003 s = 5.00 h   <- the banner
 *     age vs NOW()           = 3 s               <- reality
 *
 * `location_reports.reported_at` holds wall-clock time in the install's
 * area_timezone (the Python listener stamps _now_local_str(); api/location.php
 * uses date(); inc/atak_route.php converts inbound ISO-Z to local), but
 * api/aprs-positions.php measured it against UTC_TIMESTAMP(). No local-time
 * row is ever newer than UTC-minus-an-hour, so the window matched nothing.
 *
 * WHY THIS TEST FORCES A TIMEZONE. The bug is INVISIBLE on a UTC server: there
 * NOW() and UTC_TIMESTAMP() are the same instant, so the broken query passes.
 * CI runs on UTC. So rather than depending on the host's zone, this test pins
 * the MySQL session to -05:00 (US Central, where it was reported) and stamps
 * the fixture row the way the listener does — with the session's own NOW().
 * That reproduces the real writer's state instead of hand-seeding an ideal row.
 *
 * Each assertion runs BOTH the shipped query and the pre-fix UTC variant, so
 * the test demonstrates it would have failed before this fix rather than
 * merely passing after it.
 *
 * Usage: php tests/test_aprs_window_clock.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

echo "=== APRS station window — clock agreement ===\n\n";
$pass = 0; $fail = 0;
function ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

// ── Preconditions — self-skip on a virgin DB ──────────────────────────────
try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
foreach (['location_reports', 'location_providers'] as $t) {
    try {
        $has = db_fetch_value("SHOW TABLES LIKE " . db()->quote($t));
    } catch (Throwable $e) { $has = null; }
    if (!$has) {
        echo "SKIP: table `$t` not present on this install\n";
        echo "\n=== 0 passed, 0 failed ===\n";
        exit(0);
    }
}

$TAG        = 'TZTEST-' . getmypid();
$SESSION_TZ = '-05:00';          // US Central, where the bug was reported
$origTz     = null;
$providerId = 0;
$madeProvider = false;

// Always undo the fixture, even on a fatal.
register_shutdown_function(static function () use (&$providerId, &$madeProvider, &$origTz, $TAG) {
    try {
        if ($providerId) {
            db_query('DELETE FROM `location_reports` WHERE `provider_id` = ? AND `unit_identifier` = ?',
                [$providerId, $TAG]);
        }
        if ($madeProvider && $providerId) {
            db_query('DELETE FROM `location_providers` WHERE `id` = ?', [$providerId]);
        }
        if ($origTz !== null) { db()->exec("SET time_zone = '" . $origTz . "'"); }
    } catch (Throwable $e) { /* teardown must never mask a failure */ }
});

try {
    $origTz = (string) db_fetch_value('SELECT @@session.time_zone');
    db()->exec("SET time_zone = '$SESSION_TZ'");

    $clock = db_fetch_one('SELECT NOW() AS n_local, UTC_TIMESTAMP() AS n_utc');
    $offsetSec = (int) db_fetch_value('SELECT TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())');
    is_true($offsetSec === 18000,
        "session pinned to $SESSION_TZ so NOW() and UTC_TIMESTAMP() differ by 5 h",
        "offset was {$offsetSec}s (local {$clock['n_local']} / utc {$clock['n_utc']})");

    // Reuse the real 'aprs' provider when present; otherwise make a temp one.
    $providerId = (int) db_fetch_value("SELECT id FROM `location_providers` WHERE code = 'aprs' LIMIT 1");
    if (!$providerId) {
        db_query("INSERT INTO `location_providers` (code, name, enabled) VALUES ('aprs_tztest', 'TZ test', 0)");
        $providerId = (int) db_insert_id();
        $madeProvider = true;
    }

    // Stamp the row the way the listener does: the session's own wall clock.
    db_query(
        'INSERT INTO `location_reports`
            (provider_id, unit_identifier, lat, lng, raw_data, reported_at, received_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
        [$providerId, $TAG, 44.9778, -93.2650, $TAG . '>APRS,TCPIP*:!4458.66N/09315.90W-test']
    );

    // ── 1. The station window (the "0 stations" symptom) ──────────────────
    $windowSql = 'SELECT COUNT(DISTINCT unit_identifier)
                    FROM `location_reports`
                   WHERE provider_id = ?
                     AND unit_identifier = ?
                     AND reported_at > DATE_SUB(%s, INTERVAL ? MINUTE)';

    $nowCount = (int) db_fetch_value(sprintf($windowSql, 'NOW()'), [$providerId, $TAG, 60]);
    $utcCount = (int) db_fetch_value(sprintf($windowSql, 'UTC_TIMESTAMP()'), [$providerId, $TAG, 60]);

    is_true($nowCount === 1,
        'a freshly written station appears in the 60-minute window',
        "expected 1 station, got $nowCount — this is the \"0 stations in window\" bug");
    is_true($utcCount === 0,
        'the pre-fix UTC window would have missed it (proves the regression)',
        "the UTC variant returned $utcCount, so this test could not have caught the bug");

    // ── 2. age_sec, as rendered in the station list ───────────────────────
    $ageNow = (int) db_fetch_value(
        'SELECT TIMESTAMPDIFF(SECOND, reported_at, NOW()) FROM `location_reports`
          WHERE provider_id = ? AND unit_identifier = ? ORDER BY id DESC LIMIT 1',
        [$providerId, $TAG]
    );
    $ageUtc = (int) db_fetch_value(
        'SELECT TIMESTAMPDIFF(SECOND, reported_at, UTC_TIMESTAMP()) FROM `location_reports`
          WHERE provider_id = ? AND unit_identifier = ? ORDER BY id DESC LIMIT 1',
        [$providerId, $TAG]
    );
    is_true($ageNow >= 0 && $ageNow < 300,
        'age of a just-written report reads as seconds, not hours',
        "age_sec was $ageNow");
    is_true($ageUtc >= 17000,
        'the pre-fix age would have read ~5 h (the "Last position received 5h ago" banner)',
        "UTC age_sec was $ageUtc");

    // ── 3. The listener-status heuristic (`< 300` = running) ──────────────
    $lastNow = (int) db_fetch_value(
        'SELECT TIMESTAMPDIFF(SECOND, MAX(reported_at), NOW()) FROM `location_reports`
          WHERE provider_id = ? AND unit_identifier = ?',
        [$providerId, $TAG]
    );
    $lastUtc = (int) db_fetch_value(
        'SELECT TIMESTAMPDIFF(SECOND, MAX(reported_at), UTC_TIMESTAMP()) FROM `location_reports`
          WHERE provider_id = ? AND unit_identifier = ?',
        [$providerId, $TAG]
    );
    is_true($lastNow < 300,
        'listener status reads "running" while rows are arriving',
        "last_seen_ago was {$lastNow}s, which the API renders as \"stopped\"");
    is_true($lastUtc >= 300,
        'the pre-fix heuristic would have declared the healthy listener "stopped"',
        "UTC last_seen_ago was {$lastUtc}s");

    // ── 4. The shipped endpoint must not carry the UTC comparison ─────────
    $src = (string) file_get_contents(__DIR__ . '/../api/aprs-positions.php');
    $code = (string) preg_replace('#^\s*//.*$#m', '', $src);   // drop the clock-note comment
    is_true(strpos($code, 'UTC_TIMESTAMP') === false,
        'api/aprs-positions.php no longer measures reported_at against UTC',
        'the endpoint still contains a UTC_TIMESTAMP comparison');
} catch (Throwable $e) {
    bad('unexpected exception', $e->getMessage());
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
