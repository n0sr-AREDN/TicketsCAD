<?php
/**
 * Geocoder call-site audit (2026-07-31).
 *
 * THE RULE: exactly ONE file in the browser may know a geocoder's address,
 * and that file is assets/js/geocode.js. Everything else calls
 * Geocode.search() / Geocode.reverse().
 *
 * WHY A GATE, RATHER THAN TRUSTING THE CONVENTION.
 *
 * Before 2026-07-31 there were eleven hand-written copies of
 * `https://nominatim.openstreetmap.org` across six page scripts, and a
 * Settings dropdown offering five providers that no code read. The eleven
 * copies were not one bad decision; they accumulated, one page at a time, each
 * author reasonably copying the page next door. That is exactly the shape a
 * convention cannot stop and a gate can.
 *
 * The consequences were real: an administrator who selected their own
 * geocoding server got nothing at all, offline address lookup was impossible,
 * and every dispatcher's browser disclosed the address being typed — the
 * location of the call — to a third party, from their own IP, uncached and
 * unthrottled, in breach of the usage policy of the one provider it hardcoded.
 *
 * This is the same family as the project's other cross-boundary gates:
 *   tools/schema_audit.php        SQL        vs the real database schema
 *   tools/api_contract_audit.php  JavaScript vs what the API emits
 *   tools/legacy_level_audit.php  API gate   vs the page's RBAC gate
 *   tools/geocode_audit.php       browser    vs "who may contact a geocoder"
 *
 * There is a second, independent reader of the same rule that cannot rot: in
 * the shipped server mode, inc/security-headers.php emits a connect-src with
 * NO geocoder host in it, so a twelfth hardcoded call site fails visibly in
 * every browser rather than silently leaking on every install. This tool
 * catches it at commit time; the CSP catches it at run time.
 *
 * Usage:
 *   php tools/geocode_audit.php            # findings not in the baseline
 *   php tools/geocode_audit.php --all      # include baselined findings
 *
 * Exit 0 = clean, 1 = new findings.
 */

// CLI only, as the first executable statement — the web root is the app root,
// so everything under tools/ is published unless something says otherwise. This
// is the layer that works on any server in any configuration, independent of
// .htaccess (Apache-only) or web.config (IIS-only), and tests/test_web_exposure_hardening.php
// requires it of every script under sql/ and tools/.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
$showAll = in_array('--all', $argv ?? [], true);

/**
 * Hostnames that ARE geocoders. Deliberately a list of geocoding services
 * rather than a generic "any external fetch" rule: the browser legitimately
 * talks to RainViewer, callook.info and radioid.net, and a gate that fires on
 * those would be baselined into uselessness within a week.
 */
function ga_geocoder_hosts(): array
{
    return [
        'nominatim.openstreetmap.org',
        'us1.locationiq.com',
        'locationiq.com',
        'api.geoapify.com',
        'maps.googleapis.com/maps/api/geocode',
        'geocode.search.hereapi.com',
        'revgeocode.search.hereapi.com',
        'photon.komoot.io',
        'photon.komoot.de',
    ];
}

/** Files allowed to name a geocoder. */
function ga_allowed(string $rel): bool
{
    return $rel === 'assets/js/geocode.js';
}

/**
 * Scan one file for geocoder hostnames.
 *
 * Comment lines are skipped: the conversion left explanatory comments naming
 * the old host ("was a hardcoded fetch to nominatim.openstreetmap.org"), and
 * a gate that forbids describing the bug it prevents would push maintainers to
 * delete the explanation. Only code counts.
 *
 * @return array<int,array{0:int,1:string}> [line, trimmed source]
 */
function ga_scan(string $path): array
{
    $out = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return $out;
    }
    $inBlock = false;
    foreach ($lines as $i => $line) {
        $t = ltrim($line);

        // Track /* ... */ blocks so a multi-line explanation is not a finding.
        if ($inBlock) {
            if (strpos($t, '*/') !== false) { $inBlock = false; }
            continue;
        }
        if (strpos($t, '/*') === 0) {
            if (strpos($t, '*/') === false) { $inBlock = true; }
            continue;
        }
        if (strpos($t, '//') === 0 || strpos($t, '*') === 0 || strpos($t, '#') === 0) {
            continue;
        }

        foreach (ga_geocoder_hosts() as $host) {
            if (stripos($line, $host) !== false) {
                $out[] = [$i + 1, trim($line)];
                break;
            }
        }
    }
    return $out;
}

/** Every .js under assets/js, excluding vendored libraries. */
function ga_files(string $root): array
{
    $out = [];
    $dir = $root . '/assets/js';
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'js') {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (strpos($rel, 'assets/js/vendor/') === 0) {
            continue;
        }
        $out[] = $rel;
    }
    sort($out);
    return $out;
}

// ── Baseline ─────────────────────────────────────────────────────────────
$baselineFile = $root . '/tools/geocode_baseline.txt';
$baseline = [];
if (is_file($baselineFile)) {
    foreach (file($baselineFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#') { continue; }
        $baseline[$l] = true;
    }
}

echo "Geocoder call-site audit\n";
echo "========================\n\n";

$new = 0;
$known = 0;
foreach (ga_files($root) as $rel) {
    if (ga_allowed($rel)) {
        continue;
    }
    foreach (ga_scan($root . '/' . $rel) as [$line, $src]) {
        $key = $rel . ' :: ' . $src;
        if (isset($baseline[$key])) {
            $known++;
            if ($showAll) { echo "  [baseline] {$rel}:{$line}\n             {$src}\n"; }
            continue;
        }
        $new++;
        echo "  [NEW] {$rel}:{$line}\n        {$src}\n";
        echo "        -> call Geocode.search() / Geocode.reverse() instead.\n";
        echo "           assets/js/geocode.js is the only file that may name a\n";
        echo "           geocoder; the provider is an administrator's choice and\n";
        echo "           may be a server on their own network. A hardcoded host\n";
        echo "           also fails the Content Security Policy in the shipped\n";
        echo "           server mode. See inc/geocode.php.\n";
    }
}
if ($new === 0) {
    echo "  (none)\n";
}
echo "\nfindings: {$new} new, {$known} baselined\n";

exit($new > 0 ? 1 : 0);
