# Fable 5 — architecture & direction review prompt (token-economical)

> Written 2026-06-10 (s5). Purpose: a fresh-eyes, frontier-level architecture/direction review
> of the v2 foundation, sized to run as a **single Fable 5 agent at `high` effort** within the
> "Fable 5 free until Jun 22" window — without the token blow-up of ultracode/multi-agent fan-out.
>
> **How to run:** Claude Code Desktop → `/model` → Claude Fable 5 → set effort **high** (NOT
> ultracode, NOT max) → one fresh session → paste the prompt below.
>
> **Why architecture-only, not a full code sweep (decided with operator s5):** the framework is
> mid-rewrite (S3 in progress; S4/S5/S6 ahead). A line-by-line sweep now = low signal (half the
> findings are "already on the roadmap" or "legacy we're deleting") + findings go stale + token
> waste at Fable's 2× price ($10/$50 per MTok vs Opus 4.8 $5/$25). The durable, high-leverage use
> of a frontier model now is the **foundation + direction**, which is stable (S0 done/tagged).
> The exhaustive per-file sweep is deferred to the "v2.0 feature-complete" milestone (Fable via
> usage-credits then, or an Opus 4.8 fan-out).

---

## PROMPT (paste into a single Fable 5 session)

```text
You are a frontier-level software architect doing a FRESH-EYES architecture & direction
review of the Woodev Plugin Framework (PHP 7.4–8.x WooCommerce/WordPress plugin framework,
mid-rewrite to "platform v2"). Working dir: D:\Projects\woodev_framework.

Your job is ASSURANCE: tell the team whether the v2 foundation and direction are sound,
where the real architectural risks are, and what they're NOT seeing. I value disagreement
over validation — challenge the decisions; do not rubber-stamp them.

== HARD CONSTRAINTS (respect strictly) ==
- READ-ONLY. Do not edit code, write files, run composer/tests, or make commits. Output is
  a single report in your final message.
- SINGLE agent. Do NOT spawn subagents / parallel fan-out. Work sequentially yourself.
- Token-disciplined reading: read the DOCS first (cheap, high-signal), build a hypothesis of
  the architecture, THEN open only the specific code you need to confirm or refute a concern.
  Use Serena (get_symbols_overview / find_symbol / find_referencing_symbols / search_for_pattern)
  for targeted reads — do NOT bulk-read whole files when an overview + a symbol body suffices.
  Do not read the entire woodev/ tree line by line; this is an architecture review, not a sweep.

== CONTEXT (so you don't waste effort) ==
The framework is HALF-MIGRATED. Treat these as out of scope — do NOT report them:
- Legacy code slated for deletion under the clean-break policy (ADR-005). Internal-API back-compat
  is intentionally being broken; do not flag "missing deprecation shims".
- Anything already on the roadmap (PLANS.md) or known-debt list (docs-internal/FUTURE-BACKLOG.md,
  docs-internal/GOTCHAS.md). Read these so you can DEDUPE against them, not re-report them.
- Pure style nits, naming, formatting, micro-optimizations. Not the point.
The INSTALLED-SITE DATA CONTRACTS are release-blocking and must be preserved byte-for-byte
(option keys, license/instance IDs, gateway/shipping method IDs, hook names, cron, REST namespaces,
AJAX actions, admin slugs, meta keys). Judge the architecture against THAT invariant.

== READING MAP (start here, in order) ==
Direction & rules:
  PLANS.md ; CLAUDE.md ; AGENTS.md
  docs-internal/CURRENT-STATE.md ; docs-internal/platform-v2-program-tracker.md
  docs-internal/platform-v2-execution-protocol.md ; docs-internal/platform-v2-cleanbreak-plan.md
  docs-internal/platform-v2-base-decomposition-subplan.md
Decisions:
  docs-internal/adr/README.md and docs-internal/adr/001..006
Dedupe sources (known debt/gotchas — do not re-report):
  docs-internal/FUTURE-BACKLOG.md ; docs-internal/GOTCHAS.md
Newest design (high-stakes, security-critical):
  docs-internal/platform-v2-s3-licensing-need-license-spec.md (+ the -plan.md)
Core code (read overviews first; bodies only to verify a concern):
  woodev/bootstrap.php ; woodev/class-framework-resolver.php ; woodev/class-plugin.php ;
  woodev/class-woocommerce-plugin.php ; woodev/licensing/ ; woodev/shipping-method/ ;
  woodev/payment-gateway/ (overview only — 2378-line main file is known debt) ; woodev/box-packer/

== WHAT TO EVALUATE ==
1. Foundation soundness: bootstrap + multi-version resolver + the Woodev_Plugin →
   Woocommerce_Plugin / (future) EDD_Plugin split. Are the abstraction boundaries right?
   Is the base genuinely platform-neutral, or does WooCommerce/EDD leak into it?
2. Direction & sequencing: is the v2 plan (S0..S6) staged in the right order? Any decision in the
   ADRs that looks locally reasonable but creates a foundational trap downstream (esp. for the
   future EDD plugin type and the React admin)?
3. Licensing security (the new two-layer is_need_license + Ed25519 design): is the threat model
   sound? Can the anti-pirate invariant be defeated? Any flaw in the signed-claim scheme, the
   canonical-JSON contract, replay/expiry, or the client/server split?
4. Contract integrity: any place the v2 architecture risks silently breaking an installed-site
   data contract on real plugin migration?
5. Blind spots: what is the team NOT seeing? The single biggest risk to v2.0 shipping correctly.

== OUTPUT (bounded — keep it tight) ==
A. Executive read (≤200 words): is the direction sound? top 3 risks.
B. Findings, ordered by impact. For EACH: short title | severity (Critical/High/Medium) |
   area | evidence (file:line or doc section) | why it matters | concrete recommendation.
   Deduplicate aggressively; merge related findings. Aim for the ~10–15 findings that matter,
   not an exhaustive list. Skip anything in the "do not report" set above.
C. What's done RIGHT (≤5 bullets) — calibration, so the team knows what to preserve.
D. If you ran low on budget, say what you did NOT get to. No silent truncation.

Do not start coding anything. Produce the report only.
```

## After the run
- Save Fable 5's report to `docs-internal/reviews/fable5-architecture-review-<date>.md`.
- Triage findings with the operator; real ones become autodev atomic tasks (worker + adversarial
  critic), same flow as S-stages. Do NOT auto-apply Fable's recommendations.
- Defer the exhaustive per-file sweep to the v2.0-complete milestone.

## Related
- [PLANS.md](../PLANS.md) · [platform-v2-program-tracker.md](platform-v2-program-tracker.md)
- [platform-v2-s3-licensing-need-license-spec.md](platform-v2-s3-licensing-need-license-spec.md)
