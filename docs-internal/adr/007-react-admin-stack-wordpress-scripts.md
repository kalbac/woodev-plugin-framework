# ADR-007: React Admin Stack — @wordpress/scripts

**Status:** accepted

**Date:** 2026-06-11

## Context

S3.2 introduces the first React-powered admin surface in the Woodev Plugin Framework: the
license-management page. Before writing any component code, the build toolchain must be
chosen and locked.

Decision **D-1** (operator decision, 2026-06-10, S5 foundation) resolved the open
questions before implementation began: use `@wordpress/scripts` as the sole build tool,
author components with JSX, rely on the `@wordpress/components` design system, and commit
the compiled bundle so that vendored plugin consumers need no Node toolchain to deploy.

Key constraints:
- The framework ships as a vendored library inside each consuming plugin; CI must be the
  single source of truth for the compiled assets.
- WordPress already ships React, `@wordpress/element`, and the major `@wordpress/*`
  runtime packages as globals. Bundling them would bloat every plugin zip and conflict
  at runtime.
- Deterministic builds are mandatory: the bundle committed on the feature branch must be
  byte-for-byte identical to what `npm ci && npm run build` produces on a clean Linux CI
  runner.

## Decision

Adopt **`@wordpress/scripts`** as the exclusive build toolchain for all framework JS/CSS.

Specific choices:

1. **`@wordpress/scripts`** — wraps webpack 5 + Babel + SASS with WordPress-safe defaults.
   Pinned to an exact version in `package.json` (no `^`) and locked via
   `package-lock.json`; `.nvmrc` pins the Node major.

2. **JSX** — components are authored in JSX (`.js` files); `@wordpress/scripts` transpiles
   JSX out of the box via Babel with the `@wordpress/babel-preset-default` preset.

3. **`@wordpress/components`** as the design system — native WP admin look-and-feel,
   zero additional bundle cost (externalized, see point 4).

4. **Major `@wordpress/*` runtime packages are externalized** via the bundled
   `DependencyExtractionWebpackPlugin` — for every package that WordPress exposes as
   a `wp.*` global (element, components, api-fetch, i18n, …) the plugin rewrites
   `import { createElement } from '@wordpress/element'` into a reference to
   `wp.element.createElement` and records the script handle in `index.asset.php`.
   Exception: packages the plugin does not map to globals (e.g. `@wordpress/icons`)
   are bundled into the output — keep an eye on bundle size when importing those.
   JSX compiles to `wp.element.createElement` calls — the `react` global is not
   referenced by this bundle at all. The generated `index.asset.php` currently
   declares `wp-element` as the sole JS dependency so WordPress enqueues it before
   the bundle.

5. **Classic JSX runtime** — `babel.config.js` at the project root extends
   `@wordpress/babel-preset-default` and adds `@babel/plugin-transform-react-jsx`
   with `runtime: 'classic'`, `pragma: 'createElement'`, `pragmaFrag: 'Fragment'`
   in the `plugins` array. Babel runs plugins before presets, so the classic
   transform preempts the preset's automatic-runtime transform (which then finds
   no JSX left and no-ops). The automatic runtime (default in
   `@wordpress/babel-preset-default`) emits `import { jsx } from 'react/jsx-runtime'`
   which `DependencyExtractionWebpackPlugin` maps to the WP script handle
   `react-jsx-runtime`. That handle is only registered by WordPress core from
   WP 6.6 onwards. This framework supports WP 6.3+, so using the automatic runtime
   would break pages on WP 6.3–6.5. The classic runtime resolves to `wp.element`
   (present in all supported WP versions) and requires no special enqueue shim.
   **Rule for s6-p4 and all subsequent JSX files:** every `.js` file that contains
   JSX must include `import { createElement, Fragment } from '@wordpress/element';`
   at the top so the pragma references are defined in scope.

6. **Build output committed** under `woodev/assets/build/license-page/` — the compiled
   `index.js`, `index.asset.php`, and `style-index.css` are tracked in git. Vendored
   consumers (plugin repos that include the framework as a subtree or ZIP) need no Node
   toolchain at deploy time.

7. **`src/`** directory is the authoring root and must not ship in release ZIPs.
   As of this ADR the release rsync does NOT yet exclude `src/` — task **s6-p5** adds
   `--exclude='src'` to the release workflow. `woodev/assets/build/` rides inside
   `woodev/` and IS included.

## Consequences

**Easier:**
- Any WordPress developer already knows `wp-scripts`; no custom webpack configuration
  to maintain.
- RTL CSS is generated automatically (`style-index-rtl.css`).
- The `index.asset.php` dependency manifest is generated automatically; PHP enqueue
  code can `include` it directly rather than hardcoding script handles.
- CI build-parity check (s6-p5) is straightforward: `npm ci && npm run build &&
  git diff --exit-code woodev/assets/build/` fails if the committed bundle is stale.

**Harder / accepted costs:**
- Node toolchain required for local development (mitigated by exact-pinned
  `@wordpress/scripts` + `package-lock.json` + `.nvmrc`).
- `@wordpress/scripts` major-version upgrades require a `npm run build` + commit step
  to refresh the committed bundle.
- Committed CSS includes an auto-generated RTL variant (`style-index-rtl.css`); both
  must be committed even if RTL is not used initially.
- A project-root `babel.config.js` replaces the wp-scripts fallback Babel config, so
  `npm start` loses the conditionally-added React Fast Refresh plugin. Accepted: the
  admin surface is small; restore `react-refresh/babel` in the config if hot reload
  becomes worth it.

## Related

- [006-capability-gated-feature-seam.md](006-capability-gated-feature-seam.md) — feature seam ADR for S3 licensing UI
- [005-platform-v2-clean-break-policy.md](005-platform-v2-clean-break-policy.md) — clean-break policy (internal code free to change)
- `docs-internal/platform-v2-s3-licensing-ui-plan.md` — task s6-p3 (this scaffold) and s6-p4 (full UI)
- `docs-internal/platform-v2-s3-licensing-ui-spec.md` — §5.1 toolchain spec, §6 build decisions
