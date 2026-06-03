# Platform v2 — Program Tracker (live)

> Sweep-across-the-whole-program status. Any session reads this first (per execution-protocol §0) to learn where we are. Update the "Next action" + statuses as work lands.

**Branch:** `refactor/platform-v2-clean-break` · **Baselines:** `platform-v2-pivot-baseline`, `platform-v2-pre-refactor`
**Last updated:** 2026-06-03

## Next action
▶ **S0 / Phase 2** — pilot gate: build the edostavka-shaped fixture and drive it through the new load path; fill the data-preservation checklist. First external GPT-5.5 audit fires at this gate.

## Stage map
| Stage | Scope | Status | Plan |
|---|---|---|---|
| **S0 Platform Split** | clean break + decompose base + minimal resolver (+ `api/` under base) | 🟡 in progress | `platform-v2-cleanbreak-plan.md` (+ base-decomposition sub-plan) |
| S1 Shipping | universal module; PVZ-map abstraction first | ⚪ planned (spec at S0 gate) | — |
| S2 Box-packer | minimal-virtual-box algorithm + neutral wrapper | ⚪ planned (spec at S1 gate) | — |
| S3 Licensing | `is_need_license` → modern UI → webhooks | ⚪ planned (spec at S2 gate) | — |
| S4 EDD | `Woodev_EDD_Plugin` (concept in v2.0) | ⚪ deferred | — |
| S5 React admin UI | built-in WP/WC React | ⚪ post-v2.0 | — |
| S6 Ecosystem orchestration | cross-project automation | ⚪ post-v2.0 stable | — |

## S0 phase board
| Phase | What | Status | External audit |
|---|---|---|---|
| P0 | Branch + frozen baseline | ✅ done (197/197 green, tags set) | — |
| P1 | CLAUDE.md/AGENTS.md clean-break reconciliation | ✅ done (ADR-005 added; ADR-002 bridge superseded) | no (docs) |
| P2 | Pilot gate: edostavka-shaped fixture through new path | 🟡 next | **yes** |
| P3 | Delete internal-API back-compat debt (cohesive) | ⚪ | **yes** |
| P4 | Decompose `Woodev_Plugin` (sub-plan) | ⚪ | **yes** |
| P5 | Re-minimize resolver (ADR-003) | ⚪ | no (internal) |
| P6 | "Split done" gate | ⚪ | **yes** → tag `platform-v2-split-done` |

## Decisions on record
- D-1 split-first; D-2 clean break + preserve data; D-3 pragmatic base decomposition; D-4 keep thin rendezvous; D-5 pilot=edostavka.
- Validation deviation (operator): P2 gate uses an **in-repo fixture**, not a live edostavka rewrite → branch proves architecture, not live-data; data preservation enforced per-plugin at rewrite time.
- Review: external GPT-5.5 audit at key gates (P2/P3/P4/P6 + module gates); GPT-5.5 also = second opinion on contested design forks.

## Open follow-ups (out of current scope)
- `class-payment-gateway.php` (~2,378 lines) trait extraction — post-split debt.
- godaddy-fork study (Traits/Enums/Abilities, PLANS.md §4) — candidate GPT-5.5 research delegation before S1.

## Related
- [platform-v2-execution-protocol.md](platform-v2-execution-protocol.md) — the rulebook
- [platform-v2-cleanbreak-plan.md](platform-v2-cleanbreak-plan.md) · [platform-v2-base-decomposition-subplan.md](platform-v2-base-decomposition-subplan.md)
- [CURRENT-STATE.md](CURRENT-STATE.md) — phase/bug detail
