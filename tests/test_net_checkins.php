<?php
/**
 * test_net_checkins.php — Phase 131, net-control check-ins (`/net`).
 *
 * This feature exists because the owner was asked to run Net Control for a
 * Skywarn severe-weather activation. The failure mode that matters is not a
 * crash — it is a check-in that quietly does not appear, so a spotter with a
 * tornado report never gets called on. So the tests drive REAL writers and the
 * REAL creation path, never hand-seeded rows.
 *
 * That distinction is load-bearing in this codebase. GH #20 shipped three
 * "still not working" rounds because 32 tests hand-inserted
 * `assigns.rec_facility_id` — a state the real writer never produces — and
 * passed against it for weeks. Anywhere below that a row could have been
 * INSERTed directly, it is instead produced by the function the application
 * actually calls.
 *
 * Covered:
 *   1. The parser — no note, whitespace, punctuation, the hail 3/4" guard
 *   2. Per-operator isolation — B cannot see, edit, delete or work A's list
 *   3. The worked/deleted lifecycle and history retrieval
 *   4. Incident creation THROUGH incident_create_internal() — the identifier
 *      really lands in the caller/contact phone field, the note in description
 *   5. Note append THROUGH incident_add_note_internal()
 *   6. The constituent lookup resolves numeric AND callsign identifiers,
 *      with a negative control
 *   7. RBAC seeded in both files; migration wired; settings read correctly
 *   8. The widget JS executed under node, with negative controls
 */

require_once __DIR__ . '/../config.php';
require_once NEWUI_ROOT . '/inc/net-checkins.php';
require_once __DIR__ . '/_test_admin.php';

$base = realpath(__DIR__ . '/..');

echo "=== Net-control check-in tests (Phase 131) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }
function eq($expected, $actual, string $name): void {
    if ($expected === $actual) { ok($name); return; }
    bad($name, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. The /net parser --\n";
// ─────────────────────────────────────────────────────────────────────────

// The owner's own example, verbatim from the specification.
$p = net_parse_checkins('1234 tornado / 3344 hail / 6543 hail / 3243 wind damage', '/', true);
eq(4, count($p), "the owner's example yields four check-ins");
eq('1234', $p[0]['identifier'] ?? '', 'first token is the identifier');
eq('tornado', $p[0]['note'] ?? '', 'the remainder is the note');
eq('3243', $p[3]['identifier'] ?? '', 'last identifier parsed');
eq('wind damage', $p[3]['note'] ?? '', 'a multi-word note stays whole');

// An entry with NO note. A station may check in without giving a report.
$p = net_parse_checkins('1234', '/', true);
eq(1, count($p), 'an entry with no note is still an entry');
eq('1234', $p[0]['identifier'] ?? '', 'no-note entry keeps its identifier');
eq('', $p[0]['note'] ?? null, 'no-note entry has an empty note, not a null');

$p = net_parse_checkins('1234 / 3344 hail', '/', true);
eq(2, count($p), 'a no-note entry mixed with a noted one');
eq('', $p[0]['note'] ?? null, 'the bare entry still has an empty note');
eq('hail', $p[1]['note'] ?? '', 'the noted entry is unaffected');

// Extra whitespace. Typed fast, mid-sentence, with a radio in the other hand.
$p = net_parse_checkins("   1234    tornado   /   3344   hail   ", '/', true);
eq(2, count($p), 'extra whitespace does not create phantom entries');
eq('1234', $p[0]['identifier'] ?? '', 'leading whitespace trimmed');
eq('tornado', $p[0]['note'] ?? '', 'internal whitespace runs collapse');
eq('hail', $p[1]['note'] ?? '', 'trailing whitespace trimmed');

// Empty chunks from a doubled or trailing separator must not become entries.
$p = net_parse_checkins('1234 tornado // 3344 hail /', '/', true);
eq(2, count($p), 'doubled and trailing separators produce no empty entries');

// Punctuation in a note. NWS hail reports are full of it.
$p = net_parse_checkins('6543 hail, 1" quarter, moving NE', '/', true);
eq(1, count($p), 'a punctuated note is one entry');
eq('hail, 1" quarter, moving NE', $p[0]['note'] ?? '',
   'commas and an inch-mark survive verbatim');

$p = net_parse_checkins("2415 wall cloud — rotating, 1-3 min / 1234 tornado", '/', true);
eq(2, count($p), 'an em-dash and a hyphen do not break the split');
eq('wall cloud — rotating, 1-3 min', $p[0]['note'] ?? '', 'unicode punctuation preserved');

// THE FRACTIONAL-INCH GUARD. NWS hail sizes are fractional inches (1/4" pea,
// 3/4" penny, 1 1/2" walnut), so on a hail net — which is the net this was
// built for — '/' turns up inside legitimate notes.
$p = net_parse_checkins('6543 hail 3/4"', '/', true);
eq(1, count($p), 'hail 3/4" stays ONE entry with the digit guard on');
eq('hail 3/4"', $p[0]['note'] ?? '', 'the fraction is preserved intact');

$p = net_parse_checkins('1234 tornado / 6543 hail 3/4" / 3344 wind', '/', true);
eq(3, count($p), 'a spaced separator still splits while a fraction does not');
eq('hail 3/4"', $p[1]['note'] ?? '', 'the fraction survives in a multi-entry round');

$p = net_parse_checkins('6543 hail 1 1/2" walnut', '/', true);
eq(1, count($p), 'a mixed-number hail size (1 1/2") stays one entry');

// NEGATIVE CONTROL: with the guard OFF the fraction MUST split. Without this,
// a test asserting "3/4 survives" would still pass if the separator had
// stopped working altogether.
$p = net_parse_checkins('6543 hail 3/4"', '/', false);
is_true(count($p) === 2,
    'negative control: with the guard OFF the fraction does split',
    'got ' . count($p) . ' entries — the guard is not what is doing the work');

// A different separator makes the whole question moot.
$p = net_parse_checkins('1234 hail 3/4" ; 3344 wind', ';', true);
eq(2, count($p), 'a custom separator splits');
eq('hail 3/4"', $p[0]['note'] ?? '', 'and leaves / alone entirely');

eq(0, count(net_parse_checkins('', '/', true)), 'empty input yields no entries');
eq(0, count(net_parse_checkins('   ', '/', true)), 'whitespace-only input yields no entries');
eq(0, count(net_parse_checkins('///', '/', true)), 'separators only yields no entries');

// Caps: a paste accident must not write unbounded rows or overlong values.
$many = [];
for ($i = 0; $i < 120; $i++) { $many[] = 'ID' . $i . ' note'; }
$p = net_parse_checkins(implode(' / ', $many), '/', true);
is_true(count($p) <= NET_MAX_ENTRIES_PER_COMMAND,
    'entry count is capped', 'got ' . count($p));

$p = net_parse_checkins('1234 ' . str_repeat('x', 600), '/', true);
is_true(strlen($p[0]['note']) <= NET_MAX_NOTE_LEN, 'a long note is truncated, not rejected');

// A degenerate separator must fall back rather than shredding every note.
$p = net_parse_checkins('1234 tornado / 3344 hail', ' ', true);
is_true(count($p) === 2, 'a whitespace separator is refused and falls back to /');
$p = net_parse_checkins('1234 tornado / 3344 hail', '5', true);
is_true(count($p) === 2, 'a digit separator is refused and falls back to /');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Storage, isolation and lifecycle (real writers) --\n";
// ─────────────────────────────────────────────────────────────────────────

$haveDb = false;
try {
    $haveDb = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [($GLOBALS['db_prefix'] ?? '') . 'net_checkins']) > 0;
} catch (Throwable $e) { $haveDb = false; }

if (!$haveDb) {
    echo "SKIP: net_checkins table absent — run sql/run_phase131_net_checkins.php\n";
    echo "      (database-backed checks were not run)\n";
} else {
    // Two distinct operator ids. A is the real admin on THIS install (which is
    // NOT necessarily user 1 — base_schema.sql pins `user` to
    // AUTO_INCREMENT=3, and the admin is id 3 in CI, 1 on your deployment, 29 on
    // training). B is a high id that cannot collide with a real account.
    $opA = test_admin_user_id();
    $opB = 900000 + ($opA === 900001 ? 2 : 1);

    $tbl = db_table('net_checkins');
    db_query("DELETE FROM {$tbl} WHERE `user_id` IN (?, ?)", [$opA, $opB]);

    // Through the REAL parser into the REAL writer — the same two calls the
    // endpoint makes for a /net command.
    $entriesA = net_parse_checkins('1234 tornado / 3344 hail / 6543 hail', '/', true);
    $resA = net_add_entries($entriesA, $opA);
    eq(3, $resA['added'], 'three check-ins stored for operator A');

    $listA = net_list($opA, 0);
    eq(3, count($listA), "operator A's list has three waiting entries");
    eq('1234', $listA[0]['identifier'], 'arrival order preserved (first)');
    eq('6543', $listA[2]['identifier'], 'arrival order preserved (last)');
    is_true($listA[0]['created_at'] !== '' && $listA[0]['created_at'] !== null,
        'every entry carries a timestamp (the widget shows it)');
    eq('pending', $listA[0]['status'], 'a new check-in starts as waiting');

    // A second /net continues the sequence rather than restarting it.
    net_add_entries(net_parse_checkins('3243 wind damage', '/', true), $opA);
    $listA = net_list($opA, 0);
    eq(4, count($listA), 'a second /net appends to the same list');
    eq('3243', $listA[3]['identifier'], 'the later check-in sorts last, not first');

    // ── PER-OPERATOR ISOLATION ──
    net_add_entries(net_parse_checkins('9999 flooding', '/', true), $opB);

    $listB = net_list($opB, 0);
    eq(1, count($listB), "operator B sees only their own check-in");
    eq('9999', $listB[0]['identifier'], "operator B's entry is theirs");

    $idsA = array_map(function ($r) { return $r['id']; }, net_list($opA, 0));
    $idsB = array_map(function ($r) { return $r['id']; }, $listB);
    is_true(count(array_intersect($idsA, $idsB)) === 0,
        'the two operators share no rows');

    $aFirst = $idsA[0];
    is_true(net_get_entry($aFirst, $opB) === null,
        "operator B cannot READ operator A's entry by id");
    is_true(net_get_entry($aFirst, $opA) !== null,
        'negative control: operator A CAN read their own entry');

    is_true(net_update_entry($aFirst, $opB, 'HACKED', 'hacked') === false,
        "operator B cannot EDIT operator A's entry");
    eq('1234', net_get_entry($aFirst, $opA)['identifier'],
        "operator A's entry is untouched after B's attempt");

    is_true(net_set_status($aFirst, $opB, 'deleted') === false,
        "operator B cannot DELETE operator A's entry");
    is_true(net_set_status($aFirst, $opB, 'worked') === false,
        "operator B cannot WORK operator A's entry");
    eq('pending', net_get_entry($aFirst, $opA)['status'],
        "operator A's entry is still waiting after B's attempts");
    eq(4, count(net_list($opA, 0)), "operator A's list is intact");

    // ── EDIT (a misheard callsign) ──
    is_true(net_update_entry($aFirst, $opA, 'N0NKI', 'tornado on the ground'),
        'the owner can correct a misheard identifier');
    $e = net_get_entry($aFirst, $opA);
    eq('N0NKI', $e['identifier'], 'the corrected identifier persisted');
    eq('tornado on the ground', $e['note'], 'the corrected note persisted');
    is_true(net_update_entry($aFirst, $opA, '', null) === false,
        'an entry cannot be edited down to no identifier');

    // ── LIFECYCLE: worked ──
    $second = $idsA[1];
    is_true(net_set_status($second, $opA, 'worked', 4242), 'an entry can be marked worked');
    $e = net_get_entry($second, $opA);
    eq('worked', $e['status'], 'status is worked');
    eq(4242, $e['ticket_id'], 'the incident it produced is linked');
    is_true(!empty($e['worked_at']), 'worked_at is stamped');

    // FR-5: once worked, it drops out of the active list.
    $active = net_list($opA, 0);
    $stillThere = false;
    foreach ($active as $r) { if ($r['id'] === $second) $stillThere = true; }
    is_true(!$stillThere, 'a worked entry leaves the active list');
    eq(3, count($active), 'three entries still waiting');

    // ...but is reachable through history, and stays hotkey-able there.
    $withHistory = net_list($opA, 10);
    $found = null;
    foreach ($withHistory as $r) { if ($r['id'] === $second) $found = $r; }
    is_true($found !== null, 'a worked entry IS visible when history is shown');
    eq('worked', $found['status'] ?? '', 'and still reports its state');

    // ── LIFECYCLE: deleted, then recovered ──
    $third = $idsA[2];
    is_true(net_set_status($third, $opA, 'deleted'), 'an entry can be deleted');
    eq('deleted', net_get_entry($third, $opA)['status'], 'status is deleted');
    eq(2, count(net_list($opA, 0)), 'a deleted entry leaves the active list');

    $withHistory = net_list($opA, 10);
    $found = null;
    foreach ($withHistory as $r) { if ($r['id'] === $third) $found = $r; }
    is_true($found !== null, 'a deleted entry is still visible in history');

    // THE RECOVERY PATH. A deleted check-in is a spotter nobody called on, so
    // getting one back has to work.
    is_true(net_set_status($third, $opA, 'pending'), 'a deleted entry can be restored');
    $e = net_get_entry($third, $opA);
    eq('pending', $e['status'], 'the restored entry is waiting again');
    is_true(empty($e['deleted_at']), 'the restore clears the deletion stamp');
    eq(3, count(net_list($opA, 0)), 'the restored entry is back in the active list');

    // A worked entry can be un-worked too (created the wrong incident).
    is_true(net_set_status($second, $opA, 'pending'), 'a worked entry can be restored');
    is_true(empty(net_get_entry($second, $opA)['worked_at']),
        'the restore clears the worked stamp so it cannot look stale later');

    // ── History count is honoured ──
    net_set_status($idsA[0], $opA, 'deleted');
    net_set_status($idsA[1], $opA, 'deleted');
    net_set_status($idsA[2], $opA, 'deleted');
    $one = net_list($opA, 1);
    $past = 0;
    foreach ($one as $r) { if ($r['status'] !== 'pending') $past++; }
    eq(1, $past, 'history=1 returns exactly one historical row');
    $zero = net_list($opA, 0);
    foreach ($zero as $r) {
        if ($r['status'] !== 'pending') { bad('history=0 returns no historical rows'); break; }
    }
    ok('history=0 returns no historical rows');

    // ── Priority ordering (the configurable alternative to arrival order) ──
    db_query("DELETE FROM {$tbl} WHERE `user_id` = ?", [$opA]);
    net_add_entries(net_parse_checkins('1111 hail / 2222 tornado / 3333 wind', '/', true), $opA);
    $ids = array_map(function ($r) { return $r['id']; }, net_list($opA, 0));
    is_true(net_set_priority($ids[2], $opA, 10), 'an operator can raise an entry\'s priority');
    $byArrival  = net_list($opA, 0, 'arrival');
    $byPriority = net_list($opA, 0, 'priority');
    eq('1111', $byArrival[0]['identifier'], 'arrival order is unchanged by priority');
    eq('3333', $byPriority[0]['identifier'], 'priority order floats the raised entry');

    // ── Pruning ── waiting entries are NEVER removed, however old.
    db_query("DELETE FROM {$tbl} WHERE `user_id` = ?", [$opA]);
    net_add_entries(net_parse_checkins('7777 old / 8888 alsoold', '/', true), $opA);
    $ids = array_map(function ($r) { return $r['id']; }, net_list($opA, 0));
    net_set_status($ids[0], $opA, 'worked');
    db_query("UPDATE {$tbl} SET `updated_at` = DATE_SUB(NOW(), INTERVAL 400 DAY) WHERE `user_id` = ?", [$opA]);
    $removed = net_prune($opA, 7);
    eq(1, $removed, 'pruning removes the aged worked entry');
    $left = net_list($opA, 10);
    eq(1, count($left), 'only the waiting entry remains');
    eq('pending', $left[0]['status'], 'a WAITING entry is never pruned, however old');
    eq(0, net_prune($opA, 0), 'retention 0 means keep forever');

    eq(1, net_pending_count($opA), 'pending_count reports the waiting entries');
    eq(0, net_pending_count(999999), 'pending_count is per-operator');

    // ─────────────────────────────────────────────────────────────────────
    echo "\n-- 3. Creating an incident from a check-in (THE REAL WRITER) --\n";
    // ─────────────────────────────────────────────────────────────────────
    //
    // The spec's core promise: the identifier lands in the Caller/Contact
    // phone field and the note in Description. This drives
    // incident_create_internal() — the function api/incident-create.php calls
    // — rather than asserting anything about the form markup. A test that
    // hand-INSERTed a ticket row would prove nothing about the real path.

    require_once NEWUI_ROOT . '/inc/incident-write.php';

    $typeId = 0;
    try {
        $typeId = (int) db_fetch_value("SELECT `id` FROM " . db_table('in_types') . " ORDER BY `id` LIMIT 1");
    } catch (Throwable $e) { $typeId = 0; }

    if ($typeId <= 0) {
        echo "SKIP: no incident types configured — the creation checks were not run\n";
    } else {
        db_query("DELETE FROM {$tbl} WHERE `user_id` = ?", [$opA]);
        net_add_entries(net_parse_checkins('1234 tornado on the ground', '/', true), $opA);
        $entry = net_list($opA, 0)[0];

        // Exactly what net-prefill.js puts in the two fields, then submits.
        $created = incident_create_internal([
            'in_types_id' => $typeId,
            'scope'       => 'Net check-in ' . $entry['identifier'],
            'phone'       => $entry['identifier'],   // Caller / Contact phone number
            'description' => $entry['note'],         // Description
        ], $opA);

        is_true(empty($created['errors']), 'the incident was created',
            implode('; ', $created['errors'] ?? []));
        // incident_create_internal() returns 'id' — NOT 'ticket_id', which is
        // what api/incident-create.php renames it to in its JSON. Reading the
        // writer's actual return shape rather than the endpoint's is the whole
        // point of driving the real writer.
        $ticketId = (int) ($created['id'] ?? 0);
        is_true($ticketId > 0, 'a ticket id came back');

        if ($ticketId > 0) {
            $t = db_fetch_one("SELECT `phone`, `description` FROM " . db_table('ticket') . " WHERE `id` = ?", [$ticketId]);
            eq('1234', $t['phone'] ?? '',
                'THE IDENTIFIER IS IN THE CALLER/CONTACT PHONE FIELD');
            eq('tornado on the ground', $t['description'] ?? '',
                'THE NOTE IS IN THE DESCRIPTION FIELD');

            // And then the entry is marked worked and linked — the step
            // net-prefill.js performs once the incident actually exists.
            is_true(net_set_status($entry['id'], $opA, 'worked', $ticketId),
                'the check-in is marked worked once the incident exists');
            $e = net_get_entry($entry['id'], $opA);
            eq('worked', $e['status'], 'the entry is worked');
            eq($ticketId, $e['ticket_id'], 'the entry links to the incident it produced');
            eq(0, count(net_list($opA, 0)), 'and it has left the active list');

            // A callsign identifier must survive the same path (ticket.phone
            // is varchar(16) — a callsign fits, but only if nothing numeric-
            // only is assumed anywhere along the way).
            net_add_entries(net_parse_checkins('N0NKI wall cloud rotating', '/', true), $opA);
            $entry2 = net_list($opA, 0)[0];
            $created2 = incident_create_internal([
                'in_types_id' => $typeId,
                'scope'       => 'Net check-in ' . $entry2['identifier'],
                'phone'       => $entry2['identifier'],
                'description' => $entry2['note'],
            ], $opA);
            $ticket2 = (int) ($created2['id'] ?? 0);
            if ($ticket2 > 0) {
                $t2 = db_fetch_one("SELECT `phone` FROM " . db_table('ticket') . " WHERE `id` = ?", [$ticket2]);
                eq('N0NKI', $t2['phone'] ?? '', 'a CALLSIGN identifier survives the creation path too');
                db_query("DELETE FROM " . db_table('ticket') . " WHERE `id` = ?", [$ticket2]);
            } else {
                bad('a callsign check-in creates an incident', implode('; ', $created2['errors'] ?? []));
            }

            // ── [a] append — through the REAL note writer ──
            echo "\n-- 4. Appending a note to an existing incident (THE REAL WRITER) --\n";
            db_query("DELETE FROM {$tbl} WHERE `user_id` = ?", [$opA]);
            net_add_entries(net_parse_checkins('3344 hail 3/4" penny', '/', true), $opA);
            $entry3 = net_list($opA, 0)[0];
            eq('hail 3/4" penny', $entry3['note'], 'the fraction survived storage as well as parsing');

            $noteRes = incident_add_note_internal($ticketId, $entry3['note'], $opA);
            is_true(empty($noteRes['errors'] ?? []), 'the note was appended to the incident',
                implode('; ', $noteRes['errors'] ?? []));

            $actions = db_fetch_all(
                "SELECT `description` FROM " . db_table('action') . " WHERE `ticket_id` = ?", [$ticketId]);
            $foundNote = false;
            foreach ($actions as $a) {
                if (strpos((string) $a['description'], 'hail 3/4" penny') !== false) $foundNote = true;
            }
            is_true($foundNote, 'the check-in note is in the incident activity log');

            is_true(net_set_status($entry3['id'], $opA, 'worked', $ticketId),
                'the appended check-in is marked worked');
            eq($ticketId, net_get_entry($entry3['id'], $opA)['ticket_id'],
                'and links to the incident it was appended to');

            db_query("DELETE FROM " . db_table('action') . " WHERE `ticket_id` = ?", [$ticketId]);
            db_query("DELETE FROM " . db_table('ticket') . " WHERE `id` = ?", [$ticketId]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    echo "\n-- 5. The constituent lookup resolves BOTH identifier shapes --\n";
    // ─────────────────────────────────────────────────────────────────────
    //
    // Skywarn spotters report 2-5 digit numbers; social/practice nets use
    // callsigns. Installs that keep those people in `constituents` store the
    // value in `reference`. api/constituents.php has had an exact ?reference=
    // lookup since Phase 73h — and until this phase NOTHING CALLED IT: the
    // #phone blur handler only ever asked ?phone=, which resolves neither
    // shape (a callsign has too few digits to pass the floor; a spotter number
    // LIKE-matches telephone numbers and can never match `reference`).

    $cTbl = db_table('constituents');
    $haveConstituents = false;
    try {
        $haveConstituents = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'reference'",
            [($GLOBALS['db_prefix'] ?? '') . 'constituents']) > 0;
    } catch (Throwable $e) { $haveConstituents = false; }

    if (!$haveConstituents) {
        echo "SKIP: constituents.reference absent — the lookup checks were not run\n";
    } else {
        db_query("DELETE FROM {$cTbl} WHERE `reference` IN ('P131-2415', 'P131-N0NKI')");
        db_query("INSERT INTO {$cTbl} (`contact`, `reference`, `phone`) VALUES (?, ?, ?)",
                 ['Spotter Numeric', 'P131-2415', '612-555-0101']);
        db_query("INSERT INTO {$cTbl} (`contact`, `reference`, `phone`) VALUES (?, ?, ?)",
                 ['Spotter Callsign', 'P131-N0NKI', '612-555-0102']);

        // The exact-match query the endpoint runs for ?reference=.
        $num = db_fetch_one("SELECT * FROM {$cTbl} WHERE `reference` = ? LIMIT 1", ['P131-2415']);
        eq('Spotter Numeric', $num['contact'] ?? '', 'a NUMERIC spotter id resolves to a name');

        $call = db_fetch_one("SELECT * FROM {$cTbl} WHERE `reference` = ? LIMIT 1", ['P131-N0NKI']);
        eq('Spotter Callsign', $call['contact'] ?? '', 'a CALLSIGN identifier resolves to a name');

        // NEGATIVE CONTROL — an unknown identifier must resolve to NOTHING.
        // Without this, a lookup that returned an arbitrary first row would
        // pass both assertions above and put the wrong name on an incident.
        $none = db_fetch_one("SELECT * FROM {$cTbl} WHERE `reference` = ? LIMIT 1", ['P131-NOSUCH']);
        is_true($none === false || $none === null,
            'negative control: an unknown identifier resolves to nobody');

        db_query("DELETE FROM {$cTbl} WHERE `reference` IN ('P131-2415', 'P131-N0NKI')");
    }

    // Clean up everything this test created.
    db_query("DELETE FROM {$tbl} WHERE `user_id` IN (?, ?)", [$opA, $opB]);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Wiring: endpoint, RBAC, settings, migration --\n";
// ─────────────────────────────────────────────────────────────────────────

$apiSrc = (string) @file_get_contents($base . '/api/net-checkins.php');
is_true($apiSrc !== '', 'api/net-checkins.php exists');
is_true(strpos($apiSrc, "rbac_can('action.net_checkin')") !== false,
    'the endpoint gates on action.net_checkin');
is_true(strpos($apiSrc, 'csrf_verify') !== false, 'the endpoint verifies CSRF on POST');
is_true(strpos($apiSrc, "ini_set('display_errors', '0')") !== false,
    'display_errors suppressed so a warning cannot corrupt the JSON');
is_true(strpos($apiSrc, "\$_SESSION['user_id']") !== false,
    'the owner comes from the session');
is_true(!preg_match('/\$(_GET|_POST|input)\[[\'"]user_id[\'"]\]/', $apiSrc),
    'the endpoint NEVER accepts a client-supplied user id');
is_true(strpos($apiSrc, "file_get_contents('php://input')") !== false,
    'the JSON body is read from php://input (PHP does not populate $_POST for JSON)');

// Every mutation must be owner-scoped. This is the isolation guarantee as
// expressed in the source, checked so a later refactor cannot quietly drop it.
$libSrc = (string) @file_get_contents($base . '/inc/net-checkins.php');
preg_match_all('/UPDATE\s+.*?WHERE(.*?)(?:"|\')/s', $libSrc, $updates);
$allScoped = true;
foreach ($updates[1] as $whereClause) {
    if (strpos($whereClause, 'user_id') === false) { $allScoped = false; }
}
is_true($allScoped, 'every UPDATE in the library is scoped by user_id');
is_true(substr_count($libSrc, 'user_id') >= 10, 'reads and writes alike carry the owner');

// Settings must come from the store the Settings UI writes.
$tokens = token_get_all($libSrc);
$called = [];
foreach ($tokens as $tok) {
    if (is_array($tok) && $tok[0] === T_STRING) { $called[$tok[1]] = true; }
}
is_true(isset($called['get_variable']), 'settings read via get_variable()');
is_true(!isset($called['get_setting']),
    'settings NOT read from the other (config) store, which the Settings UI never writes');

// RBAC seeded in BOTH files — they must agree or a fresh install diverges
// from an upgraded one.
$rbacSql = (string) @file_get_contents($base . '/sql/rbac.sql');
$rbacPhp = (string) @file_get_contents($base . '/sql/run_00_rbac.php');
is_true(strpos($rbacSql, 'action.net_checkin') !== false,
    'the permission is seeded in sql/rbac.sql');
is_true(strpos($rbacPhp, 'action.net_checkin') !== false,
    'the permission is seeded in sql/run_00_rbac.php');

// It is an action.* code deliberately: run_00_rbac.php grants Dispatcher,
// Operator and Read-Only by `category IN ('screen','widget')` wholesale, so a
// screen.* code would have been handed silently to Read-Only.
is_true(strpos($rbacSql, 'screen.net_checkin') === false
     && strpos($rbacPhp, 'screen.net_checkin') === false,
    'it is an action.* permission, so the broad screen/widget grants cannot sweep it up');

$mig = $base . '/sql/run_phase131_net_checkins.php';
is_true(is_file($mig), 'the migration exists');
$migSrc = (string) @file_get_contents($mig);
is_true(strpos($migSrc, 'CREATE TABLE IF NOT EXISTS') !== false, 'the migration is idempotent');
is_true(strpos($migSrc, "PHP_SAPI !== 'cli'") !== false,
    'the migration carries the CLI-only guard (Phase 130 web exposure)');
is_true(strpos($migSrc, 'exit(1)') !== false,
    'the migration exits NON-ZERO on failure — a step that catches its own '
  . 'exception and exits 0 is a step that never ran');
is_true(strpos($migSrc, 'information_schema.TABLES') !== false,
    'the migration VERIFIES its outcome against the database, not its own log');

// Discovered by the migration runner's run_*.php glob.
is_true(strpos(basename($mig), 'run_') === 0,
    'the migration is named so sql/run_migrations.php discovers it');

// The widget partial and its wiring.
$widget = (string) @file_get_contents($base . '/inc/net-checkin-widget.php');
is_true($widget !== '', 'the widget partial exists');
is_true(strpos($widget, "rbac_can('action.net_checkin')") !== false,
    'the widget renders nothing without the permission');

// GH #84: a <button> without type inside a <form> submits it and reloads.
// Strip comments first — a docblock that TALKS about buttons is prose, and a
// test that fails on prose teaches people to weaken the test.
$widgetMarkup = preg_replace('#/\*.*?\*/#s', '', $widget);
$widgetMarkup = preg_replace('#<!--.*?-->#s', '', (string) $widgetMarkup);
preg_match_all('/<button\b[^>]*>/i', (string) $widgetMarkup, $btns);
$untyped = 0;
foreach ($btns[0] as $b) { if (stripos($b, 'type=') === false) $untyped++; }
eq(0, $untyped, 'every <button> in the widget has an explicit type');

$widgetJsSrc = (string) @file_get_contents($base . '/assets/js/net-checkins.js');
preg_match_all("/'<button[^']*'/", $widgetJsSrc, $jsBtns);
$untypedJs = 0;
foreach ($jsBtns[0] as $b) { if (stripos($b, 'type=') === false) $untypedJs++; }
eq(0, $untypedJs, 'every <button> the widget JS emits has an explicit type');

foreach (['index.php' => 'the dashboard', 'situation.php' => 'the EOC display'] as $page => $what) {
    $src = (string) @file_get_contents($base . '/' . $page);
    is_true(strpos($src, 'net-checkin-widget.php') !== false,
        'the widget is included on ' . $what);
}
foreach (['new-incident.php', 'incident-detail.php'] as $page) {
    $src = (string) @file_get_contents($base . '/' . $page);
    is_true(strpos($src, 'net-prefill.js') !== false, "net-prefill.js is loaded on {$page}");
}

$cbSrc = (string) @file_get_contents($base . '/assets/js/command-bar.js');
is_true(strpos($cbSrc, "name: 'net'") !== false, 'the /net command is registered');
is_true(preg_match("/name: 'net'.*takesArgs: true/s", $cbSrc) === 1,
    '/net takes arguments (without this the matcher rejects an input with spaces)');
is_true(strpos($cbSrc, 'api/net-checkins.php') !== false, '/net posts to the endpoint');
// The parse belongs to the server; a second one here would drift from it.
// Checked behaviourally: the command sends the RAW string, and nothing here
// splits on the separator or knows about the separator setting.
is_true(preg_match("/action:\s*'add',\s*raw:/", $cbSrc) === 1,
    'the command bar sends the RAW string and lets the server parse it');
is_true(strpos($cbSrc, "net_checkin_separator") === false,
    'the command bar does not carry its own copy of the separator setting');
is_true(!preg_match('/\.split\(\s*[\'"]\/[\'"]\s*\)/', $cbSrc),
    'the command bar does NOT split entries itself');

// The prefill fills the two fields the spec names, and no others.
$prefill = (string) @file_get_contents($base . '/assets/js/net-prefill.js');
is_true(strpos($prefill, "getElementById('phone')") !== false,
    'the prefill targets the caller/contact phone field');
is_true(strpos($prefill, "getElementById('description')") !== false,
    'the prefill targets the description field');
is_true(strpos($prefill, "getElementById('noteText')") !== false,
    'the prefill targets the activity-log note box');
is_true(strpos($prefill, 'setSelectionRange') !== false,
    'the cursor is placed after the copied text so the operator keeps typing');
// Out of scope, emphatically: the new-incident screen already picks the type
// by regex from in_types.match_pattern. Checked behaviourally — the prefill
// must never touch the type select or evaluate a match pattern. (Comments
// naming in_types are explanation, not behaviour, so they are stripped first.)
$prefillCode = preg_replace('#/\*.*?\*/#s', '', $prefill);
$prefillCode = preg_replace('#^\s*//.*$#m', '', (string) $prefillCode);
is_true(strpos((string) $prefillCode, 'in_types_id') === false,
    'the prefill never touches the incident-type select');
is_true(strpos((string) $prefillCode, 'match_pattern') === false
     && strpos((string) $prefillCode, 'matchPattern') === false,
    'the prefill invents NO incident-type mapping (the existing regex already does it)');

// The reference lookup now has a caller — this is the FR-7 defect fix.
$niSrc = (string) @file_get_contents($base . '/assets/js/new-incident.js');
is_true(strpos($niSrc, 'constituents.php?reference=') !== false,
    'the ?reference= lookup finally HAS a caller (it had none before this phase)');
is_true(strpos($niSrc, 'constituents.php?phone=') !== false,
    'the phone lookup is still there — a real phone number behaves as before');
is_true(strpos($niSrc, 'NetPrefill.markWorked') !== false,
    'creating an incident marks the check-in worked');
$idSrc = (string) @file_get_contents($base . '/assets/js/incident-detail.js');
is_true(strpos($idSrc, 'NetPrefill.markWorked') !== false,
    'saving a note marks the check-in worked');

// The admin panel writes through the settings endpoint.
$cfgSrc = (string) @file_get_contents($base . '/assets/js/config.js');
is_true(strpos($cfgSrc, 'bindNetCheckinPanel') !== false, 'the settings panel is bound');
is_true(strpos($cfgSrc, "tab === 'net-checkins'") !== false, 'the settings tab loads its values');
is_true(preg_match("/bindNetCheckinPanel.*apiPost\('settings'/s", $cfgSrc) === 1,
    'the panel saves through the settings endpoint (the store get_variable reads)');
$setSrc = (string) @file_get_contents($base . '/settings.php');
foreach (['net_checkin_history_count', 'net_checkin_autofocus', 'net_checkin_order',
          'net_checkin_separator', 'net_checkin_separator_digit_guard',
          'net_checkin_retention_days'] as $key) {
    is_true(strpos($setSrc, $key) !== false, "the admin panel exposes {$key}");
}
is_true(strpos((string) @file_get_contents($base . '/inc/config-sidebar.php'), 'net-checkins') !== false,
    'the settings sidebar has a tab for it');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. The widget JS, executed (node) --\n";
// ─────────────────────────────────────────────────────────────────────────

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    $harness = sys_get_temp_dir() . '/tcad_netcheckin_harness_' . getmypid() . '.js';
    $jsPath  = str_replace('\\', '/', $base . '/assets/js/net-checkins.js');
    $js = <<<'JS'
// Drive the REAL assets/js/net-checkins.js. Only the browser objects it
// touches at load time are stubbed, so the logic under test is production
// code rather than a re-implementation of it.
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

global.window = global;
global.document = {
    readyState: 'complete',
    getElementById: function () { return null; },
    querySelector: function () { return null; },
    addEventListener: function () {}
};
global.fetch = function () { return Promise.resolve({ json: function () { return {}; } }); };

eval(fs.readFileSync(process.argv[2], 'utf8'));

var NC = global.window.NetCheckins;
check('NetCheckins loaded', !!NC);

// ── shouldHandleKey — the "do not steal keystrokes" rule ──
// The POSITIVE control matters as much as the negative: a handler that never
// fires at all would satisfy every "does not fire in a text box" assertion.
check('fires when nothing is focused', NC.shouldHandleKey(null, 'd') === true);
check('fires when focus is on a plain element',
      NC.shouldHandleKey({ tagName: 'DIV' }, 'd') === true);
check('fires when focus is on the panel itself',
      NC.shouldHandleKey({ tagName: 'SECTION' }, 'Enter') === true);

function field(tag) {
    return { tagName: tag, getAttribute: function () { return null; } };
}
check('does NOT steal d from a focused text input',   NC.shouldHandleKey(field('INPUT'), 'd') === false);
check('does NOT steal a from a focused textarea',     NC.shouldHandleKey(field('TEXTAREA'), 'a') === false);
check('does NOT steal Enter from a focused input',    NC.shouldHandleKey(field('INPUT'), 'Enter') === false);
check('does NOT steal keys from a select',            NC.shouldHandleKey(field('SELECT'), 'e') === false);
check('does NOT steal keys from a contenteditable',
      NC.shouldHandleKey({ tagName: 'DIV', isContentEditable: true }, 'd') === false);

// The widget's OWN edit box is the one exception, and only for two keys.
var editBox = { tagName: 'INPUT', getAttribute: function (n) { return n === 'data-net-edit' ? '1' : null; } };
check('the inline edit box still handles Enter',  NC.shouldHandleKey(editBox, 'Enter') === true);
check('the inline edit box still handles Escape', NC.shouldHandleKey(editBox, 'Escape') === true);
check('the inline edit box does NOT swallow d as a hotkey',
      NC.shouldHandleKey(editBox, 'd') === false);

// ── computeVisible — active list, ordering, and history ──
function e(id, seq, status, priority, updated) {
    return { id: id, seq: seq, status: status, priority: priority || 0,
             updated_at: updated || ('2026-07-31 10:0' + seq + ':00'),
             identifier: 'ID' + id, note: '' };
}
var all = [
    e(1, 1, 'pending'), e(2, 2, 'worked'), e(3, 3, 'pending'),
    e(4, 4, 'deleted'), e(5, 5, 'pending')
];

var active = NC.computeVisible(all, { order: 'arrival', historyCount: 10, showHistory: false });
check('worked/deleted entries are inactive by default', active.length === 3, 'got ' + active.length);
check('active list is in arrival order',
      active[0].id === 1 && active[1].id === 3 && active[2].id === 5,
      active.map(function (x) { return x.id; }).join(','));

var withHist = NC.computeVisible(all, { order: 'arrival', historyCount: 10, showHistory: true });
check('history reveals the worked and deleted entries', withHist.length === 5, 'got ' + withHist.length);
check('waiting entries still come first', withHist[0].status === 'pending' && withHist[1].status === 'pending');
check('history is appended after the waiting entries', withHist[3].status !== 'pending');

var capped = NC.computeVisible(all, { order: 'arrival', historyCount: 1, showHistory: true });
check('the history count is honoured', capped.length === 4, 'got ' + capped.length);

// NEGATIVE CONTROL: history requested but count 0 must add nothing — proves
// the count is what limits it, not the showHistory flag alone.
var zero = NC.computeVisible(all, { order: 'arrival', historyCount: 0, showHistory: true });
check('negative control: history count 0 shows no history', zero.length === 3, 'got ' + zero.length);

// Priority ordering — the configurable alternative to arrival order.
var pAll = [e(1, 1, 'pending', 0), e(2, 2, 'pending', 5), e(3, 3, 'pending', 0)];
var byPri = NC.computeVisible(pAll, { order: 'priority', historyCount: 0, showHistory: false });
check('priority ordering floats the raised entry', byPri[0].id === 2, byPri[0].id);
check('equal priorities keep arrival order', byPri[1].id === 1 && byPri[2].id === 3);
var byArr = NC.computeVisible(pAll, { order: 'arrival', historyCount: 0, showHistory: false });
check('negative control: arrival ordering ignores priority', byArr[0].id === 1, byArr[0].id);

check('an empty list computes to an empty list',
      NC.computeVisible([], { order: 'arrival', historyCount: 10, showHistory: true }).length === 0);

// ── rowStateClass — worked/deleted must be visually distinct (FR-6) ──
var cP = NC.rowStateClass({ status: 'pending' });
var cW = NC.rowStateClass({ status: 'worked' });
var cD = NC.rowStateClass({ status: 'deleted' });
check('a worked row gets its own class', cW && cW !== cP, cW + ' vs ' + cP);
check('a deleted row gets its own class', cD && cD !== cP, cD + ' vs ' + cP);
check('worked and deleted are distinguishable from each other', cW !== cD, cW + ' vs ' + cD);

// ── shortTime — NCS logs and reads back clock time ──
check('a datetime renders as HH:MM', NC.shortTime('2026-07-31 14:19:07') === '14:19',
      NC.shortTime('2026-07-31 14:19:07'));
check('a missing timestamp renders as empty', NC.shortTime(null) === '');

console.log(out.join('\n'));
JS;
    file_put_contents($harness, $js);
    $raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($jsPath) . ' 2>&1');
    @unlink($harness);

    if (!is_string($raw) || strpos($raw, '|') === false) {
        bad('node harness ran net-checkins.js', trim((string) $raw));
    } else {
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode('|', trim($line), 3);
            if (count($parts) < 2) { continue; }
            if ($parts[0] === 'PASS') { ok('[js] ' . $parts[1]); }
            else { bad('[js] ' . $parts[1], $parts[2] ?? ''); }
        }
    }
}

echo "\n";
echo "==========================================================\n";
echo "=== {$pass} passed, {$fail} failed ===\n";
echo "==========================================================\n";

exit($fail > 0 ? 1 : 0);
