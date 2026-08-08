# A feature built on both sides, with nothing calling it in the middle

**Namespace:** `[framework/wiring]` · **Discovered:** s56 (2026-08-08), issue #219

## What happened

The viewport strategy's lazy detail fetch existed end to end — except for the one line that would
have made it run:

| Link | State |
|---|---|
| REST route `/points/(?P<id>…)` | built |
| `Point_Source::fetch_details()` | built, implemented in both fixtures |
| Server-side verdict recomputation over the full record | built |
| `dataSource.fetchDetails()` | built, and **unit-tested** (nonce header, 404 mapping, no debounce) |
| A production caller | **none, anywhere** |

```bash
$ grep -rn "fetchDetails" woodev/ --include=*.js
# only the definition, plus a mention in another file's docblock
```

The consequence was not cosmetic: a viewport carrier may omit `accepts_cod`/`max_weight` from its
bbox listing, so every point stayed selectable and the refusal only surfaced at order submission.

## Why nothing caught it

- **The piece had its own passing tests.** `pickup-datasource.test.js` proves `fetchDetails()`
  sends the nonce, maps a 404 and is never debounced. All true, all irrelevant to whether anything
  calls it. **Tests of a unit never test its wiring.**
- **The other strategy does not need it.** `bulk` gets the full record in the listing, so the only
  code path that would have exercised this had no reason to.
- **A migration moved a responsibility and dropped half of it.** The map provider used to pull
  points through an injected dataSource; a later task moved fetching to the mount.
  `fetchPoints` was rewired, `fetchDetails` was not.
- **The evidence was sitting in a test file's own docblock** — *"`fetchDetails()` is unused by
  this file and stubbed trivially"* — written as a note about the stub, read by nobody as a
  finding.

## The tell, and how to look for it

The signature is a **fossil docblock**: prose describing an arrangement the code no longer has.
Here, `map-provider-yandex.js` still opened with

> THIS FILE NO LONGER FETCHES ANYTHING ITSELF. The old version pulled points through an injected
> `dataSource` (`fetchPoints`/`fetchDetails`) and decided when to call it. That is now the
> caller's job.

Two names go out, one name comes back. That sentence was the bug report, in the repository, the
whole time.

**When a responsibility moves between files, enumerate what moved and grep each name for a
PRODUCTION caller** — not a test, not a docblock, not an export:

```bash
grep -rn "<name>" <source-dirs> --include=*.js | grep -v tests/
```

**And treat "a strategy/branch nobody exercises end to end" as a place this is likely already
true.** This one survived from the migration until the first rig run of that strategy, several
sessions later.

## Related

- [[dispatcher-files-unwired-in-includes]] — the PHP shape of the same thing: a class that exists,
  is loaded by the test autoloader, and fatals in production because nothing requires it.
- [[mutation-sweep-branch-only-false-confidence]] — another "green run proves less than it looks".
