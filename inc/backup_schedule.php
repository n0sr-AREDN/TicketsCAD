<?php
/**
 * Phase 122 (2026-07-25) — automatic, verified, restorable backups.
 *
 * Why this exists. A new user lost power with MySQL running, four tables were
 * damaged, and the honest advice at the end of a long recovery was "turn on
 * backups" — advice given three times in one week to three different people.
 * That is a tell that the PRODUCT should be doing this, not the operator. The
 * audience runs TicketsCAD on laptops, Raspberry Pis and mini-PCs, frequently
 * for emergency response. Their hardware will lose power.
 *
 * Three properties this aims for, in order:
 *   1. ON BY DEFAULT. A backup nobody enabled is the backup nobody has.
 *   2. VERIFIED. A backup that has never been read back is a hypothesis, not a
 *      backup — backup_verify() opens every archive it writes and checks the
 *      SQL inside really contains the schema.
 *   3. RESTORABLE. Until Phase 122 there was no restore tool at all; see
 *      tools/restore.php. A backup you cannot restore is a rounding error.
 *
 * Scheduling without cron. Most of these installs are Windows/XAMPP where cron
 * does not exist and Task Scheduler is never set up. So scheduling is
 * OPPORTUNISTIC: backup_maybe_run_opportunistic() is cheap (one settings read
 * plus a timestamp compare), safe to call on ordinary requests, and guarded by
 * a lock so concurrent requests cannot stampede. Operators who *do* have
 * cron/Task Scheduler should call tools/backup_run.php and get exact timing.
 *
 * Settings (settings table, read via get_variable — NOT the `config` table):
 *   backup_auto_enabled     '1' (default ON)
 *   backup_interval_hours   '24'
 *   backup_retention_count  '7'
 *   backup_dir              '' → BACKUP_DIR
 *   backup_last_run_at      unix ts of last ATTEMPT
 *   backup_last_ok_at       unix ts of last VERIFIED success
 *   backup_last_status      'ok' | 'failed: …'
 */

require_once __DIR__ . '/backup.php';

const BACKUP_DEFAULT_INTERVAL_HOURS = 24;
const BACKUP_DEFAULT_RETENTION      = 7;

/** Read a backup setting with a default (settings table via get_variable). */
function backup_setting(string $name, string $default = ''): string {
    if (!function_exists('get_variable')) return $default;
    $v = get_variable($name);
    // get_variable() returns FALSE for an absent setting (not null, not ''), so
    // all three must count as "unset" and fall back to the default. Missing this
    // made backup_auto_enabled() return false on every fresh install — i.e.
    // automatic backups would have shipped silently OFF, which is precisely the
    // failure this feature exists to prevent. Caught by test_backup_schedule.php.
    if ($v === null || $v === false || $v === '') return $default;
    return (string) $v;
}

/** Persist a backup setting. Best-effort — never fatal to a backup run. */
function backup_setting_set(string $name, string $value): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$name, $value]
        );
    } catch (Throwable $e) {
        error_log('[backup] could not persist setting ' . $name . ': ' . $e->getMessage());
    }
}

function backup_auto_enabled(): bool {
    return backup_setting('backup_auto_enabled', '1') === '1';
}

function backup_interval_hours(): int {
    $h = (int) backup_setting('backup_interval_hours', (string) BACKUP_DEFAULT_INTERVAL_HOURS);
    return $h > 0 ? $h : BACKUP_DEFAULT_INTERVAL_HOURS;
}

function backup_retention_count(): int {
    $n = (int) backup_setting('backup_retention_count', (string) BACKUP_DEFAULT_RETENTION);
    return $n > 0 ? $n : BACKUP_DEFAULT_RETENTION;
}

function backup_dir(): string {
    $d = backup_setting('backup_dir', '');
    return $d !== '' ? $d : BACKUP_DIR;
}

/**
 * Pure schedule decision, so the rule is unit-testable without a clock or DB.
 * Due when automatic backups are on AND we have never run, or the interval has
 * elapsed.
 */
function backup_is_due_at(bool $enabled, int $lastRunAt, int $intervalHours, int $now): bool {
    if (!$enabled) return false;
    if ($lastRunAt <= 0) return true;                 // never run → due now
    return ($now - $lastRunAt) >= ($intervalHours * 3600);
}

/** Is a scheduled backup due right now? */
function backup_is_due(): bool {
    return backup_is_due_at(
        backup_auto_enabled(),
        (int) backup_setting('backup_last_run_at', '0'),
        backup_interval_hours(),
        time()
    );
}

/**
 * Open the archive we just wrote and prove it contains a real SQL dump. This is
 * what separates "a file exists" from "a backup exists". Returns [ok, detail].
 */
function backup_verify(string $archivePath): array {
    if (!is_file($archivePath)) return [false, 'archive missing'];
    $size = filesize($archivePath);
    // Only reject an EMPTY file here. Verification must rest on the CONTENT of
    // the dump (below), not on a guessed minimum size: a small database
    // compresses to very little, and an arbitrary byte threshold would reject
    // perfectly good backups while telling us nothing about whether the dump is
    // actually usable.
    if ($size === false || $size < 32) return [false, "archive is empty ({$size} bytes)"];

    $sql = null;
    if (substr($archivePath, -4) === '.zip' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) return [false, 'archive will not open'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (substr($n, -4) === '.sql') { $sql = $zip->getFromIndex($i); break; }
        }
        $zip->close();
        if ($sql === null) return [false, 'archive contains no .sql dump'];
    } elseif (substr($archivePath, -3) === '.gz' && function_exists('gzopen')) {
        $fh = gzopen($archivePath, 'rb');
        if (!$fh) return [false, 'archive will not open'];
        $sql = gzread($fh, 262144);   // first 256 KB is plenty to prove structure
        gzclose($fh);
    } else {
        $sql = file_get_contents($archivePath, false, null, 0, 262144);
    }

    if (!is_string($sql) || $sql === '') return [false, 'dump is empty'];
    if (stripos($sql, 'CREATE TABLE') === false) {
        return [false, 'dump contains no CREATE TABLE statements'];
    }
    return [true, 'verified: readable archive containing schema'];
}

/** Delete the oldest archives beyond the retention count. Returns count pruned. */
function backup_prune(string $dir, int $keep): int {
    $files = glob(rtrim($dir, '/\\') . '/*.{zip,gz,sql}', GLOB_BRACE);
    if (!is_array($files) || count($files) <= $keep) return 0;
    // Oldest first, so the tail is what we keep.
    usort($files, static function ($a, $b) { return filemtime($a) <=> filemtime($b); });
    $pruned = 0;
    foreach (array_slice($files, 0, count($files) - $keep) as $old) {
        if (@unlink($old)) $pruned++;
    }
    return $pruned;
}

/**
 * Create one backup: dump → package → VERIFY → record status → prune.
 * Returns ['ok'=>bool, 'path'=>?string, 'detail'=>string].
 */
function backup_run_now(?string $dir = null): array {
    $dir = $dir ?: backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        $msg = 'cannot create backup directory: ' . $dir;
        backup_setting_set('backup_last_status', 'failed: ' . $msg);
        return ['ok' => false, 'path' => null, 'detail' => $msg];
    }

    backup_setting_set('backup_last_run_at', (string) time());
    $stamp   = date('Ymd-His');
    $tmpSql  = rtrim(sys_get_temp_dir(), '/\\') . "/ticketscad-{$stamp}.sql";
    // backup_extension() already includes the dot ('.zip' / '.sql.gz').
    $archive = rtrim($dir, '/\\') . "/ticketscad-{$stamp}" . backup_extension();

    try {
        if (!backup_dump_sql($tmpSql)) throw new RuntimeException('database dump failed');
        $config = function_exists('backup_export_config') ? backup_export_config() : '{}';
        $made = backup_has_zip()
            ? backup_create_zip($tmpSql, $config, $archive)
            : backup_create_gzip_fallback($tmpSql, $config, $archive);
        if (!$made) throw new RuntimeException('could not write archive');
    } catch (Throwable $e) {
        @unlink($tmpSql);
        $msg = $e->getMessage();
        backup_setting_set('backup_last_status', 'failed: ' . $msg);
        error_log('[backup] FAILED: ' . $msg);
        return ['ok' => false, 'path' => null, 'detail' => $msg];
    }
    @unlink($tmpSql);

    // Prove it, don't assume it.
    [$ok, $detail] = backup_verify($archive);
    if (!$ok) {
        backup_setting_set('backup_last_status', 'failed verification: ' . $detail);
        error_log('[backup] wrote an archive that FAILED verification: ' . $detail);
        return ['ok' => false, 'path' => $archive, 'detail' => 'verification failed: ' . $detail];
    }

    backup_setting_set('backup_last_ok_at', (string) time());
    backup_setting_set('backup_last_status', 'ok');
    $pruned = backup_prune($dir, backup_retention_count());

    return ['ok' => true, 'path' => $archive,
            'detail' => $detail . ($pruned ? "; pruned {$pruned} old backup(s)" : '')];
}

/**
 * Cheap opportunistic hook, safe to call on an ordinary page request: does
 * nothing unless a backup is due, and a lock file keeps concurrent requests
 * from starting several at once. This is what makes backups actually happen on
 * a Windows/XAMPP box where nobody configured a scheduler.
 */
function backup_maybe_run_opportunistic(): void {
    try {
        if (!backup_is_due()) return;
        $lock = rtrim(sys_get_temp_dir(), '/\\') . '/ticketscad-backup.lock';
        $fh = @fopen($lock, 'c');
        if (!$fh) return;
        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return; }  // another run holds it
        if (backup_is_due()) backup_run_now();                        // re-check under lock
        flock($fh, LOCK_UN);
        fclose($fh);
    } catch (Throwable $e) {
        error_log('[backup] opportunistic run error: ' . $e->getMessage());
    }
}

/** Status for the UI / health page. */
function backup_status(): array {
    $lastOk    = (int) backup_setting('backup_last_ok_at', '0');
    $interval  = backup_interval_hours();
    $ageHours  = $lastOk > 0 ? (int) floor((time() - $lastOk) / 3600) : null;
    // Stale once we're past two intervals without a verified success.
    $stale     = ($lastOk <= 0) || ($ageHours !== null && $ageHours > ($interval * 2));
    return [
        'enabled'         => backup_auto_enabled(),
        'interval_hours'  => $interval,
        'retention_count' => backup_retention_count(),
        'directory'       => backup_dir(),
        'last_ok_at'      => $lastOk ?: null,
        'last_ok_age_hours' => $ageHours,
        'last_status'     => backup_setting('backup_last_status', 'never run'),
        'stale'           => $stale,
        'warning'         => $stale
            ? 'No verified backup recently — if this machine lost power now, recent work could be lost.'
            : '',
    ];
}
