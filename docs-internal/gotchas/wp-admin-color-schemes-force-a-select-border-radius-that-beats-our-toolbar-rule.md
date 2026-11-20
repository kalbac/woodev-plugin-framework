# The WP admin colour scheme sets `select` border-radius with `!important` — it beats a toolbar select rule

**Namespace:** `[admin-ui/tables]` · **Discovered:** s239 (21.11.2026, #1901)

## What happens

Selects in our records-table toolbar keep a rounded 8px corner although our stylesheet sets a flat radius; the toolbar looks inconsistent with the grouped buttons beside it.

## Root cause

The recoloured admin schemes (`wp-recolor`) ship `select { border-radius: 8px !important }`. A plain rule of ours, whatever its specificity, loses to `!important`.

## Fix

- ❌ `.woodev-toolbar select { border-radius: 0; }`
- ✅ `.woodev-toolbar select { border-radius: 0 !important; }` — only an `!important` of ours, scoped to our toolbar, wins; verify in the browser, not by grepping the stylesheet.

## Related

- [a-grep-of-a-vendor-stylesheet-is-an-incomplete-measurement](a-grep-of-a-vendor-stylesheet-is-an-incomplete-measurement.md) — why the vendor rule was missed
- [s239 session](../sessions/s239.md)
