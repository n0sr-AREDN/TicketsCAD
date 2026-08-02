<?php
/**
 * Documentation gate — the AI / third-party-egress disclosure and the
 * supported-versions policy must exist, and must keep naming the services the
 * CODE can actually reach.
 *
 * Why this gate exists. Until 2026-07-30 TicketsCAD shipped a user-facing
 * feature (Radio AI, `radio-ai.php`) that transmits amateur-radio transcripts to
 * a commercial LLM API, and NOTHING in README.md, SECURITY.md,
 * docs/SECURITY-POLICY.md or docs/CJIS-POSTURE.md said so. An agency reads
 * CJIS-POSTURE.md to decide whether the product may touch criminal-justice
 * information; silence there is the worst place for it. The feature is off by
 * default and that is reassuring — but only if somebody is told.
 *
 * The failure mode this guards against is not "the paragraph got deleted"
 * (though it catches that). It is DRIFT: someone adds a second hosted engine, or
 * repoints a driver, and the disclosure quietly stops being true. So the checks
 * below are derived from the code rather than hard-coded prose:
 *
 *   - every external host reachable from the AI/TTS code must be NAMED in
 *     SECURITY.md — add a service without documenting it and this fails;
 *   - the off-by-default claim is checked against the seeded setting value in
 *     sql/run_phase85f_radio_ai.php, not against the sentence that asserts it;
 *   - the "Piper is local" claim is checked by proving engine_piper.php opens no
 *     network connection at all;
 *   - the "no telemetry" claim is checked against inc/, api/ and assets/js.
 *
 * Usage: php tests/test_ai_disclosure.php
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

echo "=== Docs: AI disclosure + supported versions ===\n\n";

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);

$read = function (string $rel) use ($root): string {
    return (string) @file_get_contents($root . '/' . $rel);
};

$readme   = $read('README.md');
$security = $read('SECURITY.md');
$cjis     = $read('docs/CJIS-POSTURE.md');
$policy   = $read('docs/SECURITY-POLICY.md');

// ─────────────────────────────────────────────────────────────
// 1. The code facts the disclosure asserts
// ─────────────────────────────────────────────────────────────
echo "-- The claims, checked against the code --\n";

$aiClient = $read('inc/radio_ai_client.php');
$seed     = $read('sql/run_phase85f_radio_ai.php');
$piper    = $read('inc/tts/engine_piper.php');
$ttsSeed  = $read('sql/run_tts_engines.php');

test('inc/radio_ai_client.php still posts to api.anthropic.com',
    strpos($aiClient, 'https://api.anthropic.com/v1/messages') !== false,
    'the disclosure names this endpoint; if it moved, the docs must move too');

test('Radio AI is seeded OFF (radio_ai_enabled => 0)',
    (bool) preg_match("/'radio_ai_enabled'\s*=>\s*'0'/", $seed),
    'README/SECURITY.md both claim it ships disabled');

test('Radio AI requires an operator-created key file outside the repo',
    strpos($aiClient, "/etc/ticketscad/anthropic.env") !== false
    && !file_exists($root . '/anthropic.env'),
    'the disclosure says no installer creates it');

test('Piper TTS driver opens no network connection (the "local by default" claim)',
    $piper !== '' && !preg_match('~https?://~', $piper)
    && stripos($piper, 'curl_') === false && stripos($piper, 'fsockopen') === false,
    'engine_piper.php must stay a local subprocess call');

test('piper-default is the seeded TTS engine',
    strpos($ttsSeed, "'piper-default', 'piper'") !== false,
    'the disclosure claims a stock install synthesises speech offline');

// ─────────────────────────────────────────────────────────────
// 2. Anti-drift: every host the AI/TTS code can reach is documented
// ─────────────────────────────────────────────────────────────
echo "\n-- Every external host in the AI/TTS code is named in SECURITY.md --\n";

$aiSources = [
    'inc/radio_ai_client.php',
    'inc/tts/engine_deepgram.php',
    'inc/tts/engine_openai_compat.php',
    'inc/tts/engine_piper.php',
    'inc/tts/engine.php',
];
$hosts = [];
foreach ($aiSources as $rel) {
    if (preg_match_all('~https?://([A-Za-z0-9._-]+\.[A-Za-z]{2,})~', $read($rel), $m)) {
        foreach ($m[1] as $h) {
            $h = strtolower(rtrim($h, '.'));
            // Localhost-ish and example hosts are not third parties.
            if (preg_match('~^(127\.|localhost|example\.|0\.0\.0\.0)~', $h)) continue;
            $hosts[$h] = true;
        }
    }
}
test('at least one external AI/TTS host was discovered (the scan works)',
    count($hosts) > 0,
    'if this fails the scan is broken, not the docs');
foreach (array_keys($hosts) as $h) {
    test("SECURITY.md names the external service '$h'",
        stripos($security, $h) !== false,
        'a service the code can reach is missing from the disclosure');
}

// No OTHER hosted LLM may appear without being documented.
echo "\n-- No undocumented LLM provider anywhere in inc/ or api/ --\n";
$llmHosts = ['api.openai.com', 'generativelanguage.googleapis.com', 'api.groq.com',
             'api.mistral.ai', 'openrouter.ai', 'api.cohere.ai', 'api.x.ai',
             'api.deepseek.com', 'api.perplexity.ai', 'api.together.xyz'];
$found = [];
foreach (['inc', 'api'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
    foreach ($it as $f) {
        if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') continue;
        $src = (string) @file_get_contents($f->getPathname());
        foreach ($llmHosts as $h) {
            if (stripos($src, $h) !== false) $found[$h] = true;
        }
    }
}
foreach (array_keys($found) as $h) {
    test("LLM provider '$h' appears in the code and IS disclosed in SECURITY.md",
        stripos($security, $h) !== false,
        'add it to the disclosure, or remove it from the code');
}
test('LLM-provider scan completed', true);

// The "no telemetry / no phone-home" claim, made in three documents.
echo "\n-- The \"no telemetry\" claim --\n";
$telemetry = ['google-analytics.com', 'www.googletagmanager.com', 'sentry.io',
              'matomo.', 'plausible.io', 'segment.io'];
$telemetryHits = [];
foreach (['inc', 'api', 'assets/js'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        // Third-party libraries are inventoried in the SBOM, not here.
        if (strpos(str_replace('\\', '/', $f->getPathname()), '/vendor/') !== false) continue;
        $ext = strtolower($f->getExtension());
        if ($ext !== 'php' && $ext !== 'js') continue;
        $src = (string) @file_get_contents($f->getPathname());
        foreach ($telemetry as $t) {
            if (stripos($src, $t) !== false) $telemetryHits[] = $t . ' in ' . $f->getFilename();
        }
    }
}
test('no analytics/telemetry endpoint in inc/, api/ or assets/js',
    empty($telemetryHits),
    implode('; ', array_slice($telemetryHits, 0, 3)));

// ─────────────────────────────────────────────────────────────
// 3. The disclosure itself
// ─────────────────────────────────────────────────────────────
echo "\n-- SECURITY.md carries the disclosure --\n";

test('SECURITY.md has the outbound-services section',
    stripos($security, 'What TicketsCAD sends outside your network') !== false);
test('SECURITY.md names Radio AI',
    stripos($security, 'Radio AI') !== false);
test('SECURITY.md names the model',
    stripos($security, 'claude-sonnet-4-6') !== false);
test('SECURITY.md states Radio AI is off by default',
    stripos($security, 'radio_ai_enabled') !== false
    && stripos($security, 'Off by default') !== false);
test('SECURITY.md says what is NOT sent (no CAD data)',
    stripos($security, 'never sent') !== false);
test('SECURITY.md discloses the server-side web search',
    stripos($security, 'web_search') !== false);
test('SECURITY.md covers speech-to-text (Vosk / Whisper) as local',
    stripos($security, 'Vosk') !== false && stripos($security, 'whisper') !== false);
test('SECURITY.md covers text-to-speech engines',
    stripos($security, 'Piper') !== false && stripos($security, 'Deepgram') !== false);
test('SECURITY.md names the geocoder that is on by default',
    stripos($security, 'nominatim') !== false);
test('SECURITY.md states there is no telemetry',
    stripos($security, 'telemetry') !== false);
test('SECURITY.md has offline / air-gapped guidance',
    stripos($security, 'air-gapped') !== false);

echo "\n-- README.md carries the summary --\n";

test('README.md has an AI section a reader can find',
    stripos($readme, 'AI features') !== false);
test('README.md names the third party by host',
    stripos($readme, 'api.anthropic.com') !== false);
test('README.md states the feature ships off',
    stripos($readme, 'radio_ai_enabled') !== false);
test('README.md points at the full disclosure in SECURITY.md',
    stripos($readme, 'What TicketsCAD sends outside your network') !== false);
test('README.md states no telemetry',
    stripos($readme, 'telemetry') !== false);

echo "\n-- The compliance documents agree with it --\n";

test('CJIS-POSTURE.md has the outbound-connections section',
    stripos($cjis, 'Outbound connections to third-party services') !== false);
test('CJIS-POSTURE.md names the LLM host',
    stripos($cjis, 'api.anthropic.com') !== false);
test('CJIS-POSTURE.md no longer calls §5.1 simply out of scope',
    !preg_match('~\|\s*§5\.1\s*\|[^|]*\|\s*Out of scope \(org-level\)\s*\|~', $cjis),
    'information exchange is in play once a feature transmits to a third party');
test('CJIS-POSTURE.md recommends radio_ai_enabled stay 0',
    stripos($cjis, 'radio_ai_enabled') !== false);
test('SECURITY-POLICY.md §8 points at the egress disclosure',
    stripos($policy, 'egress filtering') !== false
    && stripos($policy, 'What TicketsCAD sends outside your network') !== false);

// ─────────────────────────────────────────────────────────────
// 4. Supported versions / support status
// ─────────────────────────────────────────────────────────────
echo "\n-- SECURITY.md carries a supported-versions policy --\n";

test('SECURITY.md has a Supported versions section',
    (bool) preg_match('~^##\s+Supported versions~mi', $security));
test('it names the currently supported line',
    stripos($security, 'v4.2.x') !== false);
test('it states the legacy v3.44 line is security + bug fixes only',
    stripos($security, 'v3.44') !== false
    && stripos($security, 'Security and bug fixes only') !== false);
test('it says how a version reaches end of life',
    stripos($security, 'end of life') !== false);
test('it is explicit that there are no backports',
    stripos($security, 'no backports') !== false);
test('it tells the reader how to find their running version',
    stripos($security, 'Help → About') !== false || stripos($security, 'VERSION') !== false);
test('it addresses release cadence rather than leaving it unstated',
    stripos($security, 'cadence') !== false);
test('it routes legacy v3.44 security reports to a real channel',
    stripos($security, 'legacy v3.44') !== false);

// ─────────────────────────────────────────────────────────────
// 5. The disclosure must survive the public snapshot
// ─────────────────────────────────────────────────────────────
echo "\n-- The disclosure reaches the public reader --\n";

// README.md and SECURITY.md are root files, never excluded by
// tools/release-snapshot.sh. Guard against the disclosure being moved into a
// path that IS excluded, which would silently un-publish it.
$excluded = ['specs/', 'coordination/', 'docs/training-scripts/',
             'docs/RADIO-AI-SECURITY-REVIEW.md', 'docs/questions-for-eric.md',
             'BACKLOG.md', 'REVIEW-NOTES.md'];
$linkTargets = [];
if (preg_match_all('~\]\(([^)]+)\)~', $security . "\n" . $readme, $m)) {
    $linkTargets = $m[1];
}
$deadLinks = [];
foreach ($linkTargets as $t) {
    foreach ($excluded as $ex) {
        if (strpos($t, $ex) !== false) $deadLinks[] = $t;
    }
}
test('README.md / SECURITY.md do not link into snapshot-excluded paths',
    empty($deadLinks),
    implode(', ', array_slice($deadLinks, 0, 3)));

test('SECURITY.md and README.md exist at the repository root',
    is_file($root . '/SECURITY.md') && is_file($root . '/README.md'));

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
