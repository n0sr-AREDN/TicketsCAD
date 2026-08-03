<?php
/**
 * Phase 129 migration — dmr_channels.bridge_token_format
 *
 * Context: openises/tickets#10 (@kmk1971). `bridge_token` was stored as
 * `hash('sha256', $token)`, but every unattended caller in the CAD has to
 * PRESENT that token to the bridge as `Authorization: Bearer` — and a digest
 * cannot be turned back into the value the bridge compares against. So every
 * server-side call answered 401. See inc/dmr_token.php for the full write-up.
 *
 * The fix stores the plaintext. This migration deals with what is already in
 * the database, which is the awkward part: a SHA-256 digest and a freshly
 * minted token are BOTH 64 hex characters, so an existing value cannot be
 * classified by inspection. We therefore record which era it came from.
 *
 *   bridge_token_format = 'plain'        usable — the CAD can present it
 *   bridge_token_format = 'legacy_hash'  a digest; unrecoverable, must be
 *                                        rotated (or repaired by pasting the
 *                                        saved plaintext into the Test dialog,
 *                                        which adopts it on a successful probe)
 *
 * Every non-empty token that exists at the moment this column is FIRST added
 * was written by the old hashing code, so the backfill runs exactly once — on
 * the ALTER. Re-running this script never re-flags a repaired channel.
 *
 * Ordering note: sql/run_migrations.php sorts scripts lexicographically, so
 * "run_phase129_" sorts BEFORE "run_phase73i_dvswitch_schema.php" (the script
 * that creates dmr_channels). On a fresh install this therefore runs before
 * the table exists — handled below by a table-existence guard, with the column
 * also declared in run_phase73i's CREATE TABLE so fresh installs still get it.
 *
 * Idempotent. Safe to run repeatedly. Exits non-zero if it cannot verify its
 * own outcome — a migration that catches its exception and exits 0 is a
 * migration that never ran.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 129 — DMR bridge token storage format\n";
echo "===========================================\n\n";

function p129_col_exists(string $table, string $col): bool
{
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $col]
    ) > 0;
}

$table = $prefix . 'dmr_channels';

try {
    $tableExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    ) > 0;
} catch (Exception $e) {
    echo "[FATAL] cannot inspect schema: " . $e->getMessage() . "\n";
    exit(1);
}

if (!$tableExists) {
    echo "[--] {$table} does not exist yet — nothing to migrate.\n";
    echo "     (Fresh install: run_phase73i_dvswitch_schema.php creates the\n";
    echo "      table with bridge_token_format already declared.)\n\nDone.\n";
    exit(0);
}

try {
    if (p129_col_exists($table, 'bridge_token_format')) {
        echo "[skip] {$table}.bridge_token_format already present\n";
    } else {
        db_query(
            "ALTER TABLE `{$table}`
               ADD COLUMN `bridge_token_format` VARCHAR(16) NOT NULL
               DEFAULT 'plain' AFTER `bridge_token`"
        );
        echo "[add ] {$table}.bridge_token_format\n";

        // Everything that already had a token got it from the hashing code.
        db_query(
            "UPDATE `{$table}`
                SET `bridge_token_format` = 'legacy_hash'
              WHERE `bridge_token` IS NOT NULL AND `bridge_token` <> ''"
        );
        $flagged = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$table}` WHERE `bridge_token_format` = 'legacy_hash'"
        );
        if ($flagged > 0) {
            echo "\n";
            echo "  !! {$flagged} DMR channel(s) hold a bridge token that was stored as a\n";
            echo "     SHA-256 hash and CANNOT be sent to the bridge. Those channels have\n";
            echo "     never been able to transmit, stream live audio, or report health\n";
            echo "     without an operator pasting the token by hand.\n\n";
            echo "     Fix each one, either way round:\n";
            echo "       a) Settings -> Communications & Integrations -> DMR -> Test, paste the token you\n";
            echo "          saved when the channel was created. A successful probe adopts\n";
            echo "          it — no bridge change needed; or\n";
            echo "       b) Rotate the token, then put the new value in the bridge's\n";
            echo "          DMR_BEARER_TOKEN and restart the bridge.\n\n";
            foreach (db_fetch_all(
                "SELECT `id`, `label`, `talkgroup` FROM `{$table}`
                  WHERE `bridge_token_format` = 'legacy_hash' ORDER BY `id`"
            ) as $row) {
                echo "       - #{$row['id']} {$row['label']} (TG {$row['talkgroup']})\n";
            }
            echo "\n";
        } else {
            echo "  [ok  ] no existing tokens to reclassify\n";
        }
    }
} catch (Exception $e) {
    echo "[FATAL] migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

// ── Verify the OUTCOME, not the fact that we ran ────────────────────────────
try {
    if (!p129_col_exists($table, 'bridge_token_format')) {
        echo "[FATAL] verification failed: {$table}.bridge_token_format is still missing\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "[FATAL] verification query failed: " . $e->getMessage() . "\n";
    exit(1);
}
echo "[ok  ] verified: {$table}.bridge_token_format exists\n";

echo "\nDone.\n";
