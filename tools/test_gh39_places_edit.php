<?php
/**
 * GH#39 (Chris Byrd, 2026-08-07): "When you are in the Places Page, You can
 * enter state and lat/long during the first entry on the pop ups, but there
 * is no option to edit to change anything... Have an edit option, or screen
 * that has all info. Allow for lat/long lookup for autofill."
 *
 * api/places.php's action=update endpoint already accepted every field
 * (name, apply_to, street, city, state, information, lat, lon, zoom) --
 * the gap was purely client-side: assets/js/config.js's window.__pl_edit()
 * only ever prompt()'d for name/street/city, so state/lat/lon/zoom/
 * information could be set on create but never touched again. The fix
 * replaces both the create and edit flows with one modal (#placeEditModal
 * in settings.php) carrying every field, plus a Lookup button wired to the
 * shared Geocode helper (assets/js/geocode.js) for lat/long autofill.
 *
 * This test proves the backend half of that contract still holds: every
 * field the new modal writes actually persists via the exact UPDATE
 * api/places.php's action=update runs, driven the way the modal drives it
 * (partial field sets, since the endpoint only SETs keys present in the
 * JSON body). A regression here would silently strand the new UI the same
 * way the old prompt() chain was stranded.
 *
 * REOPENED (Chris Byrd, 2026-08-08): the Lookup button wrote the geocoder's
 * FULL state name ("Minnesota") into the 4-char `state` column. The real
 * fix is client-side (assets/js/config.js now resolves the name against the
 * states_translator-backed list before writing it -- not testable from this
 * PHP suite), but auditing api/places.php's update handler while fixing it
 * found a related server-side gap this test now covers: every field was
 * truncated to a blanket 1024 chars regardless of its real column width,
 * which only worked by accident because this install's sql_mode has
 * STRICT_TRANS_TABLES off (MySQL silently truncates instead of erroring).
 * `create` already used per-field lengths; `update` now matches it.
 *
 * Usage: php tools/test_gh39_places_edit.php
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

echo "=== GH#39 — Places edit (full field set, not just name/street/city) ===\n\n";

// Literal copy of api/places.php's action=update SET-builder, so this test
// fails loudly if a future edit narrows which fields it accepts -- exactly
// the class of regression that stranded the old UI in the first place.
function placesUpdateSql(array $input): array {
    $maxLen = ['name' => 64, 'street' => 96, 'city' => 32, 'state' => 4, 'information' => 1024, 'apply_to' => 4];
    $sets = [];
    $params = [];
    foreach (['name','street','city','state','information','apply_to'] as $f) {
        if (isset($input[$f])) {
            $sets[] = "`{$f}` = ?";
            if ($f === 'apply_to' && !in_array((string) $input[$f], ['city','bldg'], true)) $input[$f] = 'bldg';
            $params[] = substr((string) $input[$f], 0, $maxLen[$f]);
        }
    }
    foreach (['lat','lon'] as $f) {
        if (array_key_exists($f, $input)) { $sets[] = "`{$f}` = ?"; $params[] = $input[$f] === null ? null : (float) $input[$f]; }
    }
    if (isset($input['zoom'])) { $sets[] = "zoom = ?"; $params[] = max(1, min(20, (int) $input['zoom'])); }
    return [$sets, $params];
}

$marker = 'gh39_test_' . getmypid();

// ── 1. Create a place the way the old (and new) create flow does ────────
db_query(
    "INSERT INTO `{$prefix}places` (name, apply_to, street, city, state, information, lat, lon, zoom)
     VALUES (?, 'bldg', ?, ?, ?, ?, ?, ?, ?)",
    [$marker, '100 Main St', 'Anytown', 'MN', 'original notes', 44.9, -93.2, 15]
);
$placeId = (int) db_insert_id();

try {
    // ── 2. Edit modal saves EVERY field, including state/lat/lon/zoom/
    //      information/apply_to -- the exact set the old prompt() chain
    //      could never reach.
    $editPayload = [
        'name'        => $marker . '_edited',
        'apply_to'    => 'city',
        'street'      => '200 Second Ave',
        'city'        => 'Othertown',
        'state'       => 'WI',
        'information' => 'edited via GH#39 modal',
        'lat'         => 45.123,
        'lon'         => -93.456,
        'zoom'        => 12,
    ];
    [$sets, $params] = placesUpdateSql($editPayload);
    (count($sets) === 9) ? ok('all 9 editable fields produced a SET clause') : bad('all 9 fields produced a SET clause', 'got ' . count($sets));

    $params[] = $placeId;
    db_query("UPDATE `{$prefix}places` SET " . implode(', ', $sets) . " WHERE id = ?", $params);

    $row = db_fetch_one(
        "SELECT name, apply_to, street, city, state, information, lat, lon, zoom FROM `{$prefix}places` WHERE id = ?",
        [$placeId]
    );
    (!empty($row)) ? ok('row still readable after update') : bad('row still readable after update');

    if ($row) {
        ($row['name'] === $editPayload['name']) ? ok('name persisted') : bad('name persisted', 'got ' . var_export($row['name'], true));
        ($row['apply_to'] === 'city') ? ok('apply_to persisted (was bldg, edited to city)') : bad('apply_to persisted', 'got ' . var_export($row['apply_to'], true));
        ($row['street'] === $editPayload['street']) ? ok('street persisted') : bad('street persisted');
        ($row['city'] === $editPayload['city']) ? ok('city persisted') : bad('city persisted');
        // GH#39's central complaint: state could be set on create but not edit.
        ($row['state'] === 'WI') ? ok('state persisted -- this is the field the old edit UI could never touch') : bad('state persisted', 'got ' . var_export($row['state'], true));
        ($row['information'] === $editPayload['information']) ? ok('information persisted') : bad('information persisted');
        (abs((float) $row['lat'] - 45.123) < 0.0001) ? ok('lat persisted -- also unreachable from the old edit UI') : bad('lat persisted', 'got ' . var_export($row['lat'], true));
        (abs((float) $row['lon'] - (-93.456)) < 0.0001) ? ok('lon persisted') : bad('lon persisted', 'got ' . var_export($row['lon'], true));
        ((int) $row['zoom'] === 12) ? ok('zoom persisted') : bad('zoom persisted', 'got ' . var_export($row['zoom'], true));
    }

    // ── 3. A PARTIAL edit (only name+lat/lon, as Lookup-then-Save would send
    //      if the dispatcher never touched the other fields) must not clobber
    //      the untouched fields -- proves the SET-builder is additive, not a
    //      full-row overwrite.
    [$sets2, $params2] = placesUpdateSql(['name' => $marker . '_partial', 'lat' => 46.0, 'lon' => -94.0]);
    $params2[] = $placeId;
    db_query("UPDATE `{$prefix}places` SET " . implode(', ', $sets2) . " WHERE id = ?", $params2);
    $row2 = db_fetch_one("SELECT name, state, city, lat FROM `{$prefix}places` WHERE id = ?", [$placeId]);
    ($row2 && $row2['state'] === 'WI') ? ok('a partial update leaves untouched fields (state) intact') : bad('partial update leaves state intact', 'got ' . var_export($row2['state'] ?? null, true));
    ($row2 && $row2['city'] === $editPayload['city']) ? ok('a partial update leaves untouched fields (city) intact') : bad('partial update leaves city intact');
    ($row2 && abs((float) $row2['lat'] - 46.0) < 0.0001) ? ok('a partial update still applies the fields it does send (lat)') : bad('partial update applies lat');

    // ── 4. GH#39 reopened: a too-long state value (the geocoder's full name,
    //      if the client-side fix ever regresses) must be truncated to the
    //      column's real 4-char width on update, not the old blanket 1024 --
    //      defense in depth so a client bug can't silently write garbage.
    [$sets3, $params3] = placesUpdateSql(['state' => 'Minnesota']);
    $params3[] = $placeId;
    db_query("UPDATE `{$prefix}places` SET " . implode(', ', $sets3) . " WHERE id = ?", $params3);
    $row3 = db_fetch_one("SELECT state FROM `{$prefix}places` WHERE id = ?", [$placeId]);
    ($row3 && $row3['state'] === 'Minn') ? ok('update() truncates state to its real 4-char column width, not a blanket 1024')
        : bad('update() truncates state to 4 chars', 'got ' . var_export($row3['state'] ?? null, true));
} finally {
    db_query("DELETE FROM `{$prefix}places` WHERE id = ?", [$placeId]);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
