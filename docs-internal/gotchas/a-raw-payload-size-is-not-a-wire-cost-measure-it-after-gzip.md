# Gotcha: [perf/payload] — A raw JSON size is not a wire cost: this one shrank 96× under gzip

> Tags: performance, payload, checkout, blocks, woocommerce, measurement | Session: s118

## What happens

You measure a payload the obvious way — `strlen( wp_json_encode( $data ) )` — see it grow by tens
of kilobytes, and file it as a performance concern. Card **#364** was exactly this: applying the
field-order preset to every shipping country grew WooCommerce's country locale from **101 to 251**
countries, and the JSON from **~10 KB to ~58 KB**. `CartCheckoutUtils::get_country_data()` embeds
that locale into every block cart and block checkout page, so the reading was "+48 KB of JSON now
rides to the client on every checkout".

That number is real and it is also irrelevant, because nothing serves that payload uncompressed.

## Root cause

The locale is a table with the **same field keys repeated once per country** — `address_1`,
`address_2`, `city`, `state`, `postcode`, `priority`, and so on, 250 times over. That is close to
the best case a DEFLATE window can be handed. Measured on the rig (`:8973`, WP 7.1 + WC 11.1.0,
250 shipping countries), taking both sides in one process and resetting `WC_Countries::$locale`
and `$address_formats` between them:

| | countries | locale raw | locale gzip | `get_country_data()` raw | `get_country_data()` gzip |
|---|---|---|---|---|---|
| filter removed | 101 | 9 582 | 1 243 | 100 728 | 24 304 |
| filter active | 251 | 57 702 | 1 989 | 143 591 | 24 807 |

**+47.0 KB raw becomes +503 bytes on the wire** — a ratio of about **96:1 on the delta**, against
roughly 4:1 for the page as a whole. The two figures do not merely differ in scale; they point at
different decisions. On the raw number you go optimise the filter. On the wire number you close
the card.

## The premise you must check, not assume

The gzip figure only means anything if the response is actually compressed. Check it rather than
believing it — one `curl` settles it:

```bash
$ curl -sI -H 'Accept-Encoding: gzip' http://localhost:8973/checkout/
HTTP/1.1 200 OK
Vary: Accept-Encoding
Content-Encoding: gzip
Content-Length: 49030
```

Here it was on by default on the bare Apache `wp-env` ships, with nothing configured — so it is a
default rather than a property of our rig. Do not carry that forward as an assumption for another
transport: a REST response, an admin AJAX reply or an asset served by a different host may not be
compressed at all.

## ❌ Wrong

```php
$bytes = strlen( wp_json_encode( $payload ) );
// "+48 KB per page load" -> file a performance card, start optimising the producer
```

## ✅ Correct

```php
$raw  = strlen( wp_json_encode( $payload ) );
$wire = strlen( gzencode( wp_json_encode( $payload ), 9 ) );
// Report BOTH, and confirm Content-Encoding on the real response before trusting $wire.
```

And measure both sides in one process with the producer's caches reset between them, or the second
reading is a cache hit rather than a measurement.

## Why this is worth a file

The trap is not "gzip exists" — everyone knows that. It is that a **raw byte count reads as a
finished measurement**. It has a unit, it is reproducible, it goes straight onto a card, and
nothing about it announces that it is off by two orders of magnitude for the question actually
being asked. #364 sat open for weeks on a correct number and a wrong conclusion; the raw figures
on the card reproduced within 5 % a WooCommerce minor later, so the card was never wrong about
what it measured — only about what that meant.

Repetitive, key-heavy structures are where the gap is widest: locale and i18n tables, settings
schemas, column definitions, anything shaped as "the same keys once per row".

## Related

- Card [#364](https://github.com/kalbac/woodev-plugin-framework/issues/364) — closed `not planned`
  on this measurement; the card's own step 1 named the closing condition.
- [`Checkout_Field_Policy::filter_country_locale()`](../../woodev/shipping-method/checkout/class-checkout-field-policy.php)
  — the producer, bound at `LATE` on `woocommerce_get_country_locale`.
- [a-probe-that-uses-the-production-accessor-creates-the-state-it-measures](a-probe-that-uses-the-production-accessor-creates-the-state-it-measures.md)
  — the neighbouring measurement trap: the probe itself changes the answer.
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md)
  — same shape again: a technically correct reading, taken the wrong way.
- [wiki/local-rig.md](../wiki/local-rig.md) — the rig this was measured on.
