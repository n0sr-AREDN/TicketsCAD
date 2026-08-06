<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

/**
 * Phase 132 Step 4 (2026-08-04, GH #16) — seed captions for the new
 * incident-detail disposition UI (the "any time" dropdown beside the Add
 * Note box, and the close-flow dropdown beside the status control). See
 * specs/phase-132-incident-disposition/{spec.md,plan.md,tasks.md}.
 *
 * Step 1's migration (sql/run_phase132_disposition.php) seeded the 6
 * disposition LABELS (category 'disposition') but had no UI yet to seed
 * strings for — Step 4 built that UI and needs its own captions. Mirrors
 * sql/run_incdetail_comments_caption.php's pattern exactly: idempotent
 * INSERT IGNORE on (caption_key, lang), category 'incdetail' (matching
 * every other label on this page), self-verifying exit code.
 *
 * Per the standing rule (CLAUDE.md "Captions"), a new t() call with no
 * seeded row leaves the Translations UI with nothing to edit and pins
 * the string to its hardcoded English fallback forever.
 *
 * Auto-discovered by sql/run_migrations.php's glob('run_*.php') — no
 * separate wiring needed.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 132 Step 4 — seed incident-detail disposition UI captions\n";
echo "=================================================================\n\n";

$translations = [
    'incdetail.label.disposition' => [
        'en' => 'Disposition',
        'de' => 'Erledigung',
        'nl' => 'Afhandeling',
        'fr' => 'Décision',
        'es' => 'Resolución',
    ],
    'incdetail.disposition.none' => [
        'en' => '— None —',
        'de' => '— Keine —',
        'nl' => '— Geen —',
        'fr' => '— Aucune —',
        'es' => '— Ninguna —',
    ],
    'incdetail.disposition.required_error' => [
        'en' => 'A disposition is required before closing this incident.',
        'de' => 'Vor dem Schließen dieses Vorfalls ist eine Erledigung erforderlich.',
        'nl' => 'Er is een afhandeling vereist voordat dit incident kan worden gesloten.',
        'fr' => 'Une décision est requise avant de clôturer cet incident.',
        'es' => 'Se requiere una resolución antes de cerrar este incidente.',
    ],
];

$fail = [];
$capAdded = 0;
foreach ($translations as $key => $langs) {
    foreach ($langs as $lang => $value) {
        try {
            db_query(
                "INSERT IGNORE INTO `{$prefix}captions_i18n` (`caption_key`, `lang`, `value`, `category`)
                 VALUES (?, ?, ?, 'incdetail')",
                [$key, $lang, $value]
            );
            $capAdded += (int) db_fetch_value('SELECT ROW_COUNT()');
        } catch (Exception $e) {
            $fail[] = "caption {$key}[{$lang}]: " . $e->getMessage();
            echo "  [FAIL] caption {$key}[{$lang}]: " . $e->getMessage() . "\n";
        }
    }
}
echo "  [+] captions: {$capAdded} new row(s) seeded (" . count($translations) . " keys x 5 languages)\n";

// Verify the outcome rather than trusting the statements ran — a tracker
// entry and a clean exit are evidence the script RAN, not that it WORKED.
$expected = count($translations) * 5;
$present = 0;
foreach (array_keys($translations) as $key) {
    $present += (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}captions_i18n` WHERE `caption_key` = ?", [$key]);
}
if ($present < $expected || !empty($fail)) {
    fwrite(STDERR, "FAILED: expected {$expected} caption rows, found {$present}. Errors: "
        . implode('; ', $fail) . "\n");
    exit(1);
}

echo "done: {$present}/{$expected} caption rows present across " . count($translations) . " keys.\n";
