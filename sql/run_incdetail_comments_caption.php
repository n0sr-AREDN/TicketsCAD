<?php
/**
 * GH TicketsCAD#16 (Ron Jones) — seed incdetail.label.comments.
 *
 * incident-detail.php hardcoded this field's label as "Comments" with no t()
 * wrapper, while 110 sibling incdetail.* captions on the same page are seeded
 * and translated. So on a non-English install that one label rendered in
 * English, and an admin could not rename it through the caption system — which
 * v3 could, its own changelog recording:
 *
 *     10/15/08 changed 'Comments' to 'Disposition'
 *
 * It is the same field as new-incident.php's newinc.label.comments, so it takes
 * the same wording, in the same five languages that key already ships in.
 * Seeding all five rather than English-only matters here: a German install
 * already shows "Erledigung / Kommentare" on the create form, and having the
 * detail page say "Comments" for the identical field reads as two different
 * fields.
 *
 * Per the standing rule, a new t() call without a seeded row leaves the
 * Translations UI with nothing to edit and the string permanently stuck on its
 * hardcoded fallback.
 *
 * Idempotent — INSERT IGNORE on (caption_key, lang). Safe to re-run.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH TicketsCAD#16 — seed incdetail.label.comments\n";
echo "===============================================\n\n";

// Taken from the existing newinc.label.comments rows so the two surfaces cannot
// disagree about what the same field is called.
$translations = [
    'en' => 'Disposition / Comments',
    'de' => 'Erledigung / Kommentare',
    'nl' => 'Afhandeling / opmerkingen',
    'fr' => 'Décision / commentaires',
    'es' => 'Resolución / comentarios',
];

$key   = 'incdetail.label.comments';
$added = 0;

foreach ($translations as $lang => $value) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}captions_i18n` (`caption_key`, `lang`, `value`, `category`)
             VALUES (?, ?, ?, 'incdetail')",
            [$key, $lang, $value]
        );
        $added += (int) db_fetch_value('SELECT ROW_COUNT()');
    } catch (Exception $e) {
        // Never exit 0 on a failure: a migration that reports success it did not
        // achieve is indistinguishable from one that worked.
        fwrite(STDERR, "ERROR seeding $key [$lang]: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// Verify the outcome rather than trusting that the statements ran. A tracker
// entry and a clean exit are evidence the script RAN, not that it WORKED.
$present = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}captions_i18n` WHERE `caption_key` = ?",
    [$key]
);
if ($present < count($translations)) {
    fwrite(STDERR, "FAILED: expected " . count($translations)
        . " rows for $key, found $present\n");
    exit(1);
}

echo "done: $added new caption row(s) seeded; $present total for $key\n";
echo "This label is now renameable in Settings → Translations.\n";
