# Two mobile-only defects, both invisible without an actual narrow viewport

**Discovered:** s50 (2026-08-04), rig verification (Task 20), resizing a real page to 390–500px —
neither jsdom (no viewport) nor any desktop screenshot could have shown either one.

## 1. The dialog stayed 920px wide on a real narrow screen

`WoodevModal`'s constructor applies a consumer's `width` option as an **inline** style:

```js
dialog.style.minWidth = 'number' === typeof self._width ? self._width + 'px' : self._width;
```

The pickup map passes `920` (spec V-1, a desktop value). The mobile media query tried to override
it:

```css
@media screen and ( max-width: 782px ) {
	.woodev-modal__content {
		min-width: 100%;   /* ❌ never applies */
	}
}
```

**An inline style always outranks a class selector, regardless of specificity or media query.**
`min-width: 100%` in the stylesheet cannot beat `style="min-width: 920px"` on the element — only
`!important` in an author stylesheet can. The dialog rendered exactly as wide on a 390px phone as on
desktop, with the header and most of the map shifted off-screen to the right.

The fix repeats the pattern already used for `.woodev-modal__body`'s inline `height` in the same
media query — the two are the same collision on two different properties of the same element:

```css
.woodev-modal__content {
	min-width: 100% !important;
}
```

## 2. The zoom control floated over the full-width card

On desktop, `.woodev-pickup-zoom` (bottom-left) and `.woodev-pickup-card`/`.woodev-pickup-list`
(right column, capped at 320px) never overlap, so their relative `z-index` never mattered:
`.woodev-pickup-zoom { z-index: 5 }` sat above `.woodev-pickup-card { z-index: 3 }` by accident, not
by design.

At the mobile breakpoint the panels drop their `max-width` cap and go full-width (spec V-15) — now
overlapping the zoom control's fixed position — and the stale ordering surfaced: the two zoom
buttons floated visibly on top of an otherwise blank stretch of the open card.

Fixed by lowering the zoom control below the panels it can now share screen space with:
`.woodev-pickup-zoom { z-index: 1 }` (list stays 2, card stays 3, the toggle that must remain
clickable over both stays 4 — unaffected).

## The wider lesson

Both were only reachable by actually resizing a live page and clicking through it at that width.
Neither an inline-style collision nor a z-index ordering that only matters once two elements start
occupying the same screen region can be caught by a unit test, a desktop screenshot, or reading the
CSS in isolation — the ≤782px pass in the verification checklist exists specifically because "does
it look right at 920px" says nothing about "does it look right at 390px".

## Related

- The T19 commit already fixed the identical inline-style-vs-media-query collision for
  `.woodev-modal__body`'s `height` — this is the same lesson, applied incompletely to a sibling
  property (`min-width`) on a sibling element (`.woodev-modal__content`) in the same file.
- `docs-internal/archive/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — V-1, V-15
