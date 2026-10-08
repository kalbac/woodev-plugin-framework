# Gotcha: [build/wp-scripts] — A plain `.css` imported from the Blocks entry lands in `index.css`, which nothing registers
> Tags: build/wp-scripts, shipping/checkout-blocks | Session: s160

## What happens

The block-checkout address suggestions (#1164) imported the classic typeahead stylesheet with
`import '…/location.css'` in `src/checkout-blocks/index.ts`. The build passed, jest passed, the critic approved — and
the suggestion list would have rendered unstyled at the bottom of the page on a real shop.

## Root cause

wp-scripts' webpack config splits CSS by FILE NAME: only an imported `style.(s)css` goes to `style-index.css`; any
other imported stylesheet goes to `index.css`. `Locality_Blocks_Integration` registers only `style-index.css`, so the
new `index.css` (and `index-rtl.css`) were emitted, untracked, and loaded by nobody. The only signal was two NEW
untracked files in `woodev/assets/build/checkout-blocks/` after the coordinator's build.

## Fix

❌ Import an extra stylesheet from the entry and assume the bundle carries it.

✅ Pull it into the existing `style.scss` without the extension so Sass inlines it
(`@import "../../woodev/shipping-method/assets/css/frontend/location";`) — `style-index.css` grew 1.8 → 5 KB. After any
build, an untracked file under `woodev/assets/build/` is a question to answer, not noise.

## Related

- [local-npm-run-build-is-not-assets-parity-evidence](local-npm-run-build-is-not-assets-parity-evidence.md)
- `woodev/shipping-method/checkout/blocks/class-locality-blocks-integration.php`, `src/checkout-blocks/style.scss`
