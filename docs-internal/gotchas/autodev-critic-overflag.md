# Autodev critic over-flags two non-breaks on every incremental task

**Namespace:** `[autodev/critic]`
**Discovered:** 2026-06-06 (autodev loop — P3/P4 batch: tracking, webhook escalated as `broken` though clean)

## The pattern

When the GPT-5.5 critic finally ran on the additive P3/P4 scaffolding tasks, it returned
`verdict: broken` (conf 0.94–0.98) on tasks that have ZERO installed-site contract risk, because
it treats two things as breaks that the project's incremental-build model does not:

1. **A NEW additive forward hook name** (e.g. `woodev_shipping_{prefix}_tracking_admin_display`)
   flagged as "unguarded hook contract". But guards exist to PRESERVE *existing* installed-site
   strings; a brand-new hook name breaks nothing and needs no guard — only a human one-glance
   (exactly how `checkout-handler` was resolved). INVARIANTS `hooks.exact_strings` is `[]`.
2. **"Class not wired into `Shipping_Plugin::includes()`"** flagged as a load-order break. But
   wiring is a deliberately separate task (`s1-p6-plugin-wiring`); every prior S1 class landed
   unwired and was wired at the end. An unloaded additive class breaks nothing (nothing references
   it yet).

These two inflate `broken` verdicts on clean tasks → false escalations → the loop stalls on
operator decisions that are all "commit anyway".

## What the critic gets RIGHT (do not touch)

In the SAME batch the critic correctly caught real bugs with the same confidence: contract-string
derivation (ajax action / admin slug — see [[contract-string-not-derivable]]), a POST-shape
mismatch vs shipped JS, and a background-job payload misuse. Those findings are exactly why the
adversarial critic exists. Any recalibration must be SURGICAL: stop scoring (1) and (2) as
`broken`; keep everything else.

## Recommended fix (operator decision — NOT auto-applied)

Weakening an adversarial safety check is itself risky, so this was left as a recommendation rather
than an autonomous change. Narrow options:
- Teach the critic (prompt) the project's incremental model: a new additive hook/filter name is
  `clean`, not `broken`; "not yet wired into includes()" is expected (wiring is a tracked separate
  task), not a break. Keep the rename/remove/derive-existing-string detections intact.
- OR feed the critic the list of planned wiring tasks so "unwired" is contextualized.

Until then: triage these `broken`-on-additive escalations by reading the diff — if the only
findings are (1) and (2) and there is zero installed-site string, override + commit.

## Related
- [[contract-string-not-derivable]] — the critic's CORRECT findings in the same batch
- [[autodev-attempt-refund-symmetry]] — the earlier (infra) critic-vs-conductor issue
- `.autodev/escalations/_outbox.md` — the 2026-06-06 batch resolutions (tracking/webhook = override)
