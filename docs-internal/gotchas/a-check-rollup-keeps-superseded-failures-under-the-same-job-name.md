# A PR's check rollup keeps SUPERSEDED failures under the same job name

**Namespace:** `[build/*]`
**Found:** s98 (27.08.2026), re-running two PRs after the Actions quota block lifted.

## The trap

`gh pr view --json statusCheckRollup` returns **every** check that has ever reported on the PR,
including runs that a later run superseded. Summarise it naively and a fully green PR reads as
badly broken:

```
PR 582: MERGEABLE/CLEAN
  не-успешные: Read version, JS Tests, Secret scan, Assets build parity,
               WP 6.4 / …, WP 6.6 / …, WP latest / …, Label PR
```

`mergeStateStatus` says **CLEAN** and the list says eight failures — because GitHub's merge
decision takes the **latest result per check name**, while the array keeps the history. Every one
of those names also appears as `SUCCESS`, from a newer run id.

Sorting by run id makes it obvious:

```
FAILURE  JS Tests   33035803239/...     <- the quota-blocked run
SUCCESS  JS Tests   33050397333/...     <- the re-run
```

## Why it matters here

This project's merge rule is *"every job green individually, state CLEAN"* precisely because
`main` has no required-check gate. An agent that trusts its own naive summary refuses to merge a
green PR; one that trusts `CLEAN` alone skips the per-job count the rule asks for. Both are wrong
in the same place.

## ✅ Correct — filter to the CURRENT runs, then count

```bash
gh pr view <n> --json statusCheckRollup --jq \
  '[.statusCheckRollup[] | select((.detailsUrl // "") | test("/runs/<prefix>"))]
   | group_by(.conclusion) | map("\(.[0].conclusion): \(length)") | join("  |  ")'
# -> SKIPPED: 1  |  SUCCESS: 18
```

Get the current run ids from `gh run list` first; anything older is history.

## While you are here: do not `awk $2` over `gh pr checks`

Job names contain spaces (`Assets build parity`, `WP 6.4 / WC 8.5.1 / PHP 8.1`), so
whitespace-splitting yields `build`, `Compat`, `6.4` as if they were statuses. The output is
TAB-separated — use `awk -F'\t'`, or the JSON. This silently broke an until-loop into exiting
immediately, twice.

## Related

- [a-pull-request-workflow-can-simply-not-fire](a-pull-request-workflow-can-simply-not-fire.md) — the other "count the jobs, do not read the colour"
- [every-ci-job-failing-in-two-seconds-is-a-billing-block](every-ci-job-failing-in-two-seconds-is-a-billing-block.md) — what produced the superseded failures in the first place
