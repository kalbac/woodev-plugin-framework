# A filter option keyed `key` instead of `value` renders «Filter» as a DISABLED button

> Namespace: `woocommerce/*` — added session 128 (2026-09-09). Found by reading WooCommerce's own
> sources, not by debugging; the page looked entirely healthy and every gate was green.

## The trap

`AdvancedFilters` renders, the merchant adds a filter, picks a value, presses «Filter» — and
**nothing happens at all**:

```text
URL before : page=wc-admin&path=/woodev-shipping-orders&carrier=advanced
URL after  : page=wc-admin&path=/woodev-shipping-orders&carrier=advanced
new REST requests: 0
```

No error, no console warning. The button is simply not a button — it is the disabled branch.

## Root cause

WooCommerce's filter option type is `{ value, label }`:

```ts
// packages/js/components/src/advanced-filters/types.ts
export type FilterOption = { value: string; label: string; };
```

Build the options as `{ key, label }` — the shape `FilterPicker`'s own `filters` array uses, which
is what makes the mistake so easy — and every step below degrades silently:

| step | where | result |
|---|---|---|
| `getDefaultOptionValue()` reads `get( options, [ 0, 'value' ] )` | `navigation/src/filters.js` | `undefined` |
| `addFilter()` sets `newFilter.value = undefined` | `advanced-filters/index.tsx` | filter added, no value |
| `getQueryFromActiveFilters()` guards `if ( filter.value )` | `navigation/src/filters.js` | nothing written to the URL |
| `addQueryArgs()` skips `undefined` | `navigation/src/url.js` | `updateHref` equals the current URL |
| `updateDisabled = 'admin.php' + window.location.search === updateHref` | `advanced-filters/index.tsx` | `true` |

and at `updateDisabled` the component renders a disabled `<Button>` in place of the navigating
`<Link>`:

```jsx
{ updateDisabled && <Button isPrimary disabled>{ __( 'Filter' ) }</Button> }
{ ! updateDisabled && <Link type="wc-admin" href={ updateHref } … /> }
```

## The second symptom, same root

`SelectControl` renders `<option value={ option.value }>`. With `undefined`, React omits the
attribute entirely and the DOM falls back to the option's **text**. So an explicit pick submits the
human LABEL, not the key:

```text
delivery_status_is=Ожидает отправки   ->   Invalid parameter(s): delivery_status
```

That reads as a separate validation defect and is not one.

## ❌ Wrong

```ts
options: Object.entries( labels ).map( ( [ key, label ] ) => ( { key, label } ) ),
```

## ✅ Correct

```ts
options: Object.entries( labels ).map( ( [ value, label ] ) => ( { value, label } ) ),
```

## Why the tests did not catch it

**They asserted the broken shape.** `shipping-orders-page-filters.test.js` checked
`{ key: 'pending', … }` — the value the test itself supplied — so 1820 green jest tests and several
reviews all passed over it. A test about what the merchant sees must assert what is RENDERED.

The transferable half: when your object is consumed by SOMEONE ELSE'S component, the test that
matters asserts their contract, not your construction. Read the vendor's type; do not infer it from
a sibling component's array (`FilterPicker`'s `filters` really is `{ label, value }`, and
`AdvancedFilters`' `input.options` really is `{ value, label }` — close enough to swap by accident,
different enough to break).

## Related

- [a-hand-written-d-ts-for-a-runtime-global-is-an-assertion-not-a-check](a-hand-written-d-ts-for-a-runtime-global-is-an-assertion-not-a-check.md) — the same class: a claim about someone else's bundle that typecheck cannot verify
- [declaring-wc-settings-as-a-script-dependency-silently-drops-the-bundle](declaring-wc-settings-as-a-script-dependency-silently-drops-the-bundle.md)
