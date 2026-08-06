<?php
/**
 * Phase 132 (2026-08-03) — Structured incident disposition, Step 3:
 * Settings panel admin CRUD. See
 * specs/phase-132-incident-disposition/{spec.md,plan.md,tasks.md}.
 * Steps 1 (sql/run_phase132_disposition.php,
 * tests/test_phase132_migration.php) and 2 (inc/incident-write.php,
 * api/incident-update.php, tests/test_phase132_writer.php) are untouched
 * and separate.
 *
 * Everything under test here drives the REAL functions
 * api/dispositions.php calls — inc/disposition-admin.php's
 * disposition_list_internal() / disposition_save_internal() /
 * disposition_set_active_internal() / disposition_set_enforcement_
 * internal() — the same split Step 2 used so its writer test could
 * exercise incident_set_disposition_internal() directly (CLAUDE.md:
 * "reproduce bugs through the REAL creation path", not hand-seeded
 * state). api/dispositions.php itself is a thin wrapper (auth/RBAC/CSRF
 * + JSON shaping) with nothing left to test independently of those
 * functions except its RBAC/CSRF wiring, which section 5 below checks
 * both statically (the guard text is present) and functionally (the
 * permission genuinely denies an unprivileged session) — mirroring
 * tests/test_chat_csrf_and_rbac.php's established static-plus-live-probe
 * shape for this codebase, and tests/test_phase132_migration.php's
 * $_SESSION + rbac_clear_cache() pattern for the functional half.
 *
 * WHY A COLD-PROCESS PROBE FOR THE ENFORCEMENT-SETTING READ-BACK
 * (tests/_p132_settings_probe.php): get_variable() caches every
 * `settings` row in a function-static array on its FIRST call and never
 * re-reads the table for the rest of the process. Writing
 * disposition_required_on_close via disposition_set_enforcement_
 * internal() and then re-reading it with get_variable() IN THIS SAME
 * PROCESS would silently return whatever was cached at the first call —
 * not what was just written. Same problem, same fix, as
 * tests/_p132_probe.php (Step 2) and tests/_par_setting_probe.php
 * (Phase 129): shell out to a fresh `php` interpreter per read.
 *
 * Leaves disposition_required_on_close reset to '0' on every exit path
 * (register_shutdown_function — the only mechanism that also fires on a
 * fatal error partway through) so this file never poisons every OTHER
 * test's close behaviour for the rest of the shared dev DB's lifetime.
 * This mirrors tests/test_phase132_writer.php's identical concern over
 * the SAME setting — both files touch it, so both reset it defensively.
 *
 * Usage: php tests/test_phase132_settings_panel.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/disposition-admin.php';
require_once __DIR__ . '/../inc/incident-write.php';   // incident_set_disposition_internal() — section 3 fixture
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Phase 132 — Incident disposition Settings panel (Step 3) ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    echo "\nSKIP: no database available — this test needs one\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$userId = test_admin_user_id();

// ─────────────────────────────────────────────────────────────────────────
// Setup: sweep any leftovers from an aborted prior run.
// ─────────────────────────────────────────────────────────────────────────
$DISP_MARK  = '__p132s_test_';
$ORG_MARK   = '__P132S Test Org';
$SCOPE_MARK = '__P132S_ Test Incident';

foreach (db_fetch_all("SELECT `id` FROM `{$prefix}ticket` WHERE `scope` = ?", [$SCOPE_MARK]) as $old) {
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$old['id']]);
    db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$old['id']]);
    db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ticket' AND `target_id` = ?",
        [(string) $old['id']]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$old['id']]);
}
db_query("DELETE FROM `{$prefix}ticket_disposition` WHERE `code` LIKE ?", [$DISP_MARK . '%']);
db_query("DELETE FROM `{$prefix}organizations` WHERE `name` = ?", [$ORG_MARK]);

$typeId = (int) db_fetch_value("SELECT `id` FROM `{$prefix}in_types` ORDER BY `id` LIMIT 1");
chk($typeId > 0, 'fixture: an in_types row exists to build a test ticket against');

/** Direct-INSERT ticket fixture (mirrors tests/test_phase132_writer.php). */
function p132s_mk_ticket(int $status): int {
    global $prefix, $typeId, $SCOPE_MARK;
    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
         `date`, `problemstart`, `_by`)
         VALUES (?, ?, 0, ?, 'phase132 settings-panel test fixture', ?, ?, 1)",
        [$typeId, $status, $SCOPE_MARK, $now, $now]
    );
    return (int) db_insert_id();
}

register_shutdown_function(function () {
    global $prefix;
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('disposition_required_on_close', '0')
             ON DUPLICATE KEY UPDATE `value` = '0'"
        );
    } catch (Throwable $e) { /* best effort — last line of defense */ }
});

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. CRUD round-trip through the real writer (disposition_save_internal) --\n";
// ─────────────────────────────────────────────────────────────────────────

$orgId = 0;
try {
    db_query("INSERT INTO `{$prefix}organizations` (`name`, `short_name`, `active`, `sort_order`) VALUES (?,?,1,999)",
        [$ORG_MARK, 'P132S']);
    $orgId = (int) db_insert_id();
} catch (Throwable $e) { /* organizations table may be absent on an old install — org_id case just skips */ }

$createRes = disposition_save_internal([
    'status_val'       => 'P132S Test Disposition',
    'description'      => 'created by test_phase132_settings_panel.php',
    'code'              => $DISP_MARK . 'alpha',
    'discipline'        => 'fire',
    'org_id'            => $orgId > 0 ? $orgId : null,
    'sort_order'        => 42,
    'requires_comment'  => 1,
], $userId);
chk(!empty($createRes['success']), 'create succeeds via disposition_save_internal()', var_export($createRes, true));
$dispId = (int) ($createRes['id'] ?? 0);
chk($dispId > 0, 'create returns a positive id');

$list1 = disposition_list_internal();
$found = null;
foreach ($list1['dispositions'] as $row) { if ((int) $row['id'] === $dispId) { $found = $row; break; } }
chk($found !== null, 'GET-equivalent list (disposition_list_internal) shows the new row');
if ($found !== null) {
    chk($found['status_val'] === 'P132S Test Disposition', 'label round-trips');
    chk($found['code'] === $DISP_MARK . 'alpha', 'code round-trips');
    chk($found['discipline'] === 'fire', 'discipline round-trips');
    chk((int) $found['sort_order'] === 42, 'sort_order round-trips');
    chk((int) $found['requires_comment'] === 1, 'requires_comment round-trips');
    chk((int) $found['active'] === 1, 'a newly-created disposition is active');
    if ($orgId > 0) {
        chk((int) $found['org_id'] === $orgId, 'org_id round-trips to the created org (not global)');
    }
}

// Create a duplicate (same code, same org scope) — must be rejected at the
// application level (no DB-level UNIQUE key exists — Phase 129's lesson,
// see inc/disposition-admin.php's docblock).
$dupRes = disposition_save_internal([
    'status_val' => 'P132S Duplicate Attempt',
    'code'        => $DISP_MARK . 'alpha',
    'org_id'      => $orgId > 0 ? $orgId : null,
], $userId);
chk(empty($dupRes['success']), 'a duplicate (code, org_id) pair is rejected', var_export($dupRes, true));

// Update the label/description/discipline/sort/requires_comment.
$updateRes = disposition_save_internal([
    'id'                => $dispId,
    'status_val'        => 'P132S Renamed',
    'description'       => 'renamed by test',
    'code'              => $DISP_MARK . 'alpha',   // unchanged, harmless resubmission
    'discipline'        => 'ems',
    'org_id'            => $orgId > 0 ? $orgId : null,
    'sort_order'        => 7,
    'requires_comment'  => 0,
], $userId);
chk(!empty($updateRes['success']), 'update succeeds', var_export($updateRes, true));

$afterUpdate = db_fetch_one("SELECT * FROM `{$prefix}ticket_disposition` WHERE `id` = ?", [$dispId]);
chk($afterUpdate['status_val'] === 'P132S Renamed', 'label was updated');
chk($afterUpdate['discipline'] === 'ems', 'discipline was updated');
chk((int) $afterUpdate['sort_order'] === 7, 'sort_order was updated');
chk((int) $afterUpdate['requires_comment'] === 0, 'requires_comment was updated');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Code is IMMUTABLE on update --\n";
// ─────────────────────────────────────────────────────────────────────────

$changeCodeRes = disposition_save_internal([
    'id'         => $dispId,
    'status_val' => 'P132S Renamed Again',
    'code'       => $DISP_MARK . 'a_completely_different_code',
], $userId);
chk(!empty($changeCodeRes['success']),
    'update "succeeds" even when a different code is submitted (silently ignored, not rejected — see docblock)',
    var_export($changeCodeRes, true));

$afterCodeAttempt = db_fetch_one("SELECT `code` FROM `{$prefix}ticket_disposition` WHERE `id` = ?", [$dispId]);
chk($afterCodeAttempt['code'] === $DISP_MARK . 'alpha',
    'the STORED code is unchanged despite the update request carrying a different one',
    (string) $afterCodeAttempt['code']);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Retiring: active flips to 0, row is NOT deleted, existing incident reference survives --\n";
// ─────────────────────────────────────────────────────────────────────────

$retireTargetRes = disposition_save_internal([
    'status_val' => 'P132S Retire Target',
    'code'        => $DISP_MARK . 'retiree',
], $userId);
chk(!empty($retireTargetRes['success']), 'fixture: second disposition created for the retirement test');
$retireId = (int) ($retireTargetRes['id'] ?? 0);

$t1 = p132s_mk_ticket(2); // open
$setRes = incident_set_disposition_internal($t1, $retireId, $userId);
chk(!empty($setRes['updated']), 'an active disposition can be assigned to a real incident', var_export($setRes, true));

$retireRes = disposition_set_active_internal($retireId, false, $userId);
chk(!empty($retireRes['success']) && (int) ($retireRes['active'] ?? 1) === 0,
    'disposition_set_active_internal(..., false) succeeds and reports active=0', var_export($retireRes, true));

$afterRetireRow = db_fetch_one("SELECT `active` FROM `{$prefix}ticket_disposition` WHERE `id` = ?", [$retireId]);
chk($afterRetireRow !== null, 'the row still EXISTS after retiring — never a DELETE');
chk((int) $afterRetireRow['active'] === 0, 'active is 0 in the database after retiring');

// The GET-equivalent list still shows the (now retired) row — the admin
// panel displays both active and retired dispositions.
$list2 = disposition_list_internal();
$stillListed = false;
foreach ($list2['dispositions'] as $row) { if ((int) $row['id'] === $retireId) { $stillListed = true; break; } }
chk($stillListed, 'the retired disposition is still present in disposition_list_internal() (shown, not hidden)');

// The incident that already carries it reads back unchanged.
$ticketDispAfter = db_fetch_value("SELECT `disposition_id` FROM `{$prefix}ticket` WHERE `id` = ?", [$t1]);
chk((int) $ticketDispAfter === $retireId,
    'the incident STILL reads back the now-retired disposition, unchanged by retiring it');

// A NEW assignment of the retired disposition is refused — this is
// incident_set_disposition_internal()'s job (Step 2), re-confirmed here
// through the retirement path this panel exposes.
$t2 = p132s_mk_ticket(2);
$setAfterRetire = incident_set_disposition_internal($t2, $retireId, $userId);
chk(empty($setAfterRetire['updated']),
    'a retired disposition cannot be newly assigned to a DIFFERENT incident',
    var_export($setAfterRetire, true));

// Reactivate — flips back to 1, the row is offered again.
$reactivateRes = disposition_set_active_internal($retireId, true, $userId);
chk(!empty($reactivateRes['success']) && (int) ($reactivateRes['active'] ?? 0) === 1,
    'disposition_set_active_internal(..., true) reactivates it', var_export($reactivateRes, true));
$setAfterReactivate = incident_set_disposition_internal($t2, $retireId, $userId);
chk(!empty($setAfterReactivate['updated']),
    'after reactivating, the disposition CAN be newly assigned again', var_export($setAfterReactivate, true));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. set_enforcement writes disposition_required_on_close via the settings table --\n";
// ─────────────────────────────────────────────────────────────────────────
// Verified with get_variable() — the SAME reader the close-enforcement
// gate (Step 2) uses — via a cold subprocess, since get_variable() caches
// per-process and a same-process re-read would prove nothing (see file
// docblock).

function p132s_probe_get_enforcement(): ?string {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_p132_settings_probe.php')
         . ' get_enforcement 2>&1';
    $out = @shell_exec($cmd);
    if ($out === null) return null;
    $j = json_decode(trim((string) $out), true);
    if (!is_array($j) || !array_key_exists('value', $j)) return null;
    return $j['value'] === false ? null : (string) $j['value'];
}

$setOnRes = disposition_set_enforcement_internal('1', $userId);
chk(!empty($setOnRes['success']) && $setOnRes['value'] === '1',
    'disposition_set_enforcement_internal(\'1\', ...) succeeds', var_export($setOnRes, true));

$readBack1 = p132s_probe_get_enforcement();
chk($readBack1 === '1',
    'a FRESH process reading get_variable(\'disposition_required_on_close\') sees 1',
    var_export($readBack1, true));

$setOffRes = disposition_set_enforcement_internal('0', $userId);
chk(!empty($setOffRes['success']) && $setOffRes['value'] === '0',
    'disposition_set_enforcement_internal(\'0\', ...) succeeds', var_export($setOffRes, true));

$readBack2 = p132s_probe_get_enforcement();
chk($readBack2 === '0',
    'a FRESH process reading get_variable(\'disposition_required_on_close\') sees 0 after flipping back',
    var_export($readBack2, true));

// Row is a genuine UPDATE in place (Phase 24 UNIQUE key on settings.name),
// not a second/duplicate row.
$settingsRowCount = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = 'disposition_required_on_close'");
chk($settingsRowCount === 1,
    'exactly one settings row exists for disposition_required_on_close (no duplicate rows)',
    (string) $settingsRowCount);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. RBAC: a session without action.manage_dispositions is refused --\n";
// ─────────────────────────────────────────────────────────────────────────

// (a) Static: api/dispositions.php actually gates on this exact
// permission and answers 403 — mirrors tests/test_chat_csrf_and_rbac.php's
// established static-analysis pattern for this codebase.
$endpointSrc = @file_get_contents(__DIR__ . '/../api/dispositions.php');
chk($endpointSrc !== false, 'api/dispositions.php is readable');
if ($endpointSrc !== false) {
    chk(strpos($endpointSrc, "rbac_can('action.manage_dispositions')") !== false,
        'the endpoint calls rbac_can(\'action.manage_dispositions\')');
    chk((bool) preg_match(
        "/if\\s*\\(!rbac_can\\('action\\.manage_dispositions'\\)\\)[\\s\\S]{0,120}json_error\\([^;]*403\\)/",
        $endpointSrc),
        'a failed check answers 403, close to the rbac_can() call');
    chk(strpos($endpointSrc, "csrf_verify(") !== false,
        'the endpoint verifies a CSRF token on POST');
    // Gated identically on GET and POST — the RBAC check must run before
    // the request-method dispatch (Phase 128: a page gate and an API gate
    // naming different permissions is exactly the bug that left a
    // your deployment Org Admin locked out of every report).
    $rbacPos   = strpos($endpointSrc, "rbac_can('action.manage_dispositions')");
    $methodPos = strpos($endpointSrc, '$method = $_SERVER');
    chk($rbacPos !== false && $methodPos !== false && $rbacPos < $methodPos,
        'the RBAC check runs BEFORE the GET/POST dispatch (applies to both)');
}

// (b) Functional: the permission itself genuinely denies an unauthenticated
// / unprivileged session — mirrors test_phase132_migration.php's
// $_SESSION + rbac_clear_cache() pattern. No session at all is the
// simplest "a session without the permission" case and needs no
// fabricated non-admin test account.
$origSessionUser = $_SESSION['user_id'] ?? null;
unset($_SESSION['user_id']);
rbac_clear_cache();
chk(rbac_can('action.manage_dispositions') === false,
    'rbac_can(\'action.manage_dispositions\') is false with no session user — the endpoint would 403');
if ($origSessionUser !== null) { $_SESSION['user_id'] = $origSessionUser; }
rbac_clear_cache();

// And the admin session this test has been using throughout DOES hold it —
// confirms the gate is not simply broken in a way that denies everyone.
$_SESSION['user_id'] = $userId;
rbac_clear_cache();
chk(rbac_can('action.manage_dispositions') === true,
    'rbac_can(\'action.manage_dispositions\') is true for the real admin session used by this test');
if ($origSessionUser === null) unset($_SESSION['user_id']); else $_SESSION['user_id'] = $origSessionUser;
rbac_clear_cache();

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Settings panel + config.js + sidebar wiring (static) --\n";
// ─────────────────────────────────────────────────────────────────────────

$settingsSrc = @file_get_contents(__DIR__ . '/../settings.php');
chk($settingsSrc !== false && strpos($settingsSrc, 'id="panel-incident-dispositions"') !== false,
    'settings.php has panel-incident-dispositions');
chk($settingsSrc !== false && strpos($settingsSrc, 'id="dispRequiredOnClose"') !== false,
    'settings.php has the enforcement toggle in the same panel');
chk($settingsSrc !== false && strpos($settingsSrc, 'id="dispCode"') !== false
    && strpos($settingsSrc, 'id="dispDiscipline"') !== false
    && strpos($settingsSrc, 'id="dispOrgId"') !== false,
    'settings.php has code/discipline/org_id fields on the edit form');

$jsSrc = @file_get_contents(__DIR__ . '/../assets/js/config.js');
chk($jsSrc !== false && strpos($jsSrc, 'function loadDispositions()') !== false,
    'config.js defines loadDispositions()');
chk($jsSrc !== false && strpos($jsSrc, "tab === 'incident-dispositions') loadDispositions()") !== false,
    'onPanelActivated() routes the incident-dispositions tab to loadDispositions()');
chk($jsSrc !== false && strpos($jsSrc, "apiPostDirect('dispositions'") !== false,
    'config.js posts writes through apiPostDirect(\'dispositions\', ...) (carries CSRF automatically)');

$sidebarSrc = @file_get_contents(__DIR__ . '/../inc/config-sidebar.php');
chk($sidebarSrc !== false && strpos($sidebarSrc, "_cfg_tab('incident-dispositions'") !== false,
    'inc/config-sidebar.php registers the incident-dispositions tab');

// ─────────────────────────────────────────────────────────────────────────
// Cleanup + final enforcement reset (register_shutdown_function is the
// backstop; do it explicitly here too on the normal-exit path).
// ─────────────────────────────────────────────────────────────────────────
disposition_set_enforcement_internal('0', $userId);

foreach (db_fetch_all("SELECT `id` FROM `{$prefix}ticket` WHERE `scope` = ?", [$SCOPE_MARK]) as $t) {
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$t['id']]);
    db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$t['id']]);
    db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ticket' AND `target_id` = ?",
        [(string) $t['id']]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$t['id']]);
}
db_query("DELETE FROM `{$prefix}ticket_disposition` WHERE `code` LIKE ?", [$DISP_MARK . '%']);
if ($orgId > 0) {
    db_query("DELETE FROM `{$prefix}organizations` WHERE `id` = ?", [$orgId]);
}

$stillEnforced = db_fetch_value(
    "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'disposition_required_on_close'");
chk((string) $stillEnforced === '0',
    'disposition_required_on_close was reset to 0 before exit — later tests are not affected',
    var_export($stillEnforced, true));

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
