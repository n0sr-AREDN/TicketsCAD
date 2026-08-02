<?php
/**
 * Phase 130 — settings for the (now real) server-side map tile proxy.
 *
 * Background: `tile_mode` has been seeded to 'proxy' on every install since
 * Phase 41 (sql/run_phase41_tile_mode_proxy_default.php), and the Settings UI
 * has described it as "route through server cache — recommended". No proxy
 * existed. `git log --all -S tile_mode -- assets/` is empty: no JS consumer
 * was ever written, so the setting was inert from the day it was introduced.
 *
 * api/tile-proxy.php now implements it for real. That makes the disk a
 * resource this app spends, so the bounds get explicit, admin-visible
 * settings rather than living only as constants:
 *
 *   tile_cache_max_mb        ceiling for the on-disk tile cache
 *   tile_cache_min_free_mb   free space that must REMAIN on the volume;
 *                            below this the proxy stops WRITING but keeps
 *                            SERVING, so a full disk costs a re-fetch, never
 *                            a blank map
 *   tile_proxy_user_agent    blank = auto-generated identifying UA. OSM's
 *                            policy blocks generic User-Agents outright, so
 *                            this must never end up as a library default.
 *
 * Deliberately does NOT touch `tile_mode`. An admin who chose 'direct' chose
 * it; making the proxy real is not a licence to switch their traffic for them.
 *
 * Safe to re-run.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 130 — tile proxy cache settings\n";
echo "=====================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

/**
 * Insert a setting only when absent. An existing value is the admin's, and
 * a re-runnable migration that overwrites admin choices is a migration that
 * silently reverts their configuration on every upgrade.
 */
function p130_seed(string $prefix, string $name, string $value, string $note): void
{
    try {
        $exists = db_fetch_value(
            "SELECT `name` FROM `{$prefix}settings` WHERE `name` = ?",
            [$name]
        );
        if ($exists === false || $exists === null) {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $value]);
            echo "[OK]   seeded {$name} = '{$value}'  ({$note})\n";
        } else {
            echo "[skip] {$name} already set — leaving the admin's value alone\n";
        }
    } catch (Throwable $e) {
        // Never silent: a seed that fails must say so, and must make the
        // runner's exit code non-zero (see the migration pitfall in CLAUDE.md
        // — a step that catches its own exception and exits 0 never ran).
        echo "[FAILED] {$name}: " . $e->getMessage() . "\n";
        throw $e;
    }
}

p130_seed($prefix, 'tile_cache_max_mb', '512',
    'on-disk tile cache ceiling, MB — LRU eviction beyond it');
p130_seed($prefix, 'tile_cache_min_free_mb', '1024',
    'free space that must remain on the volume, MB');
p130_seed($prefix, 'tile_proxy_user_agent', '',
    'blank = auto-generated identifying User-Agent');

// tile_cache_days already exists on most installs (Tile Providers panel). Seed
// a default only when absent, so the proxy has a TTL ceiling to clamp to.
p130_seed($prefix, 'tile_cache_days', '30',
    'max cached-tile lifetime, days — clamps what upstream asks for');

echo "\nDone. The tile proxy is active for providers whose terms permit it;\n";
echo "see Settings -> Maps -> Tile Providers for the per-provider verdicts.\n";
