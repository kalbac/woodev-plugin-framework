# A field that is a VIEW of another must be derived at the boundary, never at the display sites

**Namespace:** `[framework/contracts]`
**Discovered:** s64 (11.08.2026), issue #263
**Cost:** a search dropdown that rendered five results as five identical lines, unusable —
the customer could not tell one point from another.

## What happened

`Pickup_Point` has `address` (required) and `short_address` (optional). Two places render a
point row, and each decided for itself what to do when `short_address` was empty:

```js
// pickup-panels.js, buildSinglePointRow() — the sidebar row
addressEl.innerHTML = fieldValue( point.short_address ) || fieldValue( point.address );

// pickup-panels.js, buildSearchPointItem() — the search row, ~170 lines below
addressEl.innerHTML = fieldValue( point.short_address );
```

The live Yandex source sends no `short_address`. The sidebar row was fine. The search row
rendered an EMPTY address line, so each result collapsed to the point NAME alone — and a
retail chain's points share a name by construction, so a search for one street produced five
rows all reading «5 Post (Пятерочка)».

The tell was in the file already: a comment ~20 lines below the search row **cites the
fallback** ("already makes one screenful up for `short_address` falling back to `address`").
The rule had been written down. It had been applied to one of the two sites.

## Why the obvious fix is the wrong one

"Add the fallback to the search row too" restores the symmetry and leaves the shape that
produced the bug: N display sites, one rule, enforced N times. The third site drifts next.

The other obvious fix — make `short_address` REQUIRED so the domain must supply it — was
considered and rejected by the operator (11.08.2026): most carriers have no separate short
form and would write `'short_address' => $address` verbatim, which grows the contract without
making it more informative, and breaks every plugin already written against it.

## The rule

- A field that is a **derived view of a required field** (`short_address` of `address`) is
  **derived once, at the boundary constructor**. Its absence carries no information, so there
  is nothing for a display site to decide. The domain may still override with a real short
  form; it is a default, never a replacement.
- A field with **no derivation source** (`phone`, `work_time`, `instruction`) stays optional,
  and its absence IS information — «this carrier does not publish it». Forcing a plugin to
  fill it produces invented data, which is worse than an honest blank. Display code must
  handle absence for these, and only these.

Downstream may then assume the derived field is non-empty whenever its source is — here,
always, because `address` is required and a payload without it never becomes a point.

## Both ends of a boundary must derive identically

This point shape has TWO boundary constructors, one per path:

| Path | Constructor |
|---|---|
| `woodev/v1` REST | `Pickup_Point::from_array()` (PHP) |
| embedded provider | `normalizePoint()` (`map-provider-embedded.js`) |

They must derive the same way. The framework has already paid for letting these two drift:
`isNumeric()` validated a hex coordinate string that `parseFloat()` then read as `0`, so a
point silently landed on null island (#201 → live in #251).

## Escaping is not affected — check it anyway

A derived value must cross the browser boundary through the same escaping as a supplied one.
Here it does by construction: the derivation happens in `from_array()`, long before
`to_browser_array()` runs its escape list, and `short_address` is on that list. Pinned by a
test (`test_a_derived_short_address_is_escaped_for_the_browser`) rather than assumed, because
the consumers write this field with `innerHTML`.

## Related

- [built-on-both-sides-with-no-caller-in-the-middle.md](built-on-both-sides-with-no-caller-in-the-middle.md) — the same "rule written for one half of a pair" family
- [modal-script-versioned-by-version-constant-not-filemtime.md](modal-script-versioned-by-version-constant-not-filemtime.md) — a rule stated correctly in a comment and applied to one half of a shared handle
- [a-constant-field-cannot-be-a-verdict.md](a-constant-field-cannot-be-a-verdict.md) — measuring a third-party field before mapping it onto anything customer-facing
