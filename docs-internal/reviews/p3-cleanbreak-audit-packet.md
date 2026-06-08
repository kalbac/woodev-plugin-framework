# External Audit Packet — S0 / Phase 3 (Clean-Break Deletions)

> **For the operator:** paste this whole file to GPT-5.5 as an independent auditor and return its findings (or have it apply + report changes — I will verify skeptically via receiving-code-review). This is the second key-gate external review (execution-protocol §6). Phase 3 is the **irreversible** deletion of all internal-API back-compat scaffolding — the highest-risk gate for accidentally breaking something.

## Role for the auditor
Independent senior reviewer of a WordPress/WooCommerce PHP framework clean-break refactor. You did not write this. Be adversarial: hunt for breakage, dangling references, lost coverage, and — most importantly — any **installed-site data contract** that was broken while deleting "internal" code. Concise, specific, file:line.

## Context (the clean-break policy)
The Woodev Plugin Framework (branch `refactor/platform-v2-clean-break`) made a clean break: **internal code (class/method names, registration shape) is free to break; installed-site data contracts must be preserved byte-for-byte** (option keys, license/instance IDs, gateway/shipping method IDs, public hook names, cron hooks + payload, REST namespaces, AJAX actions, admin slugs, meta keys). Policy: `docs-internal/adr/005-platform-v2-clean-break-policy.md`.

Phase 3 deleted the internal-API scaffolding. Phase 2 (the pilot gate) already validated the new load path end-to-end through a fixture.

## Scope to review (the diff)
```
git diff f3d1b9b..4223597
```
Two cohesive commits:
- `7cc3666` refactor!: delete legacy positional registration path
- `4223597` refactor!: delete internal-API class aliases and deprecated shims

### What was deleted
- **Legacy registration path:** `Woodev_Plugin_Bootstrap::register_plugin()` (positional), `Framework_Resolver::register_legacy_plugin()`, `Framework_Plugin_Loader_Definition::from_legacy_registration()` + the `is_payment_gateway`/`load_shipping_method` flag reads. Capabilities now come ONLY from the explicit loader definition's `capabilities` array.
- **Global class aliases:** `woodev/class-woocommerce-plugin-alias.php`, `woodev/class-woocommerce-helper-alias.php` (+ their resolver `require_once` and composer.json classmap entries).
- **Deprecated shims:** `Woodev_Plugin::{add_class_form_wrap_start,add_class_form_wrap_end,get_woocommerce_uploads_path}`; `Woodev_Helper::{get_order_line_items,is_order_virtual,shop_has_virtual_products,render_select2_ajax}`; the `Woodev_License_Settings` shim class file. Real implementations remain on `Woodev\Framework\Woocommerce_Plugin` / `Woodev\Framework\Woocommerce_Helper` / `Woodev_Woocommerce_License_Settings`.
- **Tests:** 4 shim-dedicated test files deleted; redundant legacy-API test cases deleted; behavioral cases (version arbitration, accumulation, semver sorting, WP-requirement gating) **migrated** to `register_loader_definition()`; alias references swapped to FQCN.

### Verified state
`composer check` green: phpcs clean, phpstan L3 = 0 errors, phpunit 179 tests / 397 assertions (was 198/450 — the −19 is deleted shim/legacy scaffolding partly offset by migrations). Residue sweep: no `class_alias`/`register_legacy_plugin`/`from_legacy_registration`/`load_shipping_method` in production; the one remaining `is_payment_gateway` is a REST output field (`instanceof Woodev_Payment_Gateway_Plugin`) — an installed-site contract, intentionally kept.

Key files for review: `woodev/bootstrap.php`, `woodev/class-framework-resolver.php` (load loop + `load_early_capability_classes`), `woodev/class-framework-plugin-loader-definition.php`, `woodev/class-plugin.php`, `woodev/class-helper.php`.

## Questions for the auditor (answer directly)
1. **Data-contract breakage (highest priority):** Did deleting any "internal" symbol actually alter an installed-site contract? Specifically: does the removal of the `is_payment_gateway`/`load_shipping_method` flag mapping change *which framework base classes load* (early-capability preload) for a real plugin in any way that differs from before — could a real shipping/payment plugin now fail to get its base class preloaded at the right time?
2. **Resolver load-loop correctness:** With the legacy path gone, is `Framework_Resolver::load_plugins()` still correct for the explicit-definition-only world — version arbitration (highest wins), the `backwards_compatible` window handling, requirement gating, and `load_early_capability_classes` now driven only by the `capabilities` array? Any path that silently no-ops?
3. **Dangling references:** Beyond the known stale `.po`/`.pot` line-marker comments (generated artifacts), is there any production code path that still reaches a deleted symbol — including indirect ones (hook callbacks bound by string, `method_exists` checks, dynamic calls)?
4. **Lost coverage:** Does the −19 test delta hide any real behavioral coverage loss (arbitration / dedup / requirement gating / capability resolution), or is it purely shim/legacy scaffolding removal as claimed? Name any behavior now untested.
5. **WC plugin self-binding:** `Woodev\Framework\Woocommerce_Plugin` previously relied on the base shims for `add_class_form_wrap_*` / uploads-path; confirm it now binds/owns these itself and nothing depends on the deleted base versions.
6. **i18n artifacts:** The `.po`/`.pot` files still reference the deleted `class-plugin-license-settings.php` lines — purely cosmetic (regenerated by the i18n build), or is there a runtime/string-loading risk?

Return: findings (severity + file:line) and a direct yes/no on whether the P3 gate should be considered passed (i.e. safe to proceed to P4 base decomposition).
