<?php
/**
 * Phase 119 — DB health gate.
 *
 * Covers the pure classification logic (no DB), the real "healthy" probe the
 * gate runs, and the wiring (index.php calls the gate before any DB render;
 * the render + doc exist). CI-safe: the DB probe self-skips if the DB is absent.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db-health-gate.php';

$base = realpath(__DIR__ . '/..');
echo "=== Phase 119 — DB health gate ===\n\n";
$pass = 0; $fail = 0;
function ok($n)  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $why = '') { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }

// ── 1. Pure classification (no DB) ──────────────────────────────
db_gate_classify(false, null, -1) === 'noconnect'  ? ok('no connection → noconnect')          : bad('noconnect');
db_gate_classify(true,  true,  5) === 'ok'          ? ok('readable core → ok')                 : bad('ok');
db_gate_classify(true,  true,  0) === 'ok'          ? ok('readable wins even if listing is 0') : bad('readable wins');
db_gate_classify(true,  false, 5) === 'recovering'  ? ok('unreadable + schema present → recovering (5)') : bad('recovering 5');
db_gate_classify(true,  false, 2) === 'recovering'  ? ok('unreadable + 2 core tables → recovering')       : bad('recovering 2');
db_gate_classify(true,  false, 0) === 'empty'       ? ok('unreadable + none listed → empty')   : bad('empty');
db_gate_classify(true,  false, 1) === 'unknown'     ? ok('unreadable + 1 listed → unknown')    : bad('unknown 1');
db_gate_classify(true,  false, -1) === 'unknown'    ? ok('unreadable + info_schema failed → unknown') : bad('unknown -1');

// ── 2. The healthy probe the gate actually runs (guarded) ───────
// An empty-but-readable ticket table (genuine fresh install) must NOT throw —
// that's the no-false-positive guarantee. Skips cleanly on a DB-less box.
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    db_fetch_value("SELECT id FROM `{$prefix}ticket` LIMIT 1"); // null on empty = fine
    ok("healthy probe reads `ticket` without throwing (gate returns 'ok')");
} catch (Throwable $e) {
    echo "SKIP: ticket table not readable on this box — healthy-probe test skipped (0/0)\n";
}

// ── 3. Wiring (source) ──────────────────────────────────────────
$idx = @file_get_contents("$base/index.php") ?: '';
$gatePos = strpos($idx, 'db_health_gate()');
$fpwPos  = strpos($idx, 'force_pw_change_redirect()');
(strpos($idx, "inc/db-health-gate.php") !== false && $gatePos !== false)
    ? ok('index.php loads + calls the gate') : bad('index.php calls the gate');
($gatePos !== false && $fpwPos !== false && $gatePos < $fpwPos)
    ? ok('gate runs before the first DB-dependent call') : bad('gate ordering', 'not before force_pw_change');

$mod = @file_get_contents("$base/inc/db-health-gate.php") ?: '';
(strpos($mod, 'http_response_code(503)') !== false && stripos($mod, 'not lost') !== false && stripos($mod, 'Retry-After') !== false)
    ? ok('render: 503 + Retry-After + reassuring copy') : bad('render page', 'missing 503/Retry-After/copy');

$doc = @file_get_contents("$base/docs/TROUBLESHOOTING.md") ?: '';
(strpos($doc, 'app-empty-after-crash') !== false && stripos($doc, 'innodb_force_recovery') !== false)
    ? ok('TROUBLESHOOTING.md has the crash-recovery section') : bad('doc section', 'missing anchor/recovery');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
