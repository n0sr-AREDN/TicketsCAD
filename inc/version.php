<?php
/**
 * Canonical application version — the ONE place the deployed version is read
 * from, and the only one that a `git pull` can actually change.
 *
 * THE BUG THIS EXISTS TO FIX
 * --------------------------
 * Until 2026-07, `NEWUI_VERSION` was defined *only* in `config.php`. That file
 * is per-install and gitignored, so git never touches it: after a completely
 * correct `git pull` the About page still showed whatever version was current
 * on the day the install was created. Eric's own install sat at `4.0.0-dev`
 * while the code on disk was 4.1.3, and a beta tester following the update
 * video was told "check Help → About to prove the update worked" — a check
 * that could never pass. (Reported in the mgitwin/mgitnix video QA, finding C1.)
 *
 * PRECEDENCE — the tracked VERSION file always wins
 * -------------------------------------------------
 * A version identifies the CODE, not the installation. `config.php` is
 * install-state that git cannot update, so honouring a value found there would
 * permanently pin every install that already exists to its install-time
 * version — which is exactly the defect. Therefore:
 *
 *   1. `VERSION` (git-tracked, next to config.php) — authoritative.
 *   2. `NEWUI_VERSION` from config.php — FALLBACK ONLY, used when the VERSION
 *      file is missing or unreadable (partial deploy, odd packaging). This is
 *      the backward-compatibility path, not an override.
 *   3. `'unknown'` — never fatal.
 *
 * Nothing in the app "wins" by defining the constant first any more, because
 * every reader calls newui_version() rather than reading the constant. Older
 * installs therefore converge on the right answer with no edit to config.php.
 *
 * The constant is still defined here (when config.php has not already defined
 * it) so third-party/legacy code that reads `NEWUI_VERSION` keeps working, and
 * so a fresh install gets identical values from both spellings.
 *
 * Loaded from `inc/functions.php`, which BOTH the current and every historical
 * `config.php` require — so newui_version() is available on every code path
 * without the admin touching their config.
 */

if (!function_exists('newui_version')) {
    /**
     * Absolute path to the tracked VERSION file (repo root, one level up from inc/).
     * Deliberately derived from __DIR__, not NEWUI_ROOT: this file must work
     * even when it is included before config.php has defined anything.
     */
    function newui_version_file(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'VERSION';
    }

    /**
     * The deployed code version, e.g. "4.1.3".
     *
     * Result is memoised per request — this is called dozens of times per page
     * (every asset cache-buster) and must not cost a stat + read each time.
     */
    function newui_version(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $v = newui_version_read_file();
        if ($v === null && defined('NEWUI_VERSION')) {
            $v = trim((string) NEWUI_VERSION);   // legacy config.php fallback
        }

        $cached = ($v !== null && $v !== '') ? $v : 'unknown';
        return $cached;
    }

    /**
     * Read + validate the VERSION file. Returns null when absent, unreadable,
     * empty, or obviously not a version string (so a stray file can never inject
     * markup or a multi-kilobyte blob into a <title> tag).
     */
    function newui_version_read_file(): ?string
    {
        $file = newui_version_file();
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $raw = @file_get_contents($file, false, null, 0, 256);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        // First non-empty line only; a trailing newline is normal.
        $line = trim(strtok($raw, "\r\n") ?: '');
        if ($line === '' || strlen($line) > 40) {
            return null;
        }
        // Semver-ish: digits, dots, dashes, plus, underscores, letters.
        if (!preg_match('/^[0-9A-Za-z._+\-]+$/', $line)) {
            return null;
        }
        return $line;
    }

    /**
     * Where the answer came from: 'file', 'config' or 'unknown'. Used by the
     * health check / Diagnostics to explain a config.php that still pins an old
     * value (harmless now, but worth cleaning up).
     */
    function newui_version_source(): string
    {
        if (newui_version_read_file() !== null) {
            return 'file';
        }
        return defined('NEWUI_VERSION') ? 'config' : 'unknown';
    }

    /**
     * The stale `define('NEWUI_VERSION', …)` a legacy config.php may still
     * carry, when it disagrees with the tracked VERSION file. NULL when there is
     * nothing to report. Advisory only — the app already reports the right
     * version regardless.
     */
    function newui_version_config_pin(): ?string
    {
        if (!defined('NEWUI_VERSION')) {
            return null;
        }
        $pinned = trim((string) NEWUI_VERSION);
        $file   = newui_version_read_file();
        if ($file === null || $pinned === '' || $pinned === $file) {
            return null;
        }
        return $pinned;
    }
}

// Keep the historical constant working for anything that still reads it.
// A config.php that already defined it wins here only in the sense that PHP
// forbids redefinition — every reader in the app calls newui_version().
if (!defined('NEWUI_VERSION')) {
    define('NEWUI_VERSION', newui_version());
}
