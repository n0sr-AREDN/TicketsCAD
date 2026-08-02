<?php
/**
 * NewUI v4 API — Map Tile Proxy
 *
 * Fetches map tiles SERVER-SIDE so the dispatcher's browser never contacts the
 * tile provider. Without this, every pan and zoom tells a third party which
 * patch of ground a dispatcher is looking at — which, on a CAD console, is
 * where the incident is — continuously, for the whole shift.
 *
 *   GET api/tile-proxy.php?provider=osm&z=12&x=1023&y=1479   → image bytes
 *   GET api/tile-proxy.php?action=status                     → JSON (admin)
 *
 * Not every provider may be proxied. api/tile-proxy.php refuses the ones whose
 * terms forbid caching or re-serving their tiles, and those fall back to direct
 * browser fetch. The per-provider verdicts, their sources, the SSRF boundary
 * and the cache bounds all live in inc/tile-proxy.php.
 *
 * SECURITY NOTES, because this endpoint is exactly the shape of an open proxy:
 *   - It takes a provider IDENTIFIER and three integers. It never takes a URL.
 *     There is no parameter that can influence the upstream scheme, host, port,
 *     path or query — even the {s} subdomain is derived from the coordinates.
 *   - It requires an authenticated session. An unauthenticated tile relay is a
 *     free public CDN pointed at somebody else's service, billed to them and
 *     attributed to this server's IP.
 *   - Out-of-grid z/x/y are rejected, which is a disk guard as much as a
 *     correctness one: unbounded coordinates mint unbounded cache entries.
 */

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/tile-proxy.php';

/** 1x1 transparent PNG — the graceful-degradation tile (see fail_soft()). */
const TILE_BLANK_PNG_B64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/**
 * Upstream is unreachable and we hold no copy.
 *
 * Serve a transparent tile rather than an error status. A 4xx/5xx here paints
 * broken-image icons across a dispatcher's map and, depending on the browser,
 * can leave the layer wedged. A blank tile leaves a gap in the basemap while
 * every incident marker, unit and overlay keeps rendering on top — the map
 * degrades, the console does not break. The reason goes to the error log and
 * the X-Tile-Proxy header, never silently nowhere.
 *
 * NEGATIVE CACHING (2026-07-31). This used to send `Cache-Control: no-store`,
 * which meant the browser forgot the failure instantly and re-requested the
 * same dead tiles on the next pan — so the full upstream timeout was paid
 * again, and again, for the whole outage. A short max-age makes a failure
 * cost once per tile per minute instead of once per pan.
 *
 * The trade is honest and small: when the connection comes back, ground the
 * dispatcher has already looked at stays grey for up to $maxAge seconds
 * before the browser asks again. That is the same order as the breaker
 * cool-off, and it is bounded — unlike the worker exhaustion it prevents.
 */
function tile_fail_soft(string $why, int $maxAge = TILE_FAIL_MAX_AGE): void
{
    error_log('[tile-proxy] ' . $why);
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=' . max(0, $maxAge));
    header('X-Tile-Proxy: error');
    header('X-Tile-Proxy-Reason: ' . preg_replace('/[^\x20-\x7E]/', '', substr($why, 0, 180)));
    echo base64_decode(TILE_BLANK_PNG_B64);
    exit;
}

/** Serve bytes we already hold. */
function tile_serve_bytes(string $body, string $ctype, int $maxAge, string $cacheState): void
{
    header('Content-Type: ' . ($ctype !== '' ? $ctype : 'image/png'));
    // private: these are cached per authenticated dispatcher, and a shared
    // proxy between here and the browser has no business keeping map tiles
    // that describe an incident location.
    header('Cache-Control: private, max-age=' . max(0, $maxAge));
    header('X-Cache: ' . $cacheState);
    header('X-Tile-Proxy: hit');
    echo $body;
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// action=status — admin-only cache + policy report
// ─────────────────────────────────────────────────────────────────────────

if (($_GET['action'] ?? '') === 'status') {
    try {
        if (!function_exists('rbac_can') || !rbac_can('action.manage_config')) {
            json_error('Not permitted', 403);
        }
        $cfg = tile_proxy_settings();
        $dir = tile_cache_dir();
        $usage = tile_cache_usage($dir);
        $free  = tile_cache_free_bytes($dir);

        json_response([
            'ok'             => true,
            'mode'           => $cfg['mode'] !== '' ? $cfg['mode'] : 'proxy',
            'provider'       => $cfg['provider'],
            'effective_mode' => tile_proxy_effective_mode($cfg['mode'], $cfg['provider']),
            'user_agent'     => tile_proxy_user_agent($cfg['user_agent'], (string) ($_SERVER['HTTP_HOST'] ?? '')),
            'ua_is_default'  => (trim((string) $cfg['user_agent']) === ''),
            'cache'          => [
                'files'          => $usage['files'],
                'bytes'          => $usage['bytes'],
                'bytes_human'    => tile_format_size((int) $usage['bytes']),
                'max_bytes'      => $cfg['max_bytes'],
                'max_human'      => tile_format_size((int) $cfg['max_bytes']),
                'pct_used'       => $cfg['max_bytes'] > 0
                                        ? min(100, (int) round($usage['bytes'] / $cfg['max_bytes'] * 100)) : 0,
                'free_bytes'     => $free,
                'free_human'     => $free === null ? 'unknown' : tile_format_size($free),
                'min_free_bytes' => $cfg['min_free_bytes'],
                'min_free_human' => tile_format_size((int) $cfg['min_free_bytes']),
                'reserve_ok'     => tile_cache_space_verdict($free, 0, (int) $cfg['min_free_bytes'])['ok'],
            ],
            'policy'         => tile_proxy_policy_summary(),
            // Which providers we have stopped contacting, and for how long.
            // Empty on a healthy install: only a provider that has actually
            // failed has a breaker file.
            'breakers'       => tile_breaker_status(),
            'breaker_policy' => [
                'threshold'      => TILE_BREAKER_THRESHOLD,
                'cooloff_s'      => TILE_BREAKER_COOLOFF,
                'fail_max_age_s' => TILE_FAIL_MAX_AGE,
                'connect_timeout_s' => TILE_PROXY_CONNECT_TIMEOUT,
                'read_timeout_s'    => TILE_PROXY_READ_TIMEOUT,
            ],
        ]);
    } catch (Throwable $e) {
        json_error_safe('Could not read tile-proxy status', $e, 'tile-proxy-status', 500);
    } finally {
        ini_set('display_errors', $prevDisplay);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// Tile request
// ─────────────────────────────────────────────────────────────────────────

try {
    $provider = (string) ($_GET['provider'] ?? '');

    // ── SSRF boundary #1: the identifier must be a plain token. This is also
    //    the path-traversal guard, since it becomes a cache directory name.
    if (!tile_proxy_valid_provider_id($provider)) {
        json_error('Invalid provider', 400);
    }

    // ── SSRF boundary #2: no URL is accepted, from anywhere. Reject loudly
    //    rather than ignoring it, so a caller that tries learns immediately —
    //    and so a probe for an open proxy shows up in the logs as a 400.
    foreach (['url', 'u', 'tile_url', 'src', 'target', 'host', 'template'] as $forbidden) {
        if (isset($_GET[$forbidden])) {
            json_error('This endpoint does not accept URLs — pass provider + z/x/y only', 400);
        }
    }

    $cfg = tile_proxy_settings();

    // ── Policy gate. Refusing here is the whole point of the design: a
    //    provider whose terms forbid proxying is never fetched by this server,
    //    whatever the client asked for or the admin configured.
    $verdict = tile_proxy_verdict($provider);
    if (!$verdict['allowed']) {
        json_error('Proxying is not permitted for this tile provider: ' . $verdict['reason'], 403);
    }

    // Numeric-only coordinates. is_numeric first so "1e9", "0x10" and "" all
    // fail here rather than being silently cast to something plausible.
    foreach (['z', 'x', 'y'] as $p) {
        if (!isset($_GET[$p]) || !preg_match('/^\d{1,10}$/', (string) $_GET[$p])) {
            json_error('Invalid tile coordinates', 400);
        }
    }
    $z = (int) $_GET['z'];
    $x = (int) $_GET['x'];
    $y = (int) $_GET['y'];

    $policy   = tile_proxy_policy();
    $maxZoom  = (int) $policy[$provider]['max_zoom'];
    if (!tile_proxy_valid_zxy($z, $x, $y, $maxZoom)) {
        json_error('Tile coordinates out of range for this provider', 400);
    }

    // The upstream URL is BUILT here, from our template plus three validated
    // integers. Nothing about it came off the wire.
    $upstream = tile_proxy_upstream_url($provider, $z, $x, $y, (string) $cfg['api_key'], (string) $cfg['server_url']);
    if ($upstream === null) {
        json_error('No usable tile URL for this provider', 400);
    }

    $cachePath = tile_cache_path($provider, $z, $x, $y);
    $metaPath  = $cachePath . '.meta';
    $now       = time();

    // ── Cache lookup ────────────────────────────────────────────────────
    $meta = null;
    if (is_file($cachePath) && is_file($metaPath)) {
        $raw = @file_get_contents($metaPath);
        $meta = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($meta)) { $meta = null; }
    }

    if ($meta !== null && isset($meta['expires']) && (int) $meta['expires'] > $now) {
        // Fresh. Do not re-request a tile we already hold.
        $body = @file_get_contents($cachePath);
        if ($body !== false && $body !== '') {
            // Keep the LRU signal current, but not on every single hit — a
            // write per tile request would be its own I/O problem.
            $mtime = (int) @filemtime($cachePath);
            if ($now - $mtime > TILE_CACHE_TOUCH_INTERVAL) { @touch($cachePath); }
            tile_serve_bytes($body, (string) ($meta['ctype'] ?? 'image/png'),
                             (int) $meta['expires'] - $now, 'HIT');
        }
    }

    // ── Circuit breaker ─────────────────────────────────────────────────
    // Upstream has already failed at the transport level several times in a
    // row. Do not spend another connect timeout finding that out — a viewport
    // is ~40 tiles and a dispatcher pans repeatedly. Serve what we hold, or a
    // blank tile, and say when we will try again.
    $breaker = tile_breaker_check($provider);
    if ($breaker['open']) {
        if (is_file($cachePath)) {
            $body = @file_get_contents($cachePath);
            if ($body !== false && $body !== '') {
                tile_serve_bytes($body, (string) ($meta['ctype'] ?? 'image/png'),
                                 min(300, $breaker['retry_in']), 'STALE');
            }
        }
        tile_fail_soft('upstream ' . $provider . ' not contacted for '
                       . $breaker['retry_in'] . 's — ' . $breaker['reason'],
                       $breaker['retry_in']);
    }

    // ── Fetch (or revalidate) ───────────────────────────────────────────
    $ua = tile_proxy_user_agent((string) $cfg['user_agent'], (string) ($_SERVER['HTTP_HOST'] ?? ''));
    $reqHeaders = ['User-Agent: ' . $ua, 'Accept: image/*'];
    // OSM's policy expects a valid Referer on web-page tile requests, and
    // proxying is precisely what removes the browser's. Synthesised from our
    // own host: satisfies the condition, discloses nothing about which page
    // (or which incident) the dispatcher is looking at.
    $referer = tile_proxy_referer((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($referer !== '') {
        $reqHeaders[] = 'Referer: ' . $referer;
    }
    // Conditional revalidation: a 304 means we keep the bytes we already have
    // and pay nothing but a round trip.
    if ($meta !== null && !empty($meta['etag'])) {
        $reqHeaders[] = 'If-None-Match: ' . $meta['etag'];
    } elseif ($meta !== null && !empty($meta['last_modified'])) {
        $reqHeaders[] = 'If-Modified-Since: ' . $meta['last_modified'];
    }

    $res = tile_http_get($upstream, $reqHeaders);

    // Breaker bookkeeping, before any of the response branches below exit.
    // "Down" means the transport failed, or upstream said 5xx/429 — not that
    // one tile 404'd, which is a healthy provider answering honestly.
    if (tile_upstream_is_down((int) $res['status'], (string) $res['error'])) {
        tile_breaker_record_failure($provider,
            'HTTP ' . $res['status'] . ($res['error'] !== '' ? ' ' . $res['error'] : ''));
    } else {
        tile_breaker_record_success($provider);
    }

    if ($res['status'] === 304 && $meta !== null) {
        $body = @file_get_contents($cachePath);
        if ($body !== false && $body !== '') {
            $ttl = tile_cache_ttl_from_headers($res['headers'], TILE_CACHE_MIN_TTL, (int) $cfg['max_ttl']);
            $meta['expires'] = $now + $ttl;
            @file_put_contents($metaPath, json_encode($meta), LOCK_EX);
            @touch($cachePath);
            tile_serve_bytes($body, (string) ($meta['ctype'] ?? 'image/png'), $ttl, 'REVALIDATED');
        }
    }

    if ($res['status'] !== 200 || $res['body'] === '' || strlen($res['body']) > TILE_PROXY_MAX_BYTES) {
        // Stale-if-error: an old tile beats no tile on a dispatch map.
        if (is_file($cachePath)) {
            $body = @file_get_contents($cachePath);
            if ($body !== false && $body !== '') {
                error_log('[tile-proxy] upstream ' . $provider . ' returned ' . $res['status']
                          . ' — serving stale tile');
                tile_serve_bytes($body, (string) ($meta['ctype'] ?? 'image/png'), 300, 'STALE');
            }
        }
        tile_fail_soft('upstream ' . $provider . ' ' . $z . '/' . $x . '/' . $y
                       . ' failed: HTTP ' . $res['status']
                       . ($res['error'] !== '' ? ' (' . $res['error'] . ')' : ''));
    }

    $body  = $res['body'];
    $ctype = $res['headers']['content-type'] ?? 'image/png';
    if (stripos($ctype, 'image/') !== 0) {
        // An HTML error page dressed as a tile. Do not cache it, do not paint
        // it — and DO count it against the breaker. A captive portal or an
        // ISP interception page answers 200 with HTML for every tile, so this
        // is an outage wearing a success code, not a one-off.
        tile_breaker_record_failure($provider, 'non-image content-type ' . $ctype);
        tile_fail_soft('upstream ' . $provider . ' returned non-image content-type "' . $ctype . '"');
    }
    $ttl = tile_cache_ttl_from_headers($res['headers'], TILE_CACHE_MIN_TTL, (int) $cfg['max_ttl']);

    // ── Cache write, subject to the disk guards ─────────────────────────
    // The tile is served either way; only the on-disk copy is at stake, so a
    // full or unreadable volume costs a re-fetch, never a broken map.
    $cacheDir = dirname($cachePath);
    $free     = tile_cache_free_bytes(tile_cache_dir());
    $space    = tile_cache_space_verdict($free, strlen($body), (int) $cfg['min_free_bytes']);

    if ($space['ok']) {
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
        if (is_dir($cacheDir)) {
            @file_put_contents($cachePath, $body, LOCK_EX);
            @file_put_contents($metaPath, json_encode([
                'expires'       => $now + $ttl,
                'ctype'         => $ctype,
                'etag'          => $res['headers']['etag'] ?? '',
                'last_modified' => $res['headers']['last-modified'] ?? '',
                'provider'      => $provider,
            ]), LOCK_EX);

            // Enforce the ceiling occasionally rather than on every write —
            // the sweep walks the whole cache, so doing it per tile would turn
            // a busy map into a disk-thrash. 1-in-64 writes keeps a 512 MB cap
            // from overshooting by more than a rounding error at tile sizes.
            if (random_int(1, 64) === 1) {
                $ev = tile_cache_enforce_cap(tile_cache_dir(), (int) $cfg['max_bytes']);
                if ($ev['evicted'] > 0) {
                    error_log('[tile-proxy] cache cap reached — evicted ' . $ev['evicted']
                              . ' tiles (' . tile_format_size((int) $ev['freed']) . ')');
                }
            }
        }
    } else {
        error_log('[tile-proxy] ' . $space['reason']);
        // Try to make room for next time, but never at the cost of this reply.
        if (!$space['undetermined']) {
            tile_cache_enforce_cap(tile_cache_dir(), (int) $cfg['max_bytes']);
        }
    }

    tile_serve_bytes($body, $ctype, $ttl, 'MISS');
} catch (Throwable $e) {
    error_log('[tile-proxy] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    tile_fail_soft('internal error');
} finally {
    ini_set('display_errors', $prevDisplay);
}
