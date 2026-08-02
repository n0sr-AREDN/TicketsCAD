<?php
/**
 * Phase 133 — settings for the outage-degradation fixes (D4, D5).
 *
 * These behaviours all work correctly with no rows at all — the code falls
 * back to the same defaults this seeds. They are seeded anyway so they EXIST
 * where an administrator can find them, because a setting nobody can discover
 * is only marginally better than one nobody reads, and this codebase has
 * shipped both.
 *
 *   map_offline_banner            Show "Map background unavailable — incident
 *                                 data is still live" when tiles stop loading.
 *                                 ON by default: a dispatcher misreading a grey
 *                                 map as a dead CAD is the more expensive
 *                                 mistake. An unattended wall display may
 *                                 prefer the picture uncluttered.
 *
 *   bridge_health_down_cache_sec  How long a DOWN verdict about a DMR bridge is
 *                                 reused across requests. The Communications
 *                                 Console re-probed every bridge on every page
 *                                 load at 1.5s each; 30s of reuse removes that
 *                                 without hiding anything that changes.
 *
 *   bridge_health_up_cache_sec    How long a CONNECTED verdict is reused.
 *                                 Deliberately much SHORTER. A live bridge
 *                                 answers in milliseconds so there is little to
 *                                 save, and this is a radio console: the gap
 *                                 between a bridge dying and an operator seeing
 *                                 it should be as small as we can make it.
 *
 * Safe to re-run. Never overwrites an existing value.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 133 — outage-degradation settings\n";
echo "=======================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$failures = [];

function p133_seed(string $prefix, string $name, string $value, string $note): void
{
    global $failures;
    try {
        $exists = db_fetch_value("SELECT `name` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        if ($exists === false || $exists === null) {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $value]);
            echo "[OK]   seeded {$name} = '{$value}'  ({$note})\n";
        } else {
            echo "[skip] {$name} already set — leaving the admin's value alone\n";
        }
    } catch (Throwable $e) {
        // Never silent, and never exit 0 on failure: a migration step that
        // catches its own exception and succeeds is a step that never ran.
        $failures[] = $name . ': ' . $e->getMessage();
        echo "[fail] {$name}: " . $e->getMessage() . "\n";
    }
}

p133_seed($prefix, 'map_offline_banner', '1',
    'tell the dispatcher when the map background is unavailable');
p133_seed($prefix, 'bridge_health_down_cache_sec', '30',
    'reuse a DOWN bridge verdict this long');
p133_seed($prefix, 'bridge_health_up_cache_sec', '5',
    'reuse a CONNECTED bridge verdict this long — short on purpose');

echo "\nVerifying...\n";
foreach (['map_offline_banner', 'bridge_health_down_cache_sec', 'bridge_health_up_cache_sec'] as $name) {
    try {
        if (!db_fetch_one("SELECT `name` FROM `{$prefix}settings` WHERE `name` = ?", [$name])) {
            $failures[] = $name . ': row missing after seeding';
            echo "[fail] {$name} is still absent\n";
        }
    } catch (Throwable $e) {
        $failures[] = $name . ': verify failed — ' . $e->getMessage();
        echo "[fail] {$name}: verify failed — " . $e->getMessage() . "\n";
    }
}

if ($failures) {
    echo "\nFAILED (" . count($failures) . "):\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}
echo "\nPhase 133 complete.\n";
exit(0);
