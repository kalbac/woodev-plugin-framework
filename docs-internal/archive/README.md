# Archive — Resolved Historical Documents

Documents moved here when they are no longer actively needed but provide
historical context worth preserving.

## Archive Rules

1. Move resolved/superseded docs here instead of deleting
2. Add a note at the top explaining why it was archived
3. Reference from SESSION-LOG.md when archiving
4. Files stay for at least 2 sessions before removal

## Archived s60 (2026-08-09 docs audit)

Platform-v2 program docs (program complete; tracker demoted to history snapshot):

- `platform-v2-cleanbreak-plan.md` — S0 clean-break plan complete/superseded 2026-06-04 (merged to main)
- `platform-v2-base-decomposition-subplan.md` — P4 gate passed (WC-name-free); 2 unexecuted extractions now issue #244
- `platform-v2-epic1-spec.md` — superseded by `platform-v2-implementation-spec.md`
- `platform-v2-roadmap-reconciliation.md` — its "out of v2.0" list entirely shipped; actively misleading as guidance
- `platform-v2-strategy-alignment.md` / `platform-v2-next-analysis.md` / `platform-v2-dependency-matrix.md` — absorbed into ADR-003/ADR-004 + specs
- `platform-v2-migration-contract-template.md` + `platform-v2-phase6a-reference-gap-analysis.md` + `platform-v2-phase6a-edostavka-reference-contract-draft.md` + `platform-v2-phase6a-yandex-reference-contract-draft.md` — phase complete; mechanism demoted to per-plugin checklists in `migration/`
- `platform-v2-s1-shipping-spec.md` / `platform-v2-s1-shipping-queue-manifest.md` — S1 merged 2026-06-08 (PR #20)
- `platform-v2-s2-boxpacker-spec.md` / `platform-v2-s3-shipping-rate-packing-spec.md` — S2 merged (PR #21/#22; rate-calc weave s3)
- `platform-v2-s3-licensing-need-license-plan.md` / `-spec.md`, `platform-v2-s3-licensing-ui-plan.md` / `-spec.md`, `platform-v2-s3-licensing-webhooks-plan.md` — S3 substages merged 2026-06-08..11 (PR #25/#31/#35). The webhooks **spec** stays in place (frozen §5 wire contract, pinned by `LicenseCommandContractParityTest`)
- `s1-shipping-spec-planning-brief.md`, `autodev-loop-implementation-prompt.md`, `fable5-architecture-review-prompt.md`, `fable5-autodev-orchestrator-prompt.md` — one-shot prompts, all consumed
- `2026-06-27-ui-kit-component-inventory.md` (from `research/`) — UI-kit complete s41

Reviews (`reviews/` → `archive/reviews/`; findings all triaged/closed):

- `reviews/autodev-loop-review-2026-06-11.md`
- `reviews/fable5-architecture-review-2026-06-10.md` — B-1..B-12 triaged; B-1 fixed PR #27
- `reviews/ob3-plugin-updater-review-2026-06-14.md`
- `reviews/remote-deactivation-ux-findings-2026-06-13.md` — B-13/14/15 resolved s10–s12

Shipped-work plans (`plans/` → `archive/plans/`; executed and merged):

- `plans/2026-06-18-license-page-redesign.md`, `plans/2026-06-18-plugins-page-ob7-redesign.md`, `plans/2026-06-19-account-connection-client.md`, `plans/2026-06-20-purchases-tab-and-badge.md`, `plans/2026-06-21-competitor-notification.md`, `plans/2026-06-21-plugin-type-autoloader.md`, `plans/2026-06-22-setup-wizard.md`, `plans/2026-06-23-setup-wizard-ui-implementation.md`, `plans/2026-06-26-sp1-settings-page-plan.md`, `plans/2026-06-27-uk1-ui-kit-foundation-plan.md`, `plans/2026-06-29-sp2-secrets-auth-plan.md`, `plans/2026-06-30-sp3-field-validation-plan.md`, `plans/2026-07-01-sp3-polish-plan.md`, `plans/2026-07-05-conditional-fields-plan.md`, `plans/2026-07-06-checkout-field-layer-plan.md`
- SP-5 map iterations: `plans/2026-07-30-sp5-pickup-map-plan.md`, `plans/2026-08-01-sp5-pickup-map-rework-plan.md`, `plans/2026-08-03-sp5-pickup-map-visual-rework-plan.md`, `plans/2026-08-05-sp5-pickup-map-live-review-fixes-plan.md` — shipped; PR #149 merged s51, their "do not merge" banners are obsolete

Shipped-work specs (`specs/` → `archive/specs/`; executed and merged):

- `specs/2026-06-18-license-page-ui-ux-redesign.md`, `specs/2026-06-18-plugins-page-ob7-redesign-design.md`, `specs/2026-06-19-account-connection-client-design.md`, `specs/2026-06-20-account-install-from-connector-design.md`, `specs/2026-06-21-competitor-notification-design.md`, `specs/2026-06-21-plugin-type-autoloader-design.md`, `specs/2026-06-22-setup-wizard-design.md`, `specs/2026-06-23-setup-wizard-ui-design.md`, `specs/2026-06-26-sp1-settings-page-design.md`, `specs/2026-06-27-ui-kit-design.md`, `specs/2026-06-29-sp2-secrets-auth-design.md`, `specs/2026-06-30-sp3-field-validation-design.md`, `specs/2026-07-05-conditional-fields-design.md`, `specs/2026-07-06-checkout-field-layer-design.md`
- SP-5 map iterations: `specs/2026-07-30-sp5-pickup-map-design.md`, `specs/2026-08-01-sp5-pickup-map-rework-design.md`, `specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — shipped; PR #149 merged s51

## Related

- [[../SESSION-LOG.md]] — session history
- [[../DOCS-SCHEMA.md]] — doc format rules
