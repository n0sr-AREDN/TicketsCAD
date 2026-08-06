<?php
/**
 * Cold-process probe for tests/test_phase132_settings_panel.php.
 *
 * get_variable() caches every `settings` row in a function-static array
 * on its FIRST call and never re-reads the table for the rest of the
 * process (inc/functions.php) — the same problem tests/_p132_probe.php
 * (Step 2) and tests/_par_setting_probe.php (Phase 129) exist for. This
 * test writes disposition_required_on_close via
 * disposition_set_enforcement_internal() and has to prove a FRESH
 * process reads it back through get_variable() — the exact reader the
 * close-enforcement gate (incident_update_status_internal(), Step 2)
 * uses — rather than a value some earlier call in the SAME process
 * already cached.
 *
 * File name starts with `_` so tools/test_all.php, which globs
 * test_*.php, does not try to run it as a test.
 *
 * Usage:
 *   php tests/_p132_settings_probe.php get_enforcement
 *     -> JSON: {"value": <raw get_variable() return>}
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';

$mode = $argv[1] ?? '';

if ($mode === 'get_enforcement') {
    $v = get_variable('disposition_required_on_close');
    echo json_encode(['value' => $v]);
    exit(0);
}

fwrite(STDERR, "Unknown mode: {$mode}\n");
exit(1);
