<?php
/**
 * NewUI v4.0 — Installation Health / File-Permission Checker (GH #41)
 *
 * Shared library of pure check functions. No side effects, no output,
 * no database requirement — every public function is wrapped so it can
 * NEVER throw. Callers:
 *
 *   - api/health-check.php   (web SAPI — authoritative: is_writable /
 *                             is_readable answer for the WEB user)
 *   - tools/check-health.php (CLI — writability answers reflect the CLI
 *                             user; the unreadable-by-others scan and the
 *                             opcache/version checks are still valid)
 *   - status.php "File & Code Health" card (via the API)
 *
 * Design brief (Eric, 2026-07-04): a self-hosted beta tester who deploys
 * with `git pull` as root repeatedly hits (a) new files owned by root /
 * unreadable by the web user → new JS/endpoints 404 silently, and
 * (b) PHP opcache serving stale code after a pull because apache/php-fpm
 * was never reloaded. Policy: DETECT AND WARN, NEVER AUTO-FIX — "if
 * someone has their own way of managing their file permissions, stay out
 * of their way, but let them know when we see a potential problem."
 */

require_once __DIR__ . '/https.php';   // is_https(), is_https_verified()

// Literal build date. Compiled into the opcache'd copy of this file; the
// version-match check re-reads this constant FRESH from disk and compares
// — a mismatch means the server is executing a stale compiled copy.
if (!defined('HEALTH_CHECK_BUILD')) {
    define('HEALTH_CHECK_BUILD', '2026-07-29');
}

/**
 * Application root. NEWUI_ROOT when config.php has been loaded, else
 * derived from this file's location (inc/ is one level below root).
 */
function health_check_root(): string
{
    if (defined('NEWUI_ROOT')) {
        return NEWUI_ROOT;
    }
    return dirname(__DIR__);
}

/**
 * Resolve a file's owner to a username when possible.
 * Returns username (posix systems), numeric uid string (posix ext
 * missing), or null (Windows / stat failure).
 */
function _health_file_owner(string $path): ?string
{
    try {
        $uid = @fileowner($path);
        if ($uid === false) {
            return null;
        }
        if (function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid($uid);
            if (is_array($pw) && isset($pw['name'])) {
                return $pw['name'];
            }
        }
        // Windows: fileowner() returns 0 for everything — meaningless.
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        return (string) $uid;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * The user the CURRENT process runs as (web user via web SAPI, CLI user
 * via CLI). Best-effort; null when undeterminable.
 */
function _health_process_user(): ?string
{
    try {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['name'])) {
                return $pw['name'];
            }
        }
        $u = @get_current_user();
        return ($u !== '' && $u !== false) ? $u : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * ── WHO WILL ACTUALLY WRITE THESE DIRECTORIES ───────────────────────────────
 *
 * Everything below exists because this check spent its whole life answering
 * the wrong question.
 *
 * `is_writable()` answers for the CURRENT process user. Under the web SAPI
 * that is the web server account and the answer is right. Run from the command
 * line — which is what docs/UPDATE-CHECKLIST.md tells administrators to do,
 * over SSH, as themselves — it answers for a human login that was never
 * supposed to write those directories in the first place. On 2026-07-31 both
 * live hosts reported "5 critical" and told the operator to `chown` three
 * directories that were already `www-data:www-data 775` and already writable;
 * the same check rendered in the browser said OK. Not a disagreement between
 * two checks: one check, asked as two different people.
 *
 * That is the worst failure mode a health check has. An install that is
 * correct is told it is critically broken, every time it is checked, so the
 * report gets ignored — and this project has already been bitten once by a
 * monitoring surface nobody reads (a scheduled job that had never run for
 * seven weeks, silently, while its noisy neighbour kept working).
 *
 * So: work out who the web server runs as, and answer for THEM. When that
 * cannot be established, say `unknown` — never `ok`, and never `critical`.
 * A confident wrong answer is what caused this.
 */

/**
 * Account names that are conventionally a web server, across the
 * distributions this application is deployed on. Used ONLY to raise
 * confidence in a name discovered by some other means — never to guess one,
 * because a shared-hosting install serves the site as the account owner and
 * that name is on nobody's list.
 */
function _health_known_web_user_names(): array
{
    return [
        'www-data',   // Debian / Ubuntu (apache2, nginx, php-fpm)
        'apache',     // RHEL / Fedora / Alma / Rocky
        'httpd',
        'nginx',      // RHEL nginx packages
        'http',       // Arch
        '_www',       // macOS
        'daemon',     // some minimal / BSD packagings
        'web',
        'nobody',
    ];
}

/**
 * Resolve a username (or uid) to the identity facts writability depends on:
 * uid, primary gid, and every supplementary group.
 *
 * Returns null when POSIX is unavailable (Windows) or the account does not
 * exist. Supplementary groups come from /etc/group; groups provided only by
 * LDAP/SSSD are invisible here, which can under-report access — noted in the
 * output rather than papered over.
 */
function _health_user_record(?string $name, ?int $uid = null): ?array
{
    try {
        if (!function_exists('posix_getpwnam') || !function_exists('posix_getpwuid')) {
            return null;
        }
        $pw = false;
        if ($name !== null && $name !== '') {
            $pw = @posix_getpwnam($name);
        }
        if ($pw === false && $uid !== null) {
            $pw = @posix_getpwuid($uid);
        }
        if (!is_array($pw) || !isset($pw['uid'], $pw['name'])) {
            return null;
        }

        $gids = [(int) $pw['gid']];
        // Supplementary groups. PHP exposes no getgrouplist(), so read the
        // group database directly — a file read, no shell, no subprocess.
        try {
            $groupFile = '/etc/group';
            if (@is_readable($groupFile)) {
                $raw = @file_get_contents($groupFile, false, null, 0, 1048576);
                if (is_string($raw)) {
                    foreach (explode("\n", $raw) as $line) {
                        $parts = explode(':', $line);
                        if (count($parts) < 4) {
                            continue;
                        }
                        $members = array_filter(array_map('trim', explode(',', $parts[3])));
                        if (in_array($pw['name'], $members, true)) {
                            $gids[] = (int) $parts[2];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Primary group alone is still a usable answer.
        }

        return [
            'name' => (string) $pw['name'],
            'uid'  => (int) $pw['uid'],
            'gid'  => (int) $pw['gid'],
            'gids' => array_values(array_unique($gids)),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Is a web server running on this machine right now, and as whom?
 *
 * Reads /proc directly — no shell, no `ps`, no subprocess (this application
 * hands no command line to a shell anywhere; see
 * tests/test_no_shell_command_execution.php). The master process of apache and
 * nginx runs as root and the workers run as the web account, so uid 0 is
 * skipped: the workers are the ones that will open a file for writing.
 *
 * Returns null on a host with no /proc (Windows, macOS), with hidepid set, or
 * with no web server running.
 *
 * @param string $procRoot Injectable so tests can drive a fixture tree.
 */
function _health_web_user_from_proc(string $procRoot = '/proc'): ?array
{
    try {
        if (!@is_dir($procRoot)) {
            return null;
        }
        $wanted = ['apache2', 'httpd', 'nginx', 'php-fpm', 'lighttpd', 'caddy', 'openlitespeed', 'litespeed'];

        $entries = @scandir($procRoot);
        if ($entries === false) {
            return null;
        }
        foreach ($entries as $pid) {
            if (!ctype_digit((string) $pid)) {
                continue;
            }
            $comm = @file_get_contents($procRoot . '/' . $pid . '/comm', false, null, 0, 256);
            if (!is_string($comm)) {
                continue;
            }
            $comm = trim($comm);
            $match = false;
            foreach ($wanted as $w) {
                // php-fpm workers present as "php-fpm8.2", "php-fpm: pool www".
                if ($comm === $w || strpos($comm, $w) === 0) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                continue;
            }
            $status = @file_get_contents($procRoot . '/' . $pid . '/status', false, null, 0, 8192);
            if (!is_string($status)) {
                continue;
            }
            // "Uid:\treal\teffective\tsaved\tfs" — the effective uid is what
            // the kernel checks when the worker opens a file.
            if (!preg_match('/^Uid:\s+(\d+)\s+(\d+)/m', $status, $m)) {
                continue;
            }
            $euid = (int) $m[2];
            if ($euid === 0) {
                continue;   // the master; its workers carry the answer
            }
            $pw   = function_exists('posix_getpwuid') ? @posix_getpwuid($euid) : false;
            $name = (is_array($pw) && isset($pw['name'])) ? (string) $pw['name'] : null;
            return [
                'name'  => $name,
                'uid'   => $euid,
                'basis' => 'the ' . $comm . ' worker process running on this machine',
            ];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * What the web server's own configuration says it runs as.
 *
 * Weaker than a running process (a box can have apache installed and idle
 * while nginx actually serves the site) and weaker than the ownership of this
 * install's runtime directories, so it sits below both. Still the only signal
 * left on a host where the server is stopped at the moment of the check.
 *
 * @param array|null $files Injectable [path => regex] so tests can drive a fixture.
 */
function _health_web_user_from_server_config(?array $files = null): ?array
{
    try {
        if ($files === null) {
            $files = [
                '/etc/apache2/envvars'          => '/^\s*export\s+APACHE_RUN_USER=(\S+)/m',
                '/etc/httpd/conf/httpd.conf'    => '/^\s*User\s+([A-Za-z0-9._-]+)\s*$/m',
                '/etc/nginx/nginx.conf'         => '/^\s*user\s+([A-Za-z0-9._-]+)\s*;/m',
                '/usr/local/etc/php-fpm.d/www.conf' => '/^\s*user\s*=\s*(\S+)/m',
                '/etc/php-fpm.d/www.conf'       => '/^\s*user\s*=\s*(\S+)/m',
            ];
        }
        foreach ($files as $path => $pattern) {
            if (!@is_file($path) || !@is_readable($path)) {
                continue;
            }
            $raw = @file_get_contents($path, false, null, 0, 262144);
            if (!is_string($raw) || !preg_match($pattern, $raw, $m)) {
                continue;
            }
            $name = trim($m[1], "\"' \t");
            // "User ${APACHE_RUN_USER}" and friends are indirection, not an answer.
            if ($name === '' || strpos($name, '$') !== false) {
                continue;
            }
            return ['name' => $name, 'uid' => null, 'basis' => $path];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Who owns this install's runtime directories?
 *
 * Direct evidence about THIS application rather than about the machine: a
 * correctly-installed tree has these owned by (or group-shared with) the web
 * account by construction, because that is what the install documentation
 * instructs. Files inside them are stronger still — a tile under cache/weather
 * was written by api/weather-proxy.php, i.e. by the web server, at runtime.
 *
 * Deliberately refuses to answer when the owners DISAGREE and no candidate
 * carries a conventional web-server name: a split-ownership tree is exactly
 * the situation where a guess would produce the confident wrong answer this
 * whole change exists to stop.
 */
function _health_web_user_from_runtime_owner(?string $root = null): ?array
{
    try {
        if (PHP_OS_FAMILY === 'Windows') {
            return null;   // fileowner() returns 0 for everything on NTFS
        }
        $root = $root ?? health_check_root();
        $sep  = DIRECTORY_SEPARATOR;

        // Artefacts first (proof the web server wrote here), then the
        // directories themselves.
        $artefacts = [];
        foreach (['cache/weather', 'cache/zello-audio', 'uploads', 'uploads/overlays', 'cache'] as $rel) {
            $dir = $root . $sep . str_replace('/', $sep, $rel);
            if (!@is_dir($dir)) {
                continue;
            }
            $found = @glob(rtrim($dir, '/\\') . '/*') ?: [];
            foreach (array_slice($found, 0, 5) as $f) {
                if (@is_file($f)) {
                    $artefacts[] = ['path' => $f, 'rel' => $rel . '/' . basename($f)];
                }
            }
        }
        $dirs = [];
        foreach (['uploads', 'cache', 'cache/weather', 'cache/zello-audio', 'uploads/overlays'] as $rel) {
            $dir = $root . $sep . str_replace('/', $sep, $rel);
            if (@is_dir($dir)) {
                $dirs[] = ['path' => $dir, 'rel' => $rel];
            }
        }

        foreach ([['artefact', $artefacts], ['directory', $dirs]] as [$kind, $set]) {
            $owners = [];
            foreach ($set as $s) {
                $uid = @fileowner($s['path']);
                if ($uid === false) {
                    continue;
                }
                $owners[(int) $uid][] = $s['rel'];
            }
            if (empty($owners)) {
                continue;
            }
            $uid = null;
            if (count($owners) === 1) {
                $uid = (int) array_key_first($owners);
            } else {
                // Split ownership — only accept a conventional web account.
                $known = _health_known_web_user_names();
                foreach (array_keys($owners) as $candidate) {
                    $pw = function_exists('posix_getpwuid') ? @posix_getpwuid((int) $candidate) : false;
                    if (is_array($pw) && isset($pw['name']) && in_array($pw['name'], $known, true)) {
                        $uid = (int) $candidate;
                        break;
                    }
                }
                if ($uid === null) {
                    return null;   // genuinely ambiguous → unknown, not a guess
                }
            }
            $pw   = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
            $name = (is_array($pw) && isset($pw['name'])) ? (string) $pw['name'] : null;
            $ex   = $owners[$uid][0] ?? '';
            return [
                'name'  => $name,
                'uid'   => $uid,
                'basis' => 'the owner of this install\'s runtime ' . $kind . ' ' . $ex,
            ];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * What to tell someone whose web server account could not be established.
 *
 * Deliberately platform-aware. Naming a remedy that cannot work on the reader's
 * system is its own defect — the same shape as a health check reporting a
 * problem that is not there. Setting NEWUI_WEB_USER genuinely fixes this on a
 * POSIX host, because the account resolves to a uid and a set of groups and the
 * access question becomes answerable. On a system with no POSIX account model
 * (Windows/IIS) it cannot: there is no way to evaluate another account's access
 * to a path from here, whatever name it is given. There the honest instruction
 * is the browser, where the check runs as the web server and needs to work
 * nothing out at all.
 */
function _health_undetermined_remedy(): string
{
    if (function_exists('posix_getpwnam')) {
        return 'To get a real answer, add define(\'NEWUI_WEB_USER\', \'www-data\'); to config.php, '
             . 'substituting your own web server account (apache, nginx, http, or on shared hosting '
             . 'your own login) — or open Settings → Status in a browser, where the check runs as the '
             . 'web server itself.';
    }
    return 'This system has no POSIX account model, so one account\'s access to a path cannot be '
         . 'evaluated from another — setting NEWUI_WEB_USER would not change that. Open Settings → '
         . 'Status in a browser instead: there the check runs as the web server, and reports its '
         . 'own access directly.';
}

/**
 * The account the web server serves this application as.
 *
 * Ordered by how directly the signal answers the question actually being
 * asked, which is "who will open these files for writing":
 *
 *   1. This process, when we ARE the web server (any non-CLI SAPI). Nothing
 *      beats being the user in question — and it is the one path that sees
 *      POSIX ACLs and SELinux, because it can just ask the kernel.
 *   2. NEWUI_WEB_USER, defined in config.php or set in the environment. The
 *      operator told us; that outranks anything we can infer.
 *   3. A web server worker process running right now on this machine.
 *   4. The owner of this install's runtime directories/artefacts.
 *   5. The web server's configuration files.
 *   6. Nothing → not determined. The caller must report `unknown`.
 *
 * Never hardcodes www-data. Installs run as apache, nginx, http, _www, or —
 * on shared hosting — as the account that owns the site, and inventing a
 * default is how a correct install gets told it is broken.
 */
function health_check_web_user(bool $force = false): array
{
    static $cached = null;
    if ($cached !== null && !$force) {
        return $cached;
    }

    $out = [
        'checked'       => true,
        'name'          => null,
        'uid'           => null,
        'gids'          => [],
        'determined'    => false,
        'is_this_process' => false,
        'confidence'    => null,
        'basis'         => null,
        'note'          => '',
    ];

    try {
        $candidate = null;   // ['name'=>?string,'uid'=>?int,'basis'=>string,'confidence'=>string]

        // 1. We are the web server.
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            $me = _health_process_user();
            if ($me !== null) {
                $candidate = [
                    'name'       => $me,
                    'uid'        => function_exists('posix_geteuid') ? posix_geteuid() : null,
                    'basis'      => 'this process (SAPI ' . PHP_SAPI . ') — authoritative',
                    'confidence' => 'certain',
                ];
            }
        }

        // 2. Told to us explicitly.
        if ($candidate === null) {
            $configured = null;
            if (defined('NEWUI_WEB_USER')) {
                $configured = trim((string) constant('NEWUI_WEB_USER'));
            }
            if (($configured === null || $configured === '')) {
                $env = getenv('NEWUI_WEB_USER');
                if ($env !== false && trim($env) !== '') {
                    $configured = trim($env);
                }
            }
            if ($configured !== null && $configured !== '') {
                $candidate = [
                    'name'       => $configured,
                    'uid'        => null,
                    'basis'      => 'NEWUI_WEB_USER, configured for this install',
                    'confidence' => 'certain',
                ];
            }
        }

        // 3-5. Inference, strongest first.
        if ($candidate === null) {
            foreach ([
                ['fn' => '_health_web_user_from_proc',           'confidence' => 'high'],
                ['fn' => '_health_web_user_from_runtime_owner',  'confidence' => 'high'],
                ['fn' => '_health_web_user_from_server_config',  'confidence' => 'medium'],
            ] as $probe) {
                $hit = $probe['fn']();
                if (is_array($hit) && ($hit['name'] !== null || $hit['uid'] !== null)) {
                    $candidate = $hit + ['confidence' => $probe['confidence']];
                    break;
                }
            }
        }

        if ($candidate === null) {
            $out['note'] = 'Could not establish which account the web server runs as, so writability '
                . 'cannot be answered for it. Every directory below is reported as UNKNOWN rather than '
                . 'guessed. ' . _health_undetermined_remedy();
            $cached = $out;
            return $out;
        }

        $rec = _health_user_record($candidate['name'] ?? null, $candidate['uid'] ?? null);

        $out['name']       = $rec['name'] ?? ($candidate['name'] ?? null);
        $out['uid']        = $rec['uid']  ?? ($candidate['uid']  ?? null);
        $out['gids']       = $rec['gids'] ?? [];
        $out['basis']      = $candidate['basis'];
        $out['confidence'] = $candidate['confidence'];

        $myUid  = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $myName = _health_process_user();
        $out['is_this_process'] = ($out['uid'] !== null && $myUid !== null && (int) $out['uid'] === (int) $myUid)
            || ($out['uid'] === null && $myName !== null && $out['name'] === $myName);

        // Determined only if we can actually EVALUATE access as that account:
        // either it is us (ask the kernel) or POSIX gave us its uid + groups.
        $out['determined'] = $out['is_this_process'] || ($out['uid'] !== null && !empty($out['gids']));

        if (!$out['determined']) {
            $out['note'] = 'The web server appears to run as "' . (string) $out['name'] . '" (' . $out['basis']
                . '), but this system cannot resolve that account\'s user and group ids, so its access '
                . 'cannot be evaluated. Directories below are reported as UNKNOWN rather than guessed. '
                . _health_undetermined_remedy();
        } elseif (!$out['is_this_process']) {
            $out['note'] = 'Writability below is evaluated for "' . (string) $out['name'] . '" — '
                . $out['basis'] . ' — not for the account running this command. '
                . 'Ownership and mode bits are what is examined; POSIX ACLs and SELinux are not visible '
                . 'here, so a directory reported unwritable may still be writable through an ACL.';
        }

        $cached = $out;
        return $out;
    } catch (Throwable $e) {
        $cached = null;   // do not memoise a failure
        $out['note'] = 'Web server account could not be determined (internal error).';
        return $out;
    }
}

/**
 * PURE: would an account with these identity facts be able to write into a
 * directory with this ownership and mode?
 *
 * POSIX checks exactly ONE class and stops — owner, else group, else other —
 * so a directory you own at mode 0077 is not writable by you, however
 * permissive the group and other bits look. Creating an entry in a directory
 * needs BOTH write and search (x) on that class.
 *
 * @param array $user ['uid'=>int,'gids'=>int[]]
 */
function _health_mode_writable(int $ownerUid, int $ownerGid, int $mode, array $user): bool
{
    $uid  = (int) ($user['uid'] ?? -1);
    $gids = array_map('intval', (array) ($user['gids'] ?? []));

    if ($uid === 0) {
        return true;   // root is not subject to mode bits
    }
    $mode &= 0777;
    if ($uid === $ownerUid) {
        return ($mode & 0300) === 0300;
    }
    if (in_array($ownerGid, $gids, true)) {
        return ($mode & 0030) === 0030;
    }
    return ($mode & 0003) === 0003;
}

/**
 * Can the web server account write into $abs?
 *
 * true / false / null, where null means "not established" and must surface as
 * UNKNOWN. Asking the kernel (is_writable) is preferred whenever the account
 * in question is the one running this code, because only that path accounts
 * for ACLs and SELinux.
 */
function _health_path_writable_for(string $abs, array $webUser): ?bool
{
    try {
        if (empty($webUser['determined'])) {
            return null;
        }
        if (!empty($webUser['is_this_process'])) {
            return @is_writable($abs);
        }
        if ($webUser['uid'] === null) {
            return null;
        }
        if ((int) $webUser['uid'] === 0) {
            return true;
        }
        $st = @stat($abs);
        if (!is_array($st) || !isset($st['uid'], $st['gid'], $st['mode'])) {
            return null;
        }
        return _health_mode_writable((int) $st['uid'], (int) $st['gid'], (int) $st['mode'], $webUser);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Is a recursive chown of this path safe to suggest to an administrator?
 *
 * Standing rule in this project (docs/UPDATE-CHECKLIST.md, 2026-07-28): never
 * tell anyone to `chown -R` anything that carries .git with it. Git ≥ 2.35.2
 * refuses to operate on a repository owned by someone else (CVE-2022-24765),
 * so the reader's next `git pull` dies with "detected dubious ownership" —
 * and it was never necessary: the web server only READS program files.
 *
 * Suggestions scoped to uploads/ and cache/ are fine. Anything that is the
 * install root, an ancestor of it, or contains a .git directory is not.
 */
function _health_recursive_chown_safe(string $abs): bool
{
    try {
        if ($abs === '') {
            return false;
        }
        $norm = function (string $p): string {
            $r = @realpath($p);
            return rtrim(str_replace('\\', '/', $r !== false ? $r : $p), '/');
        };
        $target = $norm($abs);
        $root   = $norm(health_check_root());
        if ($target === '' || $target === '/') {
            return false;
        }
        if ($target === $root) {
            return false;                                   // the install itself
        }
        if (strpos($root . '/', $target . '/') === 0) {
            return false;                                   // an ancestor of the install
        }
        if (@is_dir($abs . DIRECTORY_SEPARATOR . '.git') || @is_file($abs . DIRECTORY_SEPARATOR . '.git')) {
            return false;                                   // carries a repository
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check the required-writable directories.
 *
 * Answers for the WEB SERVER account (health_check_web_user()), not for
 * whoever invoked the check — see the long note above. Severity model, which
 * is the SAME for the command line and the browser because it is computed
 * here once:
 *
 *   - exists + writable                → ok
 *   - exists + NOT writable            → critical (uploads/recordings/cache writes fail)
 *   - missing, parent writable         → warn     (created on demand at runtime)
 *   - missing, parent NOT writable     → critical (creation will fail at runtime)
 *   - web server account not known     → unknown  (never ok, never critical)
 *
 * @param array      $extraDirs Additional absolute or root-relative paths to
 *                              check (used by tests and future recordings dirs).
 * @param array|null $webUser   Override the resolved web server account. Exists
 *                              so the severity model can be driven directly —
 *                              including the not-determined case, which no host
 *                              can be relied upon to reproduce on demand.
 */
function health_check_dirs(array $extraDirs = [], ?array $webUser = null): array
{
    try {
        $root    = health_check_root();
        $webUser = $webUser ?? health_check_web_user();

        // Root-relative required-writable dirs. cache/zello-audio is the
        // Zello proxy recordings dir (hardcoded in proxy/ZelloProxyApp.php
        // as dirname(__DIR__) . '/cache/zello-audio') — this is the exact
        // dir that broke for the git-pull-as-root beta install.
        $relDirs = [
            'uploads'           => 'file attachments (api/upload.php)',
            'uploads/overlays'  => 'map image overlays (api/map-image-overlays.php)',
            'cache'             => 'general cache root',
            'cache/weather'     => 'weather tile cache (api/weather-proxy.php)',
            'cache/zello-audio' => 'Zello voice recordings (proxy/ZelloProxyApp.php)',
        ];

        $entries = [];
        $who     = $webUser['name'] !== null ? '"' . $webUser['name'] . '"' : 'the web server';

        $check = function (string $abs, string $rel, string $purpose) use (&$entries, $webUser, $who) {
            $exists   = @is_dir($abs);
            $writable = $exists ? _health_path_writable_for($abs, $webUser) : null;
            $owner    = $exists ? _health_file_owner($abs) : null;
            $mode     = null;
            if ($exists) {
                $perms = @fileperms($abs);
                if ($perms !== false) {
                    $mode = sprintf('%04o', $perms & 0777);
                }
            }

            if ($exists && $writable === true) {
                $severity = 'ok';
                $note     = '';
            } elseif ($exists && $writable === false) {
                $severity = 'critical';
                $note     = 'Directory exists but ' . $who . ' cannot write to it'
                          . ($owner !== null ? ' (owner ' . $owner . ($mode !== null ? ', mode ' . $mode : '') . ')' : '')
                          . ' — uploads, recordings and cache writes will fail.';
            } elseif ($exists) {
                $severity = 'unknown';
                $note     = 'Directory exists; whether the web server can write to it could not be established.';
            } else {
                // Missing — creatable if the nearest existing ancestor is
                // writable BY THE WEB SERVER. Being missing is normal: every
                // one of these is created on demand (api/upload.php,
                // api/weather-proxy.php, api/map-image-overlays.php,
                // proxy/ZelloProxyApp.php all mkdir their own target).
                $parent = dirname($abs);
                while ($parent !== '' && $parent !== dirname($parent) && !@is_dir($parent)) {
                    $parent = dirname($parent);
                }
                $creatable = ($parent !== '' && @is_dir($parent))
                    ? _health_path_writable_for($parent, $webUser)
                    : false;

                if ($creatable === true) {
                    $severity = 'warn';
                    $note     = 'Directory is missing but can be created on demand.';
                } elseif ($creatable === false) {
                    $severity = 'critical';
                    $note     = 'Directory is missing and ' . $who . ' cannot write its parent ('
                              . $parent . ') — the app cannot create it.';
                } else {
                    $severity = 'unknown';
                    $note     = 'Directory is missing; whether the web server could create it could not '
                              . 'be established.';
                }
            }

            $entries[] = [
                'path'         => $rel,
                'abs'          => $abs,
                'purpose'      => $purpose,
                'exists'       => (bool) $exists,
                // true / false / null. null means UNKNOWN and must never be
                // rendered as "No" — that is how a correct install gets called
                // broken.
                'writable'     => $writable,
                'writable_for' => $webUser['name'],
                'owner'        => $owner,
                'mode'         => $mode,
                'severity'     => $severity,
                'note'         => $note,
            ];
        };

        foreach ($relDirs as $rel => $purpose) {
            $check($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel), $rel, $purpose);
        }

        foreach ($extraDirs as $extra) {
            $extra = (string) $extra;
            if ($extra === '') {
                continue;
            }
            // Absolute path (unix or windows drive) vs root-relative.
            $isAbs = ($extra[0] === '/' || $extra[0] === '\\' || preg_match('/^[A-Za-z]:[\\/\\\\]/', $extra));
            $abs   = $isAbs ? $extra : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $extra);
            $check($abs, $extra, 'extra (caller-supplied)');
        }

        return [
            'checked'      => true,
            'process_user' => _health_process_user(),
            'web_user'     => $webUser,
            'dirs'         => $entries,
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'dirs check failed', 'dirs' => []];
    }
}

/**
 * Find files the CURRENT process cannot read.
 *
 * A full-tree scan is too slow per-request, so scan the highest-risk sets:
 *   (a) every file in assets/js/ and api/ — the "new JS / new endpoint
 *       404s silently" killers (an unreadable event-bus.js kills ALL
 *       real-time updates with no visible error), and
 *   (b) the 20 most-recently-modified .php/.js files anywhere under the
 *       app root — the "just pulled" set — via a bounded iterator that
 *       skips .git, vendor, uploads, cache, node_modules, backups.
 *
 * Output capped at 50 entries + a truncated flag.
 */
function health_check_unreadable(): array
{
    try {
        $root       = health_check_root();
        $rootReal   = @realpath($root) ?: $root;
        $unreadable = [];
        $scanned    = 0;
        $truncated  = false;
        $cap        = 50;

        $relPath = function (string $abs) use ($rootReal): string {
            $rel = $abs;
            if (strpos($abs, $rootReal) === 0) {
                $rel = ltrim(substr($abs, strlen($rootReal)), '/\\');
            }
            return str_replace('\\', '/', $rel);
        };

        $addIfUnreadable = function (string $abs) use (&$unreadable, &$scanned, &$truncated, $cap, $relPath): void {
            $scanned++;
            if (@is_readable($abs)) {
                return;
            }
            if (count($unreadable) >= $cap) {
                $truncated = true;
                return;
            }
            $unreadable[] = ['path' => $relPath($abs), 'issue' => 'unreadable'];
        };

        // ── (a) Targeted sets: assets/js/ and api/ ──────────────────────
        foreach (['assets/js', 'api'] as $sub) {
            $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
            if (!@is_dir($dir)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                    RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ($it as $file) {
                    if (!$file->isFile()) {
                        continue;
                    }
                    $addIfUnreadable($file->getPathname());
                    if ($scanned > 20000) {
                        $truncated = true;
                        break;
                    }
                }
            } catch (Throwable $e) {
                // The directory itself may be unreadable — that IS a finding.
                if (count($unreadable) < $cap) {
                    $unreadable[] = ['path' => $sub . '/', 'issue' => 'unreadable'];
                } else {
                    $truncated = true;
                }
            }
        }

        // ── (b) 20 most-recently-modified .php/.js files under root ─────
        $skipDirs = ['.git', 'vendor', 'uploads', 'cache', 'node_modules', 'backups', '.claude'];
        $recent   = []; // mtime-keyed candidates
        try {
            $filter = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function ($current) use ($skipDirs) {
                    /** @var SplFileInfo $current */
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), $skipDirs, true);
                    }
                    $ext = strtolower((string) $current->getExtension());
                    return ($ext === 'php' || $ext === 'js');
                }
            );
            $it = new RecursiveIteratorIterator(
                $filter,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            $visited = 0;
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $visited++;
                if ($visited > 20000) { // hard bound on per-request cost
                    break;
                }
                $mtime = 0;
                try {
                    $mtime = (int) $file->getMTime();
                } catch (Throwable $e) {
                    // Can't even stat it — very likely unreadable; probe below.
                    $mtime = PHP_INT_MAX; // force into the "recent" probe set
                }
                $recent[] = ['path' => $file->getPathname(), 'mtime' => $mtime];
            }
            usort($recent, function ($a, $b) {
                return $b['mtime'] <=> $a['mtime'];
            });
            $recent = array_slice($recent, 0, 20);
            foreach ($recent as $r) {
                // Avoid double-reporting files already caught in set (a).
                $rel = $relPath($r['path']);
                $already = false;
                foreach ($unreadable as $u) {
                    if ($u['path'] === $rel) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    $addIfUnreadable($r['path']);
                }
            }
        } catch (Throwable $e) {
            // Bounded scan failed (permissions on root?) — report nothing
            // extra rather than crash; set (a) results still stand.
        }

        return [
            'checked'    => true,
            'scanned'    => $scanned,
            'unreadable' => $unreadable,
            'truncated'  => $truncated,
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'unreadable scan failed', 'unreadable' => [], 'truncated' => false];
    }
}

/**
 * Report opcache configuration as seen by THIS SAPI.
 *
 * WARN when opcache is enabled with validate_timestamps off: code changes
 * on disk will NOT take effect until the web server / php-fpm is reloaded.
 * (Even with validate_timestamps on, revalidate_freq seconds may pass
 * before a change is picked up — informational.)
 *
 * The definitive "server is executing stale code" signal is
 * health_check_version_match(), not this.
 */
function health_check_opcache(): array
{
    try {
        $available = function_exists('opcache_get_status');
        $enabled   = false;
        if ($available) {
            $enabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
            if (PHP_SAPI === 'cli') {
                $enabled = filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN);
            }
        }

        $vtRaw = ini_get('opcache.validate_timestamps');
        $validateTimestamps = ($vtRaw === false) ? null : filter_var($vtRaw, FILTER_VALIDATE_BOOLEAN);
        $freqRaw = ini_get('opcache.revalidate_freq');
        $revalidateFreq = ($freqRaw === false) ? null : (int) $freqRaw;

        $severity = 'ok';
        $note     = '';
        if ($enabled && $validateTimestamps === false) {
            $severity = 'warn';
            $note     = 'opcache is enabled with validate_timestamps=0 — code changes on disk will NOT take effect until the web server or php-fpm is reloaded (sudo systemctl reload apache2 / php-fpm).';
        }

        $mtime = @filemtime(__FILE__);

        return [
            'checked'             => true,
            'sapi'                => PHP_SAPI,
            'enabled'             => (bool) $enabled,
            'validate_timestamps' => $validateTimestamps,
            'revalidate_freq'     => $revalidateFreq,
            'build'               => HEALTH_CHECK_BUILD,
            'file_mtime'          => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
            'severity'            => $severity,
            'note'                => $note,
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'opcache check failed', 'severity' => 'ok'];
    }
}

/**
 * Parse a define('CONST', 'literal') value fresh from a file ON DISK.
 * Returns the literal string or null.
 */
function _health_parse_define(string $file, string $constName): ?string
{
    try {
        if (!@is_file($file) || !@is_readable($file)) {
            return null;
        }
        $src = @file_get_contents($file, false, null, 0, 65536);
        if ($src === false) {
            return null;
        }
        $pat = '/define\s*\(\s*[\'"]' . preg_quote($constName, '/') . '[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/';
        if (preg_match($pat, $src, $m)) {
            return $m[1];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Definitive opcache-staleness detector: compare constants as COMPILED
 * into the running process against the same literals parsed FRESH from
 * disk (file_get_contents bypasses opcache).
 *
 *   1. NEWUI_VERSION — a legacy config.php may still define it as a literal.
 *      Since 2026-07 the canonical version is the git-tracked `VERSION` file
 *      (see inc/version.php), which is read at RUNTIME — so on a modern
 *      install this arm can no longer detect staleness (a file read always
 *      reflects disk). It is kept because installs predating the change do
 *      still carry the literal, and because reporting the resolved version +
 *      its source is useful on the health card either way.
 *   2. HEALTH_CHECK_BUILD — self-probe against this very file, which IS
 *      git-tracked: after a pull that updates inc/health-check.php, a
 *      stale opcache serves the old compiled constant while the disk
 *      regex shows the new one. THIS is the reliable staleness detector.
 *
 * Either mismatch → CRITICAL: "server is executing stale code; reload
 * apache2/php-fpm."
 */
function health_check_version_match(): array
{
    try {
        $root = health_check_root();

        // ── NEWUI_VERSION: running vs disk ───────────────────────────────
        $running     = defined('NEWUI_VERSION') ? (string) NEWUI_VERSION : null;
        $versionFile = null;
        $onDisk      = null;
        foreach (['config.php', 'inc/version.php', 'config.example.php'] as $cand) {
            $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cand);
            $val = _health_parse_define($abs, 'NEWUI_VERSION');
            if ($val !== null) {
                $versionFile = $cand;
                $onDisk      = $val;
                break;
            }
        }
        // No literal define anywhere → a current install, whose version comes
        // from the tracked VERSION file. Report that as the source.
        if ($onDisk === null) {
            $verFile = $root . DIRECTORY_SEPARATOR . 'VERSION';
            $raw     = is_file($verFile) ? @file_get_contents($verFile, false, null, 0, 256) : false;
            if (is_string($raw) && trim($raw) !== '') {
                $versionFile = 'VERSION';
                $onDisk      = trim(strtok($raw, "\r\n") ?: '');
                if ($running === null) {
                    $running = $onDisk;
                }
            }
        }
        // Only meaningful when both sides resolved.
        $versionComparable = ($running !== null && $onDisk !== null);
        $versionMatch      = $versionComparable ? ($running === $onDisk) : null;

        // A pre-2026-07 config.php may still pin a define('NEWUI_VERSION', …)
        // from its install date. Harmless — every reader calls newui_version(),
        // which prefers the tracked file — but worth telling the admin so the
        // dead line can go. Advisory only: severity stays 'ok'.
        // NOTE: deliberately does NOT touch $versionMatch. running-vs-disk stays
        // a pure staleness comparison (both sides read the same config.php
        // literal); the pin is reported separately.
        $configPin = function_exists('newui_version_config_pin') ? newui_version_config_pin() : null;
        $reported  = function_exists('newui_version') ? newui_version() : $running;

        // ── Self-probe: HEALTH_CHECK_BUILD running vs disk ───────────────
        $probeRunning = HEALTH_CHECK_BUILD;
        $probeOnDisk  = _health_parse_define(__FILE__, 'HEALTH_CHECK_BUILD');
        $probeMatch   = ($probeOnDisk !== null) ? ($probeRunning === $probeOnDisk) : null;

        $severity = 'ok';
        $note     = '';
        if ($versionMatch === false || $probeMatch === false) {
            $severity = 'critical';
            $note     = 'The server is EXECUTING STALE CODE: the version compiled into the running process differs from the file on disk. Reload the web server: sudo systemctl reload apache2   (or: sudo systemctl reload php8.2-fpm)';
        }

        if ($configPin !== null && $note === '') {
            $note = 'config.php still pins define(\'NEWUI_VERSION\', \'' . $configPin . '\') from when this '
                  . 'install was created. TicketsCAD now reports the git-tracked VERSION file (' . $reported
                  . '), so nothing is broken — but that line is dead and can be deleted (or replaced with '
                  . "require_once __DIR__ . '/inc/version.php';).";
        }

        return [
            'checked'       => true,
            'version_file'  => $versionFile,
            'running'       => $running,
            'on_disk'       => $onDisk,
            'reported'      => $reported,
            'config_pin'    => $configPin,
            'match'         => $versionMatch,
            'probe_file'    => 'inc/health-check.php',
            'probe_running' => $probeRunning,
            'probe_on_disk' => $probeOnDisk,
            'probe_match'   => $probeMatch,
            'severity'      => $severity,
            'note'          => $note,
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'version check failed', 'severity' => 'ok', 'match' => null];
    }
}

/**
 * Bundle every check + a summary the banner / status card can key off.
 * Summary counts: each problem dir, each unreadable file, an opcache
 * warn, and a version mismatch each count once.
 */
/**
 * Composer dependency presence. `vendor/` is gitignored and recreated by
 * `composer install`; if an admin deploys the code without running it, several
 * optional PHP features silently no-op. The most common casualty is Web Push
 * (minishlink/web-push): push can be ENABLED with the library absent, so
 * browsers subscribe fine but notifications are never delivered (GH #8, found
 * via the Diagnostics page 2026-07-13). This makes the gap visible on the
 * installation health page. Pure: filesystem-only, no DB, no autoloader
 * dependency (is_dir on the package path is the reliable signal even when the
 * composer autoloader hasn't been registered in this request).
 */
function health_check_dependencies(): array
{
    try {
        $root     = health_check_root();
        $autoload = $root . '/vendor/autoload.php';
        $hasVendor = is_file($autoload);

        // composer package → its installed dir → the feature it powers.
        $libs = [
            ['pkg' => 'minishlink/web-push', 'dir' => 'vendor/minishlink/web-push', 'class' => 'Minishlink\\WebPush\\WebPush', 'feature' => 'Web Push notifications'],
            ['pkg' => 'firebase/php-jwt',    'dir' => 'vendor/firebase/php-jwt',    'class' => 'Firebase\\JWT\\JWT',        'feature' => 'External API bearer tokens'],
            ['pkg' => 'cboden/ratchet',      'dir' => 'vendor/cboden/ratchet',      'class' => 'Ratchet\\Server\\IoServer', 'feature' => 'Realtime WebSocket proxy (Zello/DMR)'],
        ];
        $entries = [];
        $missing = 0;
        foreach ($libs as $l) {
            $present = is_dir($root . '/' . $l['dir']) || class_exists($l['class']);
            if (!$present) { $missing++; }
            $entries[] = ['package' => $l['pkg'], 'feature' => $l['feature'], 'present' => $present];
        }
        // Missing vendor/ or any optional lib is a WARN here (features degraded,
        // not a crash). The push-enabled-but-missing → CRITICAL elevation lives
        // in the Notifications settings panel + api/diagnostics.php, which read
        // the push_enabled setting.
        $severity = (!$hasVendor || $missing > 0) ? 'warn' : 'ok';
        return [
            'checked'    => true,
            'has_vendor' => $hasVendor,
            'libraries'  => $entries,
            'missing'    => $missing,
            'severity'   => $severity,
            'remedy'     => $severity === 'ok' ? ''
                : 'Run `composer install --no-dev --optimize-autoloader` in the install directory.',
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'dependency check failed', 'severity' => 'ok', 'libraries' => []];
    }
}

/**
 * ── Web exposure ────────────────────────────────────────────────────────────
 *
 * Ask this install, over HTTP, whether the directories that must never be
 * served actually are.
 *
 * Every other check here reads the local filesystem. None of them could have
 * caught what happened on 2026-07-30: the code and the files were exactly as
 * intended, and `GET /backups/<archive>.zip` still returned a 110 MB database
 * dump to the public internet, because the WEB SERVER had never been told not
 * to. That is not a fact about the install directory; it is a fact about the
 * server config, and the only honest way to learn it is to make the request.
 *
 * Why a self-probe rather than a line in a document: an admin who reads
 * docs/WEB-SERVER-HARDENING.md and follows it correctly still has no way to
 * know a later nginx upgrade, a vhost edit, or a move to a different host did
 * not quietly undo it. This runs on every visit to Settings → Status.
 *
 * Reporting rules, in order of importance:
 *   - A path that answers 200 is CRITICAL. It is reachable, full stop.
 *   - A path we could not reach at all is 'unknown', never 'ok'. A refused
 *     connection means the probe failed, not that the site is safe — the
 *     server may only be reachable through a tunnel or an external proxy.
 *   - 403 / 404 / 401 are all a pass. Which one an install returns depends on
 *     whether mod_rewrite or mod_alias won, and it does not matter.
 *
 * Cached for 12 hours in cache/, because this is on a page an admin may
 * refresh repeatedly and each call is three outbound requests.
 *
 * @param bool $force Re-probe even if a fresh cached result exists.
 */
function health_check_web_exposure(bool $force = false): array
{
    try {
        $base = _health_self_base_url();
        if ($base === null) {
            return [
                'checked'  => false,
                'severity' => 'ok',
                'probes'   => [],
                'error'    => 'Cannot work out this install\'s own URL from the '
                            . 'command line. Open Settings → Status in a browser, '
                            . 'or run the curl checks in docs/WEB-SERVER-HARDENING.md.',
            ];
        }

        $cacheFile = health_check_root() . '/cache/health-web-exposure.json';
        if (!$force && is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached)
                && ($cached['base'] ?? '') === $base
                && (time() - (int) ($cached['at'] ?? 0)) < 43200) {
                $cached['result']['cached'] = true;
                return $cached['result'];
            }
        }

        // What to ask for. sql/ and tools/ always exist, so a 200 on either is
        // unambiguous. The backups probe is the one that matters most, so when
        // an archive really is sitting in a web-served directory we ask for THAT
        // file by name rather than for the directory index — a server with
        // indexes off but no deny rule hands out the archive while answering 403
        // for the directory.
        $probes = [
            ['path' => 'sql/run_migrations.php', 'label' => 'sql/ (database migration scripts)'],
            ['path' => 'tools/',                 'label' => 'tools/ (maintenance scripts)'],
        ];

        $archive = null;
        try {
            if (defined('BACKUP_DIR_LEGACY') && is_dir(BACKUP_DIR_LEGACY)) {
                $found = glob(rtrim(BACKUP_DIR_LEGACY, '/\\') . '/ticketscad-*.{zip,gz}', GLOB_BRACE) ?: [];
                if (!empty($found)) { $archive = basename($found[0]); }
            }
        } catch (Throwable $e) { /* fall through to the directory probe */ }

        $probes[] = $archive !== null
            ? ['path' => 'backups/' . $archive, 'label' => 'backups/ (a real database archive)']
            : ['path' => 'backups/',            'label' => 'backups/ (database archives)'];

        $results  = [];
        $exposed  = 0;
        $unknown  = 0;
        foreach ($probes as $p) {
            $code = _health_probe_head($base . '/' . $p['path']);
            $state = ($code === null) ? 'unknown' : (($code >= 200 && $code < 300) ? 'exposed' : 'blocked');
            if ($state === 'exposed') { $exposed++; }
            if ($state === 'unknown') { $unknown++; }
            $results[] = [
                'path'   => $p['path'],
                'label'  => $p['label'],
                'url'    => $base . '/' . $p['path'],
                'status' => $code,
                'state'  => $state,
            ];
        }

        $severity = $exposed > 0 ? 'critical' : ($unknown === count($probes) ? 'warn' : 'ok');
        $out = [
            'checked'  => true,
            'cached'   => false,
            'base_url' => $base,
            'probes'   => $results,
            'exposed'  => $exposed,
            'unknown'  => $unknown,
            'severity' => $severity,
            'summary'  => $exposed > 0
                ? $exposed . ' director' . ($exposed === 1 ? 'y is' : 'ies are')
                    . ' reachable over HTTP that should not be'
                : ($unknown === count($probes)
                    ? 'Could not reach this install from itself — check by hand '
                        . '(docs/WEB-SERVER-HARDENING.md)'
                    : 'No non-public directory answered over HTTP'),
            'remedy'   => $exposed > 0
                ? 'Apache: confirm AllowOverride is All or FileInfo so the shipped '
                    . '.htaccess is read. nginx: install '
                    . 'docs/nginx/ticketscad-hardening.conf. IIS: add the hidden '
                    . 'segments. Full instructions in docs/WEB-SERVER-HARDENING.md. '
                    . 'If backups/ answered 200, treat the database as disclosed — '
                    . 'see docs/security/advisory-2026-07-30-exposed-directories.md.'
                : '',
        ];

        try {
            $cacheDir = dirname($cacheFile);
            if (is_dir($cacheDir) && is_writable($cacheDir)) {
                @file_put_contents($cacheFile, json_encode(
                    ['at' => time(), 'base' => $base, 'result' => $out]
                ));
            }
        } catch (Throwable $e) { /* caching is a nicety, never a requirement */ }

        return $out;
    } catch (Throwable $e) {
        return ['checked' => false, 'severity' => 'ok', 'probes' => [],
                'error' => 'web exposure check failed'];
    }
}

/**
 * Are any database archives sitting in a directory the web server serves?
 *
 * The filesystem half of the web-exposure check, and the half that also works
 * from the CLI. v4.2.3 moved the default backup directory above the web root,
 * but an install that has been running for a while still has its older archives
 * in the old place — nothing moves them automatically, because the ownership and
 * the free space are the operator's to judge. This is what tells them the job is
 * outstanding, and how many files it is about.
 *
 * CRITICAL when archives are present in a served directory: one of those files
 * is a complete copy of the database.
 */
function health_check_backups(): array
{
    try {
        if (!defined('NEWUI_ROOT')) {
            return ['checked' => false, 'severity' => 'ok', 'dirs' => []];
        }
        require_once __DIR__ . '/backup.php';

        $dirs = [];
        try {
            require_once __DIR__ . '/backup_schedule.php';
            $dirs = backup_dirs_all();
            $active = backup_dir();
        } catch (Throwable $e) {
            // No database (fresh install, CLI without config) — fall back to the
            // compiled-in paths so the check still says something useful.
            $dirs   = array_values(array_unique(array_filter(
                [BACKUP_DIR, defined('BACKUP_DIR_LEGACY') ? BACKUP_DIR_LEGACY : null]
            )));
            $active = BACKUP_DIR;
        }

        $entries = [];
        $exposedArchives = 0;
        foreach ($dirs as $d) {
            if (!is_dir($d)) { continue; }
            $files = glob(rtrim($d, '/\\') . '/ticketscad-*.{zip,gz}', GLOB_BRACE) ?: [];
            $web   = backup_dir_is_web_served($d);
            if ($web) { $exposedArchives += count($files); }
            $entries[] = [
                'dir'        => $d,
                'active'     => rtrim(str_replace('\\', '/', $d), '/')
                                === rtrim(str_replace('\\', '/', $active), '/'),
                'web_served' => $web,
                'archives'   => count($files),
            ];
        }

        $activeWeb = backup_dir_is_web_served($active);
        $severity  = ($exposedArchives > 0 || $activeWeb) ? 'critical' : 'ok';

        $summary = 'Backups are written outside the web root (' . $active . ')';
        if ($activeWeb) {
            $summary = 'Backups are being written INSIDE the web root (' . $active . ')';
        } elseif ($exposedArchives > 0) {
            $summary = $exposedArchives . ' older archive' . ($exposedArchives === 1 ? '' : 's')
                     . ' still in the web-served backups directory';
        }

        return [
            'checked'          => true,
            'active_dir'       => $active,
            'active_web_served'=> $activeWeb,
            'default_dir'      => BACKUP_DIR,
            'legacy_dir'       => defined('BACKUP_DIR_LEGACY') ? BACKUP_DIR_LEGACY : null,
            'dirs'             => $entries,
            'exposed_archives' => $exposedArchives,
            'severity'         => $severity,
            'summary'          => $summary,
            'remedy'           => $severity === 'ok' ? ''
                : 'Move the archives above the web root and delete the originals:'
                    . "\n  mkdir -p " . BACKUP_DIR
                    . "\n  mv " . (defined('BACKUP_DIR_LEGACY') ? BACKUP_DIR_LEGACY : 'backups') . "/ticketscad-* " . BACKUP_DIR . '/'
                    . "\n  sudo chown -R \"\$(id -un)\":www-data " . BACKUP_DIR . ' && sudo chmod 2770 ' . BACKUP_DIR
                    . "\nThen confirm nothing is left: ls " . (defined('BACKUP_DIR_LEGACY') ? BACKUP_DIR_LEGACY : 'backups')
                    . "\nIf the directory was reachable over HTTP, treat the database as disclosed — "
                    . 'see docs/security/advisory-2026-07-30-exposed-directories.md.',
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'severity' => 'ok', 'dirs' => [],
                'error' => 'backup location check failed'];
    }
}

/**
 * This install's own base URL, without a trailing slash, or null when it
 * cannot be determined (typically the CLI, where there is no request to read
 * it from).
 */
function _health_self_base_url(): ?string
{
    try {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if ($host === '') {
            $env = getenv('NEWUI_BASE_URL');
            return ($env !== false && $env !== '') ? rtrim($env, '/') : null;
        }
        // Honour the reverse proxy: the origin may speak plain HTTP while the
        // visitor is on HTTPS. Probing the wrong scheme gets a redirect, which
        // reads as "blocked" and would hide a real exposure.
        $scheme = is_https() ? 'https' : 'http';

        // Application prefix: /newui for a subdirectory install, '' at a vhost root.
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
        // API endpoints live one level down; step back up to the app root.
        if (substr($dir, -4) === '/api') { $dir = substr($dir, 0, -4); }
        if ($dir === '.' || $dir === '/') { $dir = ''; }

        return $scheme . '://' . $host . $dir;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * HEAD a URL and return the status code, or null when the request could not be
 * made at all. HEAD deliberately, not GET: one of the probe targets may be a
 * multi-hundred-megabyte database archive.
 */
function _health_probe_head(string $url): ?int
{
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) { return null; }
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,   // a redirect is not a pass
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => false,   // self-signed / internal names
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'TicketsCAD-health-check',
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return $code > 0 ? $code : null;
        }

        $ctx = stream_context_create(['http' => [
            'method'          => 'HEAD',
            'timeout'         => 5,
            'follow_location' => 0,
            'ignore_errors'   => true,
            'header'          => "User-Agent: TicketsCAD-health-check\r\n",
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $headers = @get_headers($url, false, $ctx);
        if (!is_array($headers) || empty($headers[0])) { return null; }
        if (preg_match('#\s(\d{3})\s#', ' ' . $headers[0] . ' ', $m)) {
            return (int) $m[1];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Does the database have the columns this version of the code writes to?
 *
 * Phase 125 (2026-07-26). Every other check here is about FILES. None of them
 * could see the failure that actually cost a beta tester his week: a database
 * whose structure had fallen behind the code, so saving a team returned an
 * unexplained HTTP 400. The migration runner could not see it either — its
 * tracker records whether a script RAN, not whether its schema still exists.
 *
 * CRITICAL rather than warn: a missing column is not degraded, it is a screen
 * that cannot save.
 */
function health_check_schema(): array
{
    try {
        require_once __DIR__ . '/schema-verify.php';
        $v = schema_verify();

        if (!$v['available']) {
            // Cannot verify (no manifest, unreadable information_schema).
            // Report it, but never as a failure of the user's install.
            return [
                'checked'  => false,
                'error'    => $v['error'] ?? 'schema could not be verified',
                'severity' => 'ok',
                'summary'  => schema_verify_summary($v),
            ];
        }

        return [
            'checked'              => true,
            'ok'                   => $v['ok'],
            'checked_tables'       => $v['checked_tables'],
            'checked_columns'      => $v['checked_columns'],
            'missing_tables'       => $v['missing_tables'],
            'missing_columns'      => $v['missing_columns'],
            'missing_column_count' => $v['missing_column_count'],
            'severity'             => $v['ok'] ? 'ok' : 'critical',
            'summary'              => schema_verify_summary($v),
            'remedy'               => $v['ok'] ? '' : schema_verify_repair_hint(),
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'schema check failed', 'severity' => 'ok'];
    }
}

/**
 * Are the background jobs actually being run by anything?
 *
 * Added 2026-07-29, after two scheduled ticks were found to have never
 * executed in the seven weeks since they were installed. They had been
 * dropped into /etc/cron.d on hosts with no cron daemon, which fails
 * silently, and no surface anywhere reported a job's last run — so there
 * was no observation that could have distinguished "running fine" from
 * "never started". This is that observation.
 *
 * Delegates to sched_jobs_status(); shaped like the other sections.
 */
function health_check_scheduled_jobs(): array
{
    try {
        require_once __DIR__ . '/scheduled-jobs.php';
        return sched_jobs_status();
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'scheduled job check failed',
                'jobs' => [], 'severity' => 'ok'];
    }
}

/**
 * Address lookup — is it configured coherently, and is it actually working?
 *
 * WHY THIS EXISTS, AND WHY IT DOES NOT PROBE THE NETWORK BY DEFAULT.
 *
 * Geocoding moved from the dispatcher's browser to this server on 2026-07-31
 * (see inc/geocode.php). That is the right place for it — it is the only place
 * that can cache, throttle, identify itself, keep an API key off a browser, or
 * reach a geocoder on your own network. But it moves the dependency: an
 * install where the BROWSERS have internet and the PHP process does not now
 * has no address lookup, and the usual cause is invisible. On Rocky/RHEL,
 * SELinux ships `httpd_can_network_connect` OFF, so a stock install cannot
 * make outbound HTTP from PHP at all and says nothing about it.
 *
 * The obvious answer — fire a test lookup whenever someone opens the Status
 * page — is the wrong one, twice over. It puts an outbound call with a
 * multi-second worst case on a page load, and this project has already shipped
 * a check that reported every fresh install as critically broken because it
 * inferred a fault from configuration rather than from evidence (commit
 * aed9d41). A check that cries wolf gets muted, and then it is the silent one.
 *
 * So this reports EVIDENCE:
 *   * configuration that cannot possibly work (a keyed provider with no key,
 *     a self-hosted one with no address) — a fact, not a guess;
 *   * the circuit breaker, which records real failures from real lookups. If
 *     PHP cannot reach the provider, three genuine dispatcher lookups open it
 *     and this turns critical, naming the transport error.
 * and leaves the on-demand probe to the Test button in Settings, where an
 * administrator has asked for it.
 *
 * @param bool $probe true = actually perform a live lookup (Settings' Test
 *                    button path). Off by default: see above.
 */
function health_check_geocoding(bool $probe = false): array
{
    try {
        if (!function_exists('geocode_settings')) {
            $inc = __DIR__ . '/geocode.php';
            if (!is_file($inc)) {
                return ['checked' => false, 'severity' => 'ok',
                        'error' => 'geocoding support is not installed'];
            }
            require_once $inc;
        }
        if (!function_exists('get_variable')) {
            return ['checked' => false, 'severity' => 'ok',
                    'error' => 'settings are not available in this context'];
        }

        $settings = geocode_settings();
        $cfg      = geocode_client_config($settings);
        $policy   = geocode_policy()[$settings['provider']] ?? [];

        $severity = 'ok';
        $notes    = [];

        if ($cfg['mode'] === 'off') {
            // A deliberate choice on an air-gapped install. Report it, do not
            // grade it — reporting a chosen configuration as a fault is how a
            // health page teaches people to ignore it.
            $notes[] = 'Address lookup is switched off. Dispatchers set incident locations by '
                     . 'clicking the map.';
        } else {
            if (!empty($policy['needs_key']) && trim((string) $settings['api_key']) === '') {
                $severity = 'critical';
                $notes[] = ($policy['label'] ?? $settings['provider']) . ' needs an API key and none '
                         . 'is saved. Every address lookup will fail until one is set in '
                         . 'Settings → API Keys.';
            }
            if (!empty($policy['needs_url']) && geocode_base_url((string) $settings['url']) === '') {
                $severity = 'critical';
                $notes[] = ($policy['label'] ?? $settings['provider']) . ' needs the address of your '
                         . 'own geocoding server and none is saved. Every address lookup will fail '
                         . 'until one is set in Settings → API Keys → Geocoding.';
            }
            if ($cfg['requested'] !== $cfg['mode'] && $cfg['reason'] !== '') {
                $notes[] = 'Configured as "' . $cfg['requested'] . '", running as "' . $cfg['mode']
                         . '": ' . $cfg['reason'];
            }
            if ($cfg['mode'] === 'server' && !function_exists('curl_init')) {
                if ($severity === 'ok') { $severity = 'warn'; }
                $notes[] = 'The PHP cURL extension is not installed, so lookups fall back to the '
                         . 'stream wrapper, which cannot enforce a separate connect timeout. '
                         . 'Installing php-curl makes failures faster and more predictable.';
            }
        }

        // The evidence half: what real lookups have actually done.
        $breaker = geocode_breaker_read((string) $settings['provider']);
        $decided = geocode_breaker_decide($breaker, time());
        if ($decided['open']) {
            $severity = 'critical';
            $notes[] = 'Address lookup is failing: ' . $decided['fails'] . ' consecutive failures'
                     . ($breaker['last_error'] !== '' ? ' (' . $breaker['last_error'] . ')' : '')
                     . '. If the internet is up, check that PHP itself is allowed to make outbound '
                     . 'connections — on Rocky/RHEL that is SELinux\'s httpd_can_network_connect, '
                     . 'which is off by default.';
        }

        $result = [
            'checked'    => true,
            'mode'       => $cfg['mode'],
            'requested'  => $cfg['requested'],
            'provider'   => $settings['provider'],
            'label'      => (string) ($policy['label'] ?? $settings['provider']),
            'verified'   => (string) ($policy['verified'] ?? ''),
            'cache'      => geocode_cache_usage(),
            'breaker'    => ['open' => $decided['open'], 'fails' => $decided['fails'],
                             'retry_in' => $decided['retry_in'],
                             'last_error' => $breaker['last_error']],
            'severity'   => $severity,
            'note'       => implode(' ', $notes),
        ];

        if ($probe && $cfg['mode'] !== 'off') {
            $t0 = microtime(true);
            $res = geocode_lookup('search', ['q' => '1600 Pennsylvania Ave NW, Washington, DC', 'limit' => 1]);
            $result['probe'] = [
                'ok' => (bool) $res['ok'], 'ms' => (int) round((microtime(true) - $t0) * 1000),
                'source' => $res['source'], 'count' => count($res['results']),
                'message' => $res['message'],
            ];
            if (!$res['ok']) {
                $result['severity'] = 'critical';
                $result['note'] = trim($result['note'] . ' Live test failed: ' . $res['message']);
            }
        }

        return $result;
    } catch (Throwable $e) {
        // Never let the health page be the thing that breaks. "Could not tell"
        // is its own answer and must not read as "fine" or as "broken".
        return ['checked' => false, 'severity' => 'ok', 'error' => 'geocoding check failed'];
    }
}

function health_check_all(): array
{
    try {
        $dirs       = health_check_dirs();
        $unreadable = health_check_unreadable();
        $opcache    = health_check_opcache();
        $version    = health_check_version_match();
        $deps       = health_check_dependencies();
        $schema     = health_check_schema();
        $jobs       = health_check_scheduled_jobs();
        $backups    = health_check_backups();
        $exposure   = health_check_web_exposure();
        $geocoding  = health_check_geocoding();

        $critical = 0;
        $warn     = 0;
        // A third bucket, deliberately not folded into either of the other
        // two. "We could not tell" is a distinct answer from "fine" and from
        // "broken", and collapsing it into one of them is how this check
        // came to report a healthy install as critically broken.
        $unknown  = 0;

        foreach (($dirs['dirs'] ?? []) as $d) {
            if (($d['severity'] ?? '') === 'critical') {
                $critical++;
            } elseif (($d['severity'] ?? '') === 'warn') {
                $warn++;
            } elseif (($d['severity'] ?? '') === 'unknown') {
                $unknown++;
            }
        }
        $critical += count($unreadable['unreadable'] ?? []);
        if (($opcache['severity'] ?? '') === 'warn') {
            $warn++;
        } elseif (($opcache['severity'] ?? '') === 'critical') {
            $critical++;
        }
        if (($version['severity'] ?? '') === 'critical') {
            $critical++;
        }
        if (($deps['severity'] ?? '') === 'warn') {
            $warn++;
        }
        if (($schema['severity'] ?? '') === 'critical') {
            $critical++;
        } elseif (($schema['severity'] ?? '') === 'warn') {
            $warn++;
        }
        if (($jobs['severity'] ?? '') === 'critical') {
            $critical++;
        } elseif (($jobs['severity'] ?? '') === 'warn') {
            $warn++;
        }
        foreach ([$backups, $exposure, $geocoding] as $sec) {
            if (($sec['severity'] ?? '') === 'critical') {
                $critical++;
            } elseif (($sec['severity'] ?? '') === 'warn') {
                $warn++;
            }
        }

        return [
            'checked'      => true,
            'generated_at' => date('Y-m-d H:i:s'),
            'sapi'         => PHP_SAPI,
            'process_user' => _health_process_user(),
            'web_user'     => $dirs['web_user'] ?? health_check_web_user(),
            'dirs'         => $dirs,
            'unreadable'   => $unreadable,
            'opcache'      => $opcache,
            'version'      => $version,
            'dependencies' => $deps,
            'schema'       => $schema,
            'scheduled_jobs' => $jobs,
            'backups'      => $backups,
            'web_exposure' => $exposure,
            'geocoding'    => $geocoding,
            'summary'      => ['critical' => $critical, 'warn' => $warn, 'unknown' => $unknown],
        ];
    } catch (Throwable $e) {
        return [
            'checked' => false,
            'error'   => 'health check failed',
            'summary' => ['critical' => 0, 'warn' => 0, 'unknown' => 0],
        ];
    }
}
