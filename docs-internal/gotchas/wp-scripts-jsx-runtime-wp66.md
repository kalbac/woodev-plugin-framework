# [build/js] `@wordpress/scripts` automatic JSX runtime requires WP ≥ 6.6 — use the classic runtime for WP 6.3+ support

**Discovered:** 2026-06-11 (s8, S3.2 — caught by the GPT-5.5 critic on s6-p3 before any browser ever saw it)

## The trap

The default `@wordpress/scripts` build compiles JSX with the **automatic** runtime, so
`DependencyExtractionWebpackPlugin` emits a dependency on the **`react-jsx-runtime`**
script handle. WordPress core registers that handle **only since WP 6.6**. On WP 6.3–6.5
the bundle's dependency is unresolvable → the script never enqueues → the admin page is
silently blank. CI/unit tests don't catch it (no real WP script registry involved).

## The fix (ADR-007)

Root `babel.config.js` forces the **classic** runtime — Babel applies `plugins` before
`presets`, so the explicit transform preempts the preset's automatic one:

```js
module.exports = {
	presets: [ '@wordpress/babel-preset-default' ],
	plugins: [
		[ '@babel/plugin-transform-react-jsx', {
			runtime: 'classic',
			pragma: 'createElement',
			pragmaFrag: 'Fragment',
		} ],
	],
};
```

Consequence: **every JSX file must import `{ createElement, Fragment }` from
`'@wordpress/element'`** (the pragma resolves lexically). The asset deps then contain
`wp-element` instead of `react-jsx-runtime`.

## Verification

Check `woodev/assets/build/*/index.asset.php` after a build: the `dependencies` array
must NOT contain `react-jsx-runtime` while the framework's minimum WP is < 6.6.

## Related

- [[../adr/007-react-admin-stack-wordpress-scripts.md]] — the React baseline decision
- [russian-source-i18n-plural-n.md](russian-source-i18n-plural-n.md) — the other UI-layer i18n trap from the same feature family
