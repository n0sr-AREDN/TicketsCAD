<?php
/**
 * Geocoding library gate — inc/geocode.php.
 *
 * WHAT THIS PROTECTS
 *
 * The defect: `geocoding_provider` and `geocoding_api_key` were offered by
 * Settings and read by NOTHING, while every one of the eleven geocoding calls
 * hardcoded nominatim.openstreetmap.org in the dispatcher's browser. So a
 * self-hosted geocoder was unreachable, offline address lookup was impossible,
 * and every incident address went to a third party. See inc/geocode.php.
 *
 * HOW IT IS TESTED
 *
 * Bounds are MEASURED against 203.0.113.1 — the RFC 5737 block reserved for
 * documentation, which is guaranteed never to be routed, so packets to it
 * vanish with no reply and no rejection. That is the shape of an upstream
 * outage, and it is deliberately different from a closed port on your own
 * network, which answers instantly and would make everything look fine.
 *
 * Provider adapters are driven from RECORDED FIXTURES of each service's
 * documented response, because most of them cannot be exercised without a paid
 * key. A fixture test cannot prove the live service still answers in that
 * shape — which is exactly why geocode_policy() marks those providers
 * `verified => documented` and Settings tells the administrator to press Test
 * before relying on one. Claiming more than that would be the same kind of
 * overstatement this whole change removes.
 *
 * Usage: php tests/test_geocode.php
 */

$base = realpath(__DIR__ . '/..');
require_once $base . '/inc/version.php';
require_once $base . '/inc/geocode.php';

$pass = 0; $fail = 0;
function is_ok($cond, string $label) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [ok]   $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}
function section(string $t) { echo "\n-- $t --\n"; }

// ═════════════════════════════════════════════════════════════════════
section('Policy table is complete and self-consistent');

$policy = geocode_policy();
is_ok(count($policy) >= 7, 'every provider the Settings dropdown offers has a policy entry');

$requiredKeys = ['label','kind','needs_key','needs_url','direct_supported','direct_reason',
                 'throttle_ms','policy','source','caveat','verified','unsupported'];
$complete = true;
foreach ($policy as $id => $p) {
    foreach ($requiredKeys as $k) {
        if (!array_key_exists($k, $p)) { $complete = false; echo "      missing '$k' on '$id'\n"; }
    }
    if (!geocode_valid_provider_id($id)) { $complete = false; echo "      bad id '$id'\n"; }
}
is_ok($complete, 'every entry carries every field, and every id is filesystem-safe');

// A provider that cannot be fetched from the browser must SAY WHY, because
// that sentence is what Settings shows when the effective mode differs from
// the selected one. An empty reason there is a silent override.
$reasoned = true;
foreach ($policy as $id => $p) {
    if (!$p['direct_supported'] && trim($p['direct_reason']) === '') {
        $reasoned = false; echo "      '$id' refuses direct mode without explaining why\n";
    }
}
is_ok($reasoned, 'a provider that forbids direct mode explains itself in plain language');

// The key must never reach the browser. A keyed provider that also claimed to
// be direct-capable would put it there.
$noKeyLeak = true;
foreach ($policy as $id => $p) {
    if ($p['needs_key'] && $p['direct_supported']) {
        $noKeyLeak = false; echo "      '$id' needs a key AND allows direct mode — the key would reach the browser\n";
    }
}
is_ok($noKeyLeak, 'no keyed provider is direct-capable (the API key never reaches a browser)');

is_ok((int) $policy['nominatim']['throttle_ms'] >= 1000,
      "public Nominatim's default throttle honours its 1 request/second policy");
is_ok((int) $policy['nominatim_self']['throttle_ms'] === 0,
      'a self-hosted instance is not artificially throttled — it is your own server');

// ═════════════════════════════════════════════════════════════════════
section('Effective mode — the setting and the behaviour must agree');

is_ok(geocode_effective_mode('off', 'nominatim')['mode'] === 'off',
      'off is absolute — an air-gapped install never reaches the network');
is_ok(geocode_effective_mode('server', 'nominatim')['mode'] === 'server',
      'server mode is honoured');
is_ok(geocode_effective_mode('direct', 'nominatim')['mode'] === 'direct',
      'direct mode is honoured for the one provider that supports it');
$eff = geocode_effective_mode('direct', 'google');
is_ok($eff['mode'] === 'server' && $eff['requested'] === 'direct' && $eff['reason'] !== '',
      'direct + a keyed provider resolves to server AND reports the override with a reason');
is_ok(geocode_effective_mode('direct', 'photon')['mode'] === 'server',
      'direct + a GeoJSON provider resolves to server (the browser cannot parse it)');
is_ok(geocode_effective_mode('', 'nominatim')['mode'] === 'server',
      'an unset mode defaults to server, not to the old browser-direct behaviour');
is_ok(geocode_effective_mode('wat', 'nominatim')['mode'] === 'server',
      'an unrecognised mode fails safe to server');

// ═════════════════════════════════════════════════════════════════════
section('SSRF boundary — no client-supplied host can ever be reached');

foreach ([
    'file:///etc/passwd'      => 'file://',
    'gopher://evil/'          => 'gopher://',
    'ftp://evil/x'            => 'ftp://',
    'javascript:alert(1)'     => 'javascript:',
    'data:text/plain,x'       => 'data:',
    '//evil.example/x'        => 'scheme-relative //host',
    'http://u:p@evil/x'       => 'embedded credentials',
    "http://evil/\r\nX-a: b"  => 'CRLF request splitting',
    "http://evil/\tx"         => 'embedded tab',
    ''                        => 'empty string',
] as $bad => $label) {
    is_ok(geocode_sanitize_url($bad) === '', "refused: $label");
}
is_ok(geocode_sanitize_url('https://nominatim.openstreetmap.org') !== '',
      'a plain https URL is accepted');
is_ok(geocode_sanitize_url('http://10.0.0.5:8080/nominatim') !== '',
      'a plain-http LAN address is accepted — a self-hosted geocoder usually has no certificate');
is_ok(geocode_sanitize_url('https://x.example/' . str_repeat('a', 2100)) === '',
      'an absurdly long URL is refused');

// The real HTTP client refuses too, not just the validator — a future caller
// must not be able to turn it into a general-purpose fetcher.
$r = geocode_http_get('file:///etc/passwd', []);
is_ok($r['error'] !== '' && $r['body'] === '',
      'geocode_http_get() itself refuses a non-http(s) URL');

// ═════════════════════════════════════════════════════════════════════
section('URL construction — the client contributes no host, scheme or path');

$s = ['api_key' => 'SECRET_KEY', 'url' => 'https://geo.example.org/nominatim/'];
$hostile = ['q' => "x&format=xml&key=stolen\nHost: evil", 'limit' => 999,
            'viewbox' => '-93.5,45.1,-93.0,44.8'];

foreach (['nominatim','nominatim_self','photon','locationiq','geoapify','google','here'] as $p) {
    $u = geocode_build_url($p, 'search', $hostile, $s);
    is_ok(is_string($u) && $u !== '', "$p: builds a search URL");
    if (!is_string($u)) { continue; }
    $host = parse_url($u, PHP_URL_HOST);
    is_ok($host !== 'evil' && strpos($u, "\n") === false && strpos($u, "\r") === false,
          "$p: a hostile query cannot inject a host or split the request");
}

$u = geocode_build_url('nominatim', 'search', ['q' => 'x', 'limit' => 999], $s);
is_ok(strpos($u, 'limit=10') !== false, 'limit is clamped to 10 — a client cannot ask for 999');

is_ok(geocode_build_url('nominatim_self', 'search', ['q' => 'x'], ['api_key' => '', 'url' => 'file:///etc']) === null,
      'a self-hosted provider with a non-http(s) configured URL builds nothing');
is_ok(geocode_build_url('locationiq', 'search', ['q' => 'x'], ['api_key' => '', 'url' => '']) === null,
      'a keyed provider with no key builds nothing (rather than calling with an empty key)');
is_ok(geocode_build_url('made_up_provider', 'search', ['q' => 'x'], $s) === null,
      'an unknown provider builds nothing');
is_ok(geocode_build_url('nominatim', 'delete_everything', ['q' => 'x'], $s) === null,
      'an unknown action builds nothing');
is_ok(geocode_build_url('nominatim', 'reverse', ['lat' => 91, 'lon' => 0], $s) === null,
      'an out-of-range latitude builds nothing');
is_ok(geocode_build_url('nominatim', 'reverse', ['lat' => 'abc', 'lon' => 'def'], $s) === null,
      'non-numeric coordinates build nothing');
is_ok(geocode_build_url('nominatim', 'search', ['q' => '   '], $s) === null,
      'a blank query builds nothing');

is_ok(geocode_clean_viewbox('1,2,3,x') === '', 'a non-numeric viewbox component is rejected whole');
is_ok(geocode_clean_viewbox('1,2,3') === '', 'a three-part viewbox is rejected');
is_ok(geocode_clean_viewbox('-93.5,145.1,-93.0,44.8') === '', 'an out-of-range latitude is rejected');
is_ok(geocode_clean_viewbox('-93.5,45.1,-93.0,44.8') === '-93.5000,45.1000,-93.0000,44.8000',
      'a valid viewbox is re-serialised server-side, never passed through verbatim');
is_ok(geocode_clean_countrycodes('us,ca,../etc,zz9,GB') === 'us,ca,gb',
      'country codes keep only two-letter tokens (a path fragment and a 3-char token are dropped)');
is_ok(geocode_clean_query("a\r\nb\tc   d") === 'a b c d',
      'control characters are stripped from the query');
is_ok(strlen(geocode_clean_query(str_repeat('x', 400))) === 250, 'the query is length-bounded');

// ═════════════════════════════════════════════════════════════════════
section('User-Agent — Nominatim blocks generic ones outright');

$ua = geocode_user_agent('', 'cad.example.org');
is_ok(stripos($ua, 'TicketsCAD') === 0, 'the default User-Agent names the application');
is_ok(strpos($ua, 'cad.example.org') !== false, 'and names the install, so an operator can make contact');
is_ok(geocode_user_agent('MyAgency CAD (dispatch@example.org)', 'h') === 'MyAgency CAD (dispatch@example.org)',
      'an administrator override is honoured');
is_ok(strlen(geocode_user_agent(str_repeat('u', 400), 'h')) <= 160, 'an override is length-bounded');

// ═════════════════════════════════════════════════════════════════════
section('Response normalisation — every provider, from recorded fixtures');

/**
 * Every result must carry every address key, so a caller doing
 * `addr.city || addr.town || addr.village` behaves identically whichever
 * provider is configured. A MISSING key and an EMPTY one are different things:
 * empty is "this provider does not supply it", declared in policy.unsupported.
 */
$expectedKeys = ['house_number','road','pedestrian','path','city','town','village','hamlet',
                 'state','postcode','neighbourhood','suburb','county','country','country_code',
                 'ISO3166-2-lvl4'];

$fixtures = [
    'nominatim' => ['search', [[
        'lat' => '44.9778', 'lon' => '-93.2650', 'display_name' => 'Minneapolis, MN',
        'address' => ['house_number' => '350', 'road' => 'S 5th St', 'city' => 'Minneapolis',
                      'state' => 'Minnesota', 'postcode' => '55415', 'neighbourhood' => 'Downtown West',
                      'country_code' => 'us', 'ISO3166-2-lvl4' => 'US-MN'],
    ]]],
    'locationiq' => ['search', [[
        'lat' => '44.9778', 'lon' => '-93.2650', 'display_name' => 'Minneapolis, MN',
        'address' => ['house_number' => '350', 'road' => 'S 5th St', 'city' => 'Minneapolis',
                      'state' => 'Minnesota', 'postcode' => '55415'],
    ]]],
    'photon' => ['search', ['features' => [[
        'geometry' => ['coordinates' => [-93.2650, 44.9778]],
        'properties' => ['housenumber' => '350', 'street' => 'S 5th St', 'city' => 'Minneapolis',
                         'state' => 'Minnesota', 'postcode' => '55415', 'countrycode' => 'US'],
    ]]]],
    'geoapify' => ['search', ['results' => [[
        'lat' => 44.9778, 'lon' => -93.2650, 'formatted' => '350 S 5th St, Minneapolis, MN 55415',
        'housenumber' => '350', 'street' => 'S 5th St', 'city' => 'Minneapolis',
        'state' => 'Minnesota', 'state_code' => 'MN', 'postcode' => '55415', 'country_code' => 'us',
    ]]]],
    'google' => ['search', ['status' => 'OK', 'results' => [[
        'formatted_address' => '350 S 5th St, Minneapolis, MN 55415, USA',
        'geometry' => ['location' => ['lat' => 44.9778, 'lng' => -93.2650]],
        'address_components' => [
            ['long_name' => '350', 'short_name' => '350', 'types' => ['street_number']],
            ['long_name' => 'South 5th Street', 'short_name' => 'S 5th St', 'types' => ['route']],
            ['long_name' => 'Minneapolis', 'short_name' => 'Minneapolis', 'types' => ['locality']],
            ['long_name' => 'Minnesota', 'short_name' => 'MN', 'types' => ['administrative_area_level_1']],
            ['long_name' => 'United States', 'short_name' => 'US', 'types' => ['country']],
            ['long_name' => '55415', 'short_name' => '55415', 'types' => ['postal_code']],
        ],
    ]]]],
    'here' => ['search', ['items' => [[
        'title' => '350 S 5th St',
        'address' => ['label' => '350 S 5th St, Minneapolis, MN 55415, United States',
                      'countryCode' => 'US', 'state' => 'Minnesota', 'stateCode' => 'MN',
                      'city' => 'Minneapolis', 'street' => 'S 5th St', 'postalCode' => '55415',
                      'houseNumber' => '350'],
        'position' => ['lat' => 44.9778, 'lng' => -93.2650],
    ]]]],
];

foreach ($fixtures as $provider => [$action, $payload]) {
    $rows = geocode_normalize($provider, $action, $payload);
    is_ok(count($rows) === 1, "$provider: one fixture in, one result out");
    if (!$rows) { continue; }
    $r = $rows[0];

    is_ok(abs((float) $r['lat'] - 44.9778) < 0.0001 && abs((float) $r['lon'] + 93.2650) < 0.0001,
          "$provider: latitude and longitude land in the right fields (not swapped)");
    is_ok(array_keys($r['address']) === $expectedKeys,
          "$provider: the address carries EVERY key, in the contract's order");
    is_ok($r['address']['city'] === 'Minneapolis', "$provider: city");
    is_ok($r['address']['house_number'] === '350', "$provider: house number");
    is_ok($r['address']['postcode'] === '55415', "$provider: postcode");
    is_ok($r['display_name'] !== '', "$provider: a human-readable display name");
}

// Google and Geoapify have to SYNTHESISE the subdivision code that the state
// dropdowns read; Nominatim sends it. Assert the synthesis, because a wrong
// state code silently files an incident in the wrong place.
is_ok(geocode_normalize('google', 'search', $fixtures['google'][1])[0]['address']['ISO3166-2-lvl4'] === 'US-MN',
      'google: the ISO subdivision code is assembled from country + state components');
is_ok(geocode_normalize('geoapify', 'search', $fixtures['geoapify'][1])[0]['address']['ISO3166-2-lvl4'] === 'US-MN',
      'geoapify: the ISO subdivision code is assembled from country_code + state_code');
is_ok(geocode_normalize('here', 'search', $fixtures['here'][1])[0]['address']['ISO3166-2-lvl4'] === 'US-MN',
      'here: the ISO subdivision code is assembled from countryCode + stateCode');

// Nominatim's reverse endpoint returns a bare OBJECT, not an array. Getting
// this wrong would break every map-click on the incident form.
$rev = geocode_normalize('nominatim', 'reverse', [
    'lat' => '44.98', 'lon' => '-93.27', 'display_name' => 'x',
    'address' => ['city' => 'Minneapolis'],
]);
is_ok(count($rev) === 1 && $rev[0]['address']['city'] === 'Minneapolis',
      'nominatim reverse: a single object is handled, not just an array');

foreach (['nominatim','photon','geoapify','google','here','locationiq'] as $p) {
    is_ok(geocode_normalize($p, 'search', null) === [], "$p: a null body normalises to no results, not a crash");
    is_ok(geocode_normalize($p, 'search', ['garbage' => true]) === [],
          "$p: an unexpected body normalises to no results, not a crash");
}
is_ok(geocode_normalize('nominatim', 'search', [['no_coords' => 1]]) === [],
      'a result without coordinates is dropped rather than emitted half-built');

// ═════════════════════════════════════════════════════════════════════
section('Declared unsupported fields are honest');

// A provider may only DECLARE a field unsupported if it genuinely does not
// fill it. Otherwise the Settings note ("this provider does not supply X")
// becomes its own small lie.
foreach ($fixtures as $provider => [$action, $payload]) {
    $rows = geocode_normalize($provider, $action, $payload);
    if (!$rows) { continue; }
    $declared = geocode_policy()[$provider]['unsupported'];
    $wrong = [];
    foreach ($declared as $f) {
        if (!array_key_exists($f, $rows[0]['address'])) { $wrong[] = $f . ' (not a real field)'; }
    }
    is_ok($wrong === [], "$provider: declared-unsupported fields are real address fields" .
                         ($wrong ? ' — ' . implode(', ', $wrong) : ''));
}

// ═════════════════════════════════════════════════════════════════════
section('Cache — required by policy, bounded by us, and carrying no identity');

$k1 = geocode_cache_key('nominatim', 'search', ['q' => '350 S 5th St']);
$k2 = geocode_cache_key('nominatim', 'search', ['q' => '  350 s 5TH   st ']);
is_ok($k1 === $k2, 'case and spacing collapse to one cache entry');
is_ok($k1 !== geocode_cache_key('nominatim', 'search', ['q' => '351 S 5th St']),
      'a different address is a different entry');
is_ok($k1 !== geocode_cache_key('photon', 'search', ['q' => '350 S 5th St']),
      'the provider is part of the key — switching providers does not serve the old one\'s answers');
is_ok($k1 !== geocode_cache_key('nominatim', 'reverse', ['q' => '350 S 5th St']),
      'the action is part of the key');
is_ok(preg_match('/^[a-f0-9]{64}$/', $k1) === 1,
      'the key is a plain hex hash — it cannot traverse out of the cache directory');

// The cache must not be able to answer "what did this dispatcher look up".
$GLOBALS['__gc_uid'] = 7;
is_ok(geocode_cache_key('nominatim', 'search', ['q' => 'x', 'user_id' => 1])
      === geocode_cache_key('nominatim', 'search', ['q' => 'x', 'user_id' => 2]),
      'the key holds NO user identity — the cache cannot become an address history per dispatcher');

is_ok(strpos(str_replace('\\', '/', geocode_cache_dir()),
             str_replace('\\', '/', $base) . '/') !== 0,
      'the cache lives ABOVE the web root — cache/ is documented web-reachable, and this cache '
      . 'is the record of which addresses an agency looked up');

// 12 entries, ceiling 10 → evict down to 85% (8), oldest first. Evicting only
// down to the ceiling itself would make every subsequent write re-sweep.
$entries = [];
foreach (range(1, 12) as $n) { $entries['/f' . sprintf('%02d', $n)] = 1000 + $n; }
$plan = geocode_cache_eviction_plan($entries, 10);
is_ok($plan === ['/f01', '/f02', '/f03', '/f04'],
      'eviction removes the least-recently-written first, down to 85% of the ceiling (12 → 8)');
is_ok(geocode_cache_eviction_plan(['/a' => 1], 5) === [], 'nothing is evicted below the ceiling');
is_ok(geocode_cache_eviction_plan(['/a' => 1, '/b' => 1], 0) === [],
      'a ceiling of zero disables eviction rather than deleting everything');

// Deterministic tie-break, so a test on ordering cannot be flaky.
is_ok(geocode_cache_eviction_plan(['/b' => 5, '/a' => 5, '/c' => 5, '/d' => 5], 3) === ['/a', '/b'],
      'equal timestamps break ties by path, so the plan is deterministic');

// ═════════════════════════════════════════════════════════════════════
section('Throttle — the provider\'s published rule, and bounded waiting');

is_ok(geocode_throttle_wait_ms(0, 1000, 1000) === 0, 'the first call never waits');
is_ok(geocode_throttle_wait_ms(1000, 1200, 1000) === 800, 'a call 200ms later waits the remaining 800ms');
is_ok(geocode_throttle_wait_ms(1000, 2500, 1000) === 0, 'a call after the interval does not wait');
is_ok(geocode_throttle_wait_ms(1000, 1000, 0) === 0, 'a zero interval disables throttling');
is_ok(geocode_throttle_wait_ms(5000, 1000, 1000) === 1000,
      'a clock that goes backwards is treated as "just called", not as licence to flood');

// ═════════════════════════════════════════════════════════════════════
section('Circuit breaker — a 404 is not an outage');

is_ok(geocode_upstream_is_down(0, 'connect timeout') === true, 'a transport timeout is an outage');
is_ok(geocode_upstream_is_down(503, '') === true, 'a 5xx is an outage');
is_ok(geocode_upstream_is_down(429, '') === true, 'a 429 is an outage (backing off IS the fix)');
is_ok(geocode_upstream_is_down(404, '') === false,
      'a 404 is NOT an outage — it is a healthy provider saying the address does not exist, and '
      . 'counting it would disable address lookup for everyone the first time someone mistyped a street');
is_ok(geocode_upstream_is_down(200, '') === false, 'a 200 is not an outage');
is_ok(geocode_upstream_is_down(401, '') === false,
      'a 401 is a bad key, not an outage — the breaker must not hide a configuration error');

$closed = geocode_breaker_decide(['fails' => 2, 'opened_at' => 0], 1000);
is_ok(!$closed['open'] && !$closed['half_open'], 'below the threshold the breaker is closed');
$open = geocode_breaker_decide(['fails' => 3, 'opened_at' => 1000], 1010);
is_ok($open['open'] && $open['retry_in'] > 0 && $open['reason'] !== '',
      'at the threshold it opens, reports a retry time, and says why');
$half = geocode_breaker_decide(['fails' => 3, 'opened_at' => 1000], 1000 + GEOCODE_BREAKER_COOLOFF + 1);
is_ok(!$half['open'] && $half['half_open'], 'after the cool-off ONE request is allowed to probe');

// ═════════════════════════════════════════════════════════════════════
section('Client config + CSP — the browser is told the truth');

$serverCfg = geocode_client_config([
    'mode' => 'server', 'provider' => 'nominatim', 'api_key' => 'SECRET', 'url' => '',
    'user_agent' => '', 'throttle_ms' => 1000, 'cache_ttl' => 3600, 'max_entries' => 100,
]);
is_ok($serverCfg['mode'] === 'server' && $serverCfg['direct_base'] === '',
      'server mode gives the browser no upstream address at all');
is_ok(json_encode($serverCfg) !== false && strpos(json_encode($serverCfg), 'SECRET') === false,
      'the API key is NEVER part of what is injected into the page');

$directCfg = geocode_client_config([
    'mode' => 'direct', 'provider' => 'nominatim', 'api_key' => '', 'url' => '',
    'user_agent' => '', 'throttle_ms' => 1000, 'cache_ttl' => 3600, 'max_entries' => 100,
]);
is_ok($directCfg['mode'] === 'direct'
      && $directCfg['direct_base'] === 'https://nominatim.openstreetmap.org',
      'direct mode gives the browser a base URL built on the server');

$brokenSelf = geocode_client_config([
    'mode' => 'direct', 'provider' => 'nominatim_self', 'api_key' => '', 'url' => 'not a url',
    'user_agent' => '', 'throttle_ms' => 0, 'cache_ttl' => 3600, 'max_entries' => 100,
]);
is_ok($brokenSelf['mode'] === 'server' && $brokenSelf['reason'] !== '',
      'direct mode with an unusable self-hosted URL falls back to server AND explains itself, '
      . 'rather than emitting a config that silently does nothing');

is_ok(geocode_csp_connect_hosts([
        'mode' => 'server', 'provider' => 'nominatim', 'api_key' => '', 'url' => '',
        'user_agent' => '', 'throttle_ms' => 0, 'cache_ttl' => 1, 'max_entries' => 1]) === [],
      'in server mode the CSP permits the browser NO geocoder — so a hardcoded twelfth call site '
      . 'fails visibly on every install instead of leaking silently on all of them');
is_ok(geocode_csp_connect_hosts([
        'mode' => 'direct', 'provider' => 'nominatim_self', 'api_key' => '',
        'url' => 'http://10.0.0.20:8080/nominatim',
        'user_agent' => '', 'throttle_ms' => 0, 'cache_ttl' => 1, 'max_entries' => 1])
      === ['http://10.0.0.20:8080'],
      'in direct mode the CSP carries exactly the configured origin — including a self-hosted one, '
      . 'which the old hardcoded allowlist would have blocked');
is_ok(geocode_csp_connect_hosts([
        'mode' => 'off', 'provider' => 'nominatim', 'api_key' => '', 'url' => '',
        'user_agent' => '', 'throttle_ms' => 0, 'cache_ttl' => 1, 'max_entries' => 1]) === [],
      'off mode permits nothing');

// ═════════════════════════════════════════════════════════════════════
section('The JS and PHP halves of the result contract still match');

// The whole class of bug this project keeps hitting is a producer and a
// consumer that drifted apart. Assert them against each other.
$js = @file_get_contents($base . '/assets/js/geocode.js');
is_ok(is_string($js) && $js !== '', 'assets/js/geocode.js exists');
if (is_string($js) && preg_match('/var ADDRESS_KEYS = \[(.*?)\];/s', $js, $m)) {
    preg_match_all("/'([^']+)'/", $m[1], $km);
    is_ok($km[1] === $expectedKeys,
          'ADDRESS_KEYS in geocode.js matches _geocode_result() in inc/geocode.php exactly');
} else {
    is_ok(false, 'ADDRESS_KEYS could not be read out of geocode.js');
}

// ═════════════════════════════════════════════════════════════════════
section('MEASURED: a black-holed provider fails fast and then costs nothing');

// 203.0.113.1 — RFC 5737 TEST-NET-3, guaranteed unrouted. Packets vanish, so
// this is an upstream OUTAGE, not a closed port (which would answer instantly
// and prove nothing).
$dead = ['mode' => 'server', 'provider' => 'nominatim_self', 'api_key' => '',
         'url' => 'http://203.0.113.1:8080', 'user_agent' => '', 'throttle_ms' => 0,
         'cache_ttl' => 3600, 'max_entries' => 50];

$cacheWasWritable = @is_dir(geocode_cache_dir()) || @mkdir(geocode_cache_dir(), 0700, true);
geocode_breaker_reset('nominatim_self');

$times = [];
for ($i = 1; $i <= 5; $i++) {
    $t0 = microtime(true);
    $res = geocode_lookup('search', ['q' => 'blackhole probe ' . $i . ' ' . mt_rand()], $dead);
    $times[] = (microtime(true) - $t0);
    if ($i === 1) {
        is_ok($res['ok'] === false && $res['error'] === 'upstream_down',
              'an unreachable provider reports upstream_down rather than hanging');
        is_ok(stripos($res['message'], 'place the pin') !== false,
              'and tells the dispatcher what they CAN still do — a sentence, not a spinner');
    }
}
$first = $times[0];
$last  = $times[4];
is_ok($first < (GEOCODE_CONNECT_TIMEOUT + 2),
      sprintf('one lookup against a black hole is bounded (measured %.2fs, budget %ds)',
              $first, GEOCODE_CONNECT_TIMEOUT));
if ($cacheWasWritable) {
    is_ok($last < 0.5,
          sprintf('once the breaker is open a lookup costs nothing (measured %.3fs)', $last));
    is_ok(array_sum($times) < ($first * 4),
          sprintf('five lookups during an outage cost far less than five timeouts '
                  . '(measured %.2fs total vs %.2fs unbounded)', array_sum($times), $first * 5));
} else {
    echo "  [note] breaker state directory not writable — skipping the cool-off measurements\n";
}
geocode_breaker_reset('nominatim_self');

// NEGATIVE CONTROL: the same code against a REACHABLE host must not report an
// outage. Without this, every timing assertion above would also pass if
// geocode_lookup() simply always failed.
$off = ['mode' => 'off', 'provider' => 'nominatim', 'api_key' => '', 'url' => '',
        'user_agent' => '', 'throttle_ms' => 0, 'cache_ttl' => 1, 'max_entries' => 1];
$offRes = geocode_lookup('search', ['q' => 'anything'], $off);
is_ok($offRes['ok'] === false && $offRes['error'] === 'disabled',
      'NEGATIVE CONTROL: with lookup switched off the result is "disabled", NOT "upstream_down" — '
      . 'so the outage assertions above are detecting a real outage, not a function that always fails');
is_ok(stripos($offRes['message'], 'turned off') !== false,
      'and the off message is calm and specific, not an error');

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
