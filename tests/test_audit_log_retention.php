<?php
/**
 * Phase 133 (2026-08-03) — audit-log retention & purge.
 *
 * Replaces a documentation-only "fix" the maintainer rejected: a prior
 * session, asked to reconcile docs/CJIS-POSTURE.md with the fact that
 * nothing pruned newui_audit_log, rewrote the doc to say the table is never
 * pruned and that keeping everything forever satisfies the 365-day CJIS
 * floor. Eric's response: "This is an issue to fix, not redefine. The
 * solution is to build the setting and ensure it works." This file drives
 * the real mechanism end to end.
 *
 * SAFETY AGAINST REAL DEV/CI DATA. Every destructive test in this file uses
 * either `retention_days_override` + a synthetic `now` fixed in 1950, or a
 * huge (100-year) retention window against rows deliberately backdated to
 * 1900 — so the purge cutoff never lands anywhere near real audit history
 * on the machine running this test, regardless of how old that history is.
 * A test that could sweep a developer's real audit log while proving the
 * feature works would be a worse bug than the one this phase fixes.
 *
 * POSITIVE CONTROLS. Per CLAUDE.md's root-cause-troubleshooting discipline,
 * a test that cannot be shown to fail on broken code proves nothing. Section
 * 6 (the DB-permission-revoked case) demonstrates, before trusting the real
 * detector, that (a) the simulated "DELETE denied" condition genuinely
 * throws, and (b) a naive/broken capability check would sail through it
 * undetected — then shows the REAL function does not.
 *
 * Usage: php tests/test_audit_log_retention.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/audit-retention.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Phase 133 — Audit log retention & purge ===\n";

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. Archive directory: platform-correct default (no DB needed) --\n";
// Mirrors tests/test_backup_dir_platform.php's method exactly: both
// platforms' answers are assertable from one machine, because a test that
// can only see its own platform's text is how the original backup-dir bug
// (GHSA-rrp6-pqhj-w5wj) shipped in the first place.
$n = function (string $p): string { return rtrim(str_replace('\\', '/', $p), '/'); };

$posixRoot = '/var/www/newui';
$posixDir  = audit_retention_default_dir_for($posixRoot, false);
chk($n($posixDir) === '/var/www/audit-archive',
    'POSIX default is dirname(appRoot)/audit-archive', $posixDir);

$winInstalls = [
    'C:\\inetpub\\wwwroot\\TicketsV4',
    'C:\\xampp\\htdocs\\newui',
    'D:\\sites\\ticketscad\\newui',
];
foreach ($winInstalls as $root) {
    $dir = audit_retention_default_dir_for($root, true);
    chk(stripos($dir, 'inetpub\\wwwroot') === false,
        "Windows default for {$root} never lands inside inetpub\\wwwroot", $dir);
    chk(stripos($dir, 'ProgramData') !== false && stripos($dir, 'TicketsCAD\\audit-archive') !== false,
        "Windows default for {$root} uses %ProgramData%\\TicketsCAD\\audit-archive", $dir);
    // Never inside the application's own tree either.
    chk(stripos($dir, $root) === false,
        "Windows default for {$root} is not inside the application tree", $dir);
}

chk(audit_retention_default_dir_for('/var/www/newui', false)
    === audit_retention_default_dir_for('/var/www/newui', false),
    'default-dir resolution is deterministic for a given (root, platform)');

echo "\n-- 2. CJIS-floor warning: pure function boundaries --\n";
chk(audit_retention_below_cjis_floor(0) === false, '0 (disabled) is not below the floor');
chk(audit_retention_below_cjis_floor(1) === true, '1 day is below the 365-day floor');
chk(audit_retention_below_cjis_floor(364) === true, '364 days is below the floor');
chk(audit_retention_below_cjis_floor(365) === false, '365 days is AT the floor, not below it');
chk(audit_retention_below_cjis_floor(366) === false, '366 days is above the floor');
chk(audit_retention_below_cjis_floor(3650) === false, '10 years is comfortably above the floor');

chk(audit_retention_below_cjis_floor_warning(0) === null, 'no warning when disabled');
chk(audit_retention_below_cjis_floor_warning(365) === null, 'no warning at exactly 365');
$w = audit_retention_below_cjis_floor_warning(30);
chk($w !== null && strpos($w, '365') !== false,
    'warning at 30 days names the 365-day minimum', (string) $w);

// ─────────────────────────────────────────────────────────────────────────
$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    echo "\nSKIP: no database available — the remaining Phase 133 tests need one\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix          = $GLOBALS['db_prefix'] ?? '';
$auditTableName  = $prefix . 'newui_audit_log';
$purgesTableName = $prefix . 'audit_log_purges';

$haveTable = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",
    [$purgesTableName]);
if (!$haveTable) {
    echo "\nSKIP: {$purgesTableName} missing — run php sql/run_phase133_audit_retention.php\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

require_once __DIR__ . '/../inc/audit.php';
audit_ensure_table();

$adminId = test_admin_user_id();

/** Insert a row through the REAL writer, then locate it by a unique marker
 *  (never trust lastInsertId() here — audit_log() may fire webhook/queue
 *  inserts afterward that would move it). */
function _p133_seed(string $marker, int $idx): int {
    global $auditTableName;
    $summary = "phase133 seed {$marker}-{$idx}";
    audit_log('system', 'test_seed', 'test', null, $summary, ['marker' => $marker], AUDIT_INFO);
    return (int) db_fetch_value(
        "SELECT `id` FROM `{$auditTableName}` WHERE `summary` = ? ORDER BY `id` DESC LIMIT 1", [$summary]);
}

function _p133_backdate(int $id, int $unixTs): void {
    global $auditTableName;
    // date(), not gmdate(): must match the same area-local wall-clock
    // representation inc/audit-retention.php's cutoff math now uses (see
    // audit_retention_eligible_count()) — comparing a UTC-written event_time
    // against a locally-computed cutoff would be off by the server's UTC
    // offset and could quietly break the exact-boundary assertions below.
    db_query("UPDATE `{$auditTableName}` SET `event_time` = ? WHERE `id` = ?",
        [date('Y-m-d H:i:s', $unixTs), $id]);
}

function _p133_exists(int $id): bool {
    global $auditTableName;
    return (bool) db_fetch_value("SELECT 1 FROM `{$auditTableName}` WHERE `id` = ?", [$id]);
}

/**
 * Run $phpExpr (a snippet that echoes something) in a FRESH PHP process and
 * return its stdout. Required wherever a test needs to observe the REAL
 * production settings-read path: get_variable() (inc/functions.php) caches
 * the WHOLE settings table in a static on first read per process, so once
 * THIS test file has called it once (directly or via audit_log()'s webhook/
 * notification internals), every later read in the SAME process is answered
 * from that stale snapshot no matter what this file writes afterward —
 * exactly the trap documented at get_variable()'s definition and exercised
 * by tests/test_scheduled_jobs.php for sched_stale_cutoff_min. A fresh
 * subprocess is the only honest witness, and it is also the truthful
 * simulation of a real user's request: every real HTTP request is its own
 * fresh PHP process, so this is what an admin loading Settings would
 * actually see.
 */
function _p133_probe(string $phpExpr): string {
    $php  = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $root = str_replace('\\', '/', dirname(__DIR__));
    $file = sys_get_temp_dir() . '/p133_probe_' . getmypid() . '_' . mt_rand(1000, 9999) . '.php';
    file_put_contents($file,
        "<?php\nrequire '{$root}/config.php';\nrequire '{$root}/inc/audit-retention.php';\n"
      . "require '{$root}/inc/scheduled-jobs.php';\n{$phpExpr}\n");
    $out = trim((string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($file) . ' 2>&1'));
    @unlink($file);
    return $out;
}

// Snapshot everything this file might mutate, restored at the very end.
$origRetentionSetting = (string) db_fetch_value(
    "SELECT `value` FROM `{$prefix}settings` WHERE `name`='audit_log_retention_days'") ?: '0';
$purgesMaxIdBefore = (int) (db_fetch_value("SELECT COALESCE(MAX(id),0) FROM `{$purgesTableName}`") ?: 0);
$cleanupAuditIds   = [];   // synthetic seed rows never purged — deleted at the end
$cleanupArchives   = [];   // archive files this run wrote — deleted at the end

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Settings round-trip ACROSS PROCESSES (settings table, not `config`) --\n";
// Mirrors tests/test_scheduled_jobs.php's method for sched_stale_cutoff_min:
// get_variable() caches the WHOLE settings table in a static on first read,
// so a write-then-read inside one process is answered from cache and would
// pass no matter which of this project's two settings stores the write
// landed in (CLAUDE.md, "TWO settings stores"). A subprocess is the only
// honest witness.
try {
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('audit_log_retention_days','91')
              ON DUPLICATE KEY UPDATE `value`='91'");
    $php  = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $root = str_replace('\\', '/', dirname(__DIR__));

    // A temp script, not `php -r` — shell quoting of an inline program
    // differs between cmd.exe and sh and would make the result depend on
    // which shell launched the suite.
    $probe = sys_get_temp_dir() . '/p133_probe_' . getmypid() . '.php';
    file_put_contents($probe,
        "<?php\n"
      . "require '{$root}/config.php';\n"
      . "require '{$root}/inc/audit-retention.php';\n"
      . "echo audit_retention_days(), '|', var_export(get_variable('audit_log_retention_days'), true);\n");
    $out = trim((string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($probe) . ' 2>&1'));
    @unlink($probe);

    $parts = explode('|', $out);
    chk(trim($parts[0] ?? '') === '91',
        "a fresh process reads the settings-table value via audit_retention_days() (got '"
        . substr($out, 0, 80) . "', want 91)");
    chk(strpos($parts[1] ?? '', '91') !== false,
        'get_variable() (the `settings` table) sees the value, not get_setting()/`config`');
} finally {
    db_query("UPDATE `{$prefix}settings` SET `value`=? WHERE `name`='audit_log_retention_days'",
        [$origRetentionSetting]);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Disabled by default: nothing is purged, no matter how old --\n";
$marker = 'p133-' . bin2hex(random_bytes(4));
$disabledId = _p133_seed($marker, 1);
_p133_backdate($disabledId, strtotime('1900-01-01 00:00:00'));

$r = audit_purge_run(['retention_days_override' => 0, 'triggered_by' => 'manual']);
chk($r['ok'] === true && !empty($r['skipped']) && $r['purged'] === 0,
    'retention_days=0 returns ok/skipped with purged=0', json_encode($r));
chk(_p133_exists($disabledId), 'a 126-year-old row survives when retention is disabled');

$purgesCountAfterDisabled = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$purgesTableName}` WHERE `id` > ?", [$purgesMaxIdBefore]);
chk($purgesCountAfterDisabled === 0, 'disabled purge writes no audit_log_purges row at all');

// Deleted immediately, not deferred to end-of-file cleanup: it is
// deliberately backdated to 1900, and section 5 below uses a cutoff that is
// ALSO well before real 2026 data (anchored to a fake 1950 "now") — so if
// this row were left lying around it would be swept up by section 5's
// purge too, corrupting that section's exact-row-count assertions.
db_query("DELETE FROM `{$auditTableName}` WHERE `id` = ?", [$disabledId]);

$cleanupAuditIds[] = $disabledId;

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. Real purge: archive-then-delete, exact row set, hash, manifest, self-audit --\n";
// All timestamps anchored to a fixed 1950 "now" so this test can never touch
// real 2026 audit history, whatever is actually in this database.
$fakeNow = (int) gmmktime(12, 0, 0, 6, 15, 1950);
$retentionDays = 30;
$cutoffTs = $fakeNow - ($retentionDays * 86400);

$oldIds = [];
for ($i = 1; $i <= 3; $i++) {
    $id = _p133_seed($marker, 100 + $i);
    _p133_backdate($id, $cutoffTs - (86400 * $i));   // strictly older than cutoff
    $oldIds[] = $id;
}
$keepIds = [];
for ($i = 1; $i <= 2; $i++) {
    $id = _p133_seed($marker, 200 + $i);
    _p133_backdate($id, $fakeNow - (86400 * $i));    // recent relative to fakeNow, inside cutoff
    $keepIds[] = $id;
    $cleanupAuditIds[] = $id;
}

$eligible = audit_retention_eligible_count($retentionDays, $fakeNow);
chk($eligible >= 3, "eligible_count sees at least the 3 seeded old rows (got {$eligible})");

$r2 = audit_purge_run([
    'retention_days_override' => $retentionDays,
    'now'                     => $fakeNow,
    'triggered_by'            => 'manual',
    'triggered_by_user_id'    => $adminId,
]);
chk($r2['ok'] === true, 'real purge returns ok=true', json_encode($r2));
chk($r2['purged'] === count($oldIds), 'purged count equals the seeded old-row count', (string) $r2['purged']);
chk(!empty($r2['archive']), 'response names the archive filename');

foreach ($oldIds as $id) {
    chk(!_p133_exists($id), "old row id={$id} (older than cutoff) is GONE from the live table");
}
foreach ($keepIds as $id) {
    chk(_p133_exists($id), "row id={$id} at/inside the cutoff SURVIVES in the live table");
}

$archivePath = rtrim(audit_retention_dir(), '/\\') . '/' . $r2['archive'];
chk(is_file($archivePath), 'archive file exists on disk', $archivePath);
if (is_file($archivePath)) {
    $cleanupArchives[] = $archivePath;

    $fh = @gzopen($archivePath, 'rb');
    chk($fh !== false, 'archive opens as valid gzip');
    $archivedIds = [];
    if ($fh) {
        while (!gzeof($fh)) {
            $line = gzgets($fh);
            if ($line === false) continue;
            $line = trim($line);
            if ($line === '') continue;
            $obj = json_decode($line, true);
            chk(json_last_error() === JSON_ERROR_NONE, 'each archive line is valid JSON', $line);
            if (is_array($obj) && isset($obj['id'])) $archivedIds[] = (int) $obj['id'];
        }
        gzclose($fh);
    }
    sort($archivedIds); $sortedOld = $oldIds; sort($sortedOld);
    chk($archivedIds === $sortedOld,
        'archive decompresses to EXACTLY the removed row ids, no more, no less',
        'archived=' . implode(',', $archivedIds) . ' expected=' . implode(',', $sortedOld));

    $recomputed = hash_file('sha256', $archivePath);
    $manifest = db_fetch_one(
        "SELECT * FROM `{$purgesTableName}` WHERE `archive_filename` = ? ORDER BY id DESC LIMIT 1",
        [$r2['archive']]);
    chk($manifest !== null, 'audit_log_purges row exists for this archive');
    if ($manifest) {
        chk((int) $manifest['rows_purged'] === count($oldIds),
            'manifest rows_purged matches the real count');
        chk($manifest['archive_sha256'] === $recomputed,
            'manifest archive_sha256 matches a fresh hash of the file on disk');
        chk($manifest['status'] === 'ok', 'manifest status is ok');
        chk($manifest['triggered_by'] === 'manual', 'manifest records triggered_by=manual');
        chk((int) $manifest['triggered_by_user_id'] === $adminId,
            'manifest records the triggering user id');
    }
}

// The purge's own audit_log entry — written with the REAL current time
// (audit_log() always inserts NOW()), so it is trivially after the fake
// 1950 cutoff. This is the "did not purge itself" property.
$selfEntry = db_fetch_one(
    "SELECT * FROM `{$auditTableName}` WHERE `activity`='audit_log_purge'
      AND `target_id` = ? ORDER BY `id` DESC LIMIT 1",
    [(string) $r2['purge_id']]);
chk($selfEntry !== null, 'the purge wrote its own newui_audit_log entry');
if ($selfEntry) {
    $selfTs = strtotime((string) $selfEntry['event_time']);
    chk($selfTs > $cutoffTs, "the self-entry's event_time is AFTER the cutoff it just purged (self-entry did not purge itself)");
    chk($selfEntry['category'] === 'admin', 'self-entry category is admin');
    chk((int) $selfEntry['severity'] >= AUDIT_HIGH, 'self-entry severity is at least AUDIT_HIGH');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Exact cutoff boundary: strictly-older purged, at-cutoff survives --\n";
$boundaryFakeNow = (int) gmmktime(12, 0, 0, 3, 1, 1955);
$boundaryDays    = 10;
$boundaryCutoff  = $boundaryFakeNow - ($boundaryDays * 86400);

$atCutoffId  = _p133_seed($marker, 301);
_p133_backdate($atCutoffId, $boundaryCutoff);            // event_time == cutoff exactly
$pastCutoffId = _p133_seed($marker, 302);
_p133_backdate($pastCutoffId, $boundaryCutoff - 1);       // one second older than cutoff

$r3 = audit_purge_run([
    'retention_days_override' => $boundaryDays,
    'now'                     => $boundaryFakeNow,
    'triggered_by'            => 'manual',
]);
chk($r3['ok'] === true, 'boundary purge run returns ok=true', json_encode($r3));
chk(_p133_exists($atCutoffId), 'a row exactly AT the cutoff (event_time == cutoff) is NOT purged (strict <)');
chk(!_p133_exists($pastCutoffId), 'a row one second older than the cutoff IS purged');
$cleanupAuditIds[] = $atCutoffId;
if (!empty($r3['archive'])) {
    $p = rtrim(audit_retention_dir(), '/\\') . '/' . $r3['archive'];
    if (is_file($p)) $cleanupArchives[] = $p;
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. DB-permission-revoked case: loud failure, never a silent no-op --\n";

/** A stand-in PDO that throws exactly the way a revoked DELETE grant would,
 *  for DELETE statements only. No real connection, no real database touched
 *  — this application never issues REVOKE/GRANT itself; it only detects the
 *  condition. See inc/audit-retention.php's audit_retention_check_delete_capability(). */
class P133DenyDeletePdo extends PDO {
    public function __construct() { /* no real connection */ }
    #[\ReturnTypeWillChange]
    public function exec($statement) {
        if (stripos(ltrim((string) $statement), 'DELETE') === 0) {
            throw new PDOException(
                "SQLSTATE[42000]: Syntax error or access violation: 1142 DELETE command denied "
                . "to user 'newui_test_restricted'@'localhost' for table 'newui_audit_log'");
        }
        return 0;
    }
}
$denyPdo = new P133DenyDeletePdo();

// 7a. Positive control #1 — prove the simulated failure is REAL: a bare
// attempt against the stand-in connection must actually throw.
$threw = false;
try { $denyPdo->exec('DELETE FROM whatever WHERE 1=0'); }
catch (PDOException $e) { $threw = true; }
chk($threw, 'positive control: the deny-delete stand-in genuinely throws on DELETE — the simulated failure is real');

// 7b. Positive control #2 — prove the assertions below have teeth: a naive/
// broken capability check (representing a hypothetical regression that
// swallows the error) must WRONGLY report success against the same
// connection, so the difference from the real function below is meaningful
// rather than vacuous.
$naiveCapabilityCheck = static function (PDO $pdo): array {
    try {
        // A "regression" shape: catches everything, decides optimistically.
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => true, 'error' => ''];   // still wrong on purpose
    }
};
$naive = $naiveCapabilityCheck($denyPdo);
chk($naive['ok'] === true,
    'positive control: a naive/broken detector WOULD wrongly report capable=true here — '
    . 'proving the real detector below is doing real work, not passing by default');

// 7c. The real function, against the same connection, must NOT be fooled.
$realCap = audit_retention_check_delete_capability($denyPdo);
chk($realCap['ok'] === false,
    'the REAL audit_retention_check_delete_capability() correctly reports capable=false');
chk(stripos($realCap['error'], 'denied') !== false || stripos($realCap['error'], '1142') !== false,
    'the real error message names the actual denial reason', $realCap['error']);

// 7d. Drive the REAL orchestrator through the same failure, end to end.
$beforeCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$auditTableName}`");
$purgesBeforeFail = (int) (db_fetch_value("SELECT COALESCE(MAX(id),0) FROM `{$purgesTableName}`") ?: 0);

$r4 = audit_purge_run([
    'retention_days_override' => 5,
    'now'                     => $fakeNow,
    'triggered_by'            => 'manual',
    '_capability_pdo'         => $denyPdo,
]);
chk($r4['ok'] === false, 'audit_purge_run() reports ok=false when DELETE is unavailable — not a silent no-op');
chk(!empty($r4['error']) && stripos($r4['error'], 'denied') !== false,
    'the failure detail names DELETE denial, not a generic error', (string) ($r4['error'] ?? ''));

$afterCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$auditTableName}`");
chk($afterCount === $beforeCount, 'no row was deleted when the capability check fails');
chk(empty($r4['archive']), 'no archive was written when the capability check fails BEFORE archiving');

$failManifest = db_fetch_one(
    "SELECT * FROM `{$purgesTableName}` WHERE `id` > ? ORDER BY `id` DESC LIMIT 1", [$purgesBeforeFail]);
chk($failManifest !== null, 'a manifest row records the FAILED attempt (visible in the manifest, not just a log line)');
if ($failManifest) {
    chk($failManifest['status'] === 'failed', 'manifest status is "failed"');
    chk(stripos($failManifest['detail'], 'denied') !== false, 'manifest detail names the denial');
}

// audit_retention_status() itself must be read through a FRESH process — see
// _p133_probe()'s docblock. The write above (inside audit_purge_run(), via
// audit_retention_setting_set()) already committed straight to the
// database; only the CACHED read in THIS long-running test process would be
// stale, and a stale read here would be a false pass on the test's own
// account, not evidence the feature works. A fresh process is exactly what
// a real admin's next page load is.
$probeOut = _p133_probe('echo json_encode(audit_retention_status());');
$probeStatus = json_decode($probeOut, true);
chk(is_array($probeStatus), 'status probe subprocess returned valid JSON', $probeOut);
if (is_array($probeStatus)) {
    chk($probeStatus['last_failed'] === true,
        'audit_retention_status() (what Settings/Scheduled-Jobs read) reports last_failed=true');
    chk(strpos((string) $probeStatus['last_status'], 'failed:') === 0,
        'audit_retention_status() last_status is prefixed "failed:" — loud, not swallowed',
        (string) $probeStatus['last_status']);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 8. Below-365 warning fires but never blocks the save --\n";
foreach ([200, 30, 1] as $d) {
    audit_retention_setting_set('audit_log_retention_days', (string) $d);
    $warn = audit_retention_below_cjis_floor_warning($d);
    chk($warn !== null, "warning present for {$d} day(s)");
    // "never blocks" — the setter itself has no validation gate at all; the
    // value simply persists (api/audit-retention.php's own validation only
    // rejects a genuinely invalid input, e.g. a negative number — never a
    // value below 365).
    $stored = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name`='audit_log_retention_days'");
    chk((int) $stored === $d, "the below-floor value {$d} was saved anyway (not blocked)");
}
foreach ([365, 400, 3650] as $d) {
    audit_retention_setting_set('audit_log_retention_days', (string) $d);
    chk(audit_retention_below_cjis_floor_warning($d) === null, "no warning for {$d} day(s)");
}
db_query("UPDATE `{$prefix}settings` SET `value`=? WHERE `name`='audit_log_retention_days'",
    [$origRetentionSetting]);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 9. RBAC: only Super Admin holds action.manage_audit_retention --\n";
$permRow = db_fetch_one(
    "SELECT `id` FROM `{$prefix}permissions` WHERE `code`='action.manage_audit_retention' LIMIT 1");
chk($permRow !== null, 'permission action.manage_audit_retention exists — run sql/run_phase133_audit_retention.php');

if ($permRow) {
    $permId = (int) $permRow['id'];
    $roleIds = array_map('intval', array_column(
        db_fetch_all("SELECT `role_id` FROM `{$prefix}role_permissions` WHERE `permission_id` = ?", [$permId]),
        'role_id'
    ));
    sort($roleIds);
    chk(in_array(1, $roleIds, true), 'Super Admin (role 1) holds action.manage_audit_retention');
    chk(!in_array(2, $roleIds, true), 'Org Admin (role 2) does NOT hold action.manage_audit_retention');
    chk(!in_array(3, $roleIds, true), 'Dispatcher (role 3) does NOT hold action.manage_audit_retention');
    chk(!in_array(4, $roleIds, true), 'Operator (role 4) does NOT hold action.manage_audit_retention');
    chk(!in_array(5, $roleIds, true), 'Read-Only (role 5) does NOT hold action.manage_audit_retention');
    chk($roleIds === [1], 'exactly one role (Super Admin) holds the permission', implode(',', $roleIds));

    // Corroborating runtime check: rbac_can() for the real admin session.
    // (Super Admin also short-circuits rbac_can() via is_super — this does
    // NOT by itself prove the grant exists, which is why the direct
    // role_permissions query above is the primary evidence.)
    $origSessionUser = $_SESSION['user_id'] ?? null;
    $_SESSION['user_id'] = $adminId;
    rbac_clear_cache();
    chk(rbac_can('action.manage_audit_retention') === true,
        'rbac_can() grants the permission to the real admin session');
    if ($origSessionUser === null) unset($_SESSION['user_id']); else $_SESSION['user_id'] = $origSessionUser;
    rbac_clear_cache();
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 10. Scheduled-job wiring: registry entry + required-only-when-enabled --\n";
require_once __DIR__ . '/../inc/scheduled-jobs.php';
$registry = sched_job_registry();
chk(isset($registry['audit_log_purge']), 'audit_log_purge is registered in sched_job_registry()');
if (isset($registry['audit_log_purge'])) {
    chk($registry['audit_log_purge']['interval_s'] === 86400, 'registered interval is daily (86400s)');
    chk(strpos($registry['audit_log_purge']['command'], 'audit_log_purge_tick.php') !== false,
        'registered command points at tools/audit_log_purge_tick.php');
}

// sched_job_required() calls audit_retention_days(), which reads through
// get_variable() — subject to the same per-process cache staleness _p133_probe()
// documents. Verified fresh, exactly as a real health-check request would see it.
db_query("UPDATE `{$prefix}settings` SET `value`='0' WHERE `name`='audit_log_retention_days'");
$offOut = _p133_probe("echo json_encode(sched_job_required('audit_log_purge'));");
$offReq = json_decode($offOut, true);
chk(is_array($offReq) && $offReq['required'] === false,
    'job is NOT required when retention is disabled (shipped default is not usage)', $offOut);

db_query("UPDATE `{$prefix}settings` SET `value`='30' WHERE `name`='audit_log_retention_days'");
$onOut = _p133_probe("echo json_encode(sched_job_required('audit_log_purge'));");
$onReq = json_decode($onOut, true);
chk(is_array($onReq) && $onReq['required'] === true,
    'job IS required the moment retention is enabled', $onOut);

db_query("UPDATE `{$prefix}settings` SET `value`=? WHERE `name`='audit_log_retention_days'",
    [$origRetentionSetting]);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 11. The real CLI tick tool, as a subprocess, end to end --\n";
// 100-year retention + a row backdated to 1900 — safe against real data
// (nothing in a 2026 install predates 1926) and exercises the ACTUAL
// production path: real settings read, real time(), real sched_job_record().
try {
    db_query("UPDATE `{$prefix}settings` SET `value`='36500' WHERE `name`='audit_log_retention_days'");
    $tickId = _p133_seed($marker, 401);
    _p133_backdate($tickId, strtotime('1900-06-01 00:00:00'));

    $php  = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $root = str_replace('\\', '/', dirname(__DIR__));
    $cmd  = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/audit_log_purge_tick.php') . ' 2>&1';
    $out  = (string) shell_exec($cmd);

    chk(stripos($out, 'FAILED') === false, 'tick tool subprocess reports no failure', trim($out));
    chk(!_p133_exists($tickId), 'the real tick tool purged the 126-year-old seed row through the real CLI path');

    $last = sched_table_exists() ? sched_job_last('audit_log_purge') : null;
    chk($last !== null, 'sched_job_record() recorded a run for audit_log_purge');
    if ($last) {
        chk($last['last_status'] === 'ok', 'recorded run status is ok');
    }

    // Capture the archive this run wrote so it gets cleaned up too.
    $tickManifest = db_fetch_one(
        "SELECT * FROM `{$purgesTableName}` ORDER BY id DESC LIMIT 1");
    if ($tickManifest && !empty($tickManifest['archive_filename'])) {
        $p = rtrim(audit_retention_dir(), '/\\') . '/' . $tickManifest['archive_filename'];
        if (is_file($p)) $cleanupArchives[] = $p;
    }
} finally {
    db_query("UPDATE `{$prefix}settings` SET `value`=? WHERE `name`='audit_log_retention_days'",
        [$origRetentionSetting]);
}

// ─────────────────────────────────────────────────────────────────────────
// Tidy up: remove synthetic rows that were never purged, archive files this
// run wrote, and the manifest/self-audit rows this run's real purges
// created, so repeated runs of this suite leave no residue in a real
// developer database. Scoped strictly to id > the snapshot taken before
// section 4 — never a blanket delete.
foreach (array_unique($cleanupAuditIds) as $id) {
    if (_p133_exists($id)) db_query("DELETE FROM `{$auditTableName}` WHERE `id` = ?", [$id]);
}
foreach (array_unique($cleanupArchives) as $path) {
    if (is_file($path)) @unlink($path);
}
$testPurgeIds = array_map('intval', array_column(
    db_fetch_all("SELECT `id` FROM `{$purgesTableName}` WHERE `id` > ?", [$purgesMaxIdBefore]),
    'id'
));
if ($testPurgeIds) {
    $ph = implode(',', array_fill(0, count($testPurgeIds), '?'));
    db_query("DELETE FROM `{$auditTableName}` WHERE `activity`='audit_log_purge' AND `target_id` IN ({$ph})",
        $testPurgeIds);
    db_query("DELETE FROM `{$purgesTableName}` WHERE `id` IN ({$ph})", $testPurgeIds);
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
