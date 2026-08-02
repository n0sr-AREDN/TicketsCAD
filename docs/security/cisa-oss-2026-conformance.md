# TicketsCAD — self-assessment against CISA's *Open Source Software: Security Principles and Practices*

**Assessed version:** TicketsCAD v4.2.2
**Assessment date:** 2026-07-30
**Assessed by:** the TicketsCAD maintainers (self-assessment)
**Guidance assessed against:** CISA, *Open Source Software: Security Principles
and Practices*, original publication **2026-07-30**, TLP:CLEAR.
<https://www.cisa.gov/resources-tools/resources/open-source-software-security-principles-and-practices>

---

## Read this before the table

**This is a self-assessment. Nobody certified it.**

CISA does not certify, accredit, endorse or approve any software or any project.
There is no such thing as being "CISA compliant" or "CISA certified" with respect
to this document, and any vendor telling you otherwise — including us — would be
misrepresenting it. The guidance is **advisory**. Its stated audience is
**federal agencies**, not independent open-source maintainers; we adopt it as
good practice because it is good practice, not because it applies to us.

**What this document is:** our own reading of our own project, with the evidence
attached so you can check it rather than believe it. Every row cites something in
the public repository, a workflow you can read, or a command you can run
yourself. Where we could not produce that evidence, the row is **PARTIAL** or
**NOT MET** — never MET. A criterion is not marked MET because we believe we
satisfy it; it is marked MET because you can confirm we do.

**Please do not quote this as certification.** If you are writing about
TicketsCAD's posture, the accurate sentence is: *"the project publishes a
self-assessment against CISA's 2026 open-source guidance, including the criteria
it does not meet."*

### Start with what we do not meet

Ordinarily the failures would be at the bottom. They are here instead, because a
conformance table with the gaps buried at the end is a sales document.

**1. Community — at least two active maintainers. NOT MET.**

**TicketsCAD has one active maintainer.** One person writes the code, reviews
it, and cuts the releases.

CISA's C4 framework treats the number of maintainers as a first-class risk
signal, and the guidance uses a project with a single maintainer as its own
worked example of a project *failing* evaluation. By that criterion, this
project fails. If your assessment treats maintainer count as a red flag — a
single criterion that disqualifies regardless of everything else — then
**TicketsCAD does not pass your assessment, and you should stop here.** That is
a legitimate way to apply the framework and we are not going to argue you out of
it.

Other accounts hold access to the repositories: read-only access, and
administrative rights held alongside the maintainer. **None of that is
maintainership and none of it is counted here as satisfying this criterion.**
Access is not review, and a reader checking the commit history would see through
any claim otherwise in about a minute. There is also no foundation behind the
project.

There are real mitigations, listed under [C4-2](#c4-community) and in
[`GOVERNANCE.md`](../../GOVERNANCE.md). They are engineering controls —
signed artifacts, gated builds, published documentation — and they exist
*because* a second reviewer does not. **They do not satisfy this criterion and
are not offered as though they did.** The only thing that satisfies it is a
second active maintainer.

**2. Conduct — maintainers accept their own commits. NOT MET.** CISA lists this
as a negative signal and it is straightforwardly true here, for the same reason:
with one maintainer there is nobody else to review a change, and there is no
branch protection, no required review and no required status check on `main`. A
change can reach the published tree reviewed only by the person who wrote it.

**3. Neither the code nor the releases are signed.** The SBOM is signed and you
can verify it in one command — but the software it describes is not. Commits are
unsigned, tags are unsigned, there are no release assets and no build
provenance. You can prove our ingredients list is authentic; you cannot yet
prove the same of the software. **NOT MET.**

**4. A permissive licence.** CISA steers producers towards CC0, MIT, ISC or
BSD-3. TicketsCAD is **GPL-2.0**, which is copyleft. This is a deliberate
deviation, not an oversight — see [P-16](#licensing).

**5. End-of-support signalling to `endoflife.date` / OpenEOX.** Not published.
**NOT MET.**

The full list of NOT MET rows is collected in
[What we do not meet](#what-we-do-not-meet-full-list) at the end.

### How to read the ratings

| Rating | Meaning |
|---|---|
| **MET** | An outside reader can confirm this from the public repository, a published workflow, or a command they can run. |
| **PARTIAL** | Substantially done, but with a stated limit — narrower scope than the criterion asks, or true but not externally verifiable. |
| **NOT MET** | Not done, or not evidenceable. No credit taken. |
| **N/A** | Federal-specific and has no meaning for a volunteer project. Listed for completeness, never counted as a pass. |

Item numbers (P-*n*) follow the producer checklist in CISA's guidance,
pp. 12–18. Federal-only items (source-code inventory, code.gov metadata,
`.gov` accounts, contract clauses) are marked **N/A**.

---

## Scorecard

| | Count |
|---|---|
| **MET** | **18** |
| **PARTIAL** | **13** |
| **NOT MET** | **8** |
| N/A (federal-specific) | 5 |
| **Total items assessed** | **44** |

Counting note: 38 items from CISA's producer checklist (of which 5 are
federal-specific and marked N/A), plus 6 C4 criteria that apply to us as the
subject of somebody else's evaluation.

**Do not read the MET count as a score.** The items are not equal in weight.
Item P-23 — an SBOM published with every release — carries more than several
others combined, and it is PARTIAL. C4-2, the maintainer-count criterion, is one
row and is capable of disqualifying the project on its own regardless of
everything above it. A reader who totals the column has learned less than a
reader who reads the NOT MET list.

---

## Deciding to open source (P-1 – P-4)

| # | Criterion | Rating | Evidence |
|---|---|---|---|
| P-1 | Decision to open source made at design phase, not at the end | **MET** | TicketsCAD v4 is the successor to [`openises/tickets`](https://github.com/openises/tickets), public and GPL-2.0 since long before v4 existed. `LICENSE` (GPL-2.0) is present at the first v4 tag, `v4.0.0`. The licence was never in question. |
| P-2 | Default posture is "open source unless exempted" | **PARTIAL** | Everything is published except a fixed list of internal-development material (planning specs, coordination notes, internal ops docs, deployment scripts carrying infrastructure detail). The categories are named in this document and the model is described in [`CONTRIBUTING.md`](../../CONTRIBUTING.md). **Limit:** the exclusion list lives in `tools/release-snapshot.sh`, which is itself excluded from the published tree, so you cannot read the rule — only its output. |
| P-3 | Unpublished code carries a documented exemption | **PARTIAL** | Same as P-2: the exclusions are categorical and deliberate, but a reader cannot audit the list itself. |
| P-4 | Developed openly from the start, so secrets never enter history | **PARTIAL** | Development happens in a private repository and reaches the public one as scrubbed snapshots, which is the pattern CISA cautions about. **Mitigations, and they are real:** the published history contains no development commits to leak from; the snapshot is scrubbed of infrastructure addresses and personal data and then hard-scanned for secrets before it is published; [`.gitleaks.toml`](../../.gitleaks.toml) and [`tools/git-hooks/pre-commit`](../../tools/git-hooks/pre-commit) run against the development tree; GitHub secret scanning and push protection are enabled on the repository. |

---

## Federal source-code inventory (P-5 – P-8)

| # | Criterion | Rating | Evidence |
|---|---|---|---|
| P-5 – P-8 | Source-code inventory, usage types, code.gov metadata schema, submission schedule | **N/A** | Federal agency obligations under OMB M-16-21. No volunteer-project analogue. |

---

## Pre-publication review (P-9 – P-14)

| # | Criterion | Rating | Evidence |
|---|---|---|---|
| P-9 | Code reviewed before publication so secrets are not exposed | **MET** | Three independent layers: [`.gitleaks.toml`](../../.gitleaks.toml) config; the secret check in [`tools/git-hooks/pre-commit`](../../tools/git-hooks/pre-commit) (install with `bash tools/install-git-hooks.sh`); and a hard secret scan in the release process that blocks publication. GitHub secret scanning **and push protection** are enabled — those two are owner-verifiable only, so do not take them on our word alone; the first two you can read. |
| P-10 | Automated secret detection **in CI** that can block publication | **PARTIAL** | Push protection blocks a push containing a recognised secret at the forge, and the pre-commit hook blocks locally. **Limit:** there is no secret-scanning step inside [`.github/workflows/qa.yml`](../../.github/workflows/qa.yml) that you can inspect, and the pre-commit hook is opt-in per clone and can be bypassed with `--no-verify`. |
| P-11 | Automated dependency scan in CI identifying vulnerabilities | **MET** | `.github/workflows/qa.yml`, step **"Dependency vulnerability audit (composer.lock)"** — runs `composer audit --locked` on every push and pull request, and **fails the build**. Dependabot alerts are enabled. **Scope limit, stated plainly:** this covers the PHP/Composer dependencies only. Python service packages and operating-system packages are enumerated in the SBOM but are **not** scanned for vulnerabilities by anything. |
| P-12 | Human security review in addition to the automated checks | **MET** | Process documented in [`CONTRIBUTING.md` § "Security review of contributions"](../../CONTRIBUTING.md). Its output is public and checkable: `tests/test_security_f001_upload.php`, `_f002_feed`, `_f003_fileupload`, `_f004_idor`, `_f007_sse_visibility`, `tests/test_security_csrf_bundle.php`, `tests/test_pre_release_fixes.php` — the regression tests written for findings from the April 2026 review, all of which run in CI. |
| P-13 | Commit history is not squashed | **NOT MET** | The public repository is a squashed snapshot; it has far fewer commits than the real history and shows one contributor. This is a deliberate release model, and it does cost what CISA says it costs — contributor attribution and history are not visible publicly. Attribution is recorded in `CHANGELOG.md` and release notes instead. |
| P-14 | Where history is squashed, full history is preserved in the original location | **PARTIAL** | The private development repository retains the complete history. **This is an assertion you cannot verify** — the tree it lives in is not public. Recorded as PARTIAL for exactly that reason. |

---

## Licensing (P-15 – P-17)

| # | Criterion | Rating | Evidence |
|---|---|---|---|
| P-15 | Explicit, deliberately chosen licence | **MET** | [`LICENSE`](../../LICENSE) — full GNU GPL v2 text. Declared consistently in `README.md`, in [`composer.json`](../../composer.json) (`"license": "GPL-2.0-only"`), and as `GPL-2.0-only` in `SBOM.cdx.json` `metadata.component.licenses`. |
| P-16 | A **permissive** licence (CC0, MIT, ISC, BSD-3) unless circumstances require otherwise | **NOT MET** | GPL-2.0 is copyleft. This is the "circumstances require otherwise" case and we would rather say so than dress it up: TicketsCAD v4 descends from `openises/tickets`, which is GPL-2.0, and it incorporates GPL-2.0 derived source (DMR protocol logic ported from MMDVMHost, recorded as a component in the SBOM). The licence is inherited and cannot be changed unilaterally. If your organisation cannot accept copyleft, that is a genuine blocker and you should know it now. |
| P-17 | Legal counsel reviewed the licence against export controls, privacy and contract terms | **NOT MET** | No legal counsel. A volunteer project has none, so this is self-assessment by non-lawyers. Stated rather than quietly skipped. |

---

## Repository hygiene — the four files plus SBOM (P-18 – P-24)

| # | Criterion | Rating | Evidence |
|---|---|---|---|
| P-18 | Published on a widely used public code repository | **MET** | <https://github.com/openises/TicketsCAD> |
| P-19 | `README.md` giving an overview | **MET** | [`README.md`](../../README.md) — quick start, directory map, security summary, docs index, conventions. |
| P-20 | `LICENSE` identifying the licence | **MET** | [`LICENSE`](../../LICENSE), detected by GitHub as `gpl-2.0`. |
| P-21 | `CONTRIBUTING.md` with contribution guidelines | **MET** | [`CONTRIBUTING.md`](../../CONTRIBUTING.md). Gated by `tests/test_governance_docs.php`, which fails if its substantive sections are removed. |
| P-22 | `SECURITY.md` with a vulnerability disclosure policy and a named security contact | **MET** | [`SECURITY.md`](../../SECURITY.md) — two private channels (GitHub Security Advisories, which is **enabled**; and a named email contact), acknowledgement within 3 business days, remediation timeline within 10, a 45-day coordinated disclosure window, and explicit in-scope/out-of-scope lists. |
| P-23 | **An SBOM published as a release artifact with *every* release** | **PARTIAL** | `SBOM.cdx.json` (CycloneDX 1.6, **95 components**), `SBOM.txt`, `SBOM.cdx.json.sig` and `SBOM-signing-key.pub.pem` are in the repository root at the current tag, and three CI gates keep them honest: `--check` (freshness), `--validate` (official CycloneDX schema), `--verify` (signature). **Three limits, none of them cosmetic:** (1) the SBOM is committed in the tagged tree, **not attached as a GitHub release asset** — no release has assets; (2) the signature and public key exist only from **v4.2.0 onward** — at `v4.0.0`–`v4.1.2` they are absent and the SBOM is an older, smaller document; (3) coverage is bounded and the bounds are stated in the SBOM's own `ticketscad:coverage` property. |
| P-24 | Staff use `.gov`-affiliated accounts | **N/A** | Federal-specific. |

### On the SBOM, since it is the item CISA weights most heavily

What you can check without asking us anything:

```bash
# 1. Is the SBOM real CycloneDX 1.6, per the official schema?
php tools/generate-sbom.php --validate

# 2. Does the published signature match the published bytes?
base64 -d SBOM.cdx.json.sig > sbom.sig
openssl dgst -sha256 -verify SBOM-signing-key.pub.pem -signature sbom.sig SBOM.cdx.json

# 3. Does the SBOM still describe the tree? Regenerate and compare.
php tools/generate-sbom.php --check

# 4. Does it cover what our own installers install?
php tests/test_sbom_installer_coverage.php
```

Check 4 exists because this claim was **wrong once**. When the project announced
that its bill of materials covered the whole dependency chain, two packages
(`onnxruntime` and `piper-tts`) were being pip-installed by
`services/dvswitch/install-bridge.sh` and were absent from the document — on the
same line as a package that *was* listed. The fix was not to add two names but to
make the generator read the installers, and to add a test that fails if anything
an installer installs is missing from the SBOM. The component count went from 56
to 95 as a result, which is a measure of how much the old scan could not see.

Every component that lacks a field says which field and why, in
`ticketscad:unknown` and `ticketscad:unknown-reason`. **No version in this SBOM
is a guess.** Where a version cannot be determined — a package installed with no
constraint, a container tag rather than a digest, a model fetched from a mutable
branch — the SBOM says it does not know, and why. A wrong identifier is worse
than a declared unknown: it sends you looking for vulnerability data about
software nobody is running.

---

## Contribution intake (P-25 – P-28)

| # | Criterion | Rating | Evidence |
|---|---|---|---|
| P-25 | Guidelines specify which kinds of contribution are acceptable | **MET** | [`CONTRIBUTING.md` § "What contributions are welcome"](../../CONTRIBUTING.md) — a table of accepted kinds and an explicit "Not accepted" list. |
| P-26 | Code contributors are required to submit tests | **MET** | `CONTRIBUTING.md` § "Before you open a pull request" requires a test that **fails without the change**; restated as a checkbox in [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md). |
| P-27 | External contributions go through the same review and CI as internal changes | **PARTIAL** | The pipeline is the same and is documented as such. **Limit, and it is a real one:** GitHub holds workflow runs on first-time-contributor pull requests for manual approval, and on the two outside pull requests this project has received the run was never approved — CI has, in fact, never executed on an outside contribution. `CONTRIBUTING.md` now warns contributors that this will happen so it does not read as silence. |
| P-28 | Requirements on external contributors are minimal | **MET** | A Developer Certificate of Origin sign-off (`git commit -s`) and nothing else. No contributor licence agreement, no copyright assignment. |

---

## Ongoing maintenance (P-29 – P-31)

| # | Criterion | Rating | Evidence |
|---|---|---|---|
| P-29 | Updates and new releases are clearly identified | **MET** | [`CHANGELOG.md`](../../CHANGELOG.md) carries a dated section per release; every release has an annotated tag and human-written notes; the running version is in the git-tracked `VERSION` file and shown at Help → About. |
| P-30 | Regular security scans and remediation, following NIST SP 800-218 (SSDF) | **PARTIAL** | Real and continuous: CI runs a fresh install, the full suite, a schema audit, an API↔JS contract audit, an authorisation-split audit, three SBOM gates and now a dependency vulnerability audit on every push. [`docs/MAINTENANCE-RUNBOOK.md`](../MAINTENANCE-RUNBOOK.md) sets cadences. **Limits:** no static application security testing runs in CI (a SonarQube configuration ships but points at a private host, is not run by CI, and no results are published); no CodeQL; no scanner covers the Python or OS dependencies; and the project has not formally mapped its practices to SSDF task identifiers. |
| P-31 | The VDP sets expectations for AI-assisted and semi-automated vulnerability reports | **MET** | [`SECURITY.md` § "Reports produced with AI assistance or automated tooling"](../../SECURITY.md) — states what a machine-assisted report must contain to be actionable, and what will be closed without detailed reply. |

---

## End-of-support signalling (P-32 – P-35)

| # | Criterion | Rating | Evidence |
|---|---|---|---|
| P-32 | A defined test for whether the project is "actively maintained" | **PARTIAL** | [`SECURITY.md` § "Supported versions"](../../SECURITY.md) defines the support model precisely: one supported line, the newest; no backports; no LTS branch; end of life for a version begins when a newer one is tagged. **Limit:** CISA's specific test — *no dependency is end-of-support*, plus patching inside a fixed remediation timeline — is not one we currently measure or publish. We do not, for instance, track whether every Python or OS package we install is still supported upstream. |
| P-33 | The repository is clearly marked unmaintained when maintenance stops | **PARTIAL** | The v3.x line is documented as security-and-bug-fixes-only and pre-3.44 as end of life. **Limit:** there is no written commitment to archive the repository, and no defined trigger for doing so. |
| P-34 | Support status published to `endoflife.date` and/or the OpenEOX schema | **NOT MET** | Neither is published. Verified: no reference to either in any published document. |
| P-35 | Known active forks identified when withdrawing support | **NOT MET** | No policy exists. |

---

## Contributing to other people's projects (P-36 – P-38)

| # | Criterion | Rating | Evidence |
|---|---|---|---|
| P-36 | Legal review of a project before contributing to it | **NOT MET** | No legal counsel — as at P-17. |
| P-37 | Privacy / sensitive-data review of outbound contributions, with staff training | **PARTIAL** | The rules exist and are published — [`CODE_OF_CONDUCT.md`](../../CODE_OF_CONDUCT.md) prohibits posting real operational data, and every issue template repeats it. **Limit:** these govern what enters *our* repository; there is no separate documented review for material we send to *other* projects, and "staff training" has no meaning here. |
| P-38 | Automated privacy checks screening for keys, passwords, configuration, hostnames, IP addresses, credentials | **PARTIAL** | The release process scrubs internal hostnames, RFC1918 addresses and personal names, then hard-scans before publishing; `.gitleaks.toml` and the pre-commit hook cover credentials. **Limit:** this protects our own publications rather than outbound contributions, and the scrubbing script is not itself public. |

---

## C4 — how an evaluator should score us {#c4-community}

CISA's C4 framework is a **consumer-side** model: it is what someone deciding
whether to adopt TicketsCAD is told to apply to us. Our own reading, with the
same evidence rules.

| C4 criterion | Rating | Evidence and limits |
|---|---|---|
| **C4-1 Codebase** — recent commits, no known CVEs, dependencies current | **MET** | Active development with frequent tagged releases. Dependency currency is now gated: `composer audit --locked` fails CI on any known-vulnerable Composer package. That gate was added on 2026-07-30 and immediately found four medium-severity advisories in a shipped copy of `guzzlehttp/guzzle`, which were fixed in the same change. **Limit:** no equivalent gate exists for Python or OS packages. |
| **C4-2 Community** — number of maintainers; foundation backing | **NOT MET** | **One active maintainer.** Other accounts hold read-only or administrative access to the repositories, but do not maintain the project — access is not maintainership and is not counted here. No foundation backing. The squashed-snapshot model (P-13) additionally means outside contributions do not appear in the contributor graph, so the public record shows a single account. **A consumer applying maintainer count as a red-flag criterion will reject TicketsCAD on this row alone, and that is a correct application of the framework.** <br><br>**Real mitigations, offered as mitigations and not as satisfaction of this criterion:** release artifacts are signed and independently verifiable (`--verify`, or one `openssl` command); the bill of materials is gated on every build for freshness, schema conformance and signature; CI performs a true fresh install plus the full suite and several static audits on every push; dependencies are audited for known vulnerabilities and a vulnerable one fails the build; the application maintains an audit trail; the security posture is published and maintained; and contributed code gets a documented adversarial security review with two-directional regression tests. Automation is what one person can put in place instead of a second reviewer. It is not a second reviewer. <br><br>**What would actually change this row:** a second *active* maintainer — named in `GOVERNANCE.md` and `CODEOWNERS`, holding commit access, and visibly reviewing or merging changes they did not write. |
| **C4-3 Conduct** — VDP present | **MET** | [`SECURITY.md`](../../SECURITY.md), with private reporting enabled and response commitments stated. |
| **C4-3 Conduct** — code of conduct present | **MET** | [`CODE_OF_CONDUCT.md`](../../CODE_OF_CONDUCT.md), including an escalation route outside the project for a complaint about a maintainer. |
| **C4-3 Conduct** — project requires code review; maintainers do not accept their own commits | **NOT MET** | No branch protection on `main`, no required reviewers, no required status checks. Verify: `gh api repos/openises/TicketsCAD/branches/main/protection` returns *"Branch not protected"*. A maintainer can therefore merge their own change unreviewed, which is the specific behaviour CISA flags. `CODEOWNERS` routes review requests but cannot enforce them. Same fix as C4-2. |
| **C4-4 Configuration** — secure by default | **PARTIAL** | Defaults are conservative and documented: RBAC with granular permissions, CSRF tokens on state-changing endpoints, login lockout, optional TOTP two-factor, security headers, and every third-party AI or speech integration **off by default** with the operator supplying their own key ([`SECURITY.md` § "What TicketsCAD sends outside your network"](../../SECURITY.md)). **Limits:** TicketsCAD is self-hosted, so TLS, network exposure and database hardening are the operator's to get right; the installation is only as secure as the deployment. |

---

## Software signing and provenance

Not a numbered producer item, but CISA's Codebase and Configuration
considerations lead an evaluator straight to it, so it is stated rather than
left to be discovered.

| Property | Status |
|---|---|
| SBOM signed (ECDSA P-256 / SHA-256, detached) | **Yes** — verifiable with one `openssl` command, from **v4.2.0** onward |
| Signing public key published in-repo, fingerprint in `SECURITY.md` | **Yes** |
| Commits signed | **No** — all commits report `verified=false, reason=unsigned` |
| Tags signed | **No** — annotated, not signed |
| Release assets / build attestation / SLSA provenance | **No** — releases are source-only; there are no attestations |

**The asymmetry, said first by us rather than found by you: the bill of
materials is signed and the software it describes is not.** There is also no
certificate authority and no revocation service behind the signing key — if it
were compromised, the recovery is a published key rotation, documented in
[`docs/SECURITY-POLICY.md`](../SECURITY-POLICY.md) §5.3.

---

## What we do not meet (full list) {#what-we-do-not-meet-full-list}

Nine rows, with the reason for each. No mitigations are claimed here that are
not real.

| # | Criterion | Why not |
|---|---|---|
| **C4-2** | At least two active maintainers | **One active maintainer.** Other accounts hold read-only or administrative access; that is not maintainership and is not counted. No foundation backing. This is the criterion CISA itself uses as its worked example of a project failing evaluation. |
| **C4-3** | Project requires code review; maintainers do not accept their own commits | No branch protection, no required reviewers, no required status checks on `main`. With one maintainer there is also nobody else to review. A change can be merged by its author alone. |
| **P-13** | Commit history is not squashed | The public repository is a squashed snapshot of a private development tree. Deliberate release model; the cost in lost history and attribution is real and is CISA's stated objection. |
| **P-16** | Permissive licence | GPL-2.0 is copyleft. Inherited from the parent project and from GPL-derived source we incorporate; cannot be changed unilaterally. |
| **P-17** | Legal counsel review of the licence | A volunteer project has no counsel. Self-assessed by non-lawyers. |
| **P-34** | Support status published to `endoflife.date` / OpenEOX | Not done. Low cost, not yet done; no reason beyond that. |
| **P-35** | Known active forks identified when withdrawing support | No policy exists. |
| **P-36** | Legal review before contributing to third-party projects | As P-17 — no counsel. |
| — | Signed commits, signed tags, release assets, build provenance | None implemented. See the signing table above. |

And the one that matters most, restated because it belongs in both places:
**TicketsCAD has one active maintainer, and CISA's Community criterion is NOT
MET.** No amount of engineering discipline changes that, and nothing in this
document is offered as though it did. An evaluator who declines the project on
that basis is applying the framework correctly.

A note on a related credit, so it cannot be misread as a claim: the project's
original designer is recorded as **Maintainer Emeritus** in
[`GOVERNANCE.md`](../../GOVERNANCE.md) and [`AUTHORS.md`](../../AUTHORS.md).
That is an honour for designing the system and is **not** counted as
maintainership anywhere in this assessment. An emeritus maintainer holds no
operational responsibility. The active-maintainer count for the purposes of this
table is one.

---

## Things we could easily have claimed and did not

Stated so you can calibrate how much to trust the rows above.

- **We did not mark the SBOM item MET**, although it is the strongest artefact in
  the project, because "with every release" is not true for releases before
  v4.2.0 and the files are not attached as release assets.
- **We did not count GitHub secret scanning or push protection as fully
  evidencing P-10**, because you cannot see a repository setting from outside and
  we are not asking you to take our word for it.
- **We did not claim the April 2026 security review as an audit you can read.**
  The findings are not public. What is public is every regression test written
  from them, running in CI. The remediation is verifiable; the finding is not,
  and those are different claims.
- **We did not mark P-11 MET without naming its scope.** It scans PHP
  dependencies. It does not scan the Python packages or operating-system packages
  that the same SBOM enumerates.
- **We fixed rather than documented** the four `guzzlehttp/guzzle` advisories
  found while writing this. Reporting a known-vulnerable dependency in a
  conformance statement, while shipping it, would have been the wrong way round.
- **We did not count access as maintainership.** Several accounts hold read-only
  or administrative access to the repositories, and an earlier draft of this
  document read that as evidence for the Community criterion. It is not:
  an evaluator counts who reviews and commits, not who could. The row was
  corrected to NOT MET before publication.
- **We did not count the Maintainer Emeritus credit** towards the
  active-maintainer criterion. It is a credit for designing the system, not a
  role with operational responsibility, and treating it as the latter would be
  precisely the overclaim this document exists to avoid.

---

## How to re-run this assessment

Nothing here requires our cooperation.

```bash
git clone https://github.com/openises/TicketsCAD.git && cd TicketsCAD

# The four community-health files CISA asks for
ls README.md LICENSE CONTRIBUTING.md SECURITY.md CODE_OF_CONDUCT.md GOVERNANCE.md CODEOWNERS

# SBOM: schema-valid, signature-valid, current, and covering our installers
php tools/generate-sbom.php --validate
php tools/generate-sbom.php --verify
php tools/generate-sbom.php --check
php tests/test_sbom_installer_coverage.php

# The governance documents still contain what this table cites them for
php tests/test_governance_docs.php

# What CI actually gates
cat .github/workflows/qa.yml

# The claims about repository settings, which are the ones you cannot see from the tree
gh api repos/openises/TicketsCAD/branches/main/protection     # expect: not protected
gh api repos/openises/TicketsCAD/private-vulnerability-reporting  # expect: enabled
gh api "repos/openises/TicketsCAD/commits?per_page=5" \
  --jq '.[].commit.verification.verified'                     # expect: false (unsigned)
```

If you run these and find a row in this document that is wrong, that is a
security-relevant defect in a published claim and we would like to hear about it
— [`SECURITY.md`](../../SECURITY.md) has the channels. A conformance statement
that cannot be falsified is not worth reading.

---

## Review schedule

This document is reviewed when any of the following happens, whichever comes
first:

- **Every release** that changes a security control, a CI gate, or the SBOM.
- **Whenever a repository setting changes** that a row depends on — branch
  protection, private vulnerability reporting, secret scanning, Dependabot.
- **When a maintainer is added**, which is what changes C4-2.
- **Annually**, in any case.

Superseded revisions are kept in git history rather than edited away.

---

*CISA, its guidance, and the United States Government do not endorse, certify or
approve TicketsCAD or any other product. This is a voluntary self-assessment
against advisory guidance, published so that it can be checked.*
