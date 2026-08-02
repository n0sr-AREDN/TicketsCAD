# Governance

This document says who decides what, how a change gets in, and what a person
evaluating TicketsCAD for operational use should understand about how it is run.

It is written for two readers: a contributor who wants to know how their patch
lands, and someone at an agency deciding whether to depend on this software for
dispatch. The second reader is the reason this file avoids marketing language.

## What kind of project this is

TicketsCAD is a **small volunteer project**. It is not a company, not a
foundation project, and has no commercial support contract behind it. Nobody is
paid to work on it, and nothing here is covered by a service-level agreement.
The licence ([GPL-2.0](LICENSE)) says this plainly and it is worth repeating in
plain English: **the software is provided as-is, with no warranty.**

**There is currently one active maintainer** — one person writing, reviewing and
releasing the code. Other accounts hold access to the project: read-only access
for people following along, and administrative rights held alongside the
maintainer. **Access is not maintainership, and this document does not count it
as such.** If you are evaluating TicketsCAD, the number you should work with is
one.

That is stated here rather than left for you to work out from the commit graph,
because it is the single most important thing an evaluator needs to know and the
easiest thing for a governance document to obscure. See
[Continuity](#continuity) for what does and does not follow from it.

## Maintainers

<!-- MAINTAINERS: fill this in. One row per person who ACTIVELY maintains the
     project — commits, reviews, or releases. Do not list people who only hold
     read access, and do not list anyone without their agreement.
     Publish only what that person is happy to have public: a name or GitHub
     handle, their role, and their area. No email addresses (SECURITY.md
     carries the one contact address the project publishes), and no personal
     circumstances of any kind. -->

| Name or GitHub handle | Role | Area |
|---|---|---|
| _to be completed_ | Lead maintainer | Overall direction, releases, security |

**Being listed here means** commit access, the ability to review and accept a
contribution, and a say in direction. It does not create an obligation to
respond within any particular time.

### Maintainer Emeritus

| GitHub handle | Role |
|---|---|
| [@ashore1008](https://github.com/ashore1008) | **Maintainer Emeritus** — original designer of TicketsCAD |

TicketsCAD exists because he designed it. The dispatch model this software is
built on, the problem it solves, and decades of the work that made it useful to
volunteer emergency-services groups are his; he later transferred stewardship of
the project to the current maintainer. The credit is permanent and is recorded
in [`AUTHORS.md`](AUTHORS.md) and the README as well as here, so that it ships
with every release rather than depending on any account or any file that might
be reorganised later.

**Emeritus is an honour, not a job.** It carries no operational responsibility,
no obligation of any kind, and no expectation of availability. For the avoidance
of doubt — and because being precise here is what keeps the rest of this project's
documentation trustworthy — **an emeritus maintainer is not an active
maintainer**, and this credit is deliberately *not* counted anywhere in
[`docs/security/cisa-oss-2026-conformance.md`](docs/security/cisa-oss-2026-conformance.md)
towards the criterion asking for two active maintainers. That criterion is
recorded there as NOT MET, and it stays that way.

Other people hold access to the repositories without appearing in this table —
read-only access, or administrative rights. That is deliberate: **this table
lists who maintains the project, not who can reach it.** Conflating the two
would make the project look more resilient than it is.

**Why this matters for an evaluation.** CISA's guidance on open-source
trustworthiness treats the number of active maintainers as a first-class risk
signal, and uses a project with one maintainer as its own worked example of a
project *failing* that criterion. TicketsCAD fails it. We record that in
[`docs/security/cisa-oss-2026-conformance.md`](docs/security/cisa-oss-2026-conformance.md)
as **NOT MET**, without softening, because a reader who catches us dressing up
the one thing we fail has no reason to believe the rest of the table.

The project would like a second active maintainer. See
[How someone becomes a maintainer](#how-someone-becomes-a-maintainer) — that
route is open and the interest is genuine.

## How decisions are made

In public, where the subject is public — an issue or a pull-request thread.
Anyone may argue for a change and a good argument generally wins; the discussion
is visible and you can read how past ones went.

With one active maintainer, the honest description of the mechanism is that
**the lead maintainer decides.** There is no vote, no quorum, and no second
person whose agreement is required. If a second maintainer joins, this section
changes to discussion and rough consensus between them, with the lead maintainer
as the tie-break — but writing that now would describe machinery that does not
exist.

Two categories are handled outside the public thread:

- **Security response** — handled privately under [`SECURITY.md`](SECURITY.md),
  not debated in public before a fix exists.
- **Release timing and version numbers** — the lead maintainer cuts releases;
  the SBOM signing key is held by one person (see
  [`docs/SECURITY-POLICY.md`](docs/SECURITY-POLICY.md) §5.3).

### What gets accepted

TicketsCAD is used by agencies whose procedures differ from each other. A change
that is obviously right for one group can be wrong for the project. The rough
test applied to a proposal:

1. **Does it help a dispatcher during an incident?** That is the product's
   purpose and the strongest argument available.
2. **Does it work for an installation that is not yours?** Configurable beats
   hardcoded; opt-in beats mandatory.
3. **Can it be maintained?** A feature nobody understands in a year is a
   liability, not an asset.
4. **Does it carry tests?** See [`CONTRIBUTING.md`](CONTRIBUTING.md).

A declined proposal is not a judgement on the person who made it. Scope
discipline is most of what keeps a volunteer project alive.

## How someone becomes a maintainer

There is no application form. The path is ordinary and slow on purpose:

1. **Contribute.** Issues, reproductions, documentation fixes and patches all
   count. Sustained useful contribution is the whole qualification.
2. **Show judgement over time.** The thing being assessed is not raw skill, it
   is whether someone's changes turn out to be right, and whether they say so
   when they are not.
3. **An existing maintainer proposes it**, the others agree, and the person is
   asked. Nobody is added without being asked first.

The project is genuinely interested in growing the maintainer group. If you have
been contributing and want to take more responsibility, say so — that
conversation is welcome rather than presumptuous.

## Continuity

The honest question an agency should ask is *what happens if the person running
this stops*. The answer has two halves, and the first one is uncomfortable.

**The uncomfortable half: development is dependent on one person.** There is no
succession plan, no second active maintainer to take over, and no organisation
standing behind the project. If the maintainer stopped, new development would
stop with them. Nothing in this section should be read as a promise otherwise —
and if a procurement process requires a supported product with a named
successor, TicketsCAD is not that, today.

**The half that is genuinely reassuring**, because it does not depend on anyone
remaining available:

- **The licence guarantees the code survives regardless.** TicketsCAD is
  GPL-2.0. Anyone may fork it, maintain it, and run it forever, with no
  permission required from anyone here. That is not a courtesy — it is the
  irrevocable grant in [`LICENSE`](LICENSE), and it is the strongest continuity
  assurance any open-source project can offer.
- **Your data is yours and it is not locked in.** TicketsCAD stores its data in
  a MariaDB/MySQL database you control, on a server you control. Backups are
  plain SQL ([`docs/BACKUP-RECOVERY-RUNBOOK.md`](docs/BACKUP-RECOVERY-RUNBOOK.md)).
  There is no hosted service to shut down and no proprietary format to be
  stranded in.
- **The public repository is a published snapshot** of the development
  repository (see [`CONTRIBUTING.md`](CONTRIBUTING.md)). Every release is tagged
  on GitHub, so the full source of every shipped version stays available even if
  work stops.

### What one maintainer builds instead of a second maintainer

None of the following substitutes for a second pair of eyes, and none of it is
offered as though it did. They are the controls that *can* be automated, put in
place precisely because the human review that a larger project gets for free is
not available here. Every one is verifiable by you, without asking us:

- **Release artifacts are signed and independently checkable.** The bill of
  materials carries a detached ECDSA P-256 signature and the public key is
  published, so you can verify it with one `openssl` command
  ([`SECURITY.md`](SECURITY.md)).
- **The bill of materials is gated on every build**, not produced once and left
  to rot: continuous integration fails if it is stale, if it does not validate
  against the official CycloneDX schema, or if its signature does not match.
- **Continuous integration does a true fresh install** on every push — empty
  database, base schema, every migration, admin bootstrap, then the full test
  suite and a set of static audits. You can read exactly what it enforces in
  [`.github/workflows/qa.yml`](.github/workflows/qa.yml).
- **Dependencies are audited for known vulnerabilities** on every build, and a
  known-vulnerable dependency fails it.
- **The application keeps an audit trail** of who changed what, which is a
  control for operators rather than for the code, but it is the same principle:
  make the record automatic rather than dependent on someone remembering.
- **The security posture is written down and maintained**, not asserted — see
  [`docs/SECURITY-POLICY.md`](docs/SECURITY-POLICY.md), the maintenance runbook,
  and the self-assessment in
  [`docs/security/cisa-oss-2026-conformance.md`](docs/security/cisa-oss-2026-conformance.md),
  which lists what the project fails as prominently as what it meets.
- **Contributed code gets a documented adversarial security review** before it
  is accepted, with regression tests checked in both directions
  ([`CONTRIBUTING.md`](CONTRIBUTING.md)).

Read that list as what it is: a project compensating, in the ways a machine can
compensate, for a gap it has not closed.

What the project does **not** promise: a second maintainer, a successor
organisation, an escrow arrangement, or a guaranteed response time. If your
procurement process requires those, TicketsCAD does not have them, and you
should weigh that honestly rather than take reassurance from this file.

## Relationship to the legacy project

TicketsCAD v4 (`openises/TicketsCAD`) is the active line. The v3.44 line
(`openises/tickets`) receives security and bug fixes only — no new features. The
support policy for both is in [`SECURITY.md`](SECURITY.md).

## Changing this document

Open an issue or a pull request. Governance changes are discussed in public
before they take effect.
