<?php
/**
 * Phase 134 — Inbound routing to the sender's assigned incident (Model 3,
 * GH #23) — Step 5 ONLY: the Model 1 fallback route + the router_evaluate()
 * wiring that makes "never silent drop" actually true end to end.
 *
 * specs/phase-134-inbound-routing-model3/{spec.md,plan.md}. This script does
 * plan.md §9 step 5's SEED half — "the default message_routes seed row for
 * Model 1 fallback." The other half of step 5 (router_evaluate() calling
 * mi_attach_message_to_assigned_incidents() unconditionally on every inbound
 * message) is a code change in inc/router.php, not a migration — nothing to
 * seed for it.
 *
 * WHAT THIS CREATES:
 *   One `message_routes` row: source_channel = '*' (the wildcard
 *   _router_get_routes() already matches — confirmed via
 *   `WHERE source_channel = ? OR source_channel = '*'`), dest_channel =
 *   'local_chat', direction = 'inbound', enabled = 1, attach_action = NULL.
 *
 *   Before this migration runs, `message_routes` has ZERO inbound rows on
 *   every install (confirmed live: the only two seeded rows are Phase 99v's
 *   `source_channel = 'audit_event', direction = 'outbound'` push routes) —
 *   so an inbound message from a polled channel (Telegram, Slack, ...) has
 *   NO route at all and never reaches general chat, regardless of whether it
 *   also resolves to an assigned incident. This one row is the Model 1
 *   floor spec.md's "Fallback, never silent drop" describes: EVERY inbound
 *   message, resolved sender or not, assigned incident or not, still lands
 *   in local_chat so a dispatcher watching general chat sees it go by.
 *
 * WHY source_channel = '*' AND NOT ONE ROW PER CHANNEL: the wildcard matches
 * every present AND FUTURE polled channel (Telegram and Slack today; a
 * future MQTT/webhook adapter tomorrow) without a migration per channel.
 * `attach_action` is deliberately left NULL — this route does not use the
 * Phase 111 attach_action='add_note' mechanism (that mechanism targets ONE
 * designated "active event" ticket; this is a completely different,
 * unconditional consumer wired directly into router_evaluate() — see
 * inc/router.php's Step 5 comment block for why the two must not be
 * confused).
 *
 * IDEMPOTENT — existence-checked by `name` before inserting (same shape as
 * sql/run_phase134_inbound_routing.php's comm_modes seed), so re-running is
 * a no-op. VERIFIES ITS OWN OUTCOME (CLAUDE.md, Phase 128 A9 lesson: a
 * migration step that catches its own exception and exits 0 is a step that
 * never ran) — the last thing this does is re-query the row back out and
 * confirm it exists with the expected shape, not just that the INSERT
 * didn't throw.
 *
 * RBAC (plan.md §7): none needed for this step and none added here — the
 * resolution/attach logic runs unattended, not behind a user action.
 * Configuring which channels poll (Step 4) already gates on the existing
 * action.manage_config permission.
 *
 * Usage: php sql/run_phase134_step5_fallback_route.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix      = $GLOBALS['db_prefix'] ?? '';
$routesTable = $prefix . 'message_routes';
$fail        = [];

echo "Phase 134 — Inbound Routing Model 3 (Step 5: Model 1 fallback route)\n";
echo "======================================================================\n\n";

$routeName = 'Phase 134 seed: inbound messages -> general dispatch chat (Model 1 floor)';
$routeDesc = 'Every inbound message from a polled channel reaches general chat, '
    . 'regardless of whether it also resolves to an assigned incident';

// ─────────────────────────────────────────────────────────────────────────
// 1. message_routes table must exist first (created by inc/router.php's
//    _router_ensure_tables(), which loads on any request that pulls in
//    inc/broker.php — but a bare CLI script here has not necessarily done
//    that, so require it explicitly rather than assume).
// ─────────────────────────────────────────────────────────────────────────
try {
    $tableExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$routesTable]
    );
    if ($tableExists === 0) {
        require_once __DIR__ . '/../inc/router.php';
        _router_ensure_tables();
        echo "[OK] table `{$routesTable}` created (routing was never migrated on this install)\n";
    } else {
        echo "[OK] table `{$routesTable}` present\n";
    }
} catch (Exception $e) {
    $fail[] = 'ensure message_routes table: ' . $e->getMessage();
    echo "[FAIL] ensure message_routes table: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Priority — pick a value after every currently-seeded row's priority,
//    read live rather than guessed, so this never silently races ahead of
//    (or collides with) whatever else is seeded. Falls back to the column's
//    own default (100) on a fresh install with nothing seeded yet.
// ─────────────────────────────────────────────────────────────────────────
$priority = 100;
try {
    $maxPriority = db_fetch_value("SELECT MAX(`priority`) FROM `{$routesTable}`");
    if ($maxPriority !== null && (int) $maxPriority >= $priority) {
        $priority = (int) $maxPriority + 10;
    }
} catch (Exception $e) {
    // Table just got created above and is empty, or a transient error —
    // either way, the column default (100) is a fine value to seed with.
}

// ─────────────────────────────────────────────────────────────────────────
// 3. Seed the fallback route (existence-checked by name — idempotent).
// ─────────────────────────────────────────────────────────────────────────
try {
    $already = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$routesTable}` WHERE `name` = ?", [$routeName]
    );
    if ($already === 0) {
        db_query(
            "INSERT INTO `{$routesTable}`
                (`name`, `description`, `enabled`, `priority`, `source_channel`, `dest_channel`,
                 `direction`, `filters_json`, `transform_json`, `attach_action`, `created_by`)
             VALUES (?, ?, 1, ?, '*', 'local_chat', 'inbound', NULL, NULL, NULL, NULL)",
            [$routeName, $routeDesc, $priority]
        );
        echo "  [+] fallback route seeded: '{$routeName}' (priority {$priority})\n";
    } else {
        echo "  [skip] fallback route already exists: '{$routeName}'\n";
    }
} catch (Exception $e) {
    $fail[] = 'seed fallback route: ' . $e->getMessage();
    echo "  [FAIL] seed fallback route: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 4. Verify the OUTCOME — not that the INSERT didn't throw, but that
//    exactly one row with the expected shape actually exists now.
// ─────────────────────────────────────────────────────────────────────────
try {
    $rows = db_fetch_all(
        "SELECT `id`, `enabled`, `source_channel`, `dest_channel`, `direction`, `attach_action`
           FROM `{$routesTable}` WHERE `name` = ?",
        [$routeName]
    );
    if (count($rows) !== 1) {
        $fail[] = 'verify: expected exactly 1 row named "' . $routeName . '", found ' . count($rows);
    } else {
        $row = $rows[0];
        if ((int) $row['enabled'] !== 1) $fail[] = 'verify: fallback route is not enabled';
        if ($row['source_channel'] !== '*') $fail[] = "verify: source_channel is '{$row['source_channel']}', expected '*'";
        if ($row['dest_channel'] !== 'local_chat') $fail[] = "verify: dest_channel is '{$row['dest_channel']}', expected 'local_chat'";
        if ($row['direction'] !== 'inbound') $fail[] = "verify: direction is '{$row['direction']}', expected 'inbound'";
        if ($row['attach_action'] !== null) $fail[] = "verify: attach_action is set (" . var_export($row['attach_action'], true) . "), expected NULL — this route must not use the Phase 111 attach mechanism";
    }
} catch (Exception $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);   // non-zero, so sql/run_migrations.php records a real failure
}

echo "\nDone. Inbound routing Model 3 (Step 5: Model 1 fallback route) installed.\n";
exit(0);
