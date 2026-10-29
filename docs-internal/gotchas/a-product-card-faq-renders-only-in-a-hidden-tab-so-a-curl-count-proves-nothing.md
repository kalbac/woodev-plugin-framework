# Gotcha: [build/release] — The product-card FAQ renders only in the hidden «FAQ» tab; a curl count proves nothing

> Tags: release, content, woodev-ru, faq | Session: s202 (T-Bank download 1849)

## What happens

After attaching a BetterDocs FAQ group to a product card, `curl` finds the questions in the HTML, which looks like proof the
operator sees them. On the page they sit inside the «FAQ» tab, which is hidden until clicked.

## Root cause

The theme renders `_faq_content` through `inc/template-tags.php` → `partials/content-download-tabs.php` into a tab panel. The
markup is in the page source whether or not the tab is visible.

## Fix

❌ **Wrong** — count questions with curl and report the FAQ as visible.

✅ **Correct** — open the product page in a browser, click the «FAQ» tab, and look; keep the shortcode in `_faq_content`
(`[betterdocs_faq_list_modern groups="…"]`).

## Related

- [Releasing a plugin (skill)](../../.ai/skills/woodev-plugin-core/layout-and-testing.md#releasing-a-plugin)
- [build topic index](../gotcha-index/build.md)
