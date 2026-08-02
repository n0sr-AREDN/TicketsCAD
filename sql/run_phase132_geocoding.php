<?php
/**
 * Phase 132 — settings for the (now real) server-side geocoding path.
 *
 * Background: `geocoding_provider` and `geocoding_api_key` have been offered
 * by the Settings page since Phase 43 and read by NOTHING —
 * `git log --all -S geocoding_provider -- assets/ api/ inc/` is empty, so no
 * consumer was ever written. Meanwhile every one of the eleven geocoding calls
 * hardcoded nominatim.openstreetmap.org in the dispatcher's browser. An
 * administrator who pointed that dropdown at their own server got nothing, and
 * offline address lookup was not merely unconfigured but impossible.
 *
 * inc/geocode.php + api/geocode.php implement it for real. This seeds the new
 * keys the feature reads:
 *
 *   geocoding_mode              server | direct | off. 'server' by default —
 *                               see the note below on why an existing install
 *                               is moved rather than left where it was.
 *   geocoding_url               address of a self-hosted Nominatim/Photon.
 *   geocoding_cache_hours       how long a result is kept. Caching is not an
 *                               optimisation here: OSM's Nominatim usage
 *                               policy REQUIRES it.
 *   geocoding_min_interval_ms   blank = the provider's own published limit.
 *   geocoding_user_agent        blank = auto-generated identifying UA.
 *                               Nominatim blocks generic User-Agents outright.
 *
 * WHY THIS ONE DOES CHANGE BEHAVIOUR, WHERE PHASE 130 DELIBERATELY DID NOT.
 *
 * Phase 130 refused to touch `tile_mode`, on the principle that an admin who
 * chose a value chose it. That principle does not apply here, because there
 * was never a choice to respect: the mode key did not exist, and no admin has
 * ever expressed a preference about it. Seeding 'server' is not overriding
 * anyone — it is giving a value to a setting that had none.
 *
 * It is also the only default that is defensible. Browser-direct lookup cannot
 * cache, cannot throttle and cannot identify itself, so it cannot satisfy the
 * Nominatim usage policy the shipped default provider is governed by; and it
 * cannot reach a geocoder on your own LAN at all, because an HTTPS page is
 * blocked from fetching http://10.x.x.x as mixed content. An install that
 * genuinely wants the old behaviour sets `direct` in Settings, and an
 * air-gapped one sets `off`.
 *
 * `geocoding_provider` is seeded to 'nominatim' ONLY if absent, so an admin
 * who already picked LocationIQ in the old (inert) dropdown keeps that choice
 * — and, for the first time, it will do something.
 *
 * Safe to re-run.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 132 — geocoding settings\n";
echo "==============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$failures = [];

/**
 * Insert a setting only when absent. An existing value is the admin's, and a
 * re-runnable migration that overwrites admin choices silently reverts their
 * configuration on every upgrade.
 */
function p132_seed(string $prefix, string $name, string $value, string $note): void
{
    global $failures;
    try {
        $exists = db_fetch_value(
            "SELECT `name` FROM `{$prefix}settings` WHERE `name` = ?",
            [$name]
        );
        if ($exists === false || $exists === null) {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $value]);
            echo "[OK]   seeded {$name} = '" . ($value === '' ? '(blank)' : $value) . "'  ({$note})\n";
        } else {
            echo "[skip] {$name} already set — leaving the admin's value alone\n";
        }
    } catch (Throwable $e) {
        // Never silent. A seed that fails must say so AND must make the runner
        // exit non-zero — a migration step that catches its own exception and
        // exits 0 is a step that never ran (see CLAUDE.md).
        $failures[] = $name . ': ' . $e->getMessage();
        echo "[fail] {$name}: " . $e->getMessage() . "\n";
    }
}

p132_seed($prefix, 'geocoding_mode', 'server',
    'lookups go through this server: cacheable, rate-limitable, and able to reach a LAN geocoder');
p132_seed($prefix, 'geocoding_provider', 'nominatim',
    'public OpenStreetMap geocoder — no key required');
p132_seed($prefix, 'geocoding_url', '',
    'address of your own Nominatim/Photon, when you run one');
p132_seed($prefix, 'geocoding_cache_hours', '24',
    "OSM's usage policy requires caching; this is how long a result is kept");
p132_seed($prefix, 'geocoding_min_interval_ms', '',
    "blank = the provider's own published rate limit");
p132_seed($prefix, 'geocoding_user_agent', '',
    'blank = auto-generated identifying User-Agent (generic ones are blocked)');

// ── VERIFY, rather than trusting that the inserts ran ────────────────────
//
// "It printed [OK]" is evidence the script ran, not that the outcome holds.
// Ask the database.
echo "\nVerifying...\n";
$required = ['geocoding_mode', 'geocoding_provider', 'geocoding_url',
             'geocoding_cache_hours', 'geocoding_min_interval_ms', 'geocoding_user_agent'];
foreach ($required as $name) {
    try {
        $row = db_fetch_one("SELECT `name` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        if (!$row) {
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
echo "\nPhase 132 complete.\n";
exit(0);
