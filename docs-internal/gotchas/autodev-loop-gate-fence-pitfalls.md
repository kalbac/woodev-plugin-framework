# gotcha: autodev-loop gate/fence design pitfalls (per-value guards, fingerprint fence)

**Namespace:** `[tooling/autodev]`
**Discovered:** s33 (2026-06-26, loop hardening vs "Loop Engineering" review)

Four non-obvious correctness traps in `tools/autodev/` found while hardening the loop. Each
was a real auto-commit / safety hole, not a style nit; all are now fixed + self-tested.

## 1. Guard coverage must be per contract VALUE, not per zone

A contract ZONE in `INVARIANTS.md` (e.g. `option_keys`) bundles MANY enumerated `exact_strings`.
The gate used to match a guard to a zone by `recipe.zone_id == zone.id` and treat that ONE guard
as covering the WHOLE zone. Result: a worker renaming an UNGUARDED key in the same zone (e.g.
`wc_edostavka_webhook_ids`) auto-committed, because a guard on a DIFFERENT key
(`woocommerce_edostavka_settings`) "covered" the zone and its mutation-check re-verified the
untouched key. **Fix:** require a guard whose `recipe.canonical_value` == EACH touched
`exact_string` (`Select-AutodevGuardForValue`); zone-level match (`Select-AutodevGuardForZone`)
survives only as the fallback for path_glob/grep touches with NO enumerated value in the diff.

## 2. Dirty-file fence needs a content FINGERPRINT baseline, not a path-only baseline

A path-only "new dirt = changed - pre_existing_paths" baseline has BOTH failure modes:
- **false positive** (no baseline): an escalated/parked task leaves uncommitted out-of-file_set
  files; the next task then escalates "dirty" on files it never touched → loop stalls.
- **false negative** (path-only baseline): a worker can FURTHER EDIT a pre-existing-dirty
  out-of-file_set file undetected, because its path is already in the baseline set.

**Fix:** baseline is `rawpath -> sha256(content)` (`Get-AutodevFileFingerprints`); a file counts as
worker-touched iff it is NEW or its fingerprint CHANGED (`Get-AutodevWorkerTouchedFiles`).

## 3. `ConvertTo-NormalizedPath` strips a LEADING DOT

`($p -replace '\\','/').TrimStart('./')` greedily strips leading `.`/`/`, so `.autodev/GUARDS.md`
normalizes to `autodev/GUARDS.md`. Harmless for set comparisons (both sides normalize the same),
but you CANNOT `Join-Path $RepoRoot` a normalized `.autodev/...` path and read the file. When you
need to read working-tree content (the fingerprint fence), use RAW git paths
(`Get-GitChangedFilesRaw`), not the normalized list. Also: the dirty-fence ignore list must be
SCRATCH-ONLY (`.autodev/runtime|queue|escalations|conductor.log|digest.md`) and must NOT include
the constitution files (`.autodev/GOAL.md|INVARIANTS.md|GUARDS.md`), or a worker editing the
constitution outside its file_set goes unseen. Prefix matching must be boundary-safe: a
file-shaped ignore (`conductor.log`) must match exactly, not `StartsWith` (else `conductor.log.bak`
is wrongly ignored).

## 4. Contract-risk gating must read the ACTUAL diff, including deletions

Bounded worker<->critic retry must never auto-retry a contract-risk diff. Gating on the task's
`touches_contract_zone` FRONTMATTER alone is unsafe (a mislabeled task slips through). Compute the
risk from the real diff (`Get-AutodevTouchedZoneIds`) OR'd with the declared flag OR the critic's
`broken_contracts`. When parsing changed files from a diff, read BOTH `+++ b/...` AND `--- a/...`
(skip `/dev/null`) — a DELETED file in a `path_globs`-only zone (licensing, shipping-method, rest,
gateway, cron) is invisible if you only parse the `+++ b/` side.

## How these were found
A 3-round adversarial loop: Claude gap-analysis + an independent Codex (GPT-5.5) pass produced the
10-item backlog; after implementing, Codex re-critic'd the fixes TWICE (no self-certify) and caught
#2's false-negative, #4's deletion hole, and the boundary-prefix bug — none visible to the
self-tests as first written. Re-critic on your own fixes earns its keep.

## Related
- [[codex-shell-sandbox-broken-windows]] — run the Codex critic via inline-bundle on this box.
- `tools/autodev/gate.ps1` (`-SelfTest`), `tools/autodev/conductor.ps1` (`-SelfTest`) — the guards.
- `docs-internal/autodev-loop-runbook.md` — the loop design.
