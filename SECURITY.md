# Security policy

TicketsCAD v4 is used by emergency-communications volunteer groups; we
take security seriously. Thank you for taking the time to report
problems responsibly.

## Reporting a vulnerability

**Do not open a public issue.** Use one of the following private
channels:

1. **GitHub Security Advisories** — https://github.com/openises/TicketsCAD/security/advisories/new
2. **Email the maintainer** — `ejosterberg@gmail.com`. Use a subject line
   that begins with `[TicketsCAD security]`.

Please include:

- Affected version (commit SHA or `NEWUI_VERSION`)
- A clear description of the issue and the impact
- Steps to reproduce — a minimal proof-of-concept request, payload, or
  setup is ideal
- Whether the issue has been disclosed publicly anywhere

We aim to acknowledge new reports within **3 business days** and provide
a remediation timeline within **10 business days**. Critical issues
(authentication bypass, RCE, data loss, mass account takeover) are
prioritized for same-week patches.

## Scope

In scope:

- The PHP code in `api/`, `inc/`, top-level pages, and `proxy/`
- The migration tooling in `tools/install_fresh.php`,
  `tools/import-fcc.php`, and the test suite
- The shipped systemd unit and Apache `.htaccess` files
- The schema as defined in `sql/` and applied by `install_fresh`

Out of scope:

- The legacy `openises/tickets` v3.x codebase — file those reports there
- Vulnerabilities **in the upstream code** of third-party libraries under
  `vendor/` and `assets/vendor/` — file those with the upstream project.
  Every one of those libraries is enumerated with its version in our
  published SBOM (see below), so you can identify and report them precisely.
  **In scope for us:** telling us that a library we ship is outdated,
  vulnerable, or wrong in the SBOM. Shipping a known-vulnerable version is
  our problem to fix, even when the defect is upstream.
- Penetration testing of a running instance — please coordinate with the
  operator first
- Findings on a clearly out-of-date deployment that has missed published
  patches

## What you can expect from us

- Confirmation of receipt
- A CVSS or severity assessment
- Coordinated disclosure — we ask for **45 days** to patch + ship before
  public disclosure, longer for issues that require schema changes
- Credit in the audit doc and release notes if you want it

## Software Bill of Materials (SBOM)

Every release ships a Software Bill of Materials — a complete list of the
third-party code TicketsCAD contains, with versions and licences — so that
your organisation can answer "does the new vulnerability everyone is talking
about affect our dispatch system?" without reading our source code.

| File | What it is |
|---|---|
| `SBOM.cdx.json` | Machine-readable, CycloneDX 1.6 (ECMA-424). Readable by Dependency-Track, Trivy, Grype, and similar tools. |
| `SBOM.txt` | The same information as plain text, for people. |
| `SBOM.cdx.json.sig` | A detached digital signature over `SBOM.cdx.json`. |
| `SBOM-signing-key.pub.pem` | The public key that checks that signature. |

It is generated from the repository, not written by hand:

```bash
php tools/generate-sbom.php            # regenerate
php tools/generate-sbom.php --check    # fail if the committed SBOM is stale
php tools/generate-sbom.php --verify   # check the signature (needs no private key)
php tools/generate-sbom.php --validate # check it really is valid CycloneDX 1.6
```

`--check` and `--validate` both run in CI on every push and again in the release
script, so the SBOM can neither drift out of date nor stop conforming to the
schema it claims. `--validate` runs the reference JSON-Schema validator (ajv)
against the **official** CycloneDX schema, vendored unmodified at
[`tools/schema/cyclonedx/`](tools/schema/cyclonedx/) so you can check it
offline and diff it against upstream yourself. It needs Node.js on PATH.

This gate exists because we got it wrong: a `SBOM.cdx.json` that declared
`"specVersion": "1.6"` was published while it did **not** satisfy the 1.6
schema. One component carried the licence identifier
`GPL-2.0-with-FOSS-exception`, which SPDX does not define, so the document
failed validation outright and would have been rejected by tooling. Declaring
conformance is not the same as having it, and now something checks.

**Standard.** The SBOM is built to the *2026 Minimum Elements for a Software
Bill of Materials (SBOM)*, published 2026-07-29 by CISA, NSA, FBI and
international partners, which supersedes the 2021 NTIA minimum elements:
https://www.cisa.gov/resources-tools/resources/2026-minimum-elements-software-bill-materials-sbom

**Status: 17 of 17 data fields, 6 of 6 practices**, across 56 components.

### Verify the signature yourself

Do not take our word for any of this. The SBOM is signed, and you can check the
signature without contacting us and without trusting us. You need the three
files that ship with every release and OpenSSL, which is already on macOS and
Linux and comes with Git for Windows:

```bash
base64 -d SBOM.cdx.json.sig > sbom.sig
openssl dgst -sha256 -verify SBOM-signing-key.pub.pem -signature sbom.sig SBOM.cdx.json
```

`Verified OK` means the file is byte-for-byte the one signed with our key.
Anything else means it is not, and you should ask us why. If you would rather
not use the command line, `php tools/generate-sbom.php --verify` does the same
check.

| | |
|---|---|
| Algorithm | ECDSA on NIST P-256 with SHA-256, detached signature |
| Public key | `SBOM-signing-key.pub.pem`, in this repository |
| Public key SHA-256 fingerprint | `XRcJ3AwAm0OzSzjmU8KWkknftutwY36a6z7st2YrU0g=` |
| Private key | Held by the maintainer, off this repository and out of CI. See `docs/SECURITY-POLICY.md` §5.3 for custody, rotation and compromise handling. |

The fingerprint above is also recorded inside the SBOM itself
(`ticketscad:signature-public-key-sha256`). If you want assurance that the key
file is genuinely ours, compare that fingerprint against a copy you obtained
some other way rather than from the same download.

There is no certificate authority and no revocation service behind this key, and
we do not claim otherwise. Its trustworthiness rests on being published openly
in this repository, where a substitution would be visible in the git history.

**One thing we want to be straight about: unknowns are labelled, not guessed.**
Some components ship without any version marker; others — pip packages, Composer
packages, container base images, scripts the browser fetches from a CDN — are
installed on your server or fetched at runtime, so we have no artifact to hash
and cannot state a producer or a licence from evidence. Rather than assume,
those components carry a `ticketscad:unknown` property naming each field we
could not determine and a `ticketscad:unknown-reason` explaining why. The
guidance asks for exactly this ("Explicitly Identifying Unknown Information").
Nothing in the SBOM is withheld. The generator will not produce an SBOM in
which a required field is simply missing rather than declared.

## Existing audit + hardening posture

NewUI v4 went through a multi-session security audit in April 2026.
Every CRITICAL and HIGH finding has been remediated with a regression
test. The project's security posture, key management, and CJIS notes are documented
in [`docs/SECURITY-POLICY.md`](docs/SECURITY-POLICY.md).

Run the security tests locally before declaring an installation safe:

```bash
php tests/test_security_f001_upload.php          # upload RCE chain
php tests/test_security_f002_feed.php            # feed fail-closed
php tests/test_security_f003_fileupload.php      # legacy file-upload
php tests/test_security_f004_idor.php            # IDOR triplet
php tests/test_security_f007_sse_visibility.php  # SSE per-user filter
php tests/test_security_csrf_bundle.php          # CSRF on writes
php tests/test_pre_release_fixes.php             # regression bundle
```

## Operator hardening checklist

- Run `php tools/install_fresh.php` after every upgrade — the migration
  ships schema fixes alongside code.
- Set a non-empty `feed_api_key` in Settings → API Keys before exposing
  `api/feed.php` to anything outside the LAN.
- Verify `uploads/.htaccess` exists and contains `php_flag engine off`.
  `install_fresh` writes it if missing.
- Make sure `keys/` lives **outside** the webroot (`/var/www/keys/` for
  the standard layout). PEM files: mode 600, owner = web server user.
- Enable HTTPS for every public install. Session cookies set
  `Secure` automatically when `$_SERVER['HTTPS']` is on.
- Rotate the encryption key (`keys/private.pem`) if compromise is
  suspected — see [`docs/SECURITY-POLICY.md`](docs/SECURITY-POLICY.md).
