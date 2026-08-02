<?php
/**
 * test_map_layer_prefs.php — per-user map layer visibility.
 *
 * The defect (Eric, 2026-07-31): "If I edit what map layers are visible, that
 * choice is not respected when I reload the page. I don't want to see my
 * facilities on my map but I want to load them if I need them."
 *
 * What verification actually found, which is more specific than "nothing
 * persisted" and matters for what these tests have to prove:
 *
 *   - situation.php persisted NOTHING. No read, no write, for any overlay.
 *   - The dashboard (app.js) DID save and DID restore — but the restore loop
 *     only ever called addTo(). The data groups are `.addTo(map)` at
 *     construction, so a layer switched OFF was re-added every load and the
 *     restore had no branch that could remove it. Turning a layer ON persisted;
 *     turning one OFF was structurally impossible. Facilities-off is exactly
 *     the direction that could not work.
 *   - Everything that did persist went to localStorage: per browser, not per
 *     user.
 *
 * So the single most important property here is SYMMETRY: the load path must
 * REMOVE a layer whose preference is off, not just add ones that are on. That
 * is asserted by running the REAL assets/js/map-layer-prefs.js under node
 * against a stub Leaflet map — not by grepping for wiring, which would have
 * passed for the entire time the dashboard was broken.
 *
 * Section 5 is a NEGATIVE CONTROL: the same harness is re-run against a copy of
 * the source with the remove branch deleted, and the off-layer assertion must
 * then FAIL. A test that cannot fail proves nothing, and this defect's whole
 * history is of code that looked wired and was not.
 *
 * Covered:
 *   1. The catalog + the three-layer precedence (shipped / admin / user), PHP
 *   2. Persistence through the REAL writer and the REAL reader (DB)
 *   3. The endpoint's auth, CSRF and admin gating
 *   4. The real JS: symmetric apply, save-on-toggle, graceful degradation
 *   5. Negative control — remove the fix, the test must go red
 *   6. Every map surface is actually wired to the shared component
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/map-layer-prefs.php';
require_once __DIR__ . '/_test_admin.php';

$base = realpath(__DIR__ . '/..');

echo "=== Map layer visibility preference tests ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. Catalog + precedence (shipped default / admin default / user) --\n";
// ─────────────────────────────────────────────────────────────────────────

$catalog = map_layer_catalog();
is_true(count($catalog) >= 15, 'catalog defines the layer set', (string) count($catalog));

// Every entry must be complete, or the admin UI renders a blank row and the
// JS falls back to "visible" for something nobody can see to turn off.
$incomplete = [];
foreach ($catalog as $id => $meta) {
    if (!preg_match('/^[a-z0-9_]+$/', $id))            $incomplete[] = "$id(id)";
    if (trim((string) ($meta['label'] ?? '')) === '')  $incomplete[] = "$id(label)";
    if (trim((string) ($meta['group'] ?? '')) === '')  $incomplete[] = "$id(group)";
    if (!array_key_exists('default', $meta))           $incomplete[] = "$id(default)";
}
is_true($incomplete === [], 'every catalog entry has id/label/group/default',
    implode(',', $incomplete));

// The layers Eric named must exist under stable ids, and `facilities` must be
// ON by shipped default — otherwise this change would silently take facilities
// away from everyone, which is the opposite of what was asked for.
foreach (['facilities', 'units', 'incidents'] as $id) {
    is_true(isset($catalog[$id]), "catalog has '$id'");
}
is_true(!empty($catalog['facilities']['default']),
    'facilities are ON by shipped default (unchanged for an untouched install)');
is_true(empty($catalog['markups']['default']),
    'markups stay OFF by shipped default (unchanged)');

// Boolean coercion. json_decode gives the STRING "false" for `"false"`, which
// is truthy in PHP — coercing that wrong turns every layer on.
$truthyCases = [
    [true, true], [false, false], [1, true], [0, false],
    ['1', true], ['0', false], ['true', true], ['false', false],
    ['', false], ['no', false], ['off', false], ['yes', true],
];
$coerceOk = true;
foreach ($truthyCases as $c) {
    if (_map_layer_truthy($c[0]) !== $c[1]) {
        $coerceOk = false;
        bad('coerces ' . var_export($c[0], true) . ' to ' . var_export($c[1], true));
    }
}
is_true($coerceOk, 'all ' . count($truthyCases) . ' truthiness cases correct (incl. the string "false")');

// Admin defaults start as the shipped defaults on an install that never set one.
$adminDefaults = map_layer_admin_defaults();
is_true(count($adminDefaults) === count($catalog),
    'admin defaults cover exactly the catalog', count($adminDefaults) . ' vs ' . count($catalog));
$allBool = true;
foreach ($adminDefaults as $v) { if (!is_bool($v)) $allBool = false; }
is_true($allBool, 'admin defaults are all booleans');

// An unknown id in the stored JSON must be ignored, not trusted into existence.
$structDefaults = _map_layer_defaults_struct();
$structIds = [];
foreach ($structDefaults['columns'] as $c) { $structIds[] = $c['id']; }
is_true($structIds === array_keys($catalog),
    'the defaults struct carries exactly the catalog ids, in order');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Persistence through the REAL writer and the REAL reader --\n";
// ─────────────────────────────────────────────────────────────────────────

// This section needs a database. It deliberately drives map_layer_prefs_set()
// (what the endpoint calls) and reads back with map_layer_prefs_get() (what
// navbar.php calls) — no hand-inserted rows, because a test that seeds the row
// itself proves nothing about whether anything ever writes one.
$dbUp = false;
try {
    db_fetch_value("SELECT 1");
    $dbUp = true;
} catch (Throwable $e) {
    $dbUp = false;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$tableUp = false;
if ($dbUp) {
    try {
        $tableUp = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . 'user_screen_prefs']
        );
    } catch (Throwable $e) { $tableUp = false; }
}

if (!$dbUp || !$tableUp) {
    echo "SKIP: no database or user_screen_prefs table — persistence round-trip not exercised\n";
} else {
    $uid = test_admin_user_id();
    // Preserve whatever this user already had, and restore it at the end: a
    // test must not silently rewrite a real person's saved preferences.
    $hadRow = null;
    try {
        $hadRow = db_fetch_value(
            "SELECT prefs_json FROM `{$prefix}user_screen_prefs` WHERE user_id = ? AND screen = ? LIMIT 1",
            [$uid, MAP_LAYER_PREFS_SCREEN]
        );
    } catch (Throwable $e) {}

    // Baseline: with no override, the user sees the admin default.
    map_layer_prefs_reset($uid);
    $fresh = map_layer_prefs_get($uid);
    is_true($fresh['visible']['facilities'] === ($adminDefaults['facilities'] ?? true),
        'with no override, the user sees the administrator default');
    is_true($fresh['has_overrides'] === false, 'a reset user reports no overrides');

    // THE round trip, in the direction that was broken: turn facilities OFF.
    $wrote = map_layer_prefs_set($uid, ['facilities' => false]);
    is_true($wrote === true, 'the real writer reports success');

    $after = map_layer_prefs_get($uid);
    is_true($after['visible']['facilities'] === false,
        'facilities OFF survives a re-read through the real reader');
    is_true($after['has_overrides'] === true, 'the override is reported as an override');

    // The write must be a MERGE, not a replace: a surface that only knows about
    // one layer must not reset the other seventeen.
    is_true($after['visible']['units'] === ($adminDefaults['units'] ?? true),
        'a partial save leaves untouched layers alone');

    // And it must actually be in the database, under the shared Phase 17 table
    // — i.e. we reused the existing per-user preference store rather than
    // inventing a third one.
    $rawRow = db_fetch_value(
        "SELECT prefs_json FROM `{$prefix}user_screen_prefs` WHERE user_id = ? AND screen = ? LIMIT 1",
        [$uid, MAP_LAYER_PREFS_SCREEN]
    );
    is_true(is_string($rawRow) && $rawRow !== '',
        'the preference is stored in user_screen_prefs (reused, not a new table)');
    is_true(is_string($rawRow) && strpos($rawRow, 'facilities') !== false,
        'the stored row names the layer');

    // Turning it back ON must persist too — the OLD dashboard code could only
    // do this direction, so asserting only this would have passed while broken.
    map_layer_prefs_set($uid, ['facilities' => true]);
    is_true(map_layer_prefs_get($uid)['visible']['facilities'] === true,
        'facilities ON persists as well (both directions, not just add)');

    // Reset returns the user to the administrator default.
    map_layer_prefs_set($uid, ['facilities' => false]);
    is_true(map_layer_prefs_get($uid)['visible']['facilities'] === false, 'override in place before reset');
    is_true(map_layer_prefs_reset($uid) === true, 'reset reports success');
    is_true(map_layer_prefs_get($uid)['visible']['facilities'] === ($adminDefaults['facilities'] ?? true),
        'reset restores the administrator default');

    // ── The admin default, and that a user override survives it ──
    // This is the requirement that the two are independent: an org changing its
    // default must not silently rewrite what individuals chose.
    $settingsTable = false;
    try {
        $settingsTable = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . 'settings']
        );
    } catch (Throwable $e) {}

    if (!$settingsTable) {
        echo "SKIP: no settings table — administrator default not exercised\n";
    } else {
        $priorSetting = null;
        try {
            $priorSetting = db_fetch_value(
                "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
                [MAP_LAYER_PREFS_SETTING]
            );
        } catch (Throwable $e) {}

        // Admin turns facilities OFF org-wide.
        is_true(map_layer_prefs_set_admin_defaults(['facilities' => false]) === true,
            'the administrator default writer reports success');

        // get_variable() may memoize within a request; read the effective value
        // through the real reader after clearing any cache the app keeps.
        if (function_exists('get_variable')) {
            // A fresh process would re-read; emulate by checking the stored row
            // directly AND the merged result.
            $storedRaw = db_fetch_value(
                "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
                [MAP_LAYER_PREFS_SETTING]
            );
            $decoded = json_decode((string) $storedRaw, true);
            is_true(is_array($decoded) && array_key_exists('facilities', $decoded)
                    && $decoded['facilities'] === false,
                'the administrator default is stored in the settings store as JSON');
            is_true(is_array($decoded) && count($decoded) === count($catalog),
                'the stored default covers every catalog layer (no partial state)');
        }

        // A user override must beat the admin default, in BOTH directions.
        map_layer_prefs_set($uid, ['facilities' => true]);
        $u = map_layer_prefs_get($uid);
        is_true($u['visible']['facilities'] === true,
            'a user override beats the administrator default (user ON over admin OFF)');

        // Restore whatever was there before this test ran.
        map_layer_prefs_reset($uid);
        try {
            if ($priorSetting === null || $priorSetting === false) {
                db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [MAP_LAYER_PREFS_SETTING]);
            } else {
                db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                         [MAP_LAYER_PREFS_SETTING, $priorSetting]);
            }
        } catch (Throwable $e) {}
    }

    // Restore the user's original row.
    try {
        if ($hadRow === null || $hadRow === false) {
            db_query("DELETE FROM `{$prefix}user_screen_prefs` WHERE user_id = ? AND screen = ?",
                     [$uid, MAP_LAYER_PREFS_SCREEN]);
        } else {
            db_query("INSERT INTO `{$prefix}user_screen_prefs` (user_id, screen, prefs_json)
                      VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE prefs_json = VALUES(prefs_json)",
                     [$uid, MAP_LAYER_PREFS_SCREEN, $hadRow]);
        }
    } catch (Throwable $e) {}
}

// A signed-out caller must get the administrator default rather than an error
// or a blank map — navbar.php calls this on every page.
$anon = map_layer_prefs_get(0);
is_true(isset($anon['visible']['facilities']),
    'an unauthenticated caller still resolves to the administrator default');
is_true(map_layer_prefs_set(0, ['facilities' => false]) === false,
    'a save with no user id is refused rather than writing a global row');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. The endpoint: auth, CSRF, admin gating --\n";
// ─────────────────────────────────────────────────────────────────────────

$epSrc = (string) file_get_contents($base . '/api/map-layer-prefs.php');
is_true(strpos($epSrc, "require_once __DIR__ . '/auth.php'") !== false,
    'endpoint requires an authenticated session');
is_true(strpos($epSrc, 'csrf_verify') !== false,
    'endpoint verifies a CSRF token on POST');
is_true(strpos($epSrc, "rbac_can('action.manage_config')") !== false,
    'setting the ORG default requires action.manage_config');
is_true(strpos($epSrc, "ini_set('display_errors', '0')") !== false,
    'endpoint suppresses display_errors so warnings cannot corrupt the JSON');
is_true(strpos($epSrc, "file_get_contents('php://input')") !== false,
    'endpoint reads the JSON body ($_POST is empty for application/json)');
// A save failure must be logged server-side. The client deliberately ignores
// it, so error_log is the ONLY record that a preference did not stick.
is_true(substr_count($epSrc, 'error_log(') >= 3,
    'save/reset failures are logged server-side', (string) substr_count($epSrc, 'error_log('));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. The REAL JS: symmetric apply, save on toggle --\n";
// ─────────────────────────────────────────────────────────────────────────

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

$jsPath = $base . '/assets/js/map-layer-prefs.js';

/**
 * Run the node harness against a given copy of map-layer-prefs.js.
 * Returns [name => bool] for every check the harness emitted.
 */
function run_js_harness(string $node, string $harnessJs, string $sourcePath): array {
    $h = sys_get_temp_dir() . '/tcad_mlp_harness_' . getmypid() . '_' . mt_rand() . '.js';
    file_put_contents($h, $harnessJs);
    $raw = @shell_exec($node . ' ' . escapeshellarg($h) . ' ' . escapeshellarg($sourcePath) . ' 2>&1');
    @unlink($h);
    $out = [];
    if (!is_string($raw)) return $out;
    foreach (explode("\n", trim($raw)) as $line) {
        $parts = explode('|', trim($line), 3);
        if (count($parts) < 2) continue;
        if ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL') continue;
        $out[$parts[1]] = ['ok' => $parts[0] === 'PASS', 'detail' => $parts[2] ?? ''];
    }
    return $out;
}

$harness = <<<'JS'
// Drive the REAL assets/js/map-layer-prefs.js. Only the browser objects it
// touches are stubbed, so the logic under test is production code.
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

// ── A Leaflet-shaped stub map that records add/remove ──
function StubMap() {
    this._layers = [];
    this._handlers = {};
    this._container = { querySelector: function () { return null; } };
}
StubMap.prototype.hasLayer = function (l) { return this._layers.indexOf(l) !== -1; };
StubMap.prototype.removeLayer = function (l) {
    var i = this._layers.indexOf(l);
    if (i !== -1) { this._layers.splice(i, 1); this.fire('overlayremove', { layer: l }); }
};
StubMap.prototype.addLayer = function (l) {
    if (this._layers.indexOf(l) === -1) { this._layers.push(l); this.fire('overlayadd', { layer: l }); }
};
StubMap.prototype.on = function (ev, fn) {
    (this._handlers[ev] = this._handlers[ev] || []).push(fn);
};
StubMap.prototype.fire = function (ev, e) {
    var hs = this._handlers[ev] || [];
    for (var i = 0; i < hs.length; i++) hs[i](e);
};
StubMap.prototype.getContainer = function () { return this._container; };

function StubLayer(name) { this.name = name; }
StubLayer.prototype.addTo = function (map) { map.addLayer(this); return this; };

// ── Browser globals ──
var posted = [];
global.window = global;
global.document = {
    querySelector: function () { return { getAttribute: function () { return 'CSRF-TOKEN'; } }; },
    createElement: function () {
        return { className: '', href: '', textContent: '', title: '',
                 addEventListener: function () {}, appendChild: function () {} };
    }
};
global.fetch = function (url, opts) {
    posted.push({ url: url, body: JSON.parse(opts.body) });
    return Promise.resolve({ ok: true, json: function () { return Promise.resolve({ ok: true }); } });
};
global.setTimeout = setTimeout;
global.clearTimeout = clearTimeout;

// The payload inc/navbar.php injects: facilities OFF for this user (Eric's
// case), units ON, radar ON, while the ADMIN default has facilities ON.
global.window.MAP_LAYER_PREFS = {
    layers:   { facilities: false, units: true, incidents: true, radar: true, markups: false },
    defaults: { facilities: true,  units: true, incidents: true, radar: false, markups: false }
};

eval(fs.readFileSync(process.argv[2], 'utf8'));
var MLP = global.window.MapLayerPrefs;
check('MapLayerPrefs loaded', !!MLP);

// ── THE regression, executed ──
// Every one of these is `.addTo(map)` at construction, exactly as the real
// surfaces build them. bind() must REMOVE the ones whose preference is off.
var map = new StubMap();
var facilities = new StubLayer('facilities').addTo(map);
var units      = new StubLayer('units').addTo(map);
var radar      = new StubLayer('radar');          // NOT on the map initially
var markups    = new StubLayer('markups').addTo(map);

MLP.bind(map, { facilities: facilities, units: units, radar: radar, markups: markups });

check('a layer the user turned OFF is REMOVED on load (the whole bug)',
      map.hasLayer(facilities) === false);
check('a layer the user left ON stays on', map.hasLayer(units) === true);
check('a layer the user turned ON is ADDED even though it started off',
      map.hasLayer(radar) === true);
check('a layer that is off by default and off for the user stays off',
      map.hasLayer(markups) === false);

// Reconciliation must NOT write back — otherwise every page load posts a save.
check('applying stored prefs does not queue a save', posted.length === 0,
      JSON.stringify(posted));

// ── Toggling persists, in BOTH directions ──
// The old dashboard code could only ever persist "on"; asserting only that
// direction would have passed for the entire time the bug was live.
posted.length = 0;
map.addLayer(facilities);          // operator ticks Facilities back on
MLP.flush();
check('turning a layer ON queues a save', posted.length === 1, JSON.stringify(posted));
check('the ON save names the layer and the value',
      posted.length === 1 && posted[0].body.layers.facilities === true,
      JSON.stringify(posted[0] && posted[0].body));
check('the save carries a CSRF token',
      posted.length === 1 && posted[0].body.csrf_token === 'CSRF-TOKEN');
check('the save goes to the preference endpoint',
      posted.length === 1 && posted[0].url.indexOf('api/map-layer-prefs.php') !== -1,
      posted.length ? posted[0].url : '');

posted.length = 0;
map.removeLayer(units);            // operator unticks Units
MLP.flush();
check('turning a layer OFF queues a save', posted.length === 1, JSON.stringify(posted));
check('the OFF save records false, not a missing key',
      posted.length === 1 && posted[0].body.layers.units === false,
      JSON.stringify(posted[0] && posted[0].body));

// Bursts coalesce: a dispatcher flicking four layers must not fire four POSTs.
posted.length = 0;
map.addLayer(markups);
map.removeLayer(markups);
map.addLayer(markups);
MLP.flush();
check('a burst of toggles coalesces into ONE request', posted.length === 1,
      String(posted.length));
check('the coalesced request carries the FINAL state',
      posted.length === 1 && posted[0].body.layers.markups === true,
      JSON.stringify(posted[0] && posted[0].body));

// The toggles above deliberately mutated the in-memory view (that is how a
// second map on the same page agrees with what the operator just did), so
// restore the as-injected payload before asserting on stored values.
global.window.MAP_LAYER_PREFS = {
    layers:   { facilities: false, units: true, incidents: true, radar: true, markups: false },
    defaults: { facilities: true,  units: true, incidents: true, radar: false, markups: false }
};

// register() must apply the preference to a layer built later (the situation
// screen's EOC unit/facility groups arrive after the first fetch resolves).
var lateMap = new StubMap();
var lateFac = new StubLayer('lateFac').addTo(lateMap);
MLP.register(lateMap, 'facilities', lateFac);
check('register() applies the preference to an asynchronously built layer',
      lateMap.hasLayer(lateFac) === false);

// isVisible / defaultVisible must answer from the injected payload.
check('isVisible reflects the user preference', MLP.isVisible('facilities') === false);
check('defaultVisible reflects the ADMIN default, not the user',
      MLP.defaultVisible('facilities') === true);
check('an unknown layer id defaults to visible rather than vanishing',
      MLP.isVisible('some_new_layer_nobody_catalogued') === true);

// ── Graceful degradation: no injected payload at all ──
// A page that renders a map without the navbar must behave exactly as it did
// before this feature existed — never a blank map.
delete global.window.MAP_LAYER_PREFS;
var degradedMap = new StubMap();
var degradedFac = new StubLayer('f').addTo(degradedMap);
var degradedMk  = new StubLayer('m').addTo(degradedMap);
MLP.bind(degradedMap, { facilities: degradedFac, markups: degradedMk });
check('without an injected payload, a default-ON layer stays on',
      degradedMap.hasLayer(degradedFac) === true);
check('without an injected payload, a default-OFF layer is taken off',
      degradedMap.hasLayer(degradedMk) === false);

console.log(out.join('\n'));
JS;

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    $results = run_js_harness($node, $harness, str_replace('\\', '/', $jsPath));
    if (!$results) {
        bad('node harness ran map-layer-prefs.js', 'no parseable output');
    } else {
        foreach ($results as $name => $r) {
            $r['ok'] ? ok('[js] ' . $name) : bad('[js] ' . $name, $r['detail']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    echo "\n-- 5. NEGATIVE CONTROL: remove the fix, the test must go red --\n";
    // ─────────────────────────────────────────────────────────────────────

    // The entire defect was code that LOOKED wired and was not. So prove this
    // suite can actually detect its absence: strip the remove branch — which
    // is precisely what the old dashboard restore was missing — and re-run.
    // The off-layer assertions must flip to failing. If they still pass, the
    // test is not testing anything and must not be trusted.
    $src = (string) file_get_contents($jsPath);
    $crippled = str_replace(
        '        } else if (!want && has) {' . "\n" . '            map.removeLayer(layer);',
        '        } else if (false) {' . "\n" . '            map.removeLayer(layer);',
        $src
    );
    is_true($crippled !== $src,
        'negative control could locate the remove branch to disable');

    if ($crippled !== $src) {
        $tmpJs = sys_get_temp_dir() . '/tcad_mlp_crippled_' . getmypid() . '.js';
        file_put_contents($tmpJs, $crippled);
        $negRes = run_js_harness($node, $harness, str_replace('\\', '/', $tmpJs));
        @unlink($tmpJs);

        $key = 'a layer the user turned OFF is REMOVED on load (the whole bug)';
        is_true(isset($negRes[$key]) && $negRes[$key]['ok'] === false,
            'NEGATIVE CONTROL: with the remove branch disabled, the off-layer test FAILS',
            isset($negRes[$key]) ? 'it still passed — the test proves nothing' : 'check did not run');

        // The add direction must STILL pass while crippled — that is the point:
        // the old code did that fine, which is why the bug survived so long.
        $addKey = 'a layer the user turned ON is ADDED even though it started off';
        is_true(isset($negRes[$addKey]) && $negRes[$addKey]['ok'] === true,
            'NEGATIVE CONTROL: the ADD direction still passes while crippled '
            . '(exactly why the old bug went unnoticed)');

        $lateKey = 'register() applies the preference to an asynchronously built layer';
        is_true(isset($negRes[$lateKey]) && $negRes[$lateKey]['ok'] === false,
            'NEGATIVE CONTROL: register() also loses its off-layer behaviour');
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Every map surface is wired to the shared component --\n";
// ─────────────────────────────────────────────────────────────────────────

// navbar must inject the payload SYNCHRONOUSLY and load the script globally.
// An async fetch would show the operator the layers they switched off and then
// remove them — a visible flash on every load of the screen they stare at.
$navSrc = (string) file_get_contents($base . '/inc/navbar.php');
is_true(strpos($navSrc, 'window.MAP_LAYER_PREFS') !== false,
    'navbar.php injects window.MAP_LAYER_PREFS server-side');
is_true(strpos($navSrc, 'map_layer_prefs_for_js') !== false,
    'navbar builds the payload from the real resolver, not a copy');
is_true(strpos($navSrc, 'assets/js/map-layer-prefs.js') !== false,
    'navbar loads map-layer-prefs.js on every page (no per-page tag to forget)');

// The injection must sit in the same synchronous <script> block as TILE_PROXY,
// i.e. before any map script runs. Match the ASSIGNMENT, not any mention: the
// first occurrence of the name in this file is a comment about it.
is_true(preg_match('/window\.MAP_LAYER_PREFS\s*=\s*<\?php\s+echo\s+json_encode/', $navSrc) === 1,
    'navbar assigns window.MAP_LAYER_PREFS from a server-rendered JSON literal');
$injectPos = strpos($navSrc, 'window.MAP_LAYER_PREFS     =');
$tilePos   = strpos($navSrc, 'window.TILE_PROXY          =');
is_true($injectPos !== false && $tilePos !== false && abs($injectPos - $tilePos) < 400,
    'the payload is injected alongside TILE_PROXY (synchronously, before maps build)',
    'inject=' . var_export($injectPos, true) . ' tile=' . var_export($tilePos, true));

// Each surface that owns a layer control must hand its layers to the shared
// component. These are the surfaces found when the defect was verified.
$surfaces = [
    'assets/js/app.js'         => 'dashboard map widget',
    'situation.php'            => 'situation screen',
    'assets/js/map-prefs.js'   => 'shared addLayerControl (unit/facility/incident detail + editor)',
    'assets/js/units.js'       => 'units listing map',
    'assets/js/facilities.js'  => 'facilities listing map',
];
foreach ($surfaces as $file => $label) {
    $src = (string) @file_get_contents($base . '/' . $file);
    is_true(strpos($src, 'MapLayerPrefs') !== false,
        "wired: $label ($file)");
}

// The situation screen must register the two layers Eric named by hand — they
// are built asynchronously, so bind() alone would miss them.
$sitSrc = (string) file_get_contents($base . '/situation.php');
// The map argument is `map` on some call sites and `mapVar()` on others, so
// the pattern must tolerate a nested call — [^)]* cannot cross `mapVar()`.
foreach (['units', 'facilities', 'event_zones', 'weather_alerts'] as $id) {
    is_true(preg_match("/MapLayerPrefs\.register\(\s*[^,]+,\s*'" . preg_quote($id, '/') . "'\s*,/", $sitSrc) === 1,
        "situation screen registers '$id'");
}

// The dashboard's OLD add-only restore must be gone. Leaving it in place would
// mean two mechanisms fighting over the same layers on every load.
$appSrc = (string) file_get_contents($base . '/assets/js/app.js');
is_true(strpos($appSrc, 'savedPrefs.overlays') === false,
    'the dashboard no longer restores overlays from the add-only localStorage path');
is_true(strpos($appSrc, "map.on('overlayadd', saveMapLayers)") === false,
    'the dashboard no longer double-writes overlay state to localStorage');
// ...but the BASE layer restore must survive: basemap choice is a separate
// concern from overlay visibility and was not part of this defect.
is_true(strpos($appSrc, 'savedPrefs.base') !== false,
    'the dashboard still restores the saved BASE layer (not regressed)');

// Layer visibility must stay clear of the tile provider/mode work. Sharing a
// settings key between them is how two features start silently overwriting
// each other.
$incSrc = (string) file_get_contents($base . '/inc/map-layer-prefs.php');
is_true(strpos($incSrc, 'tile_mode') === false,
    'layer visibility never touches the tile_mode setting');
// TOKENISED, not grepped. This file's own comments name get_setting() while
// explaining why it is NOT used, and a substring search scores that as a
// violation — the same trap test_tile_proxy.php records. A gate a comment can
// trip is a gate people work around by not writing comments.
$mlpFns = [];
foreach (token_get_all($incSrc) as $tok) {
    if (is_array($tok) && $tok[0] === T_STRING) { $mlpFns[$tok[1]] = true; }
}
is_true(!isset($mlpFns['get_setting']),
    'settings NOT read from the separate `config` store (get_setting)');
is_true(isset($mlpFns['get_variable']),
    'the administrator default is read with get_variable() (the `settings` store)');

// Reuse, not a third mechanism: this must go through Phase 17's helpers.
is_true(strpos($incSrc, "require_once __DIR__ . '/screen-prefs.php'") !== false,
    'reuses inc/screen-prefs.php rather than inventing a new preference store');
foreach (['prefs_get(', 'prefs_set(', 'prefs_reset('] as $fn) {
    is_true(strpos($incSrc, $fn) !== false, "reuses $fn from the existing prefs layer");
}
is_true(strpos($incSrc, 'CREATE TABLE') === false,
    'no new preference table is created');

// The client must never block or interrupt on a failed save.
$jsSrc = (string) file_get_contents($jsPath);
is_true(strpos($jsSrc, '.catch(function () {') !== false,
    'a failed save is swallowed client-side (never a modal in front of a dispatcher)');
is_true(strpos($jsSrc, 'alert(') === false && strpos($jsSrc, 'confirm(') === false,
    'the preference client never raises a browser dialog');
is_true(strpos($jsSrc, 'SAVE_DEBOUNCE_MS') !== false,
    'saves are debounced rather than fired per toggle');

echo "\n";
echo "==========================================================\n";
echo "Map layer prefs tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
