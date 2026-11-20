# Newer WP Modal wraps content in `.components-modal__children-container` — flex/scroll rules on `__content` do not apply

**Namespace:** `[admin-ui/modal]` · **Discovered:** s239 (21.11.2026, #1894)

## What happens

A dialog with a footer: the footer is clipped or the body does not scroll on a short viewport, although the CSS gives `.components-modal__content` a flex column and an inner scroll.

## Root cause

Newer WordPress versions insert `.components-modal__children-container` (`display: block`, no height limit) between `__content` and our children, so the flex/overflow rules never reach our body and footer.

## Fix

- ❌ Style only `.components-modal__content` as the flex container.
- ✅ Make the children container the flex column too (`display: flex; flex-direction: column; min-height: 0`) in the shared dialog stylesheet (`woodev-dialog.css`), and check on a WP version that has the wrapper.

## Related

- [s239 session](../sessions/s239.md)
