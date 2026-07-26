<?php
/**
 * Phase 122 — restore a TicketsCAD backup (CLI).
 *
 * Until now there was NO restore tool. Backups were write-only, which means
 * nobody could be sure they worked — and the one moment you find out is the
 * worst possible moment. This is that missing half.
 *
 *   php tools/restore.php --list
 *   php tools/restore.php --file backups/ticketscad-20260725-2130.zip --dry-run
 *   php tools/restore.php --file backups/ticketscad-20260725-2130.zip --yes
 *
 * Safety, because restoring is destructive by nature:
 *   * --dry-run inspects the archive and reports what WOULD happen. Default is
 *     effectively dry-run: without --yes we stop before touching anything.
 *   * Before writing a single statement we take a SAFETY BACKUP of the current
 *     database, so a restore of the wrong file is itself undoable.
 *   * The archive is verified before we start, not after.
 *
 * Exit codes: 0 success, 1 failure, 2 nothing to do / stopped for confirmation.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup_schedule.php';

function say(string $s): void { echo '[' . date('H:i:s') . "] $s\n"; }
function fail(string $s): void { say('ERROR: ' . $s); exit(1); }

$opts    = getopt('', ['file:', 'list', 'dry-run', 'yes', 'help']);
$dir     = backup_dir();

if (isset($opts['help'])) {
    echo "Restore a TicketsCAD backup.\n\n"
       . "  --list             show available backups\n"
       . "  --file <path>      the archive to restore\n"
       . "  --dry-run          inspect only; change nothing\n"
       . "  --yes              actually perform the restore (required to write)\n\n"
       . "A safety backup of the CURRENT database is taken before anything is written.\n";
    exit(0);
}

// ── --list ─────────────────────────────────────────────────────────────────
if (isset($opts['list'])) {
    $files = glob(rtrim($dir, '/\\') . '/*.{zip,gz,sql}', GLOB_BRACE) ?: [];
    if (!$files) { say('No backups found in ' . $dir); exit(2); }
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    say('Backups in ' . $dir . ' (newest first):');
    foreach ($files as $f) {
        [$ok, $detail] = backup_verify($f);
        printf("  %-52s %10s  %s  %s\n", basename($f),
            backup_format_size((int) filesize($f)),
            date('Y-m-d H:i', filemtime($f)),
            $ok ? 'verified' : 'UNREADABLE (' . $detail . ')');
    }
    exit(0);
}

// ── locate + verify the archive ────────────────────────────────────────────
$file = $opts['file'] ?? '';
if ($file === '') fail('give me --file <path> (or --list to see what is available)');
if (!is_file($file)) {
    $alt = rtrim($dir, '/\\') . '/' . basename($file);
    if (is_file($alt)) { $file = $alt; } else { fail('no such file: ' . $file); }
}

say('Archive: ' . $file . ' (' . backup_format_size((int) filesize($file)) . ')');
[$ok, $detail] = backup_verify($file);
if (!$ok) fail('this archive does not look restorable — ' . $detail);
say('Verified: ' . $detail);

// ── extract the SQL ────────────────────────────────────────────────────────
$sql = null;
if (substr($file, -4) === '.zip') {
    if (!class_exists('ZipArchive')) fail('PHP ZipArchive is not enabled; cannot read a .zip backup');
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) fail('cannot open archive');
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (substr($zip->getNameIndex($i), -4) === '.sql') { $sql = $zip->getFromIndex($i); break; }
    }
    $zip->close();
} elseif (substr($file, -3) === '.gz') {
    $fh = gzopen($file, 'rb');
    if (!$fh) fail('cannot open archive');
    $sql = ''; while (!gzeof($fh)) { $sql .= gzread($fh, 1048576); }
    gzclose($fh);
} else {
    $sql = file_get_contents($file);
}
if (!is_string($sql) || $sql === '') fail('no SQL dump found inside the archive');

preg_match_all('/^\s*CREATE TABLE(?: IF NOT EXISTS)?\s+`?([A-Za-z0-9_]+)`?/mi', $sql, $m);
$tables = array_values(array_unique($m[1] ?? []));
say('Dump contains ' . count($tables) . ' table definition(s), ' . backup_format_size(strlen($sql)) . ' of SQL.');

// What is in the database right now, for an honest before/after.
try {
    $live = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
    say('Current database has ' . $live . ' table(s).');
} catch (Throwable $e) { say('Could not inspect the current database: ' . $e->getMessage()); }

if (isset($opts['dry-run']) || !isset($opts['yes'])) {
    say('');
    say('DRY RUN — nothing has been changed.');
    say('This restore would REPLACE the current contents of database "'
        . ($GLOBALS['db_name'] ?? '?') . '" with the ' . count($tables) . ' table(s) above.');
    say('Re-run with --yes to proceed. A safety backup is taken first.');
    exit(2);
}

// ── safety backup, then restore ────────────────────────────────────────────
say('Taking a safety backup of the CURRENT database first…');
$safety = backup_run_now();
if ($safety['ok']) {
    say('Safety backup: ' . $safety['path']);
} else {
    say('WARNING: safety backup failed (' . $safety['detail'] . ').');
    say('Refusing to restore without one — fix that first, or move the current data aside manually.');
    exit(1);
}

say('Restoring… do not interrupt.');
$pdo = db();
$applied = 0; $errors = 0;
try { $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); } catch (Throwable $e) {}

// Split on semicolons at end-of-line, which is how our dumps are written.
$statements = preg_split('/;\s*[\r\n]+/', $sql);
foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || str_starts_with($stmt, '--') || str_starts_with($stmt, '/*')) continue;
    try { $pdo->exec($stmt); $applied++; }
    catch (Throwable $e) {
        $errors++;
        if ($errors <= 5) say('  statement failed: ' . substr($e->getMessage(), 0, 140));
    }
}
try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e) {}

say('Applied ' . $applied . ' statement(s), ' . $errors . ' failed.');
if ($errors > 0) {
    say('Some statements failed. The safety backup above still holds your pre-restore state.');
    exit(1);
}
say('Restore complete. Open TicketsCAD and confirm your data looks right.');
exit(0);
