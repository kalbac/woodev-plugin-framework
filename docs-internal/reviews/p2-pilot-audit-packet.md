# External Audit Packet — S0 / Phase 2 (Pilot Gate)

> **For the operator:** paste this whole file to GPT-5.5 as an independent auditor and return its findings. This is the first key-gate external review (execution-protocol §6). I (Claude) will process the findings via skeptical verification, not blind implementation.

## Role for the auditor
You are an independent senior reviewer giving a second perspective on a WordPress/WooCommerce PHP framework refactor. You did not write this code. Be adversarial: look for what is wrong, missing, or risky. Concise, specific, file:line where possible.

## Context (what this gate is)
The Woodev Plugin Framework is being refactored on branch `refactor/platform-v2-clean-break` ("Platform v2"). The corrected direction (after an internal audit) is: **finish the platform split cleanly first**, with a **clean break on internal APIs** but **byte-for-byte preservation of installed-site data contracts** (option keys, method IDs, hook names, cron, REST namespaces, etc.).

Phase 2 is the **validation gate**: before deleting any back-compat scaffolding (Phase 3), prove once that a plugin of real production complexity loads end-to-end through the NEW load path. The pilot is modeled on the real `woocommerce-edostavka` shipping plugin, but implemented as an **in-repo test fixture** (operator decision — the live plugin is not rewritten here). Consequence on record: this gate proves the **code architecture / load path**, not live-site **data preservation** (that is enforced per-plugin at rewrite time via a written checklist).

## New load path being validated
`bootstrap.php` (global rendezvous for multi-copy vendoring) → `Framework_Resolver` (discovery/validation/invocation) → explicit `Framework_Plugin_Loader_Definition` (validated array; `platform` + `capabilities`) → `Woodev_Plugin` (platform-neutral base) → `Woodev\Framework\Woocommerce_Plugin` → `Woodev\Framework\Shipping\Shipping_Plugin`.

## Scope to review (the diff)
```
git diff cf453d5..7ebbd20
```
Commit `7ebbd20` "test(pilot): edostavka-shaped fixture validates the new load path + data-preservation checklist". Files:
- `tests/_fixtures/woodev-edostavka-pilot-plugin/woodev-edostavka-pilot-plugin.php` (entry: loader-definition fn + include-based init callback)
- `tests/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-plugin.php` (`extends Woodev\Framework\Shipping\Shipping_Plugin`)
- `tests/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-shipping-method.php` (`id = 'edostavka'`)
- `tests/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-integration.php` (option `woocommerce_edostavka_settings`)
- `tests/unit/EdostavkaPilotFixtureTest.php`
- `docs-internal/migration/edostavka-data-preservation-checklist.md`

Relevant existing files for comparison: `tests/_fixtures/woodev-realistic-shipping-plugin/`, `tests/unit/RealisticShippingFixtureTest.php`, `woodev/class-framework-resolver.php`, `woodev/class-framework-plugin-loader-definition.php`, `woodev/shipping-method/class-shipping-plugin.php`.

State: `composer check` green — phpcs 117/117, phpstan level 3 = 0 errors, phpunit 198 tests / 446 assertions.

## Invariants that must hold
- The test exercises the **real** load path (`register_loader_definition()` → `load_plugins()` → real `Shipping_Plugin` construction), not a mocked shortcut.
- The two asserted strings are exact installed-site contracts: shipping method id `edostavka`; settings option `woocommerce_edostavka_settings`.
- The fixture is generic/framework-owned (no code copied from the read-only reference plugin).

## Questions for the auditor (answer these directly)
1. **Gate validity:** Does this fixture genuinely prove the new load path is sound for an edostavka-complexity plugin, or is it too thin to count as a real validation? What specifically would a real edostavka exercise that this fixture does NOT — and does any of that gap pose a risk to **Phase 3 (deleting the back-compat scaffolding)**?
2. **Hidden coupling:** Is there anything in `Framework_Resolver` / `Framework_Plugin_Loader_Definition` / `Shipping_Plugin` construction that only works because of the legacy paths we are about to delete in Phase 3 (legacy positional `register_plugin()`, the `is_payment_gateway`/`load_shipping_method` flag mapping, the global `class_alias` files)? I.e. could the pilot pass today yet break once Phase 3 removes those?
3. **Data-preservation completeness:** Reviewing `docs-internal/migration/edostavka-data-preservation-checklist.md` against a typical WooCommerce shipping plugin — what installed-site contract categories are missing or under-specified that would silently break customer sites if the eventual rewrite overlooked them?
4. **Test integrity:** Could the test pass for the wrong reason (e.g., a stub/alias making an `instanceof` true without the real class graph loading)? Anything in the Brain Monkey setup that masks a real failure?
5. **Sequencing risk:** Given the plan goes P2 (this) → P3 (delete back-compat, cohesive) → P4 (decompose base) → P5 (minimize resolver), is "pilot-first then delete" the right order, or is there a concrete reason to reorder?

Return: findings list (severity + file:line where possible) and a direct yes/no on whether the P2 gate should be considered passed.
