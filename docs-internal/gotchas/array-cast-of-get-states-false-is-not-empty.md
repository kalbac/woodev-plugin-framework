# `(array) WC()->countries->get_states( $country )` is NEVER empty for a country without states

**Topic:** `[woocommerce/states]`
**Discovered:** s71 (13.08.2026), adversarial review of PR #304 (Task 13 server half)

## The trap

`WC_Countries::get_states( $cc )` returns **`false`**, not `[]`, when the country key is absent from the states table (`includes/class-wc-countries.php:243-247`). Casting that to an array does not give you an empty array:

```php
(array) false === [ 0 => false ]   // NON-empty, count() === 1
```

So the natural-looking emptiness test is inverted for exactly the countries you care about:

```php
❌ $has_states = [] !== (array) WC()->countries->get_states( $country );   // TRUE for a country with NO states
```

```php
✅ $has_states = [] !== array_filter( (array) WC()->countries->get_states( $country ) );
```

WooCommerce guards this in its own code — `src/StoreApi/Utilities/ValidationUtils.php:15` writes `array_filter( (array) wc()->countries->get_states( $country ) )` — which is the strongest evidence that the bare cast is a known foot-gun and not a theoretical one.

## Measured, not reasoned (rig, wp-cli)

```
WC()->countries->get_states("RU")   →  false
(array) false                       →  array ( 0 => false )
[] !== (array) false                →  true
count((array) get_states("US"))     →  54          ← control: the read itself works
```

Control countries matter here: without US/IT/CN/MD returning real lists, "everything says it has states" reads like a broken probe rather than a broken cast.

## What it cost

The #294 region arbitration decides, per country, whether the location layer or WooCommerce owns the region field, by asking whether the country has any registered states. With the bare cast, **every one of the nine supported countries** (RU BY KZ UZ AM AZ KG TJ TM — none of which WooCommerce ships states for) answered "has states". The result in the DEFAULT configuration:

- `levels[country].region` shipped to the client as `false` everywhere → the region typeahead never attached anywhere → the feature was dead on arrival;
- the "someone else owns the states" `_doing_it_wrong()` fired on **every checkout render**, naming all nine countries.

## Why three gates missed it

- **Unit tests:** every arbitration test overrode the `wc_states()` seam with a fake map, and the only test touching the real class exercised the `function_exists('WC') === false` branch. **No test ever executed the method body.** A seam introduced to make a WC call testable also makes it possible for the suite to never run it — when you add such a seam, add one test that runs the real body.
- **PHPStan level 3:** the WC stubs declare `@return false|array`, and `(array) $x` is a legal cast of that union, so there is nothing to complain about.
- **Reading the code:** `(array)` on a documented `array|false` return looks like exactly the right defensive move. It is the opposite.

## Related

- [[checkout-field-takeover-woocommerce-states]] — the rule that a field mapping onto a WooCommerce concept is driven through native WC filters
- [[wc-uppercases-the-posted-state-and-flips-the-map]] — the sibling trap in the same feature
