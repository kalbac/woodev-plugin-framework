# Platform v2 Roadmap Reconciliation
> Status: reconciliation note (durable)
> Date: 2026-05-31
> Purpose: re-anchor execution on the original framework-first intent in `PLANS.md` and detect sequencing drift.

## 1. Why this note exists

This session did no implementation. It re-anchored the Platform v2 work on the
original intent in `PLANS.md`, compared that intent to what has actually been
built (verified in source, not only in doc claims), and locked the corrected
course so future sessions do not mistake "platform split done" for "framework
ready for real production-plugin migration".

## 2. Reconstructed true roadmap

### 2.1 Strategic intent (`PLANS.md`)

`PLANS.md` is a brainstorm, not a strict spec. Its strategic intent:

- Framework owns all infrastructure; plugins keep only domain logic.
- Platform-neutral base hierarchy: `Woodev_Plugin` (pure WordPress) →
  `Woodev_Woocommerce_Plugin` → future `Woodev_EDD_Plugin`.
- Move `Woodev_Lifecycle` and the `api/` module under `Woodev_Plugin` (no WC dependency).
- Modules: payment (works, just adapt to hierarchy); shipping (**stated top
  priority** — make it "ideal and universal"); api (→ base); licensing
  (`is_need_license`, modern UI, webhooks); box-packer (minimal virtual-box size).
- DI/SOLID where WordPress allows; reactive admin UI using WP/WC built-in React.
- Open questions: fate of `bootstrap.php`; how a plugin declares its type.
- Execution: separate branch; effectively a new framework; old plugins will be
  rewritten; **framework first**.

### 2.2 Accepted v2.0 sequencing (`platform-v2-implementation-spec.md`)

The brainstorm was deliberately narrowed into the accepted implementation spec.
This spec — not the raw brainstorm — is the active v2.0 sequencing source:

- Phase 0 — spec acceptance / branch reset.
- Phase 1 — minimal resolver facade behind `woodev/bootstrap.php`.
- Phase 2 — explicit loader definition + fixtures + minimal legacy adapter.
- Phase 3 — platform class split (`Woodev_Plugin` / `Woodev_Woocommerce_Plugin`).
- Phase 4 — early class availability + specialized bases.
- Phase 5 — platform-neutral module cleanup (remove/guard WC helpers in base modules).
- Phase 6 — production plugin migration contracts (contract before any rewrite).
- Phase 7 — future cleanup (adapter removal, versioned namespaces).
- Explicitly out of v2.0: shipping redesign, licensing webhooks/UI, box-packer
  algorithm, React admin UI, EDD runtime, payment trait extraction.

**True roadmap in one line:** framework platform split first (P1–P5) → migration
contracts (P6) → real plugin rewrites (P6B) → future cleanup (P7); the broad
`PLANS.md` feature vision is deferred to post-v2.0.

## 3. What is actually completed (verified in code)

| Area | Status | Evidence |
|------|--------|----------|
| Planning artifacts | Done | matrix, ADR-001..004, strategy alignment, next analysis, implementation spec |
| P1–P2 resolver + loader | Done | `woodev/class-framework-resolver.php`, `woodev/class-framework-plugin-loader-definition.php`, `FrameworkResolverTest`, `BootstrapRegistrationTest` |
| P3 platform split | Done | `woodev/class-woocommerce-plugin.php` (`Woocommerce_Plugin extends Woodev_Plugin`) + `class-woocommerce-plugin-alias.php` |
| P4 early classes + specialized bases | Done | payment/shipping bases `extends \Woodev\Framework\Woocommerce_Plugin`; callback-timing test in `FrameworkResolverTest` |
| P5 platform-neutral cleanup | Done | 14 cleanups + post-review; base modules guard WC helpers via `function_exists()` fallbacks (e.g. `licensing/class-license-messages.php:75,100`) |
| Resolver test matrix | Mostly done | pure-WP-without-WC load, WC gating, invalid loader, EDD rejection, PHP-requirement skip, legacy-adapter mapping, multi-version sort (`BootstrapRegistrationTest::test_version_sorting_highest_first`) |
| `composer check` | Passing | 164 tests / 322 assertions (last recorded run) |
| P6 entry | Done (docs) | `platform-v2-migration-contract-template.md` |
| P6A reference validation | Done (docs only) | gap analysis + Edostavka draft + Yandex draft, all read-only, non-production |
| P6B real production migration | **Not started** | correctly blocked; no original repo touched |

The framework v2.0 **narrow platform-split scope is essentially complete and
genuinely tested** — not just claimed in docs.

## 4. Was there sequencing drift?

**Two-level answer.**

- **Against the accepted implementation-spec roadmap: no boundary-violating drift.**
  Framework was built first; sandbox plugins were used read-only; no production
  repo was touched; resolver/bootstrap scope was not expanded; the team explicitly
  refused to start Phase 6B. Discipline held.

- **A mild soft drift in emphasis did occur.** Phase 6A produced **two** reference
  draft contracts plus a gap analysis — more migration-contract paperwork than the
  methodology validation required. More importantly, **all Phase 6A output is paper**:
  it validated the *contract template*, but it never validated the **new framework
  runtime architecture against a realistic plugin shape**.

- **Concrete evidence of the validation gap:** both sandbox copies
  (`plugins-reference/woocommerce-edostavka`, `.../woocommerce-yandex-delivery`)
  still consume the **old** framework — legacy positional `register_plugin()` with
  `load_shipping_method`/`minimum_wc_version` flags, and `class ... extends Woodev_Plugin`
  directly (not `Woodev_Woocommerce_Plugin`). The new resolver + explicit loader +
  `Woocommerce_Plugin` inheritance path has only ever been exercised by **synthetic
  inline unit fixtures**, never by a realistic plugin shape.

- **Tension with `PLANS.md` priority:** the migration rehearsal targeted shipping
  plugins, while the shipping module — `PLANS.md`'s stated top priority — is
  deliberately deferred out of v2.0. This is a documented scope decision, not an
  accident, but it means migration-contract rehearsal is running ahead of the
  framework capability that those plugins will eventually depend on.

## 5. Corrected course

1. **Stop further migration-contract rehearsal.** Phase 6A is more than sufficient;
   a third reference draft would be waste and leans toward production migration.
2. **Return to framework-first closure.** Treat the remaining gap as a framework
   readiness gap, not a plugin gap: the new architecture has not been proven against
   a realistic plugin shape.
3. **Keep the broad `PLANS.md` vision explicitly post-v2.0** (shipping universality,
   licensing webhooks/UI, box-packer, DI, React, EDD). Do not let migration rehearsal
   pull shipping-plugin specifics into the framework prematurely.

## 6. Three-tier scope discipline (lock this)

- **Framework first** — finish and verify the v2.0 platform-split framework inside
  `woodev_framework`. This is where work belongs now.
- **Sandbox plugin validation second** — `plugins-reference/*` copies are
  rehearsal/validation targets **inside this repo only**. They may be analyzed, and
  may inform realistic fixtures, but are not production source of truth and must not
  be edited as if they were.
- **Real production-plugin adaptation later** — only after the framework is
  sufficiently ready; only in the real plugin repositories; never confused with
  sandbox adaptation; never started in this session.

## 7. Single next safe work category

**Sandbox-based framework readiness validation** (framework-first, sandbox-only),
NOT more migration-contract paperwork and NOT real production migration.

Defined, not yet executed:

- Prove the new explicit-loader + `Woocommerce_Plugin` path can host a realistic
  shipping-plugin shape, using the sandbox copies as the *model* — e.g. add a
  realistic-shaped fixture under `tests/_fixtures/` derived from a sandbox plugin's
  consumption pattern, or a read-only conformance mapping of a sandbox plugin onto
  the new loader API.

Constraints for that step when it is approved:

- Stay inside `woodev_framework`; do not touch original plugin repos.
- Do not edit `plugins-reference/` copies.
- Do not expand resolver/bootstrap scope; no runtime behavior in `bootstrap.php` /
  `Framework_Resolver`.
- Keep production loading include-based; preserve multi-version early-class safety
  and guarded aliases; preserve installed-site contract discipline.
- Do not start Phase 6B.

## Related

- [PLANS.md](../PLANS.md) — strategic intent.
- [Platform v2 Implementation Spec](platform-v2-implementation-spec.md) — accepted v2.0 sequencing and DoD.
- [Platform v2 Strategy Alignment](platform-v2-strategy-alignment.md) — rewrite-first, installed-site contract policy.
- [Platform v2 Phase 6A Reference Gap Analysis](platform-v2-phase6a-reference-gap-analysis.md) — paper validation of the contract template.
- [ADR-003](adr/003-platform-v2-minimal-framework-resolver.md), [ADR-004](adr/004-platform-v2-plugin-loader-api.md) — resolver and loader boundaries.
