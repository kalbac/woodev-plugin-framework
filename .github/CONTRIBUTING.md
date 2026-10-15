# Contributing to Woodev Plugin Framework

Thank you for considering a contribution. This guide covers what a pull request has to
satisfy before a maintainer reviews it.

## What this project is

The Woodev Plugin Framework is a PHP library (PHP 7.4 and newer) that gives WooCommerce
plugins a common base: plugin lifecycle, settings, admin pages, shipping and payment
subsystems. It is **bundled inside every plugin that uses it** — plugin authors copy the
`woodev/` directory into their plugin, and several such plugins run on the same site at
once.

That is why compatibility matters more here than in a typical library. A change is judged
by two separate rules:

- **Framework internals can change.** Class names, method signatures, file layout and
  namespaces are free to move on `main`. Do not add `@deprecated` shims, `class_alias`
  files or `_deprecated_function()` wrappers for an internal API you renamed or moved;
  delete the old one instead. The reasoning is in
  [ADR-005](../docs-internal/adr/005-platform-v2-clean-break-policy.md).
- **Data on installed sites must never change.** A shop that has the plugin installed
  already stores these, and a changed value silently breaks it. Keep each of them
  byte-for-byte identical:
  - option keys and order, session and user meta keys;
  - WooCommerce gateway and shipping-method IDs, and their instance setting keys;
  - public action and filter names, and the shape of their arguments;
  - cron hooks and their payloads, custom database tables, background-job IDs;
  - REST namespaces, AJAX actions, admin page slugs, log source names.

If your change touches one of these, say so in the pull request description and explain why.
Anything under `woodev/` gets a closer review than the rest of the repository.

## Ways to contribute

Report a bug through an [issue](https://github.com/kalbac/woodev-plugin-framework/issues/new/choose) with reproduction steps and your
WordPress, WooCommerce and PHP versions. For a feature, open an issue first and describe the
problem, so the approach is agreed before you write code. Questions go to
[Discussions](https://github.com/kalbac/woodev-plugin-framework/discussions); issues labelled
[`good first issue`](https://github.com/kalbac/woodev-plugin-framework/labels/good%20first%20issue) are the easiest start.

**Security issues are never reported in public issues.** See [SECURITY.md](SECURITY.md).

## Setting up

You need PHP 7.4 or newer with [Composer](https://getcomposer.org/), and the Node.js version
in [`.nvmrc`](../.nvmrc) (22).

```bash
# after forking on GitHub:
git clone https://github.com/<your-username>/woodev-plugin-framework.git
cd woodev-plugin-framework

composer install        # PHPCS, PHPStan, PHPUnit
npm ci                  # wp-scripts (jest, build), TypeScript

git config core.hooksPath .githooks   # once per clone — see below
```

### Git hooks

The hooks in [`.githooks/`](../.githooks) stop mistakes that would cost a review round:

- `commit-msg` refuses a GitHub closing keyword (`closes`, `fixes`, `resolves` plus `#N`)
  anywhere except alone on its own line — GitHub runs them literally, even inside a quote.
- `pre-push`, when the push touches `src/` or `woodev/`, rebuilds the JS bundles and runs the
  translation-catalogue checks (about 20 seconds). It refuses a push whose committed bundles
  differ from a fresh build.
- `post-merge`, `post-checkout`, `post-commit` and `post-rewrite` run `composer dump-autoload`
  when git moved framework PHP files, so your class map is never stale.

`git push --no-verify` skips `pre-push`, but CI runs the same checks and will fail.

### Integration tests (optional)

Unit tests need only PHP. The integration suite needs WordPress with WooCommerce, which
[`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) provides (Docker required):

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli \
  env TEST_SUITE=integration \
  php /var/www/html/woodev-framework/vendor/bin/phpunit \
    --configuration /var/www/html/woodev-framework/phpunit.xml \
    --testsuite=Integration --no-coverage
npx @wordpress/env stop
```

CI runs this suite across a WordPress × WooCommerce × PHP matrix, so you need not.

## Coding rules

A reviewer checks the following; [the checks below](#checks-that-must-pass) automate most of it.

### PHP

- **WordPress Coding Standards**: tabs, `snake_case` for methods, variables and hooks,
  `Snake_Case` for classes, Yoda conditions. `composer phpcs` reports violations and
  `composer phpcbf` fixes most of them.
- **Short array syntax** — `[]`, never `array()`.
- **Type declarations** on every parameter and return value.
- **Docblocks** on every public and protected method, with `@since`, `@param` and `@return`.
- **Visibility**: start with `private`; use `protected` or `public` only when something
  outside needs the member. A method that depends only on its arguments is `static`.
- **Namespaces**: write new code under `Woodev\Framework\*` (PSR-4 layout, e.g.
  `Woodev\Framework\Shipping\Shipping_Plugin`). The legacy global `Woodev_*` classes exist
  only in files that have not been migrated yet; do not add new ones.
- **HPOS**: read and write order data through `Woodev_Order_Compatibility`, never
  `get_post_meta()` on an order.
- Treat request data as untrusted: sanitise input, escape output, check capabilities and
  nonces, use `$wpdb->prepare()`.

#### `@since` follows semver

`@since` is the release the code first ships in — not the current `VERSION` and not a date.
The authority is `extra.woodev.planned-release` in [`composer.json`](../composer.json)
(currently `2.0.3`); the unit test `SinceTagCeilingTest` fails if a tag is above it.

- `2.0.x` — bug fixes only; until a feature merges, new code carries the next patch.
- `2.1.0` — a minor release; the first pull request that adds a feature raises
  `planned-release` to it.
- `3.0.0` — a breaking release.

### JavaScript and TypeScript (`src/`)

- **New files in `src/` are TypeScript.** Existing `.js` and `.jsx` files are migrated when
  you touch them, not in bulk. `npm run lint:ts-baseline` and `npm run typecheck` enforce this.
- The raw scripts under `woodev/**/assets/js/frontend/` are served to the browser as they
  are; they stay plain JavaScript.
- `woodev/assets/build/` holds **generated** bundles that are committed. Never edit them by
  hand; change `src/` and run `npm run build`.

### Translatable strings

Who reads a string decides its language:

| The string is read by… | Write the `msgid` in… |
|---|---|
| A shopper, on the storefront | **English** — the Russian text comes from the catalogue, because a shop's frontend can run in English |
| A merchant, in the admin | Russian is fine; if you write English, add the translation to the catalogue |
| Nobody on a screen — logs, exception messages | Either language; no `__()` wrapper is needed |

Where you do wrap a string, the text domain is `woodev-plugin-framework`. Decide by where
the string is **rendered**, not by which directory the file sits in. When you add or change
a translatable string, the Russian catalogue,
`woodev/languages/woodev-plugin-framework-ru_RU.po`, must agree with the source: every
source string has to be in it, and every English `msgid` needs a Russian translation.
`npm run lint:i18n-sources` and `npm run lint:i18n` check both; `npm run lint:mo` and
`npm run lint:js-i18n` check that the compiled `.mo` and per-bundle JSON files are current
relative to the `.po`.

### Wording merchants read

Merchants are shop owners, not developers. In anything they read — labels, tooltips,
descriptions, admin notices:

- say «форма оформления заказа», not «чекаут»;
- never use the word «фреймворк»; name the plugin, or leave the word out;
- keep labels short; a tooltip or description explains the setting.

Code comments, docblocks and log lines are written for developers and may use the precise terms.

## Checks that must pass

Run these before you open a pull request. CI runs the same ones.

```bash
composer check          # PHPCS + PHPStan + unit tests
npm run test:js         # jest, through wp-scripts
npm run typecheck       # tsc over src/
npm run lint:ts-baseline
```

If you changed anything under `src/`, also run:

```bash
npm run build           # rebuild bundles — commit the result
npm run lint:i18n-sources
npm run lint:i18n
npm run lint:mo
npm run lint:js-i18n
```

- Use `npm run test:js`, never `npx jest` — a bare jest loses the jsdom environment.
- **Build parity**: CI rebuilds the bundles and fails if they differ from what you
  committed, `*.min.js` and `*.min.css` included.
- One unit test file: `./vendor/bin/phpunit tests/unit/BootstrapTest.php`.
- New behaviour comes with a test: PHP unit tests (Brain Monkey, no WordPress) in
  `tests/unit/`, JS tests in `tests/js/`.

## Commits and pull requests

Name a branch `{type}/{short-description}` — `feat/shipping-by-volume`,
`fix/rate-rounding`, `docs/contributing-guide` — with the same types as commits.

Commits follow [Conventional Commits](https://www.conventionalcommits.org/):
`type(scope): description`, for example `fix(bootstrap): handle missing WooCommerce`. The
changelog is generated from them, so the type decides which section your change lands in.
Types: `feat`, `fix`, `perf`, `refactor`, `docs`, `test`, `ci`, `chore` (left out of the
changelog). A breaking change adds `!` after the type and a footer:

```text
feat!: remove old_method()

BREAKING CHANGE: old_method() has been removed. Use new_method() instead.
```

A GitHub closing keyword goes **alone on its own line**, and only when the commit finishes
the issue:

```text
fix: stop rounding the rate twice

Closes #123
```

To merely mention an issue write `see #123`; the `commit-msg` hook rejects `closes`,
`fixes` and `resolves` anywhere else.

For the pull request: work in a branch of your fork, keep one concern per pull request, run
the [checks](#checks-that-must-pass), open it against `main` and fill in the template —
especially *How to test* and *Areas for human testing*, where you name what a machine could
not verify. Reviewers look at coding standards, design, tests, security, documentation and,
above all, whether anything on the [installed-site list](#what-this-project-is) changed.

## Publishing rules

- **Only the maintainer merges.**
- **A pull request without green CI is not reviewed.** Every CI job has to pass on its own.
- **Only the maintainer raises `Woodev_Plugin::VERSION`.** Raising it on `main` publishes a
  release (tag, changelog, archive). Do not change it in a contribution; if your change
  should ship, say so in the pull request.
- **The changelog is generated** from commit messages. Never edit `CHANGELOG.md`.
- **Documentation changes go to `website/`** and follow the rules below.

## Documentation

The public site lives in [`website/`](../website) (Astro Starlight, English); pages are
Markdown in `website/src/content/docs/`.

- Document **only the public API** a plugin author uses, with a working example for each
  capability, written so a reader a year from now still understands it.
- **Verify every example against the source.**
- Use the `%%FRAMEWORK_VERSION%%` placeholder for version numbers; never hardcode one.
- A new page also needs an entry in the sidebar in `website/astro.config.mjs`.
- Preview with `cd website && npm ci && npm run dev`; check a production build with
  `npm run build`.
- Lint with `npx markdownlint-cli2 "website/src/content/docs/**/*.{md,mdx}"`.

## License and questions

Contributions are released under the project's license, GPL-3.0-or-later. Questions go to
[Discussions](https://github.com/kalbac/woodev-plugin-framework/discussions); the
[documentation](https://kalbac.github.io/woodev-plugin-framework/) is the place to start.
