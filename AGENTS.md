# AGENTS.md — Woodev Plugin Framework
> For ALL AI agents (Claude, Gemini, Cursor, GPT, etc.). Keep updated. Last updated: 2026-08-16 (docs cleanup: SESSION-LOG split into sessions/, GOTCHAS index compressed, architecture moved to wiki/).
> **Claude Code agents:** read `CLAUDE.md` too — it adds the Claude-specific MCP tooling (Serena, Context7). It does not replace this file, and it restates nothing from it.
> **Architecture reference** (subsystems, base classes, seams): `docs-internal/wiki/architecture.md` — not loaded at session start, open it when the task needs it.

---

## ⚡ Session Start (mandatory — this is the CANONICAL list; CLAUDE.md and AGENT-RULES.md point here)

1. **Read `docs-internal/next-session-prompt.md`** — the per-session handoff: what the last session
   left for you, its **carry-over commitments**, and known traps (~1 min). Format and gate:
   `DOCS-SCHEMA.md` → Handoff Format
2. **Read `docs-internal/CURRENT-STATE.md`** — phase status, known bugs, next actions (~1 min)
3. **Scan `docs-internal/GOTCHAS.md`** — one line per gotcha; scan `[topic/*]` tags relevant to your task (~1 min). Open `gotchas/{slug}.md` for the ones that apply.
4. **Area-specific docs as needed** — relevant `docs-internal/adr/` and `docs-internal/wiki/` files (navigation hub: `docs-internal/DOCS-INDEX.md`)

---

## ✅ Session End (mandatory — CANONICAL list)

1. Update `docs-internal/CURRENT-STATE.md` — phase table, bugs, next actions
2. Write `docs-internal/sessions/sNN.md` — the session's own file: what was done, key decisions, test counts, commit hash. Then add ONE line for it to `docs-internal/SESSION-LOG.md`, which is only an index
3. **⚙️ Compilation step** — for each new gotcha discovered this session:
   - Create `docs-internal/gotchas/{slug}.md` (root cause + ❌ wrong / ✅ correct code + Related links)
   - Add index line to `docs-internal/GOTCHAS.md` under the correct `[topic/*]` section
   - Update `docs-internal/wiki/*.md` if a pattern was clarified
   - Read `docs-internal/DOCS-SCHEMA.md` for exact format rules
4. **Audit the board** — move the session's cards (`В работе` → `Готово`), close cards resolved by unrelated work, file cards for anything that surfaced but was never written down (see Backlog rule below)
5. **Update `docs-internal/next-session-prompt.md`** — replace it with the handoff for the next session (write it for someone with zero context)
6. Commit with Conventional Commits format (`feat:`, `fix:`, `docs:`, etc.)

---

## 📍 Current Phase
> **Always read `docs-internal/CURRENT-STATE.md`** — single source of truth for phase status, bugs, next actions.
> Do NOT duplicate sprint details here. This section is a pointer only.

---

## Project in one paragraph

**Woodev Plugin Framework** — PHP library (PHP 7.4–8.1) providing a scaffold for developing WooCommerce plugins. Ships as a vendored dependency bundled inside each plugin. Multiple plugins run simultaneously; bootstrap selects highest framework version. 10+ dependent plugins in production. Codebase: `woodev/` directory, no global namespace for legacy classes, `Woodev\Framework\*` PSR-4 for new code.

---

## 📚 Documentation Structure — two tiers

This project has **two documentation directories** with different audiences, publishing rules, and editing conventions.

| Directory | Audience | Published | MkDocs | Linted | Purpose |
|-----------|----------|-----------|--------|--------|---------|
| `docs/` | Plugin developers (public) | ✅ GH Pages | ✅ `mkdocs.yml` | ✅ markdownlint | Usage guides, API reference, tutorials |
| `docs-internal/` | AI agents + maintainers | ❌ Not published | ❌ excluded | ⚠️ partial | Session logs, gotchas, ADRs, operational state |

### Working with `docs/` — public documentation

**How to edit:**
1. Edit `.md` files directly in `docs/`
2. Preview locally: `mkdocs serve` (requires Python + mkdocs-material)
3. Use `%%FRAMEWORK_VERSION%%` placeholder for version numbers — CI injects the actual version from `Woodev_Plugin::VERSION` during deploy
4. Run markdownlint: `npx markdownlint-cli2 "docs/**/*.md"`

**What goes here:**
- API reference, usage guides, getting-started tutorials
- Module documentation (settings-api, payment-gateway, shipping-method, etc.)
- Code examples verified against actual source code

**What does NOT go here:**
- ❌ Session logs, gotchas, ADRs, bug tracking, phase status
- ❌ Internal architecture decisions, deferred features
- ❌ AI agent workflow rules

**Deploy:** Push to `main` → GitHub Actions (`docs.yml`) builds mkdocs → deploys to GH Pages at `https://kalbac.github.io/woodev-plugin-framework/`. Triggers on changes to `docs/**`, `mkdocs.yml`, or `woodev/class-plugin.php`.

### Working with `docs-internal/` — internal technical docs

**How to edit:**
1. Edit files directly — no build step, no mkdocs
2. Follow format rules in `docs-internal/DOCS-SCHEMA.md`
3. Session logs and gotchas excluded from markdownlint (in `.markdownlintignore`)
4. All files tracked in git — never gitignore docs-internal/ (the only ignored path is the gate's
   generated `next-session-prompt.md.prev` snapshot). **This repo is PUBLIC again** since
   27.08.2026 — it was private 25.08–27.08, and the switch back lifted the Actions billing block
   (public repos on standard runners consume no quota). Card #583.

**What goes here:**
- `CURRENT-STATE.md` — phase status, known bugs, next actions
- `SESSION-LOG.md` — index of sessions, one line each (newest at top)
- `sessions/sNN.md` — the per-session detail
- `GOTCHAS.md` — gotcha index → `gotchas/{slug}.md` atomic detail files
- `AGENT-RULES.md` — workflow + architecture rules for AI agents
- `DOCS-INDEX.md` — navigation hub for all internal docs
- `DOCS-SCHEMA.md` — doc format and lint rules
- `FUTURE-BACKLOG.md` — deferred features and technical debt
- `adr/` — Architecture Decision Records
- `wiki/` — compiled topic references
- `archive/` — resolved historical documents

**What does NOT go here:**
- ❌ Public-facing API documentation (→ `docs/`)
- ❌ User guides, tutorials (→ `docs/`)
- ❌ mkdocs configuration (→ `mkdocs.yml`)

---

## Repository map

```
woodev_framework/
├── CLAUDE.md                        # ← Entry point for Claude Code agents
├── AGENTS.md                        # ← Entry point for all other AI agents (this file)
├── docs/                            # Public docs → GH Pages (mkdocs)
│   ├── README.md, getting-started.md, core-framework.md …
│   ├── admin-module.md, settings-api.md, helpers.md …
│   ├── api-module.md, rest-api.md …
│   ├── shipping-method.md, payment-gateway.md, box-packer.md …
│   ├── utilities.md, compatibility.md, handlers.md
│   └── overrides/home.html, assets/stylesheets/extra.css
├── docs-internal/                   # Internal docs → AI agents only
│   ├── next-session-prompt.md       # Per-session handoff — every session starts here
│   ├── CURRENT-STATE.md, SESSION-LOG.md (index), GOTCHAS.md (index)
│   ├── sessions/                     # Per-session detail files
│   ├── AGENT-RULES.md, DOCS-INDEX.md, DOCS-SCHEMA.md, FUTURE-BACKLOG.md (frozen)
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
│   ├── box-packer/                  # Box packing algorithm
│   ├── compatibility/               # HPOS + WooCommerce compat
│   ├── handlers/                    # Blocks + script handlers
│   ├── licensing/                   # License key + EDD store integration (incl. updater/ — plugin update mechanism)
│   ├── payment-gateway/             # Payment gateway base classes (~13.8k lines; main file ~3,542)
│   ├── rest-api/                    # REST API routes
│   ├── settings-api/                # Typed settings framework
│   ├── shipping-method/             # Shipping plugin + method bases
│   └── utilities/                   # Async requests, background jobs
├── tests/
│   ├── unit/                        # Brain Monkey + Mockery (no WP needed)
│   ├── integration/                 # WP_UnitTestCase (wp-env)
│   └── _fixtures/                   # 7 test plugins
├── .ai/                             # AI agents and skills
│   ├── agents/                      # 5 sub-agents
│   ├── skills/                      # 5 skill directories
│   └── QUICK-REFERENCE.md
└── .github/workflows/               # CI: ci.yml, dependabot-auto-merge.yml, docs.yml,
                                     #     integration-tests.yml, markdown-lint.yml, pr-triage.yml
```

CI workflows: `ci.yml` (jobs: `unit-tests`, `test-js` — jest, added s55 PR #184, `secrets` — gitleaks, `assets` — build parity, `release`), `dependabot-auto-merge.yml`, `docs.yml`, `integration-tests.yml`, `markdown-lint.yml`, `pr-triage.yml`.

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
| Docs build | MkDocs Material | 9.6.7 (Python) |
| Changelog | git-cliff | latest |
| CI/CD | GitHub Actions | — |
| Composer | PHP dependency manager | ^2 |

---

## 🛠 Dev environment

```bash
# Install dependencies
composer install

# Run all checks
composer check              # phpcs + phpstan + unit tests

# Individual checks
composer phpcs              # code style check
composer phpcbf             # auto-fix code style
composer phpstan            # static analysis
composer test               # unit tests (Brain Monkey, no WP)
composer test:unit          # unit tests only
composer test:integration   # integration tests (requires wp-env)

# Run single test file
./vendor/bin/phpunit tests/unit/BootstrapTest.php

# JS tests + build
npm run test:js -- --roots "<rootDir>/tests/js"   # jest (800 tests) — CI gate (test-js job)
npm run build                                     # build the 5 React bundles (CI has an assets-parity job)
npm run lint:ts-baseline                          # TypeScript-by-default gate for src/ — CI gate (test-js job)
npm run typecheck                                 # tsc --noEmit over src/ — CI gate (test-js job)

# Docs
mkdocs serve                # preview public docs locally
npx markdownlint-cli2 "docs/**/*.md"  # lint public docs
```

- **Enable the commit-msg gate once per clone: `git config core.hooksPath .githooks`.** It refuses a
  GitHub closing keyword (`closes`/`fixes`/`resolves #N`) anywhere except alone on its own line —
  GitHub executes those literally even inside a quote, and s81 closed three cards that way while
  merely describing plans. A deliberate `Closes #123` on its own line still works.
- **Point github.com at `gh` once per machine: `gh auth setup-git`.** Git for Windows ships
  `credential.helper = manager` in its SYSTEM config, and Git Credential Manager blocks forever
  waiting for an interactive prompt nobody can answer — so an agent's `git push` hangs with no
  output, no error and no prompt (#560, operator decision 27.08.2026). The override is scoped to
  github.com, so every other host keeps using GCM. Do NOT hand-roll it as
  `git config credential.helper '!gh auth git-credential'` — that leaves `manager` first in the
  chain and it still hangs; `gh auth setup-git` writes the empty entry that clears it. Gotcha:
  `git-credential-manager-hangs-silently-in-an-agent-session`.
- Never run `npx jest` directly — it loses the wp-scripts jsdom environment and scans agent worktrees inside the repo (gotchas `npx-jest-bypasses-wp-scripts-jsdom`, `jest-scans-agent-worktrees-inside-the-repo`)
- Integration tests require `WP_TESTS_DIR` env var or `npx wp-env start`
- **Merge gate:** every CI job green individually (incl. `test-js` and `assets`), each with state CLEAN — not just "`composer check` passes". `main` has no required-check gate, so verify each job yourself before merging.

---

## 🎯 Coding Principles (Karpathy-inspired)

4 principles that prevent common LLM coding failures. Apply to every task.

1. **Think Before Coding** — state assumptions, surface alternatives, ask when unclear
2. **Simplicity First** — minimum code; no speculative features
3. **Surgical Changes** — touch only what the task requires; preserve installed-site data contracts (internal APIs may break on the v2 branch — see clean-break policy)
4. **Goal-Driven Execution** — define success as a verifiable check (`composer check` passes)

**Full rules with Do/Don't tables:** `docs-internal/AGENT-RULES.md` → "Workflow Rules"

---

## ✅ Definition of Done

A task is DONE only when:
1. Code is written (type declarations, docblocks, backward compat preserved)
2. `composer check` passes without errors (phpcs + phpstan + unit tests)
3. Jest is green: `npm run test:js -- --roots "<rootDir>/tests/js"` (never `npx jest`)
4. New/modified behavior is covered by tests
5. `docs-internal/CURRENT-STATE.md` is updated
6. `docs-internal/sessions/sNN.md` is written and indexed in `SESSION-LOG.md`
7. The board card is moved to `Готово` (see Backlog rule)
8. `git commit` is made with Conventional Commits format

---

## ⚠️ Critical gotchas — quick reference

Full details + code examples in `docs-internal/gotchas/`. Scan `docs-internal/GOTCHAS.md` index for your topic.

**Naming:** `woodev` (single d), `Woodev` prefix — `wooddev` is always wrong.

**Backward compatibility — clean-break policy (v2 line, D-2 2026-06-03; the `refactor/platform-v2-clean-break` branch merged to `main` 2026-06-04):**
- **Internal code is FREE TO BREAK** on the v2 line (`main`) — class/method names, registration shape, namespacing. Do NOT add `@deprecated`/`class_alias`/`_deprecated_function` shims for moved internal APIs; delete existing ones.
- **Installed-site data contracts are RELEASE-BLOCKING** — option keys, license/instance IDs, gateway/shipping method IDs, hook names, cron, REST namespaces, AJAX actions, admin slugs, meta keys. Preserve byte-for-byte (enforced per-plugin at rewrite time).
- Full policy: `docs-internal/adr/005-platform-v2-clean-break-policy.md`; operating rules: `docs-internal/platform-v2-execution-protocol.md`.
- Legacy namespace: `Woodev_*` classes; new code: `Woodev\Framework\*` PSR-4 (include-based, not Composer autoload at runtime).

**Serena MCP (PHP navigation) — binds agents that HAVE Serena:**
- Always use Serena tools for PHP source reading — never raw `Read` on `.php` files
- **Serena is a Claude Code MCP.** An agent without access to it — Codex, for one — is not expected
  to follow this rule and should say so once and proceed with git/shell inspection. Reporting the
  absence is right; stalling on it is not (s83)
- Serena is pre-indexed, faster and more accurate than file reads
- **Mandatory (operator decision, s60):** verify Serena is connected at session start; if it is
  missing, report to the operator before any PHP work — do not silently fall back to `Read`.
  Every subagent brief touching PHP must repeat this rule. Full enforcement text:
  `docs-internal/AGENT-RULES.md` → "Use Serena MCP".

**Type safety:**
- Type declarations required on ALL parameters and return types
- Docblocks required on ALL public/protected methods (`@since`, `@param`, `@return`)
- Pure methods (output depends only on inputs) must be `static`

**WooCommerce integration:**
- Use `Woodev_Order_Compatibility` for HPOS-safe order data access
- `Woodev_Plugin_Compatibility` for WP/WC version checks
- Gateway plugins extend `Woodev_Payment_Gateway_Plugin`, not `Woodev_Plugin` directly

**Docs:**
- Public docs (`docs/`) use `%%FRAMEWORK_VERSION%%` placeholder — never hardcode version
- Internal docs (`docs-internal/`) — English only, no Russian
- Gotchas go in `docs-internal/gotchas/`, NOT in `docs/`

---

## Conventions

| Area | Rule |
|------|------|
| Documentation language | English (for AI agents) — see `docs-internal/DOCS-SCHEMA.md` |
| Translatable strings | **Four rules, by WHO READS the string** (operator, 29.08.2026, #567). **Storefront → the msgid is ENGLISH** and the Russian arrives from the catalogue: a shop's frontend may run an English locale even though its admin never does. **Admin → Russian msgid is fine and stays** (do not spend effort anglicising it); an admin string that IS in English must be translated in the catalogue. **Logs, exception texts and anything else that never reaches a screen → either language, and it need not be wrapped in `__()`/`_e()` at all — a plain string is correct.** Text domain, wherever a wrapper IS used: `woodev-plugin-framework` (pinned by `TextDomainConsistencyTest`). Classify by the RENDER PATH, never by the file's directory — gotcha `classify-an-i18n-string-by-its-render-path-not-its-file-path`. |
| PHP style | WordPress Coding Standards (tabs, snake_case, PHPDoc) |
| Classes | `Snake_Case`. **New code is authored directly in namespaces** (`Woodev\Framework\*` PSR-4, e.g. `Woodev\Framework\Shipping\Shipping_Plugin`) — do NOT write new code under the legacy global `Woodev_*` shape. Legacy `Woodev_*` exists only in not-yet-migrated files. |
| Methods/variables/hooks | `snake_case` |
| Visibility | default `private`, `protected`/`public` only when needed |
| Arrays | Short syntax `[]` **only — never `array()`** in new or modified code |
| Frontend (`src/`) | **TypeScript is the default for NEW files.** Existing `.js`/`.jsx` is migrated only on touch, not in bulk — enforced by `npm run lint:ts-baseline` (baseline: `scripts/ts-baseline.txt`), gated in CI's `test-js` job alongside `npm run typecheck`. `woodev/**/assets/js/frontend/` (raw JS served to the browser as-is) is explicitly OUT of this scope — do not take TypeScript there (#542, 26.08.2026). |
| Settings help text | Goes in the **tooltip/`desc_tip`** slot by default — `tooltip` on `register_control()` for this project's typed settings API, `desc_tip` in a WooCommerce `form_fields` array. The `description` slot is reserved for text carrying **interactive elements** (e.g. an `<a href>` link), because that is the slot WooCommerce renders inline rather than as a hover tip. Operator rule, 25.08.2026. |
| Git | Conventional Commits (`feat:`, `fix:`, `docs:`, etc.) |
| Version | Stored in `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) |
| `@since` | The **planned release** the change ships in — currently `2.0.2`. NOT the `VERSION` constant, which records the **released** version (`2.0.1`). Operator decision, #409 (s83): the two are deliberately separate concepts. Raising `VERSION` on `main` publishes a release, so it lags behind by design (#285). |

---

## Git workflow
- Commit after each verified, working unit of change
- Message: `type(scope): description` — e.g. `fix(bootstrap): handle missing WC dependency`
- Never commit broken code — `composer check` must pass
- Breaking changes: add `!` after type + `BREAKING CHANGE:` footer
- Branch naming: `{type}/{description}` — e.g. `feat/new-subsystem`

---

## 🔍 Code review rule

**Order: code → review → fix → commit. Never commit first, review later.**

Run review before committing when changes touch:
- `woodev/class-plugin.php` or `woodev/bootstrap.php` (core architecture)
- `woodev/payment-gateway/` (high complexity, ~3,542 lines in main file)
- Public API surface (new/changed public methods, new classes)
- Deprecation/removal of existing functionality
- Changes spanning 3+ files

**Skip for:** docs-only, config-only, tests-only, small isolated single-file fix.

---

## 🧠 Gotcha recording rule

**When:** Record a gotcha **immediately when discovered**, not at session end.

**How (mandatory steps):**
1. **Qualify** — non-obvious + can cause bug + contradicts assumptions? If no → skip
2. **Dedup** — scan `docs-internal/GOTCHAS.md` for existing entries on the same topic
3. **If similar exists** → read the detail file, determine which fact is correct, UPDATE the existing file. Never create a second file on the same topic
4. **If new** → create `docs-internal/gotchas/{slug}.md` (format in `docs-internal/DOCS-SCHEMA.md`) + add index line to `docs-internal/GOTCHAS.md`
5. **Cross-link** — add `## Related` section in the new file

**Full protocol with examples:** `docs-internal/DOCS-SCHEMA.md` → "Gotcha write protocol"

---

## 📋 Backlog rule — GitHub Projects, not docs (MANDATORY)

**The single backlog for this repo is GitHub Issues + the board
[Woodev Framework Backlog](https://github.com/users/kalbac/projects/6) (project №6).**

Ideas, bugs, tech debt and deferred findings go there — **not** into `docs-internal/FUTURE-BACKLOG.md`,
not into a `// TODO` comment, not into a chat message the operator has to remember.

### Capture — do this immediately, not at session end

Any out-of-scope idea, bug or tech-debt item that surfaces mid-session:

1. `gh issue create --repo kalbac/woodev-plugin-framework` — **title and body in Russian** (the operator
   is the reader; labels, code, commits and docs stay English). Add a type label: `bug`, `enhancement`,
   `idea`, `tech-debt`, `research` or `polish`.
2. Add it to the board — auto-add is **not** enabled, so always do it explicitly:
   `gh project item-add 6 --owner kalbac --url <issue-url>`
3. Set the status (the board has no default that suits us):
   `gh project item-edit --id <item-id> --project-id PVT_kwHOAIbGB84BeLao --field-id PVTSSF_lAHOAIbGB84BeLaozhYnkgs --single-select-option-id <option>`

   | Status | Option id | When |
   |---|---|---|
   | Инбокс | `e765cf18` | The card needs the operator's judgement before anyone builds it |
   | Бэклог | `bdd0cc46` | **Agent-authored and you are confident it should be built** — file it straight here |
   | В работе | `34407388` | You are working on it right now |
   | Готово | `c88618cf` | Set by the board itself when the issue closes |

A code `TODO` must reference an issue (`// TODO(#123): …`) — never stand alone.

### Lifecycle

- **An agent may move a card itself — but only a card that is already SETTLED.** The discriminator is
  not who wrote it, it is **whether the card needs the operator's answer** (operator, 27.08.2026, #600):
  - you filed it and it needs no answer from him → move it wherever it belongs, `Бэклог` included.
    Parking a decision already made in `Инбокс` only forces a re-triage of it.
  - the card is FOR HIM — it asks him to decide something → it stays in `Инбокс` and **he** moves it,
    either to `Бэклог` or closed as `not planned`. This holds even when an AGENT wrote the card:
    #600 was agent-authored and still belonged in `Инбокс`, because its whole content was a question
    only he could answer.
  The earlier wording keyed on authorship alone, which gets that last case wrong — do not simplify it
  back.
- **Take the card you are working on into `В работе`.** Work driven by a spec/plan rather than a card
  still needs one — create it and move it, so the board reflects reality.
- Close via `Closes #N` in the implementing commit or PR (the board moves the card to `Готово` itself),
  or `gh issue close --reason completed` with a **Russian** comment explaining what closed it.
  Wrong-idea rejections close as `not planned`, also with a Russian comment.
- **Before creating a card from memory or docs, verify against the code first.** A card was once filed
  for work that had already shipped weeks earlier.
- **At session end, audit the board** — some cards get closed by unrelated work. A file deleted for one
  reason can resolve a card filed for another.

### `docs-internal/FUTURE-BACKLOG.md` is superseded

It remains as a historical record of items filed before the board existed. **Do not add to it.** If you
find something there that is still live, move it to an issue rather than editing the file.
