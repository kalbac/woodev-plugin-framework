# AGENTS.md — Woodev Plugin Framework
> For ALL AI agents (Claude, Gemini, Cursor, GPT, etc.). Keep updated. Last updated: 2026-05-09 (s0).
> **Claude Code agents:** read `CLAUDE.md` instead — it extends this file with Serena MCP, Context7, and Supermemory rules.

---

## ⚡ Session Start (3 steps — mandatory)

1. **Read `docs-internal/CURRENT-STATE.md`** — phase status, known bugs, next actions (~1 min)
2. **Scan `docs-internal/GOTCHAS.md`** — index of atomic gotcha files; scan `[topic/*]` tags relevant to your task (~1 min). Click through to detail files when needed.
3. **Read `docs-internal/DOCS-INDEX.md`** — navigation hub, find task-specific doc (~1 min)

---

## ✅ Session End (mandatory)

1. Update `docs-internal/CURRENT-STATE.md` — phase table, bugs, next actions
2. Append 10–20 line summary to `docs-internal/SESSION-LOG.md` — what was done, key decisions
3. **⚙️ Compilation step** — for each new gotcha discovered this session:
   - Create `docs-internal/gotchas/{slug}.md` (root cause + ❌ wrong / ✅ correct code + Related links)
   - Add index line to `docs-internal/GOTCHAS.md` under the correct `[topic/*]` section
   - Update `docs-internal/wiki/*.md` if a pattern was clarified
   - Read `docs-internal/DOCS-SCHEMA.md` for exact format rules
4. Commit with Conventional Commits format (`feat:`, `fix:`, `docs:`, etc.)

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
4. All files tracked in git — never gitignore docs-internal/

**What goes here:**
- `CURRENT-STATE.md` — phase status, known bugs, next actions
- `SESSION-LOG.md` — chronological session history (newest at top)
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
│   ├── CURRENT-STATE.md, SESSION-LOG.md, GOTCHAS.md
│   ├── AGENT-RULES.md, DOCS-INDEX.md, DOCS-SCHEMA.md, FUTURE-BACKLOG.md
│   ├── gotchas/                     # Atomic gotcha detail files
│   ├── adr/                         # Architecture Decision Records
│   ├── wiki/                        # Compiled topic references
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
│   └── _fixtures/                   # 3 test plugins
├── .ai/                             # AI agents and skills
│   ├── agents/                      # 5 sub-agents
│   ├── skills/                      # 5 skill directories
│   └── QUICK-REFERENCE.md
└── .github/workflows/               # CI: docs.yml, markdown-lint.yml, release
```

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

# Docs
mkdocs serve                # preview public docs locally
npx markdownlint-cli2 "docs/**/*.md"  # lint public docs
```

- Integration tests require `WP_TESTS_DIR` env var or `npx wp-env start`
- `composer check` is the CI gate — all three checks must pass before merge

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
3. New/modified behavior is covered by tests
4. `docs-internal/CURRENT-STATE.md` is updated
5. `git commit` is made with Conventional Commits format

---

## ⚠️ Critical gotchas — quick reference

Full details + code examples in `docs-internal/gotchas/`. Scan `docs-internal/GOTCHAS.md` index for your topic.

**Naming:** `woodev` (single d), `Woodev` prefix — `wooddev` is always wrong.

**Backward compatibility — clean-break policy (v2.0 branch, D-2 2026-06-03):**
- **Internal code is FREE TO BREAK** on `refactor/platform-v2-clean-break` — class/method names, registration shape, namespacing. Do NOT add `@deprecated`/`class_alias`/`_deprecated_function` shims for moved internal APIs; delete existing ones.
- **Installed-site data contracts are RELEASE-BLOCKING** — option keys, license/instance IDs, gateway/shipping method IDs, hook names, cron, REST namespaces, AJAX actions, admin slugs, meta keys. Preserve byte-for-byte (enforced per-plugin at rewrite time).
- Full policy: `CLAUDE.md` → Backward Compatibility; operating rules: `docs-internal/platform-v2-execution-protocol.md`.
- Legacy namespace: `Woodev_*` classes; new code: `Woodev\Framework\*` PSR-4 (include-based, not Composer autoload at runtime).

**Serena MCP (PHP navigation):**
- Always use Serena tools for PHP source reading — never raw `Read` on `.php` files
- Serena is pre-indexed, faster and more accurate than file reads

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
| User-facing strings | Russian (via WordPress i18n, text domain: `woodev-plugin-framework`) |
| PHP style | WordPress Coding Standards (tabs, snake_case, PHPDoc) |
| Classes | `Snake_Case`. **New code is authored directly in namespaces** (`Woodev\Framework\*` PSR-4, e.g. `Woodev\Framework\Shipping\Shipping_Plugin`) — do NOT write new code under the legacy global `Woodev_*` shape. Legacy `Woodev_*` exists only in not-yet-migrated files. |
| Methods/variables/hooks | `snake_case` |
| Visibility | default `private`, `protected`/`public` only when needed |
| Arrays | Short syntax `[]` **only — never `array()`** in new or modified code |
| Git | Conventional Commits (`feat:`, `fix:`, `docs:`, etc.) |
| Version | Stored in `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) |
| `@since` | Uses current `VERSION` constant value |

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
