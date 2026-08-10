# A handler extraction that self-registers its hook can silently disable a subclass override

**Namespace:** `framework/handler-extraction`
**Discovered:** #244 (2026-06-04, subplan Tasks 2/3 cancellation), re-verified and closed as **not planned** (2026-08-10)

## The trap

The general P4 handler pattern (`Cron_Handler`, `Translation_Handler`) has the handler
register its own WP hook in its own constructor, bound to itself:
`add_action( $hook, [ $this, 'method' ] )`. That is safe when nothing overrides the
moved method. It is **not** safe when a subclass polymorphically customizes the moved
behavior:

- `Woodev_Payment_Gateway_Plugin::plugin_action_links()`
  (`woodev/payment-gateway/class-payment-gateway-plugin.php:318`) overrides
  `Woodev_Plugin::plugin_action_links()` (`woodev/class-plugin.php:860`) and calls
  `parent::plugin_action_links( $actions )` to compose the base links with its own
  per-gateway links. If the filter callback registered at
  `woodev/class-plugin.php:415` were bound to a new `Plugin_Action_Links_Handler`
  instance instead of the plugin, the override would never fire — WordPress calls the
  handler's method directly, bypassing `$this` entirely. The gateway would lose its
  per-gateway configure links, silently.
- `Woodev_Payment_Gateway_Plugin::add_api_request_logging()`
  (`class-payment-gateway-plugin.php:937`) no-ops the base's registration
  (`woodev/class-plugin.php:894`, called from `add_hooks()` at `:424`) on purpose, so
  gateways can log per-gateway via their own `Woodev_Payment_Gateway` listener instead
  (separate log files per gateway). If an `API_Logger` handler registered its action
  unconditionally in its own constructor — like `Cron_Handler` does — every live
  payment plugin would double-log: once through the handler, once through the
  gateway's own listener.
- Two more `Woodev_Plugin` methods are called directly, outside the hook path entirely:
  `log_api_request()` (`class-plugin.php:916`) by
  `Woodev_Licensing_API::broadcast_request()` (`woodev/licensing/api/class-licensing-api.php:43`),
  and `get_api_log_message()` (`class-plugin.php:932`) by
  `Woodev_Payment_Gateway::log_api_request()` via `$this->get_plugin()->get_api_log_message()`
  (`woodev/payment-gateway/class-payment-gateway.php:3533,3537`). Deleting either method
  in favor of a handler-only implementation breaks these call sites, which never go
  through the action at all.

This was first flagged by `platform-v2-base-decomposition-subplan.md` (Tasks 2/3,
2026-06-04) and marked CANCELLED for exactly this reason.

## The outcome (#244, 2026-08-10) — attempted, worked, still rejected

A later worker re-verified the CANCELLED reasoning with `find_referencing_symbols` (it
still held), then built the extraction anyway, on the theory that the trap above has a
fix rather than only a cancellation: bind the handler's hook registration to the
**plugin** instance, not to the handler, so polymorphism keeps working —
`Plugin_Action_Links_Handler`'s constructor called
`add_filter( $tag, [ $plugin, 'plugin_action_links' ] )`; `API_Logger` did not
self-register at all, leaving `Woodev_Plugin::add_api_request_logging()` as a real,
overridable method that calls `$this->api_logger->register()`. This worked — the
override chain and the no-op both kept functioning, `parent::` still reached the base,
byte-for-byte hook names were preserved, and it shipped as PR #257 with full test
coverage (10 new tests).

It was rejected anyway, for two independent reasons:

1. **It still needed exactly the scaffolding D-3 forbids.** To keep the override chain
   alive, `Woodev_Plugin` had to keep four thin, still-overridable, still-delegating
   methods (`plugin_action_links()`, `add_api_request_logging()`, `log_api_request()`,
   `get_api_log_message()`) alongside the two new handler classes. That is the
   "overridable-handler scaffolding" the original Task 2 cancellation note named as
   **gold-plating, contra D-3** — the fix for the trap *is* the thing D-3 says not to
   build. There is no version of this extraction that both preserves the override chain
   and avoids the scaffolding.
2. **The base grew instead of shrinking.** The card's own success criterion was a
   smaller `Woodev_Plugin`. Measured: `woodev/class-plugin.php` went from **1514 to
   1520 lines** after the extraction (`git show 66188cf~1:woodev/class-plugin.php | wc -l`
   vs. `git show 66188cf:woodev/class-plugin.php | wc -l`) — the four delegate methods
   plus two new handler-construction call sites outweighed what moved out. Two new files
   were added (`woodev/handlers/class-plugin-action-links-handler.php`,
   `woodev/handlers/class-api-logger.php`) for a net *increase* in base line count.

So both cancellation reasons from 2026-06-04 were re-verified against current code and
**still hold** — this time with a concrete measurement behind them, not just analysis.
PR #257 was closed without merging (#244 closed as not planned); its test coverage and
this gotcha were carried forward onto the unmodified base in a follow-up PR, since that
part of the work has value independent of the extraction question.

## Rule

Before binding an extracted handler's hook callback to itself, grep
(`find_referencing_symbols` in Serena) for: (1) subclass overrides of the method being
moved, especially ones calling `parent::`, and (2) any external caller of the method
outside the hook path. Either one means the base class must keep a real, callable method
— polymorphic dispatch and direct method calls both require an actual method to exist on
the call chain, not just a hook registration. If preserving that chain requires
reintroducing thin delegate methods on the base, **that is not a fix — it is the
scaffolding D-3 already ruled out.** Measure the base's line count before and after; if
the extraction doesn't shrink it, it hasn't paid for itself.

## Related
- [[dispatcher-files-unwired-in-includes]] — another "the general pattern isn't safe for
  every case" framework-internals trap
- `docs-internal/archive/platform-v2-base-decomposition-subplan.md` — Tasks 2/3, the
  original CANCELLED analysis this confirms
- `docs-internal/platform-v2-program-tracker.md` — corrected #244 status (CANCELLED with
  reasons, not "never executed")
