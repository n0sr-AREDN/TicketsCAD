<?php
/**
 * Documentation gate — no doc may tell an admin to `chown -R` the whole tree.
 *
 * Regression gate for the mgitnix video QA (findings C1/C2/C3/C7): five shipped
 * documents told self-hosted admins to run `sudo chown -R www-data:www-data .`
 * (or the same thing with an absolute install path). That advice
 *
 *   - hands `.git` to the web server, so the reader's NEXT `git pull` stops with
 *     "fatal: detected dubious ownership" (git >= 2.35.2, CVE-2022-24765);
 *   - is unnecessary — the web server only READS program files, and 644/755
 *     covers that without any ownership change;
 *   - and, when it sweeps in `backups/`, disables `php tools/backup_run.php`
 *     for the very user the same docs tell to run it.
 *
 * The claims encoded here were verified against the code, not the QA report:
 *   - FE_KEYS_DIR      = NEWUI_ROOT . '/../keys'   (inc/field-encrypt.php)  →
 *     keys are OUTSIDE the install dir, so they are not part of a post-clone
 *     ownership fix-up at all.
 *   - BACKUP_DIR       = dirname(NEWUI_ROOT) . '/backups'  (inc/backup.php) →
 *     since v4.2.3 backups are ABOVE the install dir too, for the same reason
 *     the keys are: inside it, an archive was downloadable over HTTP. Created
 *     lazily by backup_run_now(); written by the CLI tool (as the operator) AND
 *     by api/backup.php + the documented cron line (as the web user) — hence
 *     shared ownership, not www-data ownership. BACKUP_DIR_LEGACY still names
 *     the old in-webroot path so existing archives stay listable.
 *
 * Usage: php tests/test_docs_ownership_guidance.php
 */

require_once __DIR__ . '/../config.php';

$passed = 0;
$failed = 0;

function test($label, $condition, $hint = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n";
        $failed++;
    }
}

echo "=== Docs: file-ownership guidance ===\n\n";

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);

// ── The code facts the docs are asserting ─────────────────────
echo "-- The claims, checked against the code --\n";

require_once $root . '/inc/backup.php';
require_once $root . '/inc/field-encrypt.php';

$n = function (string $p): string { return str_replace('\\', '/', $p); };

test('FE_KEYS_DIR resolves OUTSIDE the install directory (../keys)',
    defined('FE_KEYS_DIR') && strpos($n(FE_KEYS_DIR), '/../keys') !== false,
    'FE_KEYS_DIR=' . (defined('FE_KEYS_DIR') ? FE_KEYS_DIR : 'undefined'));
// v4.2.3: backups moved ABOVE the web root, for the same reason the keys are
// there. Inside it, `GET /backups/<archive>.zip` returned a 110 MB database dump
// from a live internet-facing install (2026-07-30). The ownership guidance below
// still applies — the directory has two writers wherever it lives — but it is no
// longer part of the install tree, so a post-clone fix-up must name it explicitly.
test('BACKUP_DIR resolves OUTSIDE the install directory',
    defined('BACKUP_DIR') && strpos($n(BACKUP_DIR), $n($root) . '/') !== 0,
    'BACKUP_DIR=' . (defined('BACKUP_DIR') ? BACKUP_DIR : 'undefined'));
test('the pre-4.2.3 in-webroot path is still known, so old archives are not orphaned',
    defined('BACKUP_DIR_LEGACY') && strpos($n(BACKUP_DIR_LEGACY), $n($root) . '/backups') === 0);
test('backups/ is gitignored (so it is absent on a fresh clone)',
    preg_match('#^/backups/#m', (string) @file_get_contents($root . '/.gitignore')) === 1);
test('backups/ is created lazily by backup_run_now()',
    strpos((string) file_get_contents($root . '/inc/backup_schedule.php'), 'mkdir($dir') !== false);
test('the web UI also writes into BACKUP_DIR (so it cannot be operator-only)',
    strpos((string) file_get_contents($root . '/api/backup.php'), 'BACKUP_DIR') !== false);
test('the CLI backup runs as whoever invokes it (no privilege drop)',
    strpos((string) file_get_contents($root . '/tools/backup_run.php'), 'posix_setuid') === false);

// ── No doc may recommend a whole-tree chown ───────────────────
echo "\n-- No whole-tree chown in any shipped doc --\n";

$docFiles = array_merge(
    [$root . '/README.md'],
    glob($root . '/docs/*.md') ?: []
);

// `chown -R <user>:<group> <target>` where target is "." or an install path
// (not a subdirectory like uploads/, cache/, /var/www/keys, /var/log/newui).
$badPattern = '#chown\s+-R\s+[^\s]+\s+(\.|/var/www/newui|/var/www/html/tickets|newui)\s*$#m';

$offenders = [];
foreach ($docFiles as $f) {
    $src = (string) file_get_contents($f);
    // Lines that deliberately quote the bad command to warn against it are fine.
    $lines = preg_split('/\R/', $src);
    foreach ($lines as $i => $line) {
        if (!preg_match('#chown\s+-R\s+\S+\s+(\.|/var/www/newui|/var/www/html/tickets|newui)\s*$#', trim($line))) {
            continue;
        }
        $context = strtolower(implode(' ', array_slice($lines, max(0, $i - 6), 12)));
        $isWarning = strpos($context, 'do not') !== false
                  || strpos($context, 'do NOT') !== false
                  || strpos($context, 'never ') !== false
                  || strpos($context, 'dubious ownership') !== false
                  || strpos($context, 'take the tree back') !== false;
        if (!$isWarning) {
            $offenders[] = basename($f) . ':' . ($i + 1) . ' → ' . trim($line);
        }
    }
}
test('no doc recommends chown -R on the whole install directory',
    empty($offenders),
    implode(' | ', $offenders));

// ── The five docs the QA named carry the corrected guidance ───
echo "\n-- The corrected guidance is present where it matters --\n";

$switch = (string) @file_get_contents($root . '/docs/SWITCH-FROM-ZIP-TO-GIT.md');
test('SWITCH-FROM-ZIP-TO-GIT.md warns against the dot form',
    strpos($switch, 'chown -R www-data:www-data .') !== false
    && stripos($switch, 'dubious ownership') !== false);
test('SWITCH-FROM-ZIP-TO-GIT.md chowns only uploads/ + cache/',
    strpos($switch, 'chown -R www-data:www-data uploads/ cache/') !== false);
// `../backups/` since v4.2.3 — the folder moved above the web root, but it still
// has two writers, so the shared-ownership advice is unchanged.
test('SWITCH-FROM-ZIP-TO-GIT.md shares the backup folder instead of giving it away',
    strpos($switch, 'chmod 2770 ../backups/') !== false);
test('SWITCH-FROM-ZIP-TO-GIT.md puts the backup folder above the install folder',
    strpos($switch, 'mkdir -p ../backups') !== false);
test('SWITCH-FROM-ZIP-TO-GIT.md says keys live above the install dir',
    stripos($switch, '/var/www/keys') !== false);
test('SWITCH-FROM-ZIP-TO-GIT.md tells the reader to move old in-webroot archives',
    stripos($switch, 'mv backups/ticketscad-*') !== false);

$upd = (string) @file_get_contents($root . '/docs/UPDATE-CHECKLIST.md');
test('UPDATE-CHECKLIST.md carries the ownership table',
    stripos($upd, 'dubious ownership') !== false
    && strpos($upd, 'chmod 2770 ../backups/') !== false);
test('UPDATE-CHECKLIST.md tells the reader the About version now follows the code',
    stripos($upd, 'VERSION') !== false && stripos($upd, 'About') !== false);

foreach (['docs/INSTALL.md', 'docs/INSTALLATION-CHECKLIST.md', 'README.md'] as $rel) {
    $src = (string) @file_get_contents($root . '/' . $rel);
    test("$rel chowns only the runtime dirs",
        strpos($src, 'chown -R www-data:www-data uploads') !== false
        || strpos($src, 'chown -R www-data:www-data newui/uploads') !== false
        || strpos($src, 'chown -R www-data:www-data /var/www/newui/uploads') !== false);
}

// ── The CLI health tool must not suggest it either ────────────
echo "\n-- tools/check-health.php suggestions --\n";
$cli = (string) file_get_contents($root . '/tools/check-health.php');
test('check-health.php no longer suggests chowning the whole root',
    strpos($cli, 'chown -R $webUser:$webUser $root   #') === false);
test('check-health.php suggests chmod fixes that skip .git',
    strpos($cli, "-path '*/.git' -prune") !== false);
// The scoped suggestion must survive — dropping it would trade one bad outcome
// for another, leaving an operator with a real permission fault and no command.
// It is now built from the RESOLVED web server account rather than a hardcoded
// www-data (installs run as apache, nginx, http, or the site owner), and every
// target is passed through _health_recursive_chown_safe() so nothing that could
// carry a .git can ever be printed. The behavioural half of this — reading the
// chown lines out of a real run and checking each target — is in
// tests/test_health_web_user.php.
test('check-health.php still suggests a chown scoped to the individual directory',
    strpos($cli, "sudo chown -R ' . \$wu['name'] . ':' . \$wu['name'] . ' ' . \$d['abs']") !== false);
test('check-health.php guards every chown target against carrying a .git',
    strpos($cli, '_health_recursive_chown_safe($d[\'abs\'])') !== false);
test('check-health.php no longer hardcodes www-data as the account to chown to',
    strpos($cli, 'chown -R www-data:www-data') === false);

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
