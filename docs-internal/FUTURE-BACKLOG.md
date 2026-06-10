# Future Backlog — Woodev Plugin Framework

> Features and improvements deferred for later versions.
> Format: what's done | what's missing | why deferred | when to implement

## Fable 5 Architecture Review — triaged findings (2026-06-10, s5)

> Source: `docs-internal/reviews/fable5-architecture-review-2026-06-10.md` (single Fable 5 agent, fresh-eyes architecture/direction review). **Operator decision (s5): record now, do not fix now.** Each item carries a trigger-stage; convert to an autodev atomic task when its trigger fires. **Verification:** B-1/B-2/B-3 verified against source this session (file:line in the report); B-4…B-12 are plausible-pending-verification — **re-verify before acting** (never apply an external audit blindly).

| ID | Sev | Trigger-stage | Action when triggered |
|----|-----|---------------|------------------------|
| **B-1** mixed v1/v2 fleet → site-wide fatal at the bootstrap rendezvous | **Critical** | **BEFORE first production plugin rewrite ships** | Soft-fail probe (`method_exists`) in the v2 entry-file template + a `register_plugin()` tombstone on the v2 bootstrap that records the legacy plugin into the incompatible list + admin notice. Fixture test both directions. Site-availability armor — does NOT violate clean-break (ADR-005). |
| **B-2** loader-protocol is first-loaded-wins + hard-rejects unknown vocabulary | High | **before S4 (EDD)** | (1) document the bootstrap public surface + loader-definition schema as a frozen, additive-only **wire-protocol contract between vendored copies** (new contract category); (2) capability/platform validation → warn-and-ignore unknown strings (forward tolerance), keep hard-reject only for structurally malformed defs; (3) drop the `PLATFORM_EDD` hard-reject; (4) optional protocol-version constant. |
| **B-3** keyless/free-product updates can't work: `load_updater()` requires a key | High | **with S3 §4 signing impl** | Rework `load_updater()` to always construct `Woodev_Plugin_Updater` (empty-key polling is harmless; server tolerates it); align the `is_admin()‖WP_CLI` gate with the cron path; regression test "no key → updater constructed → claim consumed". **Also correct both S3 specs** (§4.3 premise) — the fix is framework-side only; woodev-core needs no change. |
| **B-4** Ed25519 protects only the license-free path, not paid-status piracy | Medium | S3 §4 (doc) | One paragraph in spec §1.2 naming the asymmetry (paid status is a plain WP option; crypto only gates the free short-circuit). Keep enforcement server-side. |
| **B-5** signing-key ops: lazy regen, DB-resident secret, no rotation/`kid` | Medium | S3 §4 + woodev-core ops | Refuse-to-sign + alert on key absence (explicit one-time generation, not lazy); offline keypair backup; add optional `kid` to the envelope + let the framework accept a short list of embedded pubkeys (flag-day-free rotation). |
| **B-6** `site` claim binding has no defined normalization | Medium | S3 §4 | Define one normalization fn (e.g. `untrailingslashit(home_url())`, lowercase scheme+host), use byte-identically on send/sign/verify; extend the published test vector; decide multisite semantics. |
| **B-7** licensing UI is WooCommerce-only while licensing is a base service | Medium | **S3 sub-stage 2 (acceptance criterion)** | "License page renders with WooCommerce absent" becomes an explicit S3.2 acceptance criterion (React rework is the natural fix). Record limitation in tracker so S4 doesn't start before it. |
| **B-8** S3.2 React page will de-facto define the S5 React architecture | Medium | **before S3.2 coding** | One-page "React baseline" decision (PLANS §6: WP/WC-bundled React; `@wordpress/scripts` vs Vite; `apiFetch`+REST conventions; component location in a vendored framework; `@wordpress/element` availability by WP version). Treat the license page as the S5 pilot explicitly. |
| **B-9** base `includes()` eagerly loads every module for every plugin | Medium | ongoing convention | Do NOT retro-split (churn > value). Policy for NEW modules: include behind capability/feature checks (the `is_woocommerce_active()` packer-dispatcher gate is the model). Revisit only if S4/S5 measurably suffer. |
| **B-10** `invoke_plugin()` never validates main class vs declared platform/capabilities | Medium-Low | during plugin migrations | Cheap post-instantiation `instanceof` check → mismatches into `invalid_loader_definitions` (or `_doing_it_wrong` in debug). One test per capability. ADR-004 already anticipates this. |
| **B-11** data-contract enforcement at rewrite time is prose, not machine | Medium | **first real plugin migration** | Convert that plugin's checklist into an executable contract test in its repo (assert exact option keys / method ids / cron hooks / meta prefixes — `YandexPilotFixtureTest` is the template); "contract test green" becomes migration definition-of-done. One-time template, reused ~12×. |
| **B-12** `is_active()` returns true with no license data | Low | S3.2 (docblock) | Clarify the three distinct "true" meanings (genuinely-active vs not-known-bad vs license-free) in docblocks during S3.2; pre-existing, do not change behavior. |

## Technical Debt

### PHPStan Baseline Cleanup
- **What's done:** 50+ errors baselined in phpstan-baseline.neon
- **What's missing:** Fix or properly type-annotate each ignored error
- **Why deferred:** Non-blocking; baseline errors are existing patterns, not regressions
- **When:** Gradually, during normal development

### Deprecated Methods Removal (v2.0.0)
- **What's done:** 11 methods deprecated in Woodev_Plugin, `_deprecated_function()` called
- **What's missing:** Remove the deprecated methods after deprecation cycle
- **Why deferred:** Backward compatibility — 10+ dependent plugins need migration time
- **When:** v2.0.0 (next major version)

### Payment Gateway Trait Extraction
- **What's done:** class-payment-gateway.php works correctly
- **What's missing:** File is ~3900 lines — extract logical groups into traits
- **Why deferred:** Functional priority over code organization
- **When:** During payment-gateway refactoring cycle

## Maintenance & Cleanup (v2.0.0)

### 1. Bump WP/WC Minimum Versions
- **What's done:** ✅ Cleanup gate complete: documented/default minimums raised to WP 6.3+, WC 7.0+; PHPCS WP minimum and test fixtures updated; below-minimum compat remnants isolated or removed.
- **What's missing:** Nothing for Phase 0. Future platform split still needs metadata-aware WC checks per Epic 1.
- **Why deferred:** Breaking change — requires coordinated update across all dependent plugins
- **When:** v2.0.0 (first — cleanup legacy before architectural split)

### 2. Remove Unused US-Specific Payment Types
- **What's done:** ✅ Cleanup gate complete: Apple Pay and Google Pay code/assets already removed; active ACH/eCheck API and direct-gateway transaction paths removed; deprecated false-return eCheck wrappers retained for production-plugin compatibility.
- **What's missing:** Nothing for Phase 0. Remove deprecated wrappers only after production plugins no longer call them.
- **Why deferred:** Destructive cleanup — must audit all dependent plugins first to confirm no usage
- **When:** After #1 (version bump), during payment-gateway refactoring cycle

## Deferred Features

### 3. Push Notifications & Webhooks (Server → Client)
- **What's done:** Nothing — no server-to-client communication mechanism exists
- **What's missing:** 
  - REST API endpoint on client side to receive signed requests from license server
  - Actions: deactivate plugin (license non-payment), display admin notices, trigger background jobs
  - Authentication: HMAC or asymmetric signature verification
  - Retry + queue mechanism for failed deliveries
- **Why deferred:** Requires server-side coordination; design not finalized
- **When:** Post v2.0.0

### 4. Shipping Module Boilerplate
- **What's done:** Shipping method base classes exist (PSR-4: `Woodev\Framework\Shipping\*`)
- **What's missing:** Full shipping plugin scaffold — admin configuration UI, rate calculation pipeline, carrier API integration helpers, shipment tracking, label printing
- **Why deferred:** Complex subsystem; needs dedicated design phase
- **When:** Post v2.0.0 (parallel track with #3)

### 5. React-Oriented Admin UI
- **What's done:** Admin pages use PHP-rendered HTML (WordPress Settings API)
- **What's missing:** 
  - Replace key admin screens with React SPA components
  - REST API endpoints for UI data (settings CRUD, logs, diagnostics)
  - Build tooling: Webpack/Vite integration, JSX compilation
  - Component library: settings forms, data tables, charts, notifications
- **Why deferred:** Requires frontend tooling setup + REST API expansion; significant scope
- **When:** Post v2.0.0 (earliest), likely phased over multiple releases

### 6. Framework Decoupling — Support Non-WooCommerce Plugins
- **What's done:** Framework is tightly coupled to WooCommerce — `Woodev_Plugin` assumes WC is always present, bootstrap checks for WC, all modules reference WC classes
- **What's missing:** 
  - Abstract platform layer: `Woodev_Plugin` → split into `Base_Plugin` (pure WP) + `WooCommerce_Plugin` (WC-specific). Plugins extend the variant they need
  - Bootstrap: detect platform type, load appropriate module set
  - Module isolation: payment-gateway, shipping-method, box-packer remain WC-only; settings-api, licensing, REST API, utilities work on any platform
  - FUTURE: EDD plugin variant (`EDD_Plugin`) — enable same framework for Easy Digital Downloads plugins
- **Why deferred:** Major architectural change — touches bootstrap, plugin base class, and every module. Must be designed before v2.0.0 refactoring begins
- **When:** Design phase: now. Implementation: v2.0.0 (after cleanup tasks #1 & #2 — remove legacy first, then split platform layer)

## Cross-Project Initiatives

### 7. Cross-Project Ecosystem Orchestration — "Оркестрация экосистемы Woodev"
- **What's done:** Concept spec written 2026-05-13 in the `woodev_theme` project after a deep brainstorm with Maksim
- **What's missing:** Everything beyond the spec — projects manifest, event-driven orchestrator, specialized sub-agents (framework / per-plugin / content / WC-monitor / npm-monitor / n8n), compatibility matrix testing, contract testing
- **Scope:** System-level automation across the entire Woodev project graph — framework, ~12 commercial plugins, woodev-theme, n8n automations, marketing/content (blog, docs, Telegram). Goal: zero unnecessary human in the flow for change propagation, external dependency monitoring (WC/WP/EDD releases), and release announcements
- **Why deferred:** Depends on Framework v2.0 stable API. Building cross-project orchestration on v1.x patterns may not survive v2.x reality. Specifically the orchestration requires:
  - A stable public API surface in the framework (contracts depend on this)
  - Versioning discipline baked into framework (strict semver, tiered API stability)
  - Plugins isolated enough to be tested independently against the framework
  - A baseline compatibility matrix for the current state of the ecosystem
  - Contract testing pattern defined per plugin
- **When:** AFTER Framework v2.0.0 has shipped AND stabilized in production for several weeks
- **Spec location:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`
- **Trigger action for the future agent:** when v2.0 is stable, do **NOT** auto-start implementation. Re-read the spec, verify the 5 prerequisites in §8 of the spec, weigh the 4 approaches against the post-v2.0 reality, then propose a plan to Maksim for approval. The spec itself contains explicit "do not implement" instructions for the agent who picks it up.
