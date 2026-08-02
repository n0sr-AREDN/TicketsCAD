<?php
/**
 * Geocoder call-site gate — runs tools/geocode_audit.php and fails on any
 * finding not in tools/geocode_baseline.txt (which is, and should stay, empty).
 *
 * THE RULE: exactly one file in the browser may know a geocoder's address —
 * assets/js/geocode.js. Everything else calls Geocode.search()/reverse().
 *
 * WHY THE GATE NEEDS ITS OWN TEST
 *
 * A wiring check that only greps the tool's source would still pass if the
 * tool computed a verdict and then ignored it. So this plants probe files on
 * disk and runs the REAL tool over them:
 *
 *   1. the tree as it stands            → must be clean
 *   2. a probe with a hardcoded fetch   → must be FLAGGED  (it can detect)
 *   3. a probe naming the host only in
 *      a comment                        → must be CLEAN   (it does not cry wolf)
 *   4. geocode.js itself                 → must be CLEAN   (the one allowed file)
 *
 * Without 2, the gate could rot into a no-op and nobody would know. Without 3,
 * it would forbid explaining the bug it prevents, and the explanation would be
 * deleted rather than the gate fixed.
 *
 * Usage: php tests/test_geocode_audit.php
 */

$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;
$tool = $base . '/tools/geocode_audit.php';

$pass = 0; $fail = 0;
function is_ok($cond, string $label) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [ok]   $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}

function run_audit(string $php, string $tool): array {
    $out = [];
    $code = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($tool) . ' 2>&1', $out, $code);
    return [implode("\n", $out), $code];
}

if (!is_file($tool)) {
    echo "SKIP: tools/geocode_audit.php is not present\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

echo "-- the tree as it stands --\n";
[$out, $code] = run_audit($php, $tool);
is_ok($code === 0, 'no geocoder hostname outside assets/js/geocode.js');
if ($code !== 0) {
    echo $out . "\n";
}

// ── 2. NEGATIVE CONTROL: it can still detect the original defect ─────────
echo "\n-- negative control: plant the original defect and confirm it is caught --\n";
$probe = $base . '/assets/js/__geocode_audit_probe.js';
$planted = @file_put_contents($probe, <<<'JS'
(function () {
    'use strict';
    // A twelfth call site of exactly the shape this gate exists to prevent.
    function lookup(q) {
        return fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + q);
    }
    window.__probe = lookup;
})();
JS
) !== false;

if (!$planted) {
    echo "  [note] assets/js is not writable — cannot run the negative control here\n";
} else {
    [$out2, $code2] = run_audit($php, $tool);
    @unlink($probe);
    is_ok($code2 === 1, 'a hardcoded geocoder host in a new file FAILS the gate');
    is_ok(strpos($out2, '__geocode_audit_probe.js') !== false, 'and the offending file is named');
    is_ok(stripos($out2, 'Geocode.search') !== false,
          'and the message says what to do instead');
}

// ── 3. It must not fire on a comment that explains the history ───────────
echo "\n-- it does not cry wolf on comments --\n";
$probe2 = $base . '/assets/js/__geocode_comment_probe.js';
$planted2 = @file_put_contents($probe2, <<<'JS'
(function () {
    'use strict';
    /*
     * Historical note: this used to fetch nominatim.openstreetmap.org
     * directly from the browser. It does not any more.
     */
    // See also: us1.locationiq.com, which we no longer contact from here.
    window.__probe2 = function (q) { return Geocode.search({ q: q }); };
})();
JS
) !== false;

if (!$planted2) {
    echo "  [note] assets/js is not writable — cannot run the comment control here\n";
} else {
    [$out3, $code3] = run_audit($php, $tool);
    @unlink($probe2);
    is_ok($code3 === 0,
          'a geocoder host named only in a comment is NOT flagged — the gate must not forbid '
          . 'explaining the bug it prevents');
}

// ── 4. The one file that is allowed to know ──────────────────────────────
echo "\n-- the shared helper is the single allowed home --\n";
$gj = @file_get_contents($base . '/assets/js/geocode.js');
is_ok(is_string($gj) && strpos($gj, 'nominatim.openstreetmap.org') !== false,
      'assets/js/geocode.js does hold the direct-mode base URL (and is exempt by design)');
is_ok(is_string($gj) && strpos($gj, 'window.Geocode') !== false,
      'and exposes window.Geocode for every call site');

// ── 5. The call sites really do go through it ────────────────────────────
echo "\n-- every former call site now goes through Geocode --\n";
foreach (['assets/js/app.js', 'assets/js/new-incident.js', 'assets/js/incident-detail.js',
          'assets/js/unit-edit.js', 'assets/js/facility-edit.js', 'assets/js/config.js'] as $rel) {
    $src = @file_get_contents($base . '/' . $rel);
    is_ok(is_string($src) && (strpos($src, 'Geocode.search') !== false
                              || strpos($src, 'Geocode.reverse') !== false),
          "$rel calls Geocode.*");
}

// The shared helper has to actually be delivered to the browser, or every one
// of those call sites is a ReferenceError. This is the wiring half.
$navbar = @file_get_contents($base . '/inc/navbar.php');
is_ok(is_string($navbar) && strpos($navbar, 'assets/js/geocode.js') !== false,
      'inc/navbar.php loads geocode.js globally, so every page has it');
is_ok(is_string($navbar) && strpos($navbar, 'window.GEOCODING') !== false,
      'and injects window.GEOCODING synchronously, so the first lookup already knows the mode');

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
