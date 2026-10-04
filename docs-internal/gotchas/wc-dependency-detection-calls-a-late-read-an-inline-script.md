# WooCommerce's dependency detection calls a LATE read of `window.wc.*` «an inline or unknown script»

**Namespace:** `[woocommerce/blocks]`
**Discovered:** 2026-10-04 (s151, #1089)

## The trap

Under `SCRIPT_DEBUG`, WooCommerce 11.1 prints an inline `wc-dependency-detection` script that wraps
`window.wc` in a proxy. Every read of an exported key (`wcSettings`, `wcBlocksData`,
`blocksCheckout`, `wcBlocksRegistry`, …) is traced to a script, and the script's registered
dependencies are checked. The console then says one of:

- `Script "handle" accessed wc.X without declaring "dep"` — a real missing dependency;
- `An inline or unknown script accessed wc.X without proper dependency declaration` — the caller
  could not be identified.

The second one is NOT evidence of a missing dependency. The caller is found in two ways:

1. `document.currentScript.src` — only while the script is being EVALUATED;
2. otherwise the first `.js` URL in `new Error().stack`.

And the proxy re-wraps itself every time a script assigns `window.wc` (`window.wc = window.wc || {}`
hands the setter the proxy itself). Measured on the rig's block checkout: **nine** nested `get`
traps. V8 keeps ten stack frames by default, so for any read made after evaluation — a React
render, a promise callback, a store subscriber — the stack holds nothing but the proxy, no URL is
found, and a script whose dependencies are declared correctly is reported as «inline or unknown».

Proof it is the stack depth and nothing else: with `Error.stackTraceLimit = 200` set before the
page loads, the same reads produce no warning at all.

WooCommerce trips its own detector the same way: on the rig's block checkout the
`wc.wcBlocksData` warning comes from WooCommerce's `order-attribution.js`, reading the key from a
store subscriber. Our bundle never reads that key, so that one is not ours to silence.

## Wrong

Reading the global each time it is needed, from wherever the code happens to run:

```ts
export function wcRuntime() {
	return window.wc; // a render-time `wcRuntime().wcSettings` is reported as «inline or unknown»
}
```

…and then «fixing» the warning by adding dependencies that are already declared.

## Correct

Take the globals once, while the bundle is being evaluated, and answer later reads from the capture
(`src/checkout-blocks/wc-runtime.ts`: `captureWcRuntime()`, called first thing in `index.ts`):

```ts
captured = { blocksCheckout: wc.blocksCheckout, wcBlocksRegistry: wc.wcBlocksRegistry, wcSettings: wc.wcSettings };
```

At that moment `document.currentScript` names the bundle, WooCommerce finds its handle and its
declared dependencies, and says nothing.

## How to tell whose warning it is

Wrap `console.warn` in an init script and take a DEEP stack inside the wrapper (raise
`Error.stackTraceLimit` only there, or the detector's own answer changes):

```js
const keep = Error.stackTraceLimit; Error.stackTraceLimit = 300;
const stack = new Error().stack; Error.stackTraceLimit = keep;
```

The first frame that is not the proxy is the real caller.

## Also seen in the same console

`The useSelect hook returns different values … Non-equal value keys: getValidationError` on the
block checkout is WooCommerce's own. The Checkout block's `blockWrapper` (`checkout-frontend.js`)
hands every registered inner block a `validation` prop from a hook in
`wc-cart-checkout-base-frontend.js` whose `useSelect()` returns
`getValidationError: ( id ) => store.getValidationError( … )` — a fresh closure per call. The
warning's stack is `useSelect ← that hook ← blockWrapper`; no selector of ours is in it, and ours
return a string or the store's own object.

## Related

- [is-checkout-is-false-on-a-page-that-only-carries-the-checkout-block](is-checkout-is-false-on-a-page-that-only-carries-the-checkout-block.md)
- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md)
