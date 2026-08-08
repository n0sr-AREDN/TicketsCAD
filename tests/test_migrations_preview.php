<?php
/**
 * Settings -> Database Migrations: "Preview script content" must not fatal.
 *
 * GH TicketsCAD#11 (Ron Jones): expanding any migration's preview row fatally
 * errored, every time, for every file:
 *
 *   PHP Fatal error: Uncaught TypeError: feof(): supplied resource is not a
 *   valid stream resource in migrations.php:353
 *
 * The cause was two lines in the wrong order — fclose($fh) and then feof($fh)
 * on the now-closed handle to decide whether to print "(truncated)". PHP 8
 * raises a TypeError for feof() on a closed resource unconditionally; what the
 * boolean would have been is irrelevant, the call itself is fatal.
 *
 * Worth keeping in view: this is the page the pending-migrations banner sends
 * the operator to. The reporter arrived here while chasing an unrelated
 * "Database migrations pending" warning, clicked the link the product offered,
 * and got a white page. A remedy path that crashes is worse than no link.
 *
 * Two assertions, doing different jobs:
 *   1. The mechanism, established empirically rather than asserted — a probe
 *      subprocess proves PHP really does fatal on this, so the gate below is
 *      protecting against something real and stays meaningful if PHP changes.
 *   2. The file itself no longer has that ordering.
 */

$root = dirname(__DIR__);

$pass = 0;
$fail = 0;

function test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$page = $root . '/migrations.php';
if (!is_file($page)) {
    echo "SKIP: migrations.php not present\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

// ── 1. The mechanism ────────────────────────────────────────────────────────
//
// Run the broken sequence in a throwaway subprocess. If this ever stops
// fatalling, the gate below has become a style rule rather than a bug guard,
// and whoever reads this should know that.
echo "-- The mechanism --\n";

if (!function_exists('proc_open')) {
    echo "[SKIP] feof()-after-fclose() fatals — no way to start a subprocess on this host\n";
} else {
    $probe = tempnam(sys_get_temp_dir(), 'tcfeof') . '.php';
    file_put_contents($probe, <<<'PHP'
<?php
$f = tmpfile();
fwrite($f, "one\ntwo\n");
rewind($f);
fgets($f);
fclose($f);
try {
    feof($f);
    echo "NO_ERROR\n";
} catch (TypeError $e) {
    echo "TYPEERROR\n";
}
PHP
    );

    $sink  = tmpfile();
    $pipes = [];
    $proc  = @proc_open([PHP_BINARY, $probe], [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink], $pipes);
    $out   = '';
    if (is_resource($proc)) {
        fclose($pipes[0]);
        proc_close($proc);
        rewind($sink);
        $out = (string) stream_get_contents($sink);
    }
    fclose($sink);
    @unlink($probe);

    test('feof() on a closed handle raises a TypeError on this PHP '
        . '(so the ordering below genuinely matters)',
        strpos($out, 'TYPEERROR') !== false,
        'probe said: ' . trim($out));
}

// ── 2. The file ─────────────────────────────────────────────────────────────
echo "\n-- migrations.php --\n";

$src = file_get_contents($page);

test('migrations.php still has a preview block that reads the file',
    strpos($src, 'fopen(') !== false && strpos($src, 'fgets(') !== false);

// Any feof() textually after an fclose() inside the same short region is the
// defect. Look for the literal adjacency rather than parsing: the bug was two
// consecutive statements, and that is exactly what must not come back.
$hasFcloseThenFeof = (bool) preg_match(
    '/fclose\s*\(\s*\$fh\s*\)\s*;(?:(?!fopen).){0,300}?feof\s*\(\s*\$fh\s*\)/s',
    $src
);
test('no feof($fh) after fclose($fh) in migrations.php',
    !$hasFcloseThenFeof,
    'the preview will fatal with "supplied resource is not a valid stream resource"');

// And the positive form: the truncation decision is taken while the handle is
// still open.
$asksBeforeClosing = (bool) preg_match(
    '/feof\s*\(\s*\$fh\s*\)(?:(?!fopen).){0,300}?fclose\s*\(\s*\$fh\s*\)/s',
    $src
);
test('the truncation check happens before the handle is closed',
    $asksBeforeClosing);

test('the preview still tells the reader when it truncated',
    strpos($src, 'truncated') !== false);

// ── 3. The page parses ──────────────────────────────────────────────────────
echo "\n-- The page compiles --\n";

if (!function_exists('proc_open')) {
    echo "[SKIP] migrations.php lints — no way to start a subprocess on this host\n";
} else {
    $sink  = tmpfile();
    $pipes = [];
    $proc  = @proc_open([PHP_BINARY, '-l', $page], [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink], $pipes);
    $rc    = 1;
    $out   = '';
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $rc = proc_close($proc);
        rewind($sink);
        $out = trim((string) stream_get_contents($sink));
    }
    fclose($sink);
    test('migrations.php lints (php -l)', $rc === 0, $out);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
