# Gotcha: [woocommerce/hpos] — `Order_Query` with `status => ['any']` is read literally by HPOS on WC 8.5 and 9.3 — omit the argument
> Tags: hpos, order-query, status, wc-compat | Session: s227

## What happens

`Order_Query` passed `'status' => ['any']` to `wc_get_orders()`. On the HPOS datastore of WC 8.5 and 9.3 the value is matched as a
real status named «any», so the query returns no orders; on other WC versions it means «all statuses». F9a's integration jobs on
PR #1717 failed on exactly those two matrix rows.

## Root cause

`'any'` is a special value only some datastore code paths translate. Passing it is relying on version-specific behaviour.

## Fix

```php
// ❌ reads «any» as a status on WC 8.5 / 9.3 HPOS
$args['status'] = [ 'any' ];

// ✅ no status filter at all when the caller means «any»
if ( [ 'any' ] !== $statuses ) {
	$args['status'] = $statuses;
}
```

## Related

- [wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore](wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore.md)
