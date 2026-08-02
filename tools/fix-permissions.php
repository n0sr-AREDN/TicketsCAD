<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
/**
 * Put the directories the application writes to into a working state.
 *
 *   sudo php tools/fix-permissions.php              # report, then fix
 *   sudo php tools/fix-permissions.php --check      # report only, change nothing
 *   php tools/fix-permissions.php --dry-run         # show the commands, run none
 *   sudo php tools/fix-permissions.php --owner=NAME # name the operator explicitly
 *
 * This is what tools/deploy.sh runs in place of the `chown -R www-data:www-data`
 * it used to end with. That chown recursed into the backups directory and handed
 * it to the web server, which broke `php tools/backup_run.php` for the operator
 * — twice on live hosts, because a hand repair does not survive the next deploy.
 *
 * WHAT IT WILL NOT DO:
 *   - touch program files. The web server only reads those; 644/755 is enough.
 *   - chown anything that is the install directory, an ancestor of it, or that
 *     carries a .git. That breaks the next `git pull` with "detected dubious
 *     ownership" (git >= 2.35.2, CVE-2022-24765) and is refused, not warned about.
 *   - touch the keys directory. It is 0700 owner-only by design.
 *   - change a directory that is already working, even if its owner is not the
 *     account running this. Preserve first; repair only what actually fails.
 *
 * The policy, the classification and the reasoning all live in
 * inc/install-permissions.php so that the test suite drives the same code this
 * does rather than a copy of it.
 *
 * Exit codes: 0 = everything working, 1 = something could not be established,
 *             2 = at least one directory is still broken.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/install-permissions.php';

$check  = in_array('--check', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

$owner = null;
foreach ($argv as $a) {
    if (strpos($a, '--owner=') === 0) {
        $owner = substr($a, 8);
    }
}

$webUser  = health_check_web_user();
$operator = install_perm_operator($owner);

// Machine-readable: print the web server's account and nothing else, so a
// shell script can use it instead of assuming www-data. Silent + exit 1 when
// it cannot be established, so the caller can tell "unknown" from an answer.
if (in_array('--print-web-user', $argv, true)) {
    if (!empty($webUser['determined']) && ($webUser['name'] ?? null) !== null) {
        echo $webUser['name'] . "\n";
        exit(0);
    }
    exit(1);
}

echo "=== TicketsCAD — directories the application writes to ===\n";
echo 'Web server: ' . ($webUser['determined']
        ? (($webUser['name'] ?? ('uid ' . $webUser['uid'])) . '  (' . $webUser['basis'] . ')')
        : 'COULD NOT BE DETERMINED') . "\n";
echo 'Operator:   ' . ($operator['determined']
        ? ($operator['name'] . '  (' . $operator['basis'] . ')')
        : 'COULD NOT BE DETERMINED — ' . $operator['basis']) . "\n";
if (!$webUser['determined'] && ($webUser['note'] ?? '') !== '') {
    echo wordwrap('NOTE: ' . $webUser['note'], 74, "\n      ") . "\n";
}
echo "\n";

$plan = install_perm_plan($webUser, $operator);

$tag = [
    'ok'      => '[OK]  ',
    'fix'     => '[FIX] ',
    'create'  => '[NEW] ',
    'absent'  => '[--]  ',
    'unsafe'  => '[SKIP]',
    'unknown' => '[UNKN]',
];

$nameOf = function (?int $id, string $kind): string {
    if ($id === null) { return '?'; }
    if ($kind === 'user' && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid($id);
        if (is_array($pw) && isset($pw['name'])) { return $pw['name']; }
    }
    if ($kind === 'group' && function_exists('posix_getgrgid')) {
        $gr = @posix_getgrgid($id);
        if (is_array($gr) && isset($gr['name'])) { return $gr['name']; }
    }
    return (string) $id;
};

foreach ($plan as $row) {
    echo ($tag[$row['state']] ?? '[??]  ') . ' ' . $row['path']
        . '  (' . ($row['role'] === INSTALL_PERM_SHARED ? 'shared with you' : 'web server only') . ")\n";
    if ($row['owner'] !== null) {
        echo '       now:  ' . $nameOf($row['owner'], 'user') . ':' . $nameOf($row['group'], 'group')
            . sprintf(' mode %04o', (int) $row['mode']) . "\n";
    }
    if ($row['state'] === 'fix' || $row['state'] === 'create') {
        echo '       want: ' . $nameOf($row['want_uid'], 'user') . ':' . $nameOf($row['want_gid'], 'group')
            . sprintf(' mode %04o', (int) $row['want_mode']) . "\n";
    }
    if ($row['reason'] !== '') {
        echo '       ' . wordwrap($row['reason'], 68, "\n       ") . "\n";
    }
    echo '       ' . $row['purpose'] . "\n";
}

$todo = array_filter($plan, function ($r) { return in_array($r['state'], ['fix', 'create'], true); });

if ($check) {
    echo "\n--check: nothing was changed.\n";
} elseif (empty($todo)) {
    echo "\nNothing to do — every directory is already in a working state.\n";
} else {
    echo "\n-- Applying --\n";
    foreach (install_perm_apply($plan, $dryRun) as $r) {
        echo ($r['ok'] ? '[done] ' : '[FAIL] ') . $r['path'] . ' — ' . $r['detail'] . "\n";
    }
}

// ── Verify by re-asking the filesystem ───────────────────────────────
// Not by trusting that the calls above returned true: the question is what the
// directory looks like now, which is the same question the Status page asks.
echo "\n-- Verification (re-read from disk) --\n";
$after   = ($check || $dryRun) ? $plan : install_perm_plan($webUser, $operator);
$broken  = 0;
$unknown = 0;
foreach ($after as $row) {
    if ($row['state'] === 'fix' || $row['state'] === 'create') {
        $broken++;
        echo '[CRIT] ' . $row['path'] . ' — ' . $row['reason'] . "\n";
    } elseif ($row['state'] === 'unknown') {
        $unknown++;
        echo '[UNKN] ' . $row['path'] . ' — ' . $row['reason'] . "\n";
    }
}
if ($broken === 0 && $unknown === 0) {
    echo "[OK]   Every directory the application writes to is in a working state.\n";
}

if ($broken > 0) {
    if ($check || $dryRun) {
        echo "\nRun it for real:  sudo php tools/fix-permissions.php\n";
    } else {
        echo "\nStill broken after applying. Are you root? Try: sudo php tools/fix-permissions.php\n";
    }
    exit(2);
}
exit($unknown > 0 ? 1 : 0);
