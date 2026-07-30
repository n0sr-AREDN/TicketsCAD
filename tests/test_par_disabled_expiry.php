<?php
/**
 * Phase 129 (2026-07-29) — stale PAR cycles expire even when PAR is off.
 *
 * THE DEFECT
 *
 * par_run_scheduler() opened with
 *
 *     if (!par_enabled()) return [... 'reason' => 'disabled'];
 *
 * so the stale-cycle expiry added in Phase 127 — housekeeping, not
 * behaviour — could only ever run while the feature was switched on.
 * Turning PAR off therefore froze every in-flight cycle permanently.
 * Found on training.ticketscad 2026-07-29: 10 pending cycles and 8
 * unanswered acks, all 28-30 days old, sitting behind a par_tick cron
 * that had run 26 times and reported "reason=disabled" every time. The
 * job was healthy. The sweep was unreachable.
 *
 * Frozen is worse than untidy. Re-enabling PAR months later would resume
 * a month-old roll-call and, once past its window, escalate "Unit X
 * missed PAR" about an incident that closed in June — a life-safety
 * alarm with no life-safety event behind it.
 *
 * THE CONTRACT NOW
 *
 *   disabled  ->  expire stale cycles; initiate nothing; escalate nothing
 *   enabled   ->  as before (initiate, escalate, and expire stale)
 *
 * Both halves are asserted here, because a fix that expired everything
 * would satisfy the first half alone. The cycle under test is built by
 * par_initiate_cycle() — the real writer — at a past timestamp, not
 * hand-inserted into the shape that expires nicely.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/par.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';

$pass = 0; $fail = 0; $skipped = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }
function skip(string $m): void { global $skipped; $skipped++; echo "  SKIP: $m\n"; }

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "\n=== Phase 129 — stale PAR expiry with PAR disabled ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    skip('No database available — these tests need one');
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit(0);
}

$parCol = '';
try {
    $parCol = (string) db_fetch_value(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='status'",
        [$prefix . 'par_cycles']);
} catch (Throwable $e) {}
if (strpos($parCol, "'expired'") === false) {
    skip("par_cycles.status has no 'expired' state — run php sql/run_phase127_scheduled_jobs.php");
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit(0);
}

$parWas = null; $tid = null; $rid = null; $switched = false;

try {
    $parWas = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name='par_enabled'");

    // Legacy tables carry NOT NULL columns with no DEFAULT and which ones
    // differ by install age, so ask the schema rather than hardcode a list.
    $fillRequired = function (string $table, array $vals) use ($prefix): array {
        try {
            $cols = db_fetch_all(
                "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
                    AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL
                    AND EXTRA NOT LIKE '%auto_increment%'
                    AND EXTRA NOT LIKE '%GENERATED%'",
                [$prefix . $table]);
            foreach ($cols as $c) {
                $n = $c['COLUMN_NAME'];
                if (array_key_exists($n, $vals)) continue;
                $t = strtolower((string) $c['DATA_TYPE']);
                if (in_array($t, ['datetime','timestamp'], true))      $vals[$n] = '1970-01-02 00:00:00';
                elseif ($t === 'date')                                 $vals[$n] = '1970-01-02';
                elseif ($t === 'time')                                 $vals[$n] = '00:00:00';
                elseif (strpos($t, 'int') !== false
                        || in_array($t, ['decimal','float','double'], true)) $vals[$n] = 0;
                else                                                   $vals[$n] = '';
            }
        } catch (Throwable $e) {}
        return $vals;
    };
    $ins = function (string $table, array $vals) use ($prefix) {
        $cols = array_keys($vals);
        db_query("INSERT INTO `{$prefix}{$table}` (`" . implode('`,`', $cols) . "`) VALUES ("
                 . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($vals));
        return (int) db_insert_id();
    };

    // A cycle can only ever be born while PAR is on — that is the state the
    // real installs were in when these rows were created. So: switch on,
    // create through the writer, switch off, then sweep.
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','1')
              ON DUPLICATE KEY UPDATE value='1'");
    $switched = true;

    $rid = $ins('responder', $fillRequired('responder', [
        'name' => 'P129 Test Unit', 'description' => 'phase129 regression fixture',
        'un_status_id' => 0,
    ]));
    $tid = $ins('ticket', $fillRequired('ticket', [
        'scope' => 'P129 regression', 'status' => 2, 'date' => date('Y-m-d H:i:s'),
    ]));
    $ins('assigns', $fillRequired('assigns', [
        'ticket_id' => $tid, 'responder_id' => $rid, 'user_id' => 0,
        'dispatched' => date('Y-m-d H:i:s'),
    ]));

    if (empty(par_assigned_units($tid))) {
        skip('fixture unit not picked up by par_assigned_units() — schema variant');
    } else {
        // Two cycles, both past their answer window, on opposite sides of
        // the 60-minute stale cutoff.
        $ancient = par_initiate_cycle($tid, 'manual', null, 'phase129 ancient', time() - 86400 * 29);
        $recent  = par_initiate_cycle($tid, 'manual', null, 'phase129 recent',  time() - 600);
        $aid = (int) ($ancient['cycle']['id'] ?? 0);
        $rcid = (int) ($recent['cycle']['id'] ?? 0);
        is_ok($aid > 0 && $rcid > 0, 'par_initiate_cycle() created a 29-day-old and a 10-minute-old cycle');

        $ancientAcks = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` WHERE par_cycle_id=? AND state='pending'", [$aid]);
        is_ok($ancientAcks > 0, 'the ancient cycle really has unanswered units');

        // ── Now switch PAR OFF — the training.ticketscad situation ──────
        db_query("UPDATE `{$prefix}settings` SET value='0' WHERE name='par_enabled'");
        is_ok(par_enabled() === false, 'PAR is switched off for the sweep');

        echo "\n-- 1. The sweep still runs its housekeeping --\n";
        $r = par_run_scheduler(null, 60);

        $acyc = (string) db_fetch_value("SELECT status FROM `{$prefix}par_cycles` WHERE id=?", [$aid]);
        is_ok($acyc === 'expired',
              "the 29-day-old cycle is expired even though PAR is disabled (got '{$acyc}')");

        $aExpired = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` WHERE par_cycle_id=? AND state='expired'", [$aid]);
        is_ok($aExpired === $ancientAcks,
              'every unanswered unit on it is recorded expired — nothing silently vanished');

        $aMissed = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` WHERE par_cycle_id=? AND state='missed'", [$aid]);
        is_ok($aMissed === 0,
              "no unit was marked 'missed' — that is a life-safety alarm, not bookkeeping");

        is_ok(($r['cycles_expired'] ?? 0) >= 1, 'the scheduler reports the expired cycle');

        echo "\n-- 2. …but a disabled feature must not ACT --\n";
        // The half that stops "expire everything" from passing, and the
        // half that keeps a switched-off feature silent.
        $rcyc = (string) db_fetch_value("SELECT status FROM `{$prefix}par_cycles` WHERE id=?", [$rcid]);
        is_ok($rcyc === 'pending',
              "the 10-minute-old cycle is left pending (got '{$rcyc}') — the cutoff discriminates");

        $rMissed = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` WHERE par_cycle_id=? AND state='missed'", [$rcid]);
        is_ok($rMissed === 0,
              'no unit is escalated to missed while PAR is disabled');

        is_ok(($r['units_missed'] ?? -1) === 0, 'the sweep reports zero missed while disabled');
        is_ok(($r['cycles_started'] ?? -1) === 0, 'the sweep initiates no new cycle while disabled');
        is_ok(($r['reason'] ?? '') === 'disabled',
              "the result still says reason=disabled, so the job log can tell 'off' from 'idle'");

        echo "\n-- 3. Re-enabled, the recent cycle escalates normally --\n";
        // Proving the disabled path suppressed escalation rather than
        // destroying the cycle's ability to escalate at all.
        db_query("UPDATE `{$prefix}settings` SET value='1' WHERE name='par_enabled'");
        par_run_scheduler(null, 60);
        $rMissed2 = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` WHERE par_cycle_id=? AND state='missed'", [$rcid]);
        is_ok($rMissed2 > 0,
              'with PAR back on, the same unanswered recent cycle does escalate to missed');
    }
} catch (Throwable $e) {
    bad('PAR disabled-expiry test errored: ' . $e->getMessage());
} finally {
    try {
        if ($switched) {
            if ($parWas === null || $parWas === false) {
                db_query("DELETE FROM `{$prefix}settings` WHERE name='par_enabled'");
            } else {
                db_query("UPDATE `{$prefix}settings` SET value=? WHERE name='par_enabled'", [$parWas]);
            }
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

echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
exit($fail > 0 ? 1 : 0);
