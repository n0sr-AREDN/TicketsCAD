<?php
/**
 * Public issue #25 follow-up sweep — regression coverage for the read
 * sites BEYOND the two fixed in commit 1502157.
 *
 * tests/test_gh25_soft_deleted_incidents.php already covers the original
 * report: api/incidents.php (dispatch board), api/external/v1/incidents.php
 * (External API), and api/wastebasket.php. Eric's closing comment on the
 * issue named "roughly fifty read sites" beyond those two and asked for
 * the sweep to be tracked separately — tools/soft_delete_audit.php is the
 * permanent gate for that; THIS file is the regression coverage proving a
 * representative sample of the sites it found are actually fixed, driven
 * through the real endpoints/functions the same way the original GH25 test
 * does (not a hand-copied query — see that file's docblock for why).
 *
 * Covers, through the REAL production code path in every case:
 *   - api/incident-detail.php   (the desktop UI's own incident detail view
 *                                — this was the largest gap found: `t.*`,
 *                                same shape of leak as the two already-
 *                                fixed endpoints, but for every dispatcher
 *                                using the app, not just API integrators)
 *   - api/incident-list.php     (named explicitly in Eric's closing comment)
 *   - api/incident-search.php   (named explicitly in Eric's closing comment)
 *   - api/callboard.php         (the WALL-DISPLAY call board — a SEPARATE
 *                                endpoint from api/incidents.php's dispatch
 *                                board, same class of "deleted-while-open
 *                                never ages out" bug, missed by 1502157)
 *   - api/statistics.php        (dashboard counts must not include a
 *                                soft-deleted incident)
 *   - inc/assignment-write.php's assign_create_internal() (the canonical
 *                                writer — a soft-deleted incident must
 *                                refuse new unit assignments)
 *   - inc/incident-number.php's incnum_find_existing() (POSITIVE control —
 *                                this one MUST still see the deleted
 *                                incident's number, or the allocator could
 *                                reissue it; this is Eric's own named
 *                                example of a legitimate exception)
 *
 * Every "must be absent" assertion is preceded by a "must be present"
 * control on the same incident through the same probe, same discipline as
 * the original GH25 test — a probe that silently returns nothing at all
 * would pass every absence assertion in this file.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/incident-number.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Issue #25 follow-up sweep — regression coverage ===\n\n";

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

// ─────────────────────────────────────────────────────────────────────
// 1. Fixtures, made through the real writer (same pattern as GH25)
// ─────────────────────────────────────────────────────────────────────

$adminId  = test_admin_user_id();
$openId   = 0;
$closedId = 0;

$cleanup = function () use (&$openId, &$closedId, $prefix) {
    foreach ([$openId, $closedId] as $tid) {
        if (!$tid) continue;
        try {
            db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]);
            db_query("DELETE FROM `{$prefix}action`  WHERE ticket_id = ?", [$tid]);
            db_query("DELETE FROM `{$prefix}ticket`  WHERE id = ?",        [$tid]);
        } catch (Exception $e) { echo "  (cleanup warning: {$e->getMessage()})\n"; }
    }
};
register_shutdown_function($cleanup);

$typeId = 0;
try {
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
} catch (Exception $e) { /* handled below */ }

if ($typeId <= 0) {
    echo "  SKIP  no incident types configured — cannot create a fixture incident\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$marker = 'SDS sweep probe ' . bin2hex(random_bytes(5));

$res = incident_create_internal([
    'in_types_id' => $typeId,
    'scope'       => $marker,
    'street'      => '221B Baker Street',
    'city'        => 'Cleveland',
    'state'       => 'OH',
    'description' => 'SDS sweep fixture — deleted while OPEN',
], $adminId);
$openId = (int) ($res['id'] ?? 0);
if ($openId > 0) ok("real writer created the OPEN fixture incident #{$openId}");
else             bad('incident_create_internal did not create the open fixture',
                     implode('; ', $res['errors'] ?? ['unknown']));

$res2 = incident_create_internal([
    'in_types_id' => $typeId,
    'scope'       => $marker . ' CLOSED',
    'street'      => '221C Baker Street',
    'city'        => 'Cleveland',
    'state'       => 'OH',
    'description' => 'SDS sweep fixture — deleted while CLOSED',
], $adminId);
$closedId = (int) ($res2['id'] ?? 0);
if ($closedId > 0) ok("real writer created the CLOSED fixture incident #{$closedId}");
else               bad('incident_create_internal did not create the closed fixture',
                       implode('; ', $res2['errors'] ?? ['unknown']));

if ($openId <= 0 || $closedId <= 0) {
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

// Put the closed one inside the recent-close window, same as GH25's test —
// this is the state that made deletion look intermittent rather than broken.
db_query("UPDATE `{$prefix}ticket` SET `status` = 1, `problemend` = NOW() WHERE `id` = ?", [$closedId]);

$openNumber = (string) db_fetch_value("SELECT incident_number FROM `{$prefix}ticket` WHERE id = ?", [$openId]);

// ─────────────────────────────────────────────────────────────────────
// 2. Probe plumbing
// ─────────────────────────────────────────────────────────────────────

function sds_probe(array $args): ?array {
    $php  = PHP_BINARY ?: 'php';
    $cmd  = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_soft_delete_sweep_probe.php');
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string) $a);
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

/** Is $id present in a payload shaped {incidents:[...]} or {results:[...]}? */
function sds_list_has(?array $payload, int $id): bool {
    $rows = $payload['incidents'] ?? $payload['results'] ?? null;
    if (!is_array($rows)) return false;
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $id) return true;
    }
    return false;
}

// ─────────────────────────────────────────────────────────────────────
// 3. api/incident-detail.php — the desktop UI's own detail view
// ─────────────────────────────────────────────────────────────────────

$detailBefore = sds_probe(['detail', $openId]);
if (is_array($detailBefore) && (int) ($detailBefore['incident']['id'] ?? 0) === $openId) {
    ok('control: api/incident-detail.php serves the LIVE fixture before deletion');
} else {
    bad('control failed: api/incident-detail.php did not serve the live fixture',
        'an absence assertion after deletion would prove nothing — probe payload: '
        . substr(json_encode($detailBefore), 0, 200));
}

// ─────────────────────────────────────────────────────────────────────
// 4. api/incident-list.php / api/incident-search.php — before-delete controls
// ─────────────────────────────────────────────────────────────────────

$listBefore = sds_probe(['list']);
if (sds_list_has($listBefore, $openId)) {
    ok('control: the live OPEN fixture is on api/incident-list.php before deletion');
} else {
    bad('control failed: api/incident-list.php does not show the live fixture');
}

$searchBefore = sds_probe(['search', $marker]);
if (sds_list_has($searchBefore, $openId) && sds_list_has($searchBefore, $closedId)) {
    ok('control: both fixtures are found by api/incident-search.php before deletion');
} else {
    bad('control failed: api/incident-search.php does not find the live fixtures');
}

// ─────────────────────────────────────────────────────────────────────
// 5. api/callboard.php — the WALL-DISPLAY board (separate from the
//    already-fixed dispatch board in api/incidents.php)
// ─────────────────────────────────────────────────────────────────────

$wallBefore = sds_probe(['callboard_wall']);
if ($wallBefore === null || !isset($wallBefore['incidents'])) {
    bad('the wall-board probe returned no usable payload — the endpoint could not be driven',
        'every absence assertion below would pass vacuously, so they are not run');
} else {
    ok('drove api/callboard.php in-process (' . count($wallBefore['incidents']) . ' incidents)');
    if (sds_list_has($wallBefore, $openId)) {
        ok('control: the live OPEN fixture is on the wall board before deletion');
    } else {
        bad('control failed: a live open incident is not on the wall board');
    }
    if (sds_list_has($wallBefore, $closedId)) {
        ok('control: the recently-CLOSED fixture is on the wall board before deletion');
    } else {
        bad('control failed: a just-closed incident is not inside the recent-close window');
    }
}

// ─────────────────────────────────────────────────────────────────────
// 6. api/statistics.php — open-ticket count BEFORE deletion
// ─────────────────────────────────────────────────────────────────────

$statsBefore = sds_probe(['stats']);
$openCountBefore = is_array($statsBefore) ? ($statsBefore['open_tickets'] ?? null) : null;
if (is_int($openCountBefore)) {
    ok("drove api/statistics.php in-process (open_tickets={$openCountBefore})");
} else {
    bad('the statistics probe did not return an open_tickets count',
        substr(json_encode($statsBefore), 0, 200));
}

// ─────────────────────────────────────────────────────────────────────
// 7. Delete both through the real writer
// ─────────────────────────────────────────────────────────────────────

$d1 = incident_soft_delete_internal($openId, $adminId);
$d2 = incident_soft_delete_internal($closedId, $adminId);
if (!empty($d1['deleted']) && !empty($d2['deleted'])) {
    ok('incident_soft_delete_internal() reported both fixtures deleted');
} else {
    bad('the real soft-delete writer failed',
        implode('; ', array_merge($d1['errors'] ?? [], $d2['errors'] ?? [])));
}

// ─────────────────────────────────────────────────────────────────────
// 8. Absence assertions — every one preceded by the control above
// ─────────────────────────────────────────────────────────────────────

$detailAfter = sds_probe(['detail', $openId]);
$detailErr = is_array($detailAfter) ? (string) ($detailAfter['error'] ?? '') : '';
if ($detailErr !== '' && stripos($detailErr, 'not found') !== false) {
    ok('api/incident-detail.php returns "not found" for the soft-deleted incident');
} else {
    bad('api/incident-detail.php still serves the soft-deleted incident',
        substr(json_encode($detailAfter), 0, 200));
}

$listAfter = sds_probe(['list']);
if (!sds_list_has($listAfter, $openId) && !sds_list_has($listAfter, $closedId)) {
    ok('api/incident-list.php excludes both soft-deleted fixtures');
} else {
    bad('api/incident-list.php still returns a soft-deleted fixture');
}

$searchAfter = sds_probe(['search', $marker]);
if (!sds_list_has($searchAfter, $openId) && !sds_list_has($searchAfter, $closedId)) {
    ok('api/incident-search.php excludes both soft-deleted fixtures');
} else {
    bad('api/incident-search.php still returns a soft-deleted fixture');
}

$wallAfter = sds_probe(['callboard_wall']);
if ($wallAfter === null || !isset($wallAfter['incidents'])) {
    bad('the wall-board probe failed after deletion');
} else {
    if (!sds_list_has($wallAfter, $openId)) {
        ok('the incident deleted while OPEN is gone from the wall board');
    } else {
        bad('an incident deleted while OPEN is STILL on the wall board',
            "#{$openId} — this is the case that never ages out");
    }
    if (!sds_list_has($wallAfter, $closedId)) {
        ok('the incident deleted while CLOSED is gone from the wall board');
    } else {
        bad('an incident deleted while CLOSED is still on the wall board inside the recent-close window');
    }
}

$statsAfter = sds_probe(['stats']);
$openCountAfter = is_array($statsAfter) ? ($statsAfter['open_tickets'] ?? null) : null;
if (is_int($openCountBefore) && is_int($openCountAfter)) {
    if ($openCountAfter === $openCountBefore - 1) {
        ok("api/statistics.php's open_tickets count dropped by exactly 1 after deleting the open fixture ({$openCountBefore} -> {$openCountAfter})");
    } else {
        bad('api/statistics.php open_tickets count did not drop by exactly 1',
            "before={$openCountBefore} after={$openCountAfter}");
    }
} else {
    bad('could not compare open_tickets before/after — one of the probes failed');
}

// ─────────────────────────────────────────────────────────────────────
// 9. inc/assignment-write.php — the canonical writer must refuse to
//    assign a unit to a soft-deleted incident
// ─────────────────────────────────────────────────────────────────────

$responderId = 0;
try {
    $responderId = (int) db_fetch_value("SELECT id FROM `{$prefix}responder` ORDER BY id LIMIT 1");
} catch (Exception $e) { /* handled below */ }

if ($responderId <= 0) {
    echo "  SKIP  no responder configured — cannot test the assignment write-guard\n";
} else {
    $assignResult = assign_create_internal($openId, $responderId, '', $adminId);
    if (!empty($assignResult['errors']) && empty($assignResult['id'])) {
        ok('assign_create_internal() refuses to assign a unit to a soft-deleted incident');
    } else {
        bad('assign_create_internal() created an assignment against a soft-deleted incident',
            substr(json_encode($assignResult), 0, 200));
        // Best-effort cleanup if it somehow succeeded.
        if (!empty($assignResult['id'])) {
            try { db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [(int) $assignResult['id']]); }
            catch (Exception $e) {}
        }
    }
}

// ─────────────────────────────────────────────────────────────────────
// 10. POSITIVE control — inc/incident-number.php's collision check MUST
//     still see the deleted incident (Eric's own named example of a
//     legitimate exception on issue #25).
// ─────────────────────────────────────────────────────────────────────

if ($openNumber !== '' && $openNumber !== '0') {
    $foundId = incnum_find_existing($openNumber);
    if ($foundId === $openId) {
        ok("incnum_find_existing() still finds the soft-deleted incident's number ({$openNumber}) — the collision check correctly sees deleted rows");
    } else {
        bad('incnum_find_existing() no longer finds the soft-deleted incident\'s number',
            'found=' . var_export($foundId, true) . ' expected=' . $openId
            . ' — if this regressed, the allocator could now reissue a number a deleted incident already used');
    }
} else {
    echo "  SKIP  fixture incident has no rendered incident_number (numbering feature may be off) — cannot verify the collision-check exception\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
