<?php
/**
 * Issue #26 (public repo): the Location Providers health check in
 * api/health.php had two independent bugs, both fixed by extracting
 * checkLocationProviders() to inc/location-health.php (issue #30's
 * OT_CONFIG_LIBRARY_ONLY-style problem: api/health.php requires auth.php
 * and dispatches on $_SERVER['REQUEST_METHOD'] at include time, so nothing
 * in it was reachable from a CLI test):
 *
 *   1. $browserDriven checked for code 'internal_gps', which does not exist
 *      in the seeded set (the real code is 'internal'). So a fresh install
 *      with only Internal GPS enabled read "no data yet — verify provider
 *      service is running" (and counted as dark in the aggregate) instead
 *      of the intended "passive — waiting for first browser report".
 *   2. $staleSec was a flat 30 minutes for every provider, ignoring the
 *      per-provider location_providers.max_age_seconds column that
 *      api/location.php's map-freshness query already honours — so the
 *      Status page and the map could disagree about the same row. The
 *      column also wasn't even in the SELECT, so the reporter's own
 *      suggested one-line fix (read $r['max_age_seconds']) would have
 *      silently no-op'd without also widening the query.
 *
 * This file drives the real function against real location_providers /
 * location_reports rows (never a hand-copied reimplementation of the
 * status logic), using the actual seeded providers 'internal' and 'traccar'
 * for test 1 (both naturally have zero reports on any install, so no
 * insert/cleanup needed there) and temporarily enabling the actual seeded
 * 'traccar' (max_age_seconds=300) and 'owntracks' (max_age_seconds=5400)
 * rows for test 2, restoring their original enabled flags afterward no
 * matter how the test exits.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/location-health.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;

function t_ok($label, $cond, $hint = '') {
    global $pass, $fail;
    if ($cond) { echo "  [PASS] $label\n"; $pass++; }
    else       { echo "  [FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n"; $fail++; }
}

function providerEntry(array $result, string $code): ?array {
    foreach ($result['details']['providers'] as $p) {
        if ($p['code'] === $code) { return $p; }
    }
    return null;
}

echo "=== GH #26: location-provider health checks ===\n\n";

echo "-- 1. Browser-driven classification (code 'internal', not the nonexistent 'internal_gps') --\n";

$internal = db_fetch_one("SELECT id, enabled FROM `{$prefix}location_providers` WHERE code = 'internal'");
t_ok('the seeded install has an "internal" provider row', $internal !== null && $internal !== false,
    'no internal provider — cannot exercise the real seed data');

if ($internal) {
    $internalReports = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}location_reports` WHERE provider_id = ?", [(int) $internal['id']]
    );
    t_ok('control: "internal" naturally has zero reports on this install (so this test proves something)',
        $internalReports === 0,
        "found {$internalReports} existing reports — test would need to isolate them first");

    if ((int) $internal['enabled'] !== 1) {
        // Shouldn't happen (internal ships enabled=1), but don't assert
        // against a "disabled" status if some install has toggled it off.
        echo "  SKIP: 'internal' is not enabled on this install — skipping the passive-vs-disabled assertion\n";
    } else {
        $result = checkLocationProviders($prefix);
        $entry = providerEntry($result, 'internal');
        t_ok('"internal" (0 reports, enabled) reads "passive", not "no_data"',
            $entry !== null && $entry['status'] === 'passive',
            'got: ' . ($entry['status'] ?? '(missing from result)'));
        t_ok('"passive" is not counted as dark in the aggregate — status is not error solely because of this',
            $result['status'] !== 'error' || strpos($result['message'], (string) 1) === false,
            $result['message']);
    }
}

// Negative control: a non-browser-driven provider with zero reports must
// still read "no_data", not "passive" — otherwise the fix could have been
// "make everything passive" rather than fixing the specific code list.
$traccarRow = db_fetch_one("SELECT id, code, enabled, max_age_seconds FROM `{$prefix}location_providers` WHERE code = 'traccar'");
t_ok('the seeded install has a "traccar" provider row', $traccarRow !== null && $traccarRow !== false);

if ($traccarRow) {
    $traccarReports0 = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}location_reports` WHERE provider_id = ?", [(int) $traccarRow['id']]
    );
    t_ok('control: "traccar" naturally has zero reports on this install',
        $traccarReports0 === 0, "found {$traccarReports0} existing reports");

    // Temporarily enable traccar (it ships disabled) to exercise the
    // enabled-with-no-reports branch, then restore no matter what.
    $origTraccarEnabled = (int) $traccarRow['enabled'];
    db_query("UPDATE `{$prefix}location_providers` SET enabled = 1 WHERE id = ?", [(int) $traccarRow['id']]);
    try {
        $result = checkLocationProviders($prefix);
        $entry = providerEntry($result, 'traccar');
        t_ok('"traccar" (0 reports, enabled) reads "no_data", NOT "passive" (negative control)',
            $entry !== null && $entry['status'] === 'no_data',
            'got: ' . ($entry['status'] ?? '(missing from result)'));
    } finally {
        db_query("UPDATE `{$prefix}location_providers` SET enabled = ? WHERE id = ?",
            [$origTraccarEnabled, (int) $traccarRow['id']]);
    }
}

echo "\n-- 2. Per-provider max_age_seconds, not a flat 30-minute constant --\n";

// Deliberately NOT asserting specific numbers here (e.g. "owntracks is
// 5400s") — that's tuning data, not part of the contract this bug fix is
// about, and it drifts: this test originally hardcoded 5400 for owntracks
// by copying the number from the GH #26 report, which was itself already
// stale relative to sql/run_03_location_providers.php's repair step (which
// sets owntracks to 120s). A fresh install seeds 120; some already-migrated
// installs still carry an older value, because that repair only touches
// rows still on the raw column default. Whichever value is actually
// configured, the test picks two DIFFERENT thresholds dynamically and
// proves the code distinguishes between them — that's the actual bug.
$owntracksRow = db_fetch_one("SELECT id, code, enabled, max_age_seconds FROM `{$prefix}location_providers` WHERE code = 'owntracks'");
t_ok('the seeded install has an "owntracks" provider row', $owntracksRow !== null);
t_ok('traccar and owntracks have DIFFERENT configured thresholds (a meaningful test needs two distinct values)',
    $traccarRow && $owntracksRow
        && (int) $traccarRow['max_age_seconds'] > 0 && (int) $owntracksRow['max_age_seconds'] > 0
        && (int) $traccarRow['max_age_seconds'] !== (int) $owntracksRow['max_age_seconds'],
    'traccar=' . ($traccarRow['max_age_seconds'] ?? '?') . ' owntracks=' . ($owntracksRow['max_age_seconds'] ?? '?'));

if ($traccarRow && $owntracksRow
    && (int) $traccarRow['max_age_seconds'] !== (int) $owntracksRow['max_age_seconds']) {
    $origTraccarEnabled2 = (int) $traccarRow['enabled'];
    $origOwntracksEnabled = (int) $owntracksRow['enabled'];
    db_query("UPDATE `{$prefix}location_providers` SET enabled = 1 WHERE id IN (?, ?)",
        [(int) $traccarRow['id'], (int) $owntracksRow['id']]);

    // Sort by threshold so the test doesn't assume which named provider
    // has the shorter window — that relationship isn't part of the
    // contract either, only "the code honours whatever's configured."
    $short = ((int) $traccarRow['max_age_seconds'] < (int) $owntracksRow['max_age_seconds']) ? $traccarRow : $owntracksRow;
    $long  = ($short === $traccarRow) ? $owntracksRow : $traccarRow;
    $shortAge = (int) $short['max_age_seconds'];
    $longAge  = (int) $long['max_age_seconds'];
    // A report aged partway between the two thresholds: past the short
    // provider's tolerance, still inside the long provider's — the exact
    // "two parts of the same app disagreeing about the same row" scenario
    // the issue reported (api/location.php honours max_age_seconds; the
    // health check used to ignore it entirely).
    $testAgeSec = $shortAge + (int) floor(($longAge - $shortAge) / 2);

    $insertedIds = [];
    try {
        $agedTs = date('Y-m-d H:i:s', time() - $testAgeSec);
        foreach ([[$short, 'ZTEST_gh26_short'], [$long, 'ZTEST_gh26_long']] as $pair) {
            [$row, $unit] = $pair;
            db_query(
                "INSERT INTO `{$prefix}location_reports`
                    (provider_id, unit_identifier, lat, lng, reported_at, received_at)
                 VALUES (?, ?, 39.0997, -94.5786, ?, ?)",
                [(int) $row['id'], $unit, $agedTs, $agedTs]
            );
            $insertedIds[] = (int) db_insert_id();
        }

        $result = checkLocationProviders($prefix);
        $shortEntry = providerEntry($result, $short['code']);
        $longEntry  = providerEntry($result, $long['code']);

        t_ok("a {$testAgeSec}s-old report reads \"stale\" for the shorter-threshold provider ({$shortAge}s tolerance)",
            $shortEntry !== null && $shortEntry['status'] === 'stale',
            'got: ' . ($shortEntry['status'] ?? '(missing)'));
        t_ok("…but \"ok\" for the longer-threshold provider ({$longAge}s tolerance) — same age, different verdict, on purpose",
            $longEntry !== null && $longEntry['status'] === 'ok',
            'got: ' . ($longEntry['status'] ?? '(missing)'));
        t_ok('the response echoes back the REAL max_age_seconds it read, per provider (not a flat constant)',
            ($shortEntry['max_age_seconds'] ?? null) === $shortAge
                && ($longEntry['max_age_seconds'] ?? null) === $longAge,
            'short=' . ($shortEntry['max_age_seconds'] ?? '?') . " (want {$shortAge}) long=" . ($longEntry['max_age_seconds'] ?? '?') . " (want {$longAge})");
    } finally {
        foreach ($insertedIds as $id) {
            db_query("DELETE FROM `{$prefix}location_reports` WHERE id = ?", [$id]);
        }
        db_query("UPDATE `{$prefix}location_providers` SET enabled = ? WHERE id = ?",
            [$origTraccarEnabled2, (int) $traccarRow['id']]);
        db_query("UPDATE `{$prefix}location_providers` SET enabled = ? WHERE id = ?",
            [$origOwntracksEnabled, (int) $owntracksRow['id']]);
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
