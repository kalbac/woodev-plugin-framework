# Gotcha: [bootstrap/loader] — a loader definition cannot use a framework class constant
> Tags: bootstrap, loader, v2-migration, entry-point | Session: s115

## What happens

A plugin entry file migrated onto v2 registers itself the way every example in `tests/unit/`
shows, using the platform constant:

```php
require_once plugin_dir_path( __FILE__ ) . 'woodev/loader.php';

Woodev_Loader::register( __FILE__, [
	'platform' => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
	// …
] );
```

WordPress dies before it can render anything:

```
PHP Fatal error: Uncaught Error: Class "Woodev\Framework\Framework_Plugin_Loader_Definition"
not found in …/woocommerce-edostavka.php:38
```

The tell that sends you the wrong way: the class **is** in the bundle, it **is** in
`class-map.php`, and `php -l` is clean. Nothing about the file is wrong.

## Root cause

PHP builds the argument array **before** it calls the function. `Woodev_Loader::register()` is
what requires `woodev/bootstrap.php`, and the bootstrap is what registers
`Woodev_Framework_Autoloader`. So at the moment the array literal is evaluated **no framework
class has been loaded yet** — not even by autoload, because the autoloader itself does not exist
until one line later.

`loader.php` deliberately defines only `Woodev_Loader` and requires nothing else, so requiring it
first does not help either.

## Why every in-repo example looks like it disagrees

Because none of them run through the entry path:

- `BootstrapRegistrationTest`, `FrameworkResolverTest` and the rest call
  `register_loader_definition()` **directly**, with the whole framework already loaded by
  Composer. The constant is fine there.
- `tests/_fixtures/woodev-edostavka-pilot-plugin/` returns its definition from a *function*, and
  `EdostavkaPilotFixtureTest` calls that function — again with the framework already in memory.
  ⚠ **The fixture never calls `Woodev_Loader::register()` at all**, so despite what
  `migration/edostavka-data-preservation-checklist.md` says about it validating "the new Platform
  v2 load path", it does not exercise that path.
- `LoaderFacadeTest` is the ONE place that really calls `Woodev_Loader::register()`, and it passes
  `'platform' => 'wordpress'` — a plain string. That is the honest example of the contract.

## Fix

❌ Wrong — a constant in a definition passed to `Woodev_Loader::register()`:

```php
'platform' => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
```

✅ Correct — the literal, with a comment so nobody "tidies" it back:

```php
// A literal, NOT the PLATFORM_WOOCOMMERCE constant: this array is built before
// Woodev_Loader::register() requires the bootstrap, so no framework class exists yet.
'platform' => 'woocommerce',
```

The accepted values are `'wordpress'`, `'woocommerce'`, `'edd'` (`class-framework-plugin-loader-definition.php:24-26`).

The same reasoning applies to **any** framework constant, class reference or `::class` in that
array — the entry file may only use literals and its own constants.

## Related

- [framework-classmap-autoload-vendored-boot](framework-classmap-autoload-vendored-boot.md) — the autoloader whose absence this exposes
- [plugins-reference-predates-every-v2-api](plugins-reference-predates-every-v2-api.md) — the other trap when reading old plugins for v2 guidance
- `docs-internal/AGENT-RULES.md` → Rule 3 — the registration contract; note the definition field is `framework_version`, while the rule's prose calls it `version` (that is the name it is mapped to internally)
