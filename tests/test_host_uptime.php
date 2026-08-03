<?php
/**
 * Host-uptime tests — wmic is gone on modern Windows, so it cannot be the only
 * way this system learns the host's boot time.
 *
 * Reported by @rjonesbsink: api/health.php read uptime exclusively from `wmic`.
 * Microsoft removed WMIC in Windows 11 24H2, and it is deprecated/absent on
 * current Windows Server builds. On those hosts the spawn failed, the output
 * was empty, and the System Health page printed a bare "Unknown" — which reads
 * identically to "this host genuinely has no uptime", so nobody could tell the
 * check had simply lost its only mechanism.
 *
 * What is locked down here:
 *   1. wmic is never the ONLY mechanism, and a PowerShell Get-CimInstance
 *      fallback specifically exists.
 *   2. The REAL parser accepts what the fallback REALLY emits. The samples
 *      below are verbatim captures, not idealised strings — see the provenance
 *      note on $REAL. On Windows the test additionally captures live from the
 *      host it is running on, which is the only way to catch the format
 *      drifting out from under us.
 *   3. A Windows host with neither mechanism explains WHY it does not know,
 *      and says something DIFFERENT from a host whose tools ran but answered
 *      nonsense — those need different fixes.
 *   4. Non-Windows hosts are untouched: no Windows probe is ever spawned.
 *
 * Runs anywhere: no database, no HTTP, no config.php. CI is Linux, which is
 * exactly why the recorded transcripts have to carry their own weight there.
 *
 * Usage: php tests/test_host_uptime.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/host-uptime.php';

$pass = 0;
$fail = 0;

function t(string $label, bool $cond, string $hint = ''): void
{
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] $label\n";
        $pass++;
    } else {
        echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n";
        $fail++;
    }
}

$root = dirname(__DIR__);

/**
 * VERBATIM captures, base64 so no editor can normalise the line endings that
 * are half the point (wmic emits "\r\r\n"). Taken on a Windows 10 19045 host
 * through inc/host-uptime.php's own runShellCapture(), 2026-08-02, both
 * describing the same boot at 2026-06-21 14:51:08 local:
 *
 *   wmic os get LastBootUpTime
 *   powershell.exe -NoProfile -NonInteractive -Command
 *     (Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction Stop)
 *       .LastBootUpTime.ToString('yyyyMMddHHmmss.ffffff', InvariantCulture)
 *
 * Do not "tidy" these. The whole value of the pair is that it is what the two
 * mechanisms actually printed, including the trailing padding and the -300
 * offset wmic appends and PowerShell does not.
 */
$REAL = [
    'wmic'       => base64_decode('TGFzdEJvb3RVcFRpbWUgICAgICAgICAgICAgDQ0KMjAyNjA2MjExNDUxMDguNTAwMDAwLTMwMCAgDQ0KDQ0K'),
    'powershell' => base64_decode('MjAyNjA2MjExNDUxMDguNTAwMDAwDQo='),
];
/** What PowerShell prints if you DON'T format explicitly — the trap the probe avoids. */
$LOCALE_RENDERING = "\r\nSunday, June 21, 2026 2:51:08 PM\r\n";

echo "=== Host uptime: wmic must not be the only mechanism ===\n\n";

// ── 1. The fallback exists, and wmic is not alone ────────────────────
echo "-- Probe list --\n";

$probes = host_uptime_windows_probes();
t('host_uptime_windows_probes() returns at least two probes',
    count($probes) >= 2, 'got ' . count($probes));

$argv0 = [];
foreach ($probes as $p) {
    $argv0[] = strtolower((string) ($p['argv'][0] ?? ''));
}

t('wmic is present (still the fastest path where it exists)',
    in_array('wmic', $argv0, true));

$nonWmic = array_values(array_filter($argv0, static fn(string $b): bool => $b !== 'wmic'));
t('wmic is NOT the only mechanism — a non-wmic probe exists',
    $nonWmic !== [],
    'the reported defect: removing wmic from the host removed uptime entirely');

$psProbes = array_values(array_filter($probes, static function (array $p): bool {
    return in_array(strtolower((string) ($p['argv'][0] ?? '')), ['powershell.exe', 'pwsh.exe'], true);
}));
t('a PowerShell probe exists', $psProbes !== []);

$usesCim = false;
foreach ($psProbes as $p) {
    foreach ($p['argv'] as $el) {
        if (stripos((string) $el, 'Get-CimInstance') !== false
            && stripos((string) $el, 'Win32_OperatingSystem') !== false) {
            $usesCim = true;
        }
    }
}
t('the PowerShell probe queries Get-CimInstance Win32_OperatingSystem', $usesCim);

$fmtExplicit = false;
foreach ($psProbes as $p) {
    foreach ($p['argv'] as $el) {
        if (strpos((string) $el, 'yyyyMMddHHmmss') !== false
            && stripos((string) $el, 'InvariantCulture') !== false) {
            $fmtExplicit = true;
        }
    }
}
t('the PowerShell probe formats the DateTime explicitly, with InvariantCulture',
    $fmtExplicit,
    'without it the host locale decides the rendering and the parser gets prose');

t('wmic is ordered before the PowerShell fallback',
    array_search('wmic', $argv0, true) < array_search($psProbes[0]['argv'][0], $argv0, true),
    'measured on Windows 10: wmic 150ms vs powershell 647ms, and a missing binary costs 3ms');

// Every argv element a discrete literal — the property that makes spawning a
// shell interpreter safe here. Mirrors rule C of the shell-execution gate.
$allLiteral = true;
foreach ($probes as $p) {
    foreach ($p['argv'] as $el) {
        if (!is_string($el) || $el === '') $allLiteral = false;
    }
}
t('every probe argv element is a non-empty string (an argument list, not a command line)',
    $allLiteral);

// ── 2. The REAL parser against REAL output ───────────────────────────
echo "\n-- The parser, driven with verbatim captures --\n";

t('the recorded transcripts decoded', $REAL['wmic'] !== '' && $REAL['powershell'] !== '');

$tsWmic = host_uptime_parse_boot_time($REAL['wmic']);
$tsPs   = host_uptime_parse_boot_time($REAL['powershell']);

t('real wmic output parses', $tsWmic !== null, var_export($REAL['wmic'], true));
t('real PowerShell output parses', $tsPs !== null,
    'this is the fix: the fallback must satisfy the EXISTING parser — '
    . var_export($REAL['powershell'], true));

// Round-tripped through date() so the assertion is timezone-independent:
// the parser reads local time and date() formats local time.
t('wmic transcript resolves to the boot time it describes',
    $tsWmic !== null && date('YmdHis', $tsWmic) === '20260621145108',
    $tsWmic === null ? 'null' : date('YmdHis', $tsWmic));
t('PowerShell transcript resolves to the boot time it describes',
    $tsPs !== null && date('YmdHis', $tsPs) === '20260621145108',
    $tsPs === null ? 'null' : date('YmdHis', $tsPs));
t('both mechanisms resolve to the SAME instant (format compatibility)',
    $tsWmic !== null && $tsWmic === $tsPs,
    'the fallback is only a fallback if it means the same thing');

t('wmic\'s trailing UTC offset does not confuse the parser',
    strpos($REAL['wmic'], '-300') !== false && $tsWmic !== null);

echo "\n-- The parser rejects what is not a boot time --\n";

t('empty output yields null', host_uptime_parse_boot_time('') === null);
t('a header line alone yields null',
    host_uptime_parse_boot_time("LastBootUpTime  \r\r\n\r\r\n") === null);
t('PowerShell\'s DEFAULT locale rendering is rejected, not misread',
    host_uptime_parse_boot_time($LOCALE_RENDERING) === null,
    'exactly why the probe pins the format: ' . trim($LOCALE_RENDERING));
t('a 14-digit run that is not a datetime is rejected',
    host_uptime_parse_boot_time("99999999999999\n") === null);
t('an implausible month is rejected',
    host_uptime_parse_boot_time("20261321145108.500000\n") === null);

// ── 3. Live capture on a real Windows host ───────────────────────────
echo "\n-- Live capture (Windows only) --\n";

if (PHP_OS_FAMILY !== 'Windows') {
    echo "  (not Windows — live capture not applicable; recorded transcripts above cover the format)\n";
    t('non-Windows host correctly skips the live Windows capture', true);
} else {
    $anyLive = false;
    foreach ($probes as $p) {
        $out = runShellCapture($p['argv']);
        if (trim($out) === '') {
            echo "  ({$p['label']} not available on this host — skipped)\n";
            continue;
        }
        $anyLive = true;
        t("live {$p['label']} output parses with the real parser",
            host_uptime_parse_boot_time($out) !== null,
            var_export(substr($out, 0, 80), true));
    }
    t('at least one uptime mechanism works on this Windows host', $anyLive,
        'neither wmic nor PowerShell produced output');
}

// ── 4. Degrading honestly ────────────────────────────────────────────
echo "\n-- Degradation: say WHY, not \"Unknown\" --\n";

/** A Windows host where nothing can be spawned at all. */
$none = host_uptime('Windows', static fn(array $argv): string => '');

t('both mechanisms missing → no uptime figure is invented',
    $none['uptime_sec'] === null);
t('both mechanisms missing → a reason is given',
    is_string($none['reason']) && trim((string) $none['reason']) !== '');
t('the reason is not merely the word "Unknown"',
    strcasecmp(trim((string) $none['reason']), 'Unknown') !== 0
    && strlen((string) $none['reason']) > 30,
    (string) $none['reason']);
t('the reason names wmic', stripos((string) $none['reason'], 'wmic') !== false);
t('the reason explains that Microsoft removed it (24H2)',
    stripos((string) $none['reason'], '24H2') !== false);
t('the reason points at the fallback that matters on current Windows',
    stripos((string) $none['reason'], 'powershell') !== false);
t('every probe attempted is reported', count($none['attempted']) === count($probes));

/** Tools present, but WMI itself unhealthy — output, no boot time. */
$junk = host_uptime('Windows', static fn(array $argv): string => "LastBootUpTime  \r\r\n\r\r\n");

t('tools-present-but-no-boot-time also yields no figure', $junk['uptime_sec'] === null);
t('"tools absent" and "tools broken" give DIFFERENT reasons',
    is_string($junk['reason']) && $junk['reason'] !== $none['reason'],
    'a check that cannot tell no-data from not-supported is close to useless');
t('the broken-WMI reason says the probes actually ran',
    stripos((string) $junk['reason'], 'ran ') === 0, (string) $junk['reason']);

/** The reported defect, reproduced: wmic gone, PowerShell healthy. */
$fellBack = host_uptime('Windows', static function (array $argv) use ($REAL): string {
    return strtolower((string) $argv[0]) === 'wmic' ? '' : $REAL['powershell'];
});

t('wmic missing → uptime STILL resolves via the fallback',
    $fellBack['uptime_sec'] !== null,
    'this is the defect @rjonesbsink reported');
t('the fallback reports itself as the source',
    stripos((string) $fellBack['source'], 'powershell') !== false,
    var_export($fellBack['source'], true));
t('a successful lookup carries no reason', $fellBack['reason'] === null);
t('wmic present → wmic is used and the fallback is not spawned',
    (static function () use ($REAL): bool {
        $seen = [];
        $r = host_uptime('Windows', static function (array $argv) use ($REAL, &$seen): string {
            $seen[] = strtolower((string) $argv[0]);
            return strtolower((string) $argv[0]) === 'wmic' ? $REAL['wmic'] : '';
        });
        return $r['uptime_sec'] !== null && $seen === ['wmic'];
    })());

t('the invariant holds: no uptime figure is ever returned without a reason',
    (static function (): bool {
        foreach ([
            host_uptime('Windows', static fn(array $a): string => ''),
            host_uptime('Windows', static fn(array $a): string => 'garbage'),
            host_uptime('Linux',   static fn(array $a): string => 'garbage'),
        ] as $r) {
            if ($r['uptime_sec'] === null && (!is_string($r['reason']) || trim($r['reason']) === '')) {
                return false;
            }
        }
        return true;
    })());

// ── 5. Non-Windows hosts are untouched ───────────────────────────────
echo "\n-- Non-Windows hosts --\n";

$spawned = [];
$posix = host_uptime('Linux', static function (array $argv) use (&$spawned): string {
    $spawned[] = strtolower((string) $argv[0]);
    return '';
});
foreach (['wmic', 'powershell.exe', 'pwsh.exe'] as $winBin) {
    t("a Linux host never spawns {$winBin}", !in_array($winBin, $spawned, true));
}

if (is_readable('/proc/uptime')) {
    $live = host_uptime('Linux');
    t('a host with /proc/uptime reads it directly',
        $live['uptime_sec'] !== null && $live['source'] === '/proc/uptime');
    t('reading /proc/uptime spawns nothing at all',
        host_uptime('Linux', static function (array $a): string {
            throw new RuntimeException('should not spawn when /proc/uptime is readable');
        })['uptime_sec'] !== null);
} else {
    // No /proc/uptime (this is how the `uptime -s` branch gets exercised on
    // Windows) — drive it with a real `uptime -s` output shape.
    $viaUptime = host_uptime('Linux', static fn(array $a): string => "2026-06-21 14:51:08\n");
    t('without /proc/uptime the `uptime -s` branch resolves',
        $viaUptime['uptime_sec'] !== null && $viaUptime['source'] === 'uptime -s',
        var_export($viaUptime, true));
    t('the POSIX failure path also explains itself',
        $posix['uptime_sec'] === null
        && is_string($posix['reason']) && stripos((string) $posix['reason'], 'uptime') !== false,
        var_export($posix['reason'], true));
}

// ── 6. The endpoint is actually wired to it ──────────────────────────
echo "\n-- api/health.php wiring --\n";

$healthSrc = (string) @file_get_contents($root . '/api/health.php');
t('api/health.php is readable', $healthSrc !== '');
t('api/health.php requires inc/host-uptime.php',
    strpos($healthSrc, "host-uptime.php") !== false);
t('api/health.php calls host_uptime()',
    strpos($healthSrc, 'host_uptime(') !== false);
t('api/health.php no longer spawns wmic itself',
    stripos($healthSrc, "'wmic'") === false,
    'the inline single-mechanism probe must be gone, not merely supplemented');
t('the endpoint surfaces the reason to the operator',
    strpos($healthSrc, 'uptime_reason') !== false);
t('the endpoint surfaces which mechanism answered',
    strpos($healthSrc, 'uptime_source') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
