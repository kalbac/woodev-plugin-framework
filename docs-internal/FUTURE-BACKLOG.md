# Future Backlog — Woodev Plugin Framework

> Features and improvements deferred for later versions.
> Format: what's done | what's missing | why deferred | when to implement

## Operator backlog dump — s13 (2026-06-13)

> Captured verbatim from the operator at the s13 close ("пока помню"). Not yet scoped — convert each to a spec/autodev task when scheduled. None block current work.

### OB-1 — Bootstrap silently yields to a v1-framework plugin (no notice)
- **Problem:** when a plugin bundling framework **v1** is already installed and wins the class rendezvous, the bootstrap silently gives it load priority and the **v2** plugin does not load — with **no admin notice**. (This is the reverse of the B-1 tombstone case, which only covers "v2 copy wins + a v1 plugin calls `register_plugin()`".)
- **Wanted:** at minimum show a notice, e.g. "у вас установлен плагин X с версией фреймворка v1, поэтому мы не можем загрузить плагин с версией v2 (Y)." Don't fail silently.
- **Relates to:** B-1 mixed-fleet armor; `Woodev_Plugin_Bootstrap`/`Framework_Resolver`.

### OB-2 — License page React block is visually broken
- **Problem:** "Woodev → Лицензии" now renders the new React block (S3.2), but the layout/styling is "криво-косо".
- **Wanted:** fix the UI/UX of the license card-grid (styling pass). Relates to ADR-007 / S3.2.

### OB-3 — Review `Woodev_Plugin_Updater` (currently a singleton)
- **Wanted:** code review of `Woodev_Plugin_Updater`; consider folding it into the **Licensing** module (it already depends on `Woodev_Licensing_API` and reads `get_url()`). Decide on the singleton pattern.
- **✅ s14 — REVIEW DONE, recorded (not auto-fixed):** GPT-5.5 read-only review → `docs-internal/reviews/ob3-plugin-updater-review-2026-06-14.md`. Outcome: **(a)** it is NOT a singleton (fire-and-forget per-plugin instance) and should stay per-plugin but be plugin-owned + idempotent; **(b)** recommendation = **MOVE** `woodev/plugin-updater/` → `woodev/licensing/updater/` (cohesion; clean-break permits, no shim) but **do NOT** merge into `Woodev_Licensing_API` (distinct responsibilities). 4 BLOCK + 13 MINOR/NOTE findings triaged with a 5-step execution order. **Several findings touch installed-site contracts (hook arg shape, cache/changelog-URL keys) → operator sign-off + consumer audit + browser/integration verification required before implementing.** Not applied this session per the review-findings contract + updater criticality.
- **✅ s15 — Step 1 (safe subset):** F11 tested-guard + F12 types/visibility + F13 esc_attr (PR #57). **✅ s16 — Step 2 (robustness):** F2 catch(\Throwable)+log + F7 wiring-failure log (PR #58); F5 api_request param removal (PR #59). **✅ s17 — Step 5 (MOVE):** updater → `licensing/updater/` (PR #61).
- **✅ s18 — Step 4 (contract-touching, operator sign-off):** F8/F9/F10 done. **No data contract broken** (verified): **F8** fixes the `in_plugin_update_message-{$file}` 2nd arg to the response object (WP-core convention) — the sole in-repo consumer `Woodev_Plugins_License::plugin_row_license_missing()` reads `package`/`new_version` off arg 2, so this *repairs* a latent break rather than introducing one; the consumer was fixed in the same PR to read both off the response. **F9** sanitizes/unslashes the changelog `$_REQUEST` reads + strict `plugin === $this->name` — **no nonce** (operator choice), so the changelog **URL shape is unchanged** (no migration needed). **F10** stamps `source => api_url` inside the cache *value* and treats missing/mismatched source as a miss — the frozen cache **option key is unchanged** (old unstamped caches refresh once, harmless). ⛔ **Still BLOCKED:** Step 3 F1+F3 (`sections` normalization + cross-store cache key isolation) — need store payload shape verified on the rig.

### OB-4 — Reusable framework JS should be PHP-based where possible (design principle)
- **Principle:** scripts that exist for **reuse across plugins** (e.g. the PVZ-map builder for shipping methods) should be designed to be **as PHP-driven as possible** (config/markup from PHP, minimal hand-written JS). **Exception:** fixed, framework-owned admin UI (e.g. the "Woodev → Лицензии" React page) stays React — this principle does NOT apply to those.
- **Relates to:** PLANS.md §6 UI note (built-in WP/WC React for reactive admin).

### OB-5 — Study the godaddy fork for v2 patterns to borrow
- Review https://github.com/godaddy-wordpress/wc-plugin-framework for `Traits` / `Enums` / `Abilities` worth adopting in v2. (Also noted in PLANS.md §4 and the program-tracker "Open follow-ups".) Candidate for a GPT-5.x research delegation.

### OB-10 — Audit + rework Setup Wizard (brainstorm later)
- Framework has an opt-in onboarding wizard (`Woodev_Plugin_Setup_Wizard`, `woodev/admin/abstract-plugin-admin-setup-wizard.php` + payment-gateway variant `abstract-payment-gateway-plugin-admin-setup-wizard.php`) that has **never been touched/reviewed** in v2. Operator wants a dedicated **brainstorm** for it (s27 — раздельно от competitor-модуля). First step: state-of-the-module audit (what it does today, coupling, whether it survived clean-break intact), then brainstorm the v2 rework.

### OB-11 — Setup Wizard: deferred Codex-critic findings (s31, 2026-06-25)
Deferred from the s31 Codex GPT-5.5 critic pass (PR #85 fixed the confirmed-real ones; these are lower-severity / judgment calls):
- **#2 forward-navigation can complete setup without saving:** the clickable stepper + `#finish-step` hash let a user jump to the synthetic finish step (which fires `complete('completed')`) without passing the intervening settings steps. All wizard steps are skippable so this only means defaults remain, but jumping straight to finish via hash is sloppy. Fix idea: allow back-nav freely, forward only to already-visited steps; ignore `#finish-step` unless reached normally; optionally have the server verify prerequisites before accepting `completed`.
- **#3 completion failure still shows success:** the finish-step effect catches a failed `complete()`, warns to console, and still renders the success screen. Completion is idempotent (retried on next admin load) so this is cosmetic, but a retry/error UI would be more honest.
- **#7 sensitive setting values exposed in bootstrap:** `get_field_schema()` emits the current `value` for every field, including any future password/secret control, into page source + JS state (admin-only). When a `TYPE_PASSWORD`/sensitive control is actually used in a wizard, return an empty/masked sentinel and handle "unchanged" semantics on save.

### OB-6 — Dead-file sweep in v2
- Many files in the v2 tree are effectively unused (loaded nowhere / never referenced). Do a dead-code/dead-file audit and remove them. Pairs well with the trait-extraction + the big array/typing review.

### OB-7 — Modernize "Woodev → Плагины" page (React) + woodev.ru account integration
- **Now:** the plugins page is badly outdated.
- **Wanted:** rebuild it in a modern style using built-in **WP React** components. **Future idea:** let the user connect their **woodev.ru account** on this page to see which plugins they already own. Reference UX: WooCommerce extensions screen `/wp-admin/admin.php?page=wc-admin&path=%2Fextensions`.

### OB-8 — Add a "Woodev marketplace" entry on the plugin-install screen
- WooCommerce adds a "WooCommerce marketplace" tab on `/wp-admin/plugin-install.php` (link `?tab=woo`). Add the equivalent for Woodev — a marketplace tab/link on the Add-Plugins screen.

### OB-9 — Shipping module nuances (many) — discuss separately
- The operator has many shipping-module (`shipping-method/`) nuances to raise; they will be discussed in a dedicated session. Placeholder so they are not forgotten. Pairs with the deferred edostavka pilot (audit-first).

## Fable 5 Architecture Review — triaged findings (2026-06-10, s5)

> Source: `docs-internal/reviews/fable5-architecture-review-2026-06-10.md` (single Fable 5 agent, fresh-eyes architecture/direction review). **Operator decision (s5): record now, do not fix now.** Each item carries a trigger-stage; convert to an autodev atomic task when its trigger fires. **Verification:** B-1/B-2/B-3 verified against source this session (file:line in the report); B-4…B-12 are plausible-pending-verification — **re-verify before acting** (never apply an external audit blindly).
>
> **s7 status (2026-06-11, Fable orchestrator):** **B-1 implemented** (PR #27 — probe + tombstone + MixedFleetBootstrapGateTest, GPT-5.5 critic SHIP). **B-3/B-4/B-6 re-verified against source and folded into the specs** — S3.1 spec §4.3 (corrected updater premise + §4-time impl task), §1.2 (protection-asymmetry paragraph), §4.2 (`woodev_normalize_site` rule + multisite + test-vector case); woodev-core spec mirrored (local commit in woodev_theme). Their §4-time *code* work remains tied to the §4 trigger.

| ID | Sev | Trigger-stage | Action when triggered |
|----|-----|---------------|------------------------|
| **B-1** mixed v1/v2 fleet → site-wide fatal at the bootstrap rendezvous | **Critical** | ✅ **DONE s7 (PR #27)** | Soft-fail probe (`method_exists`) in the v2 entry-file template + a `register_plugin()` tombstone on the v2 bootstrap that records the legacy plugin into the incompatible list + admin notice. Fixture test both directions. Site-availability armor — does NOT violate clean-break (ADR-005). |
| **B-2** loader-protocol is first-loaded-wins + hard-rejects unknown vocabulary | High | **before S4 (EDD)** | (1) document the bootstrap public surface + loader-definition schema as a frozen, additive-only **wire-protocol contract between vendored copies** (new contract category); (2) capability/platform validation → warn-and-ignore unknown strings (forward tolerance), keep hard-reject only for structurally malformed defs; (3) drop the `PLATFORM_EDD` hard-reject; (4) optional protocol-version constant. |
| **B-3** keyless/free-product updates can't work: `load_updater()` requires a key | High | **with S3 §4 signing impl** | Rework `load_updater()` to always construct `Woodev_Plugin_Updater` (empty-key polling is harmless; server tolerates it); align the `is_admin()‖WP_CLI` gate with the cron path; regression test "no key → updater constructed → claim consumed". **Also correct both S3 specs** (§4.3 premise) — the fix is framework-side only; woodev-core needs no change. |
| **B-4** Ed25519 protects only the license-free path, not paid-status piracy | Medium | S3 §4 (doc) | One paragraph in spec §1.2 naming the asymmetry (paid status is a plain WP option; crypto only gates the free short-circuit). Keep enforcement server-side. |
| **B-5** signing-key ops: lazy regen, DB-resident secret, no rotation/`kid` | Medium | S3 §4 + woodev-core ops | Refuse-to-sign + alert on key absence (explicit one-time generation, not lazy); offline keypair backup; add optional `kid` to the envelope + let the framework accept a short list of embedded pubkeys (flag-day-free rotation). |
| **B-6** `site` claim binding has no defined normalization | Medium | S3 §4 | Define one normalization fn (e.g. `untrailingslashit(home_url())`, lowercase scheme+host), use byte-identically on send/sign/verify; extend the published test vector; decide multisite semantics. |
| **B-7** licensing UI is WooCommerce-only while licensing is a base service | Medium | ✅ **DONE s8 (PR #31, 2026-06-11)** | Closed by S3.2: license page + `woodev/v1` REST run on core (no WC gate); proven by process-isolated no-WC tests in `LicensePageRenderTest` / `LicenseRestControllerTest` + integration `LicenseRestAuthTest`. |
| **B-8** S3.2 React page will de-facto define the S5 React architecture | Medium | ✅ **DONE s8 (PR #31, 2026-06-11)** | Closed by ADR-007: `@wordpress/scripts` + native `@wordpress/components`, classic JSX runtime (WP 6.3+ support), `wp.apiFetch` + `woodev/v1` REST conventions, committed build artifacts + CI parity job. The license page IS the S5 pilot. |
| **B-9** base `includes()` eagerly loads every module for every plugin | Medium | ongoing convention | Do NOT retro-split (churn > value). Policy for NEW modules: include behind capability/feature checks (the `is_woocommerce_active()` packer-dispatcher gate is the model). Revisit only if S4/S5 measurably suffer. |
| **B-10** `invoke_plugin()` never validates main class vs declared platform/capabilities | Medium-Low | during plugin migrations | Cheap post-instantiation `instanceof` check → mismatches into `invalid_loader_definitions` (or `_doing_it_wrong` in debug). One test per capability. ADR-004 already anticipates this. |
| **B-11** data-contract enforcement at rewrite time is prose, not machine | Medium | **first real plugin migration** | Convert that plugin's checklist into an executable contract test in its repo (assert exact option keys / method ids / cron hooks / meta prefixes — `YandexPilotFixtureTest` is the template); "contract test green" becomes migration definition-of-done. One-time template, reused ~12×. |
| **B-12** `is_active()` returns true with no license data | Low | ✅ **DONE s8 (PR #31, 2026-06-11)** | Docblock on `Woodev_Plugins_License::is_active()` now names the three distinct "true" meanings (genuinely-active vs not-known-bad vs license-free); behavior unchanged (s6-p1). |

## Remote-deactivation UX findings (operator manual run, s11, 2026-06-13)

> Source: `docs-internal/reviews/remote-deactivation-ux-findings-2026-06-13.md`. The happy path is proven; these were lifecycle/UX gaps. **✅ ALL THREE RESOLVED in s12 (rig-verified vs real WC 10.8.1):** B-13 — `Woodev_Lifecycle::handle_activation()` clears the stale notice option entry on reactivation (KEPT). B-14 — WC Admin Notes breadcrumb was tried then **reverted per operator** (PR #46): a single-v2-plugin site intentionally shows NO banner (kill-switch targets violators); banner shows only with ≥2 active v2 plugins. B-15 — deactivator «Отменить»→«Снять с доставки» wording by delivery state + re-deactivation-after-terminal verified. None blocked prod.

| ID | Sev | Trigger-stage | Action when triggered |
|----|-----|---------------|------------------------|
| **B-13** remote-deactivation notice not cleared on plugin (re)activation | Medium | **s12** | Framework: on plugin activation, remove the plugin's entry from `woodev_license_remote_deactivation_notices` so a re-enabled plugin doesn't show a stale "you were disabled" notice. |
| **B-14** a remotely-deactivated single-v2-plugin site can't render its own notice | Medium | **s12 (design)** | Framework: `render_remote_deactivation_notices()` only runs via an ACTIVE engine; the deactivated plugin can't render its own. Pick a design — render from bootstrap/any loaded copy independent of plugin state, OR a core-surfaced breadcrumb, OR accept sibling-render-only. Operator decision needed. |
| **B-15** issuer metabox button stuck on «Отменить»; can't re-deactivate | Medium | **s12** | Deactivator (woodev_theme): clarify «Отменить» semantics for an already-delivered command; ensure re-deactivation is possible after a completed cycle + client reactivation. (Part of the "stuck" was a rig-only missing-ack — ack is synchronous in prod push.) |

## Technical Debt

### Big consistency review: @since annotations + long array() syntax (operator request, 12.06.2026)
- **What's wrong:** (1) v2-era code carries `@since 1.4.1` (and other stale versions) — the
  framework was bumped to 2.0.0 on 12.06.2026, every symbol ADDED during the v2 program
  must say `@since 2.0.0`; (2) agents keep writing `array()` where the project convention
  is short `[]` (CLAUDE.md → Coding Conventions) — the codebase is inconsistent.
- **Action when triggered:** one dedicated review session: (a) sweep `@since`/`@version`
  tags against git history (symbols introduced on the v2 branch → `2.0.0`); (b) convert
  long array syntax to `[]` across `woodev/` and `tests/`; (c) ENFORCE both going forward —
  add `Generic.Arrays.DisallowLongArraySyntax` to phpcs.xml so phpcbf auto-fixes and CI
  pins the convention (no more agent drift).
- **Why deferred:** pure mechanical churn — schedule between feature blocks; the phpcs rule
  makes the fix one `composer phpcbf` run plus a docblock sweep.
- **When:** operator-scheduled "большое ревью" session (after the deactivator deployment).

### PHPStan Baseline Cleanup — ✅ RESOLVED (s3)
- **Outcome:** the 50+ baselined errors were fixed/typed; `phpstan-baseline.neon` was **removed**. PHPStan runs at level 3 with no baseline. **Do not reintroduce a baseline** — fix errors at source.

### Deprecated Methods Removal — ✅ DONE (clean-break Phase 3, 2026-06-04)
- **Outcome:** the internal-API deprecation shims, the 2 `class_alias` files, and the legacy positional registration path were deleted (merged to `main`). Only 3 legitimate `_deprecated_function()` misuse-markers remain (api-base, lifecycle, payment-token); `register_plugin()` survives only as a B-1 mixed-fleet tombstone. Clean-break policy: ADR-005.

### Payment Gateway Trait Extraction — OPEN (grooming candidate, s13)
- **What's done:** `class-payment-gateway.php` works correctly.
- **What's missing:** main file is ~3,542 lines (whole `payment-gateway/` tree ~13.8k) — extract cohesive method groups (Refunds / Voids / Tokenization / Capture-Authorization / Customer-ID / Card-Types / CSC / Environments / Debug / Order-Meta / Settings) into PHP traits. Pure physical reorganization — no public signature/hook/contract change.
- **Why deferred:** large; functional priority over code organization. Run via the autodev-loop with a Codex critic.
- **When:** dedicated grooming session (operator-scheduled).

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

---

## Account ecosystem ideas — s26 (2026-06-21)

> Operator's ideas after #8 (install-from-connector) shipped + the connector went live on prod. **DEFER until v2 is fully done AND all Woodev plugins are rewritten onto v2** — premature before that. Recorded so they aren't lost. Likely the client-side counterpart of the dormant ecosystem-orchestration spec above.

### IDEA-1 — Connected-sites management in the user's account (woodev.ru ЛК)
- **Goal:** show the user their connected sites in the woodev.ru account, with a «Отключить» button to revoke a connection right from the ЛК (server side). Later (low priority): stats/graphs — when each site connected, which plugins were installed via the connector.
- **What's already done:** the connector's `woodev_account_connections` table already stores `user_id`/`customer_id`/`site_url`/`site_id`/`created`/`last_access` per connection — the data for the list exists. Disconnect = delete the row (`Connection_Store`).
- **What's missing:** account-UI to list+revoke (filterable, per operator); install-event logging for the future stats (new log).
- **Framework-side pairing (this repo):** when a connection is revoked server-side, the consumer still holds `woodev_account_data` → next signed request 401s. Teach `Woodev_Account_Connection` to detect a revoked/invalid connection (401/invalid-token) and **auto-clear the local state** (mirrors the remote-deactivation pattern), so the UI doesn't desync.
- **Where (server side):** woodev_theme `woodev-account-connector` — cross-ref its `docs/FUTURE-BACKLOG.md`.

### IDEA-2 (main) — Free "Woodev Plugins Updater" plugin = the framework-v2 hub/entry-point
- **Goal:** a free plugin that simply bundles framework v2+ and adds the "Woodev" admin section (Плагины/Лицензии/Мои покупки/install/updates) — a thin wrapper / hub, like the WooCommerce.com Helper or the EDD updater.
- **Why it matters:** today the "Woodev" section + account + catalog + install (#8) only appear if the user already has an active v2 plugin (the framework boots from a bundled copy). A new user with no Woodev plugin has no entry point — chicken-and-egg. The free Updater is the entry point: install it → connect account → browse catalog → install everything else from admin. It's also the natural home for account connection (connect once) and the future push-«Обновить».
- **What's already proven:** `.wp-env-stand/woodev-stand.php` is effectively this wrapper (thin plugin that bundles `woodev/` + registers a v2 loader). Productize it: free, no own license, **plugin-agnostic hub**.
- **Design points to settle:** the hub must render with ZERO feature-plugins active (catalog + account are already framework-level; the Лицензии page just aggregates — verify standalone); free → `is_need_license()` false; new `plugin_id` + free `download_id` on woodev.ru; multi-version bootstrap already picks the highest framework version → the Updater becomes the version-floor raiser for the whole fleet (a benefit).
- **Interlock:** IDEA-2 (hub) + push-«Обновить» (woodev_theme backlog) + IDEA-1 (ЛК site management) = one "Woodev account ecosystem"; likely the core of the dormant ecosystem-orchestration spec.
- **When:** after v2 ships AND all plugins are migrated onto v2 (operator's explicit gate).

### UK-CFR — Custom field/section renderers (settings extensibility)
- **Goal (operator s36/2026-06-29):** a plugin must be able to add ANY custom field or whole section to the settings page — not be limited to the framework's built-in control types. The framework must be open, not a closed component set.
- **Two levels:**
  - **(a) Custom field renderer** — for an unknown `controlType`, `control-field` asks a hook (`woodev.settings.controlRenderer` via `@wordpress/hooks` `applyFilters(null, controlType, {schema, value, onChange})`); the plugin returns its own React component. Cheap; covers any single custom field/widget.
  - **(b) Custom whole section** — for sub-CRUD widgets (СДЭК «Формирование упаковок» box table, Яндекс «Склады» table): the section declares a React component provider and the kit renders it INSTEAD of the field list; the plugin owns the table + its REST + add/edit/delete.
- **Why deferred (operator chose «separate cycle», not in #92):** design both levels together as one coherent API, ideally against a real consumer (when migrating СДЭК/Яндекс onto v2) so the contract isn't guessed. No real consumer yet (YAGNI).
- **When:** a dedicated mini-cycle (brainstorm → spec → impl) after the settings polish, or folded into the СДЭК/Яндекс pilot migration.

### SP-2-DEF — Secret wipe / disconnect affordance (deferred from SP-2, s38)
- **What's done (SP-2):** a `sensitive` field masks its stored value; replacing it = type a new value (force-sent via dirty-tracking). A `constant_name` field is always masked + read-only.
- **What's missing:** an explicit way to **clear a stored secret to empty** (i.e. disconnect / forget the credential). SP-2's first attempt at a «Очистить» button was removed in the rig fix-loop — it gave no visual feedback (the `is_set` placeholder stayed), its layout wrapped below the input, and the operator found it confusing. The functional path works (dirty-tracking sends `''`), but the UX was wrong.
- **Wanted:** a proper "Очистить сохранённое" / "Отключить" affordance with **confirmation** and a visible "значение будет удалено при сохранении" + «Отменить» state (the Отменить needs an app-level "remove a single edit" path so the untouched secret is preserved). Design it when a real carrier needs disconnect (the secret-rotation/disconnect UX is the real consumer).
- **When:** fold into SP-3 «поля» validation work OR the carrier pilot, whichever first needs it. Not blocking.

### SP-3 — Settings field validation (live + Save + server) — NEXT increment (s39 spec)
- **Operator-requested (s38):** `required` fields (+ star `<abbr>*</abbr>`), email/url/tel/number validation. **Decision #1 LOCKED:** live inline validation = **blur-first → live-clear-on-input once errored**; `required` validated on blur+Save (not on focus); color/date constrained by pickers (no live). Two tiers — client UX blocks Save, server is the authoritative per-field gate (client is bypassable — s31 enum lesson).
- **Open for the spec (s39):** `required` semantics per control type (toggle/select/multiselect — what is "filled"?), the REST save **per-field error contract** (response shape on invalid), and **wizard step-gating** (block «Далее» on an invalid step?). Applies to BOTH surfaces (settings + setup wizard). This is the roadmap's SP-3 «поля (классика)».
