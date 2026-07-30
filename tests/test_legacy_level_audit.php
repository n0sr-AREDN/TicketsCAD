<?php
/**
 * Page/API authorisation-split regression gate (2026-07-29).
 *
 * Runs tools/legacy_level_audit.php — which flags api/ endpoints that
 * decide authorisation from the LEGACY `user.level` column alone — and
 * fails on any finding not in tools/legacy_level_baseline.txt.
 *
 * This is the third of the field/gate mismatch gates:
 *   test_schema_audit.php        SQL        vs the real database schema
 *   test_api_contract_audit.php  JavaScript vs what the API emits
 *   test_legacy_level_audit.php  API gate   vs the page's RBAC gate
 *
 * The bug it exists to prevent: reports.php gated on
 * rbac_require_screen('screen.reports') while api/reports.php gated on
 * `$_SESSION['level'] > 1`. On your deployment the Org Admin was role_id=2,
 * user.level=4 — she passed the page and the API refused every report,
 * and the org-scope filter written for exactly her case was unreachable.
 *
 * If this test fails: gate on is_admin() / rbac_can('...'). Phase 128
 * (2026-07-29) removed the OR-fallback allowance — a legacy level is not
 * acceptable even as a second opinion, and the scope widened from api/
 * to pages, inc/, tools/, proxy/, sql/ and assets/js. The only exempt
 * paths are the one-time v3 -> v4 migration bridge.
 *
 * Also asserts the shipped gate still detects the original bug shape on
 * every surface, and does NOT fire on zoomLevel / severityLevel, so the
 * audit can neither rot into a no-op nor cry wolf into being baselined.
 *
 * Usage: php tests/test_legacy_level_audit.php
 */
$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;
$tool = $base . '/tools/legacy_level_audit.php';

// Bootstrap the DB BEFORE any output — config.php sets session ini values
// and warns loudly if headers/output already went out.
$dbReady = false;
if (is_file($base . '/config.php')) {
    try {
        require_once $base . '/config.php';
        require_once $base . '/inc/rbac.php';
        $pfx = $GLOBALS['db_prefix'] ?? '';
        db_fetch_value("SELECT 1 FROM `{$pfx}role_permissions` LIMIT 1");
        $dbReady = true;
    } catch (Throwable $e) {
        $dbReady = false;
    }
}

$pass = 0; $fail = 0;
function lla_ok(string $m): void   { global $pass; $pass++; echo "[PASS] $m\n"; }
function lla_bad(string $m, string $extra = ''): void {
    global $fail; $fail++;
    echo "[FAIL] $m" . ($extra !== '' ? " — $extra" : '') . "\n";
}

echo "=== Page/API authorisation-split gate ===\n\n";

if (!is_file($tool)) {
    lla_bad('tools/legacy_level_audit.php present');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
lla_ok('tools/legacy_level_audit.php present');

if (is_file($base . '/tools/legacy_level_baseline.txt')) {
    lla_ok('tools/legacy_level_baseline.txt present');
} else {
    lla_bad('tools/legacy_level_baseline.txt present');
}

// ── 1. No new findings in the tree as it stands ──────────────────────────
$out = [];
exec(escapeshellarg($php) . ' ' . escapeshellarg($tool) . ' 2>&1', $out, $code);
if ($code === 0) {
    lla_ok('no api/ endpoint gates on user.level alone');
} else {
    echo implode("\n", $out) . "\n";
    lla_bad('audit found NEW page/API authorisation splits (see above)');
}

// ── 2. The gate still bites ──────────────────────────────────────────────
// Drop the pre-fix reports.php shape into api/ and confirm a non-zero exit.
$probe = $base . '/api/__legacy_level_probe.php';
$probeSrc = "<?php\n"
    . "// temporary probe written by tests/test_legacy_level_audit.php\n"
    . "\$_probeLevel = (int) (\$_SESSION['level'] ?? 99);\n"
    . "if (\$_probeLevel > 1) {\n"
    . "    json_error('Aggregate reports require admin access', 403);\n"
    . "}\n";
if (@file_put_contents($probe, $probeSrc) === false) {
    lla_bad('could not write probe file', $probe);
} else {
    $pOut = [];
    exec(escapeshellarg($php) . ' ' . escapeshellarg($tool) . ' 2>&1', $pOut, $pCode);
    @unlink($probe);
    $text = implode("\n", $pOut);
    if ($pCode !== 0 && strpos($text, '__legacy_level_probe.php') !== false) {
        lla_ok('audit detects a level-only endpoint gate (the original bug shape)');
    } else {
        lla_bad('audit did NOT detect the planted level-only gate — the gate has rotted',
                'exit ' . $pCode);
    }
    // The probe must not linger and poison later runs.
    if (!file_exists($probe)) lla_ok('probe cleaned up');
    else lla_bad('probe file left behind', $probe);
}

// ── 3. Phase 128: the RBAC OR-fallback form is now REJECTED ──────────────
// Until 2026-07-29 the audit forgave a level comparison whenever an RBAC
// call appeared in the same statement, on the theory that the level was
// "only a fallback for pre-migration installs". It is not a fallback, it
// is a second permission system that can still say yes — and tolerating
// it is what kept the level concept alive through three phases of being
// declared dead. Login now refuses outright on an unmigrated install, so
// there is nothing left for a fallback to protect.
$probe2 = $base . '/api/__legacy_level_ok_probe.php';
$probe2Src = "<?php\n"
    . "// temporary probe written by tests/test_legacy_level_audit.php\n"
    . "\$_probeLevel = (int) (\$_SESSION['level'] ?? 99);\n"
    . "if (!is_admin() && !rbac_can('action.view_audit') && \$_probeLevel > 1) {\n"
    . "    json_error('Admin access required', 403);\n"
    . "}\n";
if (@file_put_contents($probe2, $probe2Src) === false) {
    lla_bad('could not write fallback probe file', $probe2);
} else {
    $qOut = [];
    exec(escapeshellarg($php) . ' ' . escapeshellarg($tool) . ' 2>&1', $qOut, $qCode);
    @unlink($probe2);
    if ($qCode !== 0 && strpos(implode("\n", $qOut), '__legacy_level_ok_probe.php') !== false) {
        lla_ok('an RBAC check with a legacy OR-fallback is REJECTED (Phase 128)');
    } else {
        lla_bad('the RBAC-with-level-fallback form was accepted — the escape hatch is back',
                'exit ' . $qCode);
    }
}

// ── 3b. Pages, shared includes and JS are enforced, not advisory ─────────
// The page half of a page/API split lives in a page. While pages were
// only "advisory", the audit could see one side of a disagreement and
// pass — which is exactly how settings.php kept a bare `level > 1` gate
// after the endpoint half had been fixed.
$surfaces = [
    'page template'   => $base . '/__legacy_level_page_probe.php',
    'shared include'  => $base . '/inc/__legacy_level_inc_probe.php',
];
foreach ($surfaces as $label => $probePath) {
    $src = "<?php\n"
        . "// temporary probe written by tests/test_legacy_level_audit.php\n"
        . "\$userLevel = (int) (\$_SESSION['level'] ?? 99);\n"
        . "if (\$userLevel > 1) { http_response_code(403); exit; }\n";
    if (@file_put_contents($probePath, $src) === false) {
        lla_bad("could not write $label probe", $probePath);
        continue;
    }
    $sOut = [];
    exec(escapeshellarg($php) . ' ' . escapeshellarg($tool) . ' 2>&1', $sOut, $sCode);
    @unlink($probePath);
    ($sCode !== 0 && strpos(implode("\n", $sOut), basename($probePath)) !== false)
        ? lla_ok("a legacy level gate in a $label fails the build")
        : lla_bad("a legacy level gate in a $label was NOT caught", 'exit ' . $sCode);
    if (file_exists($probePath)) lla_bad('probe file left behind', $probePath);
}

// JS: the browser re-deriving authorisation from the legacy column.
$jsProbe = $base . '/assets/js/__legacy_level_probe.js';
$jsSrc = "(function () {\n"
    . "    'use strict';\n"
    . "    var userLevel = parseInt(document.getElementById('userLevel').value, 10);\n"
    . "    var isAdmin = userLevel <= 1;\n"
    . "    return isAdmin;\n"
    . "})();\n";
if (@file_put_contents($jsProbe, $jsSrc) === false) {
    lla_bad('could not write JS probe', $jsProbe);
} else {
    $jOut = [];
    exec(escapeshellarg($php) . ' ' . escapeshellarg($tool) . ' 2>&1', $jOut, $jCode);
    @unlink($jsProbe);
    ($jCode !== 0 && strpos(implode("\n", $jOut), '__legacy_level_probe.js') !== false)
        ? lla_ok('a client-side level gate in assets/js fails the build')
        : lla_bad('a client-side level gate was NOT caught', 'exit ' . $jCode);
}

// ...and the audit must NOT flag unrelated "level" names. A gate that
// cries wolf on zoomLevel gets baselined into uselessness.
$fpProbe = $base . '/assets/js/__legacy_level_falsepos_probe.js';
$fpSrc = "(function () {\n"
    . "    var zoomLevel = 12;\n"
    . "    var severityLevel = 3;\n"
    . "    if (zoomLevel > 10 && severityLevel >= 2) { return true; }\n"
    . "})();\n";
if (@file_put_contents($fpProbe, $fpSrc) !== false) {
    $fOut = [];
    exec(escapeshellarg($php) . ' ' . escapeshellarg($tool) . ' 2>&1', $fOut, $fCode);
    @unlink($fpProbe);
    ($fCode === 0)
        ? lla_ok('zoomLevel / severityLevel comparisons are not mistaken for access control')
        : lla_bad('the audit false-positives on unrelated *Level names', implode("\n", $fOut));
}

// ── 3c. The migration bridge is exempt, and only the bridge ──────────────
// sql/run_rbac_v2.php A9 reads user.level on purpose — that is the whole
// point of a one-time migration. If the exemption list ever grows to
// cover an ordinary runtime file, the gate is worthless.
$auditSrc = @file_get_contents($tool);
if ($auditSrc !== false && preg_match('/function lla_is_migration_path.*?static \$exempt = \[(.*?)\];/s', $auditSrc, $m)) {
    $exemptCount = preg_match_all("/'[^']+'/", $m[1]);
    ($exemptCount > 0 && $exemptCount <= 10)
        ? lla_ok("migration-path exemption list is short and reviewable ($exemptCount entries)")
        : lla_bad('migration-path exemption list has grown past 10 entries', (string) $exemptCount);
    (strpos($m[1], 'sql/run_rbac_v2.php') !== false)
        ? lla_ok('sql/run_rbac_v2.php (the level->role migration) is the exempt bridge')
        : lla_bad('sql/run_rbac_v2.php missing from the migration exemption list');
} else {
    lla_bad('could not read the migration-path exemption list from the audit');
}

// ── 4. api/reports.php specifically — the endpoint that started this ─────
$rep = @file_get_contents($base . '/api/reports.php');
if ($rep === false) {
    lla_bad('api/reports.php present');
} else {
    lla_ok('api/reports.php present');
    if (strpos($rep, "rbac_can('action.view_reports')") !== false) {
        lla_ok("api/reports.php gates on action.view_reports");
    } else {
        lla_bad("api/reports.php gates on action.view_reports");
    }
    if (!preg_match('/\$_currentLevel\s*>\s*1/', $rep)) {
        lla_ok('api/reports.php no longer gates on the legacy level');
    } else {
        lla_bad('api/reports.php still gates on the legacy level');
    }
}

// ── 5. The permission is seeded everywhere an install can pick it up ─────
$seeds = [
    'sql/rbac.sql'             => "'action.view_reports'",
    'sql/run_00_rbac.php'      => "'action.view_reports'",
    'sql/run_report_perm.php'  => "action.view_reports",
];
foreach ($seeds as $file => $needle) {
    $src = @file_get_contents($base . '/' . $file);
    if ($src !== false && strpos($src, $needle) !== false) {
        lla_ok("action.view_reports seeded in $file");
    } else {
        lla_bad("action.view_reports seeded in $file");
    }
}
// ...and withheld from Dispatcher's broad NOT IN grant (CLAUDE.md: broad
// grants in re-runnable seeds sweep up later permissions).
$rbacSql = @file_get_contents($base . '/sql/rbac.sql');
if ($rbacSql !== false
    && preg_match('/SELECT 3, `id` FROM `permissions`\s*WHERE `code` NOT IN \((.*?)\);/s', $rbacSql, $m)
    && strpos($m[1], 'action.view_reports') !== false) {
    lla_ok('action.view_reports excluded from the Dispatcher blanket grant');
} else {
    lla_bad('action.view_reports excluded from the Dispatcher blanket grant');
}

// ── 6. Live reproduction of the your deployment state ────────────────────────
// role_id=2 (Org Admin) + user.level=4. The OLD gate refused her; the new
// one must not. Driven through the real rbac_can(), not a hand-rolled copy.
// Self-skips on a virgin / unreachable database ($dbReady set at the top).
if (!$dbReady) {
    echo "SKIP: database not reachable/seeded — live RBAC reproduction skipped\n";
} else {
    $pfx = $GLOBALS['db_prefix'] ?? '';
    $permId = (int) db_fetch_value(
        "SELECT id FROM `{$pfx}permissions` WHERE code = ?", ['action.view_reports']
    );
    if ($permId <= 0) {
        echo "SKIP: action.view_reports not seeded in this DB "
           . "(run php sql/run_report_perm.php) — live reproduction skipped\n";
    } else {
        lla_ok('action.view_reports exists in the database');

        $granted = db_fetch_all(
            "SELECT role_id FROM `{$pfx}role_permissions` WHERE permission_id = ? ORDER BY role_id",
            [$permId]
        );
        $roleIds = array_map(fn($r) => (int) $r['role_id'], $granted);
        in_array(2, $roleIds, true)
            ? lla_ok('Org Admin (role 2) holds action.view_reports')
            : lla_bad('Org Admin (role 2) holds action.view_reports', 'roles: ' . implode(',', $roleIds));
        !in_array(5, $roleIds, true)
            ? lla_ok('Read-Only (role 5) does NOT hold action.view_reports')
            : lla_bad('Read-Only (role 5) must not hold action.view_reports');

        // Synthetic actor: a user_roles grant is all rbac_can() reads.
        $probeUser = 999901;
        try {
            db_query("DELETE FROM `{$pfx}user_roles` WHERE user_id = ?", [$probeUser]);
            db_query(
                "INSERT INTO `{$pfx}user_roles`
                    (user_id, role_id, org_id, scope_kind, scope_id, granted_at, reason)
                 VALUES (?, 2, NULL, 'global', NULL, NOW(), 'test_legacy_level_audit probe')",
                [$probeUser]
            );
            $savedSession = $_SESSION ?? [];
            $_SESSION['user_id'] = $probeUser;
            $_SESSION['level']   = 4;   // exactly the your deployment state
            if (function_exists('rbac_clear_cache')) rbac_clear_cache();

            // The old gate: (int) $_SESSION['level'] > 1 → denied.
            ((int) $_SESSION['level'] > 1)
                ? lla_ok('the legacy gate would still have refused this user (bug reproduced)')
                : lla_bad('probe session does not reproduce the legacy denial');

            rbac_can('action.view_reports')
                ? lla_ok('Org Admin with user.level=4 passes the new gate')
                : lla_bad('Org Admin with user.level=4 still fails rbac_can(action.view_reports)');

            // A Read-Only actor must still be refused the aggregate reports.
            db_query("UPDATE `{$pfx}user_roles` SET role_id = 5 WHERE user_id = ?", [$probeUser]);
            if (function_exists('rbac_clear_cache')) rbac_clear_cache();
            !rbac_can('action.view_reports')
                ? lla_ok('Read-Only is still refused the aggregate reports')
                : lla_bad('Read-Only can now run aggregate reports — gate opened too far');

            $_SESSION = $savedSession;
            if (function_exists('rbac_clear_cache')) rbac_clear_cache();
        } catch (Throwable $e) {
            lla_bad('live RBAC reproduction errored', $e->getMessage());
        } finally {
            try { db_query("DELETE FROM `{$pfx}user_roles` WHERE user_id = ?", [$probeUser]); }
            catch (Throwable $e) { /* nothing to clean */ }
        }
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
