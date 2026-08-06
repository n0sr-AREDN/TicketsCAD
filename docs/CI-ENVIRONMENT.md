# CI environment contract

What `.github/workflows/qa.yml`'s `fresh-install-and-test` job actually gives you —
read this before writing a test or a build script an agent hasn't run on CI before.
Every fact below is drawn directly from that workflow file; if it and this doc ever
disagree, the workflow file wins and this doc is stale.

## What exists

- A **genuinely fresh MariaDB 10.11** container — empty except for `base_schema.sql`
  + every `sql/run_*.php` migration, applied once by `tools/install_fresh.php`, then
  `tools/create_admin.php` + `sql/seed_demo_data.php`. No manual admin-UI action has
  ever touched this database. Anything a feature *lazily self-heals into existence at
  runtime* (see `docs/SCHEMA-REFERENCE.md`'s "Known gotchas" — `member_comm_
  identifiers.sort_order` is the confirmed case) will NOT be present, because nothing
  on this install path ever triggers that code.
- PHP **8.2**, with `pdo_mysql, mysqli, mbstring, openssl, curl, zip, gd`.
- `composer` (from `shivammathur/setup-php`) — used ONLY for `composer audit --locked`.

## What does NOT exist

- **`vendor/` is never created.** The workflow's own comment on the audit step says
  it explicitly: "this job never runs `composer install`, so `vendor/` does not exist
  here." Any code path that does `is_file(__DIR__ . '/../vendor/autoload.php')` and
  falls back to `error_log()` when it's missing (see `inc/push.php`) WILL take that
  fallback path on every single CI run. `error_log()` under PHP's CLI SAPI writes to
  **stderr by default** — a subprocess test helper that merges stdout+stderr (a bare
  `shell_exec($cmd . ' 2>&1')`) and expects pure JSON back WILL have that line
  corrupt the parse, with zero local reproduction if your own working tree happens to
  have `vendor/` installed from earlier work. This bit Phase 134 Step 4's poller test
  exactly this way. **Use `proc_open()` with separate stdout/stderr pipe descriptors
  for any subprocess probe** (`tests/test_telegram_channel_security.php`'s
  child-process harness is the reference pattern) — never merge the streams and parse
  the result as a single blob.
- **No web server.** `NEWUI_TEST_NO_HTTP=1` is set for the full-suite step; every test
  file tagged `@requires-http` in its docblock is skipped, not run. If a test needs
  live HTTP, it either self-skips correctly or it will never actually execute on CI —
  a false sense of coverage if you don't check for the `@requires-http` tag.
- **No prior manual admin action of any kind.** Nothing has ever been clicked, saved,
  or toggled through the UI. Any column, setting row, or cached value that a feature
  creates lazily on first *use* (as opposed to on migration) will read as absent.

## The sequence, in order

1. `config.php` from `config.example.php` (DB host rewritten to `127.0.0.1`, password
   to the CI service container's password).
2. `php tools/install_fresh.php` — base schema + all migrations, once.
3. `php sql/run_migrations.php` a second time — must report `Pending: 0` (idempotency
   proof; a script whose hash changes between runs without being genuinely new content
   fails this softly, as a warning, not a hard failure).
4. `tools/create_admin.php` + `sql/seed_demo_data.php`.
5. Five static/schema audit gates (schema, API↔JS contract, legacy-level authorization
   split, timezone/clock-consistency, UI-consistency) — each is its own workflow step,
   each a `tests/test_*.php` or `tools/*.php` file you can run identically locally.
6. Three SBOM gates (current, schema-conformant, signature-verifies) —
   `php tools/generate-sbom.php --check|--validate|--verify`.
7. `composer audit --locked --no-interaction` — the ONLY thing `composer` is used for;
   confirms `vendor/` is genuinely never installed elsewhere in this job either.
8. The full test suite, `NEWUI_TEST_NO_HTTP=1 php tools/test_all.php`.
9. The Python audio-matrix suite (`services/audio-matrix/tests/`), no radio/bridge
   hardware involved.

## Before you write a test that spawns a subprocess or assumes a column exists

- Ask: would this exact statement succeed against an install that has ONLY ever run
  `sql/run_migrations.php` plus this phase's own migrations — never any admin UI
  action, never any other test file? If you're relying on something that isn't
  guaranteed by (a) the base schema, (b) a migration your own change ships, or (c)
  something your own code creates in the same run, that's a bug to fix now, not
  something to discover on the next push.
- If you spawn a subprocess and parse its output, use `proc_open()` with separate
  stdout/stderr pipes. Never `shell_exec($cmd . ' 2>&1')` into a strict parser.
- Check `docs/SCHEMA-REFERENCE.md`'s "Known gotchas" section before referencing a
  column you haven't used before — it exists specifically to save you the live query.

## Reproducing this locally (when you need real certainty, not just care)

There's no cheap way to spin up a byte-for-byte match of this container from a
working dev database with years of manual use behind it. When a fix depends on
"does this actually work on a fresh install," the reliable options are, in order of
preference: (1) reason carefully against this doc and `docs/SCHEMA-REFERENCE.md`
rather than guessing, (2) add a temporary diagnostic step to the workflow that runs
the one file in question directly (full untruncated output, not the summarized
suite's tail-only failure report — `tools/suite_contract.php`'s `test_all_tail()`
caps how much of a failing file's output ever reaches the CI log) and revert it once
you have what you need, (3) as a last resort, provision an actually-fresh MariaDB
instance and run the same install sequence above against it.
