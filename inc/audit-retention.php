<?php
/**
 * Phase 133 (2026-08-03) — audit-log retention & purge.
 *
 * WHY THIS EXISTS. A prior session was asked to reconcile docs/CJIS-POSTURE.md
 * with the fact that nothing pruned `newui_audit_log`, and "fixed" it by
 * rewriting the doc to say the table is never pruned and that keeping
 * everything forever satisfies the 365-day CJIS floor. Eric's response,
 * verbatim: "This removes the intent. We need to have a process to delete
 * logs in a way that meets local, state and federal guidelines. Keeping
 * archives forever is not practical and allowing a disk drive to fill up is
 * also unpractical. This is an issue to fix, not redefine. The solution is
 * to build the setting and ensure it works." This file is that setting.
 *
 * THE MODEL, deliberately mirrored on inc/backup_schedule.php because it is
 * the same problem shape (an install running on a laptop/mini-PC/Raspberry Pi
 * cannot be allowed to grow a table without bound, but also cannot be allowed
 * to silently lose the record retention rules require):
 *
 *   1. OFF BY DEFAULT. `audit_log_retention_days` = '0' means disabled/keep
 *      forever. Upgrading TicketsCAD must never start deleting an operator's
 *      history they never configured it to delete.
 *   2. ARCHIVE BEFORE DELETE. Every purge writes a gzip NDJSON archive of the
 *      EXACT rows about to be removed, verifies the write, and only then
 *      deletes those specific row ids. The live table shrinks; the record
 *      does not disappear — it moves to disk, which is what "retention" is
 *      supposed to mean.
 *   3. NO HARD-ENFORCED FLOOR. CJIS cites 365 days as a MINIMUM, and a given
 *      agency's state/local rules may require longer — this application does
 *      not know which apply to a given install, so it warns below 365 and
 *      never blocks. Compliance posture is the operator's call.
 *   4. LOUD FAILURE, NEVER A SILENT NO-OP. docs/CJIS-POSTURE.md recommends
 *      revoking DELETE on the audit table from the app's DB user for
 *      tamper-resistance. If that grant is gone, this purge must not pretend
 *      to have run. audit_retention_check_delete_capability() probes real
 *      DELETE privilege with a zero-row statement BEFORE touching anything,
 *      so a revoked grant is caught before an archive is even written, and
 *      is reported as a FAILURE on the Scheduled Jobs page and in Settings —
 *      never absorbed silently. See docs/CJIS-POSTURE.md for how this
 *      resolves the tamper-resistance-vs-automation tension.
 *
 * Settings live in the `settings` table, read via get_variable() — NOT the
 * `config` table read by get_setting(). Crossing those wires is a documented,
 * previously-real bug in this project (CLAUDE.md, "TWO settings stores") —
 * a value saved by the Settings UI would read back as the default forever.
 */

require_once __DIR__ . '/served-dir.php';
require_once __DIR__ . '/audit.php';

/** CJIS Security Policy §5.4 cites this as a MINIMUM, not a target. Never
 *  used to block a save — only to decide whether to show a warning. */
const AUDIT_RETENTION_CJIS_MIN_DAYS = 365;

/**
 * The default archive directory for an application root, per platform.
 *
 * Sibling of backup_default_dir_for() (inc/backup.php) — same reasoning, same
 * shared served_dir_program_data() helper so the two locations cannot drift
 * apart on a scrubbed Windows environment. Exposed as a function, taking the
 * platform explicitly, so both platforms' answers are assertable from one
 * test machine (see inc/served-dir.php's own history of why "one level up"
 * is not a security boundary independent of the OS).
 *
 * @param string    $appRoot  The application root (NEWUI_ROOT).
 * @param bool|null $windows  NULL = detect from this machine.
 */
function audit_retention_default_dir_for(string $appRoot, ?bool $windows = null): string
{
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    if (!$windows) {
        return dirname($appRoot) . '/audit-archive';
    }
    return served_dir_program_data() . '\\TicketsCAD\\audit-archive';
}

define('AUDIT_ARCHIVE_DIR', audit_retention_default_dir_for(NEWUI_ROOT));

/** Read an audit-retention setting with a default (settings table via get_variable). */
function audit_retention_setting(string $name, string $default = ''): string
{
    if (!function_exists('get_variable')) return $default;
    $v = get_variable($name);
    // get_variable() returns FALSE for an absent setting (not null, not ''),
    // so all three must count as "unset" and fall back to the default — the
    // exact bug class documented at backup_setting()'s definition
    // (inc/backup_schedule.php): missing this would make a fresh install's
    // absent setting read as '' -> (int) 0 by accident rather than by the
    // named default, which happens to be the same value here but must not be
    // relied upon to coincide.
    if ($v === null || $v === false || $v === '') return $default;
    return (string) $v;
}

/** Persist an audit-retention setting. Best-effort — never fatal to a purge. */
function audit_retention_setting_set(string $name, string $value): void
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$name, $value]
        );
    } catch (Throwable $e) {
        error_log('[audit-retention] could not persist setting ' . $name . ': ' . $e->getMessage());
    }
}

/** Retention window in days. 0 = disabled (keep everything). */
function audit_retention_days(): int
{
    $d = (int) audit_retention_setting('audit_log_retention_days', '0');
    return $d > 0 ? $d : 0;
}

/**
 * Pure decision: is $days below the CJIS-cited minimum? Never used to block a
 * save — TicketsCAD does not know which jurisdiction's rules apply to a given
 * install, so this only ever drives a warning.
 */
function audit_retention_below_cjis_floor(int $days): bool
{
    return $days > 0 && $days < AUDIT_RETENTION_CJIS_MIN_DAYS;
}

function audit_retention_below_cjis_floor_warning(int $days): ?string
{
    if (!audit_retention_below_cjis_floor($days)) return null;
    return "Retention is set to {$days} day(s), below the 365-day minimum CJIS Security Policy "
         . '§5.4 requires for Criminal Justice Information. Your state or local rules may require '
         . 'longer still. TicketsCAD does not know which rules apply to your agency, so this is a '
         . 'warning, not a block — confirm this value with your CJIS Systems Officer before relying on it.';
}

/**
 * Operator override for the archive directory, or '' for the platform default.
 */
function audit_retention_setting_dir(): string
{
    return audit_retention_setting('audit_archive_dir', '');
}

/**
 * Can mkdir($dir, …, true) plausibly succeed? Walks up to the nearest
 * existing ancestor and asks whether THAT is writable — same shape as
 * backup_dir_creatable() (inc/backup_schedule.php), because testing only the
 * immediate parent says "not creatable" for a directory a recursive mkdir
 * would happily create.
 */
function audit_retention_dir_creatable(string $dir): bool
{
    $p    = rtrim(str_replace('\\', '/', $dir), '/');
    $seen = 0;
    while ($p !== '' && $seen++ < 24) {
        if (is_dir($p)) return is_writable($p);
        $up = dirname($p);
        if ($up === $p) break;
        $p = $up;
    }
    return false;
}

/** Where this install writes new audit-log archives. */
function audit_retention_dir(): string
{
    $d = audit_retention_setting_dir();
    if ($d !== '') return $d;
    if (is_dir(AUDIT_ARCHIVE_DIR)) return AUDIT_ARCHIVE_DIR;
    if (audit_retention_dir_creatable(AUDIT_ARCHIVE_DIR)) return AUDIT_ARCHIVE_DIR;
    // Nothing creatable either — report the intended default anyway, so any
    // failure message names the directory we actually want rather than an
    // empty string.
    return AUDIT_ARCHIVE_DIR;
}

/**
 * Could this archive directory be published over HTTP by some web site on
 * this machine? Delegates to inc/served-dir.php — the same graded-verdict
 * helper backups and encryption keys use, so a fourth instance of "one level
 * up is not a security boundary" cannot happen here (see served-dir.php's own
 * history for why that assumption has already shipped three times).
 */
function audit_retention_dir_exposure(string $dir): array
{
    return served_dir_exposure($dir);
}

/** Drop deny rules beside the archives when the directory is served or suspect. */
function audit_retention_harden_dir(string $dir): void
{
    served_dir_harden($dir, 'Audit log archives', false);
}

/**
 * How many rows would a purge at $days remove right now? Drives the "N rows
 * eligible" readout so an admin sees the impact BEFORE turning retention on
 * or changing the threshold.
 */
function audit_retention_eligible_count(int $days, ?int $now = null): int
{
    if ($days <= 0) return 0;
    $now    = $now ?? time();
    // date(), not gmdate(): this stack stores DATETIME columns as area-local
    // wall clock (inc/db.php syncs the MySQL session time_zone to PHP's
    // offset on connect) and reads them with NOW() — a UTC-built cutoff would
    // be off by the server's UTC offset against every event_time comparison.
    $cutoff = date('Y-m-d H:i:s', $now - ($days * 86400));
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE `event_time` < ?", [$cutoff]);
    } catch (Throwable $e) {
        error_log('[audit-retention] eligible count failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Probe real DELETE privilege on the audit table WITHOUT deleting anything.
 *
 * `WHERE 1=0` can never match a row, but MySQL/MariaDB evaluate the
 * privilege to run a DELETE statement before evaluating whether the WHERE
 * clause matches anything — so a revoked DELETE grant raises the same
 * "command denied" error (1142) a real purge would hit, with zero risk. This
 * is what lets docs/CJIS-POSTURE.md's tamper-resistance advice (revoke
 * DELETE from the app's DB user) be detected and reported LOUDLY instead of
 * discovered only when a real purge silently deletes nothing.
 *
 * $pdo is accepted for testing: passing a connection (or a stand-in object)
 * with DELETE withheld lets a test drive this exact code path deterministically,
 * without this application ever issuing a REVOKE/GRANT against a real install
 * — TicketsCAD detects privilege state, it does not manage it.
 *
 * @return array{ok:bool,error:string}
 */
function audit_retention_check_delete_capability(?PDO $pdo = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $sql    = "DELETE FROM `{$prefix}newui_audit_log` WHERE 1=0";
    try {
        if ($pdo !== null) {
            $pdo->exec($sql);
        } else {
            db_query($sql);
        }
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Write an NDJSON (one JSON object per line), gzip-compressed archive of the
 * given rows. Returns the absolute path written, or null on failure.
 *
 * Uses gzopen()/gzwrite() — the same tool inc/backup.php already depends on
 * for its gzip fallback path, so this introduces no new PHP extension
 * requirement.
 */
function audit_retention_write_archive(string $path, array $rows): bool
{
    $gz = @gzopen($path, 'wb9');
    if (!$gz) return false;
    try {
        foreach ($rows as $row) {
            $out = $row;
            if (array_key_exists('details', $out) && is_string($out['details']) && $out['details'] !== '') {
                $decoded = json_decode($out['details'], true);
                $out['details'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $out['details'];
            }
            $line = json_encode($out, JSON_UNESCAPED_UNICODE);
            if ($line === false) { gzclose($gz); return false; }
            gzwrite($gz, $line . "\n");
        }
        return true;
    } finally {
        gzclose($gz);
    }
}

/** sha256 of a file, or '' if it cannot be read. */
function audit_retention_file_sha256(string $path): string
{
    $h = @hash_file('sha256', $path);
    return $h !== false ? $h : '';
}

/**
 * The purge orchestrator. Archive-then-delete, never bare-delete.
 *
 * $opts:
 *   'now'                 int    unix ts to evaluate "now" as (tests)
 *   'triggered_by'        string 'scheduled' | 'manual'
 *   'triggered_by_user_id' int|null
 *   'retention_days_override' int|null  TEST-ONLY, never read from request
 *                                 input (api/audit-retention.php never
 *                                 forwards it). get_variable() caches the
 *                                 whole settings table in a static on first
 *                                 read per process (see CLAUDE.md / this
 *                                 project's test_scheduled_jobs.php for the
 *                                 same constraint on sched_stale_cutoff_min),
 *                                 so a single test process cannot exercise
 *                                 "disabled" then "enabled at N days" then
 *                                 "enabled at M days" by writing the setting
 *                                 between calls — each write after the first
 *                                 get_variable() call would be invisible for
 *                                 the rest of the process. This override lets
 *                                 the purge MECHANICS (archive, delete,
 *                                 manifest, capability check) be tested
 *                                 deterministically in-process; the real
 *                                 settings-table read path is covered
 *                                 separately, across a real subprocess
 *                                 boundary, the same way sched_stale_cutoff_min
 *                                 is.
 *   '_capability_pdo'      PDO    TEST-ONLY. Never read from request input —
 *                                 see api/audit-retention.php, which never
 *                                 forwards arbitrary POST fields into $opts.
 *                                 Lets a test drive THIS function's real
 *                                 failure path with a simulated
 *                                 privilege-denied connection, rather than
 *                                 only unit-testing the capability probe in
 *                                 isolation.
 *
 * @return array{ok:bool,skipped:bool,purged:int,detail:string,archive:?string,
 *               purge_id:?int,error:?string}
 */
function audit_purge_run(array $opts = []): array
{
    $now          = $opts['now'] ?? time();
    $triggeredBy  = ($opts['triggered_by'] ?? 'scheduled') === 'manual' ? 'manual' : 'scheduled';
    $triggeredUid = $opts['triggered_by_user_id'] ?? null;
    $prefix       = $GLOBALS['db_prefix'] ?? '';

    // 1. Disabled means disabled. No capability check, no directory touched,
    //    nothing written — an install that has never turned this on must see
    //    zero side effects from it existing.
    $days = array_key_exists('retention_days_override', $opts) && $opts['retention_days_override'] !== null
        ? (int) $opts['retention_days_override']
        : audit_retention_days();
    if ($days < 0) $days = 0;
    if ($days <= 0) {
        return ['ok' => true, 'skipped' => true, 'purged' => 0,
                'detail' => 'disabled (audit_log_retention_days=0)',
                'archive' => null, 'purge_id' => null, 'error' => null];
    }

    // 2. Capability check FIRST — before selecting or archiving anything, so
    //    a revoked DELETE grant never leaves an orphan archive with nothing
    //    to show for it in the manifest.
    $cap = audit_retention_check_delete_capability($opts['_capability_pdo'] ?? null);
    if (!$cap['ok']) {
        $detail = 'DELETE denied — the application database user does not have permission to '
                . 'delete from newui_audit_log (' . $cap['error'] . '). This is the expected result '
                . 'of following the tamper-resistance advice in docs/CJIS-POSTURE.md (revoking DELETE '
                . 'from the app DB user); automated retention cannot run under that configuration. '
                . 'See docs/CJIS-POSTURE.md for the trade-off and the alternative (a dedicated '
                . 'retention DB user with DELETE granted only on this table).';
        audit_retention_setting_set('audit_purge_last_run_at', (string) $now);
        audit_retention_setting_set('audit_purge_last_status', 'failed: ' . $detail);
        $purgeId = audit_retention_record_purge(date('Y-m-d H:i:s', $now), 0, '', '',
            $triggeredBy, $triggeredUid, 'failed', $detail);
        error_log('[audit-retention] purge FAILED (capability): ' . $detail);
        return ['ok' => false, 'skipped' => false, 'purged' => 0, 'detail' => $detail,
                'archive' => null, 'purge_id' => $purgeId, 'error' => $detail];
    }

    // 3. The cutoff. Always strictly in the past once days >= 1, which is
    //    what guarantees the audit_log row written in step 10 (event_time =
    //    NOW()) can never fall inside the range just purged.
    $cutoffTs  = $now - ($days * 86400);
    // date(), not gmdate() — see audit_retention_eligible_count() above for why.
    $cutoffSql = date('Y-m-d H:i:s', $cutoffTs);

    // 4. Select exactly the rows to remove — by full row, so the archive is
    //    complete, and by id, so the delete in step 8 removes precisely what
    //    was archived and nothing a concurrent write added afterward.
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `event_time`, `user_id`, `user_name`, `ip_address`, `category`,
                    `activity`, `severity`, `target_type`, `target_id`, `summary`, `details`
               FROM `{$prefix}newui_audit_log`
              WHERE `event_time` < ?
              ORDER BY `id`",
            [$cutoffSql]
        );
    } catch (Throwable $e) {
        $detail = 'could not select rows to purge: ' . $e->getMessage();
        audit_retention_setting_set('audit_purge_last_status', 'failed: ' . $detail);
        return ['ok' => false, 'skipped' => false, 'purged' => 0, 'detail' => $detail,
                'archive' => null, 'purge_id' => null, 'error' => $detail];
    }

    if (empty($rows)) {
        audit_retention_setting_set('audit_purge_last_run_at', (string) $now);
        audit_retention_setting_set('audit_purge_last_status', 'ok: nothing older than cutoff');
        return ['ok' => true, 'skipped' => false, 'purged' => 0,
                'detail' => 'no rows older than ' . $cutoffSql,
                'archive' => null, 'purge_id' => null, 'error' => null];
    }

    // 5. Ensure the archive directory exists and is fenced if it might be
    //    web-served.
    $dir = audit_retention_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        $detail = 'cannot create audit archive directory: ' . $dir
                . ' (create it yourself, or set a different one in Settings -> Audit Log -> Retention & Purge)';
        audit_retention_setting_set('audit_purge_last_status', 'failed: ' . $detail);
        return ['ok' => false, 'skipped' => false, 'purged' => 0, 'detail' => $detail,
                'archive' => null, 'purge_id' => null, 'error' => $detail];
    }
    audit_retention_harden_dir($dir);

    // 6. Write the archive: gzip NDJSON, one file per run.
    $ids     = array_map(static fn($r) => (int) $r['id'], $rows);
    $oldest  = substr((string) $rows[0]['event_time'], 0, 10);
    $newest  = substr((string) $rows[count($rows) - 1]['event_time'], 0, 10);
    $stamp   = gmdate('Ymd-His', $now);
    $file    = "audit-log-{$oldest}_{$newest}-{$stamp}.jsonl.gz";
    $path    = rtrim($dir, '/\\') . '/' . $file;

    if (!audit_retention_write_archive($path, $rows)) {
        @unlink($path);
        $detail = 'could not write archive: ' . $path;
        audit_retention_setting_set('audit_purge_last_status', 'failed: ' . $detail);
        $purgeId = audit_retention_record_purge($cutoffSql, 0, $file, '',
            $triggeredBy, $triggeredUid, 'failed', $detail);
        return ['ok' => false, 'skipped' => false, 'purged' => 0, 'detail' => $detail,
                'archive' => null, 'purge_id' => $purgeId, 'error' => $detail];
    }

    // 7. Hash it — this is the value an operator or auditor checks the
    //    archive against later.
    $hash = audit_retention_file_sha256($path);

    // 8. Delete exactly the archived rows, by id.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `id` IN ({$placeholders})", $ids);
        $purged = $stmt->rowCount();
    } catch (Throwable $e) {
        $detail = 'archive written but DELETE failed: ' . $e->getMessage()
                . ' — the archive at ' . $path . ' is complete; no rows were removed.';
        audit_retention_setting_set('audit_purge_last_status', 'failed: ' . $detail);
        $purgeId = audit_retention_record_purge($cutoffSql, 0, $file, $hash,
            $triggeredBy, $triggeredUid, 'failed', $detail);
        return ['ok' => false, 'skipped' => false, 'purged' => 0, 'detail' => $detail,
                'archive' => $file, 'purge_id' => $purgeId, 'error' => $detail];
    }

    // 9. The manifest row — how an operator finds this archive later.
    $purgeId = audit_retention_record_purge($cutoffSql, $purged, $file, $hash,
        $triggeredBy, $triggeredUid, 'ok', '');

    // 10. The purge writes its own audit-log entry, AFTER the delete
    //     committed, so its event_time (NOW()) is always after the cutoff —
    //     the record of the purge outlives the purge.
    try {
        audit_log('admin', 'audit_log_purge', 'audit_log_purges', $purgeId,
            "Purged {$purged} audit log row(s) older than {$cutoffSql}",
            ['cutoff' => $cutoffSql, 'rows_purged' => $purged, 'archive' => $file,
             'archive_sha256' => $hash, 'triggered_by' => $triggeredBy],
            AUDIT_HIGH);
    } catch (Throwable $e) {
        error_log('[audit-retention] could not write purge audit entry: ' . $e->getMessage());
    }

    audit_retention_setting_set('audit_purge_last_run_at', (string) $now);
    audit_retention_setting_set('audit_purge_last_status', "ok: purged {$purged} row(s)");

    return ['ok' => true, 'skipped' => false, 'purged' => $purged,
            'detail' => "purged {$purged} row(s); archived to {$file}",
            'archive' => $file, 'purge_id' => $purgeId, 'error' => null];
}

/** Insert one audit_log_purges row. Returns the new id, or null on failure. */
function audit_retention_record_purge(
    string $cutoffSql, int $purged, string $file, string $hash,
    string $triggeredBy, $triggeredUid, string $status, string $detail
): ?int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}audit_log_purges`
                (`cutoff_date`, `rows_purged`, `archive_filename`, `archive_sha256`,
                 `triggered_by`, `triggered_by_user_id`, `status`, `detail`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$cutoffSql, $purged, $file, $hash, $triggeredBy, $triggeredUid, $status, substr($detail, 0, 512)]
        );
        return (int) db_insert_id();
    } catch (Throwable $e) {
        error_log('[audit-retention] could not record purge manifest row: ' . $e->getMessage());
        return null;
    }
}

/** Status for the Settings panel / API. */
function audit_retention_status(): array
{
    $days    = audit_retention_days();
    $lastRun = (int) audit_retention_setting('audit_purge_last_run_at', '0') ?: null;
    $status  = audit_retention_setting('audit_purge_last_status', 'never run');
    $dir     = audit_retention_dir();
    $exposure = audit_retention_dir_exposure($dir);

    return [
        'enabled'          => $days > 0,
        'retention_days'   => $days,
        'cjis_min_days'    => AUDIT_RETENTION_CJIS_MIN_DAYS,
        'below_cjis_floor' => audit_retention_below_cjis_floor($days),
        'cjis_warning'     => audit_retention_below_cjis_floor_warning($days),
        'eligible_count'   => audit_retention_eligible_count($days > 0 ? $days : 0),
        'directory'        => $dir,
        'directory_exposure' => $exposure,
        'last_run_at'      => $lastRun,
        'last_status'      => $status,
        'last_failed'      => strpos($status, 'failed:') === 0,
    ];
}
