# A grep of a vendor stylesheet is an incomplete measurement — read the computed style instead

**Discovered:** s133 (12.09.2026), #829.

## What happened

#829 asked for our delivery-status badge to use WooCommerce's own order-status palette and geometry,
explicitly: *"Замерить, чем WC красит свои статусы заказа... ⛔ Не подбирать цвета на глаз."*

The measurement was duly taken (s127, recorded as a comment on the card) by grepping WooCommerce's
`assets/css/admin.css` for `.order-status`. It produced a correct-looking table of five
background/text pairs plus geometry:

```
display: inline-flex; line-height: 2.5em; border-radius: 4px;
border-bottom: 1px solid rgba(0,0,0,.05)
```

The badge was built from exactly that, jest was green (1928 tests), typecheck, build and every
catalogue gate passed — and the page was visibly broken. Our two-word label «Готово к выдаче»
**wrapped**, and because every wrapped line is `2.5em` tall, the pill became a two-line block
sitting next to single-line pills.

## Root cause

**The grep did not return the whole rule.** Reading `getComputedStyle()` off a REAL `.order-status`
element on WooCommerce's own orders screen (WC 11.1.0, rig, 12.09.2026) returned three things the
grep had not:

| property | computed | why the grep missed it |
|---|---|---|
| `white-space` | `nowrap` | declared in a different rule/selector than the one grepped |
| horizontal padding | `0` on the element; its inner `<span>` carries `margin: 0 13px` | the breathing room is on a CHILD element, invisible to a grep for the parent selector |
| `margin` | `-3.25px 0` (`-0.25em`) | same — a separate declaration |

A CSS rule as the browser applies it is the union of every matching selector, inherited values and
the cascade. A grep answers about ONE selector's literal text in ONE file. Those are different
questions, and the difference is invisible: the grep's answer is not wrong, it is incomplete, and
incompleteness reads exactly like completeness.

## ❌ Wrong

```bash
# "Measuring" a vendor component by grepping its stylesheet
grep -o '\.order-status[^{]*{[^}]*}' assets/css/admin.css
```

Then copying the declarations found into our own rule.

## ✅ Correct

```js
// Read what the browser actually computes, off the vendor's own rendered element,
// on the vendor's own screen.
const el = document.querySelector( '.order-status' );
const cs = getComputedStyle( el );
// ...and look at the inner elements too — breathing room often lives on a child.
const inner = el.querySelector( 'span' );
```

Then reproduce the computed values, and write down in the code WHICH of them came from the
measurement — see `src/shipping-orders-page/style.scss`'s `.woodev-orders-status` docblock, which
now records both the incomplete first reading and the corrected one.

## Why the test suite could not catch it

jsdom computes no layout: nothing in a jest run can observe that a label wrapped. The suite asserted
the rendered text and the class name, both of which were correct. **Only opening the page caught
it** — which is the standing rule about not declaring UI work done on a green suite alone.

## Related

- [local-npm-run-build-is-not-assets-parity-evidence](local-npm-run-build-is-not-assets-parity-evidence.md) — the other half of the same PR's trouble
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — same shape one layer down: a stand-in answers about itself, not about the real thing
- `docs-internal/sessions/s133.md` — the session that hit it
