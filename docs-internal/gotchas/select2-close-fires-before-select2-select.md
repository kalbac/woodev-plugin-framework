# `select2:close` fires BEFORE `select2:select` — a "the pick will cancel it" guard cannot work

**Namespace:** `[js/select-value-space]`
**Found:** s92 (25.08.2026).

## The trap

Any design that says *"the close handler will be cancelled by the pick that follows"* is inverted.
Measured on the live rig against WooCommerce's bundled selectWoo — four reproductions, mouse and
keyboard, ajax and non-ajax adapters, all identical:

```
select2:opening → jquery change → select2:closing → select2:close → select2:select
```

**`select2:close` arrives before `select2:select`.** And the jQuery `change` arrives FIRST of all,
so anything the change handler writes (in this repo: `clearDescendants()`'s
`entry.clearedByEdit[level]` snapshot) is already in place by the time `close` runs.

## Why it is expensive here

s92 round 2 of #517 built a "pick clears the pending abandon" guard on the assumed opposite order.
It passed its own tests, because those tests **hand-dispatched `select2:select` then
`select2:close`** — an order the browser does not produce. A green suite pinned a fiction.

Under the real order the close flushed a pending abandon before the pick could clear it, and
`restoreClearedDescendants()` wrote the previous settlement's street under the newly picked town.

## ✅ How to be right

- **Do not build a guard on select/close ordering.** Defer the flush by a macrotask so the later
  `select` can still cancel it, or use the field's own `focusout`, which is what
  `location-typeahead.js` does.
- **Make the test fake emit the measured order.** `tests/js/support/fake-select2.js` now does; a
  fake that dispatches a fictional order makes every test built on it wrong in the same direction.
- **Measure the order, never recall it.** This repo vendors no `selectWoo.full.js`, so it cannot be
  read from source here — only from a live page.

## How to measure it again

```js
const $el = jQuery( '#shipping_city' );
window.__o = [];
[ 'select2:opening', 'select2:closing', 'select2:close', 'select2:select' ]
    .forEach( n => $el.on( n, () => window.__o.push( n ) ) );
$el.on( 'change', () => window.__o.push( 'jquery change' ) );
// open, type, then click a result row; read window.__o
```

## Related

- [jquery-trigger-change-fires-no-native-event](jquery-trigger-change-fires-no-native-event.md)
- [a-select-value-write-with-no-matching-option-submits-nothing](a-select-value-write-with-no-matching-option-submits-nothing.md)
- [advancing-the-whole-interval-does-not-pin-a-delay](advancing-the-whole-interval-does-not-pin-a-delay.md) — same family: a test that cannot fail
