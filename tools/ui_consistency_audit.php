<?php
/**
 * UI-consistency audit — catches new interface work drifting from the
 * conventions the rest of this product already follows.
 *
 * WHY THIS EXISTS (Eric, 2026-07-31)
 * ----------------------------------
 * A newly shipped widget was reviewed on a live system and found inconsistent
 * with every other widget in the product. In Eric's words: "I do not want the
 * software to appear as if we have multiple developers who have never seen this
 * software before or ever talked to each other, working on the same codebase."
 *
 * The three concrete drifts, each of which has a rule below:
 *
 *   1. Action hotkeys rendered as a plain run of text along the bottom of the
 *      panel. Every other widget renders them as labelled buttons with keycap
 *      badges in the HEADER (assets/js/widget-manager.js:196-250 — three bars,
 *      17 buttons, one shape).
 *   2. A dismiss control with no way to reopen. Every dashboard widget's
 *      top-right control is the shared REFRESH affordance
 *      (widget-manager.js:277-278); open/close belongs to the widgets toolbar
 *      (index.php:133-182 -> toggleWidget()).
 *   3. Per-user state persisted by a newly invented mechanism, when
 *      `dashboard_layouts` (api/layout.php) and Phase 17's `user_screen_prefs`
 *      (inc/screen-prefs.php, api/screen-prefs.php) already exist.
 *
 * Documentation alone does not stop this; gates are what has actually been
 * catching things here. This is the fifth member of that family, after
 * schema_audit, api_contract_audit, legacy_level_audit and timezone_audit:
 * a CLI scanner, a plain-text baseline of KNOWN findings, and a test under
 * tests/ that fails the suite ONLY on findings the baseline does not list.
 * Existing debt must not block work; NEW drift must.
 *
 * EVERY RULE BELOW WAS DERIVED FROM THE CODE, NOT INVENTED. Counts over the
 * product tree (994 files, worktree copies excluded) at the time of writing:
 *
 *   icon-source        2305 `bi bi-*`, 0 FontAwesome/glyphicon/material  (100%)
 *   control-size       659/732 form-control, 298/306 form-select carry -sm
 *   theme-color        518 var(--bs-*) vs 289 hardcoded hex on a themed prop
 *   es5                92 of 95 project JS files free of arrow functions
 *   widget-registry    all 10 widget ids present in all 6 parallel registries
 *   action-bar         3 of 3 action bars share one markup shape and one CSS
 *                      selector group (assets/css/widgets.css:373,386,400)
 *
 * Rules that were CONSIDERED AND REJECTED are recorded at the bottom of this
 * file, so the next person does not re-propose them.
 *
 * THE ISOLATED-STRING-LITERAL TRAP
 * --------------------------------
 * tools/schema_audit.php examined each PHP string literal on its own and so
 * missed all 89 writer INSERTs, every one of which is built by concatenation
 * (Phase 125). Markup here has the same shape, and there is no template-literal
 * escape hatch because the JavaScript is ES5. tools/ui_extract.php is this
 * audit's equivalent of tools/sql_extract.php: it blanks `<?php … ?>` regions
 * inside a tag's own attribute list, and stitches `'a' + expr + 'b'` chains, so
 * a rule about "the widget header's control" can actually see
 * widget-manager.js:277 — which is three literals with the attribute name in
 * one and its value in another.
 *
 * Exit code: 0 = clean (or only baseline-listed findings), 1 = new findings.
 * Baseline: tools/ui_consistency_baseline.txt — one finding key per line.
 *
 * Usage:
 *   php tools/ui_consistency_audit.php              # report + exit code
 *   php tools/ui_consistency_audit.php --all        # include baseline entries
 *   php tools/ui_consistency_audit.php --rules      # list the rule ids
 *   php tools/ui_consistency_audit.php --path=DIR   # scan a fixture tree
 *                                                   # (how the gate test drives
 *                                                   #  the REAL detectors)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/ui_extract.php';

$argvSafe  = $argv ?? [];
$showAll   = in_array('--all', $argvSafe, true);
$listRules = in_array('--rules', $argvSafe, true);
$scanRoot  = null;
foreach ($argvSafe as $a) {
    if (strpos($a, '--path=') === 0) { $scanRoot = substr($a, 7); }
}
if ($scanRoot === null) { chdir(__DIR__ . '/..'); $scanRoot = '.'; }

// ── The rules, and the convention each one encodes ────────────────────────
const UIA_RULES = [
    'widget-registry' =>
        'a dashboard widget id must appear in every registry that describes it '
        . '(DEFAULT_LAYOUT / WIDGET_ICONS / WIDGET_LABELS_EN in '
        . 'assets/js/widget-manager.js, $__allowedWidgets and DASH_WIDGET_TITLES '
        . 'in index.php, a <template id="tpl-ID">, and a .widget-toggle button)',
    'widget-header' =>
        'a dashboard tile\'s header comes from the shared emitter in '
        . 'assets/js/widget-manager.js — do not hand-roll a card-header inside a '
        . 'grid-stack item',
    'widget-header-control' =>
        'the top-right control of a dashboard panel header is the shared refresh '
        . 'affordance; open/close belongs to the widgets toolbar, so a dismiss '
        . 'button with no re-open path does not belong there',
    'hotkey-affordance' =>
        'keyboard actions on a dashboard panel are labelled buttons with keycap '
        . 'badges in the HEADER (btn-xs + *-action-btn + .action-label + <kbd>), '
        . 'not a run of <kbd> text',
    'action-bar-css' =>
        'a new *-action-bar must be added to all three selector groups in '
        . 'assets/css/widgets.css or its buttons inherit full Bootstrap sizing',
    'theme-color' =>
        'colours come from Bootstrap CSS variables so both themes work; a '
        . 'hardcoded hex outside a [data-bs-theme] block is theme-blind',
    'control-size' =>
        'form controls use the compact -sm variants the UX principles require',
    'icon-source' =>
        'icons come from Bootstrap Icons (bi bi-*)',
    'form-button-type' =>
        'a <button> inside a <form> must set type="button" unless it really '
        . 'submits — without it the browser submits and reloads the page',
    'state-store' =>
        'per-user layout/column state persists server-side (api/layout.php -> '
        . 'dashboard_layouts, or ScreenPrefs -> user_screen_prefs); localStorage '
        . 'is a cache alongside that write, not a replacement for it',
    'es5' =>
        'browser JavaScript is ES5 in an IIFE — no arrow functions, let/const, '
        . 'template literals or destructuring',
];

if ($listRules) {
    foreach (UIA_RULES as $id => $why) { echo "$id\n    $why\n"; }
    exit(0);
}

// ── Finding collection ────────────────────────────────────────────────────
$findings = [];   // key => [[file, line, message], ...]

/**
 * Record a finding.
 *
 * The KEY is what the baseline matches on, so it must survive reformatting and
 * line movement while still being specific enough that a second violation in an
 * already-baselined file is a NEW key. Following tools/legacy_level_audit.php
 * and tools/geocode_audit.php: rule + path + a whitespace-collapsed excerpt of
 * the offending source.
 */
function uia_add(string $rule, string $file, int $line, string $evidence, string $msg): void
{
    global $findings;
    $ev = trim(preg_replace('/\s+/', ' ', $evidence) ?? '');
    if (strlen($ev) > 120) { $ev = substr($ev, 0, 117) . '...'; }
    $findings["$rule: $file :: $ev"][] = [$file, $line, $msg];
}

// ── Collect source ────────────────────────────────────────────────────────
$phpFiles = ui_collect_files($scanRoot, ['php']);
$jsFiles  = ui_collect_files($scanRoot, ['js']);
$cssFiles = ui_collect_files($scanRoot, ['css']);

// Non-product PHP: the test suite and the tooling itself quote markup in their
// own assertions and doc comments. Scanning them reports the gate's own
// examples as violations of the gate.
$isFixture = ($scanRoot !== '.');
$phpFiles = array_values(array_filter($phpFiles, static function (string $p) use ($isFixture) {
    if ($isFixture) { return true; }
    return !preg_match('#^(tests|tools|specs|docs)/#', $p);
}));

$src = [];   // path => raw source
foreach (array_merge($phpFiles, $jsFiles, $cssFiles) as $p) {
    $full = rtrim($scanRoot, '/') . '/' . $p;
    $s = @file_get_contents($full);
    if ($s !== false) { $src[$p] = $s; }
}
echo count($phpFiles) . ' PHP, ' . count($jsFiles) . ' JS, ' . count($cssFiles)
    . " CSS files scanned\n";

/** Markup chunks for a file, computed once. */
$chunkCache = [];
$chunks = static function (string $p) use (&$chunkCache, $src): array {
    if (!isset($chunkCache[$p])) {
        $chunkCache[$p] = ui_markup_chunks($p, $src[$p] ?? '');
    }
    return $chunkCache[$p];
};

// ── Which files render a dashboard panel? ─────────────────────────────────
//
// Derived, not hardcoded: the widget engine and its widget scripts, plus any
// file that speaks GridStack, plus every inc/ include the dashboard pulls in.
// Scoping matters — <kbd> in help.php is a keyboard-shortcut REFERENCE and a
// btn-close in a modal is correct everywhere. Neither is a widget affordance.
$panelFiles = [];
foreach (array_merge($phpFiles, $jsFiles) as $p) {
    $s = $src[$p] ?? '';
    if (strpos($s, 'grid-stack') !== false || strpos($s, 'GridStack') !== false) {
        $panelFiles[$p] = true;
    }
    if (preg_match('#^assets/js/widgets/#', $p)) { $panelFiles[$p] = true; }
}
foreach (['index.php', 'situation.php'] as $dash) {
    if (!isset($src[$dash])) { continue; }
    if (preg_match_all('#(?:include|require)(?:_once)?[\s(]+[^;]*?[\'"/](inc/[a-z0-9_.-]+\.php)#i',
            $src[$dash], $m)) {
        foreach ($m[1] as $inc) { if (isset($src[$inc])) { $panelFiles[$inc] = true; } }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: widget-registry
//
// Six parallel lists describe the same ten widget ids. They are consistent
// today; the cost of that is that adding a widget means editing six places,
// and forgetting one is silent — widget-manager.js:164-166 skips a widget with
// no <template> without a word, and api/layout.php strips ids the permission
// filter does not know. This rule is the thing that notices.
// ─────────────────────────────────────────────────────────────────────────
$wmPath = 'assets/js/widget-manager.js';
$idxPath = 'index.php';
if (isset($src[$wmPath]) && isset($src[$idxPath])) {
    $wm  = ui_js_strip_comments($src[$wmPath]);
    $idx = $src[$idxPath];

    $listOf = static function (string $hay, string $pattern, string $itemRe): array {
        if (!preg_match($pattern, $hay, $m)) { return []; }
        $out = [];
        if (preg_match_all($itemRe, $m[1], $mm)) { $out = $mm[1]; }
        return array_values(array_unique($out));
    };

    $registries = [
        'DEFAULT_LAYOUT (widget-manager.js)' => $listOf(
            $wm, '/DEFAULT_LAYOUT\s*=\s*\[(.*?)\];/s', "/\\bid:\\s*'([a-z0-9_]+)'/"),
        'WIDGET_ICONS (widget-manager.js)' => $listOf(
            $wm, '/WIDGET_ICONS\s*=\s*\{(.*?)\};/s', '/([a-z0-9_]+)\s*:/'),
        'WIDGET_LABELS_EN (widget-manager.js)' => $listOf(
            $wm, '/WIDGET_LABELS_EN\s*=\s*\{(.*?)\};/s', '/([a-z0-9_]+)\s*:/'),
        // Wrapped in array_values(array_filter([...], fn => dash_can(...))) —
        // match the literal list wherever it sits inside that expression.
        '$__allowedWidgets (index.php)' => $listOf(
            $idx, '/\$__allowedWidgets\s*=[^;]*?\[([^\]]*?)\]/s', "/'([a-z0-9_]+)'/"),
        'DASH_WIDGET_TITLES (index.php)' => $listOf(
            $idx, '/DASH_WIDGET_TITLES\s*=\s*\{(.*?)\}/s', '/[\'"]?([a-z0-9_]+)[\'"]?\s*:/'),
    ];
    // The two structural registries: a content template and a toolbar toggle.
    $tpl = [];
    if (preg_match_all('/<template\s+id="tpl-([a-z0-9_]+)"/i', $idx, $m)) { $tpl = $m[1]; }
    $registries['<template id="tpl-ID"> (index.php)'] = array_values(array_unique($tpl));
    $tog = [];
    if (preg_match_all('/class="[^"]*\bwidget-toggle\b[^"]*"[^>]*?data-widget="([a-z0-9_]+)"/i',
            ui_php_markup_document($idx), $m)) { $tog = $m[1]; }
    $registries['.widget-toggle button (index.php)'] = array_values(array_unique($tog));

    // Only compare when every registry parsed — a refactor that renames one of
    // them must not silently turn this rule into "every widget is missing".
    $parsed = array_filter($registries, static fn(array $v) => $v !== []);
    if (count($parsed) === count($registries)) {
        // DEFAULT_LAYOUT is the roster of GRID TILES, and it is the anchor: a
        // tile needs all six entries or the dashboard cannot render, translate,
        // permit or re-open it. An id that appears in the other registries but
        // NOT in DEFAULT_LAYOUT is a different animal — a floating panel given
        // a toolbar toggle so it has a re-open path (which is the right fix for
        // a panel that should not be a grid tile). That gets ONE finding
        // describing what it is, not six complaining it is not a tile.
        $tiles = $registries['DEFAULT_LAYOUT (widget-manager.js)'];
        $all = [];
        foreach ($registries as $ids) { foreach ($ids as $id) { $all[$id] = true; } }
        ksort($all);
        foreach (array_keys($all) as $id) {
            if (!in_array($id, $tiles, true)) {
                $where = [];
                foreach ($registries as $label => $ids) {
                    if (in_array($id, $ids, true)) { $where[] = $label; }
                }
                uia_add('widget-registry', $wmPath, 0,
                    "$id registered outside DEFAULT_LAYOUT: " . implode(', ', $where),
                    "'$id' appears in " . implode(' / ', $where) . ' but is not a grid '
                    . 'tile in DEFAULT_LAYOUT. That is legitimate for a floating panel '
                    . 'that only needs a re-open path from the widgets toolbar — '
                    . 'baseline it saying so. It is a bug for anything meant to be a '
                    . 'dashboard tile, which will silently never render');
                continue;
            }
            foreach ($registries as $label => $ids) {
                if (in_array($id, $ids, true)) { continue; }
                uia_add('widget-registry', $wmPath, 0, "$id missing from $label",
                    "widget '$id' is a grid tile in DEFAULT_LAYOUT but absent from "
                    . "$label — the dashboard cannot render, translate, permit or "
                    . 're-open a widget that only half the registries know about');
            }
        }
    } else {
        $missing = array_keys(array_diff_key($registries, $parsed));
        uia_add('widget-registry', $wmPath, 0,
            'unparsable registry: ' . implode(', ', $missing),
            'this rule could not find ' . implode(' / ', $missing)
            . ' — if they were renamed, update tools/ui_consistency_audit.php so '
            . 'the registries stay compared');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: widget-header  — a hand-rolled card-header inside a grid-stack item.
// The one legitimate emitter is widget-manager.js:272-282.
// ─────────────────────────────────────────────────────────────────────────
foreach (array_keys($panelFiles) as $p) {
    if ($p === $wmPath) { continue; }
    foreach ($chunks($p) as [$startLine, $text]) {
        // Proximity, not mere co-occurrence: a page may mention grid-stack in
        // one place and have an unrelated card elsewhere. What is flagged is a
        // header sitting INSIDE a grid tile.
        $tiles = [];
        if (preg_match_all('/grid-stack-item/', $text, $mt, PREG_OFFSET_CAPTURE)) {
            foreach ($mt[0] as $t) { $tiles[] = $t[1]; }
        }
        if (!$tiles) { continue; }
        if (!preg_match_all('/<div[^>]*class="[^"]*\bcard-header\b[^"]*"/i', $text, $m,
                PREG_OFFSET_CAPTURE)) { continue; }
        foreach ($m[0] as $hit) {
            $near = false;
            foreach ($tiles as $t) {
                if ($hit[1] > $t && $hit[1] - $t < 2000) { $near = true; break; }
            }
            if (!$near) { continue; }
            uia_add('widget-header', $p, ui_chunk_line($startLine, $text, $hit[1]), $hit[0],
                'a grid tile header built by hand. The shared emitter '
                . '(assets/js/widget-manager.js:272-282) is what gives every widget '
                . 'the same title, drag handle and refresh control');
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: widget-header-control  — a dismiss control in a panel header.
//
// A modal's btn-close is correct everywhere, so chunks that mention a modal are
// skipped. What is flagged is a header whose only control hides the panel: the
// user has no way back, because re-opening lives in the widgets toolbar.
// ─────────────────────────────────────────────────────────────────────────
$dismissRe = '/<(?:button|a|span|i)\b[^>]*(?:\bbtn-close\b|\bbi-x(?:-lg|-circle|-square)?\b'
    . '|\bbi-dash(?:-lg)?\b|\bdata-bs-dismiss=)[^>]*>/i';
foreach (array_keys($panelFiles) as $p) {
    foreach ($chunks($p) as [$startLine, $text]) {
        if (strpos($text, 'card-header') === false
            && strpos($text, '-header-actions') === false) { continue; }
        if (stripos($text, 'modal') !== false) { continue; }
        if (!preg_match_all($dismissRe, $text, $m, PREG_OFFSET_CAPTURE)) { continue; }
        foreach ($m[0] as $hit) {
            uia_add('widget-header-control', $p, ui_chunk_line($startLine, $text, $hit[1]),
                $hit[0],
                'a dismiss control in a panel header. Every dashboard widget\'s '
                . 'top-right control is the shared refresh affordance '
                . '(widget-manager.js:277); showing and hiding is the widgets '
                . 'toolbar\'s job, so a bare close leaves no way back');
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: hotkey-affordance  — a <kbd> outside the shared action-button shape,
// in a dashboard panel. Scope is deliberate: help.php's 33 <kbd> are a
// shortcut REFERENCE, and settings.php's two are prose. Neither is a control.
// ─────────────────────────────────────────────────────────────────────────
foreach (array_keys($panelFiles) as $p) {
    foreach ($chunks($p) as [$startLine, $text]) {
        if (stripos($text, '<kbd') === false) { continue; }
        // The shared shape: the keycap rides inside an action button that also
        // carries its own label. Present => this is the sanctioned component.
        if (preg_match('/\baction-btn\b/', $text) && preg_match('/\baction-label\b/', $text)) {
            continue;
        }
        if (!preg_match_all('/<kbd\b[^>]*>/i', $text, $m, PREG_OFFSET_CAPTURE)) { continue; }
        $line = ui_chunk_line($startLine, $text, $m[0][0][1]);
        // One finding per region, not per key — the drift is the treatment.
        uia_add('hotkey-affordance', $p, $line,
            count($m[0]) . ' <kbd> outside an action button',
            count($m[0]) . ' keycap(s) rendered outside the shared action-bar '
            . 'component. The convention is a labelled button per action in the '
            . 'panel HEADER — btn-xs + *-action-btn + <span class="action-label"> '
            . '+ <kbd> (assets/js/widget-manager.js:196-250, styled at '
            . 'assets/css/widgets.css:386-397)');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: action-bar-css  — the triplicated selector hazard.
//
// assets/css/widgets.css enumerates the action bars by name in three separate
// selector groups. Its own comment records this trap firing twice: the
// Responders bar shipped unstyled because the selector said .incident-action-bar
// only, and Phase 115's Facilities bar hit it again. A fourth bar will too.
// ─────────────────────────────────────────────────────────────────────────
// Every project stylesheet, not one named file: these rules moved from
// assets/css/widgets.css to assets/css/action-bar.css on 2026-08-01 (widgets.css
// is loaded by index.php but not situation.php, and the check-in panel renders
// on both). A rule that names its stylesheet breaks the next time that happens.
$allCss = '';
foreach ($cssFiles as $p) { $allCss .= "\n" . ($src[$p] ?? ''); }
if (trim($allCss) !== '') {
    $bars = [];
    foreach (array_merge($phpFiles, $jsFiles) as $p) {
        if (!preg_match_all('/\b([a-z][a-z0-9]*(?:-[a-z0-9]+)*?-action-bar)\b/', $src[$p] ?? '', $m)) {
            continue;
        }
        foreach ($m[1] as $bar) { $bars[$bar][] = $p; }
    }
    // A generic selector ([class$="-action-bar"] &c.) covers every bar at once
    // and makes the enumeration moot — accept it rather than demanding the list.
    $generic = (bool) preg_match('/\[class[\^$*~|]?=\s*[\'"][^\'"]*-action-bar/', $allCss);
    $groupNames = ['.btn-xs sizing', '.btn-xs kbd', '.action-label'];
    foreach ($bars as $bar => $where) {
        if ($generic) { continue; }
        $q = preg_quote($bar, '/');
        $missing = [];
        foreach ([
            '/\.' . $q . '\s+\.btn-xs\s*(?:,|\{)/',
            '/\.' . $q . '\s+\.btn-xs\s+kbd\s*(?:,|\{)/',
            '/\.' . $q . '\s+\.action-label\s*(?:,|\{)/',
        ] as $i => $re) {
            if (!preg_match($re, $allCss)) { $missing[] = $groupNames[$i]; }
        }
        if (!$missing) { continue; }
        uia_add('action-bar-css', $where[0], 0,
            "$bar missing from: " . implode(', ', $missing),
            "the .$bar action bar is absent from " . count($missing) . ' of the three '
            . 'shared selector groups (' . implode(', ', $missing) . '). Its buttons '
            . 'fall back to full Bootstrap .btn sizing and tower over the rest of the '
            . 'header — the regression assets/css/action-bar.css records happening '
            . 'three times. Add the bar to the selector lists there; never copy the '
            . 'rules into a page-local stylesheet');
    }
    // If NO bar resolves, the selector shape itself has been refactored away and
    // this rule has quietly stopped checking anything. Say so rather than pass.
    if ($bars && !$generic
        && !preg_match('/-action-bar\s+\.btn-xs\s+kbd\s*(?:,|\{)/', $allCss)) {
        uia_add('action-bar-css', 'assets/css', 0, 'no shared action-bar selector group found',
            'no stylesheet in this tree carries the shared `*-action-bar .btn-xs kbd` '
            . 'rule any more. Either the shared styling was deleted, or it was renamed '
            . 'and tools/ui_consistency_audit.php needs updating to follow it');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: theme-color
//
// Both themes must work — an explicit project convention ("support both light
// and dark themes — use Bootstrap CSS variables, not hardcoded colors"). A hex
// INSIDE a [data-bs-theme=...] block is the theme-specific half of a pair and
// is correct; a hex outside one is the same colour in both themes.
// print.css is exempt: print output has no theme, and Bootstrap's own theme
// variables are not applied to it.
// ─────────────────────────────────────────────────────────────────────────
const UIA_THEMED_PROP = 'color|background|background-color|border|border-color|border-top'
    . '|border-bottom|border-left|border-right|outline|outline-color|fill|stroke';

foreach ($cssFiles as $p) {
    if (basename($p) === 'print.css') { continue; }
    $lines = explode("\n", $src[$p] ?? '');
    $depth = 0;
    $themeDepth = -1;
    foreach ($lines as $i => $ln) {
        $isThemeSel = (bool) preg_match('/\[data-bs-theme/', $ln);
        if ($themeDepth < 0
            && preg_match('/(?:^|[;{\s])(' . UIA_THEMED_PROP . ')\s*:[^;{]*?(#[0-9a-fA-F]{3,8})\b/', $ln, $m)) {
            uia_add('theme-color', $p, $i + 1, $m[1] . ': ' . $m[2],
                "`{$m[1]}: {$m[2]}` is the same colour in light and dark. Use a "
                . 'Bootstrap variable (var(--bs-body-color), var(--bs-border-color), '
                . 'rgba(var(--bs-emphasis-color-rgb), …)) or pair it with a '
                . '[data-bs-theme="dark"] override');
        }
        $open = substr_count($ln, '{');
        $close = substr_count($ln, '}');
        if ($isThemeSel && $open > 0 && $themeDepth < 0) { $themeDepth = $depth; }
        $depth += $open - $close;
        if ($themeDepth >= 0 && $depth <= $themeDepth) { $themeDepth = -1; }
    }
}

// Inline style="" carrying a hex is theme-blind wherever it lands, and no
// stylesheet can override it without !important.
foreach (array_merge($phpFiles, $jsFiles) as $p) {
    foreach ($chunks($p) as [$startLine, $text]) {
        if (!preg_match_all(
            '/style\s*=\s*"([^"]*?(?:' . UIA_THEMED_PROP . ')\s*:\s*#[0-9a-fA-F]{3,8}[^"]*)"/i',
            $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) { continue; }
        foreach ($m as $hit) {
            uia_add('theme-color', $p, ui_chunk_line($startLine, $text, $hit[0][1]),
                'style="' . $hit[1][0] . '"',
                'an inline hex colour cannot follow the theme and cannot be '
                . 'overridden without !important — move it to a stylesheet using '
                . 'Bootstrap variables');
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: control-size  — the compact -sm variants.
// ─────────────────────────────────────────────────────────────────────────
foreach (array_merge($phpFiles, $jsFiles) as $p) {
    foreach ($chunks($p) as [$startLine, $text]) {
        if (!preg_match_all('/class\s*=\s*"([^"]*)"/i', $text, $m,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) { continue; }
        foreach ($m as $hit) {
            $cls = $hit[1][0];
            foreach (['form-control', 'form-select'] as $base) {
                if (!preg_match('/\b' . $base . '(?![-\w])/', $cls)) { continue; }
                if (strpos($cls, $base . '-sm') !== false) { continue; }
                // -plaintext is a static display, not an input; it has no size.
                if (strpos($cls, $base . '-plaintext') !== false) { continue; }
                uia_add('control-size', $p, ui_chunk_line($startLine, $text, $hit[0][1]),
                    "$base without $base-sm in class=\"$cls\"",
                    "`$base` without `$base-sm`. Dispatch forms are compact by "
                    . 'design — speed over beauty, every pixel earns its place');
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: icon-source  — Bootstrap Icons, and only Bootstrap Icons.
// 2305 `bi bi-*` in the tree and not one icon from any other family.
// ─────────────────────────────────────────────────────────────────────────
$foreignIcons = [
    '/\bclass\s*=\s*"[^"]*\b(?:fa|fas|far|fal|fab)\s+fa-[a-z0-9-]+/i' => 'Font Awesome',
    '/\bclass\s*=\s*"[^"]*\bglyphicon\b/i'                            => 'Glyphicons',
    '/\bclass\s*=\s*"[^"]*\bmaterial-icons\b/i'                       => 'Material Icons',
    '/\bclass\s*=\s*"[^"]*\bmdi\s+mdi-[a-z0-9-]+/i'                   => 'Material Design Icons',
    '/\bclass\s*=\s*"[^"]*\bion-[a-z0-9-]+/i'                         => 'Ionicons',
    '/\bclass\s*=\s*"[^"]*\boi\s+oi-[a-z0-9-]+/i'                     => 'Open Iconic',
];
foreach (array_merge($phpFiles, $jsFiles) as $p) {
    foreach ($chunks($p) as [$startLine, $text]) {
        foreach ($foreignIcons as $re => $family) {
            if (!preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE)) { continue; }
            foreach ($m[0] as $hit) {
                uia_add('icon-source', $p, ui_chunk_line($startLine, $text, $hit[1]), $hit[0],
                    "$family icon. This product uses Bootstrap Icons everywhere "
                    . '(`bi bi-*`); a second icon font is a second download, a second '
                    . 'set of metrics and a visibly different drawing style');
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: form-button-type
//
// GH #84: a <button> without an explicit type inside a <form> defaults to
// type=submit, so a JS-handler button submits the form and reloads the page.
// a beta tester reported the unit-edit OwnTracks button "immediately refreshes the
// page" — that was this. Only PHP markup can be scoped to a form statically;
// markup assembled in JS is injected somewhere this cannot see, so it is out
// of scope rather than guessed at.
// ─────────────────────────────────────────────────────────────────────────
foreach ($phpFiles as $p) {
    $doc = ui_php_markup_document($src[$p] ?? '');
    if (stripos($doc, '<form') === false) { continue; }
    // Form regions: <form ...> ... </form>
    $regions = [];
    if (preg_match_all('/<form\b/i', $doc, $mo, PREG_OFFSET_CAPTURE)) {
        foreach ($mo[0] as $open) {
            $end = stripos($doc, '</form', $open[1]);
            $regions[] = [$open[1], $end === false ? strlen($doc) : $end];
        }
    }
    if (!$regions) { continue; }
    if (!preg_match_all('/<button\b([^>]*)>/i', $doc, $mb, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        continue;
    }
    foreach ($mb as $btn) {
        $off = $btn[0][1];
        $inForm = false;
        foreach ($regions as [$s0, $e0]) { if ($off >= $s0 && $off < $e0) { $inForm = true; break; } }
        if (!$inForm) { continue; }
        if (preg_match('/\btype\s*=/i', $btn[1][0])) { continue; }
        uia_add('form-button-type', $p, ui_line_at($doc, $off),
            trim(preg_replace('/\s+/', ' ', $btn[0][0]) ?? ''),
            'a <button> inside a <form> with no type= defaults to type="submit". '
            . 'If this button runs JavaScript, the browser submits the form and '
            . 'reloads the page instead (GH #84). Add type="button"');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: state-store
//
// Per-user LAYOUT and COLUMN state has two homes: dashboard_layouts via
// api/layout.php, and user_screen_prefs via ScreenPrefs/api/screen-prefs.php.
// localStorage alongside a server write is a legitimate render-speed cache —
// that is exactly what assets/js/widget-manager.js:416-419 does. localStorage
// as the ONLY home means the dispatcher's columns do not follow them to the
// next machine, and it is how a "newly invented mechanism" starts.
//
// Deliberately narrow: device-local ephemera (theme, mute, GPS on/off, panel
// position) is NOT flagged. See the rejected-rules note at the bottom.
// ─────────────────────────────────────────────────────────────────────────
foreach (array_merge($phpFiles, $jsFiles) as $p) {
    $code = substr($p, -3) === '.js'
        ? ui_js_strip_comments($src[$p] ?? '')
        : ($src[$p] ?? '');
    if (!preg_match_all(
        '/localStorage\s*\.\s*setItem\s*\(\s*[\'"]([A-Za-z0-9_.\-]*(?:[Ll]ayout|[Cc]olumn|[Cc]olConfig|[Cc]ols)[A-Za-z0-9_.\-]*)[\'"]/',
        $code, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) { continue; }
    // Does this file ALSO write the value to the server?
    $serverBacked = preg_match('#api/layout\.php|api/screen-prefs\.php|ScreenPrefs\s*\.\s*save#', $code);
    foreach ($m as $hit) {
        if ($serverBacked) { continue; }
        uia_add('state-store', $p, ui_line_at($code, $hit[0][1]),
            "localStorage.setItem('" . $hit[1][0] . "')",
            "per-user layout/column state saved only in this browser. It belongs in "
            . 'user_screen_prefs (ScreenPrefs.save -> api/screen-prefs.php) or '
            . 'dashboard_layouts (api/layout.php), with localStorage kept as a cache '
            . 'alongside that write the way widget-manager.js:416-419 does');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// RULE: es5
//
// Seven test files already spot-check ONE JS file each for this
// (test_quadkey.php, test_channel_registry.php, test_console_views.php,
// test_event_zones.php, test_audit_dashboard_widget.php,
// test_road_conditions_overlay.php). This extends the same rule to the whole
// tree instead of adding an eighth per-file copy.
//
// Comments AND string literals are stripped first. That is not optional: a raw
// backtick scan reports ~100 "template literals" across assets/js and every
// single one is a backtick inside a comment (assets/js/config.js:15,
// assets/js/net-checkins.js:23, assets/js/zello-widget.js:387, …).
// ─────────────────────────────────────────────────────────────────────────
$es5 = [
    'arrow function'   => '/=>/',
    'let/const'        => '/(?:^|[^\w$.])(?:let|const)\s+[A-Za-z_$\[{]/',
    'template literal' => '/`/',
    'destructuring'    => '/(?:^|[^\w$.])(?:var|let|const)\s*[\[{][^;=]*\]\s*=|(?:^|[^\w$.])(?:var|let|const)\s*\{[^;=}]*\}\s*=/',
];
foreach ($jsFiles as $p) {
    $code = ui_js_code_only($src[$p] ?? '');
    foreach ($es5 as $what => $re) {
        if (!preg_match_all($re, $code, $m, PREG_OFFSET_CAPTURE)) { continue; }
        uia_add('es5', $p, ui_line_at($code, $m[0][0][1]),
            count($m[0]) . ' x ' . $what,
            count($m[0]) . " occurrence(s) of a $what. Browser JavaScript here is "
            . 'ES5 wrapped in an IIFE — no build step, no transpiler, and the '
            . 'browsers this dispatches on are whatever a volunteer agency has');
    }
}

// ── Report ────────────────────────────────────────────────────────────────
$baselineFile = __DIR__ . '/ui_consistency_baseline.txt';
$baseline = [];
if (is_file($baselineFile)) {
    foreach (file($baselineFile) as $l) {
        $l = trim($l);
        if ($l !== '' && $l[0] !== '#') { $baseline[$l] = true; }
    }
}

ksort($findings);
$newCount = 0;
$byRule = [];
foreach ($findings as $key => $sites) {
    $rule = substr($key, 0, (int) strpos($key, ':'));
    $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
    $inBaseline = isset($baseline[$key]);
    if ($inBaseline && !$showAll) { continue; }
    if (!$inBaseline) { $newCount++; }
    echo ($inBaseline ? '[baseline] ' : '[NEW]      ') . $key . "\n";
    foreach (array_slice($sites, 0, 3) as [$f, $l, $msg]) {
        echo "             $f" . ($l ? ":$l" : '') . " — $msg\n";
    }
    if (count($sites) > 3) { echo '             … +' . (count($sites) - 3) . " more site(s)\n"; }
}

ksort($byRule);
echo "\nFindings by rule:\n";
foreach ($byRule as $rule => $n) { printf("  %-24s %d\n", $rule, $n); }
echo "\n" . count($findings) . " distinct finding(s), $newCount new (not in baseline)\n";

if ($newCount > 0) {
    echo "\nThese are interface conventions the rest of the product already follows —\n"
       . "see `php tools/ui_consistency_audit.php --rules` for the rule behind each\n"
       . "finding, and newui-dev/newui/docs/UI-CONVENTIONS.md for the reasoning.\n"
       . "Fix the drift, or — if this really is a considered exception — add the\n"
       . "finding key to tools/ui_consistency_baseline.txt WITH a comment saying why.\n";
}
exit($newCount === 0 ? 0 : 1);

/*
 * ── RULES CONSIDERED AND REJECTED ────────────────────────────────────────
 *
 * A rule that most of the product violates is a bad rule: it produces a
 * baseline nobody reads and a gate nobody trusts. These were measured against
 * the tree and dropped.
 *
 *   "localStorage is never used for per-user state."  120 references across 21
 *   JS files — localStorage is the numeric majority, not the deviation, and
 *   most of it is right: theme, mute, GPS on/off and floating-panel position
 *   are properties of the DEVICE, not the person. Only layout/column state has
 *   a server home that it is bypassing, so only that is flagged.
 *
 *   "Every <button> must carry type=."  388 of 1196 buttons have no type. The
 *   documented bug (GH #84) is specifically a button inside a FORM, where the
 *   default is submit; outside a form the default is harmless. Scoped to forms.
 *
 *   "Never hardcode a hex colour."  A hex inside a [data-bs-theme="dark"] block
 *   is the theme-specific half of a correct pair (49 in the tree), and print.css
 *   has no theme at all. Both are excluded rather than baselined.
 *
 *   "Widget headers must be emitted by the shared helper" as a blanket rule.
 *   The Zello and chat panels are floating overlays with their own long-standing
 *   header vocabulary, not grid tiles; the rule is scoped to card-headers inside
 *   a grid-stack item, which is where the shared emitter actually applies.
 *
 *   "Every <kbd> must be an action button."  help.php has 33 in a keyboard
 *   shortcut REFERENCE table and settings.php has two in prose. Those are
 *   documentation, not controls. Scoped to dashboard panels.
 *
 *   Spacing/utility-class conventions (py-1 px-2 on headers, gap-1, me-2). Real,
 *   but too weakly held across the tree to gate on without a large baseline that
 *   would bury the findings that matter.
 */
