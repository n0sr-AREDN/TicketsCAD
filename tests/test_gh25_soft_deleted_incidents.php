<?php
/**
 * Public issue #25 — a soft-deleted incident was still served in full.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────
 *
 * `deleted_at` appeared in api/external/v1/incidents.php exactly twice,
 * and both were existence guards for PATCH and DELETE. So the file
 * applied soft deletion when deciding whether a WRITE may proceed, and
 * not when deciding what to RETURN. Both read paths — the list and the
 * detail — handed back deleted incidents complete with street address,
 * caller name and narrative, and `deleted_at` was not in the response
 * either, so a receiver could not filter them out client-side.
 *
 * The reporter then extended it to api/incidents.php, which is worse,
 * because that is the dispatch board:
 *
 *   * Deleted while OPEN — permanent. incident_soft_delete_internal()
 *     sets deleted_at and leaves `status` alone, so the incident stays
 *     status=2 and matches the board's first clause forever. One
 *     observed install had a deleted incident sitting on the board as a
 *     live open call for 22 hours, until somebody closed a record they
 *     had already deleted.
 *   * Deleted while CLOSED — intermittent. A closed incident shows while
 *     `problemend` is inside recent_close_mins, so anything that
 *     re-stamps problemend drops a long-deleted incident back on screen.
 *
 * ── WHY THIS TEST IS SHAPED THIS WAY ─────────────────────────────────
 *
 * It does not hold a copy of the queries. The board statement is built
 * as "SELECT … {$where} {$group_filter} …", so the soft-delete term is
 * in a different PHP string from the SELECT — a test carrying its own
 * copy would be asserting against a statement that exists nowhere, and
 * would go on passing if the endpoint's real WHERE lost the term. Both
 * halves of the round trip are therefore real: rows are made and deleted
 * through the REAL writers (incident_create_internal /
 * incident_soft_delete_internal), and read back by INCLUDING the REAL
 * endpoints in a subprocess (tests/_gh25_endpoint_probe.php).
 *
 * Every "must be absent" assertion is preceded by a "must be present"
 * control on the same incident through the same probe. Absence is the
 * easiest assertion in the world to satisfy by accident — a probe that
 * silently returned nothing at all would pass the whole file.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/_test_admin.php';

$root   = str_replace('\\', '/', dirname(__DIR__));
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== GH #25 — soft-deleted incidents must not be served ===\n\n";

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

// ─────────────────────────────────────────────────────────────────────
// 0. The wastebasket projection must name columns that exist
// ─────────────────────────────────────────────────────────────────────
//
// Found while fixing the read paths, and it belongs with them: the
// wastebasket declared the incident projection as
// `id, nature, address, city, …`. Neither `nature` nor `address` is a
// column of `ticket` (they are `scope` and `street`), so the SELECT
// raised 1054, safe_wb_fetch() swallowed it and returned [], and
// soft-deleted incidents were invisible in the recovery UI — while
// still being served everywhere else. Closing the read paths without
// this would leave a deleted incident with no route back at all.
//
// Asked of the DATABASE rather than of a remembered column list, and
// applied to every type, so the next projection to drift is caught too.

/** @return array<int, array{type:string,table:string,cols:string[]}> */
function gh25_wastebasket_projections(string $src): array {
    $re = '/\'(\w+)\'\s*=>\s*\[\s*\'table\'\s*=>\s*\$prefix\s*\.\s*\'(\w+)\'.*?\'select\'\s*=>\s*\'([^\']+)\'/s';
    preg_match_all($re, $src, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $cols = [];
        foreach (explode(',', $x[3]) as $c) {
            $c = trim($c, "` \t");
            if ($c !== '') $cols[] = $c;
        }
        $out[] = ['type' => $x[1], 'table' => $x[2], 'cols' => $cols];
    }
    return $out;
}

function gh25_column_exists(string $table, string $col): bool {
    try {
        return (bool) db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $col]
        );
    } catch (Exception $e) { return false; }
}

// Positive control: the detector must be able to report a bad column.
$plant = "<?php \$tableConfig = [\n"
       . "  'ticket' => [ 'table' => \$prefix . 'ticket', 'label' => 'Incident',\n"
       . "                'select' => 'id, nature, address, deleted_at' ],\n];";
$planted = gh25_wastebasket_projections($plant);
if (count($planted) === 1 && in_array('nature', $planted[0]['cols'], true)
    && !gh25_column_exists($prefix . 'ticket', 'nature')) {
    ok('positive control: the projection detector sees the original bad column list');
} else {
    bad('positive control failed — the projection gate cannot detect the bug it exists for');
}

$wbSrc = (string) @file_get_contents($root . '/api/wastebasket.php');
$projections = gh25_wastebasket_projections($wbSrc);
if (count($projections) >= 5) {
    ok('parsed ' . count($projections) . ' wastebasket projections');
} else {
    bad('could not parse the wastebasket projections', 'found ' . count($projections));
}
foreach ($projections as $p) {
    $missing = [];
    foreach ($p['cols'] as $c) {
        if (!gh25_column_exists($prefix . $p['table'], $c)) $missing[] = $c;
    }
    if (!$missing) {
        ok("wastebasket '{$p['type']}' projection: every column exists on `{$p['table']}`");
    } else {
        bad("wastebasket '{$p['type']}' selects column(s) `{$p['table']}` does not have",
            implode(', ', $missing) . ' — the SELECT throws and the type silently lists empty');
    }
}

// ─────────────────────────────────────────────────────────────────────
// 1. Fixtures, made through the real writer
// ─────────────────────────────────────────────────────────────────────

$adminId  = test_admin_user_id();
$openId   = 0;   // deleted while OPEN   — the permanent case
$closedId = 0;   // deleted while CLOSED — the reappearing case

$cleanup = function () use (&$openId, &$closedId, $prefix) {
    foreach ([$openId, $closedId] as $tid) {
        if (!$tid) continue;
        try {
            db_query("DELETE FROM `{$prefix}action`  WHERE ticket_id = ?", [$tid]);
            db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]);
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

$marker = 'GH25 soft-delete probe ' . bin2hex(random_bytes(5));

$res = incident_create_internal([
    'in_types_id' => $typeId,
    'scope'       => $marker,
    'street'      => '900 Prospect Avenue',
    'city'        => 'Cleveland',
    'state'       => 'OH',
    'description' => 'GH25 regression fixture — deleted while OPEN',
], $adminId);
$openId = (int) ($res['id'] ?? 0);
if ($openId > 0) ok("real writer created the OPEN fixture incident #{$openId}");
else             bad('incident_create_internal did not create the open fixture',
                     implode('; ', $res['errors'] ?? ['unknown']));

$res2 = incident_create_internal([
    'in_types_id' => $typeId,
    'scope'       => $marker . ' CLOSED',
    'street'      => '901 Prospect Avenue',
    'city'        => 'Cleveland',
    'state'       => 'OH',
    'description' => 'GH25 regression fixture — deleted while CLOSED',
], $adminId);
$closedId = (int) ($res2['id'] ?? 0);
if ($closedId > 0) ok("real writer created the CLOSED fixture incident #{$closedId}");
else               bad('incident_create_internal did not create the closed fixture',
                       implode('; ', $res2['errors'] ?? ['unknown']));

if ($openId <= 0 || $closedId <= 0) {
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

// The closed one is put inside the board's recent-close window, which is
// the state that made deletion look intermittent rather than broken.
db_query("UPDATE `{$prefix}ticket` SET `status` = 1, `problemend` = NOW() WHERE `id` = ?", [$closedId]);

// ─────────────────────────────────────────────────────────────────────
// 2. Probe plumbing
// ─────────────────────────────────────────────────────────────────────

function gh25_probe(array $args): ?array {
    $php  = PHP_BINARY ?: 'php';
    $cmd  = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_gh25_endpoint_probe.php');
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string) $a);
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

/** Is $id present in a board/search payload? */
function gh25_board_has(?array $payload, int $id): bool {
    foreach (($payload['incidents'] ?? []) as $row) {
        if ((int) ($row['id'] ?? 0) === $id) return true;
    }
    return false;
}

// ─────────────────────────────────────────────────────────────────────
// 3. The dispatch board  (api/incidents.php)
// ─────────────────────────────────────────────────────────────────────

$board = gh25_probe(['board']);
if ($board === null || !isset($board['incidents'])) {
    bad('the board probe returned no usable payload — the endpoint could not be driven',
        'every absence assertion below would pass vacuously, so they are not run');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}
ok('drove api/incidents.php func=0 in-process (' . count($board['incidents']) . ' incidents)');

// Control FIRST: a live open incident must be on the board.
if (gh25_board_has($board, $openId)) {
    ok('control: the live OPEN incident is on the board before deletion');
} else {
    bad('control failed: a live open incident is not on the board',
        'the probe cannot see incidents, so an absence assertion proves nothing');
}
if (gh25_board_has($board, $closedId)) {
    ok('control: the recently-CLOSED incident is on the board before deletion');
} else {
    bad('control failed: a just-closed incident is not inside the recent-close window');
}

// Delete both through the real writer, leaving `status` exactly as the
// production path leaves it — open stays open.
$d1 = incident_soft_delete_internal($openId, $adminId);
$d2 = incident_soft_delete_internal($closedId, $adminId);
if (!empty($d1['deleted']) && !empty($d2['deleted'])) {
    ok('incident_soft_delete_internal() reported both fixtures deleted');
} else {
    bad('the real soft-delete writer failed',
        implode('; ', array_merge($d1['errors'] ?? [], $d2['errors'] ?? [])));
}

// The state the filter has to cope with is the state the writer really
// produces: deleted_at set, status untouched.
$openStatus = (int) db_fetch_value("SELECT `status` FROM `{$prefix}ticket` WHERE id = ?", [$openId]);
$openDel    = db_fetch_value("SELECT `deleted_at` FROM `{$prefix}ticket` WHERE id = ?", [$openId]);
if ($openDel !== null && $openDel !== '' && $openStatus === 2) {
    ok('the writer leaves a deleted-while-open incident at status=2 with deleted_at set');
} else {
    bad('unexpected post-delete state', "status={$openStatus} deleted_at=" . var_export($openDel, true));
}

$board2 = gh25_probe(['board']);
if ($board2 === null || !isset($board2['incidents'])) {
    bad('the board probe failed after deletion');
} else {
    if (!gh25_board_has($board2, $openId)) {
        ok('the incident deleted while OPEN is gone from the board');
    } else {
        bad('an incident deleted while OPEN is STILL on the dispatch board',
            "#{$openId} — this is the case that never ages out");
    }
    if (!gh25_board_has($board2, $closedId)) {
        ok('the incident deleted while CLOSED is gone from the board');
    } else {
        bad('an incident deleted while CLOSED is still inside the recent-close window',
            "#{$closedId}");
    }
}

// The search branch of the same endpoint has its own WHERE.
$search = gh25_probe(['search', $marker]);
if ($search === null) {
    bad('the search probe returned no usable payload');
} else {
    if (!gh25_board_has($search, $openId) && !gh25_board_has($search, $closedId)) {
        ok('api/incidents.php ?search= does not return the deleted incidents');
    } else {
        bad('the incident search branch still returns soft-deleted incidents');
    }
}

// ─────────────────────────────────────────────────────────────────────
// 4. The wastebasket must now hold them (the recovery path)
// ─────────────────────────────────────────────────────────────────────

$waste = gh25_probe(['waste']);
if ($waste === null || !isset($waste['items'])) {
    bad('the wastebasket probe returned no usable payload');
} else {
    $ids = [];
    foreach ($waste['items'] as $it) $ids[] = (int) ($it['id'] ?? 0);
    if (in_array($openId, $ids, true) && in_array($closedId, $ids, true)) {
        ok('both deleted incidents are listed in the wastebasket — recovery is possible');
    } else {
        bad('deleted incidents are not listed in the wastebasket',
            'they are now hidden everywhere AND unrecoverable, which is worse than the leak');
    }
    foreach ($waste['items'] as $it) {
        if ((int) ($it['id'] ?? 0) !== $openId) continue;
        if (!empty($it['label']) && strpos((string) $it['label'], $marker) !== false) {
            ok('the wastebasket label is built from the real columns (scope/street)');
        } else {
            bad('the wastebasket label does not carry the incident scope',
                'label=' . var_export($it['label'] ?? null, true));
        }
    }
}

// ─────────────────────────────────────────────────────────────────────
// 5. The External API  (api/external/v1/incidents.php)
// ─────────────────────────────────────────────────────────────────────

$tokenRaw = null;
try {
    require_once $root . '/inc/external-auth.php';
    if (function_exists('ext_api_mint_token')) {
        $minted = ext_api_mint_token($adminId, ['incidents:read'], $adminId,
            ['name' => 'gh25-regression-' . bin2hex(random_bytes(3))]);
        $tokenRaw = $minted['raw_token'] ?? null;
        $tokenId  = (int) ($minted['id'] ?? 0);
        if ($tokenId > 0) {
            register_shutdown_function(function () use ($tokenId, $prefix) {
                try {
                    db_query("DELETE FROM `{$prefix}external_api_tokens` WHERE id = ?", [$tokenId]);
                } catch (Exception $e) { /* best effort */ }
            });
        }
    }
} catch (Exception $e) { /* reported below */ }

if (!$tokenRaw) {
    echo "  SKIP  External API half — could not mint a bearer token "
       . "(external_api_tokens table absent or minting unavailable)\n";
} else {
    $extList = gh25_probe(['ext_list', $tokenRaw]);
    if ($extList === null) {
        bad('the External API list probe returned no usable payload');
    } else {
        $rows = $extList['data']['incidents'] ?? $extList['incidents'] ?? null;
        if (!is_array($rows)) {
            bad('the External API list did not reach the query',
                'error=' . (string) ($extList['error'] ?? '?')
                . ' — a refusal at the edge cannot demonstrate the filter works');
        } else {
            ok('the External API list probe authenticated and reached the query');
            $ids = [];
            foreach ($rows as $r) $ids[] = (int) ($r['id'] ?? 0);
            if (!in_array($openId, $ids, true) && !in_array($closedId, $ids, true)) {
                ok('the External API list excludes both soft-deleted incidents');
            } else {
                bad('the External API list still returns soft-deleted incidents');
            }
        }
    }

    $extDetail = gh25_probe(['ext_detail', $tokenRaw, $openId]);
    if ($extDetail === null) {
        bad('the External API detail probe returned no usable payload');
    } else {
        // Specifically not_found. An earlier draft accepted "any error",
        // and passed on an `https_required` refusal from the edge — an
        // absence assertion satisfied by never reaching the query at all.
        $err = (string) ($extDetail['error'] ?? $extDetail['code'] ?? '');
        $leaked = isset($extDetail['data']['street']) || isset($extDetail['data']['contact']);
        if ($err === 'not_found' && !$leaked) {
            ok('the External API detail returns not_found for a soft-deleted incident');
        } elseif ($err !== '' && $err !== 'not_found') {
            bad('the External API detail failed before reaching the query',
                "error={$err} — this assertion proves nothing about the fix");
        } else {
            bad('the External API still returns a deleted incident in full',
                substr(json_encode($extDetail), 0, 200));
        }
    }

    // Control: a LIVE incident must still be served, or the fix is just
    // "the endpoint stopped working".
    $liveId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}ticket`
          WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
          ORDER BY id DESC LIMIT 1");
    if ($liveId > 0) {
        $liveDetail = gh25_probe(['ext_detail', $tokenRaw, $liveId]);
        $servedOk = is_array($liveDetail)
            && (($liveDetail['ok'] ?? null) === true || isset($liveDetail['data']));
        if ($servedOk) {
            ok("control: the External API still serves a LIVE incident (#{$liveId})");
        } else {
            bad('the External API no longer serves live incidents either',
                substr(json_encode($liveDetail), 0, 200));
        }
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
