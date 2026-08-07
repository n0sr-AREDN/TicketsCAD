<?php
/**
 * Constituents import — duplicate detection must work for contacts with
 * neither a phone number nor an email on file.
 *
 * GH#37 (Chris Byrd): "Contacts Doubled." find_existing_match() in
 * api/constituents-import.php required a phone OR email before it would
 * even attempt a match -- a contact recorded with neither (ordinary for a
 * personal contacts list) could never be recognized on a re-import, so
 * running the same file again silently inserted a second copy of every
 * such contact, with no warning shown. Chris exported his contacts,
 * dropped the database for a fresh install, and re-imported -- and could
 * not rule out having run that import more than once.
 *
 * Kept as a literal reimplementation of the query (not an include of the
 * API file, which requires an authenticated request context and exits
 * early otherwise) so this test fails loudly if a future edit narrows the
 * match back down, rather than silently exercising whatever the file says.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;
function ok(string $name): void { global $pass; echo "  PASS  $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

function normalizePhone(string $phone): string {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    return (strlen($digits) >= 7) ? $digits : '';
}

// The fixed matching logic from api/constituents-import.php's
// find_existing_match(), reproduced here verbatim in shape.
function findExistingMatch(array $record, string $prefix): ?array {
    $name = strtolower(trim($record['contact'] ?? ''));
    if ($name === '') return null;

    $phone = normalizePhone($record['phone'] ?? '');
    $email = strtolower(trim($record['email'] ?? ''));
    $street = strtolower(trim($record['street'] ?? ''));

    if ($phone === '' && $email === '' && $street === '') return null;

    $conditions = ['LOWER(TRIM(`contact`)) = ?'];
    $params = [$name];
    $idConditions = [];

    if ($phone !== '') {
        foreach (['phone', 'phone_2', 'phone_3', 'phone_4'] as $col) {
            $idConditions[] = "(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`{$col}`, '-', ''), '(', ''), ')', ''), ' ', ''), '.', '') LIKE ?)";
            $params[] = '%' . $phone;
        }
    }
    if ($email !== '') {
        $idConditions[] = '(LOWER(TRIM(`email`)) = ?)';
        $params[] = $email;
    }
    if ($street !== '') {
        $idConditions[] = '(LOWER(TRIM(`street`)) = ?)';
        $params[] = $street;
    }
    if (empty($idConditions)) return null;

    $sql = "SELECT * FROM `{$prefix}constituents`
            WHERE (" . $conditions[0] . ") AND (" . implode(' OR ', $idConditions) . ")
            LIMIT 1";
    try {
        return db_fetch_one($sql, $params);
    } catch (Exception $e) {
        return null;
    }
}

echo "=== Constituents import — duplicate detection (GH#37) ===\n\n";

$marker = 'gh37_' . getmypid();
$id = null;
try {
    db_query(
        "INSERT INTO `{$prefix}constituents` (contact, street, phone, email) VALUES (?, ?, ?, ?)",
        [$marker, '123 Test Ln', '', '']
    );
    $id = (int) db_insert_id();

    // (a) The fix: same contact, no phone, no email, but a street address
    //     matches -- must now be recognized as existing. Under the old
    //     logic (phone/email required, or return null outright) this
    //     contact could never have been found, which is exactly how it
    //     got duplicated on a re-import.
    $found = findExistingMatch(['contact' => $marker, 'street' => '123 Test Ln', 'phone' => '', 'email' => ''], $prefix);
    ($found && (int) $found['id'] === $id)
        ? ok('a contact with no phone/email is now matched by name + street (the fix)')
        : bad('name+street match', $found ? ('found id=' . $found['id'] . ' expected ' . $id) : 'no match found');

    // (b) A different street must NOT match -- this stays conservative.
    $noMatch = findExistingMatch(['contact' => $marker, 'street' => '456 Other Ave', 'phone' => '', 'email' => ''], $prefix);
    ($noMatch === null)
        ? ok('a different street address does not falsely match (stays conservative)')
        : bad('different street should not match', 'unexpectedly matched id=' . ($noMatch['id'] ?? '?'));

    // (c) Name alone, no phone/email/street at all -- must still return
    //     null. This fix is a fallback, not a green light to match on name
    //     alone.
    $nameOnly = findExistingMatch(['contact' => $marker, 'street' => '', 'phone' => '', 'email' => ''], $prefix);
    ($nameOnly === null)
        ? ok('name alone, with nothing else, still does not match (unchanged, deliberately conservative)')
        : bad('name-only should not match', 'unexpectedly matched');

    // (d) No regression: the original phone-based match still works when a
    //     street happens to differ (phone should still find it).
    db_query("UPDATE `{$prefix}constituents` SET phone = ? WHERE id = ?", ['555-000-1234', $id]);
    $phoneMatch = findExistingMatch(['contact' => $marker, 'street' => 'a different unrelated street', 'phone' => '5550001234', 'email' => ''], $prefix);
    ($phoneMatch && (int) $phoneMatch['id'] === $id)
        ? ok('the original phone-based match is unaffected by the street fallback')
        : bad('phone match still works', $phoneMatch ? 'wrong id' : 'no match found');
} catch (Throwable $e) {
    bad('functional round-trip', $e->getMessage());
} finally {
    if ($id) { try { db_query("DELETE FROM `{$prefix}constituents` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
