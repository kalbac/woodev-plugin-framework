# Current State — Woodev Plugin Framework
> Last updated: 2026-06-12 (session 10: server half (woodev_theme) REVIEWED via 3-round GPT-5.5 critic — BLOCK→fixes→SHIP; deployed to prod + deactivator activated; plugin versions bumped. Framework untouched, green. **Open:** live command cycle on a framework-2.0.0 site — fresh session, wp-env stand (option A). Then edostavka-pilot (module audit first).)

## Session 10 (2026-06-12) — server half reviewed + deployed (cross-repo woodev_theme)
- **Reviewed the woodev_theme agent's 3-spec implementation** with a 3-round READ-ONLY GPT-5.5 critic (`codex exec`). Round 1 BLOCK (3 MAJOR: admin XSS via `innerHTML` of target-controlled push outcome; SSRF DNS-rebind; pull resurrecting a cancelled row + 3 MINOR + 2 NIT). Operator picked fixes; deactivator `17aeedf` (XSS→textContent, `wp_safe_remote_post`, CAS pull flip, `args:{}`, ack whitelist + terminal-row guard, `issue()` gating + `GET_LOCK`). Round 2 SHIP-WITH-NITS → delta fixes → Round 3 SHIP, no regressions.
- **Codex shell sandbox is broken on this Windows box** (`CreateProcessAsUserW failed: 5`) → ran the critic with an INLINE bundle (no shell). Reusable pattern for future codex reviews here.
- **Deployed (operator):** plugins FTP'd, woodev-plugins-deactivator activated; theme already v1.0.25 (admin update). **Bumps `16dbf0f`:** woodev-core 1.0.2, license-monitor 2.1.0, deactivator 1.0.1. Theme NOT bumped (I changed nothing in it).
- **E2e partial (prod):** pubkey field ✓, license metabox ✓ (buttons grey — no prod plugin on fw≥2.0.0 yet = correct gating), monitor columns+filter ✓, `framework_version`/`url` arriving ✓. **Open:** live issue→pull→ack→replay cycle needs a fw-2.0.0 site.
- All woodev_theme work is LOCAL (no remote); inner `woodev-theme/` repo released v1.0.25 to `kalbac/woodev-theme`. Per-file `git add` only (npm-cache junk in the tree).

## Session 9 continuation (2026-06-12) — cross-repo server half launched + v2.0.0 release
- **PR #35 (S3.3) + #36 (docs) merged**; operator standing rule: **self-merge any PR once GH Actions are green** — recorded in local memory `feedback_auto_merge_green_ci`.
- **PR #37 `fdde793`:** PRODUCTION `WOODEV_LICENSE_AUTHORITY_PUBKEY` captured from woodev.ru (`6N6HaUIrqZMuyDTYjvazMoQjpHwdeyLbmz5Zu3Fh2rM=`, recorded in the woodev-core signing spec) and embedded — pubkey test un-skipped, sodium suite zero skips. Key = standalone Ed25519 in option `woodev_license_authority_keys` (no SSL/domain ties; rotation invalidates all claims/commands).
- **PR #38 `a372a2a`:** `framework_version` default in `Woodev_Licensing_API::get_body()` — every licensing request reports the framework version (webhook-capability signal).
- **PR #39 `885232e` + tag `v2.0.0`:** VERSION 1.4.1 -> 2.0.0 (constant lagged the whole v2 program; also fixed the capability signal which reported 1.4.1). Release pipeline green, GitHub release published (Latest).
- **Server-half design locked with operator (D-PD1..D-PD7)** -> 3 specs committed in woodev_theme (`51c78d0`): standalone `woodev-plugins-deactivator` (queue/sign/push/pull/acks + EDD SL license-page metabox + per-site Deactivate button gated on webhook-capable && license-not-valid + 14-day freshness + pubkey display field), theme version-tracker companion (framework_version + last_seen meta), license-monitor companion (version columns, sorting, reason filter, site_url capture). Old woodev-core queue spec marked SUPERSEDED (wire contract still valid). A separate agent session in woodev_theme is implementing all three.
- **Backlog added (operator request):** big consistency review — `@since` sweep vs git history + `array()`->`[]` + `Generic.Arrays.DisallowLongArraySyntax` phpcs enforcement (FUTURE-BACKLOG -> Technical Debt).
- **Operator note for the edostavka pilot (after the deploy checks):** he doubts the shipping module is fully production-ready (his manual review found nuances) — the pilot session must START with a shipping-module audit against his findings list, not with migration.

## Autodev digest — 2026-06-11 (session 9: S3.3 built-in webhooks + §4 signing — autodev tasks s8-p1…p6; **PR #35 OPEN, CI green**)
- **Full mission from `next-session-prompt.md` executed:** plan first (`platform-v2-s3-licensing-webhooks-plan.md` — all 9 §9 BLOCKING protocol decisions resolved PRE-code + critic-round rulings recorded), then 6 autodev tasks in order **p1→p0→p2→p3→p4→p5** (p1 before p0: the verifier precedes its consumers; B-3 still lands before pull-fallback as mandated) + s8-p6 freeze/holistic. Worker subagents (opus p1/p0/p2, sonnet p3/p4/p5) → **GPT-5.5-high critic via `codex exec` read-only on EVERY diff and EVERY fix batch** (no self-certify) → commit. Every commit `composer check` green.
- **Shipped (branch `feat/s3-licensing-webhooks`):** (p1 `9592462`) shared Ed25519 envelope verifier + `woodev_normalize_site()` byte-locked to the woodev-core test vector, kid rule B-5, sodium fail-closed, CI ext-sodium pin + sentinel; (p0 `cdeea1d`) `is_license_required()` reads verified site-bound strict-bool claims (option `woodev_{id}_license_required`, 14-day grace, fail-closed) + **B-3** keyless updater polling + additive raw `url` param; (p2 `112eeb7`) atomic per-nonce `add_option` claim store + 11-step §9.4 pipeline (frozen reason vocabulary/HTTP map, `{n,t0}` rate limiter, zero-side-effect rejections); (p3 `31cf2f5`) public `POST woodev/v1/license-command` via `Woodev_REST_V1_Registrar` (auth IS the signature, uniform `{status:rejected, reason}` wire shape, reason whitelist); (p4 `4f9b638`) `deactivate_plugin` handler — D-W1 deactivate-only, multisite network-active reject, idempotent, persistent Russian notice + public hook `woodev_{id}_remote_deactivated` + log, ZERO license-state writes; (p5 `a88d0ab`) pull-fallback `license_commands[]` + structured acks (`consumed_command_nonces`, intersection-only `acks_received`, FIFO 50/30-day); (p6 `fdfaa8c`) spec §5 frozen table + mutation-verified `LicenseCommandContractParityTest`, sealed command vocabulary, tracker, mirror server spec → woodev_theme `a484067`.
- **Critic value (~12 real defects killed pre-merge):** kid-erasure signature bypass (`kid => null` collapsed with absent); `license_required` type-juggling unlock (signed `0`/`''` would have unlocked); IPv6-bracket normalization mutation breaking idempotence; cron-gate misalignment (filterable `wp_doing_cron()` vs `DOING_CRON` require → prod fatal); prune-on-rejection writes; rate-limiter TTL accumulation; mark_consumed retention clobber; non-vocabulary reason leak on the public wire; OPEN command registry (any plugin could replace the kill-switch handler → SEALED, no runtime registration); pull delivery gated off on the common path; inbound transport never writing acks; unconditional ack confirmation (lost-ack); claims dropped on authority-only updater responses; PHP-8 named args in a 7.4-matrix test.
- **Recorded rulings/deviations (plan "§9 protocol resolutions" + "Holistic-round rulings"):** raw `url` on the wire (server normalizes pre-signing — EDD contract untouched); lazy at-cap prune `store_full` exception; best-effort takeover + idempotent-vocabulary registry contract; soft nonce cap; carrier scope = `check_license` + updater `get_version` only; ack-store RMW race accepted (bounded by server redelivery).
- **Verification:** 600 unit tests / 2056 assertions (sodium run: exactly 1 by-design skip = production-pubkey test), 41/41 integration in wp-env, PHPCS + PHPStan L3 clean, PR #35 all checks green (`UNSTABLE` state = the release job's expected on-PR skip).
- **Operator steps pending:** (1) merge decision PR #35 (squash + delete-branch); (2) capture PROD `WOODEV_LICENSE_AUTHORITY_PUBKEY` post-deploy (wp-eval snippet in woodev-core signing spec) — placeholder keeps everything fail-closed; (3) woodev-core server half per `woodev_theme/docs/superpowers/specs/2026-06-11-woodev-core-license-command-queue-spec.md`.
- **Next:** after merge — S3 stage gate review; then S4 EDD or B-x backlog per tracker. Known follow-ups still open: `Abstract_Warehouse_Store::save()` wpdb return check; stale `.po` line markers.

## Autodev digest — 2026-06-11 (session 8: S3.2 modern license-page UI — autodev tasks s6-p1…p5; **PR #31 MERGED `f7d29f3`**)
- **Operator-directed full pipeline:** branch hygiene (stash WIP → rebase on main → green 281-test baseline) → `superpowers:writing-plans` over the approved spec → 5 atomic autodev tasks (worker→**GPT-5.5-high critic via `codex exec` read-only**→commit each; in-place fixes re-criticked, no self-certify) → holistic whole-feature critic → CI fixes → merge on operator decision. Plan: `platform-v2-s3-licensing-ui-plan.md` (locked decisions: static instance registry; reusable `Woodev_REST_V1_Registrar`; key-option write-before-dispatch legacy parity; deactivate keeps `_license_key`; unconditional `includes()` wiring; no `_n()`).
- **What shipped:** (p1 `19b9f5f`) pure ops `activate()/deactivate()/set_beta_enabled()/get_state()` on `Woodev_Plugins_License` + static registry; legacy `Woodev_Woocommerce_License_Settings` + `options.php` handlers DELETED (ADR-005 clean break; storage byte-for-byte, NO migration). (p2 `570ce6a`) `Woodev_REST_API_License` on `woodev/v1` via new reusable registrar — core `rest_api_init`, NOT WC-gated (B-7 closed). (p3 `45039b2`) `@wordpress/scripts` 32.4.0 scaffold + ADR-007 + classic-JSX-runtime babel override (automatic runtime needs WP ≥ 6.6; we support 6.3+). (p4 `dec115c`) React card-grid app (masked key + eye toggle, ConfirmDialog deactivate, beta toggle, license-free branch), enqueue/mount via `window.woodevLicenses` payload. (p5 `6e63c51`) CI `assets` build-parity job; release gated on it + src/ excluded from the zip.
- **Critic-caught real bugs (6):** stale `deactivate()` state (REST returned 'valid' after deactivation — re-instantiate `Woodev_License`); automatic JSX runtime breaking WP 6.3–6.5; stored-admin XSS via `RawHTML` (fixed at PHP boundary: `wp_kses_post()` in `get_state()`); React mutation race + lock leak (sync `useRef` lock, release-first finally); CI parity check fail-open (`|| true` masked git errors); release not gated on assets (stale-bundle release possible).
- **Post-merge CI fixes (`a6844dc`):** order-dependent Brain-Monkey function pollution (PlatformNeutral tests define `wp_date` earlier in the process → stub `wp_date`/`wp_timezone` in the helper, deliberately NOT `wc_*`; `@runInSeparateProcess` on 3 function-absence tests); integration auth assertion — anonymous gets **401** from `rest_authorization_required_code()`, not 403 (assert error code + status ∈ [401,403]).
- **Contracts:** option keys `woodev_{id}_license/_license_key/_beta_version`, EDD `edd_action` params + endpoint, hooks `woodev_license_saved/_deleted/woodev_enable_license_logging`, slug `woodev-licenses`, transient `woodev_extensions`, `WOODEV_LICENSE_DEBUG` — all pinned by parity tests with mutation spot-checks. Anti-pirate invariant intact (enforcement never reads `is_need_license()`).
- **Backlog closed:** B-7 (page works without WC — process-isolated tests + integration), B-8 (ADR-007 = the S5 React baseline), B-12 (`is_active()` three-meanings docblock).
- **Next:** S3.3 webhooks per the DRAFT spec's §9 blocking checklist (+ §4 Ed25519 signing, B-3 updater rework, capture woodev-core PROD pubkey). Deferred nits: two-real-plugins registry integration test; shared date-stub trait; Fast Refresh in babel config. Known follow-ups still open: `Abstract_Warehouse_Store::save()` wpdb return check; stale `.po` line markers.

## Autodev digest — 2026-06-10/11 (session 7: first Fable-5-orchestrator run — re-tiering, B-1, B-3/B-4/B-6, S3.3 draft; **PRs #26/#27/#28 MERGED**)
- **First session under `fable5-autodev-orchestrator-prompt.md`:** Fable 5 orchestrator, tiered workers (haiku/sonnet/opus via Agent tool), **critic = real GPT-5.5 high via `codex exec -s read-only`**. All work in an isolated git worktree on branches off fresh `main` (parallel S3.2 session owned the main tree — untouched). Operator fork: S3.2 skipped here; took the 4 items below. Details: SESSION-LOG session 7.
- **PR #26 `cb27f5b` (tooling):** per-task `model:` frontmatter → invoke-worker sub-ladder; contract-zone opus pin unchanged/overriding. Critic's BLOCK proven false-positive → SHIP.
- **PR #27 `101678e` (B-1 Critical, the hard gate before any production plugin rewrite):** mixed v1/v2 fleet WSOD armor — entry-template `method_exists` probe (dormant+notice) + `register_plugin()` tombstone (never calls the v1 callback; dedicated list; WP-core-only notice renderer). Critic caught 3 real bugs over 2 BLOCKs (renderer fataled without `Woodev_Helper`; `_n()` Russian-plural; CI-skipped purity detector) — all fixed; SHIP-WITH-NITS, nits applied. **`composer check` green: PHPCS 152/152, PHPStan 0, 281 tests / 852 assertions.** Gotchas [[russian-source-i18n-plural-n]], [[serena-index-vs-git-worktree]].
- **PR #28 `815e9de` (docs):** B-3/B-4/B-6 re-verified vs source, folded into S3.1 spec (§4.3 updater premise + impl task; §1.2 asymmetry; §4.2 `woodev_normalize_site` + multisite + vector case; §4.4 = 14 days) + mirrored into the woodev-core spec (**woodev_theme local commit `c0e275b`**, no remote). Plus **S3.3 webhooks spec DRAFT** (operator decisions D-W1..D-W4: deactivate-only, shared `woodev/v1/license-command`, pull-fallback in v1, diagnostics deferred; §9 = BLOCKING protocol-hardening checklist → s8 tasks; implement only AFTER S3.2 merges).
- **Next:** (1) parallel session lands S3.2 (license UI) — then rebase-check `is_active()` B-12 docblock; (2) s8 = S3.3 implementation per the draft's §9 checklist + §4 signing task (incl. the B-3 updater rework); (3) loop-tooling follow-ups: worktree-aware Serena guidance in `invoke-worker.ps1`, cheap-critic threshold, evidence-re-verdict round. Known follow-ups still open: `Abstract_Warehouse_Store::save()` wpdb return check; fixture local-dev path fallback resolves to `tests/` (masked by `WOODEV_FRAMEWORK_DIR` in wp-env).
## Autodev digest — 2026-06-10 (session 6: S3.2 modern license-page UI — design + spec, impl deferred)
- **Operator-directed brainstorm (superpowers:brainstorming + visual companion).** Picked the S3 fork = **A (modern license-page UI)**. No code this session — design only, by operator's choice to implement in a fresh session.
- **Locked decisions (operator-approved):** (1) **`@wordpress/scripts` + JSX** on native `@wordpress/components`, card-grid layout — first React surface of the framework, sets the S5 pattern; (2) **REST `woodev/v1`** on core `rest_api_init` (NOT WC-gated) + `wp.apiFetch`; (3) **clean-break: drop the legacy Settings form / `<noscript>` / `options.php` handlers** (internal plumbing, ADR-005) — **no migration**, REST reads/writes the SAME option keys; (4) stored-data contract preserved byte-for-byte (option keys, EDD, hooks, slug `woodev-licenses`); (5) masked key + eye-toggle. Operator challenged the back-compat `<noscript>` as overhead → agreed, dropped it; clarified no migration is needed (same storage).
- **Spec:** `docs-internal/platform-v2-s3-licensing-ui-spec.md` (§9 = 5 autodev tasks `s6-p1…p5`: pure-ops refactor + legacy removal; REST controller; wp-scripts scaffold; React app; CI build-parity + holistic critic). Committed `a4433f2` on `feat/s3-licensing-ui` (off fresh `main`).
- **Code surveyed (Serena):** `Woodev_Woocommerce_License_Settings::do_license_fields()`, `Woodev_Admin_Pages` (menu/enqueue), `Woodev_Plugins_License` (activate/deactivate/verify/get_state surface), `Woodev_REST_API` (WC-gated `register_routes` seam). No production code changed.
- **Feedback captured:** in AskUserQuestion, put the actually-recommended option FIRST (I led with a non-recommended option labelled "рекомендую" and misled the operator) → memory `feedback_recommended_option_first`.
- **Next:** new session — `superpowers:writing-plans` over the spec, then autodev-loop `s6-p1…p5`. Handoff in `next-session-prompt.md`. Known follow-up still open: `Abstract_Warehouse_Store::save()` wpdb return-value check.

## Autodev digest — 2026-06-10 (session 5: S3 Licensing sub-stage 1 — `is_need_license` safe-scaffold; **PR #25 MERGED `61006c3`**)
- **Operator-directed via the brainstorming→writing-plans→autodev pattern.** S3 decomposed into 3 sub-stages (operator decision); this session shipped **only sub-stage 1** (`is_need_license` flag + safe-scaffold). Branch `feat/s3-licensing-need-license` off fresh `main`.
- **Design (brainstormed with operator, PLANS §6 discussion format):** TWO-LAYER model. **L1** `Woodev_Plugin::is_need_license()` (default `true`) — **presentation only** (license-page block, nags, form-wrap, action-link, activate/deactivate submit handlers). **L2** `Woodev_Plugins_License::is_license_required()` (default `true`) — **enforcement authority**; `is_license_valid()`/`is_active()` short-circuit to true only when `! is_license_required()`. **Anti-pirate invariant:** the local L1 flag NEVER influences enforcement (a pirate setting it false gets clean UI only; features/updates stay server-gated). Full signing (Ed25519 server-signed `license_required` claim) **deferred** to a later cross-repo session — safe-scaffold keeps `is_license_required()` a literal `true` (no option read → no tamper vector), so behavior is byte-for-byte unchanged.
- **Specs:** framework client `docs-internal/platform-v2-s3-licensing-need-license-spec.md` + plan `...-plan.md`; **cross-repo woodev-core server spec written into `D:\Projects\woodev_theme\docs\superpowers\specs\2026-06-10-woodev-core-license-authority-signing-spec.md`** — already IMPLEMENTED by a parallel agent (woodev-core s126): resolved decisions = Ed25519, `plugin_id` = EDD download-id string, `licensing_enabled()` marker, 14-day window, `license_authority` envelope key, test vector published. Framework spec §4 reconciled to those values.
- **3 atomic tasks (autodev), each worker→adversarial-critic→commit:** s5-p1 (flag+seam, critic SHIP), s5-p2 (5 presentation sites; critic BLOCK → found a real `deactivate_license()` `wp_die(403)` on the still-rendered Save button for license-free plugins → fixed by short-circuiting activate/deactivate on the flag; re-critic SHIP-WITH-NITS → restored dropped docblocks), s5-p3 (cron outage-grace `try/catch (\Throwable)`; critic SHIP). **Holistic whole-feature critic: SHIP.**
- **`composer check` green: PHPCS 152/152, PHPStan 0, 275 tests / 847 assertions** (+12 tests over session 4's 265 — wait: 263→265 was s4; this session 269→275). Commits on branch: spec/plan/tasks docs, `feat(s3)` ×2, `fix(s3)` ×1, chore markers, gotcha.
- **New gotcha** [[license-need-vs-required]] — the L1/L2 naming trap.
- **Merged & verified:** PR #25 squash-merged to `main` (`61006c3`) after ALL GH Actions green (Unit 7.4–8.3, Integration WP 6.4/6.6/latest, Lint, PHP Compat, Markdown). **woodev-core server half** (Ed25519 signing) implemented by the parallel agent + reviewed here (php -l clean, code correct) and **committed locally in `D:\Projects\woodev_theme` (master, no git remote → not pushed)**.
- **Fable 5 architecture review (2026-06-10):** fresh-eyes review done (`docs-internal/reviews/fable5-architecture-review-2026-06-10.md`); 12 findings triaged into `FUTURE-BACKLOG.md` with trigger-stages (top-3 verified vs source — B-1 Critical mixed-fleet WSOD is a hard gate before the first plugin rewrite). **Autodev re-tiered (operator s5): orchestrator = Fable 5 high, workers = Haiku/Sonnet 4.6/Opus 4.8 by complexity, critic = GPT-5.5 high** — see `docs-internal/fable5-autodev-orchestrator-prompt.md`.
- **Next:** run the autodev-loop under the Fable orchestrator on the updated plan — S3 sub-stage 2 (modern license-page UI — React/`@wordpress/*`; do the B-8 React-baseline decision + B-7 acceptance-criterion first) or sub-stage 3 (webhooks §3.4.1, reuses the Ed25519 primitive); B-1 before any production migration. **Cross-repo follow-up:** capture the woodev-core PRODUCTION Ed25519 public key (`wp eval` snippet in the woodev-core spec) and embed it in the framework when sub-stage-1 signing (spec §4) is implemented. Known follow-up still open: `Abstract_Warehouse_Store::save()` wpdb return-value check.

## Autodev digest — 2026-06-10 (session 4: shipping pattern-conformance audit + remediation; **PR #24 MERGED `033368c`**)
- **Operator-directed via the autodev pattern** (Phase-1 audit done directly; scope brainstormed with operator; atomic specs queued; worker subagents wrote files; adversarial + holistic critic subagents stood in for GPT-5.5). Branch `feat/shipping-supports-predicates` off fresh `main`.
- **Audit (`docs-internal/reviews/shipping-pattern-conformance-audit-2026-06-10.md`):** `woodev/shipping-method/` vs Capability-Gated Feature Seam (wiki + ADR-006). **Overwhelmingly conforming, zero hard gaps.** Standalone subsystems (REST/AJAX/checkout/webhook/admin) = justified deviations (placement #2), left untouched. Two convention fixes: M7 predicate wrappers + P6 dead plugin-level `supports()`.
- **s4-p1 (`7287c89`):** added public `supports_box_packing()` / `supports_shipping_classes()` on `Shipping_Method`, routed the 4 raw `supports(self::FEATURE_*)` sites through them (aligns with payment-gateway). +2 tests. Internal-API only — no contract touched.
- **s4-p2 (`b1978e7`):** documented `Shipping_Plugin::supports()` as the host-facing plugin-scoped capability surface (docblock-only; no speculative constants per operator).
- **Reviews:** s4-p1 adversarial = SAFE-WITH-NITS (0 must-fix); whole-feature holistic = SHIP (0 must-fix).
- **`composer check` green: PHPCS 152/152, PHPStan 0, 265 tests / 827 assertions.** Commits `779ec6c` (audit+specs), `7287c89` (predicates), `b1978e7` (docs). PR #24 `MERGEABLE/UNSTABLE` (CI running).
- **Next:** merge after green GH Actions + operator decision. Known follow-up still open: `Abstract_Warehouse_Store::save()` doesn't check the wpdb return value.

## Autodev digest — 2026-06-09 (session 3: packing seam → real rate-calc; **PR open, not merged**)
- **Operator-directed via the autodev pattern** (design brainstormed + approved by operator; atomic specs queued; worker subagents wrote files; adversarial silent-failure-hunter + holistic code-reviewer agents stood in for the GPT-5.5 critic). Branch `feat/shipping-rate-packing-seam` off fresh `main`.
- **Packing woven into the rate flow (Variant B — single-seam template):** `Shipping_Method::calculate_rate()` is now a **final** concrete template — when the method supports `FEATURE_BOX_PACKING` it packs via `pack_package()` and hands the nullable `?\Woodev_Packer_Result` to a new abstract seam `rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate`. Framework owns ONLY the wiring; **per-parcel price aggregation stays the carrier's job** (no built-in summing — billing footgun for multi-place carrier tariffs). Migrated the 5 in-repo subclasses to the new seam. Internal-API rename only — zero installed-site contract touched. Gotcha [[shipping-rate-no-parcel-sum]].
- **Validation gate:** 3 wiring tests (parcels delivered / virtual-only→null / opt-in-off→null) + a multi-parcel end-to-end test; a `false` sentinel + `assertNotSame` guard proves `rate_package` actually ran.
- **Reviews:** P1 adversarial = SAFE (suggested `final`, adopted); whole-feature holistic = SHIP, zero must-fix (3 optional polish items adopted).
- **`composer check` green: PHPCS clean, PHPStan 0, 263 tests / 823 assertions.** Commits `063ba78` (spec+queue), `71f8969` (seam), `51bb97a` (gate), `bf3d7bd` (polish).
- **Next:** merge after green GH Actions + operator decision. Known follow-up still open: `Abstract_Warehouse_Store::save()` doesn't check the wpdb return value.

## Autodev digest — 2026-06-09 (session 2: dispatcher wiring + warehouse REST redesign; **PR #22 MERGED**)
- **Operator-directed** (worker agents for file-writing + adversarial silent-failure-hunter as the GPT-5.5-critic stand-in; this session did **not** drive the conductor loop). Three workstreams on top of merged S2.
- **Cleanup:** closed S2 queue (pending→done), resolved `s2-p2` escalation, synced auto-regen Serena config, ignored `.mcp.json`.
- **Box-packer dispatcher wired into production:** `Woodev_Packer_Dispatcher` + WC subclass + contract classes were never `require`d by `Woodev_Plugin::includes()` — they resolved only via the Composer test autoloader, so a real plugin calling the dispatcher in prod would fatal. Wired the 5 neutral files unconditionally + the WC dispatcher behind `Woodev_Helper::is_woocommerce_active()`. Added a `Shipping_Method` box-packing seam: opt-in `FEATURE_BOX_PACKING` + `packing_algorithm` instance setting + `pack_package()` / `get_packing_algorithm()`. Gotcha [[dispatcher-files-unwired-in-includes]].
- **rest-warehouses redesign — deferred `s1-p4` RESOLVED** (was parked for the React rework). Root cause: the `Warehouse` VO carried only the carrier id, so `Abstract_Warehouse_Store::save()` could never update (always insert — latent bug). Fix: VO gained a nullable `storage_id` distinct from the carrier `get_id()`; store stamps it from the PK in `get()/all()` and reads it in `save()`. Abstract controller rewritten — route `(?P<id>\d+)` = storage row id, body `code` = carrier id, **read-merge** update (no partial-overwrite data loss), 3 subclass seams (`get_additional_schema_properties` / `merge_additional_fields_into_data` / `prepare_additional_response_fields`) round-tripping carrier fields via the `raw` escape hatch; wired into `Shipping_Plugin::includes()`. Yandex-shaped fixture test proves table/column/namespace preservation. Gotcha [[warehouse-storage-id-vs-carrier-id]].
- **PR #22 MERGED to `main`** (squash). CI green across Unit PHP 7.4–8.3 + Integration (WP 6.4–latest × WC 8.5.1–latest). Getting there surfaced two CI lessons: a PR that conflicts with base (`mergeStateStatus: DIRTY`) does **not** run `pull_request` workflows — only `pull_request_target` — so CI silently never ran until I rebased onto `main` (PR #21 had been squash-merged → branch diverged); gotcha [[pr-conflict-skips-pull-request-ci]]. And the new reflection tests failed on PHP 7.4/8.0 without `setAccessible(true)` (gotcha [[reflection-setaccessible-version-guard]] recurred).
- **233 → 259 unit tests / 812 assertions.** PHPStan 0, PHPCS clean. Both feature changes passed adversarial review (SAFE, no blockers).
- **Known follow-up (rides in PR #22):** `Warehouse_Store::save()` doesn't check the wpdb insert/update return value — a failed UPDATE returns 200 with stale data (newly reachable now that update actually runs). Hardening deferred (would change the `save()` contract).
- **Next:** ✅ done in session 3 — packing seam brought to a real rate-calc flow (single-seam template). See the session-3 digest at the top.

## Autodev digest — 2026-06-09 (S2 box-packer complete; branch `autodev/loop-s2`; **PR #21 open**)
- **S2 complete: 3/3 tasks done.** P1 WC-neutral single-box, P2 minimal-virtual-box algorithm, P3 validation gate tests.
- **Two adversarial-critic-caught bugs fixed in P2:** (1) rsort breaks axis-name alignment for non-normalized items; (2) `$best=null` + `PHP_FLOAT_MAX` threshold → INF volumes never update `$best` → null dereference. Both fixed before commit. See gotchas below.
- **Key commits:** `031e9e9` (P1), `7abd7a4` (P2), `05deea8` (P3). `composer check` green.
- **PR #21 open to `main`.** Do NOT auto-merge — operator decides.
- **Next:** S3 TBD (operator defines). Deferred: `s1-p4-rest-warehouses` (warehouse id-conflation → React rework).

## PR #20 CI fixed — fully GREEN (2026-06-08, operator-directed; NOT merged)
> The PR's GitHub Actions had been failing. Investigated + fixed **only the CI failures**; the deferred `rest-warehouses` controller + pre-existing `.gitignore`/`.serena` working-tree changes were left untouched. Run `27110768183` all green. **Do NOT auto-merge** (operator decides).
- **Lint (`composer audit`)** `c640209`: `--no-dev` errors with zero runtime deps → `--locked`. This step had been failing identically on `main`, **gating/skipping the entire Unit Tests matrix** (skipped ≠ failed) — so Unit had never run on CI. Gotchas [[composer-audit-no-prod-deps]], [[ci-failing-gate-skips-dependent-jobs]].
- **Markdown Lint** `c640209`: 427 errors — the `**/*.md` glob covered not-published operational docs. Scoped the workflow glob to published `docs/` + root (excluded `.autodev/`, `docs-internal/`, `.serena/`, `.kiro/`, `AGENTS.md`); disabled MD051 (Cyrillic anchors). `.markdownlintignore` is ignored when globs are CLI args. Gotcha [[markdownlint-ignorefile-vs-globs]].
- **Integration (3 jobs)** `1422c1e`: v2 resolver loads each fixture's bundled `woodev/class-plugin.php`, but `.wp-env.json` mapped `./woodev` only at the `wp-content/plugins/*` mount, not the `tests/_fixtures/*` path the bootstrap loads from → added the mapping (both blocks). Superseded a non-working bootstrap-symlink attempt (`c6a18b1`; wp-env mount not writable at runtime). Gotcha [[wpenv-resolver-fixture-mapping]].
- **Unit cascade** (revealed once the audit fix unblocked the job; operator approved fixing): `5ea04fd` — yandex contract guards skip when gitignored `plugins-reference/` is absent; `format_percentage` fallback test → `@runInSeparateProcess` (Brain Monkey can't un-define `wc_format_decimal`, gotcha [[brain-monkey-function-pollution]]). `05db8a1` — 26 `ReflectionException` on 7.4/8.0: added `setAccessible(true)` at 18 sites across 9 files, guarded by `PHP_VERSION_ID < 80100` (deprecated on 8.5), gotcha [[reflection-setaccessible-version-guard]].
- **CI-fix commits:** `c640209` (ci), `1422c1e` (integration), `5ea04fd` (unit yandex+isolation), `05db8a1` (unit reflection). Local verified on PHP 8.5: composer check 203 tests, `composer audit --locked` clean, markdownlint 0.

## Autodev digest — 2026-06-08 (S1 complete + reviewed/remediated; branch `autodev/loop-bootstrap`; PR #20)
> Mirrored from `.autodev/digest.md` (autodev loop §7) — see it for full detail. SEPARATE workstream from S0/S1.
- **S1 shipping module functionally COMPLETE + holistically reviewed.** Queue: **done 33, active 1 (deferred), pending 0**. `composer check` GREEN (PHPCS, PHPStan 0, **203 tests / 638 assertions**). PR **#20** open to `main`.
- **Holistic integration review (GPT-5.5, `docs-internal/reviews/s1-holistic-integration-review-2026-06-07.md`) caught that p6 left the module UNWIRED** (per-task + green-gate blind spot). Remediated: **R1 `93a5be5`** (includes() + lifecycle registration + session→order pickup handoff; iterated under the critic across 4 rounds, each a distinct real bug — PHP-7.4 `?->`, data-loss field-unset, pre-save hook timing, unscoped pickup validation; 5th re-critic clean) and **R2 `07fa015`** (JS honors the AJAX success flag). The 3 deferred/by-design findings: warehouse id-conflation (deferred → React), REST namespace `get_id_dasherized()` (blessed), default-selection point shape (by-design).
- **Open escalations: 1 — `critic-s1-p4-rest-warehouses`, DEFERRED to the React rework** (operator decision 2026-06-07). The warehouse REST controller has a storage-row-id vs carrier-unique-id model conflation (critic 0.99) spanning Warehouse + Warehouse_Store + controller; a redesign, not a patch. Not committed; parked in `queue/active/` + working tree (worker impl in `runtime/` which is gitignored → local only).
- **3rd conductor bug fixed `b186c52`** (overnight): invoke-critic mis-read benign repo text as a 429 → discarded valid verdicts; gotcha `autodev-critic-ratelimit-false-positive`. (Prior bugs: critic-429 refund `61811b2`, gate-RETRY→pending `1e9914c`, gate file_set scoping `557126a`.)
- **Commits this session (2026-06-07):** overnight — `1f9224b` shipment, `4f52e66` admin-bootstrap, `73c0864` rest-bootstrap, `9df0885` rest-pickup, `e5a9e98` p5b (autonomous), `b186c52` conductor fix. Operator-decision + continuation — `85a99cc` ajax-base, `47b5e1c` admin-order, `62c1f20` status-view, `7f06a6c` warehouse-admin, `4975521` abstract-api, `8887ce0` p2-pickup-checkout, `e3e31ac` test-scaffold-extract (autonomous), `105c19f` p6-plugin-wiring (autonomous), `7a21e7d` fixture-yandex (validation gate). 5 of 16 S1 commits were fully autonomous gate-COMMITs; the rest were one-glance/operator-fix with each contract-adjacent fix re-run through the codex critic.
- **Validation gate landed:** `tests/unit/YandexPilotFixtureTest.php` proves a yandex-shaped plugin loads via the v2 path and preserves every yandex installed-site contract string (method ids, option key, REST ns `yandex-delivery`, warehouse table name, order-meta prefix, session key).
- **Earlier (2026-06-04/06) escalations all remain closed:** edostavka + yandex guards blessed; pickup-models/checkout-fields/pickup-selection/order-handler/webhook/tracking/method-enhance resolved.
- **2026-06-06 operator session** (conductor stopped; operator decided each item):
  - `gate-s1-p2-checkout-handler` → approve+commit `07d8f80` (4 new additive forward hooks; critic clean).
  - `poison-s1-p1-warehouse-store` → commit-existing `c23f241` (poison was a critic-429 infra misclassification, not bad code).
  - **Q3 conductor bug fixed** `61811b2`: refund the breaker attempt on a critic 429 (exit 4), symmetric with the worker 429 refund (`557126a`). Locked by `conductor.ps1 -SelfTest`. Gotcha `autodev-attempt-refund-symmetry`.
- **S1 landed so far:** P1 PVZ-map (pickup models/source/selection, warehouse store, map provider/js, address normalizer) + P2 checkout (fields + handler). P3+ in `queue/pending/`.
- Anti-drift: ON-TRACK — all diffs additive on the loop branch; no S0 files touched.

## Phase Status

| Phase | Code | Browser-verified | Notes |
|-------|------|------------------|-------|
| Framework Core | ✅ | ✅ | Bootstrap, Plugin base, Lifecycle — stable |
| Payment Gateway | ✅ | ✅ | class-payment-gateway.php: 2378 lines (was 3927, US payment cleanup complete) |
| Shipping Method | ✅ | ✅ | PSR-4 namespaced |
| Licensing | ✅ | ✅ | EDD store integration |
| Settings API | ✅ | ✅ | Typed settings framework |
| Box Packer | ✅ | ✅ | Shipping box-packing algorithm |
| REST API | ✅ | ✅ | Plugin REST routes |
| Documentation Structure | ✅ | — | Two-tier: docs/ (GH Pages) + docs-internal/ (AI agents) |
| Legacy Cleanup (v2.0.0) | ✅ | — | WP 6.3+/WC 7.0+ minimum gate complete; US-specific payment paths removed/isolated |
| PHPStan Baseline | ✅ | ✅ | 0 errors, baseline cleaned up with documented ignores |
| eCheck/ACH Removal | ✅ | ✅ | Active ACH API/direct transaction paths removed; deprecated false-return wrappers retained |
| eCheck/ACH Audit | ✅ | — | Audit done (s3): 14 files, 5-phase removal plan in wiki/echeck-ach-audit.md |

## P6 Gate Evidence — `Woodev_Plugin` "not a god-object"

- **Platform neutrality (audit §6.1.1):** the base `Woodev_Plugin` declares **zero**
  WooCommerce/HPOS-named methods. The last explicit HPOS base seam
  (`is_hpos_compatible()`) was removed during the P6 split-done audit follow-up.
  Late-safe WC admin/status hook
  registration lives in `Woodev\Framework\Woocommerce_Plugin::register_woocommerce_hooks()`,
  called from that subclass's own constructor. Early `before_woocommerce_init`
  feature declarations are wired by `Woodev_Plugin_Bootstrap::register_loader_definition()`
  from loader `supported_features` metadata so they cannot miss WooCommerce's
  early lifecycle hook, with the HPOS WC >= 7.6 guard matching runtime
  `Woocommerce_Plugin::is_hpos_compatible()`. Base-owned `Woodev_REST_API`
  now registers WC REST hooks only when WooCommerce is active. Enforced by
  `PlatformNeutralBaseHasNoWcMethodTest`, `PlatformNeutralRestApiTest`, and
  `BootstrapRegistrationTest::test_register_loader_definition_wires_early_woocommerce_feature_compatibility()`.
- **Base size (`woodev/class-plugin.php`), 2026-06-04:** **1,296 lines**,
  **77 methods declared on `Woodev_Plugin`** (58 public). Baseline before the
  handler extractions + seam removal was ~1,435 lines / ~87 methods.
- **Base size after P6 split-done audit follow-up, 2026-06-04:** **1,274 lines**,
  **74 methods declared on `Woodev_Plugin`** (56 public). Additional reduction came
  from removing the residual HPOS base method and returning the actual plugin main-file
  basename from `get_plugin_file()` instead of deriving `{directory}/{directory}.php`.
- **Construction shape (P4 Task 6):** `__construct()` is a clean ordered list of
  `init_*_handler()` / `load_*` calls ending with `add_hooks()`; `add_hooks()` wires
  only base-owned hooks (lifecycle `init_plugin`/`init_admin`, `load_updater`,
  enqueue, admin notices, plugin action-links, API request logging). No orphaned
  `add_action`/`add_filter` remained after the Translation/Cron handler extractions —
  no further tidy was required.

## Known Bugs (open)

- [⚠️] class-payment-gateway.php is 2378 lines — candidate for trait extraction
- [✅] **Independent audit 2026-06-01 — 3 release-blocker PHPStan-ignore masks** — all fixed 2026-06-02 (`95ae463` B-1a, `96cce09` B-1b, `6a1244c` B-1c)
- [✅] `Woodev_Plugin::get_woocommerce_uploads_path()` WC-leak — moved to `Woodev_Woocommerce_Plugin` with deprecation shim (`2817143` B-2, 2026-06-02)
- [✅] `Woodev_Plugin::get_blocks_handler()` typed-property trap — made property/return nullable (`2bd041b` B-3, 2026-06-02)
- [✅] PHP 8.4+ implicit-nullable deprecations in legacy payment handler files — explicit `?Type` added to 13 sites; test mask removed; `reportUnmatchedIgnoredErrors: true` enabled (`ef3d067` H1, 2026-06-02)
- [✅] 50+ PHPStan baseline ignores — cleaned up (s3)
- [✅] Woodev_Plugin_Dependencies::get_missing_php_functions() — fixed `4d00539`
- [✅] 47 deprecated methods total — removed `728c6f9`
- [✅] Woodev_Helper::get_post() non-existent method — fixed (s3)
- [✅] Woodev_Payment_Gateway::$voided_order_message dynamic — fixed (s3)
- [✅] eCheck/ACH payment type — removed (s3), `is_echeck_gateway()` returns false, deprecated
- [✅] Payment gateway base-method regression — v2 cleanup (`728c6f9`/`d85a1f9`) removed 28 still-called `Woodev_Payment_Gateway` methods (checkout/refund fatals), masked by a blanket PHPStan ignore — restored infrastructure methods + removed the blanket ignore (2026-05-31)

## Next Actions (priority order)

1. ~~Populate docs-internal/gotchas/~~ ✅ s2
2. ~~Fix get_missing_php_functions() bug~~ ✅ s2
3. ~~Clean up PHPStan baseline~~ ✅ s3
4. ~~eCheck/ACH audit + removal~~ ✅ s3
5. ~~Sandbox shipping runtime validation slice~~ ✅ 2026-05-31 — added a realistic
   file-based shipping fixture under `tests/_fixtures/woodev-realistic-shipping-plugin`
   plus `RealisticShippingFixtureTest`, proving explicit loader definition + WC gating +
   selected-framework early shipping base + include-based callback + `Shipping_Plugin` /
   `Woodev_Woocommerce_Plugin` inheritance against a realistic plugin shape.
6. ~~Sandbox payment runtime validation slice~~ ✅ 2026-05-31 — added a realistic
   file-based payment fixture under `tests/_fixtures/woodev-realistic-payment-plugin`
   plus `RealisticPaymentFixtureTest` (read-only `woodev-vkredit` cues), proving explicit
   loader definition + payment capability + WC gating + selected-framework early payment
   base + include-based callback + real `Woodev_Payment_Gateway_Plugin` construction +
   `Woodev_Woocommerce_Plugin` inheritance + concrete `Woodev_Payment_Gateway` gateway-class
   registration, against a realistic payment-plugin shape. No gateway is instantiated.
7. ~~**Independent audit 2026-06-01 — fix 3 release-blocker PHPStan-ignore masks**~~ ✅ 2026-06-02
    - (a) ✅ `95ae463` — B-1a: instanceof guards at `class-payment-gateway-hosted.php:440-452`; class-wide ignore removed
    - (b) ✅ `96cce09` — B-1b: split `Woodev_Box_Packer_Item` into base + `Woodev_Box_Packer_Item_With_Product` interface; ignore removed
    - (c) ✅ `6a1244c` — B-1c: narrowed `Shipping_API` to base framework contracts (`Woodev_API_Response`, `Woodev_API_Exception`, `Woodev_API_Request`, `WC_Order`); interface-scoped ignore removed
8. ~~**Independent audit 2026-06-01 — fix 2 base-class contract leaks**~~ ✅ 2026-06-02
    - (a) ✅ `2817143` — B-2: moved `get_woocommerce_uploads_path()` to `Woodev_Woocommerce_Plugin` with deprecation shim on base
    - (b) ✅ `2bd041b` — B-3: made `Woodev_Plugin::$blocks_handler` nullable with `= null` default; `get_blocks_handler(): ?Woodev_Blocks_Handler`
9. ~~**Independent audit 2026-06-01 — fix PHP 8.4+ deprecation mask**~~ ✅ 2026-06-02
    - H1 (`ef3d067`): added explicit `?Type` nullable annotations to 13 sites across 4 files (`class-payment-gateway.php`, `class-payment-gateway-my-payment-methods.php`, `handlers/abstract-hosted-payment-handler.php`, `handlers/abstract-payment-handler.php`); removed `error_reporting` mask in `RealisticPaymentFixtureTest.php:88-94`; enabled `reportUnmatchedIgnoredErrors: true` in `phpstan.neon:78`; also surfaced and removed dead `get_check_number` ignore (eCheck API was removed in s3)
10. ~~**Deferred / post-v2.0 (lower priority than the audit fixes):**~~ ✅ 2026-06-02 — 12 of 13 deferred audit items resolved in 6 commits (H2, H3, H4, M-2, M-3, M-4, M-5, L-1, L-2 [partial], L-3, L-5, L-6). Test count 177/369 → 188/406. Remaining:
    - **H2** ✅ `0d333eb` — `Framework_Resolver` constructor accepts `?callable $update_notice_renderer` + `?callable $deactivation_notice_renderer` (defaults no-op); `Woodev_Plugin_Bootstrap::__construct()` injects `[$this, 'render_update_notices']` / `[$this, 'render_deactivation_notice']`. Resolver no longer references `Woodev_Plugin_Bootstrap::instance()`. +3 tests.
    - **H3** ✅ `0d333eb` — `Framework_Resolver::load_plugins()` guarded by `$loaded` flag for one-shot-per-instance behavior in long-running WP-Cron/AS processes. +1 test.
    - **H4** ✅ `0d333eb` — `register_loader_definition()` + `register_legacy_plugin()` dedupe by `plugin_id` via `plugin_ids` map; second registration with the same id throws `RuntimeException`. +1 test.
    - **M-2** ✅ `89bd1ee` — `Woodev_Plugin_Bootstrap::is_woocommerce_active()` delegates to `Woodev_Helper::is_woocommerce_active()` (single source of truth).
    - **M-3** ✅ `67a1ab6` — `@since 2.0.0 Must be overridden by plugin subclasses; returns null/empty in base.` added to `get_documentation_url()`, `get_support_url()`, `get_sales_page_url()`.
    - **M-4** ✅ `e1c079a` — Moved `add_class_form_wrap_start()` and `add_class_form_wrap_end()` to `Woodev_Woocommerce_Plugin`. Base class retains deprecated shims using `_deprecated_function()` + `instanceof \Woodev\Framework\Woocommerce_Plugin` check. `tests/unit/AddClassFormWrapLocationTest.php` (3 tests).
    - **M-5** ✅ `67a1ab6` — Fixed mixed tabs/spaces indentation at lines 486, 615, 618, 619 in `class-framework-resolver.php` (phpcbf did not auto-detect; manual fix).
    - **L-1** ✅ `303f128` — `@version` docblock synced to 1.4.1 in `class-plugin.php`.
    - **L-2 (partial)** ✅ `c758ca0` — 4 of 5 recommended test coverage gaps added to `FrameworkResolverTest.php` (multi-version arbitration, `minimum_wp_version` legacy, resolver boundary negative, bootstrap delegation). The 5th (backwards_compatible window test) **deferred** — see #11.
    - **L-3** ✅ `c758ca0` — Created `docs-internal/wiki/v2-extension-point-pattern.md` documenting `add_woocommerce_hooks()` empty stub as positive pattern; updated `docs-internal/wiki/README.md` index.
    - **L-5** ✅ `303f128` — `Woodev_Lifecycle::install_default_settings()` comment rewritten to reflect platform-neutrality (no longer describes WC_Admin_Settings as the target).
    - **L-6** ✅ `303f128` — `get_framework_file()` docblock extended with multi-version arbitration note.
11. ~~**Polish session 2026-06-02 — M-1 + L-4 helper-class split + B-2 shim FQCN fix**~~ ✅ 2026-06-02 — 2 commits: `d703f8c` (B-2 FQCN) + helper split (in flight). Resolved the remaining audit lower-priority items the user prioritized for this session. Test count 188/406 → 194/426. Details:
     - **B-2 polish** ✅ `d703f8c` — `Woodev_Plugin::get_woocommerce_uploads_path()` shim now references FQCN `\Woodev\Framework\Woocommerce_Plugin` in both the `class_exists(...,false)` check and the delegate call. Previously the bare short name `Woocommerce_Plugin::class` resolved to the global-namespace `\Woocommerce_Plugin` (which does not exist), so the shim silently fell through to the inline `wp_upload_dir()` fallback. Added source-string regex test `WoocommerceUploadsPathLocationTest::test_base_shim_uses_fqcn_for_woocommerce_plugin()`. +1 test, +2 assertions.
     - **M-1 + L-4 helper-class split** ✅ — Created `woodev/class-woocommerce-helper.php` (`namespace Woodev\Framework; class Woocommerce_Helper`) with the 4 WC-coupled methods moved from `Woodev_Helper`: `get_order_line_items()`, `is_order_virtual()`, `shop_has_virtual_products()`, `render_select2_ajax()`. Created `woodev/class-woocommerce-helper-alias.php` providing the global-namespace `Woodev_Woocommerce_Helper` alias (mirrors `class-woocommerce-plugin-alias.php`). Replaced the 4 methods in `woodev/class-helper.php` with deprecated shims that emit `_deprecated_function()` and delegate to the new class (the FQCN, not the alias, so PHPStan resolves the static calls). The 2 shims for methods without WC_Order parameters include a `class_exists('\Woodev\Framework\Woocommerce_Helper', false)` guard for safe no-op in no-WC context. Updated `class-framework-resolver.php` to load the new helper+alias files alongside `class-woocommerce-plugin.php` (only when `$requires_woocommerce_base` is true). Updated the internal caller in `class-payment-gateway.php:2706` to use the FQCN directly. Added 2 new test files: `WoocommerceHelperLocationTest.php` (3 tests, 13 assertions) and `WoocommerceHelperShimTest.php` (2 tests, 5 assertions). Updated `PlatformNeutralHelperTest.php` to require the new files and to call the new class location. Added the 2 new files to `composer.json` classmap (PHPStan needs them; they don't comply with PSR-4 because the class lives in `class-woocommerce-helper.php` not `Woocommerce_Helper.php`, matching the existing `class-woocommerce-plugin.php` convention). +4 tests, +13 assertions (net from this commit, excluding the B-2 polish above).
     - **Defer helper shim test (render_select2_ajax branch)** — The render_select2_ajax shim's "no-op when class not loaded" branch is not tested in a dedicated test method because PHPUnit's `@runInSeparateProcess` does not give clean class-table isolation on Windows in this setup (the autoloader is inherited). The shim's behavior is covered indirectly: (1) the `class_exists` guard is visible in the source (regex test verifies the FQCN reference), (2) the shim's `_deprecated_function` call is verified, (3) the actual method's behavior is tested in `WoocommerceHelperLocationTest` when the class is loaded. Acceptable for a shim whose only logic is "emit deprecation + guarded delegate".
12. ~~**Deferred L-2 (backwards_compatible window test)**~~ ✅ 2026-06-04 — resolved during P3 audit fixes. `Framework_Plugin_Loader_Definition` now carries optional `backwards_compatible`, `Framework_Resolver::load_plugins()` keeps the selected highest-version framework record even when `Woodev_Plugin` is already loaded, and `FrameworkResolverTest::test_explicit_definition_backwards_compatible_window_blocks_too_old_frameworks()` covers the window in a separate process.
13. **P3 clean-break audit findings** ✅ 2026-06-04 — applied audit-packet findings: explicit `backwards_compatible` mapping restored, missing `main_class` loaders now become `invalid_loader_definitions` instead of silent no-ops, and `CAPABILITY_WOOCOMMERCE_PLUGIN` has base/helper-only preload coverage. `composer check` green: PHPCS 114/114, PHPStan 0 errors, PHPUnit 182/412.
14. **P4 decomposition audit follow-up** ✅ 2026-06-04 — applied `docs-internal/reviews/p4-decomposition-audit-packet.md` finding: `before_woocommerce_init` is no longer registered from `Woocommerce_Plugin::__construct()`; bootstrap wires early HPOS/Blocks declarations from loader `supported_features` metadata, while constructor keeps only late-safe WC admin/status hooks. `composer check` green: PHPCS 116/116, PHPStan 0 errors, PHPUnit 191/510.
15. **P6 split-done audit fixes** ✅ 2026-06-04 — applied cross-cutting findings from `docs-internal/reviews/p6-split-done-audit-packet.md`: base REST API no longer registers WC REST hooks when WooCommerce is absent; settings permission callbacks fall back safely if `wc_rest_check_manager_permissions()` is unavailable; `Woodev_Plugin::get_plugin_file()` now preserves the actual installed plugin basename; early HPOS declarations require WC >= 7.6; residual base `is_hpos_compatible()` removed. `composer check` passes (PHPCS 116/116, PHPStan 0 errors, PHPUnit 195/592).
16. (Deferred / post-v2.0) Extract traits from class-payment-gateway.php (2378 lines)
    and the broad `PLANS.md` vision: shipping universality, licensing webhooks/UI,
    box-packer minimal virtual box, DI/SOLID, React admin UI, EDD runtime.

## Session 2026-06-03 — Licensing v2 split (atomic 1 of 1)

**Result:** Clean v2 split of the only hard WC coupling in `woodev/licensing/`.
- New class `Woodev_Woocommerce_License_Settings` in `woodev/licensing/class-woocommerce-license-settings.php` (real implementation, 3 methods + constructor; picked up by existing classmap entry for `woodev/licensing/`).
- `Woodev_License_Settings` truncated to a deprecated shim: constructor assigns `$plugin` to a private property (silences PHPStan `unusedParameter`) and emits `_deprecated_function()` + `_doing_it_wrong()`. Class still resolves for any external `class_exists()` / `instanceof` check.
- `Woodev_Plugin::load_license_settings_fields()` now gates on `Woodev_Helper::is_woocommerce_active()` and instantiates the new class. Pure-WP plugins no longer pull in the `woocommerce_screen_ids` callback in `is_admin()`.
- New test `tests/unit/WoocommerceLicenseSettingsLocationTest.php` (3 tests, 14 assertions): reflection proves the new class declares all 3 methods, source regex proves the loader uses the FQCN + the `is_woocommerce_active()` gate, source regex proves the shim's constructor calls `_doing_it_wrong()`.
- `composer check` green: PHPCS 117/117, PHPStan 0 errors, **PHPUnit 197/440** (was 194/426; +3 tests, +14 assertions).

**Mapping reminder (for next session).** The other 4 licensing files (`Woodev_Plugins_License`, `Woodev_License`, `Woodev_License_Messages`, `Woodev_Licensing_API_Request`) either have no WC coupling or are already behind `function_exists()` + filter contracts from Phase 5 cleanup #9. No further clean v2 split surface remains in the licensing subsystem.

## Session 2026-06-03 — P2 pilot gate hardening

**Result:** P2 edostavka-shaped pilot fixture now validates the new load path more strictly.
- Fixed fixture include order so the concrete shipping method loads only after `Shipping_Plugin::__construct()` has included the framework shipping base classes; this prevents Composer test autoload from masking production include-order failures.
- Strengthened `EdostavkaPilotFixtureTest` with pre-load class absence assertions, an asserted `woocommerce_shipping_methods` filter registration, a direct `register_shipping_methods( [] )` assertion, and class-existence proof after the real callback path runs.
- Expanded `edostavka-data-preservation-checklist.md` with WooCommerce shipping-zone persistence (`woocommerce_shipping_zone_methods.method_id = edostavka`) and potential per-instance settings (`woocommerce_edostavka_{instance_id}_settings`) as release-blocking rewrite checks.
- `composer check` green: PHPCS 117/117, PHPStan 0 errors, **PHPUnit 198/450** (was 198/446; +4 assertions).

**Gate note.** P2 now better proves framework architecture/load-path readiness. It still does **not** prove live-site data preservation; that remains enforced per production plugin rewrite through the migration checklist.

### Platform v2 (strategy alignment)

| Step | Status | Artifact |
|------|--------|----------|
| 1 Dependency matrix | ✅ 2026-05-28 | `docs-internal/platform-v2-dependency-matrix.md` |
| 2 ADR bootstrap + plugin type | ✅ 2026-05-28 | `docs-internal/adr/001-*.md`, `002-*.md` |
| 3 Epic 1 spec (platform layer) | ✅ 2026-05-28 accepted | `docs-internal/platform-v2-epic1-spec.md` |
| 4 v2 cleanup #1–#2 gate | ✅ 2026-05-28 `f9fea5f` | WP 6.3+ / WC 7.0+; ACH/eCheck surface removed |
| 5 Spike branch | ✅ 2026-05-28 `0ed6df8` | `feat/platform-v2-epic1-spike` — Woodev_Woocommerce_Plugin + bootstrap metadata |
| 6 Strategy alignment | ✅ 2026-05-29 | `docs-internal/platform-v2-strategy-alignment.md` — hybrid roadmap, rewrite-first migration, minimal resolver |
| 7 Deep analysis | ✅ 2026-05-29 | `docs-internal/platform-v2-next-analysis.md`, ADR-003, ADR-004 — resolver, loader API, migration contracts |
| 8 Implementation spec | ✅ 2026-05-29 | `docs-internal/platform-v2-implementation-spec.md` — active source for resolver-first implementation |
| 9 PHP implementation | ✅ 2026-05-29 | Resolver facade + explicit loader definition slice implemented |
| 10 Platform class split | ✅ 2026-05-29 | Hook ownership, initial WooCommerce feature/Blocks state, system-status rows, WooCommerce logger, template loader, HPOS/Blocks feature declarations, and payment/shipping specialized inheritance moved to `Woodev_Woocommerce_Plugin`; remaining base items are compatibility wrappers or Phase 5 module cleanup |
| 11 Early class availability | ✅ 2026-05-29 | Payment/shipping early capabilities load WooCommerce base from selected framework copy; callback timing test proves specialized child classes can be declared inside plugin callback |
| 12 Phase 5 cleanup #1 | ✅ 2026-05-29 | Base-owned API, lifecycle, and licensing deprecated wrappers now use WordPress core deprecation helpers instead of WooCommerce wrappers |
| 13 Phase 5 cleanup #2 | ✅ 2026-05-29 | Settings API boolean and URL helpers now use local platform-neutral equivalents preserving `yes`/`no` storage and `http`/`https` validation contracts |
| 14 Phase 5 cleanup #3 | ✅ 2026-05-30 | Licensing helper slice now uses local platform-neutral equivalents for `wc_strtolower()`, `wc_print_r()`, and licensing API URL validation while preserving case-insensitive action checks, print_r-style request logging output, and `http`/`https` URL acceptance contracts |
| 15 Phase 5 cleanup #4 | ✅ 2026-05-30 | Lifecycle event history now uses a local platform-neutral recursive sanitization helper instead of `wc_clean()` while preserving stored event name/version/data cleaning semantics in a no-WooCommerce unit context |
| 16 Phase 5 cleanup #5 | ✅ 2026-05-30 | Plugin updater beta opt-in now uses a local platform-neutral boolean helper in `Woodev_Plugin` instead of `wc_string_to_bool()`, preserving the installed-site `beta_version` option key and WooCommerce-compatible truthy semantics in a no-WooCommerce unit context |
| 17 Phase 5 cleanup #6 | ✅ 2026-05-30 | Dependency PHP setting size parsing now uses a local platform-neutral byte conversion helper in `Woodev_Plugin_Dependencies` instead of `wc_let_to_num()`, preserving incompatible-setting detection and formatted notice payloads in a no-WooCommerce unit context |
| 18 Phase 5 cleanup #7 | ✅ 2026-05-30 | Admin notice dismiss JavaScript now queues through `Woodev_Helper::enqueue_js()` instead of `wc_enqueue_js()`, with footer print hooks registered by the helper so base-owned admin notices work in a no-WooCommerce unit context |
| 19 Phase 5 cleanup #8 | ✅ 2026-05-30 | Settings API error paths now use WordPress `_doing_it_wrong()` instead of `wc_doing_it_wrong()`, preserving register-setting and register-control failure messages in a no-WooCommerce unit context |
| 20 Phase 5 cleanup #9 | ✅ 2026-05-30 | Licensing date formatting now uses WordPress date formatting in `Woodev_License_Messages` instead of `wc_date_format()`, `wc_string_to_datetime()`, and `wc_format_datetime()`, preserving localized expiration-date message output in a no-WooCommerce unit context |
| 21 Phase 5 cleanup #10 | ✅ 2026-05-30 | Job batch handler inline JavaScript now queues through `Woodev_Helper::enqueue_js()` instead of `wc_enqueue_js()`, preserving the batch-handler payload and footer print-hook contract in a no-WooCommerce unit context |
| 22 Phase 5 cleanup #11 | ✅ 2026-05-30 | Setup wizard step-registration error reporting now uses WordPress `_doing_it_wrong()` instead of `wc_doing_it_wrong()`, preserving invalid-step diagnostics in a no-WooCommerce unit context |
| 23 Phase 5 cleanup #12 | ✅ 2026-05-30 | `Woodev_Helper::maybe_doing_it_early()` now falls back to WordPress `_doing_it_wrong()` when WooCommerce is unavailable while preserving the WooCommerce diagnostic path where `wc_doing_it_wrong()` exists |
| 24 Phase 5 cleanup #13 | ✅ 2026-05-30 | `Woodev_Helper::format_percentage()` now falls back to local decimal formatting when `wc_format_decimal()` is unavailable while preserving the WooCommerce decimal-helper path and trim/precision contract in a no-WooCommerce unit context |
| 25 Phase 5 cleanup #14 | ✅ 2026-05-30 | `Woodev_Helper::shop_has_virtual_products()` now returns `false` when `wc_get_products()` is unavailable, preserving published-virtual-product detection without fataling in a no-WooCommerce unit context |
| 26 Phase 5 post-review follow-up | ✅ 2026-05-30 | Licensing date formatting now preserves WooCommerce date-format filter and WordPress timezone semantics without hard WooCommerce dependencies; licensing request debug stringification preserves the WooCommerce `wc_print_r()`/fallback-filter contract; `wc_enqueue_js()` wrapper/filter difference accepted as non-atomic for this follow-up |
| 27 Phase 6 entry | ✅ 2026-05-30 | Created `docs-internal/platform-v2-migration-contract-template.md`; no first production plugin target is identified in this repo, so real plugin-specific contract work must wait for plugin selection/external repo context |
| 28 Phase 6A reference validation | ✅ 2026-05-30 | Read-only copied-plugin validation completed against `plugins-reference/woocommerce-edostavka` and `plugins-reference/woocommerce-yandex-delivery`; template refined for WC API callbacks, Action Scheduler groups/payloads, WC data-store keys, checkout/session state, shipping rate/package meta, email template paths, and legacy migration maps; no Phase 6B production migration started |
| 29 Phase 6A first reference draft | ✅ 2026-05-30 | Created `docs-internal/platform-v2-phase6a-edostavka-reference-contract-draft.md` as a reference-based, non-production, non-release-blocking draft that validates the template is fillable from copied plugin evidence while marking production repo / installed-site gaps explicitly |
| 30 Phase 6A second reference draft | ✅ 2026-05-30 | Created `docs-internal/platform-v2-phase6a-yandex-reference-contract-draft.md` as the second reference-based draft; confirmed the template works for a different plugin shape (custom DB tables, custom REST routes, AS recurring scheduling, WC session keys, checkout POST fields, localized script objects, competitor notes); no new framework-side template gap appeared |
| 31 Roadmap reconciliation | ✅ 2026-05-31 | Re-anchored on `PLANS.md`; verified P1–P5 complete in source (resolver/loader/`Woocommerce_Plugin`/specialized bases/tests/`composer check`); found no boundary-violating drift but a mild soft drift (Phase 6A is paper-only; new framework path unvalidated against a realistic plugin shape; sandbox copies still use the old framework). Corrected next category = sandbox-based framework readiness validation. See `docs-internal/platform-v2-roadmap-reconciliation.md` |
| 32 Sandbox shipping validation | ✅ 2026-05-31 | Added `tests/_fixtures/woodev-realistic-shipping-plugin` and `tests/unit/RealisticShippingFixtureTest.php`; read-only cues came from Edostavka/Yandex sandbox copies, but fixture stays framework-owned and generic. Verified explicit loader definition, WooCommerce requirement gate, selected-framework early shipping base, include-based callback/class graph, real `Shipping_Plugin` construction, and inheritance from `Woodev_Woocommerce_Plugin`; `composer check` passes (165 tests / 330 assertions). |
| 33 Sandbox payment validation | ✅ 2026-05-31 | Added `tests/_fixtures/woodev-realistic-payment-plugin` and `tests/unit/RealisticPaymentFixtureTest.php`; read-only cues came from `plugins-reference/woodev-vkredit` (entry constants, `register_plugin()` with `is_payment_gateway`, singleton plugin `extends Woodev_Payment_Gateway_Plugin`, `gateways` arg by class-name, concrete gateway `extends Woodev_Payment_Gateway_Hosted`, gateway loaded include-based). Fixture stays framework-owned and generic. Verified explicit loader definition, payment capability + WooCommerce gating, selected-framework early payment base availability, include-based callback graph, real `Woodev_Payment_Gateway_Plugin` construction (full `includes()` chain), `Woodev_Woocommerce_Plugin` inheritance, and concrete `Woodev_Payment_Gateway` gateway-class registration via `get_gateway_class_names()`. No gateway is instantiated (no payment runtime executed). `composer check` passes (166 tests / 338 assertions). |
| 34 Independent audit 2026-06-01 | ✅ 2026-06-01 | Second-model independent audit of `phpstan.neon` blanket ignores, `Woodev_Plugin` v2 split, payment-gateway restore, and resolver architecture. Surfaced 3 release-blocker PHPStan-ignore masks (Payment_Notification_Response class-wide, Box_Packer_Item::get_product, Shipping_API broken contract) + 2 base-class contract leaks (get_woocommerce_uploads_path WC-leak, get_blocks_handler typed-property trap) + 1 PHP 8.4+ deprecation mask (RealisticPaymentFixtureTest). All findings recorded as gotchas + prioritized in [Next Actions](#next-actions-priority-order) and detailed in `docs-internal/audit-2026-06-01.md`. No code changes — audit + docs only. `composer check` still passes (no PHP/runtime changes). |
| 35 P2 pilot gate hardening | ✅ 2026-06-03 | Hardened the edostavka-shaped pilot fixture/test after applying `docs-internal/reviews/p2-pilot-audit-packet.md` skeptically: no Composer-autoload include-order masking, asserted WC shipping-method hook registration, direct `register_shipping_methods()` contract assertion, and shipping-zone persistence added to the data-preservation checklist. `composer check` passes (198 tests / 450 assertions). |
| 36 P3 clean-break audit fixes | ✅ 2026-06-04 | Applied `docs-internal/reviews/p3-cleanbreak-audit-packet.md` findings: explicit `backwards_compatible` restored for loader definitions, selected framework record fixed when base class is preloaded, missing `main_class` no longer silently no-ops, and resolver coverage added. `composer check` passes (182 tests / 412 assertions). |
| 37 P4 decomposition audit follow-up | ✅ 2026-06-04 | Applied `docs-internal/reviews/p4-decomposition-audit-packet.md` finding: early WooCommerce HPOS/Blocks declarations now register from bootstrap loader metadata before `plugins_loaded`; `Woocommerce_Plugin` constructor keeps only late-safe WC admin/status hooks. `composer check` passes (191 tests / 510 assertions). |
| 38 P6 split-done audit fixes | ✅ 2026-06-04 | Applied the cross-cutting split sign-off findings: REST hook registration is WC-active gated, settings permissions have a no-WC-helper fallback, actual plugin main-file basename is preserved, early HPOS declaration matches the WC >= 7.6 runtime gate, and the residual base HPOS method was removed. `composer check` passes (195 tests / 592 assertions). |

## Planned — v2.0.0 & Beyond

> Detailed specs in `docs-internal/FUTURE-BACKLOG.md`

| # | Task | Category | Target |
|---|------|----------|--------|
| 1 | Bump WP/WC minimums (WP 6.3+, WC 7.0+) + remove deprecated compat code | ✅ Done | v2.0.0 |
| 2 | Remove unused US-specific payment types (echeck, Apple Pay, Google Pay) | ✅ Done | v2.0.0 |
| 3 | Push notifications & webhooks (server→client) | Feature | Post v2.0.0 |
| 4 | Shipping module boilerplate | Feature | Post v2.0.0 |
| 5 | React-oriented admin UI | Feature | Post v2.0.0 |
| 6 | Framework decoupling — support pure WP plugins + future EDD | Architecture | v2.0.0 |
| 7 | Cross-project ecosystem orchestration ("Оркестрация экосистемы Woodev") | Cross-Project | Post v2.0.0 stable |

> **v2.0.0 execution order:** #1 → #2 (cleanup legacy) → #6 (architectural split). Features #3–#5 post v2.0.0. **#7 is a cross-project initiative that unlocks only after v2.0.0 is shipped AND stable — see Cross-Project Reminders below.**

## 🔔 Cross-Project Reminders

> **For the agent reading this on session start:** if any item in this section is triggered, surface it in your session opening summary so Maksim is reminded.

### Post-v2.0.0 Trigger — Ecosystem Orchestration

- **Status:** dormant — waiting for Framework v2.0.0 to ship and stabilize
- **Trigger condition:** when v2.0.0 tasks #1, #2, #6 are all marked ✅ in the Phase Status table AND v2.0.0 has been live for several weeks without major regressions
- **What to remind Maksim about:** the concept spec **"Оркестрация экосистемы Woodev"** — system-wide automation across all Woodev projects (framework, ~12 plugins, woodev-theme, n8n automations, marketing/content). Goal: zero unnecessary human in the change-propagation flow
- **Spec location:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`
- **Why this lives in this project's docs:** Framework v2.0 is the gating prerequisite for the orchestration work. The reminder belongs where v2.0 progress is tracked
- **What the agent must do when trigger fires:**
  1. Mention the reminder in the session opening summary — do NOT bury it
  2. Do **NOT** auto-start implementation work
  3. Point Maksim to the spec file above and ask whether he wants to revisit it now
  4. If yes — read the spec's "Prompt for the Future Agent" section first (it has explicit anti-implementation instructions)
- **Cross-reference:** `FUTURE-BACKLOG.md` → "Cross-Project Initiatives" → #7

## Active Queue

> **2026-06-01 independent audit completed; all release-blocker items fixed 2026-06-02.**
> Commits `95ae463`, `96cce09`, `6a1244c`, `2817143`, `2bd041b`, `ef3d067` resolve every
> release-blocker finding (3 PHPStan-ignore masks, 2 base-class contract leaks, 1 PHP
> 8.4+ deprecation mask) and one dead PHPStan ignore surfaced as a side effect of
> enabling `reportUnmatchedIgnoredErrors: true`. `composer check` is green at 177 tests
> / 369 assertions, PHPStan 0 errors, phpcs clean. The audit prompt
> (`audit-2026-06-01-next-session-prompt.md`) is now obsolete and has been deleted.
>
> **Current boundary:** v2.0 release-blocking audit items are clear. Lower-priority
> findings (resolver edge cases, helper residual coupling, test coverage gaps, and the
> user's note on "what went off track" → see audit doc) remain documented for future
> sessions to plan against. Do not continue Phase 6A paperwork, do not start Phase 6B,
> do not edit `plugins-reference/`, and do not expand resolver/bootstrap scope until
> the lower-priority findings have been prioritized with the user.

## Infrastructure Reference

- **Framework version:** Woodev_Plugin::VERSION (in woodev/class-plugin.php)
- **PHP target:** 8.1 (composer platform)
- **WP minimum:** 6.3
- **WC minimum:** 7.0
- **Test framework:** Brain Monkey (unit) + WP Test Library (integration)
- **CI:** GitHub Actions (docs.yml, markdown-lint.yml, release workflow)
