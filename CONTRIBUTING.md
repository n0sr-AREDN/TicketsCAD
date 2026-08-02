# Contributing to TicketsCAD

Thank you for being here. TicketsCAD is dispatch software for volunteer
emergency-services groups, and most of what has improved it came from people
running it during real callouts and writing down what broke.

By taking part you agree to the [Code of Conduct](CODE_OF_CONDUCT.md). Who
decides what, and how, is in [GOVERNANCE.md](GOVERNANCE.md).

---

## Read this first: how changes actually land

**The public repository `openises/TicketsCAD` is a published snapshot, not the
development repository.** Releases are produced by exporting a scrubbed snapshot
of a private development tree into the public repo. The practical consequences
for you:

- **A pull request against the public repo cannot simply be merged.** Even after
  it is reviewed and accepted, the change has to be applied in the development
  tree. If it is only merged publicly, **the next release snapshot overwrites
  it** and your work silently disappears.
- **So "accepted" and "merged" are two different events here,** and there can be
  a gap between them. A maintainer will tell you in the thread when a change has
  been applied upstream, and it will appear in `CHANGELOG.md` for the release
  that carries it.
- **The public commit history is squashed snapshots**, so it does not show
  individual contributions. Attribution goes in the changelog entry and the
  release notes instead. If you would like to be credited differently — or not
  at all — say so and that will be respected.

This is unusual and it is better to know it up front than to discover it. It is
not a way of quietly absorbing other people's work: contributions are
acknowledged, and this section exists so the mechanism is not a surprise.

---

## What contributions are welcome

**All of these, in roughly descending order of how useful they are:**

| Kind | Notes |
|---|---|
| **Bug reports from real use** | The most valuable thing you can send. Use the issue template. |
| **Reproductions** of an existing open issue | Confirming a bug on a second installation is genuinely hard to get. |
| **Documentation corrections** | Especially install and upgrade docs, where a wrong step costs someone their evening. |
| **Translations** | See [`docs/locales/CONTRIBUTING-TRANSLATIONS.md`](docs/locales/CONTRIBUTING-TRANSLATIONS.md). |
| **Bug-fix patches** | Welcome. Read the security-review section below first. |
| **New features** | Discuss in an issue *before* writing code — see below. |

**Please open an issue before building a feature.** Not bureaucracy: TicketsCAD
serves agencies with incompatible procedures, and a feature that is obviously
correct for your group may be wrong for the project (see GOVERNANCE.md, "What
gets accepted"). A short issue first can save you a weekend.

**Not accepted:**

- Bulk automated changes — mass reformatting, blanket lint fixes, dependency
  bumps with no stated reason. They are hard to review and hard to attribute.
- Changes that add a build step, a framework, or a transpiler. The project is
  deliberately dependency-light procedural PHP with ES5 JavaScript; see
  [`README.md`](README.md).
- New runtime dependencies without a discussion. Every dependency is a supply-
  chain commitment and has to go in the SBOM.

---

## Before you open a pull request

**1. Install the git hooks, once per clone:**

```bash
bash tools/install-git-hooks.sh
```

Every commit then runs `php -l` on staged files, the schema audit
(`tools/schema_audit.php` — SQL written vs. the real schema), the API↔JS
contract audit (`tools/api_contract_audit.php`), the UI-consistency audit
(`tools/ui_consistency_audit.php` — new interface work vs. the conventions the
rest of the product follows), and the SBOM freshness check.

If the UI audit stops you, `php tools/ui_consistency_audit.php --rules` states
the convention behind each rule and
[`docs/UI-CONVENTIONS.md`](docs/UI-CONVENTIONS.md) explains the reasoning with
the code each one was derived from. It fails only on findings that are not
already in `tools/ui_consistency_baseline.txt`, so it will not ask you to clean
up debt you did not create.

**2. Run the full test suite:**

```bash
php tools/test_all.php
# no Apache running? use the same mode CI uses:
NEWUI_TEST_NO_HTTP=1 php tools/test_all.php
```

It must pass. If a test fails on your machine before you have changed anything,
say so in the pull request rather than working around it.

**3. Write tests. This is a requirement, not a preference.**

Every behavioural change needs a test under `tests/` that fails without your
change and passes with it. If you cannot make it fail first, the test is not
testing your change.

Two rules the project has learned the hard way, both from real regressions:

- **Drive the real writer.** A test that hand-inserts ideal rows can pass
  against a state the application never actually produces. Exercise the real
  code path — the API endpoint, the writer function, the form handler.
- **Test the default configuration a real user gets**, not the one setting
  combination that happens to work.

**4. Sign off your commits (DCO).**

```bash
git commit -s
```

That appends `Signed-off-by: Your Name <your@email>`, which certifies you wrote
the change or have the right to submit it under the project's licence — the
[Developer Certificate of Origin](https://developercertificate.org/). This is
the *only* legal paperwork the project asks for. There is no contributor licence
agreement and no copyright assignment: your contribution stays yours, licensed
under [GPL-2.0](LICENSE) like the rest.

**5. Keep the commit history.** Please do not force-push a rewritten history
onto an open pull request while it is being reviewed — it makes an in-progress
review impossible to follow.

---

## Security review of contributions

**Every contribution containing code gets an adversarial security review before
it is accepted.** This applies to contributions from outside the project and to
the maintainer's own changes equally: the pipeline is the same and the review is
the same. If you are contributing, here is what will happen to your patch so
that none of it reads as distrust.

**1. The change is reviewed adversarially, before acceptance.** The reviewer's
question is not "does this work" but "what does this let someone do". Areas
looked at specifically: authentication and RBAC gates, SQL construction (PDO
prepared statements only), output escaping, CSRF on state-changing endpoints,
file-path handling, shell invocation, and whether an error message leaks
anything.

**2. The review is widened beyond the reported symptom.** A finding in one field
prompts a check of every comparable field. This is not theoretical: a
contributor once reported a single settings field leaking a secret, the review
widened to nine fields, and it turned up the *inverse* bug — two booleans being
wrongly masked, which silently switched off an ingest authentication
requirement. The narrow fix would have shipped with a worse bug beside it.

**3. A security regression test is written that FAILS against the vulnerable
behaviour and PASSES once it is fixed.** Both directions are checked. A test
that only passes after the fix proves nothing about whether it would have caught
the bug — the way to know is to reintroduce the flaw and watch the test go red.

**4. The tests are run again after the change is applied upstream.** Because of
the snapshot model described above, "reviewed and green" and "in the shipped
tree and green" are separate facts. The suite is run again on the merged result.

**5. Nothing is accepted on the strength of a green check alone.** Automation
catches what it was written to catch. A human reads the diff.

**If you are fixing a vulnerability, do not open a public pull request.** Follow
[`SECURITY.md`](SECURITY.md) and use a private channel first, so a fix is
available before the problem is described in public.

---

## What happens after you open a pull request

1. **CI runs** — `.github/workflows/qa.yml`: a true fresh install against an
   empty MariaDB, every migration, then the full test suite and both audits.
   Note that for a **first-time contributor GitHub holds the workflow for
   manual approval**, so your run may sit as `action_required` until a
   maintainer releases it. That is GitHub's gate, not silence.
2. **A maintainer reviews it**, adversarially as described above.
3. **You may be asked for changes.** Usually tests, or a narrower scope.
4. **On acceptance the change is applied in the development tree**, and a
   maintainer says so in the thread.
5. **It ships in a tagged release** with an entry in `CHANGELOG.md`.

Timescales are best-effort. This is a volunteer project; see
[GOVERNANCE.md](GOVERNANCE.md).

---

## House style

Full conventions are in [`README.md`](README.md#conventions); the essentials:

- **PHP** — procedural, PHP 8.2, no framework. All database access through the
  `db_query()` / `db_fetch_*()` helpers, which use PDO prepared statements.
  **Never concatenate user input into SQL.** API endpoints suppress
  `display_errors` and return JSON via `json_response()` / `json_error()`.
- **JavaScript** — **ES5 only**. No arrow functions, `let`/`const`, template
  literals or modules. Wrap each file in an IIFE. There is no build step and
  none is wanted.
- **CSS** — Bootstrap 5 utilities first. Support light and dark themes; use
  Bootstrap CSS variables rather than hardcoded colours.
- **Database** — never assume a column exists; installations differ in age. Use
  a fallback query or an `information_schema` check rather than trusting a
  column to be there. Migrations must be idempotent — safe to run twice.
- **Commit messages** — describe what changed and why it was wrong before.
  Please do not add AI-assistant attribution trailers.

---

## Reporting a security vulnerability

**Not here — do not open a public issue.** [`SECURITY.md`](SECURITY.md) has the
private channels, the scope, and what to expect. That includes the project's
position on AI-assisted and automated vulnerability reports, which is worth
reading before sending one.

---

## Questions

Open an issue with the question template. There is no chat channel or mailing
list; issues are the project's public forum.
