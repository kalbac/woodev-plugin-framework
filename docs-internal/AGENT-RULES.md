# Agent Rules — Woodev Plugin Framework
> For AI agents. Keep updated. Last updated: 2026-09-04 (s115: Rule 3 names `framework_version`; Codex is not critic-only).
> Navigation → `DOCS-INDEX.md` | Current status → `CURRENT-STATE.md`

---

## Session Start Checklist

> Canonical list: `AGENTS.md` → "Session Start". This checklist mirrors it — do not let them diverge.

1. Read `next-session-prompt.md` — per-session handoff: what the last session left, plus known traps
2. Read `CURRENT-STATE.md` — phase status, bugs, next actions
3. Read `GOTCHAS.md` — scan `[topic/*]` tags relevant to current task
4. Area-specific docs as needed — relevant `adr/` and `wiki/` files (navigation hub: `DOCS-INDEX.md`)
5. Load the relevant project skill from `.ai/skills/` when the task matches one: `woodev-framework-backend-dev`, `woodev-framework-code-review`, `woodev-framework-dev-cycle`, `woodev-framework-git`, `woodev-framework-markdown`

---

## Session End Checklist

> Canonical list: `AGENTS.md` → "Session End". This checklist mirrors it — do not let them diverge.

1. Update `CURRENT-STATE.md` — phase status, bugs, next actions
2. Write `sessions/sNN.md` — the session write-up, PHPStan result + commit hash; add one index line to `SESSION-LOG.md`
3. Compilation step — scan the new session file for gotchas → `gotchas/{slug}.md` + one index line in `GOTCHAS.md`
4. Audit the board — move the session's cards (`В работе` → `Готово`), file cards for anything unformalized (see `AGENTS.md` → "Backlog rule")
5. Replace `next-session-prompt.md` with the handoff for the next session
6. See `DOCS-SCHEMA.md` for full compilation protocol

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

**And for whole directories Serena is configured to ignore.** `.serena/project.yml` sets
`ignored_paths` to **`tests/**`, `docs/**`, `.github/**`, `.ai/**`, `.serena/**`, `.claude/**`**.
A symbolic operation against any of them fails flatly with `… while the path is ignored` — so
there `Read`/`Edit`/`Write` are not a fallback, they are **the only tool, and using them is not a
rule violation**. Every brief that touches test files must say so; one that repeats the bare
"never `Read` a `.php` file" line is asking for the impossible and costs the worker a detour.
Recorded in s105, and it cost a worker time again in s112 because this rule text still omitted it —
which is why the exception now lives here rather than only in the gotcha. Detail, including how to
tell this apart from a genuine Serena outage: gotcha
`serena-refuses-the-tests-directory-so-the-never-read-php-rule-cannot-apply-there`.

**Enforcement (operator decision, s60 / 2026-08-09):** this rule is mandatory, not best-effort —
measured effect is fewer mistakes and fewer tokens. Three hard requirements:

1. **Verify at session start** that Serena is actually connected (`find_symbol` /
   `get_symbols_overview` present in the tool list, including deferred tools). If it is NOT
   connected, **report it to the operator before doing any PHP work** — a missing Serena is an
   environment defect to fix, not a license to fall back to `Read`/grep. (s45–s59 drifted exactly
   this way: Serena silently disappeared from sessions and agents fell back without telling anyone.)
2. **Propagate into subagent briefs:** every brief for a task that touches PHP source MUST repeat
   this rule. A brief without it is a defective brief — the orchestrator is responsible.
   **Substitute the worker's OWN worktree path when you do.** Copying `D:/Projects/woodev_framework`
   into a brief for a worker running elsewhere sends its Serena edits into the main tree while its
   git work stays in its worktree — silently, with no error (s83, gotcha
   `serena-activate-path-must-be-the-worker-s-worktree`). Require the worker to verify activation
   by checking that a `find_symbol` result reports a path under its own worktree.
3. **Fallback is an exception, not a routine:** if Serena errors on a specific call, note it in the
   session log and only then use built-in tools for that call.

### Document After Coding
After implementing each logical code block:
1. Document new gotchas immediately (Don't defer to session end)
2. Update `CURRENT-STATE.md` — honest current status
3. Git commit with Conventional Commits message

### Subagent-Driven Execution for Parallelism
When a task has **3+ independent workstreams each taking > 2 minutes**, use subagent-driven
execution. Do NOT parallelize simple single-file edits or inherently sequential tasks.

**The shape (operator decision, s83): worker = Sonnet 5, critic = Codex, and nobody accepts their
own work.** ⚠ That is the DEFAULT pairing, not a cap on Codex: the critic-only restriction was
lifted 24.08.2026 and Codex is a full worker again — see `CLAUDE.md` → Orca for which model gets
which task. The invariant that survives both is the last clause: nobody accepts their own work.
Run it through Orca orchestration, not in-process subagents — the worker keeps its own
context in its own terminal and the orchestrator reads only the `worker_done` report. Full recipe,
placement rules and traps: `wiki/orchestrating-agents-with-orca.md`. Never recall an `orca` flag
from memory; the binary serves its own version-matched guide via `orca skills get orchestration`.

**Placement is the orchestrator's responsibility.** Name each worker's expected file set before
starting a wave; any two that overlap get separate worktrees or a `--deps` chain. A worker cannot
know what another worker is editing — dispatching two into one tree and hoping is how s82 lost
finished work (gotcha `two-agents-one-file-is-the-orchestrator-s-bug`).

Jest caveat: run `npm run test:js`, never `npx jest` (gotchas `jest-scans-agent-worktrees-inside-the-repo`, `npx-jest-bypasses-wp-scripts-jsdom`). Orca worktrees under `.orca/worktrees/` live inside the repo and are a full checkout, `tests/js/` included; `jest-unit.config.js` scopes `roots` to `<rootDir>/tests/js` so a bare run no longer counts them, but `npx jest` still loses the wp-scripts jsdom environment either way. And a fresh Orca worktree has **no `node_modules/`** until the worker runs `npm ci` — the repo has no Orca setup hook.

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
> Policy set 2026-06-03 (direction audit D-2, ADR-005). **Supersedes the prior "deprecation cycle for everything" rule.** Two different rules apply depending on what you change. Full policy: `adr/005-platform-v2-clean-break-policy.md`.

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

**Naming conventions the generator enforces (#647):** a class's directory must match its namespace,
and its file name must not repeat what the file's own kind-prefix already says. The generator exits
non-zero on a violation — the exact rule table (including the two directory aliases and the small
grandfather lists of pre-#647 exceptions) lives in `bin/generate-class-map.php`; don't restate it here.
1. **A namespace segment maps to a directory of the same name, unless aliased.** E.g.
   `Woodev\Framework\Shipping\*` lives under `woodev/shipping-method/`, not `woodev/shipping/`.
2. **`Abstract_` is dropped from the file name** — the `abstract-` file prefix already says it:
   `Abstract_Shipment_Handler` → `abstract-shipment-handler.php`, not `class-abstract-shipment-handler.php`.
3. **`Woodev_` is dropped from a legacy (un-namespaced) class's file name:**
   `Woodev_API_Base` → `woodev/api/class-api-base.php`, not `class-woodev-api-base.php`.

**Multi-version conventions (REQUIRED in every loader definition):**
1. **Always set `framework_version`** (the framework version this plugin bundles) **and `backwards_compatible`** (the oldest framework version this plugin is compatible with). The guard at `resolver:148-153` is skipped if `backwards_compatible` is empty — then a too-old plugin is NOT quarantined. ⚠ **The definition field is `framework_version`; `version` is only the name it is mapped to internally** (`class-framework-plugin-loader-definition.php:258`) — this rule said `version` until s115, which does not match the contract the validator enforces. Required fields, per that validator (`:278`): `plugin_id`, `plugin_name`, `plugin_version`, `framework_version`, `plugin_file`, `platform`, `requirements`. ⚠ And the definition may contain **no framework constant at all** — gotcha `a-loader-definition-cannot-use-a-framework-class-constant`.
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
- `@since` — the **planned release** the change ships in, currently `2.0.2`. It is NOT the
  `Woodev_Plugin::VERSION` constant: `VERSION` records the *released* version (`2.0.1`) and lags on
  purpose, because raising it on `main` publishes a release (#285). Operator decision, #409 (s83) —
  the earlier "uses current VERSION" wording contradicted 1388 tags against one and was wrong.
- **Inherited code carries `1.0.0`, never an upstream number** (#116a, s111). This framework was
  forked with its upstream's docblocks, and that upstream's ladder ran to 5.x. Seven members read
  `@since 3.0.0`/`4.0.0`/`5.2.0` — numbers ABOVE the released `2.0.1`, so anything comparing
  versions concluded the API was unreleased. In THIS repo's history those members exist since the
  initial import (`01dfbe7`), which is exactly what `1.0.0` already marks in 151 other places.
  `2.0.2` would be equally untrue: it claims the API is new in the coming release.
- The machine-readable authority for the planned release is `composer.json` →
  `extra.woodev.planned-release`, and `tests/unit/SinceTagCeilingTest.php` gates every `@since` in
  `woodev/**` against it (#752) — the #116a sweep above had no gate and regressed within one night.
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

### Rule 7 — The framework owns the checkout address fields, and WooCommerce's own setting decides which column

**Settled by the operator: 7a/7b twice (s44, and again s86), 7c in s87 (#475). 7b came back for
re-litigation once already because it was recorded only in a session file — do not re-open any of
them.**

**7a. Shared settings live in the framework, never in a carrier plugin.** That is the whole point:
several carriers run side by side, and per-carrier copies of a shared option make them fight over
it — the failure mode observed in the production plugins. A setting that describes the SHOP (not
one carrier's transport) belongs here.

**7b. Which checkout column the location cascade attaches to is derived from
`woocommerce_ship_to_destination`, never declared per field:**

| `woocommerce_ship_to_destination` | The cascade attaches to |
|---|---|
| `billing_only` ("Force shipping to the customer billing address") | **billing only** |
| anything else | **both billing and shipping** |

Note the second row is **both columns**, not "whichever one determines delivery". A plugin author
does not choose the section for a field declared with `source_location()`.

**Do not derive this from `Address_Target::resolve()`.** That class answers a DIFFERENT question —
where to WRITE a chosen pickup point's address — and therefore returns exactly one prefix
(`billing` or `shipping`). One target versus a set of columns; the two rules coincide in the
`billing_only` row and diverge everywhere else.

**Keep two questions apart.** "Which columns is the cascade attached to" (this rule, a shop
setting) is not "which column is active right now" (the live «Ship to a different address»
checkbox, which `location-cascade.js` already resolves in `activeAddressSection()`).

Background for 7b, in WooCommerce's own code (`class-wc-checkout.php`): `get_posted_address_data()`
returns the billing value for a shipping key when `ship_to_different_address` is false, that flag is
forced false in `billing_only`, and the shipping fieldset is skipped entirely. In RU/CIS billing IS
the delivery address, so a rule that always wrote `shipping_*` would write nowhere visible.

**7c. ONE live cascade, and it follows the active column — settled by the operator, s87 (#475).**

The fields exist on both columns per 7b. The live widget and the chain do **not**: exactly one
cascade is live at a time, on the column that currently determines delivery, and it MOVES when that
column changes.

*The alternative was considered and rejected:* two simultaneously live cascades, one per column. The
engine keys `records` / `unresolved` / `clearedByEdit` / `pendingRecord` by LEVEL, so that would mean
re-keying by `[section][level]` plus a second single-flight `/select` queue — and it would force a
data-contract answer on whether `woodev_customer_location` is one record per customer or one per
column. It buys nothing: the customer edits one address at a time, and when the checkbox is
unchecked the other column is not an independent address at all — WooCommerce copies billing into
it, so an independent cascade there would fight that copy.

**Which column is active is decided by the LIVE checkbox, and by nothing else.** Verified against
WooCommerce's own source, because the setting name invites the opposite reading:

| Fact | Where |
|---|---|
| `wc_ship_to_billing_address_only()` is literally `'billing_only' === woocommerce_ship_to_destination` | `wc-order-functions.php:544` |
| `ship_to_different_address` = the posted checkbox **AND NOT** `billing_only` — so under `billing_only` it is forced off whatever is posted | `class-wc-checkout.php:767` |
| With that flag false the whole shipping fieldset is skipped | `class-wc-checkout.php:742` |
| With that flag false, `get_posted_address_data()` returns the **billing** value for a shipping key | `class-wc-checkout.php:1391` |
| `woocommerce_ship_to_destination` sets only the checkbox's DEFAULT state (`shipping` → checked) | `templates/checkout/form-shipping.php:26` |

So `woocommerce_ship_to_destination` never picks the column. It does exactly two things: at
`billing_only` it stops the checkbox existing, and otherwise it seeds the checkbox's default. The
live checkbox is the only thing that picks the column — which is what `activeAddressSection()`
already computes.

**Switching must work in BOTH directions, live.** A customer who fills billing with the box
unchecked (rates already calculated from it) and then checks it must not be dropped, and neither
must the reverse.

**And the chain's RECORDS must move with it, not just the widget.** WooCommerce copies billing into
shipping when the box is unchecked, so on a toggle the customer sees the TEXT carried over — but the
picked-locality identity lives in our chain, not in the field text. Move the widget without moving
the records and the customer gets filled fields plus a re-locked address field: exactly the failure
#337 and #459 were about. Carrying the records is part of this rule, not an optimisation.

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
| JS tests | jest — `npm run test:js` (800 tests; `jest-unit.config.js` scopes `roots`). **Never `npx jest`** — two recorded gotchas (`npx-jest-bypasses-wp-scripts-jsdom`, `jest-scans-agent-worktrees-inside-the-repo`) | React admin UI / JS logic |
| Integration tests | `wp-env` + `WP_TESTS_DIR` | Full WP stack testing |
| Static analysis | PHPStan (level 3, PHP 7.4+) | Every commit |
| Code style | PHPCS (WordPress + PHPCompatibility) | Every commit |

Test fixtures live in `tests/_fixtures/` — seven plugins: `woodev-test-plugin`, `woodev-test-payment-gateway`, `woodev-test-shipping-method`, `woodev-edostavka-pilot-plugin`, `woodev-realistic-payment-plugin`, `woodev-realistic-shipping-plugin`, `woodev-yandex-pilot-plugin`.

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

- `CLAUDE.md` — Claude Code entry point: Serena/Context7 tooling and a lookup table (no project reference material)
- `wiki/architecture.md` — subsystems, base classes, seams
- `DOCS-INDEX.md` — navigation hub, session start/end protocol
- `DOCS-SCHEMA.md` — doc format rules, lint checklist, compilation protocol
