# [woocommerce/filter-options] `AdvancedFilters` renders the rule select where the TITLE asks, but keys the URL by the RULE

> Namespace: `woocommerce/*` — added session 129 (2026-09-09)

## The trap

A filter with one possible rule renders a `SelectControl` holding one option — a control the
merchant cannot operate. The obvious fix is to stop declaring the rule:

```ts
rules: [],
```

The select does disappear, no error is thrown, and the «Filter» link still builds. It looks like a
clean win. It is not: **the filter can no longer be read back out of the URL.**

```js
window.wc.navigation.getActiveFiltersFromQuery( { has_tracking_is: 'yes' }, { has_tracking: { rules: [ { value: 'is' } ] } } )
// => [ { key: 'has_tracking', rule: 'is', value: 'yes' } ]

window.wc.navigation.getActiveFiltersFromQuery( { has_tracking: 'yes' }, { has_tracking: { rules: [] } } )
// => []
```

Measured on the rig, 09.09.2026. So the merchant applies the filter, the table filters correctly
(our own code reads the query directly), and the advanced block shows **nothing**: the applied
filter cannot be seen, edited or cleared. A worse defect than the dead select, and one that only
shows up on the way BACK to the page.

## Root cause

Two different parts of the contract read `rules`, and only one of them is visible on screen:

| what | reads | consequence |
|---|---|---|
| the rule `SelectControl` | the `{{rule /}}` token in `labels.title` | no token → no control rendered |
| the query key | `getUrlKey( key, rule )` → `has_tracking_is` | no rule → no key |
| reading the filter back | `getActiveFiltersFromQuery()` iterates `rules` | no rule → nothing found |

The control and the round-trip are therefore controlled by **different** things, which is what makes
`rules: []` look safe.

## ❌ Wrong

```ts
has_tracking: {
    labels: { title: __( 'Трек-номер {{rule /}} {{filter /}}' ) },
    rules: [ isRule ],          // one option: an un-operable select
    input: { component: 'SelectControl', options: presenceOptions },
}
```

```ts
has_tracking: {
    labels: { title: __( 'Трек-номер {{filter /}}' ) },
    rules: [],                  // select gone — and so is the round trip
}
```

## ✅ Correct

Keep the rule, drop the token:

```ts
has_tracking: {
    labels: { title: __( 'Трек-номер {{filter /}}' ) },
    rules: [ isRule ],          // never rendered, still builds and parses `has_tracking_is`
    input: { component: 'SelectControl', options: presenceOptions },
}
```

Also rejected, for the record: hiding the select with CSS (the operator called it a crutch, and it
leaves a focusable control in the tab order), and giving the filter two real rules instead of a
value select — a rule carrying no value never reaches the URL at all, because
`getQueryFromActiveFilters()` skips any active filter whose `value` is falsy (measured s128).

**Where this applies:** a PRESENCE filter, whose two states already live in the value select
(«Есть» / «Отсутствует»). A filter with a genuine choice of rules keeps its token — on this page
that is «Статус доставки» and «Статус заказа», which offer «равен» / «не равен».

## Related

- [a-filter-option-keyed-key-instead-of-value-disables-the-filter-button](a-filter-option-keyed-key-instead-of-value-disables-the-filter-button.md) — the other half of this component's contract, and the same shape of lesson: its parts fail silently rather than throwing
- [addhistorylistener-fires-before-the-url-changes](addhistorylistener-fires-before-the-url-changes.md) — the URL is this page's state; anything that breaks reading it back breaks the page
- [wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry](wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry.md) — measuring URL-driven behaviour on this page needs a click, never a `goto`
