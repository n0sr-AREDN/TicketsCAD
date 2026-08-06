<?php
/**
 * Phase 134 (Model 3, GH #23), Step 5 — the LAST piece: wiring
 * mi_attach_message_to_assigned_incidents() into router_evaluate() as a
 * second, unconditional consumer of every inbound message, plus the Model 1
 * fallback route (sql/run_phase134_step5_fallback_route.php) that makes
 * "never silent drop" (spec.md) actually true.
 *
 * specs/phase-134-inbound-routing-model3/{spec.md,plan.md} §6-9. Steps 1-4
 * (dedupe table + comm_modes seed, the real _telegram_receive()/
 * _slack_receive() implementations, the responder->open-assignment->ticket
 * join, the poller) are covered by tests/test_phase134_migration.php,
 * tests/test_phase134_receivers.php, tests/test_phase134_assigned_
 * incidents.php and tests/test_phase134_poller.php respectively — not
 * repeated here.
 *
 * ── WHAT THIS FILE PROVES THAT NO EARLIER STEP DID ──────────────────────
 *
 * Every earlier Phase 134 test drives ONE piece of the chain in isolation.
 * This is the file that proves the pieces actually connect: a single
 * router_evaluate('telegram', 'inbound', $message, null) call — the exact
 * call broker_receive() makes for a real poll — must, in one pass:
 *   (a) write a note to the sender's open assigned incident, when one
 *       exists, via mi_attach_message_to_assigned_incidents(), AND
 *   (b) forward the same message to general dispatch chat via the seeded
 *       Model 1 fallback route,
 * NOT either/or (spec.md's explicit "not either/or" framing, plan.md §6).
 *
 * Driven through the REAL writers throughout (incident_create_internal(),
 * assign_create_internal()), never hand-seeded `assigns` rows — per this
 * project's repeated "test asserts against state the real writer never
 * produces" failure class (CLAUDE.md).
 *
 * ── WHY GENERAL-CHAT DELIVERY IS ASSERTED TWO WAYS ──────────────────────
 *
 * router_evaluate()'s own return value already reports a 'forwarded' status
 * per matched route — cheap and precise, but it is the router's own
 * self-report. To prove the message ACTUALLY reached local_chat (not just
 * that the router believes it did), every scenario also queries the real
 * `chat_messages` table (inc/channels/local_chat.php's _chat_send() writes
 * there synchronously) for a row with the exact test body. Both must agree.
 *
 * The "reached local_chat" check does not assert an exact result-array
 * shape or count, because this is a shared dev database other test files
 * also touch `message_routes` on (test_routing.php, test_router_
 * recipients.php) — a stray route left behind by an unrelated failed run
 * elsewhere must not make THIS file flaky. It asserts "at least one matched
 * route forwarded to local_chat", which is both sufficient and robust.
 *
 * Usage: php tests/test_phase134_router_wiring.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/sse.php';           // local_chat send path
require_once __DIR__ . '/../inc/comm_resolve.php';  // sender resolution
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/broker.php';        // auto-loads inc/channels/*.php + inc/router.php (+ message-incident.php)
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Phase 134 — Inbound routing Model 3 router wiring (Step 5) ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    echo "\nSKIP: no database available — this test needs one\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

if (!function_exists('mi_attach_message_to_assigned_incidents') || !function_exists('router_evaluate')) {
    echo "\nSKIP: Phase 134 resolver / router functions not present\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit(0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
$marker  = substr(md5(uniqid('', true)), 0, 8);

$cleanup = [];
function track(&$cleanup, $table, $id) { $cleanup[$table][] = (int) $id; }

/** First real in_types id on this install (never assume id=1 exists). */
function _p134w_first_in_types_id(): int {
    static $id = null;
    if ($id !== null) return $id;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $id = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    return $id;
}

/** Create a minimal incident through the REAL writer. Throws on failure. */
function _p134w_make_ticket(int $userId, string $label): int {
    $r = incident_create_internal(
        ['in_types_id' => _p134w_first_in_types_id(), 'scope' => 'Phase 134 Step5 test — ' . $label],
        $userId
    );
    if (empty($r['id'])) {
        throw new RuntimeException('incident_create_internal failed: ' . implode('; ', $r['errors'] ?? ['unknown']));
    }
    return (int) $r['id'];
}

/**
 * Bare `member` row. `member.first_name`/`last_name` are GENERATED columns
 * (derived from field1/field2) — naming them directly 1906-errors. This
 * resolver never reads a member's name, so an empty row is sufficient.
 */
function _p134w_make_member(): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query("INSERT INTO `{$prefix}member` () VALUES ()");
    return (int) db_insert_id();
}

/** member -> responder (PERSONNEL/unit_personnel_assignments path) -> open assign on a fresh ticket. */
function _p134w_make_assigned_member(int $adminId, string $marker, string $label, string $handleSuffix): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    global $cleanup, $telegramModeId;

    $memberId = _p134w_make_member();
    track($cleanup, 'member', $memberId);

    $handle = 'p134rtr_' . $handleSuffix . '_' . $marker;
    db_query("INSERT INTO `{$prefix}member_comm_identifiers`
              (`member_id`, `comm_mode_id`, `values_json`, `is_primary`)
              VALUES (?, ?, ?, 1)",
        [$memberId, $telegramModeId, json_encode(['username' => $handle])]);
    track($cleanup, 'member_comm_identifiers', db_insert_id());

    db_query("INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`) VALUES (?, ?, '')",
        ['Phase134w Unit ' . $handleSuffix, 'p134wUnit' . $handleSuffix . '-' . $marker]);
    $responderId = (int) db_insert_id();
    track($cleanup, 'responder', $responderId);

    db_query("INSERT INTO `{$prefix}unit_personnel_assignments`
              (`responder_id`, `member_id`, `status`) VALUES (?, ?, 'active')",
        [$responderId, $memberId]);
    track($cleanup, 'unit_personnel_assignments', db_insert_id());

    $ticketId = _p134w_make_ticket($adminId, $label);
    track($cleanup, 'ticket', $ticketId);

    $assignRes = assign_create_internal($ticketId, $responderId, '', $adminId);
    if (!empty($assignRes['id'])) track($cleanup, 'assigns', $assignRes['id']);

    return ['member_id' => $memberId, 'handle' => $handle, 'ticket_id' => $ticketId,
            'assign_ok' => empty($assignRes['errors'])];
}

/** A resolved member with a Telegram identifier but deliberately NO responder/assign. */
function _p134w_make_unassigned_member(string $marker, string $handleSuffix): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    global $cleanup, $telegramModeId;

    $memberId = _p134w_make_member();
    track($cleanup, 'member', $memberId);

    $handle = 'p134rtr_' . $handleSuffix . '_' . $marker;
    db_query("INSERT INTO `{$prefix}member_comm_identifiers`
              (`member_id`, `comm_mode_id`, `values_json`, `is_primary`)
              VALUES (?, ?, ?, 1)",
        [$memberId, $telegramModeId, json_encode(['username' => $handle])]);
    track($cleanup, 'member_comm_identifiers', db_insert_id());

    return ['member_id' => $memberId, 'handle' => $handle];
}

/** Count `action` (note) rows carrying a specific source_message_id (any ticket). */
function p134w_note_count_by_msgid(int $msgId): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}action` WHERE source_message_id = ?", [$msgId]);
}

/** Count `action` rows on a specific ticket carrying a specific source_message_id. */
function p134w_note_count_on_ticket(int $ticketId, int $msgId): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id = ? AND source_message_id = ?",
        [$ticketId, $msgId]);
}

/** Count `chat_messages` rows with an exact body (local_chat delivery proof). */
function p134w_chat_count_by_body(string $body): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}chat_messages` WHERE `body` = ?", [$body]);
}

/** Did router_evaluate()'s own return value report a 'forwarded' hit on local_chat? */
function p134w_local_chat_forwarded(array $results): bool {
    foreach ($results as $r) {
        if (($r['dest'] ?? null) === 'local_chat' && ($r['status'] ?? null) === 'forwarded') {
            return true;
        }
    }
    return false;
}

/** Run a real subprocess (no shell, separate stdout/stderr) and return [exitCode, stdout]. */
function p134w_run_migration(): array {
    $php    = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $root   = realpath(__DIR__ . '/..');
    $script = $root . '/sql/run_phase134_step5_fallback_route.php';
    $cmd = [$php, $script];
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return [1, ''];
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    return [$rc, (string) $stdout];
}

try {
    $telegramModeId = (int) db_fetch_value("SELECT id FROM `{$prefix}comm_modes` WHERE code = 'telegram'");
    if ($telegramModeId <= 0) {
        throw new RuntimeException('telegram comm_modes row missing — Phase 134 Step 1 seed not present');
    }

    // ─────────────────────────────────────────────────────────────────────
    echo "\n-- 1. The Model 1 fallback route: real migration, idempotent, expected shape --\n";
    // ─────────────────────────────────────────────────────────────────────
    $permsBefore = 0;
    try { $permsBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}permissions`"); } catch (Throwable $e) {}

    [$rc1, $out1] = p134w_run_migration();
    chk($rc1 === 0, 'migration run #1 exits 0', substr($out1, 0, 400));

    $fallbackRows1 = db_fetch_all(
        "SELECT `id`, `enabled`, `source_channel`, `dest_channel`, `direction`, `attach_action`
           FROM `{$prefix}message_routes`
          WHERE `source_channel` = '*' AND `dest_channel` = 'local_chat' AND `direction` = 'inbound'");
    chk(count($fallbackRows1) === 1,
        'exactly one wildcard inbound->local_chat route exists after run #1',
        'found ' . count($fallbackRows1));
    if (count($fallbackRows1) === 1) {
        $row = $fallbackRows1[0];
        chk((int) $row['enabled'] === 1, 'the fallback route is enabled');
        chk($row['attach_action'] === null,
            'the fallback route does NOT carry attach_action — it is not the Phase 111 mechanism',
            var_export($row['attach_action'], true));
    }

    [$rc2, $out2] = p134w_run_migration();
    chk($rc2 === 0, 'migration run #2 (re-run) exits 0', substr($out2, 0, 400));
    $fallbackRows2 = db_fetch_all(
        "SELECT `id` FROM `{$prefix}message_routes`
          WHERE `source_channel` = '*' AND `dest_channel` = 'local_chat' AND `direction` = 'inbound'");
    chk(count($fallbackRows2) === 1,
        'still exactly one such route after re-running the migration (idempotent)',
        'found ' . count($fallbackRows2));
    chk(($fallbackRows1[0]['id'] ?? null) === ($fallbackRows2[0]['id'] ?? null),
        're-running the migration did not replace the row — same id both times');

    $permsAfter = 0;
    try { $permsAfter = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}permissions`"); } catch (Throwable $e) {}
    chk($permsAfter === $permsBefore,
        'no RBAC permission row was added by this migration (plan.md §7: none needed for this step)',
        "before={$permsBefore} after={$permsAfter}");

    $migrationSrc = (string) file_get_contents(__DIR__ . '/../sql/run_phase134_step5_fallback_route.php');
    chk(stripos($migrationSrc, $prefix . 'permissions') === false && stripos($migrationSrc, "'permissions'") === false,
        'the migration source never references the permissions table at all');

    // ─────────────────────────────────────────────────────────────────────
    echo "\n-- 2. Resolved sender, ONE open assignment: BOTH the note AND general chat --\n";
    // ─────────────────────────────────────────────────────────────────────
    $fixH = _p134w_make_assigned_member($adminId, $marker, 'H both-consumers', 'h');
    chk($fixH['assign_ok'], 'fixture: assign_create_internal succeeded for ticket H');

    $bodyH = 'Phase134w test body H ' . $marker;
    $msgIdH = 910001;
    $resultsH = router_evaluate('telegram', 'inbound', ['from' => $fixH['handle'], 'body' => $bodyH, 'message_id' => $msgIdH], null);

    chk(p134w_note_count_on_ticket($fixH['ticket_id'], $msgIdH) === 1,
        'a note WAS written to the sender\'s assigned incident');
    chk(p134w_chat_count_by_body($bodyH) === 1,
        'the SAME message ALSO reached general dispatch chat (chat_messages row exists)');
    chk(p134w_local_chat_forwarded($resultsH),
        "router_evaluate()'s own return value reports a 'forwarded' hit on local_chat",
        var_export($resultsH, true));

    // ─────────────────────────────────────────────────────────────────────
    echo "\n-- 3. Unresolved sender: general chat still happens, no note anywhere --\n";
    // ─────────────────────────────────────────────────────────────────────
    $ghostHandle = 'p134rtr_ghost_' . $marker;
    $bodyGhost = 'Phase134w test body GHOST ' . $marker;
    $msgIdGhost = 910002;
    $resultsGhost = router_evaluate('telegram', 'inbound', ['from' => $ghostHandle, 'body' => $bodyGhost, 'message_id' => $msgIdGhost], null);

    chk(p134w_note_count_by_msgid($msgIdGhost) === 0,
        'an unresolved sender writes NO note anywhere');
    chk(p134w_chat_count_by_body($bodyGhost) === 1,
        'an unresolved sender\'s message STILL reaches general chat — the fallback is not conditional on resolution');
    chk(p134w_local_chat_forwarded($resultsGhost),
        "router_evaluate() reports 'forwarded' to local_chat for the unresolved sender");

    // ─────────────────────────────────────────────────────────────────────
    echo "\n-- 4. Resolved sender, NO open assignment: general chat still happens, no note anywhere --\n";
    // ─────────────────────────────────────────────────────────────────────
    $fixNoAssign = _p134w_make_unassigned_member($marker, 'noassign');
    $bodyNoAssign = 'Phase134w test body NOASSIGN ' . $marker;
    $msgIdNoAssign = 910003;
    $resultsNoAssign = router_evaluate('telegram', 'inbound',
        ['from' => $fixNoAssign['handle'], 'body' => $bodyNoAssign, 'message_id' => $msgIdNoAssign], null);

    chk(p134w_note_count_by_msgid($msgIdNoAssign) === 0,
        'a resolved sender with NO open assignment writes NO note anywhere');
    chk(p134w_chat_count_by_body($bodyNoAssign) === 1,
        'a resolved-but-unassigned sender\'s message STILL reaches general chat');
    chk(p134w_local_chat_forwarded($resultsNoAssign),
        "router_evaluate() reports 'forwarded' to local_chat for the resolved-but-unassigned sender");

    // ─────────────────────────────────────────────────────────────────────
    echo "\n-- 5. A forwarded copy (_is_routed_forward=true) does NOT re-trigger the resolver --\n";
    // ─────────────────────────────────────────────────────────────────────
    // Same assigned fixture as section 2 (member/ticket H) reused with a
    // FRESH body/message_id so section 2's note doesn't contaminate the
    // count here. First prove the baseline still works on this fixture
    // (guards against a false pass from an already-broken resolver),
    // THEN prove the forwarded-copy guard actually suppresses it.
    $bodyH2 = 'Phase134w test body H2 baseline ' . $marker;
    $msgIdH2 = 910004;
    router_evaluate('telegram', 'inbound', ['from' => $fixH['handle'], 'body' => $bodyH2, 'message_id' => $msgIdH2], null);
    chk(p134w_note_count_on_ticket($fixH['ticket_id'], $msgIdH2) === 1,
        'baseline: a second, non-forwarded inbound message from the same sender writes its own new note');

    $bodyH3 = 'Phase134w test body H3 forwarded-copy ' . $marker;
    $msgIdH3 = 910005;
    router_evaluate('telegram', 'inbound', [
        'from'               => $fixH['handle'],
        'body'               => $bodyH3,
        'message_id'         => $msgIdH3,
        '_is_routed_forward' => true,   // marks this as a router-internal forwarded copy
        '_route_depth'       => 1,
        '_routed'            => [],
    ], null);
    chk(p134w_note_count_on_ticket($fixH['ticket_id'], $msgIdH3) === 0,
        'a message flagged _is_routed_forward=true does NOT trigger a second resolve/attach pass — '
        . 'the guard mirrors router_evaluate()\'s own loop-prevention trust rule (!$trusted)');
    chk(p134w_note_count_by_msgid($msgIdH3) === 0,
        'the forwarded-copy message writes no note anywhere at all, not just not on ticket H');

} catch (Throwable $e) {
    echo "[FAIL] setup/exec threw: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $fail++;
} finally {
    // Reverse-dependency cleanup order: notes/assigns before tickets,
    // identifiers/personnel-links before members, responders after their
    // dependents are gone. The seeded fallback ROUTE ITSELF is deliberately
    // left in place — it is the real, permanent Step 5 migration outcome for
    // this install, exactly like Step 1's seeded comm_modes rows are left in
    // place by tests/test_phase134_migration.php. Only THIS FILE's test
    // fixtures (fixtures + the messages/notes they produced) are cleaned up.
    foreach (($cleanup['ticket'] ?? []) as $tid) {
        try { db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]); } catch (Throwable $e) {}
    }
    foreach (['assigns', 'unit_personnel_assignments', 'member_comm_identifiers', 'responder', 'ticket', 'member'] as $t) {
        foreach (($cleanup[$t] ?? []) as $id) {
            try { db_query("DELETE FROM `{$prefix}{$t}` WHERE `id` = ?", [$id]); }
            catch (Throwable $e) { /* best-effort */ }
        }
    }
    try {
        db_query("DELETE FROM `{$prefix}chat_messages` WHERE `body` LIKE ?", ['Phase134w test body%' . $marker]);
    } catch (Throwable $e) { /* best-effort */ }
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
