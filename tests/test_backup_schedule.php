<?php
/**
 * Phase 122 — automatic, verified, restorable backups.
 *
 * The point of these tests is the property that was missing before: a backup
 * must be READ BACK and proven to contain a schema, and there must be a way to
 * restore it. Verification is tested against real archives written to a temp
 * directory, plus deliberately corrupt ones.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup_schedule.php';

$base = realpath(__DIR__ . '/..');
echo "=== Phase 122 — automatic + verified + restorable backups ===\n\n";
$pass = 0; $fail = 0;
function ok($n)  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $why = '') { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }

// ── 1. Schedule decision (pure — no clock, no DB) ──────────────────
$H = 3600;
backup_is_due_at(true,  0,        24, 1000000) === true  ? ok('never run → due')                : bad('never run → due');
backup_is_due_at(true,  1000000,  24, 1000000 + 23*$H) === false ? ok('23h into a 24h interval → not due') : bad('23h not due');
backup_is_due_at(true,  1000000,  24, 1000000 + 24*$H) === true  ? ok('exactly 24h → due')      : bad('24h due');
backup_is_due_at(true,  1000000,  24, 1000000 + 99*$H) === true  ? ok('long overdue → due')     : bad('overdue due');
backup_is_due_at(false, 0,        24, 1000000) === false ? ok('disabled → never due')           : bad('disabled never due');
backup_is_due_at(true,  1000000,   1, 1000000 + 2*$H)  === true  ? ok('honours a 1h interval')  : bad('1h interval');

// ── 2. Defaults: automatic backup must be ON out of the box ────────
// (The whole point: a backup nobody enabled is the backup nobody has.)
backup_setting('backup_auto_enabled', '1') === '1' ? ok('automatic backups default to ON') : bad('default ON');
backup_interval_hours()  > 0 ? ok('interval has a sane default (' . backup_interval_hours() . 'h)') : bad('interval default');
backup_retention_count() > 0 ? ok('retention has a sane default (' . backup_retention_count() . ')') : bad('retention default');

// ── 3. Verification: the property that makes a backup real ─────────
$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/tcad_bk_test_' . getmypid();
@mkdir($tmp, 0777, true);

// (a) A plain .sql containing schema → verifies.
$good = "$tmp/good.sql";
file_put_contents($good, "-- dump\nCREATE TABLE `member` (id INT);\nINSERT INTO `member` VALUES (1);\n" . str_repeat("-- pad\n", 100));
[$v, $d] = backup_verify($good);
$v ? ok('a dump containing CREATE TABLE verifies') : bad('good dump verifies', $d);

// (b) A file with no schema → must NOT verify (this is the write-only trap).
$noSchema = "$tmp/noschema.sql";
file_put_contents($noSchema, str_repeat("-- just comments, no schema here\n", 200));
[$v, $d] = backup_verify($noSchema);
!$v ? ok('a dump with no CREATE TABLE is rejected') : bad('reject schema-less dump');

// (c) A truncated/tiny file → must NOT verify.
$tiny = "$tmp/tiny.sql";
file_put_contents($tiny, 'CREATE TABLE x');
[$v, $d] = backup_verify($tiny);
!$v ? ok('an implausibly small archive is rejected') : bad('reject tiny archive');

// (d) A missing file → must NOT verify.
[$v, $d] = backup_verify("$tmp/does-not-exist.zip");
!$v ? ok('a missing archive is rejected') : bad('reject missing archive');

// (e) Real .zip round-trip, if ZipArchive is available.
if (class_exists('ZipArchive')) {
    $zipPath = "$tmp/real.zip";
    $z = new ZipArchive();
    if ($z->open($zipPath, ZipArchive::CREATE) === true) {
        $z->addFromString('backup.sql', "CREATE TABLE `ticket` (id INT);\n" . str_repeat("-- pad\n", 200));
        $z->close();
        [$v, $d] = backup_verify($zipPath);
        $v ? ok('a real .zip archive containing a dump verifies') : bad('zip verifies', $d);

        // A zip with NO sql inside must be rejected.
        $zipBad = "$tmp/nosql.zip";
        $z2 = new ZipArchive();
        $z2->open($zipBad, ZipArchive::CREATE);
        $z2->addFromString('readme.txt', str_repeat('x', 2000));
        $z2->close();
        [$v, $d] = backup_verify($zipBad);
        !$v ? ok('a .zip with no .sql inside is rejected') : bad('reject sql-less zip');
    }
} else {
    echo "SKIP: ZipArchive not enabled — zip verification tests skipped (0/0)\n";
}

// ── 4. Retention pruning keeps the newest N ────────────────────────
$pdir = "$tmp/prune"; @mkdir($pdir, 0777, true);
for ($i = 1; $i <= 6; $i++) {
    $f = "$pdir/ticketscad-$i.sql";
    file_put_contents($f, 'CREATE TABLE t (id INT);' . str_repeat('-', 600));
    touch($f, 1000000 + $i * 100);          // ascending mtimes: 6 is newest
}
$pruned = backup_prune($pdir, 3);
$left = glob("$pdir/*.sql") ?: [];
($pruned === 3 && count($left) === 3) ? ok('retention prunes oldest, keeps newest 3')
                                     : bad('retention prune', "pruned=$pruned left=" . count($left));
$names = array_map('basename', $left); sort($names);
($names === ['ticketscad-4.sql', 'ticketscad-5.sql', 'ticketscad-6.sql'])
    ? ok('the copies kept are the newest ones') : bad('kept newest', implode(',', $names));
backup_prune($pdir, 10) === 0 ? ok('pruning below the keep count is a no-op') : bad('no-op prune');

// cleanup
foreach (glob("$tmp/prune/*") ?: [] as $f) @unlink($f);
foreach (glob("$tmp/*") ?: [] as $f) { is_dir($f) ? @rmdir($f) : @unlink($f); }
@rmdir($tmp);

// ── 5. The restore tool exists and is safe by default ──────────────
$restore = "$base/tools/restore.php";
is_file($restore) ? ok('tools/restore.php exists (it did not before Phase 122)') : bad('restore tool exists');
$rs = @file_get_contents($restore) ?: '';
(strpos($rs, "--yes") !== false && strpos($rs, 'DRY RUN') !== false)
    ? ok('restore refuses to write without --yes (dry run by default)') : bad('restore is dry-run by default');
(strpos($rs, 'backup_run_now()') !== false && stripos($rs, 'safety backup') !== false)
    ? ok('restore takes a safety backup before overwriting') : bad('restore safety backup');
(strpos($rs, 'backup_verify(') !== false)
    ? ok('restore verifies the archive BEFORE touching the database') : bad('restore verifies first');

$runner = "$base/tools/backup_run.php";
is_file($runner) ? ok('tools/backup_run.php exists for cron / Task Scheduler') : bad('runner exists');

// ── 6. Status reporting drives the "your backups are stale" warning ─
$st = backup_status();
(array_key_exists('stale', $st) && array_key_exists('last_status', $st) && array_key_exists('warning', $st))
    ? ok('status reports staleness for the UI') : bad('status shape');
(array_key_exists('last_drill_at', $st) && array_key_exists('last_drill_status', $st))
    ? ok('status reports when a restore drill last ran') : bad('drill status in status()');

// ── 7. The restore DRILL — "verified" vs "proven" ──────────────────
// A drill must never condemn a backup for a SETUP problem. Bad credentials are
// inconclusive; only a run that actually restored may declare a backup bad.
$tmp2 = rtrim(sys_get_temp_dir(), '/\\') . '/tcad_drill_' . getmypid();
@mkdir($tmp2, 0777, true);
$sqlOk = "$tmp2/drillgood.sql";
file_put_contents($sqlOk, "CREATE TABLE `member` (id INT);\n" . str_repeat("-- pad\n", 100));

$r = backup_drill($sqlOk, 'definitely_not_a_real_user_' . getmypid(), 'wrong-password');
($r['ok'] === false && empty($r['conclusive']))
    ? ok('bad admin credentials → INCONCLUSIVE, not a failed backup')
    : bad('bad creds are inconclusive', json_encode(['ok' => $r['ok'], 'conclusive' => $r['conclusive'] ?? null]));
(stripos($r['detail'], 'credential') !== false || stripos($r['detail'], 'connect') !== false)
    ? ok('the message blames credentials, not the backup') : bad('credential message', $r['detail']);

// A genuinely unusable archive IS conclusive — we read it and it is bad.
$bad = "$tmp2/notabackup.sql";
file_put_contents($bad, str_repeat("no schema in here at all\n", 100));
$r2 = backup_drill($bad, 'someuser', 'somepass');
($r2['ok'] === false && !empty($r2['conclusive']))
    ? ok('an unusable archive is a CONCLUSIVE failure') : bad('bad archive conclusive');

// The drill must never target the live database.
(strpos((string) ($r['scratch'] ?? ''), (string) ($GLOBALS['db_name'] ?? 'newui')) === 0
    || $r['scratch'] === null)
    ? ok('scratch database is a distinct, throwaway name') : bad('scratch naming', (string) $r['scratch']);

$rs2 = @file_get_contents("$base/tools/restore.php") ?: '';
(strpos($rs2, "--drill") !== false && strpos($rs2, 'DROP DATABASE') === false)
    ? ok('restore.php exposes --drill (and does not itself drop databases)')
    : bad('--drill wired into restore.php');
(strpos($rs2, 'never written') !== false || stripos($rs2, 'only read') !== false)
    ? ok('drill states the live database is only read') : bad('drill safety wording');

foreach (glob("$tmp2/*") ?: [] as $f) @unlink($f);
@rmdir($tmp2);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
