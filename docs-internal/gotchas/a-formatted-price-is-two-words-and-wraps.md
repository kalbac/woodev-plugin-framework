# Gotcha: [admin-ui/tables] — A formatted price is TWO words to the browser, so a narrow column breaks the amount across lines

> Tags: admin-ui, tables, css, i18n, money, measurement | Session: s133

## What happens

`wc_price()` renders a Russian amount as `3 980,00 ₽` — thousands separated by a **space**. The
browser sees a space and treats the amount as two words, so the moment the column is narrower than
the whole string it breaks it:

```
3
980,00 ₽
```

Measured on the rig (WC 11.1.0, 1440×900): the «Оплата» cell was **98 px** with `padding: 8px 24px`,
leaving **50 px** of content box against **59 px** the text needed. Every row wrapped; cell height
was 92–128 px instead of one line.

## Why it hid until s133

While the seeded orders had no line items every amount was `0,00 ₽` — short enough to fit, so the
column looked fine. The defect appeared the same day #861 gave those orders real totals. **An
appearance defect measured against an empty fixture reports the wrong answer**, and the card that
preceded this one (#862) had in fact blamed a different column entirely.

## Why jest cannot catch it

jsdom computes no layout. A green suite says nothing about whether a string wrapped; only opening
the page does. The tests that now guard this assert the *mechanism* (the class is on the rendered
node), not the appearance.

## ❌ Wrong

```scss
// The shared meta class also renders the postal address in «Доставка» —
// that one MUST wrap. This breaks it.
.woodev-orders-cell__meta { white-space: nowrap; }
```

```scss
// TableCard gives its cells no per-column class, so this is positional —
// and adding the «Действие» column (#824) shifts it onto the wrong cell.
.woocommerce-table__item:nth-child(6) { white-space: nowrap; }
```

## ✅ Correct

Give the amount its own class where it is rendered, and pin only that:

```tsx
<span className="woodev-orders-cell__meta woodev-orders-amount">
    { payment.formatted_total }
</span>
```

```scss
.woodev-orders-amount { white-space: nowrap; }
```

Then verify in the browser: the amount's rendered height must be one line (18 px here), and the
address in the neighbouring cell must still compute `white-space: normal`.

## Related

- [a-grep-of-a-vendor-stylesheet-is-an-incomplete-measurement](a-grep-of-a-vendor-stylesheet-is-an-incomplete-measurement.md) — the other s133 layout defect no test could see
- [a-hand-typed-format-table-drifts-from-the-real-spec](a-hand-typed-format-table-drifts-from-the-real-spec.md) — the other place a locale-formatted string bit us
- `docs-internal/sessions/s133.md`
