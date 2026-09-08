# Gotcha: [testing/environment-split] — `wc_get_orders()` silently returns EVERYTHING when `meta_query` meets the legacy CPT datastore, and the rig disagrees with the test environment about which datastore is in use

> Tags: testing, shipping, wc-compat, measurement | Session: s125

## What happens

`wc_get_orders()` accepts `meta_query` on **HPOS**. On the **legacy CPT** datastore it does not
support it at all:

```text
Order query argument (meta_query) is not supported on the current order datastore.
… WC_Order_Data_Store_CPT->query, wc_doing_it_wrong (This message was added in version 9.2.0.)
```

The clause is **dropped, and the query still runs**. There is no exception and no `WP_Error` — the
caller gets an ordinary, successful result set that is simply **unfiltered**. A row scope built this
way does not narrow to anything; it returns every order on the site.

In SP-10 that meant the «one carrier» tab returning another carrier's order, and it surfaced as an
assertion about a stray id rather than as anything mentioning `meta_query`:

```text
Failed asserting that an array does not contain 14
```

⚠ **The `_doing_it_wrong` notice is what makes it findable, and it only exists from WC 9.2.0.** On
an older WooCommerce the same code fails completely silently.

## Why a measurement on the rig does not settle it

**The two environments disagree**, measured 07.09.2026:

```bash
wp option get woocommerce_custom_orders_table_enabled
#   dev rig :8973        -> yes   (HPOS)
#   tests environment    -> no    (legacy CPT)
```

So a probe run through `npx wp-env run cli` proves the HPOS behaviour and says **nothing** about the
integration suite, and vice versa. In s125 a rig probe verified that `relation => OR` across two
marker keys returns exactly the right ids on HPOS — a true result that licensed a design which was
broken on the other half of the world. The integration suite is what caught it.

**Anything that queries orders has to be measured on BOTH.**

## Correct

Branch on the datastore, and on the legacy path pass a **custom query var** instead of `meta_query`,
translating it through WooCommerce's own filter. This is what all three shipped v1 plugins already
do (`plugins-reference/*/includes/admin/*list-table*.php`) — the answer was in the reference the
whole time:

```php
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ $this, 'handle_query_var' ], 10, 2 );

public function handle_query_var( array $query, array $query_vars ): array {
    if ( ! empty( $query_vars['is_yandex_delivery'] ) ) {
        $query['meta_query'][] = [ 'key' => '_yandex_delivery_state_status', 'compare' => 'EXISTS' ];
    }
    return $query;
}
```

Gate on `Woodev_Plugin_Compatibility::is_hpos_enabled()`
(`woodev/compatibility/class-plugin-compatibility.php:198`), not a raw `OrderUtil` call. On the
legacy path the args must carry **no `meta_query` key at all** — its mere presence is what trips the
notice.

## The worst case is the "match nothing" one

A "matches nothing" sentinel expressed as a `meta_query` clause is dropped on the CPT path exactly
like every other clause, so **"no carriers registered" returns every order on the site** instead of
none. Whatever expresses emptiness has to be asserted on both paths, separately.

### s128: the SAME divergence bit again, on the STATUS argument

"Narrow to nothing when the merchant asked for a status that does not exist" (#837 defect 3) has
three plausible mechanisms, and **two of them diverge between the datastores in opposite
directions**:

| mechanism | HPOS | legacy CPT |
|---|---|---|
| `'status' => []` | **every valid status** — `OrdersTableQuery::sanitize_status()` expands an empty list | — |
| `'status' => [ 'a-bogus-slug' ]` | 0 rows — the slug survives sanitising and becomes `status IN (…)` | **every row** — `WP_Query` walks only REGISTERED post statuses and drops the rest, so the condition vanishes |
| empty the PROVIDER scope → `NO_MATCH_META_QUERY` | 0 rows | 0 rows, via the query-var translation above |

The bogus slug passed the unit suite, passed on the rig, and returned a row in the integration
suite — which is the split this whole gotcha is about. **There is exactly one "matches nothing"
mechanism in this codebase and both datastore paths already share it; do not invent a second.**

The transferable half: "matches nothing" is never the absence of a condition. Every layer between
you and the database is entitled to read an empty or unrecognised filter as "no filter", and at
least one of them will.

## Related

- [wpenv-windows-gitbash-path-mangling](wpenv-windows-gitbash-path-mangling.md) — how to run the
  integration suite that catches this
- `specs/2026-09-07-sp10-orders-page-design.md` §M2 — the design this corrected, and the false
  sentence it replaced
