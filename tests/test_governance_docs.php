<?php
/**
 * Governance and community-health documents must exist, and must keep saying
 * the substantive things they were written to say.
 *
 * WHY THIS EXISTS. These files are evidence. `docs/security/cisa-oss-2026-conformance.md`
 * cites each of them against a named criterion, and the project points agencies
 * at that statement. A document that quietly loses its teeth — the DCO
 * requirement dropped from CONTRIBUTING.md, the security-review process trimmed,
 * the maintainer placeholder deleted without being filled in — would turn a
 * published conformance claim into an overclaim without anybody noticing.
 *
 * So this checks for the SUBSTANCE, not merely the filename. Presence-only tests
 * are how a repository ends up with a CONTRIBUTING.md that says "contributions
 * welcome" and nothing else while a compliance table records the criterion as
 * met.
 *
 * Usage: php tests/test_governance_docs.php
 */

require_once __DIR__ . '/../config.php';

$passed = 0;
$failed = 0;

function test($label, $condition, $hint = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n";
        $failed++;
    }
}

echo "=== Governance + community health documents ===\n\n";

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$read = static function (string $rel) use ($root): string {
    return (string) @file_get_contents($root . '/' . $rel);
};
/**
 * Substring search that ignores how the prose happens to be wrapped.
 *
 * These documents are hand-wrapped at ~79 columns, so a phrase this test looks
 * for is routinely split across two lines. Matching raw text would make the
 * gate fail whenever a paragraph is reflowed — which trains people to weaken
 * the test rather than fix the document, the opposite of what it is for.
 * Markdown emphasis markers are dropped for the same reason: bolding a phrase
 * must not change whether it is present.
 */
$has = static function (string $hay, string $needle): bool {
    $flatten = static function (string $s): string {
        /* '#' goes too: CODEOWNERS is a comment file, so a wrapped sentence in
         * it carries a '#' at the start of its continuation line. */
        $s = str_replace(['**', '*', '`', '_', '#'], '', $s);
        return strtolower((string) preg_replace('/\s+/', ' ', $s));
    };
    return strpos($flatten($hay), $flatten($needle)) !== false;
};

/* ------------------------------------------------------------------ *
 * 1. The files exist at the paths GitHub and a human both look in
 * ------------------------------------------------------------------ */
$required = [
    'CODE_OF_CONDUCT.md',
    'CONTRIBUTING.md',
    'GOVERNANCE.md',
    'AUTHORS.md',
    'CODEOWNERS',
    'SECURITY.md',
    'LICENSE',
    'README.md',
    '.github/PULL_REQUEST_TEMPLATE.md',
    '.github/ISSUE_TEMPLATE/config.yml',
    '.github/ISSUE_TEMPLATE/bug_report.yml',
    '.github/ISSUE_TEMPLATE/feature_request.yml',
    '.github/ISSUE_TEMPLATE/question.yml',
];
foreach ($required as $rel) {
    test("$rel exists", is_file($root . '/' . $rel));
}

$coc          = $read('CODE_OF_CONDUCT.md');
$contributing = $read('CONTRIBUTING.md');
$governance   = $read('GOVERNANCE.md');
$codeowners   = $read('CODEOWNERS');
$prTemplate   = $read('.github/PULL_REQUEST_TEMPLATE.md');
$issueConfig  = $read('.github/ISSUE_TEMPLATE/config.yml');

/* ------------------------------------------------------------------ *
 * 2. CODE_OF_CONDUCT.md — substance
 * ------------------------------------------------------------------ */
test('code of conduct names a reporting contact',
    $has($coc, 'ejosterberg@gmail.com'));
test('code of conduct describes graduated enforcement',
    $has($coc, 'block') && $has($coc, 'What happens after a report'));
test('code of conduct states the operational-data rule',
    ($has($coc, 'patient') || $has($coc, 'incident data')) && $has($coc, 'callsign'),
    'the no-real-operational-data rule is the project-specific clause and must stay');
test('code of conduct points at an escalation route outside the project',
    $has($coc, 'support.github.com'),
    'a small maintainer group cannot be neutral about a complaint against itself');

/* ------------------------------------------------------------------ *
 * 3. CONTRIBUTING.md — the CISA producer-side items it is evidence for
 * ------------------------------------------------------------------ */
test('contributing requires DCO sign-off',
    $has($contributing, 'git commit -s')
    && $has($contributing, 'Developer Certificate of Origin'),
    'the only legal requirement the project imposes on contributors');
test('contributing states that no CLA or copyright assignment is required',
    $has($contributing, 'contributor licence agreement')
    || $has($contributing, 'contributor license agreement'),
    'CISA: keep requirements on external contributors minimal, and say so');
test('contributing requires tests with code contributions',
    $has($contributing, 'fails without your change'),
    'tests-with-code is a named CISA producer item');
test('contributing says which kinds of contribution are acceptable',
    $has($contributing, 'What contributions are welcome')
    && $has($contributing, 'Not accepted'),
    'CISA: contribution guidelines must specify acceptable contribution types');
test('contributing states outside code runs the same CI and review as internal',
    $has($contributing, 'the pipeline is the same'));

/* The contribution security review — the process actually followed, and the
 * part most likely to be softened into meaninglessness later. */
test('contributing documents an adversarial security review before acceptance',
    $has($contributing, 'adversarial'));
test('contributing documents the two-direction security regression test',
    $has($contributing, 'FAILS against the vulnerable behaviour')
    && $has($contributing, 'PASSES once it is fixed'),
    'a test that only passes after the fix proves nothing — both directions are the point');
test('contributing documents re-running the tests after the change is applied upstream',
    $has($contributing, 'run again'));
test('contributing routes vulnerability fixes away from public pull requests',
    $has($contributing, 'do not open a public pull request'));

/* The snapshot workflow — a contributor who does not know this loses their work
 * at the next release. */
test('contributing explains the one-way snapshot release model',
    $has($contributing, 'published snapshot'));
test('contributing warns that a public-only merge is reverted by the next release',
    $has($contributing, 'overwrites it'),
    'this is the concrete consequence; without it the model reads as trivia');

/* ------------------------------------------------------------------ *
 * 4. GOVERNANCE.md — substance
 * ------------------------------------------------------------------ */
test('governance has a maintainers section',
    $has($governance, '## Maintainers'));
test('governance describes how decisions are made',
    $has($governance, 'How decisions are made'));

/* The maintainer count is the one criterion CISA uses as its worked example of
 * a project FAILING evaluation, and the one a governance document is most
 * tempted to blur. These two checks exist so it cannot be blurred quietly. */
test('governance states the active-maintainer position plainly',
    $has($governance, 'one active maintainer'),
    'an evaluator must not have to infer this from the commit graph');
test('governance does not count mere repository access as maintainership',
    $has($governance, 'Access is not maintainership'),
    'read-only and admin accounts are not maintainers and the document must say so');

/* Maintainer Emeritus is a credit for designing the system. It must be here,
 * and it must be unambiguous that it is not an active-maintainer role — the
 * conformance statement depends on that distinction holding. */
test('governance credits the Maintainer Emeritus',
    $has($governance, 'Maintainer Emeritus') && $has($governance, 'ashore1008'));
test('governance states emeritus is not an active maintainer',
    $has($governance, 'an emeritus maintainer is not an active maintainer'),
    'without this the credit could be misread as satisfying the Community criterion');
test('AUTHORS.md exists and carries the emeritus credit permanently',
    is_file($root . '/AUTHORS.md')
    && $has($read('AUTHORS.md'), 'Maintainer Emeritus')
    && $has($read('AUTHORS.md'), 'ashore1008'),
    'the credit ships with every release, independent of any account');
test('README credits the original designer',
    $has($read('README.md'), 'ashore1008'));
test('governance describes how someone becomes a maintainer',
    $has($governance, 'How someone becomes a maintainer'));
test('governance addresses continuity',
    $has($governance, '## Continuity'));
test('governance states the licence guarantees the code survives',
    $has($governance, 'fork'),
    'the irrevocable GPL grant is the real continuity assurance and must be stated');
test('governance does not promise support it cannot deliver',
    $has($governance, 'does **not** promise') || $has($governance, 'does not promise'),
    'the limits have to be as visible as the reassurances');

/* The maintainer table is a placeholder until the owner fills it in. This test
 * does not fail on that — it fails if the placeholder is REMOVED while still
 * empty, which would leave a governance document silently asserting nothing. */
$placeholderPresent = $has($governance, 'to be completed');
$namesPresent = (bool) preg_match('/^\|\s*(?:@|\[)?[A-Za-z][^|]*\|\s*(?:Lead m|M)aintainer/mi',
    str_replace('_to be completed_', '', $governance));
test('governance maintainer table is either filled in or visibly marked incomplete',
    $placeholderPresent || $namesPresent,
    'the table must not be silently emptied');
if ($placeholderPresent) {
    echo "       NOTE: the maintainer table still reads '_to be completed_'. That is a\n"
       . "       placeholder for the CURRENT maintainer's own entry, not the missing\n"
       . "       second maintainer. CISA's Community criterion stays NOT MET until a\n"
       . "       second person actively maintains the project — filling in this table\n"
       . "       does not change that row.\n";
}

/* ------------------------------------------------------------------ *
 * 4b. The conformance statement must keep failing what we fail
 * ------------------------------------------------------------------ *
 * This is the check most worth having. A conformance table is a published claim
 * to agencies that evaluate this software; the failure mode is not that it gets
 * deleted, it is that a row quietly softens from NOT MET to something kinder
 * while nothing changes in the project. The maintainer-count row is the one
 * under the most pressure to drift, so it is pinned here.
 */
$conf = $read('docs/security/cisa-oss-2026-conformance.md');
test('conformance statement exists',
    trim($conf) !== '', 'docs/security/cisa-oss-2026-conformance.md');
test('conformance statement marks the two-active-maintainers criterion NOT MET',
    (bool) preg_match('/C4-2 Community[^|]*\|\s*\*\*NOT MET\*\*/u', $conf),
    'one active maintainer — this row may only change when a second one actually maintains');
test('conformance statement marks the own-commits Conduct criterion NOT MET',
    (bool) preg_match('/maintainers do not accept their own commits[^|]*\|\s*\*\*NOT MET\*\*/u', $conf));
test('conformance statement says it is a self-assessment and that CISA certifies nobody',
    $has($conf, 'self-assessment') && $has($conf, 'does not certify'));
test('conformance statement does not claim certification or compliance',
    !preg_match('/\bCISA[- ](?:compliant|certified|approved)\b/i',
        preg_replace('/"[^"]*"|would be misrepresenting[^.]*\./', '', $conf)),
    'no such status exists; the guidance is advisory');
test('conformance statement does not count emeritus or access as maintainership',
    $has($conf, 'Access is not review') || $has($conf, 'is not counted here'),
    'the credit and the criterion must stay separate');

/* ------------------------------------------------------------------ *
 * 5. CODEOWNERS + templates
 * ------------------------------------------------------------------ */
test('CODEOWNERS sets a default owner',
    (bool) preg_match('/^\*\s+@\S+/m', $codeowners));
test('CODEOWNERS routes security-sensitive paths',
    $has($codeowners, 'SECURITY.md') && $has($codeowners, 'rbac'));
test('CODEOWNERS routes the SBOM and its generator',
    $has($codeowners, 'generate-sbom.php') && $has($codeowners, 'SBOM.cdx.json'));
test('CODEOWNERS explains it routes reviews rather than enforcing them',
    $has($codeowners, 'not an access-control mechanism'),
    'without this a reader mistakes the file for a merge gate');

test('issue template config routes vulnerabilities to a private channel',
    $has($issueConfig, 'security/advisories/new')
    && $has($issueConfig, 'Do NOT open a public issue'));
test('issue template config disables blank issues',
    (bool) preg_match('/^blank_issues_enabled:\s*false/m', $issueConfig));

test('pull-request template asks for DCO sign-off',
    $has($prTemplate, 'git commit -s'));
test('pull-request template asks for a test that fails without the change',
    $has($prTemplate, 'fails without the change'));
test('pull-request template warns against public vulnerability patches',
    $has($prTemplate, 'SECURITY VULNERABILITY'));
test('pull-request template requires SBOM regeneration when dependencies change',
    $has($prTemplate, 'generate-sbom.php'));

/* Every issue-form template must be valid enough to be a form GitHub accepts:
 * name + description + body. A malformed template silently stops appearing. */
foreach (['bug_report', 'feature_request', 'question'] as $tpl) {
    $src = $read(".github/ISSUE_TEMPLATE/$tpl.yml");
    test("$tpl.yml has name, description and body",
        (bool) preg_match('/^name:\s*\S/m', $src)
        && (bool) preg_match('/^description:\s*\S/m', $src)
        && (bool) preg_match('/^body:/m', $src));
    test("$tpl.yml warns against posting personal or operational data",
        stripos($src, 'callsign') !== false || stripos($src, 'password') !== false);
}

/* ------------------------------------------------------------------ *
 * 6. Links inside these documents must resolve in the PUBLISHED tree
 * ------------------------------------------------------------------ *
 * A governance file that points at a document only the private development
 * repository has is worse than one that points nowhere: the reader is told
 * evidence exists and cannot reach it. The project has shipped that defect
 * before — roughly twenty docs carried live-looking links into `specs/`, which
 * the release snapshot removes.
 */
$excludedPrefixes = ['specs/', 'coordination/', 'docs/training-scripts/', 'BACKLOG.md',
                     'REVIEW-NOTES.md', 'docs/questions-for-eric.md',
                     'docs/RADIO-AI-SECURITY-REVIEW.md', 'tools/release-snapshot.sh'];

$docs = [
    'CODE_OF_CONDUCT.md'               => $coc,
    'CONTRIBUTING.md'                  => $contributing,
    'GOVERNANCE.md'                    => $governance,
    '.github/PULL_REQUEST_TEMPLATE.md' => $prTemplate,
];
$broken   = [];
$excluded = [];
foreach ($docs as $docRel => $src) {
    if (!preg_match_all('~\]\(([^)#\s]+)(?:#[^)\s]*)?\)~', $src, $m)) continue;
    foreach ($m[1] as $target) {
        if (preg_match('~^(https?:|mailto:)~i', $target)) continue;
        foreach ($excludedPrefixes as $ex) {
            if (strncmp($target, $ex, strlen($ex)) === 0) {
                $excluded[] = "$docRel -> $target";
                continue 2;
            }
        }
        if (!file_exists($root . '/' . $target)) $broken[] = "$docRel -> $target";
    }
}
test('no governance document links to a file missing from this tree',
    $broken === [], implode(', ', array_slice($broken, 0, 5)));
test('no governance document links into a snapshot-excluded path',
    $excluded === [], implode(', ', array_slice($excluded, 0, 5)));

/* ------------------------------------------------------------------ *
 * 7. None of these files may be excluded from the published snapshot
 * ------------------------------------------------------------------ */
$snapshot = $read('tools/release-snapshot.sh');
if ($snapshot === '') {
    echo "[SKIP] release-snapshot.sh not in this tree (published clone) — "
       . "exclusion check not applicable\n";
} else {
    $excludesBlock = '';
    if (preg_match('/EXCLUDES=\((.*?)\)/s', $snapshot, $m)) $excludesBlock = $m[1];
    $wronglyExcluded = [];
    foreach ($required as $rel) {
        $top = explode('/', $rel)[0];
        if (preg_match('/(^|\s)' . preg_quote($rel, '/') . '(\s|$)/', $excludesBlock)
            || preg_match('/(^|\s)' . preg_quote($top, '/') . '(\s|$)/', $excludesBlock)) {
            $wronglyExcluded[] = $rel;
        }
    }
    test('no governance document is stripped by the release snapshot',
        $wronglyExcluded === [], implode(', ', $wronglyExcluded));
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
