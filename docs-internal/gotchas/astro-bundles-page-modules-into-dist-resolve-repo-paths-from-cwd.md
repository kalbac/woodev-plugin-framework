# Gotcha: [build/website] — Astro bundles a page's imported modules into `dist/`, so `import.meta.url` no longer points into the repo

> Tags: astro, starlight, website, build | Session: s183

## What happens

`website/plugins/framework-version.mjs` read `woodev/class-plugin.php` via
`new URL('../../woodev/class-plugin.php', import.meta.url)`. As a remark plugin (loaded by `astro.config.mjs`) it worked;
imported from a PAGE (`src/pages/index.astro`) the build died with
`ENOENT … website/dist/woodev/class-plugin.php` — Astro had bundled the module into `dist/` before running it.

## Root cause

Page-side imports are bundled and executed from the build output, so a module-relative path is relative to `dist/`, not
to the source file. Config-side code is not bundled, which is why the same module worked there.

## ✅ Correct

Resolve repository paths from the working directory the build runs in (`website/`, locally and in `docs.yml`):

```js
// ❌ breaks when imported from a page
const SOURCE = fileURLToPath( new URL( '../../woodev/class-plugin.php', import.meta.url ) );
// ✅ the build always runs from website/
const SOURCE = resolve( process.cwd(), '../woodev/class-plugin.php' );
```

## Related

- [the-mo-is-reproducible-from-the-po](the-mo-is-reproducible-from-the-po.md) — another build-time read of a source file
