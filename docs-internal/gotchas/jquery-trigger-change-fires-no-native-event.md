# A jQuery `.trigger( 'change' )` fires no native event — and that is how select2 reports a pick

**Namespace:** `[js/*]` · **Discovered:** s66 (2026-08-11), rig measurement on `:8973/classic-checkout/` (#271)

## The trap

`jQuery( el ).trigger( 'change' )` does **not** dispatch a DOM event. jQuery walks its own
handler registry and, for a handful of types, calls the element's native method (`el.click()`,
`el.focus()`); `change` is not one of them. So a listener registered with
`document.body.addEventListener( 'change', … )` **never runs**.

That is not an edge case on a WooCommerce checkout — it is the main path:

- **select2/selectWoo reports a user's selection with exactly that call.** §8's suggest takeover
  turns the locality field into a select2, so *the most common way a customer changes city* is
  invisible to a native listener.
- WooCommerce's own `update_checkout` churn on address fields is jQuery-triggered too.

The reverse also holds and is why "just use jQuery" is not the whole answer: this module's own
`writeAndFireChange()` dispatches a **real native** `Event`, which a jQuery binding sees (jQuery
does listen natively for real events) but which a page *without* jQuery could only observe
natively. The two worlds are not nested — they overlap.

**What makes this expensive is that a unit test cannot fall into it.** The #271 watcher was
covered by seven jest cases, all green, all mutation-checked — and every one of them drove the
field with `element.dispatchEvent( new Event( 'change', { bubbles: true } ) )`, because that is
the natural thing to write in jsdom. The native listener saw all seven. The rig, on the very
first try, showed the field unchanged after a jQuery-triggered city change: a fixture *richer*
than production in the one dimension that decided the outcome.

## The fix

Bind in **both** worlds, idempotently, and re-try the jQuery half on every mount pass so it is
picked up whenever jQuery appears (it is absent at import time under jest, present on every real
checkout):

```js
function bindLocalityWatchers() {
    if ( ! localityWatchersBound.native ) {
        localityWatchersBound.native = true;
        document.body.addEventListener( 'change', handleAddressFieldChanged );
    }

    if ( localityWatchersBound.jquery || ! window.jQuery ) {
        return;
    }

    var $body = window.jQuery( document.body );

    // A jQuery double thin enough to lack `.on()` is a legitimate shape (test harnesses):
    // a capability check, and it must NOT latch `jquery` as bound when it declines.
    if ( ! $body || 'function' !== typeof $body.on ) {
        return;
    }

    localityWatchersBound.jquery = true;
    $body.on( 'change', handleAddressFieldChanged );
}
```

Binding both means a **native** event reaches the handler twice when jQuery is loaded. That has
to be harmless *by construction*, not by luck — here the handler keys off a remembered
**transition**, so the second call finds the baseline already updated and returns having done
nothing. A handler that counted, appended, or toggled would need explicit de-duplication
instead.

Then pin it with a test that uses **real jQuery** (`jquery` is a devDependency of this repo) and
`window.jQuery( '#field' ).trigger( 'change' )`. Nothing less reproduces it: the mutation that
deletes the jQuery binding passes all seven native-event tests.

## Rule of thumb

When a page has jQuery, **"an event fired" is two different claims.** Before trusting a
delegated listener, ask which world the *producer* lives in — and remember the producer is often
a third-party widget (select2 here), not your own code.

Corollary for tests: a jsdom test drives the DOM the way *you* would, which is exactly the way
production does not. When the producer is a library, make at least one test go through the real
library's dispatch path.

## Related

- [[built-on-both-sides-with-no-caller-in-the-middle]] — the sibling failure mode: there the
  wiring was missing, here it was present but deaf. Both are invisible to unit tests and both
  showed up on the first rig pass.
- [[a-programmatic-parent-change-must-not-run-a-destructive-cascade]] — the other half of #271's
  session: WooCommerce's programmatic address-field churn arrives through this same jQuery path,
  which is why a destructive reaction must key off a transition rather than an event.
- [[an-invented-fixture-tests-your-assumptions-not-the-carrier]] — same lesson at the data layer:
  a fixture that differs from production in one dimension hides exactly the bug living there.
- `pickup-mount.js`'s own file docblock records the mirror-image asymmetry for
  `updated_checkout` (a jQuery *custom* event, unobservable natively at all).
