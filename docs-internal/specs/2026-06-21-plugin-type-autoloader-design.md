# Spec — Framework runtime autoloader + plugin type via inheritance

> Status: **DRAFT — pending operator review** · Date: 2026-06-21 (s27) · `@since 2.0.2`
> Resolves PLANS.md §5 open questions (bootstrap fate + plugin-type declaration), interim step.
> Origin: s27 brainstorm. Decision = **variant C** (progressive path toward the godaddy model).

---

## 1. Context & problem

PLANS.md §5 carried two unresolved open questions, blocking the draft from becoming a real spec:

1. **Fate of `bootstrap.php`** — looks like a superfluous layer in v2.
2. **Plugin-type declaration** — currently via an `is_payment_gateway`-style flag / a
   `capabilities` array; described as awkward and easy to desync.

The s27 brainstorm + a study of the upstream godaddy fork
(`godaddy-wordpress/wc-plugin-framework`, see §9) reframed both as **one shared root cause**:

| Pain | Why it exists today | What removes it |
|---|---|---|
| `bootstrap` must **arbitrate framework versions** | classes are single un-versioned `Woodev_*` → name collision → someone must pick one copy | versioned namespaces (deferred — see §8) |
| a `capabilities` array is needed (a hint telling the resolver **which base-class file to `require` before the subclass parses**) | **no runtime autoloader** — bases are wired by manual `require` | **a runtime autoloader** (this spec) |

So the type a plugin declares is currently stated **twice**: once by `extends`, and again
in the `capabilities` array. This spec removes the duplication by introducing the missing
autoloader, making `extends` the single source of type truth.

### Decisions locked in the brainstorm

- **Type is declared by `extends` in the entry file** — the class hierarchy IS the type.
  The hierarchy already exists in code (see §2); nothing to build there.
- **`spl_autoload_register`, hand-written, NO Composer in shipped plugins.** Composer is
  dev/test only; in shipped plugins it would pull runtime dependencies we do not want.
  The autoloader is a lightweight path-map into the **selected** framework copy.
- **Keep `highest-version-wins` arbitration on this interim step** (variant A of brainstorm
  Q3). The typed class therefore stays **deferred** (declared inside the registration
  callback, loaded only after the winning copy is chosen). The autoloader resolves
  `extends` to that winning copy.
- **Versioned namespaces + thin one-file loader = the END state**, deferred to its own
  large phase (§8). This spec is the honest first increment toward it (autoload first,
  namespaces later).

---

## 2. Current state (ground truth, verified)

### 2.1 Class hierarchy — ALREADY the desired shape

```
Woodev_Plugin                                              woodev/class-plugin.php          (pure WP)
 └─ Woodev\Framework\Woocommerce_Plugin extends \Woodev_Plugin
                                                           woodev/class-woocommerce-plugin.php:23
     ├─ Woodev_Payment_Gateway_Plugin extends \Woodev\Framework\Woocommerce_Plugin
                                                           woodev/payment-gateway/class-payment-gateway-plugin.php:34
     └─ Woodev\Framework\Shipping\Shipping_Plugin extends \Woodev\Framework\Woocommerce_Plugin
                                                           woodev/shipping-method/class-shipping-plugin.php:20
```

Missing only: `Woodev_EDD_Plugin extends Woodev_Plugin` — **out of scope** (S4 EDD, deferred;
loader currently rejects the `edd` platform outright, `class-framework-plugin-loader-definition.php:325-327`).

### 2.2 Registration / boot flow (the canonical v2 entry shape)

Reference: `tests/_fixtures/woodev-test-payment-gateway/woodev-test-payment-gateway.php`.

1. Resolve framework dir, `require_once .../woodev/bootstrap.php` (lines 27-41).
2. Build a loader-definition **array** including `platform`, `requirements`, `main_class`,
   `callback`, and **`capabilities`** (lines 48-67).
3. **Mixed-fleet B-1 probe**: if the winning bootstrap copy lacks `register_loader_definition`
   (a legacy v1 copy won), show an admin notice and bail — no fatal (lines 129-162).
   This boilerplate is copy-pasted into every entry file today.
4. `register_loader_definition( ... )` (line 163).
5. The **`callback`** (`..._plugin_init`, lines 165-233) is what DECLARES the typed class
   `final class X extends Woodev_Payment_Gateway_Plugin` and instantiates it.

### 2.3 The code the autoloader replaces

`Framework_Resolver::load_early_capability_classes()`
(`woodev/class-framework-resolver.php:526-575`) reads `capabilities` and hard-`require`s,
from the winning copy:
- `class-woocommerce-plugin.php` + `class-woocommerce-helper.php` (any WC capability),
- `payment-gateway/class-payment-gateway-plugin.php` (payment_gateway capability),
- `shipping-method/class-shipping-plugin.php` (shipping_method capability).

It runs **before** `invoke_plugin()` (582-612) fires the callback, so the base class exists
by the time `extends` parses. This whole method becomes unnecessary once a framework
autoloader is registered.

### 2.4 `capabilities` consumers (everything that changes)

- `Framework_Plugin_Loader_Definition`: field + `get_capabilities()` + `normalize_capabilities()`
  + `CAPABILITY_*` constants + validation (`class-framework-plugin-loader-definition.php`
  :28-31, :64, :89, :231-233, :341-354, :381-388, :431-433).
- `Framework_Resolver::load_early_capability_classes()` (only behavioral consumer).
- All three fixtures (`woodev-test-plugin`, `woodev-test-payment-gateway`,
  `woodev-test-shipping-method`) + any unit tests asserting on capabilities.

**Not a consumer:** nothing persists `capabilities` to the DB; it is registration-time
metadata only.

---

## 3. Design

### 3.1 Framework runtime autoloader (core)

A new lightweight autoloader, registered by the bootstrap/resolver **once the winning
framework copy is known**, mapping framework class names → files **inside that copy**.

- **Scope:** framework classes only — both legacy `Woodev_*` and PSR-4 `Woodev\Framework\*`
  (incl. `Woodev\Framework\Shipping\*`). Plugin-owned classes are **not** framework classes
  and remain defined by the plugin (its callback / its own `require`s). The autoloader must
  return silently for any class it does not own (never warn, never block other autoloaders).
- **Source of truth for paths:** the selected framework copy's root
  (`$framework_plugin['path'] . '/woodev'`), the same path `load_early_capability_classes()`
  uses today. One winning copy → one registered autoloader → no multi-version ambiguity.
- **Mechanism:** `spl_autoload_register` with a hand-written resolver. NO Composer.
  - PSR-4 segment: `Woodev\Framework\…` → path under `/woodev/…` using the project's existing
    file-naming convention (`class-foo-bar.php`, `interface-…`, `abstract-…`, `trait-…`).
  - Legacy segment: `Woodev_*` → the existing flat/located file map. Because legacy files do
    **not** follow a single deterministic path rule, the legacy side needs an explicit
    **class→file map** (generated/maintained, see §3.1.1), not a pure convention transform.
- **Idempotency / multi-boot safety:** register exactly once; guard with a static flag and a
  `class_exists(..., false)` short-circuit so a class already `require`d by `includes()` is
  never double-loaded.

> The autoloader **complements**, does not replace, the existing `includes()` require-chains
> in `class-plugin.php` this session. `includes()` stays authoritative for load order;
> the autoloader is the safety net that makes a bare `extends Woodev_Payment_Gateway_Plugin`
> resolve without a `capabilities` hint. Migrating `includes()` itself onto pure autoload is a
> later cleanup (pairs with the namespaces phase, §8), NOT in scope here.

#### 3.1.1 Legacy class→file map — open implementation choice

The PSR-4 part is a pure transform. The legacy `Woodev_*` part is not (irregular paths).
Two options, to be settled in the plan:

- **(a) Static generated map** — a `woodev/class-map.php` array (`'Woodev_Foo' => 'path/...'`),
  regenerated by a dev script + asserted complete by a unit test. Fast, explicit, but a file
  to keep in sync.
- **(b) Convention + targeted overrides** — derive most paths from the class name, with a small
  override array for the irregular cases. Less to maintain, but the derivation rules must cover
  the real tree.

Recommendation: **(a)** — explicit and test-enforced; a "every framework class is in the map"
test prevents the silent production WSOD class we have hit before
(gotcha `box-packer-interface-unwired-in-includes`).

### 3.2 Type via `extends` only — remove `capabilities`

- Delete the `capabilities` field, accessor, normalizer, `CAPABILITY_*` constants, and the
  capability/platform cross-validation from `Framework_Plugin_Loader_Definition`.
- Delete `Framework_Resolver::load_early_capability_classes()` and its call site; the
  autoloader now covers base-class availability.
- **`platform` STAYS** in the definition — it is a coarse pre-load hint genuinely needed
  before any class loads (WC-active check + early `supported_features` feature declaration on
  `before_woocommerce_init`). It is NOT the fine-grained type and is not made redundant by
  `extends`. `supported_features` (hpos/blocks) likewise stays as early metadata.
- This is an **internal-API break** (registration shape). Per the clean-break policy it is
  free to break: `capabilities` is never persisted, and the only consumers are our own
  fixtures + plugins (which are being rewritten onto v2 anyway).

### 3.3 Thin `loader.php` facade (ergonomics — the visible payoff)

Centralize the boilerplate that is copy-pasted into every entry file (framework-dir
resolution, bootstrap require, the B-1 mixed-fleet probe) behind one helper the plugin
requires:

```php
// my-plugin.php (the WP plugin-header file)
require_once __DIR__ . '/woodev/loader.php';

Woodev_Loader::register( __FILE__, [
    'plugin_id'      => 'my-plugin',
    'plugin_name'    => 'My Plugin',
    'plugin_version' => '1.0.0',
    'platform'       => 'woocommerce',
    'requirements'   => [ 'php' => '7.4', 'wordpress' => '6.3', 'woocommerce' => '7.0' ],
    'main_class'     => 'My_Gateway_Plugin',
    'callback'       => 'my_gateway_plugin_init',   // declares the typed class, see below
    // NO 'capabilities' — type comes from `extends` in the callback
    'supported_features' => [ 'hpos' => true, 'blocks' => [ 'cart' => true, 'checkout' => true ] ],
] );

function my_gateway_plugin_init(): void {
    final class My_Gateway_Plugin extends Woodev_Payment_Gateway_Plugin { /* domain only */ }
    My_Gateway_Plugin::instance();
}
```

`Woodev_Loader::register()` internally: resolves the framework dir, requires `bootstrap.php`,
runs the B-1 probe (admin notice + bail if a legacy copy won), then calls
`register_loader_definition()`. The callback-defines-the-class pattern is preserved (it is
what keeps the typed class **deferred** until after arbitration — §1 decision).

> Scope note: the facade is an additive convenience. If it risks bloating the session, the
> fallback minimum is §3.1 + §3.2 (autoloader + capabilities removal) with the existing
> array-registration shape kept. Decide in the plan.

### 3.4 Bootstrap / resolver — what changes, what stays

- **Stays:** version arbitration (highest-wins), `register_loader_definition`, early
  `supported_features` WC feature declaration, the v1 `register_plugin()` tombstone, the
  version-incompatibility notices, state aggregation. Per ADR-001 the bootstrap remains the
  platform-aware loader for v2.0.
- **Sheds:** `load_early_capability_classes()` and the capability-based `require` logic.
- **Gains:** registers the framework autoloader against the winning copy's path.
- Net: the resolver gets *smaller*, not bigger — aligned with the "minimal resolver" intent
  (gotcha `resolver-bootstrap-coupling`).

---

## 4. Data-contract & back-compat analysis

Per CLAUDE.md clean-break policy:

- **Internal (free to break), and broken here:** loader-definition shape (`capabilities`
  removed), the resolver's capability methods, the entry-file registration boilerplate.
- **Installed-site data contracts (must NOT break) — none touched:** `capabilities` is not a
  persisted value; no option keys, hook names, slugs, cron hooks, meta keys, REST namespaces,
  or class names that back stored data change. Framework PHP class names are unchanged this
  session (namespaces phase is deferred). Verified against the §2.4 consumer list.

---

## 5. Testing

- **Autoloader unit tests:** resolves a PSR-4 framework class; resolves a legacy `Woodev_*`
  class; returns silently for a non-framework class; never double-loads an already-defined
  class; registered exactly once.
- **Completeness test (§3.1.1):** every framework class file is reachable via the autoloader
  (guards the WSOD-on-first-boot failure mode).
- **Resolver tests:** a payment-gateway / shipping definition **without** `capabilities` still
  boots; `load_early_capability_classes` removal does not regress base availability;
  invalid/`edd` platform still rejected.
- **Fixtures:** update all three fixtures to drop `capabilities`; the existing integration
  boot path must stay green (the fixtures are the de-facto e2e of the loader).
- **Regression bar:** `composer check` green (phpcs + phpstan L3 + unit, currently **725
  unit**); integration suite unchanged. EOL hygiene per gotchas (built-in `Edit` on PHP;
  no Serena `replace_content`).

---

## 6. Out of scope (explicitly)

- Versioned namespaces (§8) — separate large phase.
- `Woodev_EDD_Plugin` / S4 EDD platform.
- Migrating `class-plugin.php::includes()` off manual requires onto pure autoload.
- Any shipping/box-packer module work.
- Touching public `docs/` (operator decision s13).

---

## 7. Risks

| Risk | Mitigation |
|---|---|
| Autoloader misses a legacy class → production WSOD on first boot (we have hit this class of bug) | the §3.1.1 completeness test; keep `includes()` chains as the primary loader this session — autoloader is the net, not the sole path |
| Autoloader interferes with other plugins' autoloaders | own only `Woodev_*` / `Woodev\Framework\*`; return silently otherwise; register once |
| Removing `capabilities` breaks an unmigrated plugin | clean-break accepted; only our own fixtures/plugins consume it; the B-1 tombstone still protects mixed fleets |
| Multi-version: autoloader points at the wrong copy | register only after arbitration, against `$framework_plugin['path']` (the winner) — same source as today's `load_early_capability_classes` |

---

## 8. Future phase — versioned namespaces (NOT this spec)

The end-state godaddy model (`Woodev\…\vX_Y_Z\…` + thin one-file loader). This spec builds its
prerequisite (the autoloader). Recorded so the path is explicit:

- **Why not now:** (1) the autoloader had to exist first (done here); (2) sheer mechanical
  scale + risk of moving the whole `woodev/` tree under a versioned namespace; (3) the
  plugin-side referencing strategy must be designed AND plugins rewritten to adopt it.
- **Plugin-side referencing (open):** godaddy hard-codes `use …\v5_x_x\…` and find-replaces the
  token on every framework bump (a real tax). Cleaner option to evaluate: the per-plugin
  loader sets one `class_alias( 'Woodev\Framework\v2\Payment_Gateway_Plugin',
  'My_Plugin_Gateway_Base' )` and the domain class does `extends My_Plugin_Gateway_Base` —
  version lives in ONE place, no cross-plugin collision.
- **Granularity (open):** stamp **major only** (`Woodev\Framework\v2\`) — solves the real
  v1/v2 coexistence need with far less churn than godaddy's full `v6_2_2` stamping.
- **Not a blocker:** data contracts are strings, untouched by PHP namespacing.

---

## 9. Appendix — godaddy fork findings (informing this spec)

From the s27 recon of `godaddy-wordpress/wc-plugin-framework` (v6.2.2):

- **Versioned namespaces since v5.0.0 (2018)** — their structural answer to multi-version;
  validates §8 direction. Moving toward thin-loader + (dev-time) Composer autoload (v5.14.0).
- **Type via inheritance, config via args** — `SV_WC_Payment_Gateway_Plugin extends
  SV_WC_Plugin`; per-instance payload (`gateways`, `currencies`) stays as constructor args.
  Directly endorses §3.2 (and our fixtures already do this). No `capabilities` array upstream.
- **Borrow candidates (separate backlog, not this spec):** `Block_Integration_Trait` (High);
  an `Enum_Trait` + pseudo-enums for payment status / license status / plugin type, staying
  PHP-7.4-safe with byte-identical backing strings (Med-High); `CanConvertToArrayTrait` (Med).
- **"Abilities" = false friend** — it is their integration with the WordPress core Abilities
  API (AI-agent-callable actions), unrelated to plugin-type declaration. Do NOT model our
  type system on it. (May matter later as a separate `woodev_support` diagnostics feature.)

---

## 10. Open questions for operator review

1. Legacy class→file map: **(a) generated static map** vs (b) convention+overrides (§3.1.1).
   Spec recommends (a).
2. Include the **`loader.php` facade** (§3.3) this session, or ship core-only (autoloader +
   capabilities removal) and add the facade next? Spec recommends including it — it is the
   visible payoff and centralizes the B-1 boilerplate.
