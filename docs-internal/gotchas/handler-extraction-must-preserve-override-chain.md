# A handler extraction that self-registers its hook can silently disable a subclass override

**Namespace:** `framework/handler-extraction`
**Discovered:** #244 (2026-08-10), extracting `Plugin_Action_Links_Handler` / `API_Logger`

## The trap

The general P4 handler pattern (`Cron_Handler`, `Translation_Handler`) has the handler
register its own WP hook in its own constructor, bound to itself:
`add_action( $hook, [ $this, 'method' ] )`. That is safe when nothing overrides the
moved method. It is **not** safe when a subclass polymorphically customizes the moved
behavior:

- `Woodev_Payment_Gateway_Plugin::plugin_action_links()` overrides
  `Woodev_Plugin::plugin_action_links()` and calls `parent::plugin_action_links( $actions )`
  to compose the base links with its own per-gateway links. If the filter callback is
  bound to a new `Plugin_Action_Links_Handler` instance instead of the plugin, the
  override never fires — WordPress calls the handler's method directly, bypassing `$this`
  entirely. The gateway loses its per-gateway configure links, silently.
- `Woodev_Payment_Gateway_Plugin::add_api_request_logging()` no-ops the base's
  registration hook on purpose, so gateways can log per-gateway via their own
  `Woodev_Payment_Gateway::add_api_request_logging()` instead (separate log files per
  gateway). If an `API_Logger` handler registered its action unconditionally in its own
  constructor — like `Cron_Handler` does — every live payment plugin would double-log:
  once through the handler, once through the gateway's own listener.

Both were flagged by the original `platform-v2-base-decomposition-subplan.md` (Tasks 2/3,
2026-06-04) and marked CANCELLED for exactly this reason — re-verified against the current
code with `find_referencing_symbols` before touching anything, and still true.

## The fix (not a cancellation)

The extraction is still possible, just not via handler self-registration bound to itself:

- `Plugin_Action_Links_Handler`'s constructor registers the filter, but bound to the
  **plugin** instance (`[ $plugin, 'plugin_action_links' ]`), not to the handler. This
  keeps polymorphism intact — WordPress resolves the callback through the actual runtime
  class, so a gateway override's `parent::` call still reaches
  `Woodev_Plugin::plugin_action_links()`, which now just delegates to
  `$this->plugin_action_links_handler->build_links( $actions )`.
- `API_Logger` does **not** self-register in its constructor at all. `Woodev_Plugin` keeps
  `add_api_request_logging()` as a real, overridable method whose base implementation
  calls `$this->api_logger->register()`; the gateway plugin's no-op override is untouched
  and still suppresses it.
- Two more public `Woodev_Plugin` methods stayed as one-line delegates rather than being
  deleted, because they are called directly (not via the hook) from a different class
  entirely: `log_api_request()` (called by `Woodev_Licensing_API::broadcast_request()`)
  and `get_api_log_message()` (called by `Woodev_Payment_Gateway::log_api_request()` via
  `$this->get_plugin()->get_api_log_message()`).

## Rule

Before binding an extracted handler's hook callback to itself, grep
(`find_referencing_symbols` in Serena) for: (1) subclass overrides of the method being
moved, especially ones calling `parent::`, and (2) any external caller of the method
outside the hook path. Either one means the base class must keep a real, callable method
— polymorphic dispatch and direct method calls both require an actual method to exist on
the call chain, not just a hook registration.

## Related
- [[dispatcher-files-unwired-in-includes]] — another "the general pattern isn't safe for
  every case" framework-internals trap
- `docs-internal/archive/platform-v2-base-decomposition-subplan.md` — Tasks 2/3, the
  original CANCELLED analysis this confirms
