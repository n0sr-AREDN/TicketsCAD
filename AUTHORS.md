# Authors and credits

TicketsCAD is computer-aided dispatch software for volunteer emergency-services
groups — amateur-radio emergency communications, volunteer fire departments,
CERT teams, search and rescue, small EMS agencies and campus security.

This file ships with every release. It is the permanent record of who the
project owes.

---

## Maintainer Emeritus

**[@ashore1008](https://github.com/ashore1008)** — original designer of
TicketsCAD.

He designed this system. The dispatch model it is built on, the understanding of
what a volunteer group actually needs during a callout, and many years of work
keeping it useful to real agencies are his. TicketsCAD would not exist without
him, and the current version is a continuation of his design rather than a
departure from it.

He later transferred stewardship of the project to the current maintainer.
**Maintainer Emeritus** records that history permanently: an honoured founder,
with no operational responsibility and no obligation of any kind.

---

## Current maintainer

**Eric Osterberg** ([@ejosterberg](https://github.com/ejosterberg)) — see the
Maintainers table in [`GOVERNANCE.md`](GOVERNANCE.md) for role and area.

---

## Contributors

TicketsCAD v4 has been improved by people running it during real incidents and
reporting what broke — bug reports, reproductions on second installations,
documentation corrections and patches.

Because the public repository is a published snapshot of a private development
tree, the commit graph there does not show individual contributions (see
[`CONTRIBUTING.md`](CONTRIBUTING.md)). Attribution is therefore recorded in
[`CHANGELOG.md`](CHANGELOG.md) and in the release notes for the version that
carried the change. If you contributed and would like to be credited
differently — or not at all — please say so and it will be respected.

The predecessor project, [`openises/tickets`](https://github.com/openises/tickets)
(v3.x), carries its own contributor history in its repository.

---

## Third-party software

TicketsCAD is built on other people's work. Every third-party component it
ships, installs, or downloads is enumerated — with its licence where known, and
an explicit statement of what could not be determined where it was not — in:

- [`SBOM.cdx.json`](SBOM.cdx.json) — machine-readable (CycloneDX 1.6)
- [`SBOM.txt`](SBOM.txt) — the same content, human-readable

Notable among them, because the licence obligation is a real one rather than a
courtesy: the DMR protocol logic in `services/dvswitch/` is derived from
**MMDVMHost** by Jonathan Naylor (G4KLX), used under GPL-2.0 and recorded as a
component in the SBOM with its provenance.

---

## Licence

TicketsCAD is licensed under the GNU General Public License version 2 — see
[`LICENSE`](LICENSE). The same licence as the parent project, and for the same
reason: anyone may use it, study it, change it, and pass it on.
