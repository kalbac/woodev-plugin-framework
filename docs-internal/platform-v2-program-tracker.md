# Platform v2 — Program Tracker (history snapshot)

> **Last updated: 2026-08-09 (s60 docs audit; previous update was s13 — treat pre-s60 claims as history).**
> This is a **program-history snapshot**, not the live tracker. Live status = [CURRENT-STATE.md](CURRENT-STATE.md).

## Stage map (final)

| Stage | Scope | Outcome |
|---|---|---|
| S0 Platform Split | clean break + decompose base + minimal resolver | ✅ DONE (tag `platform-v2-split-done`, merged to `main` 2026-06-04) |
| S1 Shipping | universal shipping module; PVZ-map abstraction | ✅ DONE (PR #20, 2026-06-08) |
| S2 Box-packer | minimal-virtual-box algorithm + neutral wrapper | ✅ DONE (PR #21/#22; conformance-audited PR #24) |
| S3 Licensing | `is_need_license` → modern UI → webhooks (all 3 substages) | ✅ DONE (PR #25, PR #31, PR #35; frozen wire contract = `platform-v2-s3-licensing-webhooks-spec.md` §5, pinned by `LicenseCommandContractParityTest`) |
| S4 EDD | `Woodev_EDD_Plugin` | ⚪ deferred (as previously) |
| S5 React admin UI | built-in WP/WC React | ✅ DONE — 4 surfaces + UI-kit shipped s31–s41 (ADR-007) |
| S6 Ecosystem orchestration | cross-project automation | ⚪ deferred (as previously) |

## Active program

The active program since s32 is the **shipping SP-track (SP-1…SP-11)**:

- Authoritative map: [specs/2026-06-25-shipping-module-decisions.md](specs/2026-06-25-shipping-module-decisions.md)
- Live status: [CURRENT-STATE.md](CURRENT-STATE.md)

## Genuinely-open tails (pointers, not tasks)

- **#245** — production `WOODEV_LICENSE_AUTHORITY_PUBKEY` is still a placeholder in the envelope verifier (fail-closed until captured). **Release-blocking.**
- **#244** — 2 unfinished S0/P4 base-decomposition extractions (`Plugin_Action_Links_Handler`, `API_Logger`). The P4 gate passed on the WC-name-free criterion, but subplan Tasks 2/3 were never executed.
- **Payment-gateway trait extraction** — `class-payment-gateway.php` still ~3,542 lines; known debt per CLAUDE.md, no card.

## Decisions on record (unchanged)

- D-1 split-first; D-2 clean break + preserve data; D-3 pragmatic base decomposition; D-4 keep thin rendezvous; D-5 pilot = edostavka.
- Validation deviation (operator): the S0/P2 gate used an in-repo fixture, not a live edostavka rewrite; data preservation is enforced per-plugin at rewrite time via `migration/<plugin>-data-preservation-checklist.md`.

## Related

- [CURRENT-STATE.md](CURRENT-STATE.md) — live status
- [platform-v2-execution-protocol.md](platform-v2-execution-protocol.md) — the rulebook
- [specs/2026-06-25-shipping-module-decisions.md](specs/2026-06-25-shipping-module-decisions.md) — active-program map
- [platform-v2-direction-audit-2026-06-03.md](platform-v2-direction-audit-2026-06-03.md) — direction authority
