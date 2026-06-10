# Platform v2 — Program Tracker (live)

> Sweep-across-the-whole-program status. Any session reads this first (per execution-protocol §0) to learn where we are. Update the "Next action" + statuses as work lands.

**Branch:** `main` (S0/S1/S2 all merged) · **Baselines:** `platform-v2-pivot-baseline`, `platform-v2-pre-refactor`
**Last updated:** 2026-06-10 (session 5)

## Next action
🏁 **S0 + S1 + S2 COMPLETE — all merged to `main`.** S1 shipping (PR #20), S2 box-packer (PR #21) + dispatcher production wiring & warehouse REST redesign (PR #22), packing woven into the rate-calc single-seam template (s3), and a shipping-module conformance audit vs the Capability-Gated Feature Seam pattern + `supports_*()` predicate alignment (s4, PR #24 `033368c`).

▶️ **S3 — Licensing: IN PROGRESS, decomposed into 3 sub-stages.** **Sub-stage 1 (`is_need_license` safe-scaffold) MERGED to `main` (PR #25 `61006c3`, all GH Actions green, 275 tests).** woodev-core server half (Ed25519) implemented + committed locally in woodev_theme (no remote). Two-layer model: L1 `is_need_license()` (presentation) + L2 `is_license_required()` (server authority, default-true seam); full Ed25519 signing **deferred** (server half already implemented in woodev-core s126; framework client signing is a later cross-repo session). Specs: `platform-v2-s3-licensing-need-license-spec.md` + `-plan.md`; server spec in woodev_theme. **Remaining sub-stages:** (2) modern license-page UI; (3) built-in webhooks (PLANS §3.4.1, reuses the Ed25519 primitive). Release-blocking: licensing option keys / activation state / instance ids / updater identity preserved byte-for-byte (safe-scaffold touched none — additive only).

## Stage map
| Stage | Scope | Status | Plan |
|---|---|---|---|
| **S0 Platform Split** | clean break + decompose base + minimal resolver | ✅ **DONE** (tag `platform-v2-split-done`, 195/592 green) | `platform-v2-cleanbreak-plan.md` (+ base-decomposition sub-plan) |
| **S1 Shipping** | universal module; PVZ-map abstraction first | ✅ **DONE** (merged to main PR #20 `440f238`, 2026-06-08; 203 tests green; 1 task deferred) | `platform-v2-s1-shipping-spec.md` |
| S2 Box-packer | minimal-virtual-box algorithm + neutral wrapper | ✅ **DONE** (PR #21/#22 merged; woven into rate-calc s3; shipping module conformance-audited + predicate-aligned s4 PR #24) | `platform-v2-s2-boxpacker-spec.md` |
| S3 Licensing | `is_need_license` → modern UI → webhooks | 🟢 **in progress** — sub-stage 1 (`is_need_license` safe-scaffold) done, PR open; sub-stages 2 (UI) + 3 (webhooks) remain | `platform-v2-s3-licensing-need-license-spec.md` (+ `-plan.md`) |
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

## Open follow-ups (out of current scope)
- `class-payment-gateway.php` (~2,378 lines) trait extraction — post-split debt.
- godaddy-fork study (Traits/Enums/Abilities, PLANS.md §4) — candidate GPT-5.5 research delegation before S1.
- **Test-scaffold duplication** (P2 code-review minor): `EdostavkaPilotFixtureTest` and `RealisticShippingFixtureTest` share a near-identical testable-resolver subclass + WP-stub helper. When a 3rd such fixture lands, extract a shared trait/base under `tests/unit/` instead of copying again.
- **i18n stale markers** (P3): `woodev/languages/*.po`/`*.pot` still reference the deleted `class-plugin-license-settings.php` line markers. Cosmetic (generated artifacts); regenerate via the i18n build at a convenient point.

## Related
- [platform-v2-execution-protocol.md](platform-v2-execution-protocol.md) — the rulebook
- [platform-v2-cleanbreak-plan.md](platform-v2-cleanbreak-plan.md) · [platform-v2-base-decomposition-subplan.md](platform-v2-base-decomposition-subplan.md)
- [CURRENT-STATE.md](CURRENT-STATE.md) — phase/bug detail
