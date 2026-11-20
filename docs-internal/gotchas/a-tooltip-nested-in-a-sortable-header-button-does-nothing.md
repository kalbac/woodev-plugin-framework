# A @wordpress/components Tooltip nested inside a button does nothing — a help mark in a sortable header needs a popover

**Namespace:** `[admin-ui/tables]` · **Discovered:** s239 (21.11.2026, #1901)

## What happens

A help «?» mark placed with `<Tooltip>` inside a sortable table header (the header itself is a `Button`) never shows its text on hover or focus.

## Root cause

`@wordpress/components` `Tooltip` bails out when it detects an ancestor tooltip or button context (`isNestedInTooltip`): the nested tooltip renders its child and nothing else. A sortable header is a Button, so the help mark sits inside one.

## Fix

- ❌ `<Button onClick={sort}>Name <Tooltip text="…"><span>?</span></Tooltip></Button>`
- ✅ Keep the sort Button and the help mark as siblings, and open the help with a Popover (click/focus) on the mark.

## Related

- [a-select-whose-value-is-no-option-shows-its-first-option](a-select-whose-value-is-no-option-shows-its-first-option.md) — the other table-chrome trap of the same cards
- [s239 session](../sessions/s239.md)
