<!--
Thank you for contributing to TicketsCAD.

Two things to know before you fill this in — both are in CONTRIBUTING.md and
neither is a formality:

1. This public repository is a PUBLISHED SNAPSHOT of a private development
   tree. Your pull request cannot simply be merged here — once accepted, the
   change is applied upstream and arrives in the next release. A maintainer
   will say so in the thread. "Accepted" and "merged" are two different events.

2. If this fixes a SECURITY VULNERABILITY, please close this and use the
   private channel in SECURITY.md instead. A public patch describes the
   vulnerability to everyone who has not upgraded yet.
-->

## What does this change?

<!-- Plain English. What was wrong or missing, and what does it do now? -->

## Why?

<!-- The problem this solves. If there is an issue, link it: "Fixes #123".
     For a new feature, link the issue where it was discussed first —
     see CONTRIBUTING.md, "What contributions are welcome". -->

## How was it tested?

<!-- Not "it works". What did you actually run, and on what?
     e.g. "php tools/test_all.php on PHP 8.2 / MariaDB 10.11, plus manual
     check on the incident detail page in Firefox and on an iPad." -->

---

## Checklist

- [ ] **Commits are signed off** (`git commit -s` — the Developer Certificate of
      Origin). This is the only paperwork the project asks for.
- [ ] **The full test suite passes** — `php tools/test_all.php`, or
      `NEWUI_TEST_NO_HTTP=1 php tools/test_all.php` without a running Apache.
- [ ] **A test covers this change**, and it **fails without the change**. If you
      could not make it fail first, it is not testing what you think.
- [ ] **The test drives the real code path** (the endpoint, the writer, the form
      handler) rather than hand-inserting ideal rows.
- [ ] The git hooks are installed (`bash tools/install-git-hooks.sh`) and the
      schema + API-contract audits pass.
- [ ] No secrets, credentials, real names, addresses, or operational incident
      data in the diff, the tests, or the commit messages.

### If this touches the database

- [ ] The migration is **idempotent** — safe to run twice.
- [ ] No column is assumed to exist; installations differ in age.
- [ ] `php tools/gen_schema_manifest.php` was re-run if a writer column changed.

### If this touches an API endpoint or the UI it feeds

- [ ] The endpoint checks authentication and the **correct RBAC permission**,
      and the page gate names the **same** permission as the API gate.
- [ ] State-changing requests verify the CSRF token.
- [ ] All SQL goes through prepared statements; no user input is concatenated in.
- [ ] User-controlled output is escaped.
- [ ] JavaScript reads the keys the endpoint **actually emits** — checked
      against the output mapping, not from memory.

### If this adds or changes a dependency

- [ ] It was **discussed in an issue first** — every dependency is a supply-chain
      commitment.
- [ ] `php tools/generate-sbom.php` was re-run and the SBOM files are in the diff.
- [ ] `php tools/generate-sbom.php --check` exits 0.

---

## Anything a reviewer should look at closely?

<!-- Where you are unsure, what you decided against and why, anything you would
     like a second opinion on. Saying "I'm not certain about X" is useful and
     will not be held against the change. -->

<!--
What happens next (CONTRIBUTING.md has the detail):

  CI runs a true fresh install plus the full suite. If this is your first
  contribution, GitHub holds the workflow for manual approval and it will sit
  as "action_required" until a maintainer releases it — that is GitHub's gate,
  not silence.

  A maintainer then reviews the change adversarially: what does this let
  someone do, not just does it work. Code contributions get a security review
  before acceptance, and anything security-relevant gets a regression test that
  is checked in BOTH directions — it must fail against the old behaviour and
  pass against the new. The same process applies to the maintainer's own
  changes.
-->
