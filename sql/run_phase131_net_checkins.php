<?php
/**
 * Phase 131 — Net-Control Check-Ins (`/net`)
 *
 * Creates the per-operator check-in list, seeds the RBAC permission that gates
 * it, and seeds the admin-overridable defaults.
 *
 * Idempotent — safe to re-run. Everything checks before it writes, and an
 * existing setting is left alone because an existing value is the admin's
 * choice, not a gap to fill.
 *
 * VERIFIES ITS OWN OUTCOME. A migration step that catches its own exception
 * and exits 0 is a step that never ran (CLAUDE.md, Phase 128 A9: a bad column
 * name raised 1054, the catch printed one [fail] line, the runner exited 0,
 * and the step had never run on any install). So the last thing this does is
 * re-ask the database whether the table and the permission actually exist, and
 * it exits non-zero if they do not.
 *
 * Usage:  php sql/run_phase131_net_checkins.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'net_checkins';
$fail   = [];

echo "Phase 131 — Net-Control Check-Ins\n";
echo "=================================\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. The table
// ─────────────────────────────────────────────────────────────────────────
//
// No UNIQUE key here, deliberately. MySQL/MariaDB treat every NULL in a
// UNIQUE index as distinct, so a unique key ending in a NULLable column
// constrains nothing for exactly the rows where that column is NULL — and an
// INSERT IGNORE layered on top of it suppresses nothing, because the error it
// relies on can never be raised. That trap produced 718 duplicate Super Admin
// grants on the dev box (Phase 129). Nothing here needs uniqueness: two
// check-ins from the same station really are two check-ins.
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$table}` (
        `id`         int(11)      NOT NULL AUTO_INCREMENT,
        `user_id`    int(11)      NOT NULL,
        `identifier` varchar(64)  NOT NULL,
        `note`       varchar(255) NOT NULL DEFAULT '',
        `status`     varchar(16)  NOT NULL DEFAULT 'pending',
        `seq`        int(11)      NOT NULL DEFAULT 0,
        `priority`   int(11)      NOT NULL DEFAULT 0,
        `ticket_id`  int(11)          NULL DEFAULT NULL,
        `created_at` datetime     NOT NULL,
        `worked_at`  datetime         NULL DEFAULT NULL,
        `deleted_at` datetime         NULL DEFAULT NULL,
        `updated_at` datetime     NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_net_user_status`  (`user_id`, `status`, `id`),
        KEY `idx_net_user_created` (`user_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] table `{$table}` present\n";
} catch (Exception $e) {
    $fail[] = 'create table: ' . $e->getMessage();
    echo "[FAIL] create table: " . $e->getMessage() . "\n";
}

// Column top-ups for an install that got an earlier cut of this table.
// Guarded by information_schema so a re-run never crashes on "already exists".
$wantCols = [
    'priority'   => "ADD COLUMN `priority` int(11) NOT NULL DEFAULT 0 AFTER `seq`",
    'ticket_id'  => "ADD COLUMN `ticket_id` int(11) NULL DEFAULT NULL AFTER `priority`",
    'worked_at'  => "ADD COLUMN `worked_at` datetime NULL DEFAULT NULL AFTER `created_at`",
    'deleted_at' => "ADD COLUMN `deleted_at` datetime NULL DEFAULT NULL AFTER `worked_at`",
];
foreach ($wantCols as $col => $ddl) {
    try {
        $has = db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $col]);
        if ((int) $has === 0) {
            db_query("ALTER TABLE `{$table}` {$ddl}");
            echo "  [+] column added: {$col}\n";
        }
    } catch (Exception $e) {
        echo "  [warn] column {$col}: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 2. RBAC — action.net_checkin
// ─────────────────────────────────────────────────────────────────────────
//
// Granted to Super Admin (1), Org Admin (2), Dispatcher (3). Running a net is
// a dispatcher's job, so this is an OPERATIONAL permission, not an
// administrative one — which is why it is deliberately NOT added to the broad
// `NOT IN (...)` exclusion lists in sql/rbac.sql and sql/run_00_rbac.php. Those
// lists exist to stop admin-only permissions leaking to Org Admin and
// Dispatcher on a re-import; this one is meant to reach them.
//
// It is an `action.` code rather than a `screen.` code for a load-bearing
// reason: run_00_rbac.php grants Dispatcher/Operator/Read-Only by
// `category IN ('screen','widget')` wholesale, so a screen.* code would have
// been handed silently to Read-Only.
//
// Operator (4) and Read-Only (5) can be granted it per-install via the Roles UI.
$permCode = 'action.net_checkin';
$permId   = 0;
try {
    $permId = (int) db_fetch_value(
        "SELECT `id` FROM `{$prefix}permissions` WHERE `code` = ? LIMIT 1", [$permCode]);
    if ($permId === 0) {
        db_query("INSERT INTO `{$prefix}permissions` (`code`, `name`, `description`, `category`)
                  VALUES (?, ?, ?, 'action')",
            [$permCode,
             'Use Net-Control Check-Ins',
             'Capture and work a personal net-control check-in list via the /net command.']);
        $permId = (int) db_insert_id();
        echo "[OK] permission inserted: {$permCode} (id={$permId})\n";
    } else {
        echo "[OK] permission exists: {$permCode} (id={$permId})\n";
    }
} catch (Exception $e) {
    $fail[] = 'permission: ' . $e->getMessage();
    echo "[FAIL] permission: " . $e->getMessage() . "\n";
}

if ($permId > 0) {
    foreach ([1, 2, 3] as $roleId) {
        try {
            $has = db_fetch_value(
                "SELECT 1 FROM `{$prefix}role_permissions`
                  WHERE `role_id` = ? AND `permission_id` = ? LIMIT 1", [$roleId, $permId]);
            if (!$has) {
                db_query("INSERT INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
                          VALUES (?, ?)", [$roleId, $permId]);
                echo "  [+] grant: role {$roleId} -> {$permCode}\n";
            }
        } catch (Exception $e) {
            echo "  [warn] grant role {$roleId}: " . $e->getMessage() . "\n";
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 3. Default settings
// ─────────────────────────────────────────────────────────────────────────
//
// Written to the `settings` table — the store the Settings UI writes and
// get_variable() reads. NOT the `config` table (get_setting()), which the
// Settings UI never writes, so a value put there would read as the default
// forever and the admin panel would appear to do nothing (GH #79).
//
// The owner has said his choices may not match another organization's needs,
// so each of these is a real decision an admin can revisit.
$defaults = [
    'net_checkin_history_count'         => ['10',      'how many worked/deleted rows the history view shows'],
    'net_checkin_autofocus'             => ['1',       'widget takes focus on load when check-ins are waiting'],
    'net_checkin_order'                 => ['arrival', 'arrival | priority'],
    'net_checkin_separator'             => ['/',       'entry separator in the /net command'],
    'net_checkin_separator_digit_guard' => ['1',       'a digit-flanked separator is literal, so hail 3/4" survives'],
    'net_checkin_retention_days'        => ['7',       'days a worked/deleted entry stays recoverable (0 = forever)'],
];
foreach ($defaults as $name => [$value, $note]) {
    try {
        $exists = db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        if ((int) $exists === 0) {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $value]);
            echo "  [+] setting seeded: {$name} = {$value}   ({$note})\n";
        } else {
            echo "  [skip] setting exists: {$name}\n";
        }
    } catch (Exception $e) {
        echo "  [warn] setting {$name}: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 3b. Caption key
// ─────────────────────────────────────────────────────────────────────────
//
// The widgets-toolbar button and the panel's own card header both render
// t('dash.widget.net_checkins', …) — the same key, so a per-install rename
// reaches both at once, exactly as GH #70 required of the other widgets. A
// t() call whose key is not in captions_i18n falls back to the English text
// but gives the Translations UI no row to edit, so the rename silently cannot
// be made. Seeded here, INSERT IGNORE, safe to re-run.
$captions = [
    ['dash.widget.net_checkins', 'en', 'Check-Ins',  'dash'],
    ['dash.widget.net_checkins', 'de', 'Anmeldungen', 'dash'],
];
foreach ($captions as [$key, $lang, $value, $category]) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}captions_i18n` (`caption_key`, `lang`, `value`, `category`)
             VALUES (?, ?, ?, ?)",
            [$key, $lang, $value, $category]
        );
        echo "  [+] caption: {$key} [{$lang}]\n";
    } catch (Exception $e) {
        // A very old install may not have captions_i18n yet; the t() fallback
        // still renders the English text, so this is not fatal.
        echo "  [warn] caption {$key}: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 4. Verify the OUTCOME — not that the script ran, but that it worked
// ─────────────────────────────────────────────────────────────────────────
try {
    $tableThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
    if ($tableThere === 0) $fail[] = "verify: table `{$table}` does not exist";

    $permThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}permissions` WHERE `code` = ?", [$permCode]);
    if ($permThere === 0) $fail[] = "verify: permission {$permCode} does not exist";
} catch (Exception $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);   // non-zero, so sql/run_migrations.php records a real failure
}

echo "\nDone. Net-control check-ins are installed.\n";
exit(0);
