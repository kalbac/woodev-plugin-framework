# ADR-006: Capability-Gated Feature Seam as the reference pattern for optional behaviour

**Status:** accepted

**Date:** 2026-06-09

## Context

The framework is a scaffold that plugins extend on contracts (`PLANS.md` §1). Across the
codebase, optional behaviour needs a consistent way to be wired so that: it cannot be
forgotten by a plugin author, it costs nothing when unused, and the framework never makes a
domain decision it is not entitled to make.

The `payment-gateway` module already solves this uniformly and is explicitly held as the
architectural reference for the rest of the framework — `PLANS.md` §3.2 names it the target
architecture for the shipping module. The s3 work (weaving box-packing into
`Shipping_Method::calculate_rate()`) reproduced the same shape in `shipping-method` and made
the pattern concrete enough to name. We want future feature work (shipping, licensing, and
new platform bases) to reach for this shape **by default when it fits**, rather than
re-deriving an ad-hoc wiring each time.

The pattern: **optional feature logic placed at a guaranteed invocation point, gated by an
explicit capability, delegating specifics to a named seam, inert when the capability is not
declared.** Full write-up with examples:
[wiki/capability-gated-feature-seam.md](../wiki/capability-gated-feature-seam.md).

## Decision

Adopt the **Capability-Gated Feature Seam** as the framework's **reference pattern** for
optional/extensible behaviour. It is a strong default, **not** a blanket mandate: where it
applies and is justified, build optional behaviour this way; where it does not fit (see
boundaries), do not force it.

A construct follows the pattern when all five properties hold:

1. **Guaranteed invocation point** — the logic lives in a method the flow always calls.
2. **Opt-in via a capability flag** — runs only under `supports( self::FEATURE_* )`, ideally
   through a named predicate wrapper (`supports_tokenization()`).
3. **Base owns orchestration; subclass owns specifics** — the guaranteed method is a thin
   dispatcher delegating to a seam (abstract method, empty-stub hook, handler object, or
   injected API interface).
4. **Inert-by-default** — not declaring the capability is the zero-cost identity path.
5. **Structurally protected where bypass would be a bug** — make the guaranteed method
   `final` (e.g. `Shipping_Method::calculate_rate()`).

Supporting conventions (normative for new code that adopts the pattern):

- Wrap a capability checked in more than one place in a named `supports_*()` predicate.
- Keep the guaranteed method a thin orchestrator; each feature's body goes in its own
  protected method.
- Declare the capability at the scope it governs (per-method/gateway vs per-plugin).
- The base must never encode a domain decision it cannot make correctly (e.g. the framework
  packs parcels but never sums per-parcel carrier prices — that is the carrier's tariff).

## Consequences

**Easier:**
- A plugin author cannot forget to invoke an optional behaviour — declaring the capability
  is the entire opt-in, and the wiring is guaranteed.
- Unused capabilities cost nothing; enabling/disabling is additive and reversible.
- The `supports()` vocabulary makes the capability surface discoverable and uniform across
  modules; reviewers and future agents recognise the shape immediately.
- Shipping (and future EDD/platform bases) have a concrete, in-repo target to copy rather
  than a prose ideal.

**Harder / watch-outs:**
- A guaranteed method can accrete feature branches over time; the thin-orchestrator
  convention is load-bearing — without it the method drifts toward a god-method (the known
  `class-payment-gateway.php` size, ~2378 lines, is tolerable precisely because each branch
  delegates).
- The pattern is a default, not a law: forcing it onto standalone subsystems with their own
  lifecycle (REST routes, cron, webhooks) is worse than a dedicated class + registration.
  Judgement is required; the wiki lists the "when NOT to use it" boundaries.
- `final` on the guaranteed method removes a subclass escape hatch by design; provide the
  intended extension surface (a filter/seam) alongside it, as `calculate_rate` does with the
  `woodev_shipping_method_pre_calculate_rate` filter.

## Related

- [wiki/capability-gated-feature-seam.md](../wiki/capability-gated-feature-seam.md) — the pattern, examples, conventions, boundaries
- [ADR-005: Platform v2 Clean-Break Policy](005-platform-v2-clean-break-policy.md) — internal APIs (incl. these seams) are free to break on v2
- `docs-internal/platform-v2-s3-shipping-rate-packing-spec.md` — the s3 shipping instance that made the pattern concrete
- [[shipping-rate-no-parcel-sum]] — the "base owns orchestration, not domain decisions" invariant in gotcha form
