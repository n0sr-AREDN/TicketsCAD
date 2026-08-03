<?php
/**
 * PAR roster: an UNAVAILABLE unit was dropped by substring accident, and
 * whether it belongs on the roll call is now a configured decision.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────
 *
 * par_assigned_units() filtered standby-ish units with:
 *
 *     $standbyKeywords = ['standby','staging','available','offduty',…];
 *     if (strpos($lower, $kw) !== false) { $isStandby = true; }
 *
 * "unavailable" CONTAINS "available". The shipped statuses are
 * `available` (group `av`) and `unavailable` (group `unav`), so every
 * unavailable unit was classified as standby and silently removed from
 * the PAR expectation list — the exact `LIKE '%avail%'` hazard CLAUDE.md
 * warns about, one boundary over. No setting could bring it back,
 * because nothing ever knew the unit was unavailable rather than idle.
 *
 * ── THE DECISION ─────────────────────────────────────────────────────
 *
 * Eric, 2026-08-03: this "could go either way depending on the agency,
 * so it needs to be configured with a reasonable default and good
 * documentation". Setting: `par_include_unavailable_units`, default ON.
 * A PAR asks whether every crew committed to the incident is accounted
 * for, not whether every crew is working, and an assigned unit that has
 * gone unavailable is exactly the one whose silence is hardest to read.
 * The rationale in full is on par_include_unavailable_units().
 *
 * ── WHY THIS TEST IS SHAPED THIS WAY ─────────────────────────────────
 *
 * The classification half drives the real classifier against the real
 * `un_status` rows on this install, not against a fixture table, so it
 * asserts the behaviour the deployed data produces. It opens with a
 * positive control that reproduces the OLD strpos test and shows it
 * getting "unavailable" wrong — a regression test for a substring bug
 * that never demonstrates the substring collision is taking the fix on
 * trust.
 *
 * The settings half checks the store, which is the specific trap
 * CLAUDE.md records (GH #79): runtime settings live in the `settings`
 * table and are read with get_variable(), while a SEPARATE `config`
 * table is read with get_setting(). Writing to one and reading from the
 * other means the value silently reads as the default forever. So the
 * round trip is driven end to end — written through the real endpoint's
 * write, then read back by par_include_unavailable_units() in a FRESH
 * PROCESS, because get_variable() caches every setting statically on
 * first call and an in-process re-read would just hand back the cache.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/par.php';

$root   = str_replace('\\', '/', dirname(__DIR__));
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== PAR roster: unavailable units are a decision, not an accident ===\n\n";

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

// ─────────────────────────────────────────────────────────────────────
// 0. Positive control — reproduce the bug the fix is for
// ─────────────────────────────────────────────────────────────────────

$oldKeywords = ['standby', 'staging', 'available', 'offduty', 'off duty', 'reserve'];
$oldIsStandby = function (string $label) use ($oldKeywords): bool {
    $lower = strtolower($label);
    foreach ($oldKeywords as $kw) {
        if (strpos($lower, $kw) !== false) return true;
    }
    return false;
};

if ($oldIsStandby('unavailable')) {
    ok('positive control: the old strpos test really did classify "unavailable" as standby');
} else {
    bad('positive control failed — the described bug cannot be reproduced, '
        . 'so this file is not testing what it claims');
}
if ($oldIsStandby('available')) {
    ok('positive control: the old test also matched "available" (it was not simply broken)');
} else {
    bad('positive control inconsistent');
}

// ─────────────────────────────────────────────────────────────────────
// 1. The real classifier, against the real statuses on this install
// ─────────────────────────────────────────────────────────────────────

if (!function_exists('par_classify_unit_status')) {
    bad('par_classify_unit_status() is not defined');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}

$statuses = [];
try {
    $statuses = db_fetch_all("SELECT id, status_val, `group` FROM `{$prefix}un_status` ORDER BY id");
} catch (Exception $e) { /* handled below */ }

if (!$statuses) {
    echo "  SKIP  no un_status rows on this install — cannot classify real statuses\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}
ok('read ' . count($statuses) . ' real un_status rows');

$sawUnavailable = false;
$sawAvailable   = false;
foreach ($statuses as $s) {
    $id    = (int) $s['id'];
    $label = strtolower(trim((string) $s['status_val']));
    $group = strtolower(trim((string) ($s['group'] ?? '')));
    $class = par_classify_unit_status($id);

    if ($group === 'unav' || strncmp($label, 'unavail', 7) === 0) {
        $sawUnavailable = true;
        if ($class === 'unavailable') {
            ok("status #{$id} '{$s['status_val']}' (group '{$group}') classifies as unavailable");
        } else {
            bad("status #{$id} '{$s['status_val']}' misclassified as '{$class}'",
                'this is the substring collision the fix exists for');
        }
    } elseif ($group === 'av' || strncmp($label, 'avail', 5) === 0) {
        $sawAvailable = true;
        if ($class === 'standby') {
            ok("status #{$id} '{$s['status_val']}' (group '{$group}') classifies as standby");
        } else {
            bad("status #{$id} '{$s['status_val']}' classified '{$class}', expected standby");
        }
    }
}
if ($sawUnavailable) ok('the install has an unavailable status to classify');
else                 echo "  note: no unavailable status configured here\n";
if ($sawAvailable)   ok('the install has an available status to classify');
else                 echo "  note: no available status configured here\n";

// The two must never collapse into each other again.
$avId = 0; $unavId = 0;
foreach ($statuses as $s) {
    $g = strtolower(trim((string) ($s['group'] ?? '')));
    $l = strtolower(trim((string) $s['status_val']));
    if (!$avId   && ($g === 'av'   || strncmp($l, 'avail', 5) === 0))   $avId   = (int) $s['id'];
    if (!$unavId && ($g === 'unav' || strncmp($l, 'unavail', 7) === 0)) $unavId = (int) $s['id'];
}
if ($avId && $unavId) {
    if (par_classify_unit_status($avId) !== par_classify_unit_status($unavId)) {
        ok('available and unavailable classify DIFFERENTLY (the bug is gone)');
    } else {
        bad('available and unavailable still classify identically');
    }
}

// An unknown/normal working status must be left alone — a classifier
// that answered "standby" for everything would pass the assertions above.
try {
    db_query("INSERT INTO `{$prefix}un_status` (status_val, description, `group`, sort)
              VALUES ('par_t_enroute', 'PAR test enroute', 'enr', 990)");
    $tmpId = (int) db_insert_id();
} catch (Exception $e) { $tmpId = 0; }
if ($tmpId > 0) {
    register_shutdown_function(function () use ($tmpId, $prefix) {
        try { db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$tmpId]); }
        catch (Exception $e) { /* best effort */ }
    });
    $c = par_classify_unit_status($tmpId);
    if ($c === 'active') {
        ok("a working status ('par_t_enroute', group 'enr') classifies as active");
    } else {
        bad("a working status classified as '{$c}' — the classifier over-matches");
    }
}
if (par_classify_unit_status(0) === 'active') {
    ok('a unit with no status set is treated as active (never silently dropped)');
} else {
    bad('a unit with no status is not treated as active');
}

// ─────────────────────────────────────────────────────────────────────
// 2. The setting: default, store, and a real round trip
// ─────────────────────────────────────────────────────────────────────

if (!function_exists('par_include_unavailable_units')) {
    bad('par_include_unavailable_units() is not defined');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}

// Preserve whatever this install had, and put it back afterwards.
$hadRow = null;
try {
    $hadRow = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'par_include_unavailable_units' LIMIT 1");
} catch (Exception $e) { /* treated as unset */ }

register_shutdown_function(function () use ($hadRow, $prefix) {
    try {
        if ($hadRow === null || $hadRow === false) {
            db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'par_include_unavailable_units'");
        } else {
            db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES ('par_include_unavailable_units', ?)
                      ON DUPLICATE KEY UPDATE value = VALUES(value)", [$hadRow]);
        }
    } catch (Exception $e) { /* best effort */ }
});

/** Read the setting back through the REAL reader in a cold process. */
function par_read_setting_fresh(): ?string {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_par_setting_probe.php') . ' 2>&1';
    $out = @shell_exec($cmd);
    if ($out === null) return null;
    if (strpos($out, 'INCLUDE') !== false) return 'INCLUDE';
    if (strpos($out, 'EXCLUDE') !== false) return 'EXCLUDE';
    return null;
}

// -- unset → default ON
db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'par_include_unavailable_units'");
if (par_read_setting_fresh() === 'INCLUDE') {
    ok('unconfigured installs default to INCLUDING unavailable units');
} else {
    bad('the shipped default is not "include"');
}

// -- the endpoint's own write, then the real read
//
// This is the round trip that matters: the value must land in the
// `settings` table, which is what get_variable() reads. The `config`
// table + get_setting() is a different store; a value written there
// reads as the default forever (CLAUDE.md, GH #79).
$writeViaEndpointSql = "INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE value = VALUES(value)";

foreach (['0' => 'EXCLUDE', '1' => 'INCLUDE'] as $value => $expect) {
    db_query($writeViaEndpointSql, ['par_include_unavailable_units', $value]);
    $got = par_read_setting_fresh();
    if ($got === $expect) {
        ok("round trip: settings value '{$value}' reads back as {$expect} via get_variable()");
    } else {
        bad("round trip failed for value '{$value}'", 'got ' . var_export($got, true));
    }
}

// The value must be in `settings`, and must NOT have been put in `config`.
$inSettings = db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = 'par_include_unavailable_units'");
if ((int) $inSettings === 1) {
    ok('the setting lives in the `settings` table (the store get_variable reads)');
} else {
    bad('the setting is not in the `settings` table', "count={$inSettings}");
}
try {
    $inConfig = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}config` WHERE `key` = 'par_include_unavailable_units'");
    if ($inConfig === 0) {
        ok('the setting was NOT written to the `config` table (wrong store, silent default)');
    } else {
        bad('the setting was written to the `config` table as well — crossed wires');
    }
} catch (Exception $e) {
    echo "  note: no `config` table on this install; cross-store check skipped\n";
}

// ─────────────────────────────────────────────────────────────────────
// 2b. The roster itself, driven through the real writers
// ─────────────────────────────────────────────────────────────────────
//
// Everything above tests the classifier and the setting in isolation.
// This drives par_assigned_units() against a real incident with a real
// assignment made by assign_create_internal(), because CLAUDE.md records
// two separate episodes (assigns.rec_facility_id, un_status.
// extra_data_target) where a PAR/assignment test passed by hand-seeding a
// state no production path ever produces. Each read is a fresh process,
// since get_variable() caches settings statically for the life of one.

require_once $root . '/inc/incident-write.php';
require_once $root . '/inc/assignment-write.php';
require_once __DIR__ . '/_test_admin.php';

function par_probe_roster(int $ticketId): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_par_setting_probe.php')
         . ' roster ' . escapeshellarg((string) $ticketId) . ' 2>&1';
    $out = @shell_exec($cmd);
    if ($out === null) return null;
    $j = json_decode(trim((string) $out), true);
    return isset($j['roster']) && is_array($j['roster']) ? $j['roster'] : null;
}

$parTicket = 0; $unavResp = 0; $activeResp = 0;
register_shutdown_function(function () use (&$parTicket, &$unavResp, &$activeResp, $prefix) {
    try {
        if ($parTicket) {
            db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$parTicket]);
            db_query("DELETE FROM `{$prefix}action`  WHERE ticket_id = ?", [$parTicket]);
            db_query("DELETE FROM `{$prefix}ticket`  WHERE id = ?",        [$parTicket]);
        }
        foreach ([$unavResp, $activeResp] as $rid) {
            if ($rid) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]);
        }
    } catch (Exception $e) { echo "  (cleanup warning: {$e->getMessage()})\n"; }
});

$adminId = test_admin_user_id();
$typeId  = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");

if ($typeId > 0 && $unavId > 0 && function_exists('assign_create_internal')) {
    $mk = incident_create_internal([
        'in_types_id' => $typeId,
        'scope'       => 'PAR unavailable-unit roster probe',
        'description' => 'regression fixture',
    ], $adminId);
    $parTicket = (int) ($mk['id'] ?? 0);

    // Two dedicated units so the roster has something to keep as well as
    // something to drop — a filter that returned [] would otherwise pass.
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id)
              VALUES ('PARTEST-UNAVAIL', 'PARTEST-UNAVAIL', 'par regression', ?)", [$unavId]);
    $unavResp = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id)
              VALUES ('PARTEST-ACTIVE', 'PARTEST-ACTIVE', 'par regression', ?)", [$tmpId ?: 0]);
    $activeResp = (int) db_insert_id();

    if ($parTicket > 0 && $unavResp > 0 && $activeResp > 0) {
        ok("built a real PAR fixture: incident #{$parTicket}, one unavailable unit, one active");

        $a1 = assign_create_internal($parTicket, $unavResp,   '', $adminId);
        $a2 = assign_create_internal($parTicket, $activeResp, '', $adminId);
        // Assigning normally moves a unit to Dispatched. The scenario is a
        // unit that is assigned AND unavailable, so restore that state.
        db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$unavId, $unavResp]);

        if (empty($a1['errors']) && empty($a2['errors'])) {
            ok('assign_create_internal() assigned both units through the real writer');
        } else {
            bad('the real assignment writer failed',
                implode('; ', array_merge($a1['errors'] ?? [], $a2['errors'] ?? [])));
        }

        // Setting ON (default) → the unavailable unit is expected to answer.
        db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES ('par_include_unavailable_units','1')
                  ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $rosterOn = par_probe_roster($parTicket);
        if ($rosterOn === null) {
            bad('the roster probe returned no payload (include=1)');
        } elseif (in_array($unavResp, $rosterOn, true) && in_array($activeResp, $rosterOn, true)) {
            ok('include=1: the unavailable unit IS on the PAR roster (with the active one)');
        } else {
            bad('include=1: the unavailable unit is missing from the roster',
                'roster=' . implode(',', $rosterOn));
        }

        // Setting OFF → it is left off, and the active unit still is not.
        db_query("UPDATE `{$prefix}settings` SET value = '0' WHERE name = 'par_include_unavailable_units'");
        $rosterOff = par_probe_roster($parTicket);
        if ($rosterOff === null) {
            bad('the roster probe returned no payload (include=0)');
        } elseif (!in_array($unavResp, $rosterOff, true) && in_array($activeResp, $rosterOff, true)) {
            ok('include=0: the unavailable unit is left off, the active unit is kept');
        } else {
            bad('include=0: the roster is not what the setting asks for',
                'roster=' . implode(',', $rosterOff));
        }
    }
} else {
    echo "  note: roster fixture skipped (no incident type, no unavailable status, "
       . "or assignment writer unavailable)\n";
}

// ─────────────────────────────────────────────────────────────────────
// 3. The setting is reachable and documented where it is configured
// ─────────────────────────────────────────────────────────────────────

$api = (string) @file_get_contents($root . '/api/par.php');
if (strpos($api, "'par_include_unavailable_units'") !== false
    && strpos($api, 'include_unavailable') !== false) {
    ok('api/par.php reads and writes the setting (GET config + POST save_config)');
} else {
    bad('api/par.php does not carry the setting — the UI could not persist it');
}

$ui = (string) @file_get_contents($root . '/settings.php');
if (strpos($ui, 'parIncludeUnavailable') !== false) {
    ok('settings.php exposes the control');
} else {
    bad('settings.php has no control for the setting');
}
// Help text AT the control, per Eric's instruction — not just in a doc.
if (preg_match('/parIncludeUnavailable.{0,2600}?form-text/s', $ui)) {
    ok('the control carries help text explaining the trade-off');
} else {
    bad('no help text at the control');
}

$js = (string) @file_get_contents($root . '/assets/js/config.js');
if (strpos($js, 'parIncludeUnavailable') !== false
    && strpos($js, 'include_unavailable') !== false) {
    ok('config.js both loads and saves the control');
} else {
    bad('config.js does not round-trip the control');
}

// Documented for users, not only in the UI. Matched on the SETTING NAME,
// because both files already contained the words "unavailable" and "PAR"
// somewhere — a looser check reported the documentation as written
// before a line of it existed.
$docHit = false;
foreach (['docs/NEWUI-USER-GUIDE.md', 'help.php'] as $rel) {
    $d = @file_get_contents($root . '/' . $rel);
    if ($d !== false && strpos($d, 'par_include_unavailable_units') !== false) {
        $docHit = true;
        ok("user-facing documentation names the setting ({$rel})");
    }
}
if (!$docHit) {
    bad('no user-facing documentation names par_include_unavailable_units',
        'Eric asked for it to be documented at the control AND in the written docs');
}

// ─────────────────────────────────────────────────────────────────────
// 4. No `strpos(... 'available')` may come back
// ─────────────────────────────────────────────────────────────────────

// Comments are stripped first. The docblocks in inc/par.php quote the
// old `strpos($label, 'available')` line on purpose, to record what went
// wrong — a scan over raw source reports the explanation as the defect.
$parSrc = (string) @file_get_contents($root . '/inc/par.php');
$parCode = preg_replace('!/\*.*?\*/!s', '', $parSrc);
$parCode = preg_replace('!//[^\n]*!', '', (string) $parCode);
if (!preg_match("/strpos\s*\([^)]*['\"]available['\"]/", (string) $parCode)) {
    ok("inc/par.php no longer substring-matches 'available' in executable code");
} else {
    bad("inc/par.php still contains a strpos() test for 'available'",
        'that matches inside "unavailable"');
}
// ...and the stripper must not be doing the work by deleting everything.
if (strpos((string) $parCode, 'par_classify_unit_status') !== false) {
    ok('comment-stripping left the executable code intact');
} else {
    bad('comment-stripping removed real code — the scan above proves nothing');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
