# WooCommerce uppercases the posted state and rewrites it through a flipped map — a human label used as a state KEY is mangled and the select then loses its own value

**Topic:** `[woocommerce/states]`
**Discovered:** s71 (13.08.2026), adversarial review of PR #304

## The mechanism

`WC_Checkout::validate_posted_data()` (`includes/class-wc-checkout.php:974-985`):

```php
$valid_state_values = array_map( 'wc_strtoupper', array_flip( array_map( 'wc_strtoupper', $valid_states ) ) );
$data[ $key ]       = wc_strtoupper( $data[ $key ] );
if ( isset( $valid_state_values[ $data[ $key ] ] ) ) { $data[ $key ] = $valid_state_values[ $data[ $key ] ]; }
```

Read it carefully: the map is `UPPERCASED LABEL => UPPERCASED KEY`, and the posted value is uppercased before the lookup. WooCommerce is being helpful — it accepts a customer typing a state NAME and converts it to the registered CODE.

For native WC states this is invisible, because their keys are already uppercase codes (`MOW`, `CA`) and are never equal to a label. It only bites when you register states of your own whose **key is a mixed-case human label**:

```php
❌ $states['RU'] = [ 'Московская область' => 'Московская область' ];
   // posted 'Московская область' → stored 'МОСКОВСКАЯ ОБЛАСТЬ'
```

```php
✅ $states['RU'] = [ wc_strtoupper( $label ) => $label ];
   // key is uppercase-STABLE: the round trip is a fixed point
```

Use `wc_strtoupper()`, not `strtoupper()` — the latter does not handle Cyrillic.

The Store API takes the same path via `ValidationUtils::format_state()`, so this is not a classic-checkout quirk.

## Why the damage is not just shouting

The stored value is still readable, so the defect hides. The real harm is on the NEXT render: `woocommerce_form_field()` decides the selected option with `selected( $value, $ckey )`, and the stored `МОСКОВСКАЯ ОБЛАСТЬ` no longer equals the registered key `Московская область` — so the select falls back to «Select an option…» and the customer's region silently disappears. Same family as the destructive-cascade class that [[a-programmatic-parent-change-must-not-run-a-destructive-cascade]] documents: the value is not rejected, it is quietly lost.

`get_formatted_address()` degrades the same way — `class-wc-countries.php:681` looks up `$this->states[$country][$state]` and falls back to the raw stored value, so a mismatched key prints the mangled form in orders and emails.

## The design question this settles

Whatever goes in the state VALUE persists into order data permanently, and orders must stay legible after the plugin is gone. That rules out an opaque provider key (`dadata:0c089b04-…` renders raw in old orders once the injector is absent — measured). Combined with the uppercasing above, the workable shape is:

- **key** = `wc_strtoupper( label )` — uppercase-stable, survives WC's normalisation unchanged, still legible without the plugin;
- **label** = the human label — what the customer sees, and what a client maps back to a record.

## Related

- [[array-cast-of-get-states-false-is-not-empty]] — the sibling trap in the same feature
- [[checkout-field-takeover-woocommerce-states]]
- [[an-empty-domain-key-is-not-a-key]]
