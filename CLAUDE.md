# CLAUDE.md

> Read [AGENTS.md](AGENTS.md) first for shared project rules (session start/end, coding principles, gotchas, conventions).
> This file extends it with **Claude Code-specific** integrations: Serena MCP, Context7, and detailed architecture reference.

## MCP Tools

This project has the following MCP servers configured globally:

### Serena MCP

Serena provides symbolic code navigation, cross-referencing, and safe code editing capabilities across 30+ languages including PHP.

> **RULE FOR ALL AI AGENTS:** Always use Serena tools (`find_symbol`, `get_symbols_overview`, `find_referencing_symbols`, `search_for_pattern`) to read and navigate PHP source code. Never use the `Read` tool directly on `.php` files. Serena has the codebase pre-indexed and provides semantic lookup — it is faster and more accurate than raw file reads.
>
> **Enforcement (operator decision, s60):** mandatory, not best-effort. Verify Serena is connected at session start (its tools present in the tool list, incl. deferred); if missing — **report to the operator before any PHP work**, do not silently fall back to `Read` (that is how s45–s59 drifted). Every subagent brief that touches PHP must repeat this rule. Details: `docs-internal/AGENT-RULES.md` → "Use Serena MCP".

**Configuration:** configured globally (outside this repo)

**Available Tools:**
- `find_symbol` — Find classes, functions, methods by name/path
- `get_symbols_overview` — Structure overview of a source file
- `find_referencing_symbols` — Find who uses a symbol
- `search_for_pattern` — Search a pattern across the codebase
- `read_file` / `replace_symbol_body` — Read source, edit a function body
- `insert_after_symbol` / `insert_before_symbol` — Add code around a symbol
- `list_dir` / `find_file` — List directories, find files by name

**Dashboard:** http://localhost:24282/dashboard (opens automatically when Serena starts)

**Documentation:** https://oraios.github.io/serena/

### Context7 MCP

Context7 provides up-to-date documentation and context for libraries and frameworks directly in your prompts.

**Configuration:** configured globally (outside this repo)

**Purpose:** Automatically fetches the latest documentation for any library or framework mentioned in your conversation, ensuring AI responses are based on current docs rather than training data.

**Package:** `@upstash/context7-mcp`

**Documentation:** https://upstash.com/blog/context7-mcp

## Project Overview

**Woodev Plugin Framework** (`woodev/plugin-framework`) is a PHP library providing a scaffold for developing WooCommerce plugins. It ships as a vendored dependency bundled inside each WooCommerce plugin that uses it. Multiple plugins can run simultaneously; the bootstrap selects the highest framework version to load.

- PHP 7.4–8.x, platform target: PHP 8.1
- Text domain: `woodev-plugin-framework`
- All globals/classes must be prefixed `woodev` or `Woodev`
- i18n strings for UI strings use Russian where applicable (this is intentional)

## Commands

```bash
composer install              # install all dev dependencies

composer phpcs                # check code style (WordPress Coding Standards + PHPCompatibility)
composer phpcbf               # auto-fix code style
composer phpstan              # static analysis (level 3, PHP 7.4+)
composer test                 # run unit tests
composer test:unit            # run unit tests (Brain Monkey, no WP needed)
composer test:integration     # run integration tests (requires WP_TESTS_DIR or wp-env)
composer check                # run phpcs + phpstan + unit tests together
```

```bash
npm run test:js -- --roots "<rootDir>/tests/js"   # jest (800 tests) — CI gate (test-js job)
npm run build                                     # build the 5 React bundles (CI has an assets-parity job)
```

Never run `npx jest` directly — it loses the wp-scripts jsdom environment and scans agent worktrees inside the repo (gotchas `npx-jest-bypasses-wp-scripts-jsdom`, `jest-scans-agent-worktrees-inside-the-repo`). Always use the `npm run test:js -- --roots` form above.

Run a single test file:
```bash
./vendor/bin/phpunit tests/unit/BootstrapTest.php
```

Integration tests require a WordPress test library. Set `WP_TESTS_DIR` env var or use `npx wp-env start`.

## Session Start Protocol

The canonical session start/end lists live in [AGENTS.md](AGENTS.md) — follow those; do not maintain a diverging copy here. Quick version:
1. Read `docs-internal/next-session-prompt.md` — the per-session handoff (start here)
2. Read `docs-internal/CURRENT-STATE.md` — current phase status, known bugs, next actions
3. Scan `docs-internal/GOTCHAS.md` — gotcha index (prevents repeated mistakes)
4. If working on a specific area, read relevant files from `docs-internal/adr/`, `docs-internal/wiki/`

For complete session start/end protocols and coding principles, see [AGENTS.md](AGENTS.md).

## Documentation Structure

| Directory | Audience | Published | Purpose |
|-----------|----------|-----------|---------|
| `docs/` | Developers (public) | ✅ GH Pages (mkdocs) | Usage guides, API reference, tutorials |
| `docs-internal/` | AI agents + maintainers | ❌ Not published | Session logs, gotchas, ADRs, operational state |

Internal docs (`docs-internal/`):
- `next-session-prompt.md` — per-session handoff (the doc every session actually starts from)
- `CURRENT-STATE.md` — phase status, known bugs, next actions
- `SESSION-LOG.md` — full session history
- `GOTCHAS.md` — gotcha index → `gotchas/{slug}.md` atomic detail files
- `AGENT-RULES.md` — workflow + architecture rules for AI agents
- `DOCS-INDEX.md` — navigation hub for all internal docs
- `DOCS-SCHEMA.md` — doc format and lint rules
- `FUTURE-BACKLOG.md` — deferred features and technical debt (FROZEN — backlog lives on GitHub board №6)
- `adr/` — Architecture Decision Records
- `wiki/` — compiled topic references
- `specs/` — feature specifications
- `plans/` — implementation plans
- `research/` — research notes
- `reviews/` — review reports
- `migration/` — per-plugin v2 migration docs (incl. data-preservation checklists)
- `archive/` — resolved historical documents

### Public docs (`docs/`) — GH Pages

- Built by mkdocs (Material theme), deployed automatically on push to `main`
- Uses `%%FRAMEWORK_VERSION%%` placeholder — injected by CI from `Woodev_Plugin::VERSION`
- Edit `.md` files directly, preview with `mkdocs serve`
- Lint with `npx markdownlint-cli2 "docs/**/*.md"`
- Never add session logs, gotchas, or internal notes here

### Internal docs (`docs-internal/`) — AI agents

- Plain markdown, no build step, not published
- Gotchas → `docs-internal/gotchas/{slug}.md` + index in `GOTCHAS.md`
- Session work → `CURRENT-STATE.md` + `SESSION-LOG.md`
- Architecture decisions → `adr/` (see `adr/README.md` for template)
- All files tracked in git — never gitignore docs-internal/

## Architecture

### Bootstrap & Multi-version Loading (`woodev/bootstrap.php`)

`Woodev_Plugin_Bootstrap` (singleton) is the entry point — never instantiate it directly. v2 plugins register via **`Woodev_Loader::register( __FILE__, [...] )`** (or `register_loader_definition()` directly); `register_plugin()` survives only as a v1 **tombstone** that quarantines legacy callers and never registers (see Known Technical Debt below). Every loader definition must set `version` (the framework version the plugin bundles) and `backwards_compatible` (the oldest framework version it is compatible with). On `plugins_loaded`, the resolver loads the **highest** registered framework version for the whole fleet, then initializes all compatible plugins. Plugins with incompatible framework, WC, or WP versions are deactivated with admin notices. Plugin type (WP / WC / gateway / shipping) is declared solely by what the plugin class `extends` — never by a flag or capabilities array (removed in s27). Full contract: `docs-internal/AGENT-RULES.md` → Rule 3.

### Base Plugin Class (`woodev/class-plugin.php`)

`Woodev_Plugin` is the abstract base every plugin extends. Concrete plugins must implement:
- `get_file()` — return `__FILE__`
- `get_plugin_name()` — return localized plugin name
- `get_download_id()` — return EDD/store download ID

The constructor auto-initializes all framework subsystems and registers WP hooks. Plugins override the `init_*` methods to provide their own subsystem implementations.

### Subsystems (all initialized inside `Woodev_Plugin::__construct`)

| Class | Purpose |
|---|---|
| `Woodev_Plugin_Dependencies` | PHP extension/function/setting dependency checking |
| `Woodev_Admin_Message_Handler` | Flash messages persisted across requests |
| `Woodev_Admin_Notice_Handler` | Dismissible WP admin notices |
| `Woodev_Plugins_License` | License key storage and validation |
| `Woodev_Plugin_Updater` | Pulls plugin updates from Woodev store |
| `Woodev_Hook_Deprecator` | Fires `_doing_it_wrong` for deprecated hooks |
| `Woodev_Lifecycle` | Install/upgrade routines and milestone notices |
| `Woodev_REST_API` | Registers plugin REST API routes |
| `Woodev_Blocks_Handler` | Declares WC Cart/Checkout block compatibility |
| `Woodev\Framework\Setup\Setup_Wizard` | Admin onboarding wizard — neutral React-driven, opt-in via `get_setup_wizard_handler()` (WC wrapper: `Woocommerce_Setup_Wizard`) |
| `Woodev_Admin_Pages` | Plugin settings page registration |
| `Woodev_Plugin_Compatibility` | WP/WC version helpers |
| `Woodev_Order_Compatibility` | HPOS-compatible order data access |
| `Woodev_License_Store` | License key persistence |
| `Woodev_License_Messages` | License admin messages |
| `Script_Handler` | Script/style enqueueing |
| `Woodev_Notes_Helper` | WC Admin inbox notes |

### Plugin Variants

- **`Woodev_Payment_Gateway_Plugin`** (`woodev/payment-gateway/class-payment-gateway-plugin.php`) — a payment-gateway plugin declares its type simply by extending this class (capability flags were removed in s27; type comes from `extends`). Manages one or more `Woodev_Payment_Gateway` instances.
- **`Woodev\Framework\Shipping\Shipping_Plugin`** (`woodev/shipping-method/class-shipping-plugin.php`) — a shipping plugin declares its type by extending this class (again: type from `extends`, not bootstrap args). Uses PSR-4 namespaces (`Woodev\Framework\Shipping\`).
- **Payment Gateway admin handlers** — order/user/token admin UI classes in `woodev/payment-gateway/admin/`
- **Payment Gateway REST API** — gateway-specific REST endpoints in `woodev/payment-gateway/api/`

### Licensing (`woodev/licensing/`)

License validation has its own API layer (`woodev/licensing/api/`) for communicating with the Woodev store.

### API Layer (`woodev/api/`)

`Woodev_API_Base` handles HTTP communication. Extend one of:
- `Woodev_Abstract_API_JSON_Request` / `Woodev_Abstract_API_JSON_Response`
- `Woodev_Abstract_API_XML_Request` / `Woodev_Abstract_API_XML_Response`
- `Woodev_Abstract_Cacheable_API_Base` — adds transient-based request caching via `Cacheable_Request_Trait`

Requests/responses must implement `Woodev_API_Request` / `Woodev_API_Response` interfaces. API requests are automatically logged via the `woodev_{plugin_id}_api_request_performed` action.

### Settings API (`woodev/settings-api/`)

`Woodev_Abstract_Settings` provides a WooCommerce-style settings page. Settings are defined as `Woodev_Setting` objects registered through `Woodev_Register_Settings`.

### Lifecycle & Upgrades (`woodev/class-lifecycle.php`)

Override `Woodev_Lifecycle` per plugin. Define `$upgrade_versions` array and add methods named `upgrade_to_X_Y_Z()`. Install/upgrade events are stored in the DB (last 30 events). Milestone notices prompt users for reviews after key actions.

### Box Packer (`woodev/box-packer/`)

Self-contained shipping box-packing algorithm. Implement `Woodev_Packer_Item_Interface` and `Woodev_Packer_Box_Interface`; use `Woodev_Abstract_Packer` subclasses (`Woodev_Packer_Single_Box`, `Woodev_Packer_Separately`, `Woodev_Packer_Virtual_Box`).

### Utilities (`woodev/utilities/`)

- `Woodev_Async_Request` — WP async (non-blocking) HTTP requests
- `Woodev_Background_Job_Handler` — WP background processing queue
- `Woodev_Job_Batch_Handler` — batch job processing with admin UI

## Testing

- **Unit tests** (`tests/unit/`) use Brain Monkey + Mockery; no WordPress required.
- **Integration tests** (`tests/integration/`) run inside a real WordPress environment.
- Test fixtures (`tests/_fixtures/`) contain seven plugins: `woodev-test-plugin`, `woodev-test-payment-gateway`, `woodev-test-shipping-method`, `woodev-edostavka-pilot-plugin`, `woodev-realistic-payment-plugin`, `woodev-realistic-shipping-plugin`, `woodev-yandex-pilot-plugin`.
- Test base classes `tests/unit/TestCase.php` and `tests/integration/TestCase.php` set up Brain Monkey and WP test scaffolding respectively.

## Code Style

- WordPress Coding Standards (`WordPress-Core`, `WordPress-Extra`, `WordPress-Docs`)
- Short array syntax `[]` is **required for new/modified code** — never `array()` (override of WPCS default)
- **New code is authored directly in namespaces** (`Woodev\Framework\*` PSR-4) — do not write new code under the legacy global `Woodev_*` shape
- Line length limit: 120 characters
- PHPCompatibility checked for PHP 7.4+, minimum WP version 6.6 (raised from 6.3 in s36 — enables the automatic JSX runtime; classic-JSX babel hack removed)
- PHPStan level 3; `checkDynamicProperties: false` (legacy code uses dynamic properties)

## Backward Compatibility — clean-break policy (v2 line)

> Policy set 2026-06-03 (direction audit **D-2**). Supersedes the prior "deprecation cycle for everything" rule. Originally scoped to the `refactor/platform-v2-clean-break` branch, which merged to `main` on 2026-06-04 — the policy now applies on the v2 line (`main`). Rationale: this is effectively a new framework; the old one is being rewritten and the dependent plugins will be rewritten onto it (`PLANS.md` §2.4). The previous strict-deprecation mandate was generating a back-compat tax for plugins we are about to replace (audit §4.2).

Two different rules apply depending on what you are changing:

- **Internal code — FREE TO BREAK on the v2 line (`main`):** class names, method
  signatures, the plugin entry/registration shape, namespacing, file layout.
  Do **NOT** add `@deprecated` shims, `class_alias` files, or
  `_deprecated_function()` wrappers for moved/renamed internal APIs. **Delete**
  existing internal-API shims (see `docs-internal/archive/platform-v2-cleanbreak-plan.md`
  Phase 3).
- **Installed-site data contracts — RELEASE-BLOCKING, never break:** option keys
  & settings arrays, license key option names + activation state + instance IDs,
  updater identity, WC payment-gateway IDs, WC shipping-method IDs + instance
  setting keys, public action/filter hook names, scheduled cron hooks +
  recurrence + payload shape, custom DB tables/schemas, REST route namespaces,
  AJAX action names, admin page slugs, log source names, background-job IDs,
  order/session meta keys. Preserve these byte-for-byte.

When a plugin is migrated onto v2, enforce the "never break" list via its
`docs-internal/migration/<plugin>-data-preservation-checklist.md` — that is where
data preservation is verified, at rewrite time, per plugin.

Operating rules for the whole effort live in
`docs-internal/platform-v2-execution-protocol.md`.

## Coding Conventions

- **OOP only** — no standalone functions outside bootstrap
- **Namespaces:** Legacy code has no namespace (`Woodev_Plugin`); new code uses `Woodev\Framework\*` (PSR-4)
- **Naming:** `Snake_Case` for classes, `snake_case` for methods/variables/hooks
- **Visibility:** default `private`, use `protected`/`public` only when needed
- **Type declarations** required on all parameters and return types
- **Docblocks** required on all public/protected methods with `@since`, `@param`, `@return`
- **Pure methods** (output depends only on inputs) should be `static`
- **Hooks:** name callbacks `handle_{hook_name}`, mark with `@internal`
- **Yoda conditions**, short array syntax `[]`, `??` over `isset`, PHP 7.4+ features (arrow functions, `??=`)
- **Conventional Commits** required for all commits (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`, `ci:`)

## Commit & Release

- All commits follow [Conventional Commits](https://www.conventionalcommits.org/) format
- Breaking changes: add `!` after type + `BREAKING CHANGE:` footer
- VERSION is stored in `woodev/class-plugin.php` as `Woodev_Plugin::VERSION`
- Release is automatic via the `release` job inside `.github/workflows/ci.yml` (not a separate workflow): push to main → tests → tag → CHANGELOG → release
- `@since` annotations use the current `VERSION` constant value

## Notable Utilities

- `Woodev_String_Conversion` — Cyrillic-to-Latin transliteration utility

## Knowledge Persistence

When you discover important project rules, conventions, or patterns during your work:

- **Gotchas** (mistakes to avoid, correct/incorrect patterns) → create `docs-internal/gotchas/{slug}.md` + add index line to `docs-internal/GOTCHAS.md`
- **Architecture decisions** (non-trivial choices with tradeoffs) → create `docs-internal/adr/{NNN-title}.md` + add to `docs-internal/adr/README.md`
- **Reference knowledge** (in-depth topic explanation) → create `docs-internal/wiki/{topic}.md`
- **Session work** → update `docs-internal/CURRENT-STATE.md` + append to `docs-internal/SESSION-LOG.md`
- **Quick reference** (cross-project, shared across agents) → `.ai/QUICK-REFERENCE.md` (section "Project Rules & Conventions")
- **Ideas, bugs, tech debt, deferred findings** → a **GitHub issue on board №6**, filed immediately, in Russian — never `docs-internal/FUTURE-BACKLOG.md` (frozen) and never a bare `// TODO`. Full protocol, including the board's status option ids and when a card goes to `Инбокс` versus straight to `Бэклог`: [AGENTS.md](AGENTS.md) → "Backlog rule".

## Known Technical Debt

- PHPStan level 3, **no baseline** — `phpstan-baseline.neon` was removed; the 50+ legacy ignores were fixed/typed (s3). Do not reintroduce a baseline; fix errors at source.
- Internal-API back-compat scaffolding (the 2 `class_alias` files, the `_deprecated_function` move-shims, the legacy positional registration path) has been **deleted** — clean-break Phase 3 complete + merged to `main` (2026-06-04). Only 3 legitimate `_deprecated_function` misuse-markers remain (`api/class-api-base.php`, `class-lifecycle.php`, `payment-gateway/payment-tokens/class-payment-gateway-payment-token.php`); `Woodev_Plugin_Bootstrap::register_plugin()` survives only as a B-1 mixed-fleet **tombstone** (quarantines legacy v1 callers, never registers).
- `class-payment-gateway.php` (~3,542 lines) — candidate for trait extraction (post-split debt). The whole `woodev/payment-gateway/` tree is ~13.8k lines.
