# `@woocommerce/date` throws on a half-filled custom range, and the throw takes wc-admin down

**Discovered:** s132 (12.09.2026), designing the «Период» control for #855.

## The trap

`window.wc.date`'s resolvers validate against a fixed list and **throw** rather than degrade. The
throw happens inside React's render path, so it does not break one control — it unmounts the whole
WooCommerce admin app and the page goes blank.

Measured in the browser on the rig, WooCommerce 11.1.0:

| query | result |
|---|---|
| `{}` | `year` → 2026-01-01 … 2026-09-11, «Year to date» |
| `{ period: 'custom', after: '2000-01-01' }` | **throws** `Custom date range requires both after and before dates.` |
| `{ period: 'custom' }` | **throws** the same |
| `{ period: 'all' }` | **throws** `Cannot find period: all` |
| `{ period: 'custom', after, before }` | fine |

So `period=custom` is the ONLY period value that also requires `after` and `before`, and an
unknown period value is not «ignored», it is fatal. A hand-edited URL can reach both.

## Consequences that are not obvious

**«All time» cannot be a period value.** There is no such preset — the list is
`today, yesterday, week, last_week, month, last_month, quarter, last_quarter, year, last_year,
custom`, and `All time` appears in none of WooCommerce 11.1.0's three admin bundles. Nor can one be
injected: `DateRangeFilterPicker` reads `presetValues` straight from the module —
`options: filter( presetValues, v => 'custom' !== v.value )` — not from a prop.

**So «all time» is the ABSENCE of the parameter**, which is safe on both counts: nothing throws,
and `getPersistedQuery()` only picks keys that are already in the query (see its own gotcha), so
absence survives navigation without needing sentinel dates.

## ❌ Wrong

```ts
// hands wc.date whatever the URL happens to carry
const params = dateApi.getDateParamsFromQuery( query, DEFAULT );
const dates  = dateApi.getCurrentDates( query, DEFAULT );
```

A URL with `period=custom` and one date — or `period=all` from anyone's bookmark — kills the app.

## ✅ Correct

Resolve the state yourself first, and hand `wc.date` a query you BUILT, so the throwing branches
become unreachable rather than unlikely:

```ts
const period = knownPeriod( query );            // degrades anything unknown to «all time»
if ( ALL_TIME === period ) {
    return { after: '', before: '' };           // never ask wc.date at all
}
const own = { period, compare: DEFAULT_COMPARE, after: query.after, before: query.before };
const dates = dateApi.getCurrentDates( own, DEFAULT );
```

⚠ `compare` must be present in what you pass even if your page compares nothing: the same
resolver throws `Cannot find compare:` without it. That is a contract of their input, not a
feature of your UI.

## Related

- [wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry](wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry.md) — what `getPersistedQuery()` does and does not do
- [a-wide-cell-breaks-woocommerce-s-nth-child-grid-borders](a-wide-cell-breaks-woocommerce-s-nth-child-grid-borders.md) — the control written because of this
- [a-hand-written-d-ts-for-a-runtime-global-is-an-assertion-not-a-check](a-hand-written-d-ts-for-a-runtime-global-is-an-assertion-not-a-check.md) — the same lesson about `window.wc.*`: measure it, do not declare it
