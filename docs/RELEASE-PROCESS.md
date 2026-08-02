# Release process, and how outside contributions are accepted

**Audience:** maintainers cutting a release, and contributors who want to know
what happens to a pull request after it is merged.

If you only read one thing, read
[Accepting an outside contribution](#accepting-an-outside-contribution).
Merging a pull request here is **half** of accepting it.

---

## The two-repository model

TicketsCAD is developed in a **private** repository and published to a
**separate public** repository, `openises/TicketsCAD`.

The public repository is not a mirror of the private one. Each release is a
**scrubbed, one-way snapshot**: `tools/release-snapshot.sh` exports the private
tree, removes development-only material (planning notes, internal operations
docs, beta-tester material), rewrites internal hostnames and names, verifies
that no secret or personal information survived, checks the SBOM against the
tree being published — and then the maintainer replaces the public repository's
working tree with it and commits.

That model exists for a reason. A public repository exposes its entire git
history and every ref, so publishing a clean *current* tree from a repository
with a long private history would publish the private history along with it.
Replacing the tree wholesale, release by release, keeps the private history
private.

## The consequence: a full-tree replace can revert you

Because publishing **replaces** the tree, a change that exists **only** in the
public repository does not survive the next release:

* a **merged pull request** — the file is overwritten with the private tree's
  version of it, or deleted outright if the private tree has no such file;
* a **Dependabot or security fix** merged on the public side — same;
* a **hand edit**: a typo fix, a corrected link, an updated badge — same.

Nothing fails when this happens. Git raises no objection: from the public
repository's point of view the maintainer simply committed a tree in which that
change is absent. There is no conflict, no warning, and no diff that says
"reverted". The change appears merged, sits for days, and then quietly stops
existing.

That is a bad way to treat a contributor, and a dangerous way to treat a
security fix.

## The guard

`tools/release-snapshot.sh` will not let a release proceed if it would do that.
Before the tree is publishable it runs `tools/release-divergence-check.php`,
which compares three things, per file:

| | |
|---|---|
| **snapshot** | the staged tree about to be published |
| **public** | the public repository's current `main` |
| **baseline** | the tree the **last release** published |

The baseline is the crux. "Public differs from the snapshot" says nothing on its
own — that is the shape of every release. What separates a normal update from a
silent revert is whether the public content is still what *we* last put there:

* **public == baseline** — the difference is entirely ours. Normal update.
* **public != baseline**, and the snapshot does not carry that change — the
  public content changed independently of us. Publishing would discard it.
  **The release stops.**

The baseline is resolved from the public repository's own release tags: every
release is tagged `vX.Y.Z` at the commit that replaced the tree, so the tree at
the newest reachable `v*` tag is, byte for byte, what was last published.

**Tagging a release is therefore part of publishing it, not a flourish.** An
untagged release leaves the next one with no baseline — and the check then
*refuses* rather than guessing, which is the correct failure but an avoidable
one.

When the check finds something, it prints each affected path, whether the file
would be overwritten or deleted, which public commits touched it, and an excerpt
of the content that would be lost.

### Running it on its own

It does not need a staged tree of its own to be useful, and it never writes to
the public repository — it only fetches:

```
php tools/release-divergence-check.php --snapshot=<staged-tree>
php tools/release-divergence-check.php --snapshot=<staged-tree> --json
```

Useful options: `--public-repo=<dir>` to compare against an existing clone,
`--baseline=<ref>` to name the last-published commit yourself, `--no-fetch` to
stay offline (it then compares against whatever your clone last saw — which is
exactly how a change pushed since your last fetch goes unnoticed, so do not use
it while cutting a release).

### Declared exceptions

A few differences are legitimate. They are declared in
`tools/release-public-exceptions.txt`, one record per line, each with a
**required** reason — an exception nobody had to write down is indistinguishable
from a bug.

* `authoritative` — the **public repository owns this file**; the publish step
  preserves the public version. The one standing case is **`CHANGELOG.md`**: the
  published changelog accumulates in the public repository and is rewritten at
  every release, while the private tree carries only a process-note stub.
  Copying the stub over it would erase the entire release history, so the
  publish step restores the public file (`git checkout HEAD -- CHANGELOG.md`)
  and prepends the new section.
* `ported` — a public-only change that **has already been applied** to the
  private repository. The record pins the exact public blob hash it was taken
  from, so if that file is edited again in public the record stops matching and
  the finding comes back. Remove the record once the next release has published
  the ported version.

### The override

If discarding a public-only change is genuinely intended, the release can be
forced — loudly, never by default, and never silently:

```
bash tools/release-snapshot.sh --allow-revert=<N>
```

`N` must equal the number of findings exactly. A plain boolean flag would stay
"approved" while the set of discarded changes grew underneath it; requiring the
count means you have read the report, and a finding that appears afterwards
breaks the approval again. The run still prints every path it is discarding.

---

## Accepting an outside contribution

**Merging a pull request on the public repository is only half of accepting it.**
Unless the change is also applied to the private development repository, the
next release will revert it — and the divergence check will (correctly) refuse
to cut that release until someone deals with it.

The full sequence:

1. **Review and merge** the pull request on the public repository as normal.
2. **Port the change into the private repository**, preserving the
   contributor's authorship. Either cherry-pick/`git am` the public commit, or
   apply the change and set the author explicitly:

   ```
   git commit -s --author="Contributor Name <contributor@example.com>" \
     -m "fix: <what they fixed>"
   ```

   `--author=` is not a nicety. It is the only thing that keeps their name on
   the work once the public commit is superseded by the next snapshot. Keep
   `-s` as well: the sign-off is the committer's Developer Certificate of Origin
   attestation, and it is separate from authorship.

   Adapt the change if the private tree has moved on — but adapt it, do not drop
   it. If the change no longer applies at all, say so on the pull request rather
   than letting it evaporate at release time.
3. **Re-run the release**. Once the private tree carries the change, the
   snapshot carries it too, and the check passes with no exception needed.
4. **If step 3 still reports the file** — because the port was adapted and no
   longer matches the public bytes — add a `ported` record pinning the public
   blob hash, with the porting commit in the reason:

   ```
   git -C <public-clone> rev-parse main:<path>
   ```

   Remove the record after that release publishes.

### For contributors

Please open pull requests against the public repository as usual; that is the
right place and it is where review happens. Two things are worth knowing:

* Your change is re-applied upstream by hand, so there can be a lag between
  "merged" and "in a release", and the released version of your change may be
  lightly adapted to the current upstream tree.
* Your authorship travels with it. If a release ever lands your change without
  your name on it, that is a mistake — please say so.

---

## Publish checklist

1. `bash tools/release-snapshot.sh` — builds the staged tree, scrubs it, scans
   for secrets and personal information, verifies the SBOM against the tree
   being published, and runs the divergence check. It does **not** push.
2. In a clean, up-to-date clone of the public repository, on `main`:
   ```
   git rm -rq .
   cp -a <staged-tree>/. .
   git checkout HEAD -- CHANGELOG.md
   ```
3. Prepend the new release section to `CHANGELOG.md`.
4. `git add -A && git commit -s -m "TicketsCAD vX.Y.Z — <headline>"`
5. **Tag it** — `git tag -a vX.Y.Z -m "..."` — then
   `git push origin main --follow-tags`.

Step 5 is what gives the *next* release a baseline. Skipping it does not break
this release; it breaks the check that protects the one after it.
