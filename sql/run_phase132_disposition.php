<?php
/**
 * Phase 132 — Structured incident disposition (Step 1 ONLY: schema + seeds
 * + captions + setting + RBAC permission).
 *
 * specs/phase-132-incident-disposition/{spec.md,plan.md,tasks.md}. This
 * script does exactly plan.md §10 step 1 — "Migration + seeds + captions +
 * setting" — and nothing from steps 2-5 (writer/API enforcement, Settings
 * panel, UI dropdowns, reports/export). Those are separate, later work.
 *
 * WHAT THIS CREATES:
 *   - `ticket_disposition` lookup table, shaped like the codebase's existing
 *     admin-editable lists (un_status, member_status, fac_status): id,
 *     status_val (renameable label), description, code (stable
 *     export/integration key — NEVER renamed, unlike the label), discipline
 *     (filter hint, '' = always offered), org_id (NULL = every org),
 *     sort_order, requires_comment, active (0 = retired, not deleted —
 *     plan.md §2).
 *   - `ticket.disposition_id INT NULL` + an index. NULL means "not
 *     recorded" — every historical incident, forever (spec: no
 *     back-filling).
 *   - The 6-item cross-discipline seed core: Resolved / Handled, Unfounded,
 *     Cancelled, Duplicate Call, Referred to Other Agency, No Action
 *     Necessary — each with a stable `code`, discipline='' (always offered,
 *     the empty-means-show-everything invariant), org_id=NULL (global).
 *   - Setting `disposition_required_on_close` = '0' (off by default — an
 *     existing install's behaviour must not change just by upgrading).
 *     Written to the `settings` table (name/value) — the store
 *     get_variable() reads and the Settings UI writes (NOT the separate
 *     `config` table read by get_setting() — CLAUDE.md "TWO settings
 *     stores", GH #79).
 *   - Captions for the 6 seeded labels in all five shipped languages (en,
 *     de, nl, fr, es), keyed `disposition.<code>`, category 'disposition'.
 *     A t() call with no seeded row leaves the Translations UI nothing to
 *     edit and pins the string to its English fallback forever.
 *   - RBAC permission `action.manage_dispositions`, admin-only (Super Admin
 *     ONLY by default — see sql/rbac.sql and sql/run_00_rbac.php, whose Org
 *     Admin/Dispatcher `NOT IN (...)` exclusion lists both name this code).
 *     Selecting a disposition when closing/editing a call needs NO
 *     permission (plan.md §8) — only *managing the list* does, so nothing
 *     else is granted here.
 *
 * UNIQUENESS — deliberately NO database-level UNIQUE key on `code` or
 * (org_id, code). Phase 129's lesson: MySQL/MariaDB treat every NULL in a
 * UNIQUE index as a DISTINCT value, and every seed row here has org_id
 * NULL (global) — so a naive UNIQUE(org_id, code) would constrain nothing
 * for exactly the rows this script writes, the same trap that let
 * uk_user_role_org silently multiply Super Admin grants. Rather than build
 * a NULL-safe generated-column workaround for a lookup table that doesn't
 * need one yet, idempotency is enforced at the APPLICATION level (check
 * existence, then insert) — the same pattern this project already uses for
 * permissions and settings rows (see sql/run_phase133_audit_retention.php).
 * That is also why this script's own idempotency cannot be verified by
 * reading the DDL: tests/test_phase132_migration.php actually re-runs this
 * script and asks the database whether a duplicate was accepted.
 *
 * Idempotent — safe to re-run. VERIFIES ITS OWN OUTCOME (CLAUDE.md, Phase
 * 128 A9: a migration step that catches its own exception and exits 0 is a
 * step that never ran) — the last thing this does is re-ask the database
 * whether the table, column, seeds, setting, captions, and permission grant
 * actually exist, and exits non-zero if not.
 *
 * Usage: php sql/run_phase132_disposition.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix      = $GLOBALS['db_prefix'] ?? '';
$table       = $prefix . 'ticket_disposition';
$ticketTable = $prefix . 'ticket';
$fail        = [];

echo "Phase 132 — Structured Incident Disposition (Step 1)\n";
echo "======================================================\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. The lookup table — shape per plan.md §1
// ─────────────────────────────────────────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$table}` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `status_val`       VARCHAR(64)  NOT NULL,
        `description`      TEXT         NOT NULL DEFAULT '',
        `code`             VARCHAR(64)  NOT NULL,
        `discipline`       VARCHAR(32)  NOT NULL DEFAULT '',
        `org_id`           INT          NULL DEFAULT NULL,
        `sort_order`       INT          NOT NULL DEFAULT 0,
        `requires_comment` TINYINT(1)   NOT NULL DEFAULT 0,
        `active`           TINYINT(1)   NOT NULL DEFAULT 1,
        KEY `idx_code` (`code`),
        KEY `idx_org_id` (`org_id`),
        KEY `idx_discipline` (`discipline`),
        KEY `idx_active` (`active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] table `{$table}` present\n";
} catch (Exception $e) {
    $fail[] = 'create table: ' . $e->getMessage();
    echo "[FAIL] create table: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 2. ticket.disposition_id + index
// ─────────────────────────────────────────────────────────────────────────
// Idempotent via information_schema check, same pattern as
// sql/run_org_scope.php's org_id additions.
try {
    $colExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'disposition_id'",
        [$ticketTable]);
    if ($colExists === 0) {
        db_query("ALTER TABLE `{$ticketTable}` ADD COLUMN `disposition_id` INT NULL DEFAULT NULL");
        echo "[OK] added ticket.disposition_id\n";
    } else {
        echo "[OK] ticket.disposition_id already present\n";
    }

    $idxExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_ticket_disposition'",
        [$ticketTable]);
    if ($idxExists === 0) {
        db_query("ALTER TABLE `{$ticketTable}` ADD INDEX `idx_ticket_disposition` (`disposition_id`)");
        echo "[OK] added index idx_ticket_disposition\n";
    } else {
        echo "[OK] index idx_ticket_disposition already present\n";
    }
} catch (Exception $e) {
    $fail[] = 'ticket.disposition_id: ' . $e->getMessage();
    echo "[FAIL] ticket.disposition_id: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 3. Seed the 6-item cross-discipline core
// ─────────────────────────────────────────────────────────────────────────
// discipline='' (always offered, per the empty-means-show-everything
// invariant) and org_id=NULL (global) for every seeded row — spec.md's
// "In scope" list and tasks.md Step 1 both name these exact six.
$seedRows = [
    // code                      status_val                     sort_order
    ['resolved',                'Resolved / Handled',           1],
    ['unfounded',                'Unfounded',                    2],
    ['cancelled',                'Cancelled',                    3],
    ['duplicate_call',           'Duplicate Call',               4],
    ['referred_other_agency',    'Referred to Other Agency',     5],
    ['no_action',                'No Action Necessary',          6],
];

$seededCount = 0;
foreach ($seedRows as [$code, $label, $sort]) {
    try {
        // NULL-safe existence check, not a UNIQUE key — see the docblock's
        // "UNIQUENESS" note above.
        $already = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$table}` WHERE `code` = ? AND `org_id` IS NULL", [$code]);
        if ($already === 0) {
            db_query("INSERT INTO `{$table}`
                (`status_val`, `description`, `code`, `discipline`, `org_id`, `sort_order`, `requires_comment`, `active`)
                VALUES (?, '', ?, '', NULL, ?, 0, 1)",
                [$label, $code, $sort]);
            $seededCount++;
            echo "  [+] disposition seeded: {$code} ({$label})\n";
        } else {
            echo "  [skip] disposition exists: {$code}\n";
        }
    } catch (Exception $e) {
        $fail[] = "seed {$code}: " . $e->getMessage();
        echo "  [FAIL] seed {$code}: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 4. Default setting — disposition_required_on_close
// ─────────────────────────────────────────────────────────────────────────
// Off by default so upgrading never changes an existing install's close
// behaviour (spec success criterion 7). Written to `settings`
// (name/value) — read with get_variable(), NOT get_setting() (GH #79 — the
// separate `config` table would silently never see this write).
try {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", ['disposition_required_on_close']);
    if ($exists === 0) {
        db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
            ['disposition_required_on_close', '0']);
        echo "  [+] setting seeded: disposition_required_on_close = 0\n";
    } else {
        echo "  [skip] setting exists: disposition_required_on_close\n";
    }
} catch (Exception $e) {
    $fail[] = 'setting: ' . $e->getMessage();
    echo "  [warn] setting: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 5. Captions — the 6 seeded labels, five shipped languages (plan.md §7)
// ─────────────────────────────────────────────────────────────────────────
// Keyed disposition.<code> so a future UI can call
// t("disposition.$code", $row['status_val']) and get a translated label
// while still storing/exporting the stable code. captions_i18n's UNIQUE
// KEY is (caption_key, lang) — neither column is nullable, so this one
// genuinely is enforced at the database level (INSERT IGNORE is safe
// here, unlike ticket_disposition above).
$translations = [
    'resolved' => [
        'en' => 'Resolved / Handled',
        'de' => 'Erledigt / Behandelt',
        'nl' => 'Afgehandeld',
        'fr' => 'Résolu / Traité',
        'es' => 'Resuelto / Atendido',
    ],
    'unfounded' => [
        'en' => 'Unfounded',
        'de' => 'Unbegründet',
        'nl' => 'Ongegrond',
        'fr' => 'Non fondé',
        'es' => 'Infundado',
    ],
    'cancelled' => [
        'en' => 'Cancelled',
        'de' => 'Storniert',
        'nl' => 'Geannuleerd',
        'fr' => 'Annulé',
        'es' => 'Cancelado',
    ],
    'duplicate_call' => [
        'en' => 'Duplicate Call',
        'de' => 'Doppelter Anruf',
        'nl' => 'Dubbele melding',
        'fr' => 'Appel en double',
        'es' => 'Llamada duplicada',
    ],
    'referred_other_agency' => [
        'en' => 'Referred to Other Agency',
        'de' => 'An andere Stelle verwiesen',
        'nl' => 'Doorverwezen naar andere instantie',
        'fr' => 'Renvoyé à un autre organisme',
        'es' => 'Remitido a otra agencia',
    ],
    'no_action' => [
        'en' => 'No Action Necessary',
        'de' => 'Keine Maßnahme erforderlich',
        'nl' => 'Geen actie nodig',
        'fr' => 'Aucune action nécessaire',
        'es' => 'No se requiere ninguna acción',
    ],
];

$capAdded = 0;
foreach ($translations as $code => $langs) {
    $key = "disposition.{$code}";
    foreach ($langs as $lang => $value) {
        try {
            db_query(
                "INSERT IGNORE INTO `{$prefix}captions_i18n` (`caption_key`, `lang`, `value`, `category`)
                 VALUES (?, ?, ?, 'disposition')",
                [$key, $lang, $value]
            );
            $capAdded += (int) db_fetch_value('SELECT ROW_COUNT()');
        } catch (Exception $e) {
            $fail[] = "caption {$key}[{$lang}]: " . $e->getMessage();
            echo "  [FAIL] caption {$key}[{$lang}]: " . $e->getMessage() . "\n";
        }
    }
}
echo "  [+] captions: {$capAdded} new row(s) seeded (" . count($translations) . " keys x 5 languages)\n";

// ─────────────────────────────────────────────────────────────────────────
// 6. RBAC — action.manage_dispositions (admin-only)
// ─────────────────────────────────────────────────────────────────────────
// Scoped identically to action.manage_audit_retention / action.bulk_delete_members:
// granted to role 1 (Super Admin) explicitly here, because the base "Super
// Admin gets EVERYTHING" seed in rbac.sql/run_00_rbac.php only runs once at
// install — a permission added later is NOT retroactively granted. Do NOT
// grant to any other role here; sql/rbac.sql and sql/run_00_rbac.php carry
// the matching Org Admin / Dispatcher `NOT IN (...)` exclusion-list entries
// so a fresh install and a re-import agree with this migration. This is the
// exact bug class ("broad RBAC grants in re-runnable seeds sweep up later
// permissions") that has hit this project three times before — see
// sql/run_bulk_delete_member_perm.php and sql/run_heal_audit_retention_perm.php.
$permCode = 'action.manage_dispositions';
$permId   = 0;
try {
    $permId = (int) db_fetch_value(
        "SELECT `id` FROM `{$prefix}permissions` WHERE `code` = ? LIMIT 1", [$permCode]);
    if ($permId === 0) {
        db_query("INSERT INTO `{$prefix}permissions` (`code`, `name`, `description`, `category`)
                  VALUES (?, ?, ?, 'action')",
            [$permCode,
             'Manage Incident Dispositions',
             'Add, rename, reorder and retire the incident-disposition list, and change whether a '
             . 'disposition is required at incident close. Scoped like action.manage_config: admin-only. '
             . 'Selecting a disposition when closing/editing an incident needs no permission.']);
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
    try {
        $has = db_fetch_value(
            "SELECT 1 FROM `{$prefix}role_permissions`
              WHERE `role_id` = 1 AND `permission_id` = ? LIMIT 1", [$permId]);
        if (!$has) {
            db_query("INSERT INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
                      VALUES (1, ?)", [$permId]);
            echo "  [+] grant: role 1 (Super Admin) -> {$permCode}\n";
        } else {
            echo "  [OK] grant already present: role 1 -> {$permCode}\n";
        }
    } catch (Exception $e) {
        echo "  [warn] grant role 1: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 7. Verify the OUTCOME — not that the script ran, but that it worked
// ─────────────────────────────────────────────────────────────────────────
try {
    $tableThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
    if ($tableThere === 0) $fail[] = "verify: table `{$table}` does not exist";

    $colThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'disposition_id'",
        [$ticketTable]);
    if ($colThere === 0) $fail[] = "verify: ticket.disposition_id does not exist";

    $seedCodes = array_column($seedRows, 0);
    $placeholders = implode(',', array_fill(0, count($seedCodes), '?'));
    $seedCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$table}` WHERE `org_id` IS NULL AND `code` IN ({$placeholders})",
        $seedCodes);
    if ($seedCount < count($seedRows)) {
        $fail[] = "verify: expected " . count($seedRows) . " seeded dispositions, found {$seedCount}";
    }

    $settingThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", ['disposition_required_on_close']);
    if ($settingThere === 0) $fail[] = "verify: setting disposition_required_on_close does not exist";

    $expectedCaps = count($translations) * 5;
    $capCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}captions_i18n` WHERE `category` = 'disposition'");
    if ($capCount < $expectedCaps) {
        $fail[] = "verify: expected at least {$expectedCaps} disposition captions, found {$capCount}";
    }

    $permThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}permissions` WHERE `code` = ?", [$permCode]);
    if ($permThere === 0) $fail[] = "verify: permission {$permCode} does not exist";

    if ($permId > 0) {
        $grantThere = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}role_permissions`
              WHERE `role_id` = 1 AND `permission_id` = ?", [$permId]);
        if ($grantThere === 0) $fail[] = "verify: role 1 does not hold {$permCode}";
    }
} catch (Exception $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);   // non-zero, so sql/run_migrations.php records a real failure
}

echo "\nDone. Incident disposition (Step 1: schema + seeds) installed.\n";
exit(0);
