# Vendored CycloneDX schemas

The **official, unmodified** CycloneDX JSON schemas, used to validate
`SBOM.cdx.json` before it is written and before a release is published.

They are vendored rather than fetched so that validation is deterministic,
offline, and auditable: the file that gates a release is the file in this
repository, and you can diff it against upstream yourself.

| File | Purpose |
|---|---|
| `bom-1.6.schema.json` | The CycloneDX 1.6 BOM schema. The document we publish claims to be this. |
| `spdx.schema.json`    | The SPDX licence/exception identifier enum (811 entries) that `bom-1.6` `$ref`s. |
| `jsf-0.82.schema.json` | JSON Signature Format, `$ref`d by `bom-1.6` for the native enveloped `signature` property. |

`bom-1.6.schema.json` references the other two by **relative filename**, so all
three must stay side by side in this directory.

## Provenance

- **Source:** <https://github.com/CycloneDX/specification>, `schema/` directory
- **Upstream commit:** `0bd48c88d1b1877c7a3536252e06893850763190` (2026-02-25)
- **Retrieved:** 2026-07-29
- **Licence:** Apache-2.0 (the CycloneDX specification, OWASP Foundation)

SHA-256 of the files as vendored:

```
18f57f7482593bad9f21b4feed09084640cbeff419d62ad5090c5ceccca5b37d  bom-1.6.schema.json
ea6e844ee6fba1e93473d94834d0ee0996970533497935f932f73d488ffdf4a3  spdx.schema.json
8bae002c25e723db7ee1f26afde680ae1a2b1a8f6b4b4b0fd65dc3becb090aae  jsf-0.82.schema.json
```

## Why this directory exists at all

TicketsCAD published a `SBOM.cdx.json` declaring `"specVersion": "1.6"` for a
full day without anything ever checking that claim against the 1.6 schema. It
was invalid: `mysql-connector-python` carried
`"license": {"id": "GPL-2.0-with-FOSS-exception"}`, and that string is not one
of the 811 identifiers SPDX defines, so the document failed
`/components/*/licenses` `oneOf` outright.

Asserting conformance is not the same as having it. The schema now lives here
and the generator refuses to write a document that does not validate against
it. See `tools/generate-sbom.php` and `docs/SECURITY-POLICY.md` §5.

## Validating by hand

```bash
npx --yes -p ajv-cli@5 -p ajv-formats@3 ajv validate \
  --spec=draft7 --strict=false -c ajv-formats \
  -s tools/schema/cyclonedx/bom-1.6.schema.json \
  -r tools/schema/cyclonedx/spdx.schema.json \
  -r tools/schema/cyclonedx/jsf-0.82.schema.json \
  -d SBOM.cdx.json
```

Or simply `php tools/generate-sbom.php --validate`, which runs exactly that.

## Updating them

Re-download all three from the same upstream commit or a later one, update the
commit id, date and hashes above, and re-run
`php tools/generate-sbom.php --validate`. Never hand-edit a schema: the point of
vendoring is that it is byte-identical to the published specification.
