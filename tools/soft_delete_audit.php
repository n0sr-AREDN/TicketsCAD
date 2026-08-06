<?php
/**
 * Soft-delete audit — find `ticket` read sites that don't exclude
 * soft-deleted incidents (GH public issue #25, follow-up sweep).
 *
 * ── BACKGROUND ───────────────────────────────────────────────────────────
 *
 * api/external/v1/incidents.php and api/incidents.php (the dispatch board)
 * both served soft-deleted incidents in full — worst case, an incident
 * deleted while OPEN stayed on the live board forever, because
 * incident_soft_delete_internal() sets `deleted_at` and leaves `status`
 * alone. Fixed in commit 1502157. Eric's own closing comment on the issue:
 *
 *   "a grep for `FROM .*ticket` without a `deleted_at` term would probably
 *   find others. It does: roughly fifty read sites... This release fixes
 *   the two endpoints named in this issue and does not touch those; a
 *   fifty-site sweep is not a patch-release change... It is being tracked
 *   separately rather than folded in silently."
 *
 * This tool is that tracking. It finds every SELECT that reads `ticket`
 * without excluding soft-deleted rows, so the NEXT read site added six
 * months from now can't silently reintroduce the leak.
 *
 * ── WHY THIS ISN'T A PLAIN GREP ──────────────────────────────────────────
 *
 * Half the codebase's WHERE clauses aren't in the SELECT string at all —
 * they're assembled into a `$where` (or `$whereSql`, `$sFilter`, ...)
 * variable across several statements and interpolated in later:
 *
 *     $where = ["(t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')"];
 *     ...
 *     $rows = db_fetch_all("SELECT ... FROM ticket t {$whereSql} ...", $params);
 *
 * A tool that only looks at the literal string containing "FROM ticket"
 * would flag that as missing the filter — exactly the shape
 * tests/test_gh25_soft_deleted_incidents.php's own docblock warns about
 * ("the board statement is assembled by interpolation... a test carrying
 * its own copy would assert against a statement that exists nowhere").
 *
 * So this tool resolves simple string-builder variables: `$var = EXPR`,
 * `$var .= EXPR`, `$var[] = EXPR`, tracked per PHP variable SCOPE (reset at
 * each `function` body — real PHP scoping — top-level script code is its
 * own scope). When a fragment splices in a variable, the variable's
 * accumulated text (as of that point in the file, in file order) is
 * unioned with the fragment's own literal text before checking for
 * `deleted_at`.
 *
 * ── WHAT IT CANNOT SEE, AND SAYS SO ──────────────────────────────────────
 *
 *   - Branch-specific correctness. If `$where` gets the term in only ONE
 *     arm of an if/elseif chain, this tool still credits every use of
 *     $where in that scope, because it doesn't model which branch executes
 *     — it unions everything assigned to a variable in file order within
 *     the enclosing scope. The established house pattern (api/incidents.php)
 *     appends the term UNCONDITIONALLY after the whole branch chain
 *     specifically so this is safe; a new branch that sets $where and is
 *     never reached by that append would NOT be caught by this tool. Code
 *     review still matters here.
 *   - A variable built from anything other than string literals / heredocs
 *     / db_table() / another tracked variable (a ternary, a function call,
 *     a method call) is marked UNRESOLVABLE and contributes nothing — the
 *     query is reported as a finding rather than silently trusted. Err
 *     toward flagging, not toward silence.
 *   - Whether the exclusion is actually CORRECT (right column, right
 *     table alias) — only whether the substring `deleted_at` appears
 *     somewhere reachable. A human still reads every new finding.
 *   - Bare column references without a `ticket`/`t.` qualifier in a
 *     multi-table query. Conservative like tools/schema_audit.php.
 *
 * Deliberately conservative, like tools/schema_audit.php and
 * tools/api_contract_audit.php before it: false positives cost a human
 * a exceptions-file line; false negatives cost nothing until they leak.
 *
 * Exit code: 0 = clean (or only exceptions-listed findings), 1 = new
 * findings. Exceptions live in tools/soft_delete_audit_exceptions.txt —
 * "path:line | reason" — see that file's own header for the format rules.
 *
 * Usage:
 *   php tools/soft_delete_audit.php            # report + exit code
 *   php tools/soft_delete_audit.php --all      # include exceptions-listed findings too
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';

$showAll = in_array('--all', $argv ?? [], true);

// ── 1. Collect files — same surface as tools/schema_audit.php: api/, inc/,
//      and the page roots. Deliberately excludes tools/ and tests/ (their
//      own SQL is test scaffolding, not a production read path) and
//      sql/ (migrations, not runtime reads).
$dirs = ['api', 'inc'];
$files = [];
foreach ($dirs as $d) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d)) as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
            $files[] = str_replace('\\', '/', $f->getPathname());
        }
    }
}
foreach (glob('*.php') as $f) { $files[] = $f; }
sort($files);
echo count($files) . " PHP files scanned (api/, inc/, page roots)\n";

/**
 * Scan one file for SQL-shaped fragments referencing `ticket`, resolving
 * simple string-builder variables (scoped per function / top-level, in
 * file order) so a fragment built as `"...{$where}..."` is checked against
 * $where's accumulated text, not just its own literal characters.
 *
 * ONE pass over the token stream drives THREE things in parallel, because
 * an earlier version that tried to "resolve and skip" an assignment's RHS
 * as a separate sub-walk silently swallowed the tokens of any SQL literal
 * that happened to live inside that RHS (e.g. `$incident = db_fetch_one(
 * "SELECT ... FROM ticket ...")` — the query never got a chance to be
 * recognized because the assignment resolver consumed straight through it
 * to the terminating `;`). Every token is visited exactly once:
 *
 *   1. SQL-fragment accumulation ($buf / $splicedVars / flush()) — stitches
 *      literal/heredoc/db_table() pieces into one string per statement,
 *      exactly like tools/sql_extract.php, and records which variable
 *      NAMES were spliced in at each interpolation break.
 *   2. Scope tracking (function-body braces push/pop a variable map — real
 *      PHP scoping; top-level script code is scope 0).
 *   3. Assignment tracking — when a token sequence opens `$var = `, `$var
 *      .= `, or `$var[] = `, a SEPARATE accumulator mirrors the same
 *      literal/variable/db_table() content into that variable's resolved
 *      value for the rest of the statement, without diverting or skipping
 *      any token `$buf` also needed to see.
 *
 * Returns findings: list of [line, snippet].
 */
function sda_scan_file(string $path): array {
    $src = @file_get_contents($path);
    if ($src === false) { return []; }
    $tokens = @token_get_all($src);
    if (!$tokens) { return []; }
    $n = count($tokens);

    $scopeStack = [[]];
    $braceDepthStack = [0];
    $braceDepth = 0;

    $findings = [];

    // SQL-fragment accumulation state.
    $buf = null; $bufLine = 0; $splicedVars = [];
    // Assignment-tracking state (at most one active at a time — this
    // codebase never nests `$a = ($b = "x")`).
    $assignVar = null; $assignAppend = false; $assignBuf = '';
    $assignOk = true; $assignDepth = 0;
    $inDq = false; $inHd = false;
    $curLine = 1;

    $flush = function () use (&$buf, &$bufLine, &$splicedVars, &$findings, &$scopeStack) {
        if ($buf === null) { return; }
        $sqlText = $buf;
        $hasFilterFromVars = false;
        foreach ($splicedVars as $vn) {
            $val = $scopeStack[0][$vn] ?? null;
            if (is_string($val) && stripos($val, 'deleted_at') !== false) {
                $hasFilterFromVars = true;
            }
        }
        if (trim($sqlText) !== '' && sda_is_query($sqlText)) {
            $norm = sda_normalize($sqlText);
            if (preg_match('/\bSELECT\b/i', $norm)
                && preg_match('/\b(FROM|JOIN)\s+`?\{?\$?[A-Za-z_]*\}?ticket`?\b/i', $norm)) {
                $hasFilter = $hasFilterFromVars || stripos($norm, 'deleted_at') !== false;
                if (!$hasFilter) {
                    $snippet = substr(preg_replace('/\s+/', ' ', $norm), 0, 200);
                    $findings[] = [$bufLine, $snippet];
                }
            }
        }
        $buf = null; $splicedVars = [];
    };
    $begin = function (int $line) use (&$buf, &$bufLine) {
        if ($buf === null) { $buf = ''; $bufLine = $line; }
    };
    $finishAssign = function () use (&$assignVar, &$assignAppend, &$assignBuf, &$assignOk, &$scopeStack) {
        if ($assignVar === null) { return; }
        $val = $assignOk ? $assignBuf : false;
        if ($assignAppend) {
            $prior = $scopeStack[0][$assignVar] ?? '';
            $scopeStack[0][$assignVar] = ($prior === false || $val === false) ? false : ($prior . $val);
        } else {
            $scopeStack[0][$assignVar] = $val;
        }
        $assignVar = null; $assignAppend = false; $assignBuf = ''; $assignOk = true; $assignDepth = 0;
    };
    /** Feed one piece of resolved literal text to whichever accumulators are live. */
    $feedLiteral = function (string $text) use (&$buf, &$assignVar, &$assignBuf) {
        if ($buf !== null) { $buf .= $text; }
        if ($assignVar !== null) { $assignBuf .= $text; }
    };

    for ($i = 0; $i < $n; $i++) {
        $tk = $tokens[$i];

        if (is_array($tk)) {
            [$id, $text, $ln] = $tk;
            $curLine = $ln;

            switch ($id) {
                case T_WHITESPACE:
                case T_COMMENT:
                case T_DOC_COMMENT:
                    continue 2;

                case T_FUNCTION:
                    $flush();
                    $finishAssign();
                    $j = $i + 1;
                    for (; $j < $n; $j++) { if ($tokens[$j] === '{') { break; } }
                    if ($j < $n && $tokens[$j] === '{') {
                        $braceDepth++;
                        array_unshift($scopeStack, []);
                        array_unshift($braceDepthStack, $braceDepth);
                        $i = $j;
                    }
                    continue 2;

                case T_CONSTANT_ENCAPSED_STRING:
                    $begin($ln);
                    $feedLiteral(stripcslashes(substr($text, 1, -1)));
                    continue 2;

                case T_ENCAPSED_AND_WHITESPACE:
                    $begin($ln);
                    $feedLiteral(($inDq || $inHd) ? $text : stripcslashes($text));
                    continue 2;

                case T_START_HEREDOC:
                    $inHd = true; $begin($ln);
                    continue 2;

                case T_END_HEREDOC:
                    $inHd = false;
                    continue 2;

                case T_STRING:
                    if (strtolower($text) === 'db_table') {
                        // db_table('x') -> x, fed to whichever accumulators
                        // are live (same treatment as a literal).
                        $j = $i + 1;
                        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
                        if ($j < $n && $tokens[$j] === '(') {
                            $k = $j + 1;
                            while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { $k++; }
                            if ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                                $m = $k + 1;
                                while ($m < $n && is_array($tokens[$m]) && $tokens[$m][0] === T_WHITESPACE) { $m++; }
                                if ($m < $n && $tokens[$m] === ')') {
                                    $begin($ln);
                                    $feedLiteral(substr($tokens[$k][1], 1, -1));
                                    $i = $m;
                                    continue 2;
                                }
                            }
                        }
                    }
                    if (strtolower($text) === 'implode') {
                        // implode(GLUE, $arrayVar) -> $arrayVar's accumulated
                        // text (the glue expression is ignored — we only
                        // care whether `deleted_at` appears SOMEWHERE in the
                        // joined result, not the exact separator). This is
                        // the codebase's dominant `$where_parts[] = "...";
                        // ... implode(' AND ', $where_parts)` shape
                        // (api/reports.php's report cases); without this,
                        // every one of those would be reported as
                        // unresolvable even when correctly filtered.
                        //
                        // Only the shape `implode(<anything>, $var)` is
                        // recognized — the glue may be any token run (a
                        // literal, a function call, whatever) since it's
                        // discarded; only the trailing `, $var)` matters.
                        $j = $i + 1;
                        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
                        if ($j < $n && $tokens[$j] === '(') {
                            $depth = 1; $k = $j + 1; $lastVar = null; $sawComma = false;
                            while ($k < $n && $depth > 0) {
                                $t2 = $tokens[$k];
                                if ($t2 === '(') { $depth++; }
                                elseif ($t2 === ')') { $depth--; if ($depth === 0) { break; } }
                                elseif ($t2 === ',' && $depth === 1) { $sawComma = true; $lastVar = null; }
                                elseif ($sawComma && $depth === 1 && is_array($t2) && $t2[0] === T_VARIABLE) {
                                    $lastVar = $t2[1];
                                }
                                $k++;
                            }
                            if ($k < $n && $tokens[$k] === ')' && $lastVar !== null) {
                                $val = $scopeStack[0][$lastVar] ?? null;
                                if (is_string($val)) {
                                    $begin($ln);
                                    $feedLiteral($val);
                                    $i = $k;
                                    continue 2;
                                }
                            }
                        }
                    }
                    // Any other bare identifier (function/constant name):
                    // ends SQL accumulation (not part of a query) and
                    // taints any active assignment (not a plain string).
                    if (!$inDq && !$inHd) { $flush(); }
                    if ($assignVar !== null) { $assignOk = false; }
                    continue 2;

                case T_VARIABLE:
                    if ($inDq || $inHd) {
                        // Interpolation innards inside a string: the name
                        // is dropped from the literal text (matches
                        // sql_extract.php's normalizer), but if a query
                        // buffer OR an assignment is live, record the
                        // splice so its resolved value can be consulted.
                        if ($buf !== null) { $splicedVars[] = $text; }
                        if ($assignVar !== null) {
                            $val = $scopeStack[0][$text] ?? null;
                            if (is_string($val)) { $assignBuf .= $val; } else { $assignOk = false; }
                        }
                        continue 2;
                    }

                    // Outside a string: is this the START of an assignment?
                    $j = $i + 1;
                    while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; }
                    $isArrayTarget = false;
                    if ($j < $n && $tokens[$j] === '[') {
                        $k = $j + 1; $depth = 1;
                        while ($k < $n && $depth > 0) {
                            if ($tokens[$k] === '[') { $depth++; } elseif ($tokens[$k] === ']') { $depth--; }
                            $k++;
                        }
                        $m = $k;
                        while ($m < $n && is_array($tokens[$m]) && in_array($tokens[$m][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $m++; }
                        if ($m < $n && $tokens[$m] === '=' && !(isset($tokens[$m + 1]) && $tokens[$m + 1] === '=')) {
                            $isArrayTarget = true; $j = $m;
                        }
                    }
                    $isPlainAssign = !$isArrayTarget && $j < $n && $tokens[$j] === '='
                        && !(isset($tokens[$j + 1]) && $tokens[$j + 1] === '=');
                    $isAppendAssign = !$isArrayTarget && $j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_CONCAT_EQUAL;

                    if ($isPlainAssign || $isAppendAssign || $isArrayTarget) {
                        // A new assignment statement starts. It's never
                        // part of a query itself, and any PRIOR assignment
                        // must already be finished (statements don't nest
                        // at this depth in this codebase's style).
                        $flush();
                        $finishAssign();
                        $assignVar = $text;
                        $assignAppend = $isAppendAssign; // array-target `[]=` behaves like append
                        if ($isArrayTarget) { $assignAppend = true; }
                        $assignBuf = ''; $assignOk = true; $assignDepth = 0;
                        $i = $j; // resume scanning right after the operator; nothing consumed
                        continue 2;
                    }

                    // A plain variable read spliced into a string-building
                    // expression outside any string: this codebase's style
                    // is always literal `.` concatenation, e.g. `$sql .=
                    // $where;` — record the splice for $buf, and feed the
                    // resolved value into any active assignment.
                    if ($buf !== null) { $begin($ln); $splicedVars[] = $text; }
                    if ($assignVar !== null) {
                        $val = $scopeStack[0][$text] ?? null;
                        if (is_string($val)) { $assignBuf .= $val; } else { $assignOk = false; }
                    }
                    continue 2;

                default:
                    if (!$inDq && !$inHd) { $flush(); }
                    continue 2;
            }
        }

        // Single-character tokens
        if ($tk === '"') {
            $inDq = !$inDq;
            if ($inDq) { $begin($curLine); }
            continue;
        }
        // A bare `{` / `}` is a REAL code-block brace only outside a
        // double-quoted string / heredoc. Inside one, `{$var}` complex
        // interpolation opens with a distinct T_CURLY_OPEN token (handled
        // in the is_array() branch above) but PHP's tokenizer still emits
        // its CLOSING `}` as this same bare single-char token — so without
        // this guard, `` `{$prefix}ticket` `` inside a SQL string flushed
        // the buffer mid-word at the `}`, silently truncating every query
        // built with prefix interpolation and losing the `ticket` keyword
        // that makes the query recognizable at all.
        if (!$inDq && !$inHd) {
            if ($tk === '{') { $braceDepth++; continue; }
            if ($tk === '}') {
                if (!empty($braceDepthStack) && $braceDepthStack[0] === $braceDepth && count($scopeStack) > 1) {
                    array_shift($scopeStack);
                    array_shift($braceDepthStack);
                }
                $braceDepth--;
                $flush();
                $finishAssign();
                continue;
            }
            // `[`/`(` are depth-tracked ONLY so a `;` can't end an
            // assignment from inside a nested construct (defensive; real
            // PHP can't put a bare statement-`;` inside `[...]`/`(...)`
            // anyway). They do NOT taint by themselves — an array LITERAL
            // (`$where = ["(t.deleted_at ...)"];`) is exactly the shape
            // this codebase uses for building filter fragments, and its
            // bracket is not a function call. A real function call
            // (`implode(...)`, `array_merge(...)`) already tainted the
            // assignment when its NAME token was seen (the T_STRING case
            // above flushes + taints on any identifier that isn't
            // db_table), so by the time its `(` arrives $assignOk is
            // already false.
            if ($tk === '(' || $tk === '[') {
                if ($assignVar !== null) { $assignDepth++; }
                continue;
            }
            if ($tk === ')' || $tk === ']') {
                if ($assignVar !== null) { $assignDepth--; }
                continue;
            }
            if ($tk === ';' && $assignVar !== null && $assignDepth <= 0) {
                $finishAssign();
                continue;
            }
        }
        if ($tk === '.') { continue; } // concatenation glue
        if ($tk === ',' && $assignVar !== null) { continue; } // array-literal element separator
        if ($inDq || $inHd) { continue; }
        $flush();
        if ($assignVar !== null) { $assignOk = false; } // stray operator etc.
    }
    $flush();
    $finishAssign();

    return $findings;
}

function sda_normalize(string $sql): string {
    return (string) preg_replace('/\{\$[A-Za-z_]+\}|\$[A-Za-z_]+(?=\w*`)/', '', $sql);
}

function sda_is_query(string $sql): bool {
    if (stripos($sql, 'information_schema') !== false) { return false; }
    return (bool) preg_match('/\bSELECT\s+[\s\S]+\s+FROM\s/i', $sql);
}

// ── 3. Scan every file ──
$allFindings = []; // "path:line" => snippet
foreach ($files as $file) {
    foreach (sda_scan_file($file) as [$line, $snippet]) {
        $allFindings["$file:$line"] = $snippet;
    }
}
ksort($allFindings);
echo count($allFindings) . " candidate site(s) found (SELECT ... FROM/JOIN ticket without a resolvable deleted_at term)\n";

// ── 4. Load exceptions ──
$excPath = __DIR__ . '/soft_delete_audit_exceptions.txt';
$exceptions = [];      // "path:line" => reason
$excMalformed = [];
if (is_file($excPath)) {
    foreach (preg_split('/\r?\n/', (string) file_get_contents($excPath)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        $parts = explode('|', $line, 2);
        if (count($parts) < 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            $excMalformed[] = $line;
            continue;
        }
        $exceptions[trim($parts[0])] = trim($parts[1]);
    }
}
echo count($exceptions) . " entries in tools/soft_delete_audit_exceptions.txt\n";
if ($excMalformed) {
    echo "MALFORMED exception line(s) (need 'path:line | reason'):\n";
    foreach ($excMalformed as $m) { echo "  $m\n"; }
}

// ── 5. Report ──
$newFindings = [];
$excUsed = 0;
foreach ($allFindings as $key => $snippet) {
    if (isset($exceptions[$key])) {
        $excUsed++;
        if ($showAll) { echo "[exception] $key — {$exceptions[$key]}\n             $snippet\n"; }
        continue;
    }
    $newFindings[$key] = $snippet;
}

if ($newFindings) {
    echo "\nNEW findings (no exceptions-file entry) — read sites that appear to serve\n"
       . "`ticket` rows without excluding soft-deleted incidents:\n";
    foreach ($newFindings as $key => $snippet) {
        echo "  [NEW] $key\n         $snippet\n";
    }
}

$staleExceptions = count($exceptions) - $excUsed;
echo "\n" . count($allFindings) . " candidate site(s), " . count($newFindings) . " new, "
   . "$excUsed exception(s) matched, $staleExceptions stale exception(s)"
   . ($excMalformed ? ', ' . count($excMalformed) . ' malformed' : '') . "\n";

$exitCode = 0;
if ($newFindings) { $exitCode = 1; }
if ($excMalformed) { $exitCode = 1; }
// A stale exception (no longer matching anything) is also a failure — same
// discipline as tests/test_doc_navigation_labels.php: "a line cannot
// outlive the text it excuses," or the exceptions file silently stops
// meaning anything and nobody notices when a real fix removes the need
// for it, or worse, when the line drifts to cover something else.
if ($staleExceptions > 0) {
    echo "STALE exception(s) — no longer match any finding (fixed, or the code moved):\n";
    foreach ($exceptions as $key => $reason) {
        if (!isset($allFindings[$key])) {
            echo "  $key | $reason\n";
        }
    }
    $exitCode = 1;
}

exit($exitCode);
