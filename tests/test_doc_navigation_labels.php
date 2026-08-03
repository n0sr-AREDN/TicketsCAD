<?php
/**
 * Documentation gate — a `Settings → X` path may only name a menu item that
 * the settings sidebar actually renders.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 *
 * Nine shipped documents, three published security advisories and five places
 * in the application's own text told operators to open **"Settings → Status"**.
 * There has never been a menu item called Status. `inc/config-sidebar.php`
 * renders that link — to `status.php` — with the label **"System Health"**.
 * "Settings → Backup" was the same shape of wrong: the item reads
 * **"Backup / Maintenance"**.
 *
 * That is not a typo, it is the house disease one layer out: a document
 * confidently naming something that does not exist, exactly like a query
 * written against a remembered schema (CLAUDE.md, "THE SCHEMA-MISMATCH
 * PATTERN") or JS reading a key no endpoint emits ("THE API↔JS CONTRACT
 * PATTERN"). Nothing could catch it, because nothing compared the words in
 * the docs to the words in the menu. It was found by a human reviewing a
 * training video — the only reason anyone noticed — and by then it was in a
 * *Critical security advisory*, sending operators who were mid-incident to
 * hunt for a menu item that is not there.
 *
 * ── HOW IT WORKS, AND WHY IT DERIVES RATHER THAN LISTS ────────────────────
 *
 * The valid labels are PARSED OUT OF `inc/config-sidebar.php` on every run.
 * A gate that carried its own hardcoded copy of the menu would be the same
 * defect wearing a test's clothes: rename a tab and the gate keeps cheerfully
 * approving the old name. Here, renaming a tab immediately makes every doc
 * that still uses the old name fail — which is the behaviour we want, because
 * that is precisely the moment the docs go stale.
 *
 * Two extraction paths, because the sidebar uses two shapes:
 *   1. `t('sidebar.<key>', 'English Default')` — the second argument is the
 *      label an English session sees. This is how nearly every item is
 *      written, INCLUDING the one hand-built <li> (Wastebasket) that never
 *      goes through _cfg_tab() at all.
 *   2. A bare literal passed to `_cfg_tab()` / `_cfg_link()` / `_cfg_sub()`,
 *      for anything not run through t().
 * Section headers and sub-headers count too — a doc may legitimately write
 * `Settings → Communications & Integrations → Telegram`.
 *
 * ── WHAT IT CANNOT SEE, AND SAYS SO ──────────────────────────────────────
 *
 *   - Anything past the FIRST arrow. `Settings → Backup / Maintenance →
 *     "Back up now"` names a button inside a panel; buttons are not in the
 *     sidebar and this parser has no way to enumerate them. Those segments
 *     are counted and reported, never asserted.
 *   - A label supplied only by a translation file. The parser reads the
 *     English default in the source; if an install renames a tab through the
 *     Translations UI, its docs are its own business.
 *   - A label that is not a plain literal in the sidebar source (built from a
 *     variable, or concatenated). None exist today, and the gate FAILS if one
 *     appears, rather than quietly validating against a shrunken label set.
 *   - "Settings" itself is the page's own <title> (settings.php), not the
 *     navbar button, which reads "Config". Docs have always written
 *     "Settings → …" and this gate does not relitigate that.
 *   - The difference between an instruction and a QUOTATION. A changelog entry
 *     that names the old wrong label reads to this gate exactly like a doc
 *     still telling people to go there, so a correction note has to name the
 *     bad label in prose rather than as a path.
 *
 * One rule that is easy to get backwards: several real labels themselves END
 * in the word "Settings" — Login Settings, Display Settings, System Settings.
 * In `Settings → Login Settings → Active Sessions` the second arrow is also
 * preceded by "Settings", and reading it as a fresh root turns a correct
 * nested path into a report that "Active Sessions" is missing from the menu.
 * It is not a menu item; it is a card inside Login Settings.
 *
 * A label split across two lines IS resolved — the continuation is joined
 * before matching, so a wrapped path is judged on what it says rather than on
 * where it happened to break. But the count is reported, because a wrapped
 * label is invisible to `grep -rn 'Settings → Status'`, and that is a large
 * part of why this defect survived as long as it did.
 *
 * Genuine exceptions live in tools/doc_nav_label_exceptions.txt — paths that
 * belong to a THIRD-PARTY app's Settings menu (ATAK, OwnTracks, the Traccar
 * Client), which are correct as written. Each needs a stated reason; the gate
 * rejects an entry without one, and fails when an entry stops matching
 * anything, so a line cannot outlive the text it excuses.
 *
 * Usage: php tests/test_doc_navigation_labels.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$passed = 0;
$failed = 0;

function test($label, $condition, $hint = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n";
        $failed++;
    }
}

$root = dirname(__DIR__);

// ─────────────────────────────────────────────────────────────────────────
//  1. Derive the real navigation labels from the sidebar source
// ─────────────────────────────────────────────────────────────────────────

/**
 * Every navigation label the settings sidebar can render, parsed from source.
 *
 * @return array{labels:string[], from_t:int, from_literal:int, unresolved:string[]}
 */
function nav_labels_from_sidebar(string $src): array
{
    $labels = [];
    $fromT = 0;
    $fromLiteral = 0;

    // (1) t('sidebar.*', 'English Default') — the second argument.
    if (preg_match_all("/\bt\(\s*'sidebar\.[A-Za-z0-9_.]+'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m)) {
        foreach ($m[1] as $lab) { $labels[] = stripslashes($lab); $fromT++; }
    }

    // (2) A bare literal handed to one of the sidebar emitters.
    $literalPatterns = [
        "/_cfg_tab\(\s*'[^']*'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/",
        "/_cfg_link\(\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/",
        "/_cfg_sub\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
    ];
    foreach ($literalPatterns as $re) {
        if (preg_match_all($re, $src, $m)) {
            foreach ($m[1] as $lab) { $labels[] = stripslashes($lab); $fromLiteral++; }
        }
    }

    // (3) Honesty check: every emitter call must have yielded a label by one
    //     route or the other. A call whose label is a variable or a
    //     concatenation is reported, not skipped — validating docs against a
    //     label set that silently lost entries is how a gate lies.
    $unresolved = [];
    if (preg_match_all('/(?<!function )_cfg_(?:tab|link|sub)\(([^;]*?)\);/s', $src, $calls)) {
        foreach ($calls[1] as $args) {
            $hasT = (bool) preg_match("/\bt\(\s*'sidebar\.[A-Za-z0-9_.]+'\s*,\s*'/", $args);
            $hasLit = (bool) preg_match("/,\s*'[^']*'/", $args);
            if (!$hasT && !$hasLit) {
                $unresolved[] = trim(preg_replace('/\s+/', ' ', $args));
            }
        }
    }

    $labels = array_values(array_unique(array_filter(array_map('trim', $labels), 'strlen')));
    return ['labels' => $labels, 'from_t' => $fromT, 'from_literal' => $fromLiteral, 'unresolved' => $unresolved];
}

$sidebarPath = $root . '/inc/config-sidebar.php';
$sidebarSrc = @file_get_contents($sidebarPath);

if ($sidebarSrc === false) {
    echo "SKIP: inc/config-sidebar.php is unreadable — the label set cannot be derived\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$derived = nav_labels_from_sidebar($sidebarSrc);
$LABELS = $derived['labels'];

echo "=== Doc navigation labels vs the real settings sidebar ===\n\n";
echo "-- The label set, derived from inc/config-sidebar.php --\n";
echo '     ' . count($LABELS) . " labels ({$derived['from_t']} via t(), {$derived['from_literal']} bare literals)\n";

test('the sidebar yields a plausible number of navigation labels',
    count($LABELS) >= 60,
    'got ' . count($LABELS) . ' — the parser has probably stopped matching the source');
test('every _cfg_tab/_cfg_link/_cfg_sub call resolved to a label',
    $derived['unresolved'] === [],
    'unresolved: ' . implode(' | ', array_slice($derived['unresolved'], 0, 3)));

// The two labels this whole gate was written for, plus one that exists ONLY
// because path (1) reads t() calls anywhere in the file — Wastebasket is
// emitted by a hand-built <li>, not by _cfg_tab().
test('"System Health" is derived (the real label for status.php)',
    in_array('System Health', $LABELS, true));
test('"Backup / Maintenance" is derived, slashes and all',
    in_array('Backup / Maintenance', $LABELS, true));
test('"Wastebasket" is derived from the hand-built <li>, not just _cfg_tab calls',
    in_array('Wastebasket', $LABELS, true),
    'the t() extraction path has regressed');
test('a section header is derived, not only tab labels',
    in_array('Communications & Integrations', $LABELS, true));
test('a sub-header is derived',
    in_array('Localization', $LABELS, true));
// The negative half of the derivation: the invented labels must NOT appear.
test('"Status" is NOT in the derived set',
    !in_array('Status', $LABELS, true),
    'if this passes the parser is inventing labels');
test('"Backup" alone is NOT in the derived set',
    !in_array('Backup', $LABELS, true));

// ─────────────────────────────────────────────────────────────────────────
//  2. The detector
// ─────────────────────────────────────────────────────────────────────────

/** Normalise a fragment of doc text for label comparison. */
function nav_normalise(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(["\xC2\xA0", "\xE2\x80\x99"], [' ', "'"], $s);
    $s = str_replace(['**', '__', '`'], '', $s);   // markdown emphasis / code
    $s = str_replace(['<strong>', '</strong>', '<em>', '</em>', '<b>', '</b>'], '', $s);
    $s = preg_replace('/[ \t]+/', ' ', $s);
    return $s;
}

/** The arrow forms a doc may use between navigation steps. */
const NAV_ARROW = '(?:\x{2192}|&rarr;|-&gt;|->)';

/**
 * Resolve one line of text that follows a `Settings →`.
 *
 * @return array{best:string,segment:string,rest:string[]}
 *         best is '' when the first segment names no real label.
 */
function nav_resolve_line(string $line, array $labels): array
{
    $parts = array_map(
        static fn($s) => rtrim(trim($s), '.,;:'),
        preg_split('/[ \t]*' . NAV_ARROW . '[ \t]*/u', nav_normalise($line))
    );
    // A quote left over from a PHP string concatenation the label broke across.
    $segment = ltrim((string) array_shift($parts), " \t'\"");

    $best = '';
    foreach ($labels as $L) {
        $n = nav_normalise($L);
        if ($n === '' || strlen($n) <= strlen($best)) { continue; }
        if (strncasecmp($segment, $n, strlen($n)) !== 0) { continue; }
        // Word boundary: "Status" must not satisfy a doc that wrote
        // "Statuses", and "Places" must not satisfy "Places Editor".
        $next = substr($segment, strlen($n), 1);
        if ($next === '' || $next === false || preg_match('/[^A-Za-z0-9]/', $next)) {
            $best = $n;
        }
    }
    return ['best' => $best, 'segment' => $segment, 'rest' => $parts];
}

/**
 * Find every `Settings → X` path in one blob of text.
 *
 * @return array<int,array{offset:int,segment:string,rest:string[],status:string,wrapped:bool}>
 *         status: 'ok' | 'unknown'
 */
function nav_scan_text(string $text, array $labels): array
{
    $hits = [];
    // Longest label first, so "Backup / Maintenance" wins over any shorter
    // label that happens to be a prefix of it.
    usort($labels, static fn($a, $b) => strlen($b) <=> strlen($a));

    // The lookbehind matters: without it "Display Settings → Time Zone" is
    // read as a path rooted at "Settings", and the sub-control after the
    // arrow gets reported as a missing menu item.
    if (!preg_match_all('/(?<![A-Za-z])Settings[ \t]*' . NAV_ARROW . '[ \t]*/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        return $hits;
    }

    // Labels that themselves END in the word "Settings" — "Login Settings",
    // "Display Settings", "System Settings". In `Settings → Login Settings →
    // Active Sessions` the SECOND arrow is preceded by "Settings" too, and
    // reading it as a second root turns a correct nested path into a report
    // that "Active Sessions" is missing from the menu. It is not a menu item;
    // it is a card inside Login Settings.
    $endsInSettings = array_values(array_filter(
        $labels,
        static fn($L) => preg_match('/(?<=.)\bSettings$/', trim($L)) === 1
    ));

    foreach ($m[0] as $match) {
        $isNested = false;
        foreach ($endsInSettings as $L) {
            $back = strlen($L) - strlen('Settings');
            if ($back <= 0 || $match[1] - $back < 0) { continue; }
            if (strcasecmp(substr($text, $match[1] - $back, strlen($L)), $L) === 0) {
                $isNested = true;
                break;
            }
        }
        if ($isNested) { continue; }

        $start = $match[1] + strlen($match[0]);
        $tail = substr($text, $start, 220);
        $nl = strpos($tail, "\n");
        $firstLine = $nl === false ? $tail : substr($tail, 0, $nl);

        $r = nav_resolve_line($firstLine, $labels);
        $wrapped = false;

        // Only when the first line does NOT resolve is the continuation read.
        // A label that breaks over a line is still a label, and judging it on
        // the fragment before the break would report a defect that isn't
        // there — but joining unconditionally would drag the next sentence
        // into the deeper segments of paths that were already fine.
        // The continuation's leading noise (a docblock ' * ', a '# ' shell
        // comment, a '// ', the `. '` of a PHP string concatenation) is
        // stripped. A leading '|' is NOT: that is the next row of a markdown
        // table, so the path really did end.
        if ($r['best'] === '' && $nl !== false) {
            $joined = preg_replace(
                '/\n[ \t]*(?:\*(?!\*)|#|\/\/|\.|\+)?[ \t]*[\'"]?[ \t]*/',
                ' ',
                $tail,
                1
            );
            $j = nav_resolve_line(preg_split('/\r?\n/', $joined)[0], $labels);
            if ($j['best'] !== '') { $r = $j; $wrapped = true; }
        }

        if (trim($r['segment']) === '' && $r['best'] === '') {
            $hits[] = ['offset' => $match[1], 'segment' => '(end of line)', 'rest' => [],
                       'status' => 'unknown', 'wrapped' => true];
            continue;
        }

        $segment = $r['segment'];
        $parts = $r['rest'];
        $best = $r['best'];

        if ($best === '') {
            // Trim the trailing prose so the report names the label, not a
            // sentence. Stop at the first punctuation that cannot be in a label.
            $shown = preg_split('/(?<=[a-zA-Z0-9\)])[,.;:"\)\]\|]|\s+\(|\s{2,}/', $segment)[0];
            $shown = trim(preg_replace('/\s+(and|or|in|on|to|for|is|are|the|then|with|which|it|panel|page|section|tab|shows?|tells?|lists?|says?|runs?|has|have|gains?|names?|reports?|probes?|prints?|answers?|counts?|turns?)\b.*$/i', '', $shown));
            if ($shown === '') { $shown = trim($segment); }
            $hits[] = ['offset' => $match[1], 'segment' => $shown, 'rest' => $parts,
                       'status' => 'unknown', 'wrapped' => false];
        } else {
            // wrapped === the label was only resolvable by reading past a break.
            $hits[] = ['offset' => $match[1], 'segment' => $best, 'rest' => $parts,
                       'status' => 'ok', 'wrapped' => $wrapped];
        }
    }
    return $hits;
}

/** Byte offset -> 1-based line number. */
function nav_line_of(string $text, int $offset): int
{
    return substr_count($text, "\n", 0, min($offset, strlen($text))) + 1;
}

// ─────────────────────────────────────────────────────────────────────────
//  3. Both directions — the detector must catch a bad path AND clear a good one
// ─────────────────────────────────────────────────────────────────────────
//
// A detector only ever run over the tree it was written against proves
// nothing: if its regex silently stopped matching, every doc would "pass".
// So it is driven over planted files whose answers are known, through the
// same file-scanning entry point the real scan uses.

echo "\n-- Both directions, against planted fixtures --\n";

$fixDir = sys_get_temp_dir() . '/newui_nav_fixtures_' . getmypid();
@mkdir($fixDir, 0777, true);

$goodDoc = "Open **Settings → System Health** and read the Web exposure row.\n"
         . "Set the folder in Settings → Backup / Maintenance → Backup folder.\n"
         . "See Settings -> Communications & Integrations -> Telegram for the token.\n"
         . "The Wastebasket lives at Settings → Wastebasket.\n";
$badDoc  = "Check **Settings → Status** for the answer.\n"
         . "Take one first (Settings → Backup → \"Back up now\").\n"
         . "Open Settings → Moon Phase Calibration to continue.\n";
$wrapDoc = "The archives are listed in Settings →\nBackup / Maintenance, which is a panel.\n";

file_put_contents($fixDir . '/good.md', $goodDoc);
file_put_contents($fixDir . '/bad.md', $badDoc);
file_put_contents($fixDir . '/wrapped.md', $wrapDoc);

$gh = nav_scan_text(file_get_contents($fixDir . '/good.md'), $LABELS);
$bh = nav_scan_text(file_get_contents($fixDir . '/bad.md'), $LABELS);
$wh = nav_scan_text(file_get_contents($fixDir . '/wrapped.md'), $LABELS);

$gBad = array_values(array_filter($gh, static fn($h) => $h['status'] !== 'ok'));
$bBad = array_values(array_filter($bh, static fn($h) => $h['status'] === 'unknown'));

test('the detector finds all four paths in the good fixture', count($gh) === 4, 'found ' . count($gh));
test('PASS direction: a correct doc raises nothing', $gBad === [],
    'flagged: ' . implode(', ', array_column($gBad, 'segment')));
test('the good fixture resolved "Backup / Maintenance" whole, not "Backup"',
    ($gh[1]['segment'] ?? '') === 'Backup / Maintenance', $gh[1]['segment'] ?? '(none)');
test('the good fixture accepted an ASCII -> arrow and a section header',
    ($gh[2]['segment'] ?? '') === 'Communications & Integrations', $gh[2]['segment'] ?? '(none)');
test('deeper segments are recorded, not validated',
    ($gh[1]['rest'] ?? []) === ['Backup folder'], implode('/', $gh[1]['rest'] ?? []));
// Deeper segments name buttons and cards inside a panel. This parser cannot
// enumerate those, so it must not pretend to judge them.
$deepFixture = nav_scan_text("Open Settings → System Health → Moon Phase Calibration now.\n", $LABELS);
test('a deeper segment that is NOT a menu item does not fail the path',
    ($deepFixture[0]['status'] ?? '') === 'ok'
        && ($deepFixture[0]['rest'] ?? []) === ['Moon Phase Calibration now'],
    ($deepFixture[0]['status'] ?? '(none)') . ' / ' . implode('|', $deepFixture[0]['rest'] ?? []));

test('FAIL direction: the bad fixture raises exactly three violations',
    count($bBad) === 3, 'raised ' . count($bBad) . ': ' . implode(', ', array_column($bBad, 'segment')));
test('FAIL direction: the historical defect "Status" is caught',
    ($bBad[0]['segment'] ?? '') === 'Status', $bBad[0]['segment'] ?? '(none)');
test('FAIL direction: "Backup" alone is caught, not silently widened',
    ($bBad[1]['segment'] ?? '') === 'Backup', $bBad[1]['segment'] ?? '(none)');
test('FAIL direction: a wholly invented panel is caught',
    ($bBad[2]['segment'] ?? '') === 'Moon Phase Calibration', $bBad[2]['segment'] ?? '(none)');
// A label broken over a line is judged on what it says, not on where it
// broke — but the break is still reported, because it defeats grep.
test('a label split across lines is joined and resolved, not misreported',
    count($wh) === 1 && ($wh[0]['status'] ?? '') === 'ok'
        && ($wh[0]['segment'] ?? '') === 'Backup / Maintenance',
    ($wh[0]['status'] ?? '(none)') . '/' . ($wh[0]['segment'] ?? ''));
test('...and the fact that it was split is reported',
    ($wh[0]['wrapped'] ?? false) === true);
test('a WRONG label split across lines is still caught',
    (nav_scan_text("archives are listed in Settings →\nBackup, a panel.\n", $LABELS)[0]['status'] ?? '') === 'unknown');
// "Display Settings → Time Zone" names a control inside a real panel. Reading
// the second arrow as a fresh root would report "Time Zone" as a missing menu
// item, which is how a gate manufactures work that does not need doing.
$nested = nav_scan_text("Change it via Settings → Display Settings → Time Zone.\n", $LABELS);
test('an arrow after a label that itself ends in "Settings" is not a second root',
    count($nested) === 1 && ($nested[0]['segment'] ?? '') === 'Display Settings'
        && ($nested[0]['rest'] ?? []) === ['Time Zone'],
    count($nested) . ' hit(s): ' . implode(' / ', array_column($nested, 'segment')));

// A renamed tab must invalidate the docs that still use the old name — the
// whole point of deriving rather than listing.
$pretend = array_values(array_diff($LABELS, ['System Health']));
$pretend[] = 'Install Health';
$rh = nav_scan_text("See Settings → System Health for this.\n", $pretend);
test('renaming a sidebar label immediately invalidates docs using the old one',
    ($rh[0]['status'] ?? '') === 'unknown',
    'the label set is not really driving the check');

@unlink($fixDir . '/good.md'); @unlink($fixDir . '/bad.md'); @unlink($fixDir . '/wrapped.md');
@rmdir($fixDir);

// ─────────────────────────────────────────────────────────────────────────
//  4. Exceptions — third-party menus, each with a stated reason
// ─────────────────────────────────────────────────────────────────────────

$excPath = $root . '/tools/doc_nav_label_exceptions.txt';
$exceptions = [];      // "relative/path|label" => reason
$excMalformed = [];
if (is_file($excPath)) {
    foreach (preg_split('/\r?\n/', (string) file_get_contents($excPath)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        $cols = array_map('trim', explode('|', $line));
        if (count($cols) < 3 || $cols[0] === '' || $cols[1] === '' || $cols[2] === '') {
            $excMalformed[] = $line;
            continue;
        }
        $exceptions[$cols[0] . '|' . $cols[1]] = $cols[2];
    }
}
echo "\n-- Exceptions --\n     " . count($exceptions) . " entries in tools/doc_nav_label_exceptions.txt\n";
test('every exception states a reason',
    $excMalformed === [],
    'malformed: ' . implode(' / ', array_slice($excMalformed, 0, 2)));

// ─────────────────────────────────────────────────────────────────────────
//  5. The real scan
// ─────────────────────────────────────────────────────────────────────────
//
// Shipped documentation AND the application's own operator-facing text: a
// remediation string in inc/health-check.php sends a reader to a menu just as
// surely as a runbook does. Excluded: vendor/node_modules (not ours), specs/
// (a historical record — a shipped spec is not edited after the fact),
// .claude/worktrees (other agents' scratch copies), and tests/ + any test_*
// file, whose fixtures deliberately contain wrong labels.

function nav_scan_targets(string $root): array
{
    $skip = ['/vendor/', '/node_modules/', '/.claude/', '/.git/', '/specs/',
             '/cache/', '/uploads/', '/backups/', '/tests/', '/coordination/'];
    $out = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($rii as $f) {
        if (!$f->isFile()) { continue; }
        $p = str_replace('\\', '/', $f->getPathname());
        foreach ($skip as $s) { if (strpos($p, $s) !== false) { continue 2; } }
        // Not shipped text: test fixtures deliberately contain wrong labels,
        // and the exceptions file quotes the paths it is excusing.
        if (strpos(basename($p), 'test_') === 0) { continue; }
        if (basename($p) === 'doc_nav_label_exceptions.txt') { continue; }
        if (!preg_match('/\.(md|php|js|txt|html|conf|sh|yml)$/i', $p)) { continue; }
        $out[] = $p;
    }
    sort($out);
    return $out;
}

$targets = nav_scan_targets($root);
$violations = [];
$wrapped = [];
$deeper = 0;
$okPaths = 0;
$excUsed = 0;
$filesWithPaths = 0;

foreach ($targets as $p) {
    $rel = substr(str_replace('\\', '/', $p), strlen(str_replace('\\', '/', $root)) + 1);
    $text = (string) file_get_contents($p);
    if (strpos($text, 'Settings') === false) { continue; }
    $hits = nav_scan_text($text, $LABELS);
    if ($hits) { $filesWithPaths++; }
    foreach ($hits as $h) {
        $deeper += count($h['rest']);
        $line = nav_line_of($text, $h['offset']);
        if (!empty($h['wrapped'])) { $wrapped[] = "$rel:$line"; }
        if ($h['status'] === 'ok') { $okPaths++; continue; }
        if (isset($exceptions[$rel . '|' . $h['segment']])) { $excUsed++; continue; }
        $violations[] = "$rel:$line  Settings → " . $h['segment'];
    }
}

echo "\n-- Scan --\n";
echo "     " . count($targets) . " files considered, $filesWithPaths contain a Settings → path\n";
echo "     $okPaths paths name a real menu item\n";
echo "     $deeper deeper segments recorded but NOT validated (page controls this parser cannot enumerate)\n";
echo "     $excUsed paths matched a stated exception (" . count($exceptions) . " on file)\n";
echo "     " . count($wrapped) . " labels were split across a line break — joined to validate, but they defeat grep\n";

if ($violations) {
    echo "\n     Paths naming a menu item that does not exist:\n";
    foreach ($violations as $v) { echo "       $v\n"; }
}

test('no shipped text names a settings menu item that does not exist',
    $violations === [],
    count($violations) . ' path(s) — see the list above');
test('every exception on file is still needed',
    $excUsed === count($exceptions),
    ($excUsed) . ' of ' . count($exceptions) . ' used — a stale exception hides a real check');
test('the scan actually reached the documentation',
    $okPaths >= 40,
    "only $okPaths valid paths found — the scan or the file filter has broken");

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
