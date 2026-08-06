<?php
/**
 * Location-provider health snapshot — extracted from api/health.php (issue
 * #26 on the public repo) so it can be required and driven directly by a
 * CLI test, the same reason owntracks-config.php's helper functions moved
 * to inc/unit_owntracks.php (Phase 117): api/health.php requires auth.php
 * and dispatches on $_SERVER['REQUEST_METHOD'] at include time, so nothing
 * in it is reachable from a test without also faking an HTTP admin session.
 *
 * Phase 26C (2026-06-11) — per-provider health snapshot.
 *
 * Returns rollup status across all configured location providers and
 * a per-provider `details.providers[]` list with: code, name, enabled,
 * last_receive_at, receive_count_24h, last_error.
 *
 * Status rollup:
 *   - "ok"      if all enabled providers received a packet within their
 *               own configured max_age_seconds
 *   - "warn"    if at least one enabled provider is dark (stale)
 *   - "error"   if all enabled providers are dark OR no providers
 *               configured but feature claims enabled
 *   - "unknown" if no providers configured (feature not in use)
 */
function checkLocationProviders(string $prefix): array {
    $details = ['providers' => []];
    $status = 'unknown';
    $message = 'No location providers configured';

    try {
        // max_age_seconds is selected here (issue #26, part 2) — it was
        // previously left out of this query entirely, so a fix that only
        // changed the constant-vs-column line below would have silently
        // read a value that was never fetched and fallen back to the same
        // flat default it was meant to replace.
        $rows = db_fetch_all(
            "SELECT id, code, name, enabled, color, icon, priority, max_age_seconds
               FROM `{$prefix}location_providers`
              ORDER BY priority ASC, code ASC"
        );
    } catch (Exception $e) {
        return ['status' => 'unknown', 'message' => 'location_providers table missing', 'details' => $details];
    }

    if (!$rows) {
        return ['status' => $status, 'message' => $message, 'details' => $details];
    }

    $now = time();
    $enabledCount = 0;
    $darkEnabledCount = 0;

    foreach ($rows as $r) {
        $pid = (int) $r['id'];
        $enabled = (int) ($r['enabled'] ?? 0) === 1;
        // Issue #26 part 2: each provider carries its own tuned staleness
        // threshold (api/location.php's map freshness query already honours
        // it) — a flat 30-minute constant here disagreed with the map for
        // every provider whose seeded value isn't 1800s exactly.
        $staleSec = (int) ($r['max_age_seconds'] ?? 0);
        if ($staleSec <= 0) { $staleSec = 1800; }
        $lastTs = null;
        $count24h = 0;
        try {
            $v = db_fetch_one(
                "SELECT MAX(received_at) AS last_ts,
                        SUM(CASE WHEN received_at > NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) AS c24
                   FROM `{$prefix}location_reports`
                  WHERE provider_id = ?",
                [$pid]
            );
            if ($v) {
                $lastTs = $v['last_ts'] ?: null;
                $count24h = (int) ($v['c24'] ?? 0);
            }
        } catch (Exception $e) {}

        $age = $lastTs ? max(0, $now - strtotime($lastTs)) : null;
        $provStatus = 'unknown';
        // Phase 41: providers that are "browser-driven only" (Internal GPS,
        // Manual entry) have no server-side ingest at all — the absence of
        // location_reports rows just means no mobile-web user has shared
        // their location yet. Treat that as "passive" rather than "error".
        //
        // Issue #26 part 1: the seeded code for Internal GPS is 'internal',
        // not 'internal_gps' — the list below previously named a code that
        // does not exist in location_providers, so this branch never fired
        // and a fresh install with only Internal GPS enabled read Error
        // from the moment it was switched on.
        $browserDriven = in_array((string) $r['code'], ['internal', 'manual', 'browser_gps'], true);
        if (!$enabled) {
            $provStatus = 'disabled';
        } elseif ($lastTs === null) {
            $provStatus = $browserDriven ? 'passive' : 'no_data';
        } elseif ($age > $staleSec) {
            $provStatus = 'stale';
        } else {
            $provStatus = 'ok';
        }

        if ($enabled) {
            $enabledCount++;
            // 'passive' is healthy for browser-driven providers — don't
            // count it as "dark" for the aggregate status calculation.
            if (!in_array($provStatus, ['ok', 'passive'], true)) $darkEnabledCount++;
        }

        $details['providers'][] = [
            'id'                => $pid,
            'code'              => $r['code'],
            'name'              => $r['name'],
            'enabled'           => $enabled,
            'icon'              => $r['icon'] ?? '',
            'color'             => $r['color'] ?? '',
            'priority'          => (int) $r['priority'],
            'max_age_seconds'   => $staleSec,
            'last_receive_at'   => $lastTs,
            'age_seconds'       => $age,
            'receive_count_24h' => $count24h,
            'status'            => $provStatus,
        ];
    }

    if ($enabledCount === 0) {
        $status = 'unknown';
        $message = 'No providers enabled';
    } elseif ($darkEnabledCount === 0) {
        $status = 'ok';
        $message = "{$enabledCount} provider(s) receiving";
    } elseif ($darkEnabledCount < $enabledCount) {
        $status = 'warn';
        $message = "{$darkEnabledCount}/{$enabledCount} provider(s) stale";
    } else {
        $status = 'error';
        $message = "All {$enabledCount} enabled provider(s) dark";
    }

    return ['status' => $status, 'message' => $message, 'details' => $details];
}
