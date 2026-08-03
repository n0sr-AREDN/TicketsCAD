<?php
/**
 * Cold-process reader for tests/test_par_unavailable_units.php.
 *
 * Prints INCLUDE or EXCLUDE according to par_include_unavailable_units().
 *
 * Exists as a file rather than a `php -r` one-liner for two reasons:
 * escapeshellarg() mangles inline code differently on Windows and POSIX,
 * and — the substantive one — get_variable() caches every setting in a
 * static on first call, so a re-read inside the test's own process would
 * return the cache rather than what was just written. A fresh
 * interpreter is the only way to prove the value actually round-tripped
 * through the `settings` table.
 *
 * File name starts with `_` so tools/test_all.php, which globs
 * test_*.php, does not try to run it as a test.
 *
 * Usage:
 *   php tests/_par_setting_probe.php               → INCLUDE | EXCLUDE
 *   php tests/_par_setting_probe.php roster <id>   → JSON: responder ids
 *                                                    par_assigned_units()
 *                                                    returns for that incident
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/par.php';

if (($argv[1] ?? '') === 'roster') {
    $ids = [];
    foreach (par_assigned_units((int) ($argv[2] ?? 0)) as $r) {
        $ids[] = (int) ($r['id'] ?? 0);
    }
    echo json_encode(['roster' => $ids]);
    exit(0);
}

echo par_include_unavailable_units() ? 'INCLUDE' : 'EXCLUDE';
