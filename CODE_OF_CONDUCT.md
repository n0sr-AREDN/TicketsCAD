# Code of conduct

TicketsCAD is dispatch software for volunteer emergency-services groups. Most
people who show up here are volunteers themselves, reporting something that
broke during a real callout. That context sets the tone: be useful, be plain,
and remember the person on the other end gave up an evening to write to you.

## The short version

**Be civil. Assume the other person is acting in good faith. Stay on the
technical question.**

If you can do that, you will never have a problem here and you can stop reading.

## What is expected

- **Write like a colleague, not a customer.** "This doesn't work" costs the
  maintainer an hour of guessing. "Unit status won't save on v4.2.1, Firefox,
  here's the console error" gets fixed the same day.
- **Assume competing priorities, not neglect.** An unanswered issue usually
  means one person had a bad week, not that you were ignored.
- **Accept a "no".** Not every request fits a product used by other agencies
  with different procedures. A declined feature is not a personal judgement.
- **Protect real people's data.** See the section below — this one is not
  negotiable.

## What is not acceptable

- Personal attacks, insults, or demeaning comments about a person rather than
  their code or their argument.
- Harassment of any kind, including sustained unwanted contact after being asked
  to stop, and any sexual attention or imagery.
- Discriminatory language or conduct — including on the basis of race,
  ethnicity, national origin, sex, gender identity or expression, sexual
  orientation, disability, age, religion, or veteran status.
- Publishing someone's private information without their explicit permission.
- Deliberately wasting the maintainer's time: bad-faith reports, spam,
  or knowingly false claims.

## Operational data — the rule specific to this project

**Never post real incident data, real patient information, real names,
addresses, phone numbers, or amateur-radio callsigns of third parties** in an
issue, a pull request, a screenshot, or a log excerpt. This applies even when
it would make the bug easier to diagnose.

Redact before you post. If a bug genuinely cannot be described without
operational data, say so in the issue and send the detail to the private
security channel in [`SECURITY.md`](SECURITY.md) instead. Nobody is going to
think less of a report that arrives redacted.

This rule exists because the software's users are dispatchers. A screenshot of a
live call board is somebody's medical emergency.

## Scope

This applies in every project space: GitHub issues, pull requests, discussions,
commit messages, and email to the maintainer about the project. It also applies
when you are identifiably representing the project somewhere else.

## Reporting a problem

Email **ejosterberg@gmail.com** with `[TicketsCAD conduct]` in the subject.

Now the honest part. **This is a volunteer project with one active maintainer**
(see [`GOVERNANCE.md`](GOVERNANCE.md)). That has three consequences you should
know before you report:

1. **There is no conduct committee and no appeals process.** One person reads
   the report and decides. That person is the same person who writes the code.
2. **If your complaint is about the maintainer, there is no neutral party inside
   this project to hear it.** GitHub's own [reporting
   process](https://support.github.com/contact/report-abuse) is independent of
   the project and is the right route in that case. Please use it — it is a real
   option, not a brush-off.
3. **Response is best-effort, not guaranteed within a fixed window.** Volunteer
   project, no staff.

Reports are handled privately. The reporter's identity is not disclosed to the
person reported without the reporter's consent.

## What happens after a report

Proportionate to what occurred, and decided by the maintainer:

1. **A private word** — the usual outcome, and usually the end of it.
2. **A public correction** in the thread, where the conduct was public and left
   a wrong impression.
3. **Edit or removal** of the offending comment, with a note that it was
   removed.
4. **A temporary block** from the repository.
5. **A permanent block.**

Steps 4 and 5 are for harassment, repeated behaviour after a warning, or a
single severe incident. They are not for someone being blunt about a bug.

## Attribution

This document is written for this project rather than adopted from a template.
It borrows the *structure* of the [Contributor
Covenant](https://www.contributor-covenant.org/) — expected behaviour,
unacceptable behaviour, scope, enforcement — because that structure is familiar
and works. It deliberately does not copy the Covenant's text, because the
Covenant's enforcement language describes a formal community-leadership body
with an appeals path, and this project does not have one. Describing governance
machinery that does not exist would be the first dishonest sentence in the
document.
