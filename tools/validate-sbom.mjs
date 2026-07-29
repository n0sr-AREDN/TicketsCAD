/**
 * Validate a CycloneDX document against the OFFICIAL, vendored 1.6 schema.
 *
 * Usage (this is what tools/generate-sbom.php runs):
 *
 *   npx --yes -p ajv-cli@5 -p ajv-formats@3 \
 *     node tools/validate-sbom.mjs SBOM.cdx.json
 *
 * Why a script rather than `ajv-cli` flags: ajv-cli's `-c ajv-formats` cannot
 * resolve a sibling package out of npx's temporary install, so format keywords
 * were silently ignored — the validator would have reported "unknown format
 * date-time ignored" 62 times and still said the document was fine. Driving
 * ajv directly lets us resolve both packages explicitly, assert formats, and
 * emit one clean machine-readable result.
 *
 * Exit codes:  0 valid  ·  1 invalid  ·  2 could not validate (setup problem).
 * Output: a single JSON object on stdout, so the caller never has to scrape
 * human prose.
 */

import { createRequire } from 'node:module';
import { readFileSync }  from 'node:fs';
import { fileURLToPath } from 'node:url';
import path              from 'node:path';
import process           from 'node:process';

function out(obj, code) {
  process.stdout.write(JSON.stringify(obj, null, 2) + '\n');
  process.exit(code);
}

/* ------------------------------------------------------------------ *
 * Resolve ajv + ajv-formats out of npx's temporary node_modules.
 *
 * npx puts <temp>/node_modules/.bin on PATH but does NOT put <temp>/
 * node_modules on the module search path, and node resolves from THIS file's
 * directory — inside the repository, where neither package exists. So find the
 * .bin entry on PATH and require from its parent. NODE_PATH and ordinary
 * resolution are tried too, so the script also works against a local install.
 * ------------------------------------------------------------------ */
function moduleRoots() {
  const roots = [];
  const sep   = process.platform === 'win32' ? ';' : ':';

  for (const entry of (process.env.PATH || '').split(sep)) {
    const e = entry.replace(/[\\/]+$/, '');
    if (/node_modules[\\/]\.bin$/i.test(e)) roots.push(path.dirname(e));
  }
  for (const entry of (process.env.NODE_PATH || '').split(sep)) {
    if (entry) roots.push(entry);
  }
  return [...new Set(roots)];
}

function loadDeps() {
  const tried = [];
  for (const root of [...moduleRoots(), null]) {
    try {
      const req = root
        ? createRequire(path.join(root, 'noop.js'))
        : createRequire(import.meta.url);
      const Ajv        = req('ajv');
      const addFormats = req('ajv-formats');
      return { Ajv: Ajv.default || Ajv, addFormats: addFormats.default || addFormats };
    } catch (e) {
      tried.push(`${root || '(default resolution)'}: ${e.code || e.message}`);
    }
  }
  out({ status: 'unavailable',
        error: 'could not load ajv and ajv-formats',
        tried }, 2);
}

/* ------------------------------------------------------------------ */
const dataPath = process.argv[2];
if (!dataPath) out({ status: 'unavailable', error: 'usage: validate-sbom.mjs <bom.json>' }, 2);

/* fileURLToPath, not url.pathname: the latter keeps the leading slash before a
 * Windows drive letter and leaves %20 in place for paths containing spaces. */
const schemaDir = path.join(path.dirname(fileURLToPath(import.meta.url)), 'schema', 'cyclonedx');

const { Ajv, addFormats } = loadDeps();

let bomSchema, spdxSchema, jsfSchema, data;
try {
  bomSchema  = JSON.parse(readFileSync(path.join(schemaDir, 'bom-1.6.schema.json'),  'utf8'));
  spdxSchema = JSON.parse(readFileSync(path.join(schemaDir, 'spdx.schema.json'),     'utf8'));
  jsfSchema  = JSON.parse(readFileSync(path.join(schemaDir, 'jsf-0.82.schema.json'), 'utf8'));
} catch (e) {
  out({ status: 'unavailable', error: `cannot read vendored schema: ${e.message}` }, 2);
}
try {
  data = JSON.parse(readFileSync(dataPath, 'utf8'));
} catch (e) {
  out({ status: 'invalid', error: `document is not parseable JSON: ${e.message}` }, 1);
}

/* ajv logs "unknown format X ignored" once per occurrence — 60-odd lines of
 * noise. Capture them instead: ajv-formats does not implement `iri-reference`
 * or `idn-email`, so those keywords are genuinely NOT asserted and we say so
 * in the result rather than letting a reader assume full format coverage.
 * Anything else ajv wants to say still reaches stderr. */
const unassertedFormats = new Set();
const logger = {
  log:   () => {},
  warn:  (...a) => {
    const m = a.join(' ');
    const hit = /unknown format "([^"]+)"/.exec(m);
    if (hit) { unassertedFormats.add(hit[1]); return; }
    process.stderr.write(m + '\n');
  },
  error: (...a) => process.stderr.write(a.join(' ') + '\n'),
};

const ajv = new Ajv({ strict: false, allErrors: true, allowUnionTypes: true, logger });
addFormats(ajv);

/* bom-1.6 $refs the other two by RELATIVE FILENAME, so register them under
 * that key as well as their canonical $id. */
ajv.addSchema(spdxSchema, 'spdx.schema.json');
ajv.addSchema(jsfSchema,  'jsf-0.82.schema.json');

let validate;
try {
  validate = ajv.compile(bomSchema);
} catch (e) {
  out({ status: 'unavailable', error: `schema failed to compile: ${e.message}` }, 2);
}

if (validate(data)) {
  out({ status: 'valid',
        specVersion: data.specVersion ?? null,
        components: Array.isArray(data.components) ? data.components.length : 0,
        formatsNotAsserted: [...unassertedFormats].sort() }, 0);
}

out({ status: 'invalid',
      specVersion: data.specVersion ?? null,
      formatsNotAsserted: [...unassertedFormats].sort(),
      errorCount: validate.errors.length,
      errors: validate.errors.map(e => ({
        instancePath: e.instancePath,
        schemaPath:   e.schemaPath,
        keyword:      e.keyword,
        message:      e.message,
        /* `enum` failures against spdx.schema.json carry all 811 allowed
         * identifiers. Printing them buries the one line that matters. */
        params: Object.fromEntries(Object.entries(e.params || {}).map(([k, v]) =>
          [k, Array.isArray(v) && v.length > 8
              ? `[${v.length} allowed values — see tools/schema/cyclonedx/spdx.schema.json]`
              : v])),
      })) }, 1);
