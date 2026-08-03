<?php
/**
 * Host uptime — how long the machine running this dispatch system has been up.
 *
 * ── WHY THIS IS ITS OWN FILE ──────────────────────────────────────────
 *
 * This logic used to live inline inside checkOs() in api/health.php, an
 * endpoint that authenticates and then immediately emits JSON. Nothing could
 * include it to test it, so the one thing worth proving — that the boot-time
 * output actually parses — was unprovable. That is the pattern CLAUDE.md
 * records for api/owntracks-config.php: reusable, testable logic belongs in an
 * inc/*.php include, not buried inside an endpoint.
 *
 * ── THE DEFECT THIS FIXES (reported by @rjonesbsink) ──────────────────
 *
 * Uptime on Windows was obtained ONLY from `wmic`. Microsoft removed WMIC from
 * Windows 11 24H2 and it is deprecated/absent on current Windows Server builds.
 * On those hosts the spawn simply failed, the output was empty, and the health
 * page printed a bare "Unknown" — indistinguishable from "this host has no
 * uptime to report". A check that cannot tell "no data" from "not supported
 * here" tells the operator nothing.
 *
 * ── WHY wmic IS STILL TRIED FIRST ─────────────────────────────────────
 *
 * Measured on a Windows 10 host, through this exact code path:
 *
 *     wmic os get LastBootUpTime ................  150 ms
 *     powershell.exe -Command (Get-CimInstance …).  647 ms
 *     spawning a binary that does not exist ......    3 ms
 *
 * So on a host that still has wmic, trying it first is ~4x faster; on a host
 * that does not, the failed spawn costs 3 ms before the fallback runs. Ordering
 * it this way also means hosts that work today keep the byte-for-byte behaviour
 * they have now, which is the conservative choice for a bug fix. As a bonus the
 * two mechanisms cover the Windows range between them from opposite ends: wmic
 * reaches back to hosts older than Get-CimInstance (PowerShell 3.0+), and
 * Get-CimInstance reaches forward to hosts where wmic is gone.
 *
 * ── THE FORMAT CONTRACT ───────────────────────────────────────────────
 *
 * The parser below wants the classic WMI datetime, `yyyyMMddHHmmss.ffffff±UUU`.
 * Verified on a real Windows 10 host, both mechanisms produce exactly that:
 *
 *     wmic ......  "LastBootUpTime\r\r\n20260621145108.500000-300\r\r\n"
 *     PowerShell.  "20260621145108.500000\r\n"
 *
 * Same 14 leading digits, same value. The PowerShell probe formats the DateTime
 * explicitly with InvariantCulture rather than letting it render through the
 * host's locale — the default rendering is "Sunday, June 21, 2026 2:51:08 PM",
 * which this parser would reject, and a non-Gregorian culture could otherwise
 * change what `yyyy` even means.
 *
 * NOTE ON TIME ZONES (pre-existing, deliberately unchanged): the ±UUU offset is
 * ignored and the timestamp is interpreted in PHP's default timezone. Both
 * mechanisms emit LOCAL time, so they agree with each other — which is the
 * property that matters here. An install whose PHP timezone differs from the
 * host's would have been skewed before this change in exactly the same way.
 *
 * ── NO SHELL IS INVOLVED ──────────────────────────────────────────────
 *
 * See runShellCapture() below. tests/test_no_shell_command_execution.php gates
 * this file (it sweeps inc/ recursively).
 */

if (!function_exists('runShellCapture')) {
    /**
     * Run a program and return its stdout, discarding stderr. Best-effort: any
     * failure yields '' so the caller degrades to an explained "unknown".
     *
     * NO SHELL IS INVOLVED. The argv-ARRAY form of proc_open() goes straight to
     * execvp()/CreateProcess(), so `;`, `|`, `$(…)` and backticks inside an
     * element are inert data rather than syntax. That is precisely why there is
     * no escapeshellarg() here — escapeshellarg() is a shell-QUOTING function,
     * and with no shell to unquote them the child would receive literal quote
     * characters. The `array` type hint is load-bearing, not decoration: it
     * makes passing a command STRING a TypeError instead of a shell invocation.
     *
     * Replaces shell_exec(), which hardened Windows/IIS hosts remove via
     * disable_functions — and @ does not suppress "call to undefined function",
     * so this endpoint died mid-request with an empty body instead of degrading.
     */
    function runShellCapture(array $argv): string
    {
        if ($argv === [] || !function_exists('proc_open')) return '';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @proc_open($argv, $descriptors, $pipes);
        if (!is_resource($proc)) return '';
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);   // drain stderr (was 2>NUL / 2>/dev/null)
        fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }
}

/**
 * Pull a boot timestamp out of WMI/CIM style output.
 *
 * Accepts anything whose first 14 characters on some line are the digits of a
 * `yyyyMMddHHmmss` datetime, which is what both mechanisms emit. Header lines
 * ("LastBootUpTime") and blank lines are skipped. wmic's odd "\r\r\n" line
 * endings are handled by the trim().
 *
 * @return int|null Unix timestamp, or null if no line carried a boot time.
 */
function host_uptime_parse_boot_time(string $output): ?int
{
    if ($output === '') return null;

    $lines = array_filter(array_map('trim', explode("\n", $output)));
    foreach ($lines as $line) {
        if (!preg_match('/^(\d{14})/', $line, $m)) continue;

        $bootTime = $m[1];
        $year  = (int) substr($bootTime, 0, 4);
        $month = (int) substr($bootTime, 4, 2);
        $day   = (int) substr($bootTime, 6, 2);
        $hour  = (int) substr($bootTime, 8, 2);
        $min   = (int) substr($bootTime, 10, 2);
        $sec   = (int) substr($bootTime, 12, 2);

        // Reject a 14-digit run that is not a plausible datetime, so a stray
        // number in some future output shape cannot be read as a boot time.
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31
            || $hour > 23 || $min > 59 || $sec > 60 || $year < 1980) {
            continue;
        }

        $ts = mktime($hour, $min, $sec, $month, $day, $year);
        if ($ts === false) continue;
        return $ts;
    }

    return null;
}

/**
 * The ordered list of Windows boot-time probes.
 *
 * Each entry: label (for the "we tried these" message), argv (a discrete
 * argument list — never a command line), and note (why it is in the list).
 *
 * Every argv element is a fixed literal. Nothing caller-influenced is ever
 * placed in one, which is what keeps the PowerShell entry safe and what
 * tests/test_no_shell_command_execution.php rule C requires.
 */
function host_uptime_windows_probes(): array
{
    return [
        [
            'label' => 'wmic',
            'argv'  => ['wmic', 'os', 'get', 'LastBootUpTime'],
            'note'  => 'removed in Windows 11 24H2 and recent Windows Server',
        ],
        [
            'label' => 'powershell.exe Get-CimInstance',
            'argv'  => ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
                        "(Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction Stop).LastBootUpTime.ToString('yyyyMMddHHmmss.ffffff',[Globalization.CultureInfo]::InvariantCulture)"],
            'note'  => 'Windows PowerShell 5.1, present on every supported Windows',
        ],
        [
            'label' => 'pwsh.exe Get-CimInstance',
            'argv'  => ['pwsh.exe', '-NoProfile', '-NonInteractive', '-Command',
                        "(Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction Stop).LastBootUpTime.ToString('yyyyMMddHHmmss.ffffff',[Globalization.CultureInfo]::InvariantCulture)"],
            'note'  => 'PowerShell 7+, for hosts that ship only the modern shell',
        ],
    ];
}

/**
 * Resolve host uptime on Windows.
 *
 * @param callable|null $runner Injected for testing: given an argv array,
 *                              returns captured stdout. Defaults to the real
 *                              subprocess capture.
 * @return array{uptime_sec:int|null,source:string|null,reason:string|null,attempted:array<int,string>}
 */
function host_uptime_windows(?callable $runner = null): array
{
    $probes    = host_uptime_windows_probes();
    $attempted = [];

    if (!function_exists('proc_open')) {
        return [
            'uptime_sec' => null,
            'source'     => null,
            'attempted'  => [],
            'reason'     => 'PHP cannot start subprocesses on this host (proc_open is '
                . 'unavailable, usually via disable_functions), so the Windows boot '
                . 'time could not be read',
        ];
    }

    $runner = $runner ?? 'runShellCapture';

    $ran = [];   // probes that produced output but no parsable boot time
    foreach ($probes as $probe) {
        $attempted[] = $probe['label'];
        $output = (string) $runner($probe['argv']);
        if (trim($output) !== '') {
            $ran[] = $probe['label'];
        }
        $ts = host_uptime_parse_boot_time($output);
        if ($ts === null) continue;

        return [
            'uptime_sec' => max(0, time() - $ts),
            'source'     => $probe['label'],
            'reason'     => null,
            'attempted'  => $attempted,
        ];
    }

    // Nothing worked. Say which case this is — "the tools are not here" and
    // "the tools are here but answered nonsense" need different fixes, and an
    // operator cannot act on a bare "Unknown".
    if ($ran === []) {
        $reason = 'no boot-time source on this host — none of these could be run: '
            . implode(', ', $attempted)
            . '. Microsoft removed wmic in Windows 11 24H2, so on a current Windows '
            . 'the PowerShell fallback is the one that matters; check that '
            . 'powershell.exe is on PATH for the web server account';
    } else {
        $reason = 'ran ' . implode(', ', $ran)
            . ' but got no parsable boot time back (expected a WMI datetime like '
            . '20260621145108.500000) — WMI/CIM may be unhealthy on this host';
    }

    return [
        'uptime_sec' => null,
        'source'     => null,
        'reason'     => $reason,
        'attempted'  => $attempted,
    ];
}

/**
 * Resolve host uptime on Linux/macOS/BSD. Unchanged behaviour: /proc/uptime
 * first, then `uptime -s`. Only the failure path gained an explanation.
 *
 * @param callable|null $runner Injected for testing.
 * @return array{uptime_sec:int|null,source:string|null,reason:string|null,attempted:array<int,string>}
 */
function host_uptime_posix(?callable $runner = null): array
{
    if (is_readable('/proc/uptime')) {
        $raw = (string) @file_get_contents('/proc/uptime');
        if (trim($raw) !== '') {
            return [
                'uptime_sec' => (int) floatval($raw),
                'source'     => '/proc/uptime',
                'reason'     => null,
                'attempted'  => ['/proc/uptime'],
            ];
        }
    }

    if (!function_exists('proc_open')) {
        return [
            'uptime_sec' => null,
            'source'     => null,
            'attempted'  => ['/proc/uptime'],
            'reason'     => '/proc/uptime is not readable and PHP cannot start '
                . 'subprocesses (proc_open is unavailable) to run `uptime -s`',
        ];
    }

    $runner = $runner ?? 'runShellCapture';
    $output = (string) $runner(['uptime', '-s']);
    $bootTs = trim($output) !== '' ? strtotime(trim($output)) : false;
    if ($bootTs !== false) {
        return [
            'uptime_sec' => max(0, time() - $bootTs),
            'source'     => 'uptime -s',
            'reason'     => null,
            'attempted'  => ['/proc/uptime', 'uptime -s'],
        ];
    }

    return [
        'uptime_sec' => null,
        'source'     => null,
        'attempted'  => ['/proc/uptime', 'uptime -s'],
        'reason'     => '/proc/uptime is not readable and `uptime -s` returned '
            . 'nothing usable on this host',
    ];
}

/**
 * Resolve host uptime for whichever OS this is.
 *
 * @param string|null   $osFamily Defaults to PHP_OS_FAMILY. Injected for testing.
 * @param callable|null $runner   Injected for testing.
 * @return array{uptime_sec:int|null,source:string|null,reason:string|null,attempted:array<int,string>}
 */
function host_uptime(?string $osFamily = null, ?callable $runner = null): array
{
    $osFamily = $osFamily ?? PHP_OS_FAMILY;

    return $osFamily === 'Windows'
        ? host_uptime_windows($runner)
        : host_uptime_posix($runner);
}
