<?php
/**
 * Adds `deleted_by_sender_at` to `internal_messages` — lets the SENDER of a
 * message remove it from their own Sent view without touching any
 * recipient's inbox.
 *
 * GH#42 (Chris Byrd, 2026-08-08): "Tried to delete sent message. It does not
 * delete... Messages do delete from the Inbox."
 *
 * ROOT CAUSE: the only soft-delete this feature ever had was
 * `message_recipients.deleted_at`, keyed on `to_user_id` -- it models "this
 * recipient removed their copy". A message you SENT has no
 * `message_recipients` row where `to_user_id` is you (unless you mailed
 * yourself), so clicking Delete from the Sent view updated zero rows: not an
 * error, just silently nothing, and the Sent list was never filtered on any
 * deleted flag in the first place even when it did match. Two independent
 * gaps, same visible symptom.
 *
 * FIX SHAPE: mirrors the existing recipient-scoped pattern instead of
 * inventing a new one -- a second, independent deleted_at column, this time
 * on the sender's row directly (a message has exactly one sender, so it
 * doesn't need its own join table the way recipients do). Deleting your sent
 * copy never touches `message_recipients`; deleting from your inbox never
 * touches this column. Same as any mail client: removing your copy from Sent
 * does not unsend it.
 *
 * WHY A run_*.php WRAPPER: api/messaging.php's inline `CREATE TABLE IF NOT
 * EXISTS` only reaches installs where the table doesn't exist yet.
 * sql/run_migrations.php discovers work via glob('sql/run_*.php') --
 * without this file the column never reaches an existing install.
 *
 * Idempotent: checks information_schema before the ALTER. Safe to re-run.
 *
 * Usage: php sql/run_messaging_sender_delete.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'internal_messages';
$changes = 0;

echo "Messaging sender-delete (internal_messages.deleted_by_sender_at)\n";
echo "==================================================================\n";

try {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );

    if ($exists === 0) {
        echo "  skip `{$table}` — table not present yet (created on first use of Messages)\n";
    } else {
        $has = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_by_sender_at'",
            [$table]
        );
        if ($has === 0) {
            db_query("ALTER TABLE `{$table}` ADD COLUMN `deleted_by_sender_at` DATETIME DEFAULT NULL");
            echo "  added `{$table}`.`deleted_by_sender_at`\n";
            $changes++;
        } else {
            echo "  `{$table}`.`deleted_by_sender_at` already present\n";
        }

        $idx = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_im_deleted_by_sender'",
            [$table]
        );
        if ($idx === 0) {
            try {
                db_query("ALTER TABLE `{$table}` ADD INDEX `idx_im_deleted_by_sender` (`from_user_id`, `deleted_by_sender_at`)");
                echo "  indexed `{$table}`.(`from_user_id`, `deleted_by_sender_at`)\n";
                $changes++;
            } catch (Throwable $e) {
                echo "  [note] could not index `{$table}`: " . $e->getMessage() . "\n";
            }
        }
    }
} catch (Throwable $e) {
    echo "  [WARN] {$table}: " . $e->getMessage() . "\n";
}

echo "\nDone — {$changes} change(s). Re-running is safe.\n";
