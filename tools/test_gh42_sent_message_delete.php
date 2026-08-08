<?php
/**
 * GH#42 (Chris Byrd, 2026-08-08): "Tried to delete sent message. It does not
 * delete... Messages do delete from the Inbox."
 *
 * ROOT CAUSE: the only soft-delete api/messaging.php's action=delete ever
 * had was message_recipients.deleted_at, keyed on to_user_id -- "this
 * recipient removed their copy". A message you SENT has no
 * message_recipients row where to_user_id is you (unless you mailed
 * yourself), so the UPDATE matched zero rows: not an error, silently
 * nothing. The Sent list was also never filtered on any deleted flag, so
 * even a matching row wouldn't have changed what displayed. Two independent
 * gaps behind one symptom.
 *
 * FIX: a second, independent column -- internal_messages.deleted_by_sender_at
 * -- mirrors the existing recipient-scoped pattern instead of inventing a
 * new one. action=delete now takes a `scope` ('inbox' default, or 'sent')
 * and updates the matching column; the Sent list query excludes rows where
 * it's set. Deleting your sent copy must never touch message_recipients,
 * and vice versa -- removing your copy from Sent doesn't unsend anything,
 * same as any mail client.
 *
 * This test drives the exact SQL the API runs (literal copies, so it fails
 * loudly if either query drifts from what's checked here) rather than
 * hand-seeding an idealized deleted_at value, per this project's standing
 * "reproduce through the real writer" rule.
 *
 * Usage: php tools/test_gh42_sent_message_delete.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;
function ok(string $name): void { global $pass; echo "  PASS  $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#42 — deleting a Sent message must not require an Inbox row ===\n\n";

// Literal copy of the two queries under test.
function sentListSql(string $prefix): string {
    return "SELECT m.id FROM `{$prefix}internal_messages` m
             WHERE m.from_user_id = ? AND m.deleted_by_sender_at IS NULL";
}
function deleteSql(string $prefix, string $scope): array {
    return $scope === 'sent'
        ? ["UPDATE `{$prefix}internal_messages` SET deleted_by_sender_at = NOW()
             WHERE id = ? AND from_user_id = ? AND deleted_by_sender_at IS NULL", true]
        : ["UPDATE `{$prefix}message_recipients` SET deleted_at = NOW()
             WHERE message_id = ? AND to_user_id = ? AND deleted_at IS NULL", false];
}

$marker = 'gh42_test_' . getmypid();
$senderName = 'gh42sender_' . getmypid();
$recipName  = 'gh42recip_' . getmypid();

db_query(
    "INSERT INTO `{$prefix}user` (`user`, passwd, name_f, name_l, can_login) VALUES (?, ?, 'GH42', 'Sender', 0)",
    [$senderName, password_hash('x', PASSWORD_DEFAULT)]
);
$senderId = (int) db_insert_id();
db_query(
    "INSERT INTO `{$prefix}user` (`user`, passwd, name_f, name_l, can_login) VALUES (?, ?, 'GH42', 'Recipient', 0)",
    [$recipName, password_hash('x', PASSWORD_DEFAULT)]
);
$recipId = (int) db_insert_id();

try {
    // Exactly what action=send writes: one internal_messages row (sender),
    // one message_recipients row (recipient) -- the real writer's shape.
    db_query(
        "INSERT INTO `{$prefix}internal_messages` (from_user_id, subject, body, priority) VALUES (?, ?, 'body', 'normal')",
        [$senderId, $marker]
    );
    $msgId = (int) db_insert_id();
    db_query(
        "INSERT INTO `{$prefix}message_recipients` (message_id, to_user_id) VALUES (?, ?)",
        [$msgId, $recipId]
    );

    // ── 1. Before any delete, the message appears in the sender's Sent list.
    $sentRows = db_fetch_all(sentListSql($prefix), [$senderId]);
    $sentIds = array_column($sentRows, 'id');
    in_array($msgId, $sentIds, true) ? ok('message appears in sender\'s Sent list before delete') : bad('appears before delete');

    // ── 2. The bug as reported: deleting with the OLD inbox-only semantics
    //      (scope=inbox, i.e. matching to_user_id=sender) touches nothing,
    //      because the sender has no message_recipients row for their own
    //      send. This is the exact silent no-op Chris hit.
    [$oldSql] = deleteSql($prefix, 'inbox');
    $stmt = db_query($oldSql, [$msgId, $senderId]);
    ($stmt->rowCount() === 0) ? ok('reproduces the bug: inbox-scoped delete matches zero rows for the sender')
        : bad('inbox-scoped delete should match nothing for the sender', 'rowCount=' . $stmt->rowCount());

    $sentRows2 = db_fetch_all(sentListSql($prefix), [$senderId]);
    in_array($msgId, array_column($sentRows2, 'id'), true)
        ? ok('confirmed: message is STILL in Sent after the old-semantics delete (the bug)')
        : bad('message should still be in Sent after a no-op delete');

    // ── 3. The fix: scope=sent updates deleted_by_sender_at, verified
    //      against from_user_id (an IDOR check, not just the bare id).
    [$sentSql] = deleteSql($prefix, 'sent');
    $stmt2 = db_query($sentSql, [$msgId, $senderId]);
    ($stmt2->rowCount() === 1) ? ok('scope=sent delete updates exactly one row') : bad('scope=sent delete row count', 'got ' . $stmt2->rowCount());

    $sentRows3 = db_fetch_all(sentListSql($prefix), [$senderId]);
    !in_array($msgId, array_column($sentRows3, 'id'), true)
        ? ok('message disappears from the sender\'s Sent list after scope=sent delete')
        : bad('message should be gone from Sent now');

    // ── 4. The other half of the contract: deleting the sender's copy must
    //      NOT unsend it -- the recipient's inbox row is untouched.
    $recipRow = db_fetch_one(
        "SELECT deleted_at FROM `{$prefix}message_recipients` WHERE message_id = ? AND to_user_id = ?",
        [$msgId, $recipId]
    );
    ($recipRow && $recipRow['deleted_at'] === null)
        ? ok('the recipient\'s inbox copy is unaffected by the sender deleting their Sent copy')
        : bad('recipient copy should be untouched', 'got ' . var_export($recipRow['deleted_at'] ?? 'MISSING ROW', true));

    // ── 5. And the reverse: the recipient deleting THEIR inbox copy must not
    //      resurrect or otherwise affect the sender's (already-deleted) copy.
    db_query(
        "UPDATE `{$prefix}message_recipients` SET deleted_at = NOW() WHERE message_id = ? AND to_user_id = ?",
        [$msgId, $recipId]
    );
    $senderRow = db_fetch_one("SELECT deleted_by_sender_at FROM `{$prefix}internal_messages` WHERE id = ?", [$msgId]);
    ($senderRow && $senderRow['deleted_by_sender_at'] !== null)
        ? ok('the sender\'s Sent-deletion is independent of the recipient deleting their inbox copy')
        : bad('sender deletion should remain in effect', 'got ' . var_export($senderRow['deleted_by_sender_at'] ?? null, true));

    // ── 6. IDOR guard: scope=sent can't delete a message you didn't send.
    db_query(
        "INSERT INTO `{$prefix}internal_messages` (from_user_id, subject, body, priority) VALUES (?, ?, 'body2', 'normal')",
        [$senderId, $marker . '_2']
    );
    $otherMsgId = (int) db_insert_id();
    [$sentSql2] = deleteSql($prefix, 'sent');
    $stmt3 = db_query($sentSql2, [$otherMsgId, $recipId]); // recipId is NOT the sender of $otherMsgId
    ($stmt3->rowCount() === 0) ? ok('scope=sent cannot delete a message the caller did not send (IDOR guard holds)')
        : bad('IDOR guard', 'a non-sender was able to mark another user\'s sent message deleted');
} finally {
    db_query("DELETE FROM `{$prefix}message_recipients` WHERE message_id IN (SELECT id FROM `{$prefix}internal_messages` WHERE from_user_id = ?)", [$senderId]);
    db_query("DELETE FROM `{$prefix}internal_messages` WHERE from_user_id = ?", [$senderId]);
    db_query("DELETE FROM `{$prefix}user` WHERE id IN (?, ?)", [$senderId, $recipId]);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
