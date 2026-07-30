<?php
/**
 * Phase 127 (2026-07-29) — scheduled-job heartbeat + stale-work cutoff.
 *
 * What this is defending against, concretely: two ticks were installed into
 * /etc/cron.d on hosts with no cron daemon and never ran once for seven
 * weeks. Restarting them naively would have flushed the whole backlog —
 * held messages delivered weeks late, PAR alarms raised about closed
 * incidents — at a live emergency-response team.
 *
 * These tests drive the REAL sweeps (pending_sweep(), par_run_scheduler())
 * and the REAL tick scripts as subprocesses. They deliberately do NOT
 * hand-seed the state a passing run wants to see; the project has been
 * burned repeatedly by tests that configured the one arrangement that
 * happens to work and so proved nothing about the arrangement real users
 * get. Where a row has to exist to be swept, it is created through
 * pending_enqueue() / par_initiate_cycle() — the production writers.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';
require_once __DIR__ . '/../inc/pending-messages.php';
require_once __DIR__ . '/../inc/par.php';
require_once __DIR__ . '/../inc/health-check.php';

$pass = 0; $fail = 0; $skipped = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }
function skip(string $m): void { global $skipped; $skipped++; echo "  SKIP: $m\n"; }

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "\n=== Phase 127 — scheduled jobs + stale-work cutoff ===\n";

// Everything below needs a database.
$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    skip('No database available — Phase 127 tests need one');
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit(0);
}

$haveTable = sched_table_exists();
if (!$haveTable) {
    skip('scheduled_job_runs missing — run php sql/run_phase127_scheduled_jobs.php');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. The cutoff boundary --\n";
// The boundary is the whole safety property, so it is tested at the exact
// second on both sides rather than "well inside" either.
$now    = 1800000000;
$cutoff = 60;                       // minutes
$edge   = $now - ($cutoff * 60);    // due exactly one cutoff ago

is_ok(sched_is_stale($edge - 1, $now, $cutoff) === true,
      'one second past the cutoff is stale');
is_ok(sched_is_stale($edge, $now, $cutoff) === false,
      'exactly at the cutoff is NOT stale (boundary is inclusive of fresh)');
is_ok(sched_is_stale($edge + 1, $now, $cutoff) === false,
      'one second inside the cutoff is not stale');
is_ok(sched_is_stale($now, $now, $cutoff) === false,
      'due right now is not stale');
is_ok(sched_is_stale($now + 300, $now, $cutoff) === false,
      'not yet due is not stale');
is_ok(sched_is_stale($now - 86400 * 49, $now, $cutoff) === true,
      'the real case — 49 days overdue — is stale');
is_ok(sched_is_stale($now - 86400 * 49, $now, 0) === false,
      'cutoff=0 disables the cutoff entirely (legacy behaviour is reachable)');

$reason = sched_expiry_reason('2026-06-11 14:31:00', $now - 3600, $now, $cutoff);
is_ok(strpos($reason, '2026-06-11 14:31:00') !== false,
      'expiry reason names the scheduled time');
is_ok(strpos($reason, 'sched_stale_cutoff_min=60') !== false,
      'expiry reason names the governing setting and its value');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The cutoff setting round-trips ACROSS PROCESSES --\n";
// get_variable() caches the whole settings table in a static on first read,
// so a write-then-read inside ONE process is answered from cache and would
// pass no matter which of this project's two settings stores the write
// landed in. A subprocess is the only honest witness.
$orig = null;
try { $orig = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name='sched_stale_cutoff_min'"); }
catch (Throwable $e) {}

if ($orig === null || $orig === false) {
    skip('sched_stale_cutoff_min not seeded — run sql/run_phase127_scheduled_jobs.php');
} else {
    try {
        db_query("UPDATE `{$prefix}settings` SET value='37' WHERE name='sched_stale_cutoff_min'");
        $php  = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $root = str_replace('\\', '/', dirname(__DIR__));

        // A temp script, not `php -r` — shell quoting of an inline program
        // differs between cmd.exe and sh and would make this test's result
        // depend on which shell the suite happened to be launched from.
        $probe = sys_get_temp_dir() . '/p127_probe_' . getmypid() . '.php';
        file_put_contents($probe,
            "<?php\n"
          . "require '{$root}/config.php';\n"
          . "require '{$root}/inc/scheduled-jobs.php';\n"
          . "echo sched_stale_cutoff_min(), '|', var_export(get_variable('sched_stale_cutoff_min'), true);\n");
        $out = trim((string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($probe) . ' 2>&1'));
        @unlink($probe);

        $parts = explode('|', $out);
        is_ok(trim($parts[0] ?? '') === '37',
              "a fresh process reads the settings-table value (got '" . substr($out, 0, 60) . "', want 37)");
        // The reader must be looking at `settings` (get_variable), not the
        // separate `config` table (get_setting) — the documented trap where
        // a UI-saved value is read back as its default forever.
        is_ok(strpos($parts[1] ?? '', '37') !== false,
              'get_variable() (the `settings` table) sees the value — not get_setting()/`config`');
    } finally {
        db_query("UPDATE `{$prefix}settings` SET value=? WHERE name='sched_stale_cutoff_min'", [$orig]);
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. pending_sweep() expires stale work instead of sending it --\n";
// Driven through pending_enqueue(), the real writer the routing engine uses.
$colType = '';
try {
    $colType = (string) db_fetch_value(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='status'",
        [$prefix . 'pending_routed_messages']);
} catch (Throwable $e) {}

if (strpos($colType, "'expired'") === false) {
    skip("pending_routed_messages.status has no 'expired' state — run sql/run_phase127_scheduled_jobs.php");
} else {
    $tNow  = time();
    $stale = date('Y-m-d H:i:s', $tNow - 86400 * 40);   // 40 days overdue
    $fresh = date('Y-m-d H:i:s', $tNow - 30);           // 30s overdue

    $idStale = pending_enqueue([
        'channel' => 'local_chat', 'target' => 'p127-test',
        'subject' => 'p127 stale', 'body' => 'should never be delivered',
        'scheduled_send_at' => $stale,
    ]);
    $idFresh = pending_enqueue([
        'channel' => 'local_chat', 'target' => 'p127-test',
        'subject' => 'p127 fresh', 'body' => 'is due now',
        'scheduled_send_at' => $fresh,
    ]);

    if (!$idStale || !$idFresh) {
        skip('pending_enqueue() failed (table missing?) — cannot drive the sweep');
    } else {
        try {
            $r = pending_sweep($tNow, 60);

            $rowStale = db_fetch_one(
                "SELECT status, sent_at, send_error FROM `{$prefix}pending_routed_messages` WHERE id=?",
                [$idStale]);
            $rowFresh = db_fetch_one(
                "SELECT status FROM `{$prefix}pending_routed_messages` WHERE id=?", [$idFresh]);

            is_ok(($rowStale['status'] ?? '') === 'expired',
                  "40-day-old message is 'expired', not sent (got '" . ($rowStale['status'] ?? '?') . "')");
            is_ok(empty($rowStale['sent_at']),
                  'expired message has no sent_at — it genuinely never went out');
            is_ok(!empty($rowStale['send_error']) &&
                  strpos((string) $rowStale['send_error'], 'sched_stale_cutoff_min') !== false,
                  'expired row records WHY, naming the cutoff setting');
            is_ok(($r['expired'] ?? 0) >= 1, 'sweep reports the expiry in its return value');

            // The row still exists — expiry is not deletion.
            $stillThere = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}pending_routed_messages` WHERE id=?", [$idStale]);
            is_ok($stillThere === 1, 'expired message row is retained, not deleted (reversible)');

            // ...and the fresh one was NOT expired. Without this, a cutoff
            // that expires everything would pass every test above.
            is_ok(($rowFresh['status'] ?? '') !== 'expired',
                  "a 30-second-overdue message is NOT expired (got '" . ($rowFresh['status'] ?? '?') . "')");

            // Cutoff disabled => the same stale row would have been attempted.
            $idStale2 = pending_enqueue([
                'channel' => 'local_chat', 'target' => 'p127-test',
                'subject' => 'p127 stale nocutoff', 'body' => 'x',
                'scheduled_send_at' => $stale,
            ]);
            pending_sweep($tNow, 0);
            $s2 = (string) db_fetch_value(
                "SELECT status FROM `{$prefix}pending_routed_messages` WHERE id=?", [$idStale2]);
            is_ok($s2 !== 'expired',
                  "with cutoff=0 the same row is acted on, not expired (got '{$s2}') — proving the cutoff is what expired it");
            db_query("DELETE FROM `{$prefix}pending_routed_messages` WHERE id=?", [$idStale2]);
        } finally {
            db_query("DELETE FROM `{$prefix}pending_routed_messages` WHERE target='p127-test'");
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. par_run_scheduler() does not escalate ancient cycles --\n";
$parCol = '';
try {
    $parCol = (string) db_fetch_value(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='status'",
        [$prefix . 'par_cycles']);
} catch (Throwable $e) {}

if (strpos($parCol, "'expired'") === false) {
    skip("par_cycles.status has no 'expired' state — run sql/run_phase127_scheduled_jobs.php");
} elseif (!par_enabled()) {
    // Phase 129 changed this contract deliberately, so the assertion moved
    // with it. par_run_scheduler() no longer returns before doing anything
    // when PAR is off — that early return froze 10 cycles and 8 unanswered
    // acks on training for 29 days behind a cron job that was running fine.
    // What must still hold when the feature is off is that it does not ACT:
    // nothing initiated, nothing escalated to 'missed'. Stale-cycle expiry
    // is housekeeping and now runs either way; it is asserted directly in
    // tests/test_par_disabled_expiry.php.
    skip('PAR is disabled on this install — escalation path not exercised here');
    $r = par_run_scheduler();
    is_ok(($r['reason'] ?? '') === 'disabled',
          'par_run_scheduler() still reports reason=disabled when PAR is off');
    is_ok(($r['cycles_started'] ?? -1) === 0 && ($r['units_missed'] ?? -1) === 0,
          'disabled scheduler starts nothing and misses nothing');
} else {
    $r = par_run_scheduler(null, 60);
    is_ok(isset($r['units_expired']) && isset($r['cycles_expired']),
          'par_run_scheduler() reports expiry counters');
    is_ok(($r['units_missed'] ?? 0) >= 0, 'par_run_scheduler() completes without error');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4b. An ancient PAR cycle expires instead of raising an alarm --\n";
// The life-safety half, driven end to end. The cycle is created by
// par_initiate_cycle() — the real writer — AT a past timestamp (it takes
// one), so the "old pending cycle" under test is genuinely what production
// produces after an outage, not a row hand-crafted to expire nicely.
if (strpos($parCol, "'expired'") === false) {
    skip('par_cycles has no expired state — covered by the migration test above');
} else {
    $parWas = null;
    $tid = null; $rid = null;
    try {
        $parWas = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name='par_enabled'");
        db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','1')
                  ON DUPLICATE KEY UPDATE value='1'");

        // Fixtures: one ACTIVE incident with one assigned, non-standby unit.
        //
        // Legacy tables carry NOT NULL columns with no DEFAULT (the
        // documented "legacy NOT NULL without DEFAULT" pitfall), and which
        // ones differ by install age. Rather than hardcode a column list
        // that works only here, ask the schema and supply a typed zero for
        // anything that demands a value.
        $fillRequired = function (string $table, array $vals) use ($prefix): array {
            try {
                $cols = db_fetch_all(
                    "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
                        AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL
                        AND EXTRA NOT LIKE '%auto_increment%'",
                    [$prefix . $table]);
                foreach ($cols as $c) {
                    $n = $c['COLUMN_NAME'];
                    if (array_key_exists($n, $vals)) continue;
                    $t = strtolower((string) $c['DATA_TYPE']);
                    if (in_array($t, ['datetime','timestamp'], true))      $vals[$n] = '1970-01-02 00:00:00';
                    elseif (in_array($t, ['date'], true))                  $vals[$n] = '1970-01-02';
                    elseif (in_array($t, ['time'], true))                  $vals[$n] = '00:00:00';
                    elseif (strpos($t, 'int') !== false
                            || in_array($t, ['decimal','float','double'], true)) $vals[$n] = 0;
                    else                                                   $vals[$n] = '';
                }
            } catch (Throwable $e) {}
            return $vals;
        };
        $ins = function (string $table, array $vals) use ($prefix) {
            $cols = array_keys($vals);
            $sql  = "INSERT INTO `{$prefix}{$table}` (`" . implode('`,`', $cols) . "`) VALUES ("
                  . implode(',', array_fill(0, count($cols), '?')) . ')';
            db_query($sql, array_values($vals));
            return (int) db_insert_id();
        };

        $rid = $ins('responder', $fillRequired('responder', [
            'name' => 'P127 Test Unit', 'description' => 'phase127 regression fixture',
            'un_status_id' => 0,
        ]));
        $tid = $ins('ticket', $fillRequired('ticket', [
            'scope' => 'P127 regression', 'status' => 2, 'date' => date('Y-m-d H:i:s'),
        ]));
        $ins('assigns', $fillRequired('assigns', [
            'ticket_id' => $tid, 'responder_id' => $rid, 'user_id' => 0,
            'dispatched' => date('Y-m-d H:i:s'),
        ]));

        $units = par_assigned_units($tid);
        if (empty($units)) {
            skip('fixture unit not picked up by par_assigned_units() — schema variant');
        } else {
            // Real writer, 45 days ago.
            $long = time() - 86400 * 45;
            $res  = par_initiate_cycle($tid, 'manual', null, 'phase127 regression', $long);
            $cid  = (int) ($res['cycle']['id'] ?? 0);
            is_ok($cid > 0, 'par_initiate_cycle() created a 45-day-old pending cycle');

            if ($cid > 0) {
                $acksBefore = (int) db_fetch_value(
                    "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` WHERE par_cycle_id=? AND state='pending'", [$cid]);
                is_ok($acksBefore > 0, 'the cycle really has unanswered units to escalate');

                // The audit table is `newui_audit_log` (via db_table), not
                // `audit_log`, and an old install may not have it at all.
                $auditTbl = function_exists('db_table') ? db_table('newui_audit_log') : null;
                $auditCount = function (string $where, array $args = []) use ($auditTbl): ?int {
                    if (!$auditTbl) return null;
                    try { return (int) db_fetch_value("SELECT COUNT(*) FROM {$auditTbl} WHERE {$where}", $args); }
                    catch (Throwable $e) { return null; }
                };
                // Scoped to THIS cycle. A global par/missed count is
                // order-dependent: any other pending cycle sitting in the
                // database gets swept by the same call and moves the total,
                // so the assertion would pass or fail on unrelated data.
                $missedBefore = $auditCount(
                    "category='par' AND activity='missed' AND summary LIKE ?", ['%cycle #' . $cid . ' %']);

                $r = par_run_scheduler(null, 60);

                $cyc = db_fetch_one("SELECT status, notes FROM `{$prefix}par_cycles` WHERE id=?", [$cid]);
                is_ok(($cyc['status'] ?? '') === 'expired',
                      "45-day-old cycle is 'expired', not escalated (got '" . ($cyc['status'] ?? '?') . "')");
                is_ok(strpos((string) ($cyc['notes'] ?? ''), 'sched_stale_cutoff_min') !== false,
                      'the cycle records WHY it was not escalated');

                $stillMissed = (int) db_fetch_value(
                    "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` WHERE par_cycle_id=? AND state='missed'", [$cid]);
                is_ok($stillMissed === 0,
                      "no unit was marked 'missed' — a missed PAR is a life-safety alarm, not a bookkeeping state");

                $expiredUnits = (int) db_fetch_value(
                    "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` WHERE par_cycle_id=? AND state='expired'", [$cid]);
                is_ok($expiredUnits === $acksBefore,
                      'every unanswered unit is recorded as expired — nothing silently vanished');

                $missedAfter = $auditCount(
                    "category='par' AND activity='missed' AND summary LIKE ?", ['%cycle #' . $cid . ' %']);
                if ($missedBefore === null || $missedAfter === null) {
                    skip('audit table unavailable — escalation-silence check not run');
                } else {
                    is_ok($missedAfter === $missedBefore,
                          'no par/missed audit entry was written — nothing escalated retroactively');
                    $expAudit = $auditCount(
                        "category='par' AND activity='expire' AND target_type='par_cycle' AND target_id=?", [$cid]);
                    is_ok(($expAudit ?? 0) >= 1, 'the decision not to escalate is itself audited');
                }
                is_ok(($r['cycles_expired'] ?? 0) >= 1, 'scheduler reports the expired cycle');

                // And the counterpart: a cycle whose window JUST shut must
                // still be escalated, or "expire everything" would pass.
                $fresh = par_initiate_cycle($tid, 'manual', null, 'phase127 fresh', time() - 600);
                $fid   = (int) ($fresh['cycle']['id'] ?? 0);
                if ($fid > 0) {
                    par_run_scheduler(null, 60);
                    $fcyc = (string) db_fetch_value(
                        "SELECT status FROM `{$prefix}par_cycles` WHERE id=?", [$fid]);
                    $fmiss = (int) db_fetch_value(
                        "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` WHERE par_cycle_id=? AND state='missed'", [$fid]);
                    is_ok($fcyc !== 'expired',
                          "a 10-minute-old cycle is NOT expired (got '{$fcyc}') — the cutoff discriminates");
                    is_ok($fmiss > 0,
                          'a genuinely recent unanswered PAR still escalates to missed');
                }
            }
        }
    } catch (Throwable $e) {
        bad('PAR expiry test errored: ' . $e->getMessage());
    } finally {
        // Restore the master switch and remove every fixture row.
        try {
            if ($parWas === null || $parWas === false) {
                db_query("DELETE FROM `{$prefix}settings` WHERE name='par_enabled'");
            } else {
                db_query("UPDATE `{$prefix}settings` SET value=? WHERE name='par_enabled'", [$parWas]);
            }
            if ($tid) {
                db_query("DELETE a FROM `{$prefix}par_unit_acks` a
                          JOIN `{$prefix}par_cycles` c ON c.id=a.par_cycle_id WHERE c.ticket_id=?", [$tid]);
                db_query("DELETE FROM `{$prefix}par_cycles` WHERE ticket_id=?", [$tid]);
                db_query("DELETE FROM `{$prefix}assigns`   WHERE ticket_id=?", [$tid]);
                db_query("DELETE FROM `{$prefix}ticket`    WHERE id=?", [$tid]);
            }
            if ($rid) db_query("DELETE FROM `{$prefix}responder` WHERE id=?", [$rid]);
        } catch (Throwable $e) {
            echo "  NOTE: fixture cleanup incomplete: " . $e->getMessage() . "\n";
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. The scheduler must not treat CLOSED incidents as active --\n";
// ticket.status 1 = CLOSED, 2 = ACTIVE, 3 = SCHEDULED. The sweep used to
// select IN (0,1,2) — including CLOSED — under a comment promising the
// opposite. api/par.php has always used IN (2,3). A grep is the right test
// here: the defect is a literal in the query, and driving it would require
// enabling PAR and manufacturing a closed incident with live assigns on
// whatever database the suite happens to run against.
//
// Tokenize and inspect only STRING LITERALS. A plain file grep would match
// the comment above the query that quotes the old broken form — the test
// would then fail on a correct file, or, worse, be "fixed" by deleting the
// explanation. Only what PHP will actually send to MySQL counts.
$sqlText = function (string $file): string {
    $src = (string) @file_get_contents($file);
    if ($src === '') return '';
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (!is_array($t)) continue;
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING || $t[0] === T_ENCAPSED_AND_WHITESPACE) {
            $out .= ' ' . $t[1];
        }
    }
    return $out;
};

$parSql = $sqlText(__DIR__ . '/../inc/par.php');
is_ok($parSql !== '', 'inc/par.php tokenized for SQL inspection');
is_ok(strpos($parSql, 'status IN (0, 1, 2)') === false,
      'no query in inc/par.php selects status IN (0, 1, 2) — 1 is CLOSED');
is_ok(substr_count($parSql, 'status IN (0, 2, 3)') === 2,
      'both the primary and the deleted_at-fallback query use IN (0, 2, 3)');

$apiSql = $sqlText(__DIR__ . '/../api/par.php');
is_ok(strpos($apiSql, 'status IN (2, 3)') !== false,
      'api/par.php still uses IN (2, 3) — the two halves of PAR agree on what is live');
is_ok(strpos($apiSql, 'status IN (0, 1, 2)') === false,
      'api/par.php never treated CLOSED as live either');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. The heartbeat is written by the REAL tick scripts --\n";
// Not by calling sched_job_record() here — that would prove only that the
// recorder works, which is exactly the mistake that let a dead job look
// alive. Run the actual tools/*.php as subprocesses and read the row back.
if (!$haveTable) {
    skip('scheduled_job_runs missing — cannot verify the heartbeat');
} else {
    $php  = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $root = dirname(__DIR__);

    foreach ([
        'par_tick'              => $root . '/tools/par_tick.php',
        'pending_messages_tick' => $root . '/tools/pending_messages_tick.php',
    ] as $jobKey => $script) {
        $before = sched_job_last($jobKey);
        $beforeCount = (int) ($before['run_count'] ?? 0);

        $out = (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1');
        $after = sched_job_last($jobKey);

        is_ok($after !== null,
              "{$jobKey}: running the real tick creates a heartbeat row");
        is_ok((int) ($after['run_count'] ?? 0) === $beforeCount + 1,
              "{$jobKey}: run_count advanced by exactly one");
        is_ok(($after['last_status'] ?? '') === 'ok',
              "{$jobKey}: recorded status ok (output: " . trim(substr($out, 0, 60)) . ')');
        is_ok(!empty($after['last_ok_at']),
              "{$jobKey}: last_ok_at is stamped — this is what the health check reads");
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. Health check surfaces the jobs --\n";
$hc = health_check_scheduled_jobs();
is_ok(!empty($hc['checked']), 'health_check_scheduled_jobs() runs');
is_ok(is_array($hc['jobs'] ?? null) && count($hc['jobs']) === 2,
      'both ticks are registered and reported');
is_ok(in_array($hc['severity'] ?? '', ['ok', 'warn', 'critical'], true),
      "section severity is one of ok/warn/critical (got '" . ($hc['severity'] ?? '?') . "')");

$byKey = [];
foreach (($hc['jobs'] ?? []) as $j) $byKey[$j['job']] = $j;
foreach (['par_tick', 'pending_messages_tick'] as $k) {
    is_ok(isset($byKey[$k]), "{$k} appears in the health bundle");
    if (isset($byKey[$k])) {
        foreach (['label','state','severity','required','last_ok_at','interval_s','note','unit'] as $need) {
            if (!array_key_exists($need, $byKey[$k])) { bad("{$k}: missing key '{$need}'"); continue 2; }
        }
        ok("{$k}: carries every key the Status page and CLI render");
        is_ok(in_array($byKey[$k]['state'], ['ok','overdue','never'], true),
              "{$k}: state is one of ok/overdue/never (got '{$byKey[$k]['state']}')");
    }
}

// The bundle must carry it too, or the Status page and banner never see it.
$all = health_check_all();
is_ok(isset($all['scheduled_jobs']),
      'health_check_all() includes the scheduled_jobs section');
is_ok(isset($all['summary']['critical']) && is_int($all['summary']['critical']),
      'health_check_all() summary still well-formed with the new section');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 8. A never-run job is reported, and only alarms when needed --\n";
// This is the seven-week failure expressed as an assertion: a job with no
// last_ok_at must read 'never'. Use a registry key that cannot have run.
$ghost = sched_job_last('p127_nonexistent_job');
is_ok($ghost === null, 'an unknown job has no heartbeat row');
$req = sched_job_required('par_tick');
is_ok(isset($req['required']) && isset($req['why']),
      'sched_job_required() explains itself as well as answering');
is_ok(is_bool($req['required']),
      'requirement is a real boolean, so severity gating is deterministic');

// A FRESH INSTALL MUST NOT BE CRITICAL.
//
// The first version of this check treated "a security label has a send
// delay" as proof the message sweep was needed. run_phase18a seeds a
// 'confidential' label with a 60s delay on EVERY install, so every fresh
// install — and CI — was reported as critically broken before an admin had
// touched anything. Shipped default configuration is not usage. A check
// that cries wolf on a clean install is the same disease as one that stays
// silent for seven weeks: nobody believes it either way.
$delayedLabels = 0;
try {
    $delayedLabels = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}security_labels` WHERE routing_send_delay_secs > 0");
} catch (Throwable $e) {}
$queued = 0;
try {
    $queued = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}pending_routed_messages` WHERE status='pending'");
} catch (Throwable $e) {}

$sweepReq = sched_job_required('pending_messages_tick');
if ($delayedLabels > 0 && $queued === 0) {
    is_ok($sweepReq['required'] === false,
          "a seeded send-delay label ({$delayedLabels} present) does NOT by itself make the sweep required");
} else {
    skip('no seeded send-delay label present to test the false-positive guard against');
}
is_ok(($queued > 0) === ($sweepReq['required'] === true),
      'the sweep is required exactly when something is actually queued');

// PAR defaults to off (run_phase16a seeds par_enabled=0), so on a default
// install neither job may raise anything above 'ok'.
if (!par_enabled() && $queued === 0) {
    $fresh = sched_jobs_status();
    is_ok(($fresh['severity'] ?? '') === 'ok',
          "default install (PAR off, empty queue) is not flagged (got '" . ($fresh['severity'] ?? '?') . "')");
} else {
    skip('this install has PAR enabled or a queued message — fresh-install calibration not asserted here');
}

echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
exit($fail > 0 ? 1 : 0);
