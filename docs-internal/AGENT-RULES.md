# Agent Rules — Woodev Plugin Framework
> For AI agents. Keep updated. Last updated: 2026-06-21 (s27 — Rule 3 rewritten for v2 loader/autoloader + multi-version conventions).
> Navigation → `DOCS-INDEX.md` | Current status → `CURRENT-STATE.md`

---

## Session Start Checklist

1. Read `CURRENT-STATE.md` — phase status, bugs, next actions
2. Read `GOTCHAS.md` — scan `[topic/*]` tags relevant to current task
3. Read `DOCS-INDEX.md` — identify task-specific docs to load
4. Load relevant skill: `kilo-config` (for Kilo config questions)

---

## Session End Checklist

1. Update `CURRENT-STATE.md` — phase status, bugs, next actions
2. Append to `SESSION-LOG.md` — 10–20 line summary, PHPStan result + commit hash
3. Compilation step — scan SESSION-LOG for new gotchas → `GOTCHAS.md` + `gotchas/{slug}.md`
4. See `DOCS-SCHEMA.md` for full compilation protocol

---

## Workflow Rules

### Discuss Before Coding
Any request not phrased in assertive/imperative form is open for discussion.
- If a request seems like overkill, wrong approach, or has a better alternative — say so **before** implementing
- Ask "why this approach?" when the motivation is unclear
- Only proceed after alignment is reached

### Plan Before Coding
Before writing any new code block:
1. **What** — what component/feature is being built
2. **How** — architecture, file structure, key decisions
3. **Why** — reasoning behind the approach

### Use Serena MCP for PHP Source Navigation (REQUIRED)
**Never use raw `Read` on `.php` files.** Serena MCP provides semantic code navigation:

| Need | Use (Serena) | NOT |
|------|-------------|-----|
| Find a class/function/method | `find_symbol` | Reading whole files |
| Get file structure overview | `get_symbols_overview` | `Read` with offset |
| Search pattern across codebase | `search_for_pattern` | `grep` in Bash |
| Find who uses a symbol | `find_referencing_symbols` | Manual grep |
| Find file by name | `find_file` | `find` / `ls` |

Exception: `Read`, `Glob`, `Grep` built-in tools are fine for markdown, JSON, YAML, and non-PHP files.

### Document After Coding
After implementing each logical code block:
1. Document new gotchas immediately (Don't defer to session end)
2. Update `CURRENT-STATE.md` — honest current status
3. Git commit with Conventional Commits message

### Agent Teams for Parallelism
When a task has **3+ independent workstreams each taking > 2 minutes**, use TeamCreate to spawn parallel agents. Do NOT use Teams for simple single-file edits or inherently sequential tasks.

### Conventional Commits (REQUIRED)
All commits must follow [Conventional Commits](https://www.conventionalcommits.org/) format:
```
feat: add payment gateway admin handler
fix: resolve HPOS order compatibility issue
refactor: extract gateway traits from class-payment-gateway.php
test: add unit tests for bootstrap version sorting
docs: update GOTCHAS.md with PHP 8.1 type gotcha
chore: bump phpstan level to 4
ci: add php 8.3 to test matrix
```

---

## Architecture Rules

### Rule 0 — Backward Compatibility: clean-break policy (CRITICAL)
> Policy set 2026-06-03 (direction audit D-2, ADR-005). **Supersedes the prior "deprecation cycle for everything" rule.** Two different rules apply depending on what you change. Full policy: `CLAUDE.md` → "Backward Compatibility — clean-break policy"; ADR: `adr/005-platform-v2-clean-break-policy.md`.

- **Internal code — FREE TO BREAK on the v2 line:** class names, method signatures, the plugin entry/registration shape, namespacing, file layout. Do **NOT** add `@deprecated` shims, `class_alias` files, or `_deprecated_function()` wrappers for moved/renamed internal APIs — delete existing ones (clean-break Phase 3 already removed them).
- **Installed-site data contracts — RELEASE-BLOCKING, never break:** option keys & settings arrays, license key option names + activation state + instance IDs, updater identity, WC payment-gateway IDs, WC shipping-method IDs + instance setting keys, public action/filter hook names, scheduled cron hooks + recurrence + payload shape, custom DB tables/schemas, REST route namespaces, AJAX action names, admin page slugs, log source names, background-job IDs, order/session meta keys. Preserve these byte-for-byte.

When a plugin is migrated onto v2, enforce the "never break" list via its `docs-internal/migration/<plugin>-data-preservation-checklist.md` — verified at rewrite time, per plugin.

The remaining legitimate `_deprecated_function()`/`_doing_it_wrong()` calls are misuse-markers and clone/wakeup guards, **not** internal-API move-shims — those are allowed.

### Rule 1 — OOP Only
No standalone functions outside bootstrap. Everything is a class method.
- Legacy code: `Snake_Case` classes with no namespace (e.g. `Woodev_Plugin`)
- New code: `Woodev\Framework\*` namespace (PSR-4)

### Rule 2 — Subsystem Pattern
All framework subsystems are initialized in `Woodev_Plugin::__construct()` via `init_*()` methods. Plugins override these to provide their own implementations.

| Subsystem | Init Method |
|-----------|-------------|
| Dependencies | `init_dependencies()` |
| Admin Message Handler | `init_admin_message_handler()` |
| Admin Notice Handler | `init_admin_notice_handler()` |
| License | `init_license()` |
| Updater | `init_updater()` |
| Hook Deprecator | `init_hook_deprecator()` |
| Lifecycle | `init_lifecycle_handler()` |
| REST API | `init_rest_api_handler()` |
| Blocks Handler | `init_blocks_handler()` |
| Setup Wizard | `init_setup_wizard()` |
| Script Handler | `init_script_handler()` |
| Admin Settings | `init_admin()` |

### Rule 3 — Bootstrap, plugin registration & multi-version (post-s27)
`Woodev_Plugin_Bootstrap` (singleton) is the entry point. Never instantiate it directly — use the singleton accessor. `register_plugin()` is a **v1 tombstone only** (quarantines legacy callers; see `bootstrap.php`). v2 plugins register via **`Woodev_Loader::register( __FILE__, [...] )`** (or `register_loader_definition()` directly).

**Plugin type is declared by `extends`, never by a flag/array (s27):**
- pure WordPress → `extends Woodev_Plugin`
- WooCommerce → `extends \Woodev\Framework\Woocommerce_Plugin`
- payment gateway → `extends Woodev_Payment_Gateway_Plugin` (already extends Woocommerce_Plugin)
- shipping → `extends \Woodev\Framework\Shipping\Shipping_Plugin` (already extends Woocommerce_Plugin)

There is **no `capabilities` array** — it was removed in s27. The runtime `Woodev_Framework_Autoloader` resolves base classes on demand from a generated `woodev/class-map.php`. **After adding/renaming any framework class, run `php bin/generate-class-map.php` and commit the map** (gotcha `framework-classmap-autoload-vendored-boot`; no Composer in shipped plugins).

**Multi-version conventions (REQUIRED in every loader definition):**
1. **Always set `version`** (the framework version this plugin bundles) **and `backwards_compatible`** (the oldest framework version this plugin is compatible with). The guard at `resolver:148-153` is skipped if `backwards_compatible` is empty — then a too-old plugin is NOT quarantined.
2. On `plugins_loaded` the resolver loads the **highest** registered framework version for the WHOLE fleet — so framework **classes always come from the highest copy**, regardless of which copy won the bootstrap class rendezvous (the rendezvous winner, first-loaded alphabetically, runs only orchestration; it registers the autoloader against the winning/highest path). A plugin whose bundled framework `version` is **older than the loaded copy's `backwards_compatible`** is deactivated with an "update the outdated plugin" admin notice.
3. **The registration contract is additive-only from v2.0.0.** Future releases may ADD optional fields to the loader definition, but must not remove/rename required ones — an older copy that wins the rendezvous must always be able to read a newer plugin's registration. (This is why B-2 "loader-protocol forward-tolerance" is handled rather than a blocker: highest-wins class loading + additive contract.)

### Rule 4 — Type Declarations
Type declarations are **required** on all parameters and return types. PHP 7.4+ features allowed: `??`, `??=`, arrow functions, typed properties.

```php
// ✅ Correct
public function get_plugin_name(): string {
    return $this->plugin_name;
}

// ❌ Wrong
public function get_plugin_name() {
    return $this->plugin_name;
}
```

### Rule 5 — Docblocks
Docblocks are **required** on all public and protected methods:
- `@since` — version when method was introduced
- `@param` — all parameters with types
- `@return` — return type with description
- `@deprecated` — if applicable, with replacement method

```php
/**
 * Gets the plugin name.
 *
 * @since 1.0.0
 *
 * @return string
 */
public function get_plugin_name(): string {
    return $this->plugin_name;
}
```

### Rule 6 — Pure Methods Static
Methods whose output depends only on their inputs (no `$this` usage, no side effects) should be declared `static`.

```php
// ✅ Static — output depends only on $version
public static function is_valid_version( string $version ): bool {
    return (bool) preg_match( '/^\d+\.\d+\.\d+/', $version );
}
```

---

## PHP/WP Gotchas Summary

| Topic | Description |
|-------|-------------|
| HPOS Compatibility | Use `Woodev_Order_Compatibility` methods, never `get_post_meta()` on orders |
| Yoda Conditions | `if ( true === $var )` — required by WPCS |
| Short Array Syntax | `[]` over `array()` — project standard |
| Null Coalesce | `??` over `isset()` — PHP 7.4+ |
| Late Static Binding | Use `static::class` not `__CLASS__` in abstract classes when called from child |
| Hooks: Prefix Everything | `woodev_{plugin_id}_{hook_name}` — always include plugin ID |
| `_doing_it_wrong()` | Use `Woodev_Hook_Deprecator` for deprecated hooks |
| `__construct()` No Side Effects | Constructor should auto-initialize subsystems, not fire actions |
| Check PHP Extensions | `Woodev_Plugin_Dependencies` has helpers — use them, don't write raw `extension_loaded()` checks |

---

## Testing Rules

| Layer | Tool | When |
|-------|------|------|
| Unit tests | Brain Monkey + Mockery | PHP logic without WP |
| Integration tests | `wp-env` + `WP_TESTS_DIR` | Full WP stack testing |
| Static analysis | PHPStan (level 3, PHP 7.4+) | Every commit |
| Code style | PHPCS (WordPress + PHPCompatibility) | Every commit |

Test fixtures live in `tests/_fixtures/` — three minimal plugins: `woodev-test-plugin`, `woodev-test-payment-gateway`, `woodev-test-shipping-method`.

Run a single test:
```bash
./vendor/bin/phpunit tests/unit/BootstrapTest.php
```

Run all checks:
```bash
composer check   # phpcs + phpstan + unit tests
```

---

## Related

- `CLAUDE.md` — project overview, commands, architecture, coding conventions
- `DOCS-INDEX.md` — navigation hub, session start/end protocol
- `DOCS-SCHEMA.md` — doc format rules, lint checklist, compilation protocol
