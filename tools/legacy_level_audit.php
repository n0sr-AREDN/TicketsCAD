<?php
/**
 * Legacy-level authorisation audit (2026-07-29).
 *
 * THE RULE: no file may make an authorisation decision from the legacy
 * `user.level` column. Not an endpoint, not a page, not a shared include,
 * not a line of JavaScript. Not even as a fallback.
 *
 * The disease, one boundary over from tools/schema_audit.php (SQL vs the
 * real schema) and tools/api_contract_audit.php (JS reads vs API emits):
 * two gates on the same feature answering to two different permission
 * systems. An install can hold role_id=2 (Org Admin) and user.level=4 at
 * the same time, so the gates disagree and the user gets a screen that
 * refuses to do anything.
 *
 * Found in production 2026-07-29: a your deployment Org Admin passed
 * reports.php's rbac_require_screen('screen.reports') and was then
 * refused by api/reports.php's `$_SESSION['level'] > 1`, on every single
 * report. The org-scope filter written specifically so an Org Admin
 * could run reports was unreachable code.
 *
 * How it works:
 *   1. Collect the "legacy level" expressions in each file:
 *      $_SESSION['level'], $current_level, and any local whose name
 *      reads as a level ($userLevel / $currLevel / $lvl / $level ...).
 *   2. Find the ones used in a COMPARISON (`> 1`, `<= 2`, in_array(...)).
 *      A level merely *stored* (dmr_ws_tokens.user_level) or passed to an
 *      RBAC-aware helper is not a gate and is not flagged.
 *   3. Flag it. There is no escape except the baseline.
 *
 * SCOPE (Phase 128, 2026-07-29 — widened):
 *   Was: `api/` enforced, pages and inc/ merely advisory, and a level
 *   comparison forgiven whenever `is_admin(` or `rbac_can(` appeared in
 *   the same statement.
 *
 *   Both of those were holes, and both were load-bearing in the bug this
 *   tool exists to prevent:
 *     * The page half of a page/API split lives in a PAGE. Leaving pages
 *       advisory meant the tool could only ever catch one side of the
 *       disagreement — and settings.php sat on a bare `level > 1` gate
 *       for another day because of it.
 *     * The `rbac_can(` escape hatch permitted `rbac_can(...) || $lvl <= 1`.
 *       That is still a level that can say yes. Eric's instruction was
 *       "no more levels", not "levels only as a second opinion", and the
 *       fallback is what made the one-time migration optional in the
 *       first place. See specs/phase-128-eliminate-legacy-levels/.
 *
 *   Now enforced across api/, page templates, inc/, tools/, proxy/ and
 *   assets/js/. The migration tooling is exempt by path — it is the
 *   bridge from v3 and is supposed to read the column.
 *
 * Exit code: 0 = clean/baseline-only, 1 = new findings.
 * Baseline:  tools/legacy_level_baseline.txt (one "file :: statement"
 *            per line; add a verified-legitimate finding WITH a comment).
 *
 * Usage:
 *   php tools/legacy_level_audit.php          # report + exit code
 *   php tools/legacy_level_audit.php --all    # include baselined finds
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
$showAll = in_array('--all', $argv ?? [], true);

// ── Baseline ─────────────────────────────────────────────────────────────
$baselineFile = 'tools/legacy_level_baseline.txt';
$baseline = [];
if (is_file($baselineFile)) {
    foreach (file($baselineFile, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $baseline[$line] = true;
    }
}

/** Collapse whitespace so a finding key survives reformatting. */
function lla_norm(string $s): string {
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Does this variable name read as "the legacy user level"?
 * Deliberately narrow — $severity_level / $zoom_level must not match.
 */
function lla_is_level_var(string $var): bool {
    return (bool) preg_match(
        '/^\$(user|curr|current|usr|my|the|acct|account|session|sess)?_?(level|lvl)$/i',
        $var
    );
}

/**
 * The statement containing offset $pos: back up to the previous
 * `;` `{` `}` or PHP open tag, forward to the next `;` or `{`.
 */
function lla_statement(string $src, int $pos): string {
    $start = 0;
    for ($i = $pos; $i >= 0; $i--) {
        if ($src[$i] === ';' || $src[$i] === '{' || $src[$i] === '}') { $start = $i + 1; break; }
    }
    $len = strlen($src);
    $end = $len;
    for ($i = $pos; $i < $len; $i++) {
        if ($src[$i] === ';' || $src[$i] === '{') { $end = $i; break; }
    }
    return substr($src, $start, $end - $start);
}

/**
 * Blank out comments and inline HTML, replacing every character with a
 * space (newlines preserved) so byte offsets and line numbers still map
 * to the original file. Without this a `// was: level > 1` note in a
 * comment reads as a live gate, and a page's HTML body swallows the
 * statement boundaries whole.
 */
function lla_code_only(string $src): string {
    $blank = ['' => true];
    try {
        $tokens = @token_get_all($src);
    } catch (Throwable $e) {
        return $src;
    }
    $out = '';
    foreach ($tokens as $t) {
        $text = is_array($t) ? $t[1] : $t;
        $drop = is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true);
        if (!$drop) { $out .= $text; continue; }
        $out .= preg_replace('/[^\n]/', ' ', $text);
    }
    unset($blank);
    return $out;
}

/** Scan one PHP file, return findings as [line, statement]. */
function lla_scan(string $path): array {
    $src = file_get_contents($path);
    if ($src === false) return [];
    $src = lla_code_only($src);

    // Locals assigned from $_SESSION['level'] count as level vars even if
    // their name is unusual.
    $vars = ['\$_SESSION\[[\'"]level[\'"]\]', '\$current_level'];
    if (preg_match_all('/(\$[A-Za-z_]\w*)\s*=\s*[^;]*\$_SESSION\[[\'"]level[\'"]\]/', $src, $m)) {
        foreach ($m[1] as $v) $vars[] = preg_quote($v, '/');
    }
    // Names that read as a level (covers function PARAMETERS, which no
    // assignment scan can see — te_can_modify($entry, $uid, $currentLevel)).
    if (preg_match_all('/(\$[A-Za-z_]\w*)/', $src, $m)) {
        foreach (array_unique($m[1]) as $v) {
            if (lla_is_level_var($v)) $vars[] = preg_quote($v, '/');
        }
    }
    $vars = array_values(array_unique($vars));
    if (!$vars) return [];
    $alt = implode('|', $vars);

    // A comparison against a number, either order, plus in_array() membership.
    $patterns = [
        '/(?:' . $alt . ')\s*(?:<=|>=|<|>|===|==|!==|!=)\s*-?\d+/',
        '/-?\d+\s*(?:<=|>=|<|>|===|==|!==|!=)\s*(?:' . $alt . ')/',
        '/in_array\s*\(\s*\(?\s*(?:int\)\s*)?(?:' . $alt . ')/',
    ];

    $findings = [];
    $seen = [];
    foreach ($patterns as $re) {
        if (!preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as $hit) {
            $pos  = $hit[1];
            $stmt = lla_norm(lla_statement($src, $pos));
            if ($stmt === '' || isset($seen[$stmt])) continue;
            $seen[$stmt] = true;
            // Phase 128: the `is_admin( / rbac_can( in the same statement`
            // escape hatch is DELETED. `rbac_can(...) || $lvl <= 1` is
            // still a legacy level that can grant access, and permitting
            // it is what let the level concept survive three phases of
            // being declared dead.
            $line = substr_count(substr($src, 0, $pos), "\n") + 1;
            $findings[] = [$line, $stmt];
        }
    }
    return $findings;
}

/** Every file with one of $exts under a directory. */
function lla_files(string $dir, array $exts = ['php']): array {
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
        if (in_array($ext, $exts, true)) {
            $out[] = str_replace('\\', '/', $f->getPathname());
        }
    }
    sort($out);
    return $out;
}

/** Back-compat alias — tests and older callers use this name. */
function lla_php_files(string $dir): array { return lla_files($dir, ['php']); }

/**
 * Paths allowed to read user.level: the one-time v3 -> v4 bridge, the
 * audit itself, and the test that drives it. Everything else is subject
 * to the rule.
 *
 * Keep this list SHORT and specific. "It's only a tool" is not a reason —
 * tools/legacy_level_audit.php's whole value is that the exemption list
 * is small enough to read.
 */
function lla_is_migration_path(string $path): bool {
    static $exempt = [
        'sql/run_rbac_v2.php',               // A9: the level -> role migration
        'sql/run_phase11d_mobile_first.php', // derives mobile_first from legacy_level
        'tools/migrate_rbac.php',            // standalone level -> role migrator
        'tools/upgrade/',                    // the v3 -> v4 upgrade tooling
        'api/legacy-import.php',             // imports a legacy `user` table
        'tools/legacy_level_audit.php',      // this file
        'tools/phase12_sweep_apis.php',      // one-shot Phase 12 sweep scripts
        'tools/phase12_sweep_pages.php',
    ];
    foreach ($exempt as $e) {
        if (strpos($path, $e) !== false) return true;
    }
    return false;
}

/**
 * JS client-side level gates. The browser cannot authorise anything, but
 * a `level <= 1` in JS means a page is deciding what to show from the
 * legacy column instead of from a server-computed RBAC answer — which is
 * how a UI ends up offering buttons the API then refuses (and vice
 * versa). Narrow on purpose: a name containing "level"/"lvl" compared to
 * a number.
 */
function lla_scan_js(string $path): array {
    $src = file_get_contents($path);
    if ($src === false) return [];
    // Strip // and /* */ comments so a note about the old code isn't a hit.
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    $src = preg_replace('#^\s*//.*$#m', '', $src);
    $re = '/\b(?:var\s+)?([A-Za-z_$][\w$]*(?:[Ll]evel|[Ll]vl))\s*(?:<=|>=|<|>|===|==|!==|!=)\s*-?\d+/';
    if (!preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) return [];
    $out = [];
    $seen = [];
    foreach ($m[0] as $i => $hit) {
        $name = $m[1][$i][0];
        // Only names that read as the USER's level. zoomLevel, severityLevel,
        // logLevel and friends are not access control.
        if (!preg_match('/^\$?(user|curr|current|usr|my|acct|account|session|sess)?_?([Ll]evel|[Ll]vl)$/', $name)) {
            continue;
        }
        $stmt = lla_norm($hit[0]);
        if (isset($seen[$stmt])) continue;
        $seen[$stmt] = true;
        $out[] = [substr_count(substr($src, 0, $hit[1]), "\n") + 1, $stmt];
    }
    return $out;
}

echo "=== Legacy-level authorisation audit ===\n";
echo "Rule: NO file may decide authorisation from the legacy user.level —\n";
echo "      not an endpoint, not a page, not an include, not JS, not as a\n";
echo "      fallback. RBAC is the only permission system.\n\n";

// ── The gate: everything except the migration bridge ─────────────────────
$targets = array_merge(
    lla_files('api'),
    glob('*.php') ?: [],          // page templates at the webroot
    lla_files('inc'),
    lla_files('tools'),
    lla_files('proxy'),
    lla_files('sql'),
    lla_files('assets/js', ['js'])
);
sort($targets);

$new = 0; $known = 0;
foreach ($targets as $path) {
    if (lla_is_migration_path($path)) continue;
    $isJs = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'js';
    $findings = $isJs ? lla_scan_js($path) : lla_scan($path);
    foreach ($findings as [$line, $stmt]) {
        $key = $path . ' :: ' . $stmt;
        if (isset($baseline[$key])) {
            $known++;
            if ($showAll) echo "  [baseline] {$path}:{$line}\n              {$stmt}\n";
            continue;
        }
        $new++;
        echo "  [NEW] {$path}:{$line}\n        {$stmt}\n";
        if ($isJs) {
            echo "        -> have the server emit a computed RBAC boolean and read\n";
            echo "           that; the browser must not re-derive authorisation.\n";
        } else {
            echo "        -> gate on is_admin() / rbac_can('...'). A level is not\n";
            echo "           acceptable even as an OR-fallback: login refuses on an\n";
            echo "           unmigrated install, so a fallback can only grant from\n";
            echo "           stale data. See specs/phase-128-eliminate-legacy-levels/.\n";
        }
    }
}
if ($new === 0) echo "  (none)\n";
echo "\nfindings: {$new} new, {$known} baselined\n";

exit($new > 0 ? 1 : 0);
