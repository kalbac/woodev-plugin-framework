# Docs Index — Woodev Plugin Framework
> Navigation hub for AI agents — open it when you need to find a document, not at session start.
> `docs-internal/` — internal technical documentation (not published).
> Freshness: `git log -1 --format=%ad --date=short -- <file>`.

---

## Session start and end

**The checklists live in `AGENTS.md` → "Session Start" / "Session End" only** — it starts with git sync, then
`next-session-prompt.md`, not with this file. The mirrored copies that used to sit here had drifted
from it (s135 audit), so they were removed rather than re-synced.

(`platform-v2-program-tracker.md` is a **program-history snapshot**, not a session-start read.)

---

## Operational Docs (live)

| File | Purpose |
|------|---------|
| `next-session-prompt.md` | Prepared entry prompt for the next session — read first |
| `CURRENT-STATE.md` | Live status: phase/track state, open bugs, next actions — read every session start |
| `SESSION-LOG.md` | Index of sessions — one line each, newest at top |
| `sessions/sNN.md` | Per-session detail — the full write-up |
| `GOTCHAS.md` | Topic map (count in its header) → `gotcha-index/{topic}.md` one-line indexes → `gotchas/{slug}.md` |
| `AGENT-RULES.md` | Workflow + architecture rules (Rule 0 = clean-break policy / ADR-005) |
| `DOCS-SCHEMA.md` | Doc format rules, lint checklist, compilation protocol |
| `platform-v2-execution-protocol.md` | Operating rulebook + resume protocol + authority chain |

## Repository map

```
woodev_framework/
├── CLAUDE.md                        # ← Entry point for Claude Code agents
├── AGENTS.md                        # ← Entry point for all other AI agents
├── website/                         # Public docs → Astro Starlight (English), GitHub Pages
│   ├── astro.config.mjs             #   site config + explicit sidebar
│   ├── src/content/docs/            #   pages: getting-started/, core/, settings/, shipping/, payments/, reference/
│   └── plugins/                     #   build-time %%FRAMEWORK_VERSION%% replacement
├── docs-internal/                   # Internal docs → AI agents only
│   ├── next-session-prompt.md       # Per-session handoff — every session starts here
│   ├── CURRENT-STATE.md, SESSION-LOG.md (index), GOTCHAS.md (topic map)
│   ├── sessions/                     # Per-session detail files
│   ├── AGENT-RULES.md, DOCS-INDEX.md, DOCS-SCHEMA.md, FUTURE-BACKLOG.md (frozen)
│   ├── gotcha-index/                # One-line gotcha indexes, one file per topic (mapped by GOTCHAS.md)
│   ├── gotchas/                     # Atomic gotcha detail files
│   ├── adr/                         # Architecture Decision Records
│   ├── wiki/                        # Compiled topic references
│   ├── specs/                       # Feature specifications
│   ├── plans/                       # Implementation plans
│   ├── research/                    # Research notes
│   ├── reviews/                     # Review reports
│   ├── migration/                   # Per-plugin v2 migration docs
│   └── archive/                     # Resolved historical docs
├── woodev/                          # Framework source code
│   ├── bootstrap.php                # Singleton bootstrap loader
│   ├── class-plugin.php             # Woodev_Plugin abstract base (VERSION here)
│   ├── class-lifecycle.php          # Install/upgrade lifecycle
│   ├── class-helper.php             # Static utility helpers
│   ├── admin/                       # Admin pages
│   ├── api/                         # HTTP API base classes
│   ├── account/, assets/, competitor/ # Account page, shared assets, competitor notice
│   ├── box-packer/                  # Box packing algorithm
│   ├── compatibility/               # HPOS + WooCommerce compat
│   ├── error-reporting/             # Error reporter (opt-in)
│   ├── handlers/                    # Blocks + script handlers
│   ├── http/, languages/            # HTTP helpers, translation catalogues
│   ├── licensing/                   # License key + EDD store integration (incl. updater/ — plugin update mechanism)
│   ├── payment-gateway/             # Payment gateway base classes (~13.9k lines; main file ~3.6k)
│   ├── rest-api/                    # REST API routes
│   ├── settings-api/                # Typed settings framework
│   ├── settings-page/, setup/       # woodev-settings page, setup wizard engine
│   ├── shipping-method/             # Shipping plugin + method bases
│   └── utilities/                   # Async requests, background jobs
├── tests/
│   ├── unit/                        # Brain Monkey + Mockery (no WP needed)
│   ├── integration/                 # WP_UnitTestCase (wp-env)
│   └── _fixtures/                   # 9 fixture plugins (+ data fixtures)
├── .ai/                             # AI agents and skills
│   ├── agents/                      # 5 sub-agents
│   ├── skills/                      # 7 skill directories
│   └── QUICK-REFERENCE.md
└── .github/workflows/               # CI: ci.yml, dependabot-auto-merge.yml, docs.yml,
                                     #     integration-tests.yml, markdown-lint.yml, pr-triage.yml
```

CI workflows: `ci.yml` (jobs: `version`, `lint`, `compat`, `unit-tests`, `test-js` — jest, added s55 PR #184, `secrets` — gitleaks, `assets` — build parity, `release`), `dependabot-auto-merge.yml`, `docs.yml`, `integration-tests.yml`, `markdown-lint.yml`, `pr-triage.yml`.

---

## Tech stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Language | PHP | 7.4–8.x (platform: 8.1) |
| WordPress | WP API | ≥6.6 |
| WooCommerce | WC API | ≥7.0 |
| Testing (unit) | Brain Monkey + Mockery | ^2.6 / ^1.6 |
| Testing (integration) | WP PHPUnit + wp-env | ^6.9 |
| Linting | PHP_CodeSniffer (WPCS) | ^3.9 / ^3.1 |
| Static analysis | PHPStan | ^1.12 (level 3) |
| Docs build | Astro Starlight | see `website/package.json` (Node 22.22+) |
| Changelog | git-cliff | latest |
| CI/CD | GitHub Actions | — |
| Composer | PHP dependency manager | ^2 |

---

## Architecture & Direction

| File | Purpose |
|------|---------|
| `platform-v2-direction-audit-2026-06-03.md` | Historical execution audit; D-1…D-5 retained as decisions in the ADRs / execution protocol |
| `platform-v2-implementation-spec.md` | Historical pre-implementation design; current resolver/loader reference = ADR-003/004/005 + `wiki/architecture.md` |
| `platform-v2-program-tracker.md` | **Program-history snapshot** (v2 program S0–S6, rewritten s60) — live status is `CURRENT-STATE.md` |
| `platform-v2-s3-licensing-webhooks-spec.md` | S3.3 webhooks + Ed25519 signing — §5 is the FROZEN wire contract, pinned by `LicenseCommandContractParityTest` |

## Specs / Plans / Research (live dirs — what remains after the s60 sweep)

> A design document stays here after its work ships: it records WHY the shape is what it is, and the
> `Shipped` column says which ones that applies to. No spec count is written here — the counts went
> stale twice (s104, s210); `ls specs/` is the count.

| Spec | Purpose | Shipped |
|------|---------|---------|
| `specs/2026-06-25-shipping-module-decisions.md` | **Authoritative map of the active shipping SP-track (SP-1…SP-11)** — program-level decisions locked with the operator | live map |
| `specs/2026-08-06-sp5-pickup-selection-mechanism-design.md` | SP-5 pickup-point selection mechanism | ✅ |
| `specs/2026-08-09-238-cart-change-verdict-invalidation.md` | #238 — wire cart-change verdict invalidation to a real cart-change signal; supersedes the issue's own framing on two points | ✅ |
| `specs/2026-08-09-sp5-viewport-point-accumulation-design.md` | SP-5 viewport point accumulation (#234) | ✅ |
| `specs/2026-08-10-embedded-map-provider-adapter-seam.md` | #251 — make the embedded carrier-widget seam reachable and prove it with a real carrier | ✅ |
| `specs/2026-08-11-sp5-pickup-selection-persistence-design.md` | SP-5 — pickup selection persistence across checkout requests (#176) | ✅ |
| `specs/2026-08-12-location-provider-design.md` | The Location Provider layer — the provider seam itself | ✅ |
| `specs/2026-08-15-location-chain-design.md` | The location chain (#334 + #330) — both cards share one root | ✅ |
| `specs/2026-08-18-location-and-field-settings-brainstorm-input.md` | INPUT to a brainstorm, deliberately not a design — read it as the question, not the answer | n/a |
| `specs/2026-08-18-shipping-settings-v2-design.md` | The «Доставка» tab: Location / Fields / Map | ✅ |
| `specs/2026-08-21-settlement-search-design.md` | Settlement search replacing the preset list — decision 1 shipped in s109, the rest is NOT part of #437 | partial |
| `specs/2026-08-24-popular-settlements-design.md` | Popular settlements — where the list lives and how it is scoped | ✅ |
| `specs/2026-08-25-shipping-tools-section.md` | The «Инструменты» section of the «Доставка» tab | ✅ |
| `specs/2026-09-07-sp10-orders-page-design.md` | SP-10 «Заказы доставки» — the orders page: columns, filters, actions left open by #694 | ✅ |
| `specs/2026-09-27-710-create-edit-order-design.md` | #710 «Создать заказ» / «Редактировать» — admin order wizard; operator’s brainstorm s141, increments I0–I8 | ✅ |
| `specs/2026-10-04-error-reporter-design.md` | Framework PHP error reporter (#130) — consent, scope, anonymity, rate limits | ✅ |
| `specs/2026-10-04-sp11-block-checkout-design.md` | SP-11 — Checkout Blocks location and pickup adapter (#1078, decisions #1086) | ✅ |
| `specs/2026-10-06-cdek-v2-plugin-design.md` | CDEK v2 plugin (`woocommerce-edostavka` 2.3.x), written from scratch on Framework v2 | ✅ |
| `specs/2026-10-08-setup-wizard-v2-contract.md` | Setup-wizard v2 contract (#109 engine, #1177 CDEK migration wizard) | ✅ |
| `specs/2026-10-12-yandex-v2-plugin-design.md` | Yandex Delivery v2 plugin (`woocommerce-yandex-delivery` 2.0.x) — the second carrier for the v2 release | ✅ |
| `specs/2026-10-21-russian-post-v2-plugin-design.md` | Russian Post v2 plugin (`woodev-russian-post` 2.0.x) | ✅ |
| `specs/2026-10-27-tbank-v2-plugin-design.md` | T-Bank «Рассрочки и кредиты» v2 plugin (`woodev-vkredit` 2.0.0) — the first payment plugin on v2 | ✅ |
| `specs/2026-10-30-ozon-delivery-v2-plugin-design.md` | Ozon Delivery plugin (new product, released 2.0.0) — phone-first checkout, plugin-owned postings, returns; s203 decisions in `research/2026-10-30-ozon-gap-refresh/` | ✅ |

| Plan | Implements |
|------|------------|
| `plans/2026-08-06-sp5-pickup-selection-mechanism-plan.md` | the SP-5 selection-mechanism spec |
| `plans/2026-08-09-sp5-viewport-point-accumulation-plan.md` | the viewport-accumulation spec (#234) |
| `plans/2026-08-11-sp5-pickup-selection-persistence-plan.md` | the selection-persistence spec (#176) |
| `plans/2026-08-12-location-provider-plan.md` | the Location Provider design |
| `plans/2026-08-18-shipping-settings-v2-plan.md` | the «Доставка» tab design |
| `plans/2026-08-20-shipping-tab-admin-polish.md` | the «Доставка» admin polish batch (#375 #380 #377 #376 #373 #378) |
| `plans/2026-08-24-popular-settlements-slice-3-plan.md` | popular settlements, slice 3 (#488) |

⚠ **Four of those plans open with "REQUIRED SUB-SKILL: `superpowers:subagent-driven-development`".**
That instruction is superseded — parallel work runs through Orca orchestration now
(`AGENT-RULES.md`). The plans are kept as the record of what was built, not as live instructions.

Only the pre-s60 SP-5 map specs/plans were moved to `archive/specs/` and `archive/plans/`; shipped specs stay in `specs/` (see the note above).

⚠ **`research/` and `reviews/` are NOT archived** — this line used to say they were, and it was wrong:
both are live directories an agent writes to, and `archive/` holds only the pre-s60 ones. No file count
here: the last two counts written into this line went stale. **The earlier docs audits live in
`reviews/`** — `2026-08-29-docs-and-board-audit.md` (s104), `2026-09-05-644-part1-contradiction-map.md`
(s119), `2026-10-12-docs-audit-s171.md`, `2026-10-15-docs-audit-s183.md` and `2026-11-02-docs-audit-s210.md`;
read them before starting another (next due: s220), so a fixed contradiction is not re-litigated.

## Architecture Decision Records

| File | Purpose |
|------|---------|
| `adr/README.md` | ADR index |
| `adr/001` … `adr/012` | Bootstrap loader · plugin-type inheritance (002 partly superseded by 005 — the metadata bridge only; inheritance still holds) · minimal resolver · loader API · **005 clean-break policy** · capability-gated feature seam · React admin stack · conditional-fields operator set · map-provider seam (source, not library) · Yandex Maps JS API 2.1 not 3.0 · vendored IMask + generated phone masks · shipping `includes()` stays authoritative |

## Migration / Wiki / Autodev

| File | Purpose |
|------|---------|
| `migration/edostavka-data-preservation-checklist.md` · `migration/yandex-data-preservation-checklist.md` · `migration/signature-probe.md` | Per-plugin data contracts (applied — both rewrites shipped) and the signature-probe record. The Russian Post checklist lives in the plugin repo, not here |
| `wiki/` | Deep-dive topic references — the full list with a line each is [wiki/README.md](wiki/README.md) |
| `archive/autodev-loop-runbook.md` | Autodev loop runbook — retired s183 (`tools/autodev/` and `.autodev/` removed); history only |

## Historical reference (kept in place — still cited by active docs)

| File | Note |
|------|------|
| `audit-2026-06-01.md` | Independent audit; all release-blocker findings resolved (2026-06-02). Still linked from gotchas — kept in place |
| `FUTURE-BACKLOG.md` | **Frozen 2026-07-23** — backlog lives on GitHub board №6; kept for B-x history only |

## Archive (`archive/`)

Passed-gate audits, the completed platform-v2 program docs (plans/specs/prompts), triaged reviews (`archive/reviews/`), and shipped-work `archive/plans/` + `archive/specs/`. Full annotated listing: [archive/README.md](archive/README.md).

## Public Docs

`website/` (repo root) is the public documentation site (Astro Starlight). It is published (GitHub Pages); the framework version is substituted at build time and the pages are updated per release. Scope, editing and publishing rules are in [`DOCS-SCHEMA.md`](DOCS-SCHEMA.md#public-docs-website--how-to-work-with-them).

---

## Related

- `CLAUDE.md` — Claude Code entry point: Serena/Context7 tooling + lookup table (Claude Code)
- `wiki/architecture.md` — subsystems, base classes, seams (opened on demand, not at session start)
- `wiki/rig-pickup-walkthrough.md` — the rig walkthrough for the pickup layer, in the order that actually works (s75); moved here from `CURRENT-STATE.md` in s87
- `wiki/orchestrating-agents-with-orca.md` — how multi-agent work is run here: worker Sonnet / critic Codex, worktree placement, what we did not adopt
- `wiki/local-rig.md` — why the rig's fixtures and options are set the way they are (the pickup mu-plugin, the company field, the two location providers, the live-Yandex switch); moved here from `CURRENT-STATE.md` in s91
- `AGENTS.md` — shared project rules (session start/end, coding principles)
- `wiki/pickup-trigger-placement-and-text.md` — who decides where the checkout pickup button is drawn (the framework) and what it says (the carrier)
- `.ai/QUICK-REFERENCE.md` — shared project rules and conventions for all AI agents
