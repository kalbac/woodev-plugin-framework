# External Audit Packet — S0 / Phase 4 (Base Decomposition)

> **For the operator:** paste to GPT-5.5 as an independent auditor; return findings (or apply + report — I verify skeptically). Third key-gate external review (execution-protocol §6).

## Role for the auditor
Independent adversarial reviewer of a WordPress/WooCommerce PHP framework refactor. You did not write this. Default assumption: a moved method or a re-homed hook silently changed behavior or dropped an installed-site contract. Prove it with a line, or return clean. Concise, file:line.

## Context
`Woodev_Plugin` (the platform-neutral base) was decomposed so it is no longer a god-object and carries no WooCommerce seams. The base already delegated ~18 subsystems to handlers; Phase 4 extracted two more inline concerns into handlers that own their own WP hooks, deliberately LEFT two polymorphic ones on the base, and removed the last WC-named seam. Hierarchy: `Woodev_Plugin` (neutral) → `Woodev\Framework\Woocommerce_Plugin` → `Woodev_Payment_Gateway_Plugin` / `Woodev\Framework\Shipping\Shipping_Plugin`.

Clean-break policy: internal code free to break; **installed-site data contracts release-blocking** (option keys, hook names, cron event names + schedule, AJAX actions, REST namespaces, method/gateway IDs, meta keys, text domains).

## Scope to review (the diff)
```
git diff 52da7fa..7a4e34e
```
Key commits: `dc4f661` (extract `Translation_Handler`), `9acb359` (extract `Cron_Handler`), `dd47b99` (remove `add_woocommerce_hooks` WC seam). Plus the decision record `fa40ec8`.

### What changed
- **Extracted** `woodev/handlers/class-translation-handler.php` (`Woodev\Framework\Handlers\Translation_Handler`) — owns `load_translations()` + textdomain helpers, registers its own `init` hook. Removed from base.
- **Extracted** `woodev/handlers/class-cron-handler.php` (`Cron_Handler`) — owns `add_schedules()`/`schedule_events()`/`weekly_license_check()`/`ajax_verify_license()`, registers `cron_schedules` filter, `wp` action, `woodev_weekly_scheduled_events` action, `wp_ajax_woodev_verify_license`. Calls `$plugin->get_license_instance()`. Removed from base.
- **NOT extracted (deliberate decision):** `plugin_action_links()` and `add_api_request_logging()`/`log_api_request()`/`get_api_log_message()` — both are polymorphic, overridden by `Woodev_Payment_Gateway_Plugin` (action-links calls `parent::`; api-logging is no-op'd by gateways which log per-gateway, and `get_api_log_message()` is an external caller). Left on the base to avoid breaking the override chain / double-logging.
- **WC seam removed:** deleted the empty `add_woocommerce_hooks()` stub from `Woodev_Plugin` and its `add_hooks()` call. `Woocommerce_Plugin` now registers its WC hooks from a private `register_woocommerce_hooks()` invoked in its own `__construct()` (after `parent::__construct()`). Hooks re-homed: `before_woocommerce_init → handle_features_compatibility`; `woocommerce_before/after_settings_{shipping,checkout,integration} → add_class_form_wrap_start/end`; `woocommerce_system_status_environment_rows → add_system_status_php_information`. Payment/shipping subclasses inherit these via constructor chaining (they do not override `add_woocommerce_hooks`).
- Guard test `PlatformNeutralBaseHasNoWcMethodTest` asserts `Woodev_Plugin` declares no method whose name contains "woocommerce".

### Verified state
`composer check` green: phpcs clean, phpstan L3 = 0 errors, phpunit 190 tests / 508 assertions. Base `class-plugin.php` 1,296 lines / 77 methods (was ~1,435 / ~87).

## Questions (answer directly)
1. **Hook-name / timing drift (highest priority):** For every hook the two handlers register, is the name BYTE-IDENTICAL to before extraction, and is the registration still reached at a load phase that fires it correctly? Specifically the cron event `woodev_weekly_scheduled_events` + schedule key `weekly` + `wp_ajax_woodev_verify_license`, and the api-logging action `woodev_{id}_api_request_performed` (still on base). Any name changed or any hook now registered too late to fire?
2. **WC-seam re-homing completeness:** Does `Woocommerce_Plugin::register_woocommerce_hooks()` register EXACTLY the set the old `add_woocommerce_hooks()` override did — none dropped, none duplicated? Is calling it at the end of `Woocommerce_Plugin::__construct()` (vs the base's old call site) timing-safe for ALL of them (e.g. `before_woocommerce_init` must be added before WC fires it)? Do `Woodev_Payment_Gateway_Plugin` and `Shipping_Plugin` instances still end up with these WC hooks registered?
3. **The non-extraction decision:** Is leaving `plugin_action_links` + api-logging on the base the right call, or did it miss a cleaner option that doesn't risk the gateway double-logging / `parent::` chain?
4. **Residual WC awareness:** Beyond method NAMES, does any method still on `Woodev_Plugin` assume WooCommerce (call a `wc_*`/`WC_*` symbol unguarded, etc.)? Is the base genuinely loadable + correct with WooCommerce absent?
5. **Handler construction safety:** Handlers are constructed eagerly in `Woodev_Plugin::__construct()` and register hooks in their own constructors. Any ordering hazard (a handler needing something not yet built, or a hook registered before a dependency exists)?
6. **Contract regressions in moved bodies:** Did the verbatim-move of the translation/cron method bodies silently change any behavior (a `$this->` that should now be `$this->plugin->`, a lost early-return, a changed option/meta access)?

Return findings (severity + file:line) and a direct yes/no on whether the P4 gate passes (safe to proceed to P5 resolver minimization).
