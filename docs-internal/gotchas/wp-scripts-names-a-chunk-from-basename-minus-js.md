# Gotcha: [build/wp-scripts] — a `.tsx` build entry silently becomes `index.tsx.js`, because wp-scripts strips only `.js` from the entry name

> Tags: build, js, typescript | Session: s125

## What happens

Convert a page entry from `index.js` to `index.tsx`, point the build script at it the obvious way:

```json
"build:orders": "wp-scripts build ./src/shipping-orders-page/index.tsx --output-path=woodev/assets/build/shipping-orders-page"
```

The build succeeds. It emits **`index.tsx.js`**, `index.tsx.asset.php` and
`style-index.tsx.css` — while the PHP that enqueues them asks for `index.js`,
`index.asset.php` and `style-index.css`. The page then loads nothing, with no build error and no
PHP error: `file_exists()` on the asset file simply fails and the enqueue falls back or goes silent.

## Why

`@wordpress/scripts` derives a CLI entry's webpack chunk name with
`basename( path, '.js' )` (`node_modules/@wordpress/scripts/utils/config.js`). That strips `.js` and
nothing else — `.ts`, `.tsx` and `.jsx` survive into the chunk name and therefore into every emitted
filename.

## Correct

Use wp-scripts' documented `name=path` entry syntax, which sets the chunk name explicitly:

```json
"build:orders": "wp-scripts build \"index=./src/shipping-orders-page/index.tsx\" --output-path=…"
```

Output filenames are `index.js` / `index.asset.php` / `style-index.css` again, and **no PHP has to
change** — which is the point: the enqueue side should not have to know the source language.

Apply it to every script naming that entry (`build`, `build:<page>`, `start:<page>`) — the main
`build` chain is what CI's **Assets build parity** job runs, so a chain still passing the bare path
fails parity even when the per-page script was fixed.

## Also needed on the first `.ts`/`.tsx` entry in a repo

A side-effect stylesheet import (`import './style.scss'`) has no ambient type, so `tsc` fails. One
project-wide declaration covers it and every future TS entry:

```ts
// src/global.d.ts
declare module '*.scss';
```

## Related

- [npm-run-test-js-is-not-the-whole-js-gate](npm-run-test-js-is-not-the-whole-js-gate.md) — the gate
  that sends you into this conversion in the first place
- [local-npm-run-build-is-not-assets-parity-evidence](local-npm-run-build-is-not-assets-parity-evidence.md)
  — where the rebuilt bundle must be produced
