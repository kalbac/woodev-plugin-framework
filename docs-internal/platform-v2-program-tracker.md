# Platform v2 — Program Tracker (live)

> Sweep-across-the-whole-program status. Any session reads this first (per execution-protocol §0) to learn where we are. Update the "Next action" + statuses as work lands.

**Branch:** `feat/s3-licensing-webhooks` (S3.3 + §4 signing implemented, PR pending operator merge) · **Baselines:** `platform-v2-pivot-baseline`, `platform-v2-pre-refactor`
**Last updated:** 2026-06-11 (session 9 — S3.3 webhooks + §4 Ed25519 signing implemented on `feat/s3-licensing-webhooks`, PR open)

## Next action
🏁 **S0 + S1 + S2 COMPLETE — all merged to `main`.** S1 shipping (PR #20), S2 box-packer (PR #21) + dispatcher production wiring & warehouse REST redesign (PR #22), packing woven into the rate-calc single-seam template (s3), and a shipping-module conformance audit vs the Capability-Gated Feature Seam pattern + `supports_*()` predicate alignment (s4, PR #24 `033368c`).

✅ **S3.2 — modern license-page UI: MERGED to `main` (PR #31 `f7d29f3`, 2026-06-11, ALL GH Actions green).** All 5 autodev tasks s6-p1…p5 (worker→GPT-5.5-critic→commit each; holistic whole-feature critic passed): pure ops + legacy Settings-form transport deleted (`19b9f5f`), `woodev/v1` REST controller + reusable registrar (`570ce6a`), `@wordpress/scripts` scaffold + ADR-007 classic-JSX-runtime (`45039b2`), React card-grid app + enqueue/mount (`dec115c`), CI assets build-parity job (`6e63c51`), holistic fixes incl. REST nonce-auth integration test (`eb651ff`), CI-order pollution + 401/403 assertion fixes (`a6844dc`). Stored-data contracts byte-for-byte (parity tests pin option names/EDD params/hooks). 337 unit tests / 1107 assertions + integration `LicenseRestAuthTest`. Backlog B-7/B-8/B-12 closed.

✅ **S3.3 — built-in webhooks + §4 Ed25519 signing: IMPLEMENTED (s9, 2026-06-11), PR awaiting operator merge.** All 9 §9 protocol decisions resolved pre-code (plan "§9 protocol resolutions" + critic-round rulings); 6 autodev tasks s8-p1→p0→p2→p3→p4→p5 (worker → GPT-5.5-high critic per diff, every fix re-criticized) + s8-p6 freeze/holistic. Landed: shared Ed25519 envelope verifier + `woodev_normalize_site()` locked to the woodev-core test vector; `is_license_required()` consumes verified server claims (14-day grace, strict-bool, fail-closed) + B-3 keyless updater polling; atomic per-nonce command store + 11-step abuse pipeline; public `woodev/v1/license-command` (auth = signature, uniform wire shape); sealed `deactivate_plugin`-only vocabulary (D-W1); pull-fallback + structured intersection-confirmed acks (D-W3); contracts frozen in spec §5 + `LicenseCommandContractParityTest`. 600 unit / 41 integration tests green. **Operator steps pending:** (1) merge decision on the PR; (2) capture PRODUCTION `WOODEV_LICENSE_AUTHORITY_PUBKEY` (wp-eval snippet in the woodev-core signing spec) — placeholder keeps everything fail-closed until then; (3) woodev-core server half per new mirror spec `woodev_theme/docs/superpowers/specs/2026-06-11-woodev-core-license-command-queue-spec.md` (local commit `a484067`).

▶️ **S3 — Licensing: IN PROGRESS, decomposed into 3 sub-stages.** **Sub-stage 1 (`is_need_license` safe-scaffold) MERGED to `main` (PR #25 `61006c3`, all GH Actions green, 275 tests).** woodev-core server half (Ed25519) implemented + committed locally in woodev_theme (no remote). Two-layer model: L1 `is_need_license()` (presentation) + L2 `is_license_required()` (server authority, default-true seam); full Ed25519 signing **deferred** (server half already implemented in woodev-core s126; framework client signing is a later cross-repo session). Specs: `platform-v2-s3-licensing-need-license-spec.md` + `-plan.md`; server spec in woodev_theme. **Remaining sub-stages:** (2) modern license-page UI; (3) built-in webhooks (PLANS §3.4.1, reuses the Ed25519 primitive). Release-blocking: licensing option keys / activation state / instance ids / updater identity preserved byte-for-byte (safe-scaffold touched none — additive only).

## Stage map
| Stage | Scope | Status | Plan |
|---|---|---|---|
| **S0 Platform Split** | clean break + decompose base + minimal resolver | ✅ **DONE** (tag `platform-v2-split-done`, 195/592 green) | `platform-v2-cleanbreak-plan.md` (+ base-decomposition sub-plan) |
| **S1 Shipping** | universal module; PVZ-map abstraction first | ✅ **DONE** (merged to main PR #20 `440f238`, 2026-06-08; 203 tests green; 1 task deferred) | `platform-v2-s1-shipping-spec.md` |
| S2 Box-packer | minimal-virtual-box algorithm + neutral wrapper | ✅ **DONE** (PR #21/#22 merged; woven into rate-calc s3; shipping module conformance-audited + predicate-aligned s4 PR #24) | `platform-v2-s2-boxpacker-spec.md` |
| S3 Licensing | `is_need_license` → modern UI → webhooks | 🟢 **in progress** — sub-stage 1 MERGED `61006c3`; sub-stage 2 (UI) MERGED PR #31 `f7d29f3`; **sub-stage 3 (webhooks + §4 signing) IMPLEMENTED s9, PR awaiting operator merge** | `platform-v2-s3-licensing-need-license-spec.md`, `platform-v2-s3-licensing-ui-spec.md`, **`platform-v2-s3-licensing-webhooks-spec.md`** (+ `-plan.md`) |
| S4 EDD | `Woodev_EDD_Plugin` (concept in v2.0) | ⚪ deferred | — |
| S5 React admin UI | built-in WP/WC React | ⚪ post-v2.0 | — |
| S6 Ecosystem orchestration | cross-project automation | ⚪ post-v2.0 stable | — |

## S0 phase board
| Phase | What | Status | External audit |
|---|---|---|---|
| P0 | Branch + frozen baseline | ✅ done (197/197 green, tags set) | — |
| P1 | CLAUDE.md/AGENTS.md clean-break reconciliation | ✅ done (ADR-005 added; ADR-002 bridge superseded) | no (docs) |
| P2 | Pilot gate: edostavka-shaped fixture through new path | ✅ **gate PASSED** (`7ebbd20`+`6ed8b72`); internal reviews ✅; ext audit (GPT-5.5) applied — caught real include-order coupling, hardened | done |
| P3 | Delete internal-API back-compat debt (cohesive) | ✅ **gate PASSED** (`711cbae`,`7cc3666`,`4223597` + audit fixes); green 182/412; internal verify ✅; audit-packet findings applied | done |
| P4 | Decompose `Woodev_Plugin` (sub-plan) | ✅ **gate PASSED** (`dc4f661`,`9acb359`,`dd47b99`,`ae84d9d`); base WC-name-free 1296/77; ext audit caught+fixed HPOS-timing bug; green 191/510 | done |
| P5 | Re-minimize resolver (ADR-003) | ✅ done — resolver already minimal post-P3 (641 lines, all members ADR-sanctioned); responsibility table + no-extraction decision in ADR-003 | no (internal) |
| P6 | "Split done" gate | ✅ **gate PASSED** (`743e153`); holistic audit caught base-REST neutrality leak + plugin-file bug + is_hpos seam; green 195/592; **tagged `platform-v2-split-done`** | done |

## Decisions on record
- D-1 split-first; D-2 clean break + preserve data; D-3 pragmatic base decomposition; D-4 keep thin rendezvous; D-5 pilot=edostavka.
- Validation deviation (operator): P2 gate uses an **in-repo fixture**, not a live edostavka rewrite → branch proves architecture, not live-data; data preservation enforced per-plugin at rewrite time.
- Review: external GPT-5.5 audit at key gates (P2/P3/P4/P6 + module gates); GPT-5.5 also = second opinion on contested design forks.

## Fable 5 review — s7 execution (2026-06-11, first Fable-orchestrator session; PRs #26/#27/#28 MERGED)
- **B-1 (Critical) DONE — PR #27 `101678e`:** mixed-fleet WSOD hard-gate (entry-template probe + `register_plugin()` tombstone + `MixedFleetBootstrapGateTest`; 281 tests green). The pre-production-rewrite gate is closed.
- **Conductor re-tiering wired — PR #26 `cb27f5b`:** task frontmatter `model: haiku|sonnet|opus` → invoke-worker sub-ladder; contract-zone opus pin intact (critic side was already GPT-5.5).
- **B-3/B-4/B-6 folded into specs — PR #28 `815e9de`** (each re-verified vs source first); woodev-core spec mirrored (woodev_theme local commit `c0e275b`, no remote). Their *code* work stays tied to the §4 signing trigger.
- **S3.3 webhooks spec DRAFT** added (`platform-v2-s3-licensing-webhooks-spec.md`): operator decisions D-W1..D-W4 fixed (deactivate-only; shared `woodev/v1/license-command`; pull-fallback in v1; diagnostics deferred); §9 = BLOCKING protocol-hardening checklist for s8; **implementation only after S3.2 merges**.
- Critic transport = real **GPT-5.5 high via `codex exec` (read-only)** — caught 3 real B-1 bugs + 15 spec findings; one critic false-positive refuted with evidence (no self-certify both ways).

## Fable 5 architecture review (2026-06-10) + autodev model shift
- **Fresh-eyes architecture review done** (`docs-internal/reviews/fable5-architecture-review-2026-06-10.md`). 12 findings triaged into `FUTURE-BACKLOG.md` → "Fable 5 Architecture Review" with trigger-stages. **Operator: record now, fix per-trigger.** Top-3 (B-1 Critical mixed-fleet WSOD, B-2 loader-protocol forward-compat, B-3 keyless-updater premise) **verified against source**; B-4…B-12 re-verify before acting. **B-1 is a hard gate before the first production plugin rewrite ships.**
- **Autodev model re-tiering (operator decision s5):** orchestrator = **Fable 5 high**; workers/executors = **Haiku / Sonnet 4.6 / Opus 4.8** by task complexity; critic = **GPT-5.5 high** (later 5.6). Orchestrator prompt: `docs-internal/fable5-autodev-orchestrator-prompt.md`. Next autodev runs use this tiering.

## Open follow-ups (out of current scope)
- `class-payment-gateway.php` (~2,378 lines) trait extraction — post-split debt.
- godaddy-fork study (Traits/Enums/Abilities, PLANS.md §4) — candidate GPT-5.5 research delegation before S1.
- **Test-scaffold duplication** (P2 code-review minor): `EdostavkaPilotFixtureTest` and `RealisticShippingFixtureTest` share a near-identical testable-resolver subclass + WP-stub helper. When a 3rd such fixture lands, extract a shared trait/base under `tests/unit/` instead of copying again.
- **i18n stale markers** (P3): `woodev/languages/*.po`/`*.pot` still reference the deleted `class-plugin-license-settings.php` line markers. Cosmetic (generated artifacts); regenerate via the i18n build at a convenient point.

## Related
- [platform-v2-execution-protocol.md](platform-v2-execution-protocol.md) — the rulebook
- [platform-v2-cleanbreak-plan.md](platform-v2-cleanbreak-plan.md) · [platform-v2-base-decomposition-subplan.md](platform-v2-base-decomposition-subplan.md)
- [CURRENT-STATE.md](CURRENT-STATE.md) — phase/bug detail
