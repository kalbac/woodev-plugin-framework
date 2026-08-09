# A `hidden`-attribute element needs its own `[hidden] { display: none }` — an author `display` rule at equal specificity beats the UA default

**Discovered:** s50 (2026-08-03/04), while reviewing Task 17's message card against Task 16's busy
overlay in the pickup map.

## What happened

`pickup-panels.js`'s busy overlay is toggled purely through the DOM `hidden` property:

```js
Panels.prototype.setBusy = function( busy ) {
	this._busy = !! busy;

	if ( this._overlayEl ) {
		this._overlayEl.hidden = ! this._busy;
	}
};
```

Its CSS was:

```css
.woodev-pickup-overlay {
	position: absolute;
	inset: 0;
	display: flex;
	/* … */
}
```

`setBusy( false )` sets the `hidden` attribute correctly. Nothing changes on screen: the overlay
renders `display: flex` permanently, busy or not.

## Why

The browser's UA stylesheet contains `[hidden] { display: none }`. Specificity is computed purely
from selector shape, not origin: a class selector (`.woodev-pickup-overlay`, one class → 0-1-0) and
an attribute selector (`[hidden]`, one attribute → 0-1-0) tie exactly. **At equal specificity, author
styles win over UA styles regardless of source order** — that rule exists specifically so a page's
own CSS can override user-agent defaults at all. So the class rule's `display: flex` beats the UA's
`[hidden]` rule every time, and the element never actually hides.

The fix is to repeat the override explicitly, at a specificity the class rule cannot beat:

```css
.woodev-pickup-overlay[hidden] {
	display: none;
}

.woodev-pickup-overlay {
	display: flex;
	/* … */
}
```

`.woodev-pickup-overlay[hidden]` (class + attribute, 0-2-0) beats the bare class rule regardless of
which is written first — placing it before the base rule here is for readability, not correctness.

## Why no test caught it

jsdom does not compute the cascade the way a real browser does for this purpose, and every existing
test in this file asserts the `hidden` **DOM property**, which is exactly what `setBusy()` sets
correctly. The break is entirely in computed style, which only a real browser shows — same class of
invisible-to-jsdom defect as [[modal-backdrop-opacity-dims-the-whole-dialog]].

## The wider lesson

Any element whose visibility is driven by the plain `hidden` attribute/property — not a class this
file's own rules key off (`.is-open`, `.is-busy`, `.is-card`) — needs its own `[<class>][hidden] {
display: none }` rule. An element gated by a state class instead (the sidebar list, the card, the
search results dropdown) does not have this problem, because that class rule is what supplies
`display` in the first place; there is no competing UA default to lose to.

## Related

- [[modal-backdrop-opacity-dims-the-whole-dialog]] — same session, same "invisible to jsdom, visible
  in a real browser" root class of defect
- `docs-internal/archive/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — V-4 (busy overlay),
  V-5 (message card)
- Issue #160 (found by a Task 17 code-review pass, fixed same session)
