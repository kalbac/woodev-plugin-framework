# A probe that uses the production accessor creates the state it measures

> `[testing/*]` — discovered s74 (15.08.2026) while refuting issue #329 on the rig.

## The shape

Issue #329 claimed that `wc_load_cart()` inside a REST callback leaves an EMPTY cart, so
WooCommerce's `WC_Cart_Session::maybe_set_cart_cookies()` takes its deletion branch and drops
`woocommerce_items_in_cart` / `woocommerce_cart_hash`.

The obvious measurement is a temporary mu-plugin on `shutdown` at priority **-1** — right
before WooCommerce's own handler at priority 0 — logging whether the cart is empty. It
reported `empty=false`, i.e. "the card's premise does not hold".

**That reading was not evidence.** `WC_Cart::is_empty()` is `0 === count( $this->get_cart() )`,
and `WC_Cart::get_cart()` LAZILY LOADS the cart from the session when
`woocommerce_load_cart_from_session` has not fired. So the probe's own `is_empty()` call
populated the cart it was reporting on. Run without the probe, the state at that instant could
have been anything.

## Why it still ended up right — and how that was established

The answer came from the SOURCE, not the probe: `maybe_set_cart_cookies()` itself calls
`$this->cart->is_empty()`, so WooCommerce triggers exactly the same lazy load one line before
it decides. The deletion branch is therefore unreachable for a customer with a non-empty
session cart **by construction**, independently of `headers_sent()` and of output buffering
(measured on the rig as `headers_sent=true`, `ob_get_level()=0` — both config-dependent, and
therefore the weaker half of the answer).

## The rule

**Before quoting a probe, ask what the probe's own calls did to the thing being measured.** A
read-only-looking accessor is not read-only just because its name is a noun: `is_empty()`,
`get_*()`, `count()` and their friends routinely memoize, lazily hydrate, or fire hooks.

- ✅ Reach for the SOURCE when the measured value could have been produced by the act of
  measuring; a code path is proof, an observation of a self-perturbed system is not.
- ✅ When a probe must use a hydrating accessor, say so in the write-up, as the #329 closing
  comment does — an unqualified `empty=false` would have read as independent evidence.
- ❌ Do not "fix" it by grabbing the private state through reflection either: that measures a
  different system than the one production runs.

Related discipline, opposite direction: a CONTROL probe (`git stash` → run → unstash) answers
"did MY change do this", which self-perturbation cannot corrupt, because both sides are
perturbed identically.

## Related

- [[the-integration-suite-has-a-wc-session-a-rest-request-does-not]] — the sibling trap: the
  measurement CONTEXT, not the measuring call, is what differed there
- [[woodev-setting-get-value-is-cached-not-a-live-option-read]] — an accessor that reports the
  configuration a rig probe was trying to leave
- [[a-mutation-you-did-not-confirm-applied-proves-nothing]] — the other "your evidence is not
  what you think it is" rule
