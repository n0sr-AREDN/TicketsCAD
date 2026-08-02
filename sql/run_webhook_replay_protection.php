<?php
/**
 * Webhook replay protection — add the stable delivery uid.
 * ---------------------------------------------------------------------
 * 2026-08-02. Reported privately by Ron Jones (@rjonesbsink).
 *
 * THE DEFECT
 *
 * Outbound deliveries were signed `hash_hmac('sha256', $body, $secret)`
 * and carried no timestamp, nonce or delivery identifier. The signature
 * had no time-varying input, so a captured delivery re-POSTed unchanged
 * a month later produced the same digest and verified as authentic. The
 * receiver had nothing in the request whose absence or staleness could
 * justify rejecting it.
 *
 * inc/webhooks.php now sends a per-transmission `X-Webhook-Timestamp`
 * inside the signed material, plus `X-Webhook-Delivery` as an
 * idempotency key. This script adds the column that makes the second
 * half of that work on an existing install.
 *
 * WHY A COLUMN AND NOT webhook_deliveries.id
 *
 * The obvious move is to surface the existing row id as the delivery id.
 * It does not work: webhook_process_retries() INSERTs a NEW delivery row
 * per attempt, so the row id changes on every retry. A receiver
 * deduplicating on it would treat each retry of one event as a distinct
 * event and process it again — which is precisely the duplicate-suppression
 * the key exists to provide. The uid is minted once for the logical
 * delivery and carried across every retry and admin replay of it, which
 * is what docs/WEBHOOKS-INTEGRATOR-GUIDE.md has always promised
 * ("UUID shared across retries").
 *
 * HISTORICAL ROWS ARE LEFT NULL ON PURPOSE
 *
 * A delivery that was already sent went out without a uid on the wire.
 * Back-filling one now would invent an identifier no receiver ever saw,
 * which is worse than an honest NULL: it would make a replayed old row
 * look like a delivery the receiver ought to recognise. The retry and
 * replay paths mint a uid when they find NULL, so old rows still behave
 * correctly if they are ever re-sent.
 *
 * Idempotent. Safe to re-run.
 *
 * Usage:  php sql/run_webhook_replay_protection.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix   = $GLOBALS['db_prefix'] ?? '';
$failures = [];

function wrp_say(string $s): void { echo $s . "\n"; }

function wrp_col_exists(string $table, string $col): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]) > 0;
}

function wrp_index_exists(string $table, string $index): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
        [$prefix . $table, $index]) > 0;
}

function wrp_table_exists(string $table): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . $table]) > 0;
}

wrp_say('Webhook replay protection — delivery_uid (2026-08-02)');
wrp_say(str_repeat('=', 62));

if (!wrp_table_exists('webhook_deliveries')) {
    wrp_say('[SKIP] webhook_deliveries not present — run sql/run_webhooks.php first.');
    exit(0);
}

// ── 1. delivery_uid column ──────────────────────────────────────────────
try {
    if (wrp_col_exists('webhook_deliveries', 'delivery_uid')) {
        wrp_say('[ok]  webhook_deliveries.delivery_uid already present');
    } else {
        db_query("ALTER TABLE `{$prefix}webhook_deliveries`
                  ADD COLUMN `delivery_uid` VARCHAR(36) DEFAULT NULL AFTER `error`");
        wrp_say('[new] added webhook_deliveries.delivery_uid');
    }
} catch (Throwable $e) {
    $failures[] = 'add delivery_uid: ' . $e->getMessage();
    wrp_say('[FAIL] add delivery_uid: ' . $e->getMessage());
}

// ── 2. Lookup index ─────────────────────────────────────────────────────
// Receivers report a uid when something looks wrong; an operator needs to
// find that delivery without a table scan.
try {
    if (!wrp_col_exists('webhook_deliveries', 'delivery_uid')) {
        wrp_say('[skip] index — column absent (see failure above)');
    } elseif (wrp_index_exists('webhook_deliveries', 'idx_wd_delivery_uid')) {
        wrp_say('[ok]  idx_wd_delivery_uid already present');
    } else {
        db_query("ALTER TABLE `{$prefix}webhook_deliveries`
                  ADD KEY `idx_wd_delivery_uid` (`delivery_uid`)");
        wrp_say('[new] added idx_wd_delivery_uid');
    }
} catch (Throwable $e) {
    $failures[] = 'add idx_wd_delivery_uid: ' . $e->getMessage();
    wrp_say('[FAIL] add idx_wd_delivery_uid: ' . $e->getMessage());
}

// ── 3. Seed the tunables so they are discoverable in Settings ───────────
//
// Both are read with get_variable() (the `settings` table), NOT
// get_setting() (the separate `config` table) — reading a UI-saved value
// from the wrong store returns the default forever.
//
// Seeded with INSERT-if-absent so an admin's existing choice is never
// overwritten on re-run.
if (wrp_table_exists('settings')) {
    $seed = [
        // Freshness window receivers should apply to X-Webhook-Timestamp.
        'webhook_replay_tolerance_sec' => '300',
        // Keep emitting the legacy body-only X-Webhook-Signature header.
        // Default ON: every receiver working today reverse-engineered that
        // scheme, and silently breaking it could stop a station being
        // alerted. Set to 0 once receivers have moved to -V2.
        'webhook_legacy_signature'     => '1',
    ];
    foreach ($seed as $name => $value) {
        try {
            $exists = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
            if ($exists > 0) {
                wrp_say("[ok]  setting {$name} already set — left alone");
            } else {
                db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
                         [$name, $value]);
                wrp_say("[new] seeded setting {$name} = {$value}");
            }
        } catch (Throwable $e) {
            $failures[] = "seed {$name}: " . $e->getMessage();
            wrp_say("[FAIL] seed {$name}: " . $e->getMessage());
        }
    }
} else {
    wrp_say('[skip] settings table absent — tunables fall back to their defaults');
}

// ── 4. Verify the OUTCOME, not that the script ran ──────────────────────
//
// A migration that catches its own exception and exits 0 is a migration
// that never ran. Ask the database whether the schema is actually right.
$verified = wrp_col_exists('webhook_deliveries', 'delivery_uid');
if (!$verified) {
    $failures[] = 'VERIFY: webhook_deliveries.delivery_uid still absent after migration';
    wrp_say('[FAIL] verify: delivery_uid still absent');
} else {
    wrp_say('[ok]  verified: webhook_deliveries.delivery_uid exists');
}

wrp_say(str_repeat('-', 62));
if ($failures) {
    wrp_say('FAILED with ' . count($failures) . ' error(s):');
    foreach ($failures as $f) wrp_say('  - ' . $f);
    exit(1);
}
wrp_say('Done.');
exit(0);
