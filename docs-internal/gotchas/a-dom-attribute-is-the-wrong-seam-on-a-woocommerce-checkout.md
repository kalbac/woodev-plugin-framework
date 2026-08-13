# A DOM attribute is the wrong seam on a WooCommerce checkout — the node is not yours

**Namespace:** `[shipping/location]` · **Discovered:** s72 (2026-08-14), adversarial review of PR #317 (#309)

## What was built, and why it looked right

#309 needed the location layer to publish one bit: *is the current locality the customer's own
choice, or a default the shop guessed?* The framework deliberately ships no prompt wording — that is
domain work for the plugin — so the seam had to be data, not text. The chosen shape was a DOM
attribute on the chain field: `data-woodev-location-implicit="true"`, cleared when a real `/select`
persists.

Reasonable-sounding, fully unit-tested, six new green jest tests, mutation-checked. And wrong in
four independent ways, because **on a WooCommerce checkout the field node does not belong to you.**

## The four ways it dies

| # | Mechanism | Result |
|---|---|---|
| 1 | `boot()` runs `prefill()` **before** `attachAll()`; for `ajax-select2` (any level) and `related-list` (settlement), `buildSelectField()` creates a fresh `<select>`, copies only `id`/`name`/`className`, and removes the original input | mark destroyed at boot; feature 100% dead under a documented store setting |
| 2 | `reconcileAfterCheckoutUpdate()` re-attaches widgets and restores values after `updated_checkout`, but nothing re-applies the mark, and `prefill()` is called only from `boot()` | mark lost the first time the buyer picks a shipping method or applies a coupon |
| 3 | Billing and shipping entries share **one** `location` block; the clear ran per-entry | both fields marked; an explicit pick in the active section leaves the other stuck at `true` forever |
| 4 | `clearCountryScope()` empties every value on a country change but never clears the mark | field advertises "holds an implicitly defaulted locality" while holding nothing |

Plus a fifth, structural: spec §4.4 permits a chain level to have **no field at all**, so a
settlement-level default on an address-only checkout produces no signal whatsoever — while the server
knows the flag unconditionally.

## Why the tests said nothing

All six covered *pristine boot + one immediate pick* — the single path that works. Mutation coverage
was honest and still proved nothing about correctness: mutation testing shows a test exercises the
code, not that the code handles the states that matter.

## The rule

**Publish cross-module state through a channel you own.** In this layer that means, in order of
preference:

1. the existing native `CustomEvent` (`woodev_location_applied`, already consumed by
   `pickup-mount.js`) — add a key to its `detail`;
2. the server-rendered config block (`Checkout_Config`, `Pickup_Handler::location_config_block()`) —
   always present, level-independent, section-independent;
3. a PHP action/filter.

Every other signal in this layer already uses one of those three. Nothing else mirrors state onto a
DOM node for another module to poll — and a DOM attribute is not even observable without a
`MutationObserver`.

## The sharper lesson

A seam that is **correct only on the happy path is worse than no seam**, because a consumer reads its
absence as "the customer chose this". Leaving an extension point with no consumer yet is explicitly
fine here (`Customer_Location_Store::ACTION_SAVED` is the precedent) — but that is a *hook*: always
fired, unconditional, order-independent. Judge a proposed seam by whether it can be wrong, not by
whether anyone reads it yet.

## Related

- [[built-on-both-sides-with-no-caller-in-the-middle]] — the adjacent family; this one relocates that
  bug rather than repeating it
- `woodev/shipping-method/assets/js/frontend/location-cascade.js`,
  `location-select-modes.js` → `buildSelectField()`
- Spec `docs-internal/specs/2026-08-12-location-provider-design.md` §4.4, §4.6, D7, D11
