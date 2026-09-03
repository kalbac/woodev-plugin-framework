# A hook registered from a per-plugin object fires once PER PLUGIN — a static callback collapses it to one

**Namespace:** `[framework/wiring]` · **Measured:** s114 (03.09.2026), building #713's gateway-coordination hook.

## The trap

Every carrier plugin builds its own `Checkout_Handler`, and every one of them runs `register()`.
So the obvious registration —

```php
add_filter( 'woocommerce_available_payment_gateways', [ $this, 'apply_gateway_coordination' ] );
```

— registers **N distinct callbacks on a two-carrier shop**, and the filter then fires **N times for
one customer decision**. Nothing errors. The symptom is a filter whose consumers see the same
decision two or three times, or a "once per request" side effect that happens once per installed
plugin.

WordPress keys a registered callback by `_wp_filter_build_unique_id()`. For an object callback that
id includes `spl_object_hash( $this )` — a fresh string per instance — so WordPress has no way to
know two registrations mean the same thing.

## ✅ The fix, when the hook is about the REQUEST and not about one plugin

Register a **static** callback:

```php
add_filter( 'woocommerce_available_payment_gateways', [ self::class, 'apply_gateway_coordination' ] );
```

For `[ ClassName, 'method' ]` there is no object, so the id is the same string
(`ClassName::method`) every time and WordPress collapses every plugin's registration into the ONE
stored callback. The filter then fires exactly once per `apply_filters()` call, whatever the plugin
count.

## The part that is easy to get backwards

**This is the OPPOSITE conclusion to the `$plugin_id`-keyed statics elsewhere in this layer**, and
both are right, because they answer different questions:

| The thing being stored | Correct shape | Why |
|---|---|---|
| "have I already reported/reconciled THIS plugin?" | `array<plugin_id, true>` | the question is per plugin; a bare bool checks only the first (#736, #746, #749) |
| "is this hook registered for the REQUEST?" | a static callback, no state at all | the question is per request; per-instance registration multiplies it |
| "which handlers exist this request?" | a static registry array | a registry is not a gate — collecting every instance is the point |

So the rule is not "statics are bad" and not "statics are fine". Ask what the stored thing is ABOUT:
a plugin, a request, or a collection. Gotcha
`a-process-static-once-per-request-gate-checks-only-the-first-plugin` covers the first row; this
file covers the second and third.

## How it was found

Not by a failure — by writing the brief. #713 required "fires once per request with the chosen
method resolved, NOT once per installed plugin", because the same layer had produced three
fleet-versus-plugin defects in one week. The worker satisfied it by reasoning about
`_wp_filter_build_unique_id()` rather than by adding another guard flag, which is the better answer:
the guarantee comes from WordPress's own dedup instead of from state we have to keep correct.

## Related

- [a-process-static-once-per-request-gate-checks-only-the-first-plugin](a-process-static-once-per-request-gate-checks-only-the-first-plugin.md) — the mirror case, where a bare bool is the bug
- [a-module-that-writes-into-another-modules-field-must-announce-it](a-module-that-writes-into-another-modules-field-must-announce-it.md) — the other `[framework/wiring]` trap about modules not knowing about each other
