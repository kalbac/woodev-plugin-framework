# Agent Rules — Woodev Plugin Framework
> For AI agents. Keep updated.
> Navigation → `DOCS-INDEX.md` | Current status → `CURRENT-STATE.md`

---

## Session start and end

**The checklists live in `AGENTS.md` → "Session Start" / "Session End" and nowhere else.** This file
used to carry mirrored copies "that must not diverge"; the s135 docs audit found both had diverged
(the end list had lost two of the seven steps, the start list had gained a step the canonical one
never had). A mirror is a second copy, and a second copy drifts — so there is none.

`.ai/skills/` holds task guides for agents that load skills from that directory. Claude Code does
not: `.claude/skills` is not a working link to it.

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

Jest caveat: run `npm run test:js`, never `npx jest` (gotchas `jest-scans-agent-worktrees-inside-the-repo`, `npx-jest-bypasses-wp-scripts-jsdom`). Orca worktrees under `.orca/worktrees/` live inside the repo and are a full checkout, `tests/js/` included; `jest-unit.config.js` scopes `roots` to `<rootDir>/tests/js` so a bare run no longer counts them, but `npx jest` still loses the wp-scripts jsdom environment either way. A fresh Orca worktree needs **no install step**: `orca.yaml` shares `node_modules` and `.worktreeinclude` copies `vendor` (`CLAUDE.md` → Orca, fact 1).

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
The base subsystems are initialized in `Woodev_Plugin::__construct()` via `init_*()` methods; a platform base adds its own in its constructor (`Woocommerce_Plugin` → Blocks). Plugins override these to provide their own implementations.

| Subsystem | Init Method |
|-----------|-------------|
| Dependencies | `init_dependencies()` |
| Admin Message Handler | `init_admin_message_handler()` |
| Admin Notice Handler | `init_admin_notice_handler()` |
| Settings page | `init_settings_page()` |
| Hook Deprecator | `init_hook_deprecator()` |
| Lifecycle | `init_lifecycle_handler()` |
| Translations | `init_translation_handler()` |
| Cron | `init_cron_handler()` |
| REST API | `init_rest_api_handler()` |
| Blocks Handler | `init_blocks_handler()` — called by `Woocommerce_Plugin`'s constructor, not the base's |
| Setup Wizard | `init_setup_wizard_handler()` |
| Competitor detection | `init_competitor_handler()` |
| License | `init_license_handler()` (the updater is built separately, `construct_updater()`) |

Plus two hook callbacks a plugin overrides, which the constructor does NOT call:
`init_plugin()` on `plugins_loaded` (15) and `init_admin()` on `admin_init` (0).

⚠ Re-derived from `woodev/class-plugin.php` in s135: the previous table named `init_license()`,
`init_updater()`, `init_setup_wizard()` and `init_script_handler()`, none of which exist. When this
table and the file disagree, the file wins — list its `init_*` methods before trusting a row.

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

### Rule 8 — A plugin's settings live on `Woodev → Настройки` by default; the WooCommerce «Интеграции» tab stays available for the cases that need it

**Settled by the operator, 05.09.2026 (#777), in his own words:**

> По умолчанию настройки карьера (и не только карьера) мы строим в `Woodev → Настройки`, но при
> этом от `WooCommerce → Настройки → Интеграции` мы **не отказываемся** полностью, а используем
> этот раздел **при необходимости**.

So there is a default and there is an exception, and the exception is deliberate rather than
forbidden. Two consequences for anyone writing a plugin on v2:

**The default has a seam — use it.** `Woodev_Plugin::get_settings_providers()`
(`woodev/class-plugin.php`, `@since 2.0.2`) returns `Settings_Provider[]` and defaults to `[]`. The
plugin overrides it; `Woodev_Plugin` already calls
`Settings\Settings_Page_Registry::instance()->register_plugin( $this )`, and the page is served over
`woodev/v1/settings`. A multi-carrier plugin returns several providers, one tab each. **This is
where new settings go unless there is a reason to go elsewhere.**

**The exception also has a seam.** `Shipping_Plugin::get_integration_handler()` returns `null` in
the base, and `Shipping_Plugin::add_hooks()` only registers `woocommerce_integrations` when a plugin
returns a `Settings\Shipping_Integration`. A plugin that overrides nothing never appears on the
WooCommerce tab. Storage is `woocommerce_{plugin_id}_settings` (`WC_Settings_API::get_option_key()`),
which is an installed-site data contract — see Rule 0 before moving an existing plugin's fields off
that tab.

⚠ **«При необходимости» is deliberately left to judgement — do NOT invent a hard criterion here and
present it as his rule.** Bring the concrete case to him instead. What is settled is the DEFAULT and
the fact that the tab is not deprecated; the boundary between them is not settled and was not asked
for.

⚠ **The rig fixture on that tab is CORRECT and must not be "fixed" away.**
`tests/_fixtures/woodev-test-shipping-method/class-test-cdek-integration.php`
(`Woodev_Test_Cdek_Integration`, CDEK test-contour Client ID/Secret) predates this rule — it came
from #375, where the operator objected to those OAuth keys rendering inside the «Локация» section
even though they authenticate every CDEK call. It is the rig's live example of the exception, and
whether a real plugin would place the same fields there is exactly the judgement call above. Do not
migrate it as tidy-up; that would remove the only working demonstration of the mechanism.

### Rule 9 — Reusable framework JS is PHP-driven; the fixed admin React UI is exempt

**Design principle (OB-4, operator's dump s13, card #107).** In the operator's own words:

> Scripts that exist to be REUSED between plugins — the PVZ/pickup-map builder for shipping methods
> is the live example — are designed as PHP-driven as possible: configuration and markup come from
> PHP, hand-written JS is kept to a minimum. Exception: the fixed, framework-owned admin UI (the
> React «Woodev → Лицензии» page) stays React. The principle does NOT extend to it.

**Verified against the live example before being written down (s121).** The pickup-map builder
(`woodev/shipping-method/assets/js/frontend/pickup-{mount,datasource,geo,panels}.js`) is large —
`pickup-mount.js` and `pickup-panels.js` alone run to several thousand lines — but that size is DOM
orchestration and browser-side interaction, not domain logic duplicated from PHP. Every
customer-facing string, every strategy knob, every domain-specific behaviour is assembled in
`Pickup_Handler::get_js_config()` and handed across as one `wp_localize_script()` config global
(`woodev_pickup_config_*`): the i18n map runs through the `woodev_pickup_map_i18n` filter so a
plugin can override framework wording with carrier-specific language, `search_enabled` and
`max_accumulated` are filtered knobs, and the map provider's own script config
(`class-yandex-map-provider.php::get_js_config()`) contributes only what that provider needs. The JS
reads these by name and never hardcodes a customer-facing string or a domain rule — a missing key
renders blank rather than falling back to a JS-side default. **So "kept to a minimum" means no
business/domain logic or copy embedded in JS, not a line-count target** — an interactive map
inherently needs substantial DOM/event-handling code no matter how PHP-driven its config is.

**The exception held too.** The React admin UI (`Woodev → Лицензии` and its siblings — `license-page`,
`settings-page`, `setup-wizard`, `plugins-page`) is still built with the WordPress-bundled
`@wordpress/element` React, matching `docs-internal/archive/PLANS.md` §6's "embedded WordPress/WooCommerce
React, not a separate ReactJS" note — the only place that archived plan already touched this
principle, and only its admin-UI half; it never mentioned the PHP-driven-reusable-JS half, which is
why this rule exists.

**The seam this principle actually asks for:** a script meant to be reused across plugins gets its
configuration, markup strings and domain wording from PHP — via `wp_localize_script()` plus a
filterable string map, the way `get_js_config()` above does it — rather than deciding them itself or
duplicating a PHP-side rule in JS. This is a design principle for NEW reusable JS, not a mandate to
rewrite `woodev/**/assets/js/frontend/**` — and it is a different axis from `AGENTS.md`'s
`Frontend (src/)` TypeScript-scope rule (#542): that rule is about which LANGUAGE a file is authored
in, this one is about where its CONFIGURATION lives. `woodev/**/assets/js/frontend/**` (raw-served,
out of TypeScript scope) is exactly where this principle applies.

### Rule 10 — Merchant-facing copy: the label, the two help slots, and the vocabulary

**Moved here from `AGENTS.md` → Conventions in s122 (#799), verbatim.** These three rules are the
operator's, they are long, and they are needed only when you are actually writing settings copy —
which is not every session. `AGENTS.md` keeps a one-line pointer to each; the reading-budget gate on
that file is what forced the split, and it is working as intended.

#### Rule 10a — Settings label

**Short essence, not a full sentence — three layers do the work together** (operator rule, 31.08.2026). (1) The LABEL carries the essence and fits one line — usually 2 words, 3 if short; it is allowed to be not fully self-explanatory on its own. (2) The CONTROL TYPE carries part of the meaning: a checkbox already tells the merchant something is switched on or off, so a leading «Разрешить…»/«Включить…» in the label is wasted. (3) The `desc_tip` carries the full explanation **when one is needed** — see the row below; an option that explains itself gets no tooltip, and a `description` may sit alongside the tooltip rather than instead of it. Worked example: «Разрешить использовать города не из списка» → label «Города вне списка» + tooltip «Включите эту опцию, если хотите разрешить покупателям использовать города, которых нет в списке». ⚠ Do NOT generalise this into one formula such as «every label names an action» — that was tried and rejected the same day; naming the action suits a mode `select`, not a checkbox.

#### Rule 10b — Settings help text

**Two slots that COEXIST — `description` is not an alternative to the tooltip** (operator, 25.08.2026, corrected 31.08.2026). **`tooltip`/`desc_tip`** is the default home for an explanation — `tooltip` on `register_control()` here, `desc_tip` in a WooCommerce `form_fields` array. Used *almost* always, but it is NOT mandatory: an option that genuinely needs no explanation gets none (rare). **`description`** is the inline slot WooCommerce renders on the page rather than behind a hover, and it earns its place when the text must be **reachable or unmissable**: (1) the reader must follow a link; (2) the reader must COPY a value — the live case is an option displaying the webhook URL to paste into the provider's account; (3) the text must be SEEN, because a tooltip is not always read — e.g. «Не включайте эту опцию без необходимости. При её включении в лог записывается большее количество данных». One option may carry both slots at once.

#### Rule 10c — Merchant-facing vocabulary

**No jargon in anything a merchant reads** — labels, tooltips, `description`s, admin notices (operator rule, 31.08.2026). Two words specifically, because both were in shipped copy: **«чекаут» → «форма оформления заказа»** (and «на классическом/блочном чекауте» → «в классической/блочной форме оформления заказа»), and **«фреймворк» must not appear at all** — *«Люди вообще не знают что такое фреймворк»*; name the actor «плагин», or drop it. This is about the READER, so it does not touch code comments, docblocks, log lines or exception texts, where the words are precise and the audience is us. Swept clean once in s109 (15 strings across 5 files); a new one is a review defect, not a nit.

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
| JS tests | jest — `npm run test:js` (`jest-unit.config.js` scopes `roots`; the current count is a `CURRENT-STATE.md` baseline, not a number to copy here). **Never `npx jest`** — two recorded gotchas (`npx-jest-bypasses-wp-scripts-jsdom`, `jest-scans-agent-worktrees-inside-the-repo`) | React admin UI / JS logic |
| Integration tests | `wp-env` + `WP_TESTS_DIR` | Full WP stack testing |
| Static analysis | PHPStan (level 3, PHP 7.4+) | Every commit |
| Code style | PHPCS (WordPress + PHPCompatibility) | Every commit |

Test fixtures live in `tests/_fixtures/` — **eight** plugins: `woodev-test-plugin`, `woodev-test-payment-gateway`, `woodev-test-shipping-method`, `woodev-edostavka-pilot-plugin`, `woodev-realistic-payment-plugin`, `woodev-realistic-shipping-plugin`, `woodev-yandex-pilot-plugin`, `woodev-entry-path-fixture` (the v2 entry path's in-repo consumer, #763). `tests/_fixtures/dadata/` is JSON response data, not a plugin — count the directories carrying a `Plugin Name:` header, not the directories.

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
