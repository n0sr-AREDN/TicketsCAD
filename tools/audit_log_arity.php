<?php
/**
 * Gate: every audit_log() call must match the function's signature.
 *
 *   php tools/audit_log_arity.php
 *
 *   audit_log(string $category, string $activity, ?string $targetType = null,
 *             $targetId = null, string $summary = '', ?array $details = null, ...)
 *
 * The 5th argument is a STRING summary and the 6th is the details ARRAY. Passing
 * the details array in the 5th slot raises a TypeError — and TypeError extends
 * Error, not Exception, so the `catch (Exception $e)` that wraps almost every API
 * handler does NOT catch it. With display_errors off (which every endpoint sets,
 * deliberately, so warnings cannot corrupt JSON) the request dies silently and
 * returns an EMPTY BODY. The browser then reports:
 *
 *     Failed to execute 'json' on 'Response': Unexpected end of JSON input
 *
 * Worse, the writes before the audit_log call have already committed, so the
 * action APPEARS to half-work: the user sees an error and the change happened.
 * That is exactly what a beta tester hit on the mesh-bridge delete (2026-07-28):
 * the bridge deleted, the red error appeared, and he could not tell whether
 * something was left behind.
 *
 * A wrong argument here is invisible to `php -l`, invisible to a smoke test that
 * only checks the row is gone, and invisible until a real user clicks the button.
 * Hence a static gate.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');

$roots = ['api', 'inc', 'tools', 'sql'];
$bad = 0; $checked = 0;

/** Split a call's argument list on top-level commas only. */
function split_args(string $s): array {
    $out = []; $buf = ''; $d = 0; $q = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($q !== '') {
            $buf .= $c;
            if ($c === $q && ($i === 0 || $s[$i - 1] !== '\\')) { $q = ''; }
            continue;
        }
        if ($c === "'" || $c === '"') { $q = $c; $buf .= $c; continue; }
        if (strpos('([{', $c) !== false) { $d++; }
        if (strpos(')]}', $c) !== false) { $d--; }
        if ($c === ',' && $d === 0) { $out[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') { $out[] = trim($buf); }
    return $out;
}

foreach ($roots as $root) {
    if (!is_dir($root)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') { continue; }
        $path = str_replace('\\', '/', $f->getPathname());
        if (strpos($path, '/vendor/') !== false) { continue; }
        $src = file_get_contents($path);
        if (strpos($src, 'audit_log(') === false) { continue; }

        // Find each call and balance its parentheses to capture the full arg list.
        $off = 0;
        while (($p = strpos($src, 'audit_log(', $off)) !== false) {
            $off = $p + 10;
            // Skip the declaration itself and audit_login().
            $before = substr($src, max(0, $p - 9), 9);
            if (strpos($before, 'function ') !== false) { continue; }

            $d = 1; $i = $p + 10; $n = strlen($src); $q = '';
            while ($i < $n && $d > 0) {
                $c = $src[$i];
                if ($q !== '') { if ($c === $q && $src[$i - 1] !== '\\') { $q = ''; } }
                elseif ($c === "'" || $c === '"') { $q = $c; }
                elseif ($c === '(') { $d++; }
                elseif ($c === ')') { $d--; }
                $i++;
            }
            $args = split_args(substr($src, $p + 10, $i - $p - 11));
            $checked++;

            if (count($args) >= 5 && $args[4] !== '' && $args[4][0] === '[') {
                $line = substr_count(substr($src, 0, $p), "\n") + 1;
                printf("  %s:%d — details array passed as arg 5 (string \$summary)\n", $path, $line);
                $bad++;
            }
        }
    }
}

printf("\n  audit_log arity: %d call(s) checked, %d bad\n", $checked, $bad);
if ($bad) {
    echo "  Arg 5 is `string \$summary`; the details array belongs in arg 6.\n";
    echo "  An array there is a TypeError -> uncaught by catch(Exception) -> empty\n";
    echo "  response body -> \"Unexpected end of JSON input\" in the browser.\n";
}
exit($bad ? 1 : 0);
