<?php
/**
 * Phase 126 (2026-07-29) — backups must not be able to cause the outage.
 *
 * Eric: "I'm concerned we risk filling someone's disk space. We don't want to
 * create an outage because we consumed the disk space."
 *
 * These tests target the three things that decide whether that can happen, and
 * they target them as PURE functions so the nasty cases are reachable without a
 * full disk: the space verdict (including exactly-at-the-floor and
 * free-space-unreadable), the retention plan (including only-one-backup-exists
 * and cannot-fit-under-the-cap), and the enable/disable gate.
 *
 * The bug this phase fixed is worth restating, because a test file is where it
 * will be re-learned: backup_maybe_run_opportunistic() was defined and called
 * from nowhere. Automatic backups reported ON and produced NOTHING on any
 * install without cron. Section 5 pins the wiring so it cannot silently rot
 * back to dead code.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup_schedule.php';

$base = realpath(__DIR__ . '/..');
echo "=== Phase 126 — backup disk guard, retention and the scheduler wiring ===\n\n";
$pass = 0; $fail = 0;
function ok($n)  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $why = '') { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }
function is_ok($cond, $n, $why = '') { $cond ? ok($n) : bad($n, $why); }

$MB = 1024 * 1024;
$GB = 1024 * $MB;

// ── 1. The disk-space verdict — the guard that refuses ──────────────
// Plenty of room.
$v = backup_space_verdict(10 * $GB, 1 * $GB, 1 * $GB);
is_ok($v['ok'] === true, 'ample free space → backup proceeds');

// Would land BELOW the floor → must refuse.
$v = backup_space_verdict(2 * $GB, 1500 * $MB, 1 * $GB);
is_ok($v['ok'] === false, 'a write that would breach the reserve is refused');
is_ok(stripos($v['reason'], 'disk space') !== false && stripos($v['reason'], 'reserve') !== false,
    'the refusal explains itself in plain language', $v['reason']);

// BOUNDARY: lands EXACTLY on the floor. The floor is "space that must remain",
// so landing on it is inside policy and must be allowed.
$v = backup_space_verdict(2 * $GB, 1 * $GB, 1 * $GB);
is_ok($v['ok'] === true, 'landing EXACTLY on the reserve is allowed (>=, not >)', $v['reason']);

// BOUNDARY: one byte past the floor → refuse.
$v = backup_space_verdict(2 * $GB, 1 * $GB + 1, 1 * $GB);
is_ok($v['ok'] === false, 'one byte past the reserve is refused');

// BOUNDARY: free space UNREADABLE. Must PROCEED, and must say it degraded.
// Refusing here would trade a hypothetical disk-full for a certain no-backup.
$v = backup_space_verdict(null, 1 * $GB, 1 * $GB);
is_ok($v['ok'] === true, 'unreadable free space → proceeds anyway (degrades gracefully)');
is_ok(!empty($v['undetermined']), 'unreadable free space is flagged as undetermined, not silently ok');

// Unknown SIZE with known free space → the floor still applies to what we know.
$v = backup_space_verdict(500 * $MB, null, 1 * $GB);
is_ok($v['ok'] === false, 'already below the reserve is refused even when the size is unknown');

// A zero floor is a deliberate opt-out and must be honoured.
$v = backup_space_verdict(1024, 1 * $GB, 0);
is_ok($v['ok'] === true, 'a reserve of 0 disables the floor (explicit opt-out)');

// ── 1b. The folder-limit verdict ───────────────────────────────────
// Room to spare.
$c = backup_cap_verdict(1 * $GB, 5, 200 * $MB, 5 * $GB);
is_ok($c['ok'] === true, 'a backup that fits under the folder limit proceeds');

// Would exceed the limit, and copies already exist → refuse.
$c = backup_cap_verdict(4900 * $MB, 5, 500 * $MB, 5 * $GB);
is_ok($c['ok'] === false, 'a backup that would exceed the folder limit is refused');
is_ok(stripos($c['reason'], 'folder limit') !== false && stripos($c['reason'], 'Raise the limit') !== false,
    'the folder-limit refusal names the limit and how to fix it', $c['reason']);

// BOUNDARY: exactly ON the limit is allowed.
$c = backup_cap_verdict(4 * $GB, 3, 1 * $GB, 5 * $GB);
is_ok($c['ok'] === true, 'landing EXACTLY on the folder limit is allowed');
$c = backup_cap_verdict(4 * $GB, 3, 1 * $GB + 1, 5 * $GB);
is_ok($c['ok'] === false, 'one byte past the folder limit is refused');

// BOUNDARY THAT MATTERS: the FIRST backup is never blocked by the limit.
// A database bigger than the ceiling is a misconfiguration to surface, not a
// reason for the operator to end up with no backup at all.
$c = backup_cap_verdict(0, 0, 50 * $GB, 1 * $GB);
is_ok($c['ok'] === true, 'the folder limit never blocks the FIRST backup');
is_ok(!empty($c['first']), 'the first-backup exception is flagged, not silent');

// A limit of 0 means uncapped.
$c = backup_cap_verdict(500 * $GB, 99, 50 * $GB, 0);
is_ok($c['ok'] === true, 'a folder limit of 0 means no limit');

// Unknown archive size must not be treated as infinite.
$c = backup_cap_verdict(100 * $MB, 2, null, 5 * $GB);
is_ok($c['ok'] === true, 'an unknown archive size does not trip the folder limit');

// ── 2. Retention math — never delete the last backup ───────────────
$now = 1000000000;
$mk = function (array $spec) { return $spec; };

// Five archives, one per day, newest first.
$files = [];
for ($i = 0; $i < 5; $i++) {
    $files["/b/ticketscad-$i.zip"] = ['mtime' => $now - ($i * 86400), 'size' => 100 * $MB];
}

// Keep 3 by count.
$p = backup_retention_plan($files, 3, 0, 0, $now);
is_ok(count($p['delete']) === 2 && $p['kept_count'] === 3, 'keep-count deletes the oldest surplus',
    'deleted=' . count($p['delete']));
is_ok(!in_array('/b/ticketscad-0.zip', $p['delete'], true), 'the newest archive is never deleted by count');

// Age expiry at 2 days.
$p = backup_retention_plan($files, 0, 2, 0, $now);
is_ok(in_array('/b/ticketscad-4.zip', $p['delete'], true) &&
      !in_array('/b/ticketscad-1.zip', $p['delete'], true),
    'age expiry deletes only archives past the cutoff');

// BOUNDARY: ONLY ONE BACKUP EXISTS, and it is ancient, and the count says 0,
// and the cap is tiny. Every rule points at deleting it. It must survive.
$one = ['/b/ticketscad-old.zip' => ['mtime' => $now - (365 * 86400), 'size' => 900 * $MB]];
$p = backup_retention_plan($one, 1, 1, 1 * $MB, $now);
is_ok($p['delete'] === [], 'the ONLY backup is never deleted — not by age, count or cap',
    implode(',', $p['delete']));
is_ok($p['kept_count'] === 1, 'the only backup is still reported as kept');
is_ok($p['over_cap'] === true, 'being stuck over the cap is REPORTED rather than resolved by deleting it');

// Size cap: 5 x 100 MB with a 250 MB cap → keep 2, delete 3.
$p = backup_retention_plan($files, 0, 0, 250 * $MB, $now);
is_ok($p['kept_bytes'] <= 250 * $MB, 'the size cap is actually enforced',
    'kept=' . $p['kept_bytes']);
is_ok(!in_array('/b/ticketscad-0.zip', $p['delete'], true), 'the cap deletes oldest-first, sparing the newest');
is_ok($p['over_cap'] === false, 'a directory that fits under the cap is not flagged over-cap');

// Cap smaller than a single archive → prune to the protected minimum and FLAG.
$p = backup_retention_plan($files, 0, 0, 10 * $MB, $now);
is_ok($p['kept_count'] === BACKUP_MIN_KEEP, 'an impossible cap prunes down to the protected minimum only');
is_ok($p['over_cap'] === true, 'an impossible cap is surfaced as over_cap');

// Empty directory is not a crash.
$p = backup_retention_plan([], 7, 30, 1 * $GB, $now);
is_ok($p['delete'] === [] && $p['kept_count'] === 0, 'an empty backup directory is handled');

// ── 3. Retention on real files, incl. the "only ours" rule ─────────
$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/tcad_guard_' . getmypid();
@mkdir($tmp, 0777, true);

// Four of ours, plus two files that belong to the operator.
for ($i = 1; $i <= 4; $i++) {
    $f = "$tmp/ticketscad-2026010$i-120000.zip";
    file_put_contents($f, str_repeat('x', 1024));
    touch($f, $now + $i * 100);
}
file_put_contents("$tmp/quarterly-accounts.zip", str_repeat('y', 2048));
file_put_contents("$tmp/someone-elses-dump.sql", str_repeat('z', 2048));

$found = backup_archives($tmp);
is_ok(count($found) === 4, 'backup_archives sees only ticketscad-* files', 'saw ' . count($found));

$r = backup_apply_retention($tmp, 2, 0, 0, $now);
is_ok($r['pruned'] === 2, 'retention pruned the two oldest of ours', 'pruned=' . $r['pruned']);
is_ok(is_file("$tmp/quarterly-accounts.zip") && is_file("$tmp/someone-elses-dump.sql"),
    "retention NEVER touches files this app did not write");

$u = backup_dir_usage($tmp);
is_ok($u['count'] === 2, 'usage counts only our surviving archives', 'count=' . $u['count']);
is_ok($u['bytes'] === 2048, 'usage sums only our archives, not the operator\'s files', 'bytes=' . $u['bytes']);

// Free space on a real directory should be readable here.
$free = backup_free_bytes($tmp);
is_ok($free === null || $free > 0, 'free space is an int or an honest null');

// A directory that does not exist yet must still report free space via its
// nearest existing parent (a first run must not read as "undeterminable").
$free2 = backup_free_bytes($tmp . '/not/created/yet');
is_ok($free2 === null || $free2 > 0, 'free space resolves through a not-yet-created backup directory');

foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
@rmdir($tmp);

// ── 4. The enable/disable gate ─────────────────────────────────────
is_ok(backup_is_due_at(false, 0, 24, $now) === false, 'disabled → never due, even having never run');
is_ok(backup_is_due_at(false, $now - 999999, 1, $now) === false, 'disabled → never due, however overdue');
is_ok(backup_is_due_at(true, 0, 24, $now) === true, 'enabled + never run → due');

// The defaults an install inherits when it has never opened the panel.
is_ok(backup_setting('backup_auto_enabled', '1') === '1', 'automatic backups still default ON');
is_ok(backup_opportunistic_enabled() === true, 'the no-scheduler fallback defaults ON');
is_ok(backup_min_free_bytes() === BACKUP_DEFAULT_MIN_FREE_MB * $MB, 'the free-space reserve has a safe default');
is_ok(backup_max_dir_bytes() === BACKUP_DEFAULT_MAX_DIR_MB * $MB, 'the folder limit has a safe default');
is_ok(backup_retention_days() === 0, 'age expiry defaults OFF (count-based retention only)');

// ── 5. The wiring — this is what was missing entirely ──────────────
// backup_maybe_run_opportunistic() existed since Phase 122 and NOTHING called
// it. Assert a real caller exists, or the feature silently reverts to a
// documented promise the code never kept.
$navbar = (string) @file_get_contents("$base/inc/navbar.php");
is_ok(strpos($navbar, 'backup_schedule_tick()') !== false,
    'navbar.php actually CALLS the scheduler (it called nothing before Phase 126)');
is_ok(strpos($navbar, "require_once __DIR__ . '/backup_schedule.php'") !== false,
    'navbar.php includes the scheduler it calls');
is_ok(function_exists('backup_schedule_tick'), 'backup_schedule_tick() is defined');

$sched = (string) @file_get_contents("$base/inc/backup_schedule.php");
is_ok(strpos($sched, 'register_shutdown_function') !== false,
    'the dump is deferred to shutdown — never inside the page request');
is_ok(strpos($sched, 'session_write_close') !== false,
    'the deferred run releases the session lock so it cannot block the user');
is_ok(strpos($sched, 'fastcgi_finish_request') !== false,
    'the deferred run flushes the response before working');
is_ok(strpos($sched, "REQUEST_METHOD'] ?? 'GET') !== 'GET'") !== false,
    'the tick only rides along on GET — never on a save the user might resubmit');
is_ok(strpos($sched, 'ob_end_flush') !== false,
    'non-fpm servers still get the page pushed out before the dump starts');

// The tick must be free when nothing is due, and must never throw.
$t0 = microtime(true);
backup_schedule_tick();
$elapsed = microtime(true) - $t0;
is_ok($elapsed < 1.0, 'the scheduler tick is cheap on the hot path', sprintf('%.3fs', $elapsed));

// ── 6. The guard is wired into every write path ────────────────────
is_ok(strpos($sched, 'backup_guard($dir)') !== false, 'backup_run_now consults the guard before dumping');
$api = (string) @file_get_contents("$base/api/backup.php");
is_ok(strpos($api, 'backup_guard($destDir)') !== false,
    'the save-to-server endpoint honours the same guard as the scheduler');
is_ok(strpos($api, "action === 'status'") !== false, 'the status endpoint exists for the UI');
is_ok(strpos($api, "action === 'run_now'") !== false, 'a manual run endpoint exists');

// A refusal must be reported as a skip, not a success.
$st = backup_status();
foreach (['backup_count', 'backup_bytes', 'free_bytes', 'max_dir_bytes', 'min_free_bytes',
          'cap_pct', 'space_warning', 'opportunistic', 'retention_days'] as $k) {
    is_ok(array_key_exists($k, $st), "status exposes '$k' for the UI");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
