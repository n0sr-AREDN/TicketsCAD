<?php
/**
 * Phase 129 — the DMR bridge bearer token must survive the round trip.
 *
 * openises/tickets#10 (@kmk1971, reproduced against HBLink3): api/dvswitch.php
 * minted a token, stored `hash('sha256', $token)`, and every unattended caller
 * then read that column and put it in an `Authorization: Bearer` header. The
 * bridge compares against the plaintext DMR_BEARER_TOKEN, so every one of those
 * calls answered 401 — invisibly, because the DMR side kept working and the one
 * path a human drives (the Test dialog, where an operator pastes the plaintext)
 * did authenticate.
 *
 * The regression this file exists to prevent is precise: WHAT THE WRITER STORES
 * MUST EQUAL WHAT THE READER SENDS. So the assertions below drive the real
 * writer (`dmr_token_store()`, which is the only thing api/dvswitch.php's
 * channel_create and channel_rotate_token use) and the real reader
 * (`dmr_bridge_token()`, which is what every outbound caller now uses), against
 * a row that goes through the database in between.
 *
 * The source gates in §5 are not decoration. The bug was a *pattern* — copied
 * from api/mesh.php, where hashing is correct because that endpoint VERIFIES an
 * incoming token rather than PRESENTING one — and a functional test alone would
 * not stop someone reintroducing `hash('sha256', $token)` in a ninth caller.
 *
 * tests/test_dmr_bridge_http.php completes the proof by standing the REAL
 * bridge HTTP handler up and checking it accepts what the reader hands back.
 *
 * Usage: php tests/test_dmr_bridge_token.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/dmr_token.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0; $skipped = 0;
function t($label, $cond) {
    global $passed, $failed;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $passed++ : $failed++;
}
function sk($label, $why) { global $skipped; echo "SKIP: {$label} — {$why}\n"; $skipped++; }

echo "=== Phase 129 — DMR bridge token storage (openises/tickets#10) ===\n\n";

// ── Prerequisites ───────────────────────────────────────────────────────────
$haveDb = false;
try {
    db_fetch_value("SELECT 1");
    $haveDb = true;
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
}

$haveTable = false;
if ($haveDb) {
    try {
        $haveTable = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . 'dmr_channels']
        ) > 0;
    } catch (Throwable $e) { $haveTable = false; }
    if (!$haveTable) {
        echo "SKIP: `{$prefix}dmr_channels` not present on this install\n";
    }
}

$LABEL = 'ZZTEST_P129_TOKEN';
$cleanup = function () use ($prefix, $LABEL) {
    try { db_query("DELETE FROM `{$prefix}dmr_channels` WHERE label = ?", [$LABEL]); }
    catch (Throwable $e) {}
};

if ($haveDb && $haveTable) {
    $cleanup();

    // ── 1. Writer → database → reader, with nothing hand-seeded ─────────────
    //
    // The row is created the way channel_create creates it (INSERT with an
    // empty token, then dmr_token_store()), the token is read back out of
    // MySQL, and the reader is the same function the eight outbound callers
    // use. Nothing in between is set by hand.
    db_query(
        "INSERT INTO `{$prefix}dmr_channels`
            (label, talkgroup, network, bridge_host, bridge_port, bridge_token,
             usrp_listen_port, usrp_send_port, link_mode, chat_channel, enabled)
         VALUES (?, '999913', 'HBLink3', '127.0.0.1', 18091, '', 0, 0,
                 'bidirectional', 'dispatch', 1)",
        [$LABEL]
    );
    $id = (int) db_insert_id();

    $minted = dmr_token_mint();          // what the admin is shown, exactly once
    dmr_token_store($id, $minted);       // the ONLY writer of bridge_token

    t('minted token is 64 hex characters',
        preg_match('/^[0-9a-f]{64}$/', $minted) === 1);

    // Read back exactly the columns a caller selects (see api/dmr-tx-audio.php).
    $row = db_fetch_one(
        "SELECT id, label, bridge_host, bridge_port, bridge_token
           FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);
    $sent = dmr_bridge_token($row);

    t('reader returns the exact value the writer stored (the whole bug)',
        $sent === $minted);
    t('what is stored is NOT the SHA-256 of what the admin was shown',
        (string) $row['bridge_token'] !== hash('sha256', $minted));

    // ── 2. …and the bridge would accept it ──────────────────────────────────
    //
    // hbp_client.py's ControlHandler._auth_ok() is:
    //     auth.startswith("Bearer ") and auth[7:].strip() == self.bearer
    // where self.bearer is DMR_BEARER_TOKEN — i.e. the value the operator
    // copied out of the mint dialog. Model that comparison exactly.
    // tests/test_dmr_bridge_http.php runs it against the real handler.
    $headerTheCadSends   = 'Bearer ' . $sent;
    $bearerTheBridgeHas  = $minted;      // pasted into the bridge's env file
    t('Authorization header matches the bridge DMR_BEARER_TOKEN',
        strpos($headerTheCadSends, 'Bearer ') === 0
        && trim(substr($headerTheCadSends, 7)) === $bearerTheBridgeHas);

    // ── 3. Rotation keeps the invariant ─────────────────────────────────────
    $rotated = dmr_token_mint();
    dmr_token_store($id, $rotated);
    $row2 = db_fetch_one(
        "SELECT id, bridge_host, bridge_port, bridge_token
           FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);
    t('after rotation the reader returns the NEW token',
        dmr_bridge_token($row2) === $rotated);
    t('after rotation the reader no longer returns the old token',
        dmr_bridge_token($row2) !== $minted);

    // ── 4. An install carrying a pre-fix hash is recognised, not sent ───────
    //
    // Written the way the OLD code wrote it — a digest — and classified the
    // way the migration classifies it. A hash and a fresh token are both 64
    // hex chars, so the column is the only thing that can tell them apart.
    if (dmr_token_format_column_exists()) {
        db_query(
            "UPDATE `{$prefix}dmr_channels`
                SET bridge_token = ?, bridge_token_format = 'legacy_hash'
              WHERE id = ?",
            [hash('sha256', $rotated), $id]
        );
        $legacy = db_fetch_one(
            "SELECT id, bridge_host, bridge_port, bridge_token
               FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);

        t('legacy hash is flagged as needing regeneration',
            dmr_token_needs_regen($legacy) === true);
        t('legacy hash is NEVER handed to a caller (would 401)',
            dmr_bridge_token($legacy) === '');
        t('the operator is told to regenerate, not just "missing token"',
            stripos(dmr_token_missing_reason($legacy), 'regenerate') !== false);

        // The repair path: an operator pastes the plaintext they saved, the
        // bridge confirms it with a 200, and only then do we adopt it.
        dmr_token_adopt($id, $rotated);
        $repaired = db_fetch_one(
            "SELECT id, bridge_host, bridge_port, bridge_token
               FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);
        t('adopting a verified token clears the legacy flag',
            dmr_token_needs_regen($repaired) === false);
        t('adopting a verified token makes it usable again',
            dmr_bridge_token($repaired) === $rotated);
    } else {
        sk('legacy-hash classification', 'bridge_token_format column absent — '
            . 'run php sql/run_phase129_dmr_bridge_token.php');
    }

    // ── 5. An empty token is still an empty token ───────────────────────────
    db_query("UPDATE `{$prefix}dmr_channels` SET bridge_token = '' WHERE id = ?", [$id]);
    $empty = db_fetch_one(
        "SELECT id, bridge_host, bridge_port, bridge_token
           FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);
    t('no token at all reads as empty', dmr_bridge_token($empty) === '');
    t('no token at all is not reported as "needs regeneration"',
        dmr_token_needs_regen($empty) === false);

    $cleanup();
} else {
    sk('token round-trip', 'no database / no dmr_channels table');
}

// ── 6. Source gates — stop the pattern coming back ──────────────────────────
//
// Comments are stripped first. Half of these files EXPLAIN the old hashing
// pattern in prose, and a gate that cannot tell an explanation from an
// implementation would either fire on the explanation or — worse, on the
// "does this file use the reader?" checks below — pass because someone
// mentioned the function in a comment.
function php_code_only(string $src): string
{
    if ($src === '') return '';
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}
function read_code(string $path): string
{
    return php_code_only((string) @file_get_contents($path));
}

$dvs = read_code($base . '/api/dvswitch.php');
t('api/dvswitch.php loads inc/dmr_token.php',
    strpos($dvs, "inc/dmr_token.php") !== false);
t('channel_create stores via dmr_token_store()',
    preg_match("/'channel_create'.*?dmr_token_store\\(/s", $dvs) === 1);
t('channel_rotate_token stores via dmr_token_store()',
    preg_match("/'channel_rotate_token'.*?dmr_token_store\\(/s", $dvs) === 1);
t('api/dvswitch.php never hashes the bridge token before storing it',
    preg_match("/hash\\('sha256',\\s*\\\$token\\)/", $dvs) !== 1);
t('the token is still never returned by a GET (channels reports has_token)',
    strpos($dvs, 'AS has_token') !== false
    && preg_match("/'channels'.*?SELECT id, label.*?FROM/s", $dvs) === 1
    && preg_match("/'channels'.*?SELECT[^;]*?bridge_token,/s", $dvs) !== 1);

// Every outbound caller must go through the reader. Listed explicitly rather
// than globbed: a new caller SHOULD fail this test until someone adds it here
// and, in doing so, reads why.
$callers = [
    'api/dmr-tx-audio.php',
    'api/dmr-stream.php',
    'api/dmr-tx-stream.php',
    'api/dmr-audio.php',
    'api/radio-ai-decide.php',
    'inc/channel_registry.php',
    'inc/weather_radio.php',
    'proxy/dmr-proxy.php',
];
foreach ($callers as $rel) {
    $src = read_code($base . '/' . $rel);
    t("{$rel} presents the token via dmr_bridge_token()",
        $src !== '' && strpos($src, 'dmr_bridge_token(') !== false);
}

// The migration must exist; sql/run_migrations.php discovers run_*.php by glob,
// so the filename is the wiring.
t('Phase 129 migration script exists',
    is_file($base . '/sql/run_phase129_dmr_bridge_token.php'));
$mig = read_code($base . '/sql/run_phase129_dmr_bridge_token.php');
t('migration flags pre-existing tokens as legacy_hash',
    strpos($mig, "'legacy_hash'") !== false);
t('migration verifies its own outcome and can exit non-zero',
    strpos($mig, 'verification failed') !== false && strpos($mig, 'exit(1)') !== false);
$schema = read_code($base . '/sql/run_phase73i_dvswitch_schema.php');
t('fresh installs get bridge_token_format from the CREATE TABLE',
    strpos($schema, 'bridge_token_format') !== false);

// `channel_recent_calls` proxied GET /calls/recent, which hbp_client.py has
// never implemented — it 404'd wherever anyone pointed it. Removed in favour
// of channel_recent_messages (local, works offline, needs no bearer).
t('the dead /calls/recent proxy action is gone',
    strpos($dvs, "'channel_recent_calls'") === false
    && strpos($dvs, "'/calls/recent'") === false);
t('channel_recent_messages is still there to serve the same panel',
    strpos($dvs, "'channel_recent_messages'") !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
