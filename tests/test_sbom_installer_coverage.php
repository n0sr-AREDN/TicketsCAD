<?php
/**
 * SBOM completeness gate — anything an installer in this repository installs
 * MUST appear in the published SBOM.
 *
 * WHY THIS EXISTS. On 2026-07-29 the project announced that its bill of
 * materials "covers the whole dependency chain with no minimum depth". At that
 * moment `services/dvswitch/install-bridge.sh` line 299 pip-installed
 * `onnxruntime` and `piper-tts`, and neither was in the SBOM. They were on the
 * SAME LINE as `numpy`, which WAS listed — because a .py file happened to
 * import numpy and the generator only knew how to read requirements files and
 * import statements. The claim was checkable and wrong, and anyone who diffed
 * the installer against SBOM.txt would have found it in about two minutes.
 *
 * Adding the two names would not have fixed anything. The defect was that
 * nothing compared the installers to the document, so the next package added to
 * a script would have repeated it silently. This test is that comparison.
 *
 * DELIBERATELY NOT A MIRROR OF THE GENERATOR. It re-derives the package lists
 * with its own, simpler scan rather than calling the generator's functions. A
 * test that shares the producer's parser can only ever confirm the parser agrees
 * with itself: the generator's blind spot would be this test's blind spot too.
 * The two implementations are independent on purpose, so a bug in one shows up
 * as a disagreement rather than as shared silence.
 *
 * Usage: php tests/test_sbom_installer_coverage.php
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

echo "=== SBOM covers everything our installers install ===\n\n";

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);

/* ------------------------------------------------------------------ *
 * 1. Load the published SBOM
 * ------------------------------------------------------------------ */
$sbomPath = $root . '/SBOM.cdx.json';
if (!is_file($sbomPath)) {
    echo "[FAIL] SBOM.cdx.json is missing from the repository root\n";
    echo "\n=== Results: 0 passed, 1 failed ===\n";
    exit(1);
}
$bom = json_decode((string) file_get_contents($sbomPath), true);
test('SBOM.cdx.json parses as JSON', is_array($bom) && isset($bom['components']));
if (!is_array($bom) || !isset($bom['components'])) {
    echo "\n=== Results: $passed passed, " . ($failed + 1) . " failed ===\n";
    exit(1);
}

/* Names are compared case-insensitively; PyPI treats "-" and "_" as equivalent
 * in a distribution name, so normalise both. */
$normalise = static function (string $n): string {
    return strtolower(str_replace('_', '-', trim($n)));
};

$sbomNames = [];
foreach ($bom['components'] as $c) {
    $sbomNames[$normalise((string) ($c['name'] ?? ''))] = true;
    /* A purl carries the canonical package name; index that too so a component
     * titled descriptively is still matched by its identifier. */
    if (!empty($c['purl']) && preg_match('~^pkg:[^/]+/(?:[^/]+/)?([^@?\#]+)~', (string) $c['purl'], $m)) {
        $sbomNames[$normalise($m[1])] = true;
    }
}

/* ------------------------------------------------------------------ *
 * 2. Find every file in the SHIPPED tree that can install something
 * ------------------------------------------------------------------ */
$skip = ['specs/', 'coordination/', 'vendor/', 'node_modules/', '.git/', '.claude/', 'backups/'];
$installers = [];

$walk = function (string $dir) use (&$walk, $root, $skip, &$installers): void {
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $abs = $dir . '/' . $e;
        $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
        foreach ($skip as $s) {
            if (strncmp($rel . '/', $s, strlen($s)) === 0) continue 2;
        }
        if (is_dir($abs)) { $walk($abs); continue; }
        if (preg_match('/^services\/[^\/]+\/bench\//', $rel)) continue;
        if (preg_match('/\.sh$/i', $e)
            || preg_match('/^Dockerfile(\..+)?$/i', $e)
            || $rel === 'assets/js/mesh-console.js') {
            $installers[$rel] = $abs;
        }
    }
};
$walk($root);

test('installer files were found to scan', count($installers) >= 4,
    'found ' . count($installers));

/* The three installers the historical defect lived in must be among them —
 * otherwise a rename could quietly empty this gate. */
foreach (['services/dvswitch/install-bridge.sh',
          'services/aprs/install.sh',
          'Dockerfile'] as $required) {
    test("scan includes $required", isset($installers[$required]),
        'if this file moved, update this test rather than deleting the check');
}

/* ------------------------------------------------------------------ *
 * 3. Independently extract what they install
 * ------------------------------------------------------------------ */
$found = [];   // normalised package name => ['pypi'|'deb', file, raw]

foreach ($installers as $rel => $abs) {
    $src = (string) @file_get_contents($abs);
    /* Join backslash continuations — the package lists all wrap. */
    $src = (string) preg_replace('/\\\\\r?\n/', ' ', $src);

    foreach (preg_split('/\r?\n/', $src) ?: [] as $line) {
        if (preg_match('#^\s*(?:\#|//)#', $line)) continue;   // prose, not a command

        foreach ([['pypi', '/\bpip[23]?["\']?\s+install\s+(.*)$/'],
                  ['deb',  '/\bapt(?:-get)?\s+install\s+(.*)$/']] as [$eco, $re]) {
            if (!preg_match($re, $line, $m)) continue;
            $args = preg_split('/\s(?:&&|\|\||[;|>]|\#)/', $m[1], 2)[0];
            foreach (preg_split('/\s+/', trim($args)) ?: [] as $tok) {
                $tok = trim($tok, "\"'");
                if ($tok === '' || $tok[0] === '-') continue;
                if (strpbrk($tok, '$/=*') !== false) continue;
                if (!preg_match('/^[A-Za-z][A-Za-z0-9._+-]*$/', $tok)) continue;
                if (in_array($tok, ['install', 'update', 'upgrade', 'remove',
                                    'purge', 'clean', 'autoremove'], true)) continue;
                $found[$normalise($tok)] = [$eco, $rel, $tok];
            }
        }
    }
}

test('the scan found packages to check', count($found) >= 15,
    'found ' . count($found) . ' — a near-empty scan would make this gate vacuous');

/* ------------------------------------------------------------------ *
 * 4. THE GATE: every one of them must be in the SBOM
 * ------------------------------------------------------------------ */
$missing = [];
foreach ($found as $norm => [$eco, $rel, $raw]) {
    if (!isset($sbomNames[$norm])) $missing[] = "$raw ($eco, installed by $rel)";
}
sort($missing);

test('every package installed by an installer appears in the SBOM',
    $missing === [],
    $missing === []
        ? ''
        : count($missing) . ' missing: ' . implode('; ', array_slice($missing, 0, 8))
          . (count($missing) > 8 ? ' …' : '')
          . ' — run: php tools/generate-sbom.php (then re-sign)');

/* ------------------------------------------------------------------ *
 * 5. Regression pins for the specific defect that prompted this file
 * ------------------------------------------------------------------ */
foreach (['onnxruntime', 'piper-tts'] as $pkg) {
    test("SBOM lists $pkg (missing when the coverage claim was published)",
        isset($sbomNames[$normalise($pkg)]));
}

/* Dependencies with no manifest anywhere: executed or installed indirectly, so
 * neither a requirements file nor an import statement reveals them. They are in
 * the SBOM by explicit declaration, and this pins that they stay there. */
foreach (['esptool', 'meshcore-cli'] as $pkg) {
    test("SBOM lists $pkg (a runtime dependency no manifest declares)",
        isset($sbomNames[$normalise($pkg)]));
}

/* Operating-system packages must be covered too — the apt half of the scan is
 * only a gate if at least one such component actually exists. */
test('SBOM covers operating-system packages installed by our Dockerfiles',
    isset($sbomNames['ffmpeg']) && isset($sbomNames['qemu-user-static']),
    'apt packages from services/dvswitch are absent');

/* ------------------------------------------------------------------ *
 * 6. Nothing may be listed WITHOUT a version unless it says so
 * ------------------------------------------------------------------ *
 * The generator enforces this across every component; checked here for the
 * groups this test is about, because "we added the package but guessed its
 * version" would satisfy section 4 while being the worse outcome. An inaccurate
 * identifier sends a reader to vulnerability data for software nobody is
 * running.
 */
$undeclared = [];
foreach ($bom['components'] as $c) {
    $ref = (string) ($c['bom-ref'] ?? '');
    if (!preg_match('/#(python-services|os-packages|downloaded-artifacts|build-tooling)$/', $ref)) {
        continue;
    }
    if (!empty($c['version'])) continue;          // has a version: fine
    $declared = '';
    $reason   = '';
    foreach ($c['properties'] ?? [] as $p) {
        if ($p['name'] === 'ticketscad:unknown')        $declared = $p['value'];
        if ($p['name'] === 'ticketscad:unknown-reason') $reason   = $p['value'];
    }
    if (strpos($declared, 'Component Version') === false || trim($reason) === '') {
        $undeclared[] = (string) $c['name'];
    }
}
test('components without a version declare it unknown, with a reason',
    $undeclared === [],
    implode(', ', array_slice($undeclared, 0, 6)));

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
