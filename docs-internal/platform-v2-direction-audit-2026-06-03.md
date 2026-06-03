# Platform v2 — Direction Audit & Course Correction

> Date: 2026-06-03
> Type: deep audit + strategic reset (analysis only — no code changed this session)
> Author: AI agent (deep multi-agent audit pass)
> Status: **active — supersedes the execution assumptions of `platform-v2-implementation-spec.md` where they conflict (see §8)**
> Trigger: operator's gut feeling that the refactoring "is moving in the wrong direction" after N agent sessions.

---

## 1. Purpose & method

The operator asked for an independent, initiative-taking audit of the whole Platform v2 effort: is the direction right, what should be redone, what should not be done at all, what is missing. This document is the result.

Inputs read in full:

- `PLANS.md` (the original brainstorm/intent draft)
- `docs-internal/CURRENT-STATE.md`, `docs-internal/SESSION-LOG.md`
- `docs-internal/platform-v2-implementation-spec.md`
- ADRs 001–004, `platform-v2-strategy-alignment.md`, `platform-v2-next-analysis.md`, `platform-v2-epic1-spec.md`, `platform-v2-dependency-matrix.md`, `platform-v2-roadmap-reconciliation.md`, `platform-v2-migration-contract-template.md`
- The actual current source (`bootstrap.php`, `class-framework-resolver.php`, `class-plugin.php`, `class-woocommerce-plugin.php`, shipping-method/, helper + alias files)
- The reference shipping plugins under `plugins-reference/`

Method: four parallel audit agents (design-doc trail, git/session trajectory, current-code metrics, shipping reference survey) plus direct reads of the two central docs. Findings cross-checked across agents; they converged.

---

## 2. Executive verdict (TL;DR)

**The architecture decisions are sound. The problem is allocation, a self-contradiction, and process — not design.**

In one sentence: *the effort has been polishing infrastructure for platforms that do not exist yet (pure-WP / EDD) and paying a backward-compatibility tax for plugins that are explicitly going to be rewritten, while the modules that carry the real value (shipping, box-packer, licensing) sit nearly untouched.*

The operator's instinct is correct. The fix is not to throw away the architecture — it is to:

1. **Finish the platform split *properly and cheaply*** (resolver actually minimal, base no longer a god-object), now that a clean break removes the shim tax.
2. **Stop the back-compat machinery for internal APIs** — keep only installed-site data preservation.
3. **Stop the premature paperwork** (migration contracts written before the new path is proven on a real plugin).
4. **Reconcile the `CLAUDE.md` ↔ `PLANS.md` contradiction** that has been generating most of the waste.
5. **Then** move to the domain modules in the order `PLANS.md` itself implies (shipping → box-packer → licensing).

---

## 3. What is SOUND — do not touch

These decisions are correct, well-documented, and aligned with `PLANS.md`. Keep them.

| Decision | Why it is right |
|---|---|
| Platform-first class hierarchy `Woodev_Plugin` → `Woodev_Woocommerce_Plugin` → specialized bases | Directly implements `PLANS.md` §2.1. `Woodev_Woocommerce_Plugin` (≈299 lines) is lean and correctly focused. |
| Inheritance as the runtime source of truth for plugin type (not the `is_payment_gateway` flag) | Resolves `PLANS.md` §5 open-question #2 cleanly. ADR-002 + ADR-004. |
| Explicit loader-definition API instead of loose positional `register_plugin()` args | Machine-validatable, removes string drift. ADR-004. |
| "Resolver owns only early infrastructure, not runtime platform behavior" + boundary tests | Correct kernel discipline (spec §6.2–6.4). The *intent* is right (see §4.5 for where the *implementation* drifted from it). |
| Multi-version framework arbitration preserved | Required invariant; tests exist. |
| Distinguishing installed-site contracts (must preserve) from developer-internal entry shape (may break) | This is the *correct* nuance and survives the clean-break decision (see §6.2). |

**Conclusion:** the design trail is ~75% aligned with `PLANS.md`. There are no critical architectural flaws. Everything below is about execution.

---

## 4. What went OFF TRACK — evidence

### 4.1 Effort went where `PLANS.md` did NOT put the priority

Over ~26 days / ~79 commits on the refactor, the rough allocation was:

| Category | Approx share |
|---|---|
| Documentation / strategy / planning / roadmap resets | ~25% |
| Platform v2 core (resolver, namespace, phases 1–4) | ~20% |
| Phase 5 micro "platform-neutral helper" slices (12–14 single-`wc_*` replacements) | ~17% |
| eCheck/ACH removal (legitimate v2.0 cleanup) | ~12% |
| Audit fixes + a payment-gateway regression repair (`a7da0ea`) | ~17% |
| Sandbox fixtures | ~6% |
| **Direct progress on `PLANS.md` priority modules (shipping / box-packer / licensing)** | **~8%** |

Against the operator's own stated priorities:

- **Shipping module** — `PLANS.md` §3.2 calls it *the* priority (60%+ of WooDev plugins are shipping; the module must be "ideal and maximally universal"). Actual state: **~20% complete — a structural skeleton.** No PVZ/pickup-point map abstraction (the single most-duplicated piece, with `woocommerce-yandex-delivery` named as the reference), no checkout-field orchestration, no order/tracking/webhook base classes, no admin-UI scaffolding, no warehouse data store. Reference plugins still hand-roll all of this independently.
- **Box-packer minimal-virtual-box algorithm** — `PLANS.md` §3.5.1 specifies a concrete, well-scoped problem (current algorithm produces oversized virtual boxes for 3+ items). Actual work: **0 on the algorithm** — only a contract/interface split (`96cce09`).
- **Licensing** — `is_need_license` flag, modern interactive UI, kill-switch + diagnostic webhooks (`PLANS.md` §3.4–3.4.1). Actual work: **only the WC-coupling split started** (`2026-06-03`, "half done"). No flag, no UI, no webhooks.

### 4.2 The root cause: `CLAUDE.md` ↔ `PLANS.md` contradiction

This is the most important finding.

- `CLAUDE.md` (project) mandates: *"NEVER delete or rename public methods/classes without a deprecation cycle… ALWAYS add `@deprecated` + `_deprecated_function()`… minimum one full version before removal."* Framework "used by 10+ plugins."
- `PLANS.md` §2.4 states the opposite: *"по сути — новый фреймворк; от старого мало что останется… Поломки в плагинах на старых версиях фреймворка не страшны: их всё равно перепишем под новый."*

These cannot both be obeyed cheaply. The sessions obeyed `CLAUDE.md`. Result: **every refactor paid a back-compat tax** — a deprecation shim, often a global `class_alias`, and a dedicated test for the shim — for plugins the operator intends to rewrite. Examples that exist purely because of this contradiction:

- `class-woocommerce-plugin-alias.php`, `class-woocommerce-helper-alias.php` (global-namespace aliases).
- Deprecation shims in `class-plugin.php` (`get_woocommerce_uploads_path`, `add_class_form_wrap_*`) and `class-helper.php` (4 WC methods), each with a shim-specific test.
- The "temporary legacy adapter" (`register_legacy_plugin()`) inside the resolver and the legacy positional `register_plugin()` path.
- An entire B-2 polish sub-session spent making a shim reference the correct FQCN.

Under the operator's clean-break decision (§5), most of this is **debt to be deleted, not an asset to be maintained.**

### 4.3 Premature paperwork: migration contracts before validation

Phase 6A produced a migration-contract **template** plus **two reference-contract drafts** (`edostavka`, `yandex`) — substantial documentation — *before the new architecture was ever exercised by a real plugin.* `platform-v2-roadmap-reconciliation.md` §4 admits it bluntly:

> "The new resolver + explicit loader + `Woocommerce_Plugin` inheritance path has only ever been exercised by synthetic inline unit fixtures, never by a realistic plugin shape… both sandbox copies still consume the old framework… Phase 6A produced more migration-contract paperwork than the methodology validation required."

This is cart-before-horse: contracts were written for a path that had not been proven to load real plugin code.

### 4.4 Micro-slice churn

Phase 5 was executed as **12–14 atomic commits**, each replacing a single WooCommerce helper call (`wc_string_to_bool`, `wc_let_to_num`, `wc_clean`, `wc_date_format`, `wc_format_decimal`, `wc_enqueue_js`, `wc_doing_it_wrong`, …) with a guarded local equivalent. Each slice carried its own tests and review overhead. This is a lot of cumulative ceremony to decouple the base from WooCommerce *functions* — work whose entire payoff is hypothetical pure-WP / EDD plugins that do not exist yet. (The code itself is clean — it uses `function_exists()` guards, not reimplementation — but the sequencing and the ratio of ceremony-to-value is the issue.)

### 4.5 Process drift

- **Work landed on `main`.** `PLANS.md` §2.4 explicitly required a separate branch with the original kept for comparison. The spike branch `feat/platform-v2-epic1-spike` exists but was abandoned; all ~79 commits went to `main`. This removed the safety net the operator asked for and entangled an unfinished rewrite with the shipping line of plugins.
- **Multiple strategy resets.** Orchestration-first → abandoned; Epic 1 spec → superseded; a dedicated "roadmap reconciliation" to re-anchor on `PLANS.md`. Churn at the planning layer.
- **The "minimal resolver" is not minimal.** `class-framework-resolver.php` is ~693 lines / 16 public methods and owns the whole discovery→validation→capability-preload→invocation pipeline. It is a kernel. The boundary *tests* exist, but the class has grown past "thin."
- **The base class is still a god-object.** `Woodev_Plugin` is ~1,435 lines / ~87 methods, still mixing lifecycle, settings, messaging, hooks, REST, blocks, translations, updater, licensing, cron, logging. The "split" moved ~5 methods to the subclass. It has **not** actually decomposed the base — which is what `PLANS.md` §1 (DI / SOLID, framework owns infrastructure, plugins keep only domain logic) is really asking for.

---

## 5. Decisions taken this session (operator)

Two open questions from `PLANS.md` §5 were resolved with the operator:

**D-1 — v2.0 priority:** *Finish the platform split cleanly FIRST, then the domain modules.* (Not "modules first.") The split must reach a genuinely clean state before module work begins. EDD / pure-WP stay reserved as concepts, not implemented in v2.0.

**D-2 — Backward-compatibility policy: clean code break, preserve data.**
- Free to break: internal APIs, class names, entry-file shape (plugins will be rewritten).
- Strictly preserve installed-site data: option keys, license state + instance IDs, updater identity, shipping/payment method IDs, public hook names, cron hooks/payloads, custom tables, REST namespaces.
- Remove deprecation shims / aliases that exist only for internal-API continuity.
- Update `CLAUDE.md` to match (its current strict-deprecation mandate is now wrong for this project).
- Do the work on a **separate branch**.

A second round resolved the §9 open questions:

**D-3 — Decompose the base god-object, inside v2.0 (resolves O-1).** `Woodev_Plugin` is decomposed into injected handlers/services as part of "finish the split," not deferred to v2.x. Scope is **pragmatic**: extract the clearest subsystems into handlers, apply DI as far as WordPress allows; do not build a full DI container or gold-plate. See §6.1.3.

**D-4 — Keep a thin global rendezvous entry point (resolves O-2).** The `bootstrap.php` global entry object cannot be removed without abandoning per-plugin framework vendoring (see §6.6 for the rationale). Keep it, minimize it, and document *why* it exists so future agents do not try to "simplify it away." The only thing that would let it disappear — moving to a single shared framework install — is raised as new open question O-5 (§9).

**D-5 — Validation pilot = `woocommerce-edostavka` (resolves O-3).** Both `edostavka` and `yandex` will be migrated eventually (each has distinct value; `yandex` carries the PVZ-map reference). For the single §6.4 real-plugin validation gate, use **`edostavka`** (simpler shape). `yandex` becomes the second pilot when shipping-module work begins (§7).

---

## 6. Corrected direction

### 6.1 Finish the split — definition of "done right"

"Split is done" is **not** "WC methods moved to the subclass." Under D-1, finishing means all of:

1. **`Woodev_Plugin` is genuinely platform-neutral** — no `add_woocommerce_hooks()` stub or WC-awareness leaking into the base; base loads and runs with WooCommerce entirely absent. (The no-WC fixture already proves loading; the goal is no residual WC-shaped seams.)
2. **The resolver is actually minimal** — re-evaluate the 693-line kernel against ADR-003 §6.2/6.3. Either justify each responsibility or extract. At minimum, drop the legacy-adapter responsibilities removed by D-2 (see §6.3).
3. **The base god-object is decomposed** (D-3) — its subsystems (lifecycle, messaging, settings, REST, blocks, licensing, updater, cron, logging) become injected handlers/services rather than everything constructed inline in one 1,435-line class. This is the concrete cash-out of `PLANS.md` §1's "DI as far as WordPress allows / SOLID." **Scope (decided pragmatic):** extract the clearest subsystems into discrete handlers and inject them; apply DI only as far as WordPress's load order allows. Do **not** build a general DI container or aim for textbook purity in v2.0 — the goal is "base is no longer a god-object and each subsystem is independently understandable/testable," not a framework-within-a-framework. This is the largest single piece of "finish the split" and should get its own spec when planning begins.
4. **Hierarchy is clean for new code** — namespaced `Woodev\Framework\*` is the only target; global names survive *only* where an installed site references them, not for developer ergonomics.

### 6.2 Clean-break policy — what to preserve vs. delete

**PRESERVE (release-blocking — these touch live user sites):**
option keys & settings arrays · license key option names + activation state + instance IDs + updater state · WC payment gateway IDs · WC shipping method IDs + instance setting keys · public action/filter names · scheduled cron hooks + recurrence + payload shape · custom DB tables/schemas · REST route namespaces · AJAX action names · admin page slugs · log source names · background-job IDs · email IDs / note sources / system-status rows.

The existing `platform-v2-migration-contract-template.md` is the **right checklist for this** — but reframed: it is a *data-preservation checklist applied at the moment a specific plugin is rewritten*, **not** an upfront paper artifact produced before the architecture is proven.

**DELETE / STOP MAINTAINING (internal-API back-compat — debt under D-2):**
global `class_alias` shim files for internal classes · `_deprecated_function()` shims for moved/renamed internal methods + their shim-specific tests · the temporary legacy adapter (`register_legacy_plugin()`) and legacy positional `register_plugin()` path · the "early capabilities" metadata pattern if it only exists to map the legacy `is_payment_gateway`/`load_shipping_method` flags (inheritance is the truth — validate the loaded class instead).

> Caveat: deletion happens **on the new branch**, in lockstep with rewriting the plugins that consume these names. Do not rip them out from under a plugin that still ships against them until that plugin's rewrite lands.

### 6.3 What to STOP now

- **Stop** writing/expanding migration-contract drafts (Phase 6A paperwork). Archive the two reference drafts; keep the template as the §6.2 checklist.
- **Stop** adding internal-API deprecation shims/aliases and tests-for-shims.
- **Stop** further atomic single-`wc_*` micro-slices as standalone sessions. If more base-neutralization remains, batch it into one cohesive "platform-neutral base helpers" change, not 14 PRs.
- **Stop** committing the rewrite to `main`.

### 6.4 What to START / reorder

1. **Create the refactor branch** and snapshot `main` as the reference baseline (the safety net `PLANS.md` §2.4 wanted from the start).
2. **Reconcile `CLAUDE.md`** (and any `AGENTS.md`/global doc that echoes it) to the clean-break policy: replace "never break public API / deprecation cycle for everything" with "free to break internal APIs on the v2 branch; release-blocking only for installed-site data contracts." This single edit stops the bleeding.
3. **Prove the new path on a real plugin shape — once.** Take **`woocommerce-edostavka`** (D-5 — simpler shape; `yandex` follows as the second pilot at §7) and actually load it through the new resolver + explicit loader + `Woocommerce_Plugin` inheritance, end-to-end, *before* any further framework polishing. This is the validation Phase 6A skipped. It will surface the real gaps faster than any more analysis.
4. **Decompose the base god-object** (§6.1.3) — likely the main body of "finish the split."
5. **Re-minimize the resolver** against its own ADR.

### 6.5 Sequencing (v2.0)

```
Branch + safety baseline
  → CLAUDE.md reconciliation (clean-break policy)
  → Delete internal-API back-compat debt (lockstep w/ first plugin rewrite)
  → Prove new path on ONE real plugin (validation gate)
  → Decompose Woodev_Plugin (DI/handlers) + re-minimize resolver
  → "Split done" gate: pure-WP loads, base is not a god-object, resolver minimal, composer check green
  ───────────────────────────────────────────────
  THEN domain modules, in PLANS.md priority order:
  → Shipping universal module (biggest; PVZ-map abstraction first — see §7)
  → Box-packer minimal-virtual-box algorithm
  → Licensing (is_need_license flag → modern UI → webhooks)
```

### 6.6 Why the global rendezvous entry point must stay (D-4 rationale)

`PLANS.md` §5.1 suspected `bootstrap.php` is "an unnecessary layer." It is not — it is the **rendezvous point for independently-vendored framework copies**, and that role is structurally mandated by the distribution model:

- Every plugin ships **its own copy** of the framework and includes it via `woodev/bootstrap.php`. The N copies are mutually unaware.
- All copies declare the **same** `Woodev\Framework\*` namespaced classes. With include-based loading (spec §6.1 forbids relying on Composer autoload in production), the **first copy included wins the class table regardless of version** — exactly the failure the multi-version arbitration exists to prevent.
- Preventing it requires **one object at a stable global name** (`Woodev_Plugin_Bootstrap`), guarded by `class_exists()`, where copies #2..N detect "bootstrap already started — register onto it" instead of redefining. That negotiation cannot live in a namespaced resolver, because the resolver class is itself duplicated in every copy.

Two distinct concerns that must not be merged:

| Concern | Mechanism |
|---|---|
| "Who am I" (a plugin describing itself) | Explicit **loader definition** |
| "Which framework copy loads" (version arbitration across copies) | Global **rendezvous** (`bootstrap.php` singleton) |
| "How the winning copy loads + invokes" | **Resolver** (engine inside the winning copy) |

**Decision (D-4):** keep `bootstrap.php` as a thin compatibility include + minimal rendezvous (registration intake → version compare → hand off to the winning copy's resolver). It is already close to this (~222 lines). Add a header comment stating it is a required arbitration point, **not** a removable layer.

The single architectural move that *would* eliminate it is O-5 (§9): abandon per-plugin vendoring for one shared framework install. That changes how plugins are distributed to customers and is the operator's call — not assumed here.

---

## 7. The shipping module — sizing the real prize (for when §6.5 reaches it)

This is deferred *correctly* (getting the abstraction wrong locks bad patterns into 5+ plugins), but the roadmap must be honest that it is the **largest** piece of value, not a footnote. From the reference survey, an "ideal universal shipping module" must absorb what every shipping plugin currently hand-rolls:

- **PVZ / pickup-point map abstraction** (the #1 duplicated piece; `woocommerce-yandex-delivery` is the stable reference): pluggable map provider (Yandex/Leaflet/…), standard pickup-point data model, warehouse data store, checkout modal + balloon templates, point filtering (type / payment method / size), selection-state persistence in session/order meta, address normalization (DaData).
- Checkout-field orchestration (custom fields, validation, posted-data handling).
- Order metadata + tracking + label/export + carrier webhook/cron status sync.
- Admin-UI scaffolding (order-list columns, metabox, settings integration, batch export).
- REST routes (warehouses, pickup search, tracking) + AJAX handlers.

The **`payment-gateway` module is the architectural reference** (mature: handlers, REST controllers, admin suite). Current `shipping-method/` provides the inheritance skeleton (`Shipping_Plugin`, `Shipping_Method` with COURIER/PICKUP/POSTAL, `Shipping_Rate`, `Shipping_Integration`) and empty `admin/`/`checkout/`/`api/` dirs — i.e. ~20% of what production plugins need. This is genuinely a multi-week subsystem, comparable in size to the entire platform-split effort. Plan it as its own spec when §6.5 reaches it.

---

## 8. Relationship to existing docs

- This document **overrides the execution sequencing** of `platform-v2-implementation-spec.md` where they conflict — specifically: Phase 6 (migration contracts) is demoted from "blocking gate / paper artifact" to "data-preservation checklist at rewrite time"; the temporary legacy adapter (spec §8) is slated for deletion under D-2 rather than maintained; the "early capabilities" metadata (spec §7.4) is to be re-justified or dropped.
- The spec's **architecture** sections (§5, §9 platform boundaries, §10 early class availability, §12 fixtures) remain valid and are the reference for "finish the split."
- ADRs 001–004 remain valid as recorded decisions; ADR-002's "deprecated metadata bridge for ≥1 minor release" is **overruled by D-2** (clean break) and should get a short "superseded by 2026-06-03 clean-break decision" note.
- `platform-v2-roadmap-reconciliation.md` §7 already pointed the right way ("validate against a realistic plugin shape, not more paperwork") — this document agrees and makes it the §6.4 validation gate.

---

## 9. Open questions remaining

- **O-1 — RESOLVED (D-3):** decompose the god-object inside v2.0, pragmatically (clearest handlers + DI as far as WP allows; no DI container). See §6.1.3.
- **O-2 — RESOLVED (D-4):** keep the thin global rendezvous; it is structurally required by per-plugin vendoring, not a removable layer. See §6.6.
- **O-3 — RESOLVED (D-5):** validation pilot = `woocommerce-edostavka`; `yandex` is the second pilot at §7. Both migrate eventually.
- **O-4 (React admin UI — `PLANS.md` §6):** confirmed post-v2.0; uses WordPress/WooCommerce built-in React, not a separate ReactJS app. No action in v2.0.
- **O-5 (NEW — distribution model):** the only change that would let `bootstrap.php` and the whole version-arbitration layer disappear is moving from per-plugin vendored framework copies to **one shared framework install** (mu-plugin or shared Composer dependency). That changes how plugins are delivered to customers (today each plugin is self-contained in the store). Big simplification, big distribution change — **operator's call, not assumed.** Likely stays per-plugin, but worth a conscious decision before locking the entry-point design.

---

## 10. Appendix — audit metrics

| Metric | Value |
|---|---|
| Refactor window | 2026-05-09 → 2026-06-03 (~26 days) |
| Commits in window | ~79, **all on `main`** (spike branch abandoned) |
| Doc/planning vs code commit ratio | ~24 : ~55 |
| Strategy resets | ≥3 (orchestration-first abandoned, Epic 1 superseded, roadmap reconciliation) |
| Explicitly abandoned/deferred work items | ≥5 (incl. L-2 5th test deleted, REST-permissions seam, Phase 6A expansion) |
| `class-framework-resolver.php` | ~693 lines / 16 public methods ("minimal" resolver = kernel) |
| `class-plugin.php` (base) | ~1,435 lines / ~87 methods (still god-object) |
| `class-woocommerce-plugin.php` | ~299 lines (clean, focused) |
| Internal-API back-compat scaffolding | ~23 `_deprecated_function`/`_doing_it_wrong` calls + 2 `class_alias` files (debt under D-2) |
| Shipping module completeness vs production need | ~20% (skeleton; no PVZ map) |
| Box-packer algorithm progress | 0 (contract split only) |
| Licensing feature progress (`is_need_license`/UI/webhooks) | ~0 (WC-coupling split half-done) |
| Direct progress on `PLANS.md` priority modules | ~8% of total effort |

## Related

- [PLANS.md](../PLANS.md) — original intent draft (priorities + §5 open questions)
- [platform-v2-implementation-spec.md](platform-v2-implementation-spec.md) — architecture valid; sequencing overridden here (§8)
- [platform-v2-roadmap-reconciliation.md](platform-v2-roadmap-reconciliation.md) — §7 already recommended real-plugin validation
- [platform-v2-migration-contract-template.md](platform-v2-migration-contract-template.md) — keep as the §6.2 data-preservation checklist
- [CURRENT-STATE.md](CURRENT-STATE.md) — to be updated with the §5 decisions and §6.5 sequencing
