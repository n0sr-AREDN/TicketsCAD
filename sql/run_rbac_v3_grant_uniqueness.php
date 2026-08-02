<?php
/**
 * RBAC v3 — make the user_roles uniqueness constraint actually constrain.
 * ---------------------------------------------------------------------
 * Phase 129 (2026-07-29).
 *
 * THE DEFECT
 *
 * `user_roles` has carried a UNIQUE key over a NULLable column since the
 * very first RBAC schema:
 *
 *     rbac.sql        UNIQUE KEY uk_user_role_org   (user_id, role_id, org_id)
 *     run_rbac_v2.php UNIQUE KEY uk_user_role_scope (user_id, role_id,
 *                                                    scope_kind, scope_id)
 *
 * In MySQL and MariaDB a UNIQUE index treats every NULL as distinct, so a
 * key containing a NULLable column places NO constraint at all on rows
 * where that column is NULL. A *global* grant is exactly that row:
 * org_id NULL, scope_id NULL. The constraint has therefore never applied
 * to the most common grant in the system.
 *
 * That would be merely untidy, except a writer was built on top of it.
 * `run_00_rbac.php` seeded the bootstrap administrator with
 *
 *     INSERT IGNORE INTO user_roles (user_id, role_id, org_id)
 *     VALUES (1, 1, NULL);
 *
 * INSERT IGNORE suppresses the duplicate-key error — but there is no
 * duplicate-key error to suppress, because the key cannot see NULL
 * collisions. So the statement inserted a NEW ROW on EVERY RUN of the
 * migration pipeline. Observed 2026-07-29:
 *
 *     your-server   user 1 held Super Admin  13 times
 *     training.ticketscad   user 1 held Super Admin  23 times
 *
 * one row per migration run since 2026-06-11, timestamps matching the
 * dates the pipeline was run.
 *
 * The same statement is the cause of the *dangling* grants too, which is
 * worth stating plainly because the obvious hypothesis is wrong. It is
 * NOT a missing delete-cascade: api/config-admin.php (Phase 10b) does
 * cascade user_roles and user_password_history when a user is deleted,
 * and it works. The dangling rows exist because the seed hardcodes
 * `user_id = 1` and never asks whether user 1 exists. On training the
 * administrator is not id 1 — user 1 has never existed there — so all 23
 * rows were grants to a user that was never present. Left alone, the
 * first account to be created with id 1 would silently inherit Super
 * Admin, 23 times over. That is the real hazard here, not the row count.
 *
 * THE FIX
 *
 *   1. Dedupe existing rows, keeping the OLDEST grant per natural key.
 *   2. Delete grants whose user no longer exists (or never did).
 *   3. Add a STORED generated column `scope_key` = COALESCE(scope_id,-1)
 *      and rebuild `uk_user_role_scope` over it, so the constraint finally
 *      binds global grants. The index KEEPS ITS NAME on purpose:
 *      run_rbac_v2.php step A5 skips when an index called
 *      `uk_user_role_scope` exists, so reusing the name stops A5 from
 *      re-adding the broken nullable version underneath us.
 *
 * Step 3 is what makes the writers safe. run_00_rbac.php is being fixed
 * to guard its insert in the same change, but a guard in one writer only
 * protects that writer; the constraint protects every future one.
 *
 * Idempotent. Safe to re-run.
 *
 * Usage:  php sql/run_rbac_v3_grant_uniqueness.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$failures = [];

function v3_say(string $s): void { echo $s . "\n"; }

function v3_col_exists(string $table, string $col): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]) > 0;
}

function v3_index_columns(string $table, string $index): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all(
        "SELECT COLUMN_NAME c FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
          ORDER BY SEQ_IN_INDEX", [$prefix . $table, $index]);
    $out = [];
    foreach ($rows as $r) $out[] = $r['c'];
    return $out;
}

v3_say('RBAC v3 — user_roles grant uniqueness (Phase 129)');
v3_say(str_repeat('=', 62));

if (!v3_col_exists('user_roles', 'user_id')) {
    v3_say('[SKIP] user_roles table not present — run sql/run_00_rbac.php first.');
    exit(0);
}

// Whether the RBAC-v2 scope columns exist decides the natural key we
// dedupe on. Pre-v2 installs only have org_id.
$hasScope = v3_col_exists('user_roles', 'scope_kind')
         && v3_col_exists('user_roles', 'scope_id');

// ── 1. Delete grants whose user does not exist ──────────────────────────
//
// Done BEFORE the dedupe so we do not bother preserving the oldest of a
// set of rows that are all about to go anyway.
try {
    $dangling = db_fetch_all(
        "SELECT ur.id, ur.user_id, ur.role_id
           FROM `{$prefix}user_roles` ur
           LEFT JOIN `{$prefix}user` u ON u.id = ur.user_id
          WHERE u.id IS NULL");
    if (!$dangling) {
        v3_say('[ok]  no grants reference a missing user');
    } else {
        $byUser = [];
        foreach ($dangling as $d) {
            $byUser[(int) $d['user_id']] = ($byUser[(int) $d['user_id']] ?? 0) + 1;
        }
        db_query(
            "DELETE ur FROM `{$prefix}user_roles` ur
               LEFT JOIN `{$prefix}user` u ON u.id = ur.user_id
              WHERE u.id IS NULL");
        foreach ($byUser as $uid => $n) {
            v3_say("[new] removed {$n} grant(s) for missing user #{$uid}");
        }
        v3_say('[new] ' . count($dangling) . ' dangling grant(s) deleted in total');
    }
} catch (Throwable $e) {
    $failures[] = 'dangling-grant cleanup: ' . $e->getMessage();
    v3_say('[FAIL] dangling-grant cleanup: ' . $e->getMessage());
}

// ── 2. Dedupe: keep the OLDEST row per natural key ──────────────────────
//
// "Oldest" = lowest id. The first grant is the one with the real history
// behind it (its granted_by / reason / granted_at describe the decision
// that was actually made); the rest are seed-run artefacts.
try {
    $keyCols = $hasScope
        ? ['user_id', 'role_id', 'scope_kind', 'scope_id']
        : ['user_id', 'role_id', 'org_id'];

    // Grouped in PHP rather than SQL. `user_roles` is small (tens of rows
    // on the largest install seen), and a DELETE...JOIN that has to match
    // NULLs with <=> across four columns is the sort of statement that is
    // hard to read and easy to get subtly wrong on the one table where
    // getting it wrong hands somebody Super Admin. Explicit id list wins.
    $rows = db_fetch_all(
        "SELECT id, `" . implode('`, `', $keyCols) . "`
           FROM `{$prefix}user_roles` ORDER BY id");

    $seen = [];      // natural key => id of the oldest row holding it
    $drop = [];
    foreach ($rows as $r) {
        $parts = [];
        foreach ($keyCols as $c) {
            // NULL collapses to a sentinel so NULL groups with NULL —
            // the very thing the index would not do.
            $parts[] = ($r[$c] === null) ? "\0" : (string) $r[$c];
        }
        $k = implode('|', $parts);
        if (isset($seen[$k])) $drop[] = (int) $r['id'];
        else                  $seen[$k] = (int) $r['id'];
    }

    if (!$drop) {
        v3_say('[ok]  no duplicate grants');
    } else {
        $in = implode(',', array_map('intval', $drop));
        db_query("DELETE FROM `{$prefix}user_roles` WHERE id IN ({$in})");
        v3_say('[new] removed ' . count($drop)
             . ' duplicate grant(s), oldest of each kept');
    }
} catch (Throwable $e) {
    $failures[] = 'dedupe: ' . $e->getMessage();
    v3_say('[FAIL] dedupe: ' . $e->getMessage());
}

// ── 3. A uniqueness constraint that actually constrains ─────────────────
if (!$hasScope) {
    v3_say('[SKIP] scope columns absent (pre-RBAC-v2 schema) — '
         . 'run sql/run_rbac_v2.php, then re-run this script.');
} else {
    try {
        if (v3_col_exists('user_roles', 'scope_key')) {
            v3_say('[ok]  user_roles.scope_key generated column present');
        } else {
            // STORED (not VIRTUAL) so it can carry a UNIQUE index.
            // Generated, so no writer can set it wrong and it never needs
            // to appear in schema_manifest.json — the manifest records
            // columns the code WRITES, and nothing can write this one.
            //
            // `GENERATED ALWAYS AS (...) STORED` is the spelling that
            // works on both engines. MariaDB rejects `NOT NULL` before
            // `AS` outright (verified on 11.7/11.8), and its own shorthand
            // `AS (...) PERSISTENT` is not valid MySQL.
            //
            // INVISIBLE matters more than it looks. Adding any column to a
            // table silently changes what `SELECT *` returns, and a
            // generated column cannot be written — so every
            // `SELECT * -> re-INSERT` round-trip in the codebase would
            // start failing with error 1906 ("value specified for
            // generated column ... has been ignored"). tests/test_rbac_v2.php
            // snapshots and restores grants exactly that way and broke on
            // the first run. An INVISIBLE column is omitted from `SELECT *`
            // while still enforcing the index, so existing round-trips keep
            // working and future ones cannot trip over it.
            $base = "ALTER TABLE `{$prefix}user_roles`
                     ADD COLUMN `scope_key` INT
                         GENERATED ALWAYS AS (COALESCE(`scope_id`, -1)) STORED
                         COMMENT 'NULL-collapsed scope_id so UNIQUE can see it'";
            try {
                db_query($base . ' INVISIBLE');
            } catch (Throwable $inv) {
                // MariaDB < 10.3 / MySQL < 8.0.23 have no INVISIBLE. The
                // constraint is what matters; take the visible column.
                db_query($base);
                v3_say('[note] INVISIBLE unsupported here — scope_key is visible '
                     . 'to SELECT *; avoid SELECT *-based row copies of user_roles');
            }
            v3_say('[new] added user_roles.scope_key (COALESCE(scope_id,-1), STORED)');
        }
    } catch (Throwable $e) {
        $failures[] = 'scope_key column: ' . $e->getMessage();
        v3_say('[FAIL] scope_key column: ' . $e->getMessage());
    }

    try {
        $cur  = v3_index_columns('user_roles', 'uk_user_role_scope');
        $want = ['user_id', 'role_id', 'scope_kind', 'scope_key'];
        if ($cur === $want) {
            v3_say('[ok]  uk_user_role_scope already covers scope_key');
        } elseif (!v3_col_exists('user_roles', 'scope_key')) {
            v3_say('[SKIP] scope_key missing — cannot rebuild the unique key');
        } else {
            if ($cur) {
                db_query("ALTER TABLE `{$prefix}user_roles` DROP INDEX `uk_user_role_scope`");
            }
            // The pre-v2 key is equally toothless; drop it if it survived.
            if (v3_index_columns('user_roles', 'uk_user_role_org')) {
                db_query("ALTER TABLE `{$prefix}user_roles` DROP INDEX `uk_user_role_org`");
                v3_say('[new] dropped the pre-v2 uk_user_role_org (also NULLable)');
            }
            db_query("ALTER TABLE `{$prefix}user_roles`
                      ADD UNIQUE KEY `uk_user_role_scope`
                      (`user_id`, `role_id`, `scope_kind`, `scope_key`)");
            v3_say('[new] uk_user_role_scope rebuilt over scope_key — '
                 . 'global grants are now unique');
        }
    } catch (Throwable $e) {
        $failures[] = 'unique key rebuild: ' . $e->getMessage();
        v3_say('[FAIL] unique key rebuild: ' . $e->getMessage());
    }
}

// ── 4. VERIFY the outcome — never trust the step, ask the database ──────
//
// CLAUDE.md, Phase 125/128: a migration that catches its own exception and
// exits 0 is a migration that never ran. This is the check that decides.
try {
    $stillDupe = (int) db_fetch_value(
        "SELECT COUNT(*) FROM (
            SELECT COUNT(*) n FROM `{$prefix}user_roles`
             GROUP BY `user_id`, `role_id`,
                      " . ($hasScope ? "`scope_kind`, COALESCE(`scope_id`,-1)"
                                     : "COALESCE(`org_id`,-1)") . "
            HAVING n > 1) d");
    $stillDangling = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user_roles` ur
           LEFT JOIN `{$prefix}user` u ON u.id = ur.user_id
          WHERE u.id IS NULL");
    if ($stillDupe > 0)     $failures[] = "{$stillDupe} duplicate grant group(s) remain";
    if ($stillDangling > 0) $failures[] = "{$stillDangling} dangling grant(s) remain";
    if (!$stillDupe && !$stillDangling) {
        $total = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user_roles`");
        v3_say("[ok]  verified: {$total} grant(s), no duplicates, none dangling");
    }
} catch (Throwable $e) {
    $failures[] = 'verification: ' . $e->getMessage();
}

v3_say(str_repeat('=', 62));
if ($failures) {
    v3_say('[FAILED] ' . count($failures) . ' problem(s):');
    foreach ($failures as $f) v3_say('   - ' . $f);
    exit(1);
}
v3_say('Done.');
exit(0);
