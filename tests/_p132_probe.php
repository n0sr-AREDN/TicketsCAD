<?php
/**
 * Cold-process probe for tests/test_phase132_writer.php.
 *
 * get_variable() caches EVERY setting in a `static` array on first call
 * (inc/functions.php) and never re-reads the `settings` table for the
 * rest of the process. So flipping disposition_required_on_close
 * between '0' and '1' with a plain UPDATE and re-reading it from WITHIN
 * the test's own process would just return whatever was cached at the
 * first call — not what was actually just written. This is the exact
 * problem tests/_par_setting_probe.php exists for (Phase 129's
 * PAR-enabled cache) — a fresh interpreter is the only way to prove
 * incident_update_status_internal() really sees the current DB value of
 * disposition_required_on_close, rather than a value poisoned by
 * whatever else in this process happened to call get_variable() first.
 *
 * File name starts with `_` so tools/test_all.php, which globs
 * test_*.php, does not try to run it as a test.
 *
 * Usage:
 *   php tests/_p132_probe.php close <ticketId> [dispositionId] [skip]
 *     -> JSON: incident_update_status_internal($ticketId, 1, $userId, $extra)
 *        dispositionId: integer id, or '' for none
 *        skip: '1' to set extra['skip_disposition_check']=true, else omit
 *   php tests/_p132_probe.php autoclose_sweep
 *     -> JSON: auto_close_sweep() result
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/_test_admin.php';

$mode = $argv[1] ?? '';

if ($mode === 'close') {
    $ticketId = (int) ($argv[2] ?? 0);
    $dispositionRaw = $argv[3] ?? '';
    $dispositionId = ($dispositionRaw !== '' && (int) $dispositionRaw > 0) ? (int) $dispositionRaw : null;
    $skip = (($argv[4] ?? '') === '1');

    $extra = ['disposition_id' => $dispositionId];
    if ($skip) $extra['skip_disposition_check'] = true;

    $userId = test_admin_user_id();
    $res = incident_update_status_internal($ticketId, 1, $userId, $extra);
    echo json_encode($res);
    exit(0);
}

if ($mode === 'autoclose_sweep') {
    require_once __DIR__ . '/../inc/auto_close.php';
    $res = auto_close_sweep(50);
    echo json_encode($res);
    exit(0);
}

fwrite(STDERR, "Unknown mode: {$mode}\n");
exit(1);
