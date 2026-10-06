# Gotcha: [testing/js] — `element.click()` in jsdom skips the mousedown focus a real pointer gives a `tabindex="0"` element

**Namespace:** `testing/js` · **Discovered:** s156 (2026-10-06), #1127 round 2 REJECT
> **Measured on:** Chromium (desktop + touch emulation) vs jsdom under wp-scripts jest.

## What happens

#1127 made pickup search rows keyboard-operable and moved focus to the search input / card only on
KEYBOARD activation, gating on "did the row hold focus at pick time?". The jest tests (a plain
`row.click()` → focus unchanged) were green. In a real browser a mouse click or touch tap focuses the
`tabindex="0"` row on **mousedown**, before the click handler runs — so the gate was true for pointer
picks too and a tap still focused the input, opening the mobile keyboard over the map.

## Root cause

jsdom's `HTMLElement.click()` dispatches only a `click` event: no `mousedown`, no default focus action.
A browser's pointer sequence is `pointerdown → mousedown (focuses a focusable target) → … → click`.
`document.activeElement` at click time therefore cannot distinguish keyboard from pointer.

## Fix

❌ `const fromKeyboard = document.activeElement === row;` — true for every real click on a focusable row.

✅ Carry an explicit flag from the `keydown` handler into the shared activation path (or check
`event.detail === 0` for a synthetic keyboard click). In tests mimic the browser: `row.focus()` first,
then `row.dispatchEvent( new MouseEvent( 'click', { detail: 1, bubbles: true } ) )`, and assert the
input is NOT focused.

## Related

- [wc-store-api-batches-update-customer-300ms-after-updating-flips](wc-store-api-batches-update-customer-300ms-after-updating-flips.md) — same session: green jest, wrong live
- [../gotcha-index/testing-js.md](../gotcha-index/testing-js.md) — topic index
