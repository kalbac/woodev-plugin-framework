# Shipping-module conformance audit vs Capability-Gated Feature Seam

> **Phase 1 audit (read-only map).** Audits `woodev/shipping-method/` against the
> Capability-Gated Feature Seam pattern (wiki/capability-gated-feature-seam.md, ADR-006).
> Date: 2026-06-10 (session 4). Reference exemplar: `woodev/payment-gateway/`.
> Method: Serena symbolic read of `Shipping_Method` + `Shipping_Plugin` + subsystem seams.

## Verdict legend

- ✅ **conforming** — all 5 pattern properties hold.
- 🟡 **justified deviation** — not gated by `supports( FEATURE_* )`, and that is correct
  (standalone subsystem with its own lifecycle / placement #2 self-gating handler —
  see wiki "when NOT to use it" + "two gate placements"). Forcing the pattern here is a regress.
- 🔴 **real gap** — should follow the pattern but doesn't.
- 🔵 **convention deviation** — follows the pattern's 5 properties, but breaks a supporting
  convention (predicate wrapper / capability-dictionary hygiene). Cosmetic-but-worth-fixing.

## Headline

**Shipping is overwhelmingly conforming.** The box-packing seam (s3) is a textbook instance,
the shipping-class gating is conforming, and every standalone subsystem (REST, AJAX, checkout,
webhook, admin, integration) is wired via the blessed null-by-default / self-gating-handler
shape — a **justified deviation**, not a gap. There are **no 🔴 hard gaps**. The only
actionable items are two **🔵 convention deviations**:

1. **No `supports_*()` predicate wrappers** even though two features are each checked at 2
   sites — payment-gateway wraps these (`supports_refunds()`, `supports_tokenization()`).
2. **`Shipping_Plugin::supports()` is a dead capability surface** — declared, populated from
   `$args['supports']`, but zero in-framework consumers and zero `FEATURE_*` constants on the
   plugin (unlike `Woodev_Payment_Gateway_Plugin::FEATURE_CAPTURE_CHARGE`/`FEATURE_MY_PAYMENT_METHODS`).

This matches the next-session prediction: "mostly conforming, few real gaps."

## Conformance map

### Method level — `Shipping_Method` (`woodev/shipping-method/class-shipping-method.php`)

| # | Behaviour | Site | Verdict | Why |
|---|-----------|------|---------|-----|
| M1 | Box-packing rate seam: `calculate_shipping()` → `calculate_rate()` → `rate_package()` | `:200` / `:303` / `:329` | ✅ conforming | All 5: guaranteed (`final calculate_shipping` calls `final calculate_rate`), opt-in `supports(FEATURE_BOX_PACKING)`, thin orchestrator delegating to `pack_package()`+abstract `rate_package()` seam, inert-by-default (null `$packed`), `final`. This is the s3 reference instance. |
| M2 | `init_form_fields()` box-packing field (`packing_algorithm`) | `:135-147` | ✅ conforming | Guaranteed method (ctor calls it), gated `supports(FEATURE_BOX_PACKING)`, inert-by-default, delegates dictionary to `Woodev_Packer_Dispatcher`. |
| M3 | `init_form_fields()` shipping-class field (`shipping_class_id`) | `:123-133` | ✅ conforming | Gated `supports(FEATURE_SHIPPING_CLASSES)`, inert-by-default. |
| M4 | `is_available_for_package()` shipping-class availability gate | `:376` | ✅ conforming | Guaranteed in the `calculate_shipping` flow, gated `supports(FEATURE_SHIPPING_CLASSES)`, delegates to `has_only_selected_shipping_class()` seam, inert-by-default. |
| M5 | `FEATURE_SHIPPING_ZONES` / `FEATURE_INSTANCE_SETTINGS` declared in ctor | `:60-63` | ✅ conforming | These are **WC-native** capability strings consumed by `WC_Shipping_Method`/WC core (the guaranteed WC flow). Declared, consumed externally, no woodev-side gate needed. Clean dictionary split: WC owns these two, the framework owns `FEATURE_SHIPPING_CLASSES`/`FEATURE_BOX_PACKING`. |
| M6 | `get_pickup_point_source()` null-object accessor seam | `:528` | ✅ conforming | Inert-by-default seam (returns null in base; pickup methods override). Gated by delivery **type** (`is_pickup_shipping()`), a parallel by-design mechanism, not a capability flag. |
| **M7** | **No predicate wrappers for the two framework features** | `:124`,`:136`,`:322`,`:376` | 🔵 **convention** | `FEATURE_SHIPPING_CLASSES` checked at 2 sites (`:124` init_form_fields, `:376` is_available_for_package); `FEATURE_BOX_PACKING` checked at 2 sites (`:136` init_form_fields, `:322` calculate_rate). Convention (wiki "Conventions", ADR-006): **wrap when checked in >1 place**. payment-gateway does (`supports_refunds()` `:1530`, `supports_voids()` `:1767`, `supports_tokenization()` `:2884`). **Action:** add `supports_box_packing()` + `supports_shipping_classes()`; route the 4 sites through them. Internal-only, no contract touched. **LOW risk.** |

### Plugin level — `Shipping_Plugin` (`woodev/shipping-method/class-shipping-plugin.php`)

| # | Behaviour | Site | Verdict | Why |
|---|-----------|------|---------|-----|
| P1 | Subsystem wiring: AJAX / checkout / webhook handlers | `add_hooks()` `:187-239`; getters `:820`/`:849`/`:864` | 🟡 justified | Each `get_*_handler()` returns null in base (host overrides). `add_hooks()` null-guards then calls `register()`. Placement #2 (self-gating handler whose own `register()`/ctor is the guaranteed entry) + null-by-default inert. Wiki blesses this exactly for "standalone subsystem with an independent lifecycle (REST routes, AJAX, webhook)." **Do NOT force `supports()`-gating.** |
| P2 | Admin suite wiring (`get_shipping_admin()`, self-wires in ctor when obtained) | `:228-233`; getter `:835` | 🟡 justified | Null-by-default; `Shipping_Admin` self-registers admin_init/admin_menu in its ctor. Standalone admin lifecycle. |
| P3 | `init_rest_api_handler()` — pickup/warehouse REST bootstrap | `:80-97` | 🟡 justified | REST routes = standalone subsystem with own lifecycle; mirrors `Woodev_Payment_Gateway_Plugin::init_rest_api_handler()`. Controllers host-supplied (none by default) = inert. Mints no installed-site REST namespace literal. |
| P4 | `register_integration()` — WC_Integration settings page | `add_hooks()` `:194-196`; getter `:331` | 🟡 justified | Gated on `get_integration_handler() instanceof Shipping_Integration` (null by default). Null-by-default seam registered only when supplied. |
| P5 | `register_shipping_methods()` | `:241-305` | ✅ conforming | `final`, guaranteed (WC `woocommerce_shipping_methods` filter), iterates host-supplied classes with validation + forward hooks. Inert when no classes supplied. |
| **P6** | **`Shipping_Plugin::supports()` dead capability surface** | `:589-599`; populated `:73` | 🔵 **convention / latent** | `supports()` + `$this->supports` exist and are populated from `$args['supports']`, but have **zero in-framework consumers** (verified via `find_referencing_symbols` → `{}`) and **no `FEATURE_*` constants** declared on the plugin. Either an undocumented host-facing extension surface or vestigial. **Action options:** (a) document it as a deliberate host-facing surface with a docblock note; (b) leave as-is; (c) — only if operator wants a future plugin-scoped feature — introduce named constants. **Recommend (a) or (b); do NOT invent constants speculatively.** |

## Anti-patterns explicitly NOT found

- No `$is_x` boolean constructor flag with base-branching (the vocabulary is `supports()`/`FEATURE_*` + delivery-type predicates).
- No god-method: `calculate_shipping()`/`calculate_rate()` are thin orchestrators; each feature body lives in its own protected method (`pack_package()`, `has_only_selected_shipping_class()`).
- No ad-hoc option reads standing in for a capability check.
- No standalone subsystem forced into a hot-path branch.

## Recommended remediation scope (for Phase 2 operator decision)

| Item | Verdict | Risk | Recommendation |
|------|---------|------|----------------|
| **A.** Add `supports_box_packing()` + `supports_shipping_classes()` predicates; route the 4 raw `supports()` sites through them | 🔵 M7 | LOW (internal rename, `@since 2.0.0`, no contract) | **Do** — primary fix, aligns with payment-gateway. |
| **B.** Capability-dictionary alignment | — | — | **No-op** — the dictionary is already clean (WC-native vs framework features cleanly separated, M5). Nothing to align. |
| **C.** `Shipping_Plugin::supports()` dead surface | 🔵 P6 | LOW | **Decide with operator:** document as host-facing (recommended) vs leave vs (not recommended) add speculative constants. |
| **D.** Standalone subsystems (REST/AJAX/checkout/webhook/admin) | 🟡 P1–P4 | — | **Do NOT touch** — justified deviations; refactoring them into `supports()` branches is the over-refactor the prompt warns against. |

## Installed-site contracts in scope (must NOT break — byte-for-byte)

- Method ids (host-supplied; e.g. `edostavka`, `yandex_delivery_express`).
- `add_support()` hook: `woodev_shipping_method_{id}_supports_{name}` (`:632`). Predicate
  wrappers (item A) do **not** alter `add_support()` or this hook — purely add read accessors.
- Form-field hook `woodev_shipping_method_{id}_form_fields`, rate hooks
  `woodev_shipping_method_*`, option key `packing_algorithm`.
- Item A changes only internal call sites of `$this->supports( self::FEATURE_X )` → keeps
  identical runtime semantics (`WC_Shipping_Method::supports()` still the source of truth).

## Related

- [wiki/capability-gated-feature-seam.md](../wiki/capability-gated-feature-seam.md) — the pattern
- [adr/006-capability-gated-feature-seam.md](../adr/006-capability-gated-feature-seam.md) — the decision
- [[shipping-rate-no-parcel-sum]] — base-owns-orchestration invariant (s3)
- Reference exemplar predicates: `woodev/payment-gateway/class-payment-gateway.php:1530,1767,2884`
- Open tail (out of pattern scope): `Abstract_Warehouse_Store::save()` doesn't check the wpdb return value.
