---
title: Loader definition reference
description: Look up every field of the loader definition and what it controls.
---

In this page you will learn every field of the loader definition, the array that registers your plugin with the framework, and what happens when a field is wrong.

## The shape of a definition

You pass the definition to `Woodev_Loader::register()` in your plugin's main file. This one is complete and valid:

```php
<?php
defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'woodev/loader.php';

Woodev_Loader::register(
	__FILE__,
	[
		'plugin_id'            => 'my-carrier',
		'download_id'          => 1234,
		'plugin_name'          => 'My Carrier',
		'plugin_version'       => '1.0.0',
		'framework_version'    => '%%FRAMEWORK_VERSION%%',
		'backwards_compatible' => '2.0.0',
		'platform'             => 'woocommerce',
		'requirements'         => [
			'php'         => '7.4',
			'wordpress'   => '6.6',
			'woocommerce' => '7.0',
		],
		'supported_features'   => [
			'hpos'   => true,
			'blocks' => [
				'cart'     => true,
				'checkout' => true,
			],
		],
		'main_class'           => 'My_Carrier_Plugin',
	]
);
```

The first argument is the full path of the main file. `register()` adds it to the definition for you, so there is no `plugin_file` field to write. The method returns `true` when the definition is accepted and `false` when it is not (see [When validation fails](#when-validation-fails)).

## Fields

| Field | Type | Required | Meaning | Example |
|---|---|---|---|---|
| `plugin_id` | string | yes | The plugin's stable identifier. It must equal the ID you pass to `parent::__construct()`, and it must be unique among the plugins on a site. | `'my-carrier'` |
| `download_id` | positive integer | yes | The plugin's product id in the woodev.ru store, unique per plugin. Write it as an integer literal. | `1234` |
| `plugin_name` | string | yes | The display name. | `'My Carrier'` |
| `plugin_version` | string | yes | The plugin's own version. | `'1.0.0'` |
| `framework_version` | string | yes | The version of the framework copy you bundle. | `'%%FRAMEWORK_VERSION%%'` |
| `backwards_compatible` | string | required in practice | The oldest framework version your bundled copy can still serve. Not checked by the validator, but always set it — see below. | `'2.0.0'` |
| `platform` | string | yes | `'wordpress'` or `'woocommerce'`. | `'woocommerce'` |
| `requirements` | array | yes | Minimum versions: `php`, `wordpress`, and `woocommerce` for a WooCommerce plugin. | `[ 'php' => '7.4', ... ]` |
| `main_class` | string | one of `main_class` or `callback` | The class the loader builds. | `'My_Carrier_Plugin'` |
| `callback` | callable | one of `main_class` or `callback` | A function the loader calls instead of building the class itself. | `'my_carrier_start'` |
| `supported_features` | array | no | Declares HPOS and Cart/Checkout Blocks support. | `[ 'hpos' => true ]` |
| `type` | string | no | Only `'shipping'` is accepted. | `'shipping'` |

Fields that are not listed here are ignored.

## Literals only

The definition may contain **literals and your own constants and functions, nothing else**. Never use a framework class constant, not even one that looks harmless:

```php
<?php
// Wrong: the framework is not loaded yet, so this is a fatal error.
$wrong = [ 'platform' => \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE ];

// Right.
$right = [ 'platform' => 'woocommerce' ];
```

PHP builds the array before `register()` has loaded the framework, so the framework class does not exist yet. The result is a `Class not found` fatal that stops the site, not a friendly notice.

:::caution
A class constant from the framework in the loader definition is a fatal error. Write the string value instead.
:::

## `plugin_id`

The ID is lowercase letters, digits and dashes, such as `my-carrier`. The framework derives option names and hook names from it, so **never change it after you release**: a changed ID orphans the data that merchants already saved. The ID must equal the first argument of your `parent::__construct()` call. [Plugin layout & conventions](../plugin-layout-and-conventions/#naming) lists the helpers that give you the dashed and underscored forms.

If two plugins on one site register the same `plugin_id`, the second registration is rejected as an invalid definition.

## `download_id`

The plugin's product id in the woodev.ru store: a positive integer, unique per plugin.

## `framework_version` and `backwards_compatible`

`framework_version` is the version of the framework copy inside your plugin. Use the version of the `woodev/` folder you actually bundle. When several plugins run on one site, the newest copy serves all of them (see [Introduction](../introduction/#several-plugins-on-one-site)).

`backwards_compatible` is the oldest framework version that **your bundled copy** can still serve. When your copy is the newest on the site, every plugin whose own `framework_version` is below this value is not started, and the merchant sees a notice asking them to update that plugin. Use `'2.0.0'` for the 2.x line.

Always set it. The field is not validated, but without it a plugin that is too old is never turned away, and it would then run against a framework it cannot work with.

## `requirements`

The minimum versions your plugin needs:

```php
'requirements' => [
	'php'         => '7.4',
	'wordpress'   => '6.6',
	'woocommerce' => '7.0',
],
```

`php` and `wordpress` are always required. `woocommerce` is also required when `platform` is `'woocommerce'`. Write the values as strings.

A plugin that fails a requirement is not started. The merchant sees an admin notice such as «My Carrier requires WooCommerce 7.0 or newer», and the rest of the site keeps working. If WooCommerce is not active at all, a WooCommerce plugin does not start either.

Do not set a requirement below what the framework itself needs; see [Requirements & bundling](../requirements-and-bundling/#platform-requirements).

## `supported_features`

This is the one place to declare HPOS and Cart/Checkout Blocks support:

```php
'supported_features' => [
	'hpos'   => true,
	'blocks' => [
		'cart'     => true,
		'checkout' => true,
	],
],
```

| Key | Default | Meaning |
|---|---|---|
| `hpos` | `false` | Your plugin works with High-Performance Order Storage. |
| `blocks.cart` | `false` | Your plugin works with the Cart block. |
| `blocks.checkout` | `false` | Your plugin works with the Checkout block. |

What the framework does with them:

- It tells WooCommerce early, before WooCommerce finishes starting, so merchants do not see an incompatibility warning on the Plugins screen. HPOS is declared compatible only when you set `hpos` to `true` **and** WooCommerce is 7.6 or newer. Cart/Checkout Blocks are declared compatible only when **both** `blocks.cart` and `blocks.checkout` are `true`.
- It warns merchants in wp-admin when their Cart or Checkout page uses a block that your declaration does not cover.
- `\Woodev\Framework\Woocommerce_Plugin` reads the same values back: `get_supported_features()` returns the array, and `is_hpos_compatible()` answers the HPOS question.

Declare a feature only if your code really supports it. Declaring `hpos` and then reading orders through `get_post_meta()` breaks on stores that use HPOS; use the `WC_Order` object instead.

:::caution
Declare these features in the loader definition, never in the plugin constructor. WooCommerce reads the declaration before your class is built. If the constructor disagrees with the definition, the definition wins and WordPress logs a developer notice.
:::

For the full story on HPOS and Blocks, see [HPOS & Blocks compatibility](../../core/hpos-and-blocks-compatibility/).

## `type`

`type` is optional. The only accepted value is `'shipping'`, which marks a shipping plugin and flips one default: `blocks.cart` and `blocks.checkout` default to `true`. A shipping plugin should also use `'platform' => 'woocommerce'`.

`type` does not decide what your plugin is. Your base class does: a shipping plugin extends `\Woodev\Framework\Shipping\Shipping_Plugin`. Any other value of `type` makes the definition invalid.

## `main_class` and `callback`

You must give at least one of them.

- **`main_class` alone.** The loader checks that the class exists, then calls its static `instance()` method if it has one, and otherwise builds it with `new`. Use this when your classes load through an autoloader.
- **`callback`.** The loader calls it instead of building the class, so the callback must build the plugin itself. Use it when you load your class files by hand, as [Your first plugin](../your-first-plugin/) does.

When you use a callback, keep `main_class` set too. The framework uses it to match the running plugin object to its definition.

The callback must be callable when `register()` runs. A function declared at the top level of the main file qualifies, wherever in the file it sits.

When there is no callback and the loader builds the plugin from `main_class`, a class that does not exist makes the definition invalid, and the plugin does not start. With a callback, building the plugin is the callback's job.

## When validation fails

The framework checks the definition when you call `register()`. A problem does not crash the site:

- `register()` returns `false`.
- The plugin does not start.
- In wp-admin, users who can activate plugins see a red notice: «Плагин «My Carrier» не запущен: он собран с ошибкой. Обратитесь к автору плагина.» (Plugin “My Carrier” did not start: it was built with an error. Contact the plugin's author.)
- The technical reasons go to the PHP error log, one line per plugin, written when a user who can activate plugins opens a wp-admin screen. The line starts with `[woodev] Plugin "My Carrier" was not loaded, invalid loader definition:`. Turn on `WP_DEBUG_LOG` to find them in `wp-content/debug.log`.

The messages you can meet in the log:

| Message | Cause |
|---|---|
| `Missing required loader definition field: <field>.` | A required field is absent or empty. |
| `Loader definition download_id must be a positive integer ...` | `download_id` is not a number greater than zero. |
| `Loader definition requires at least one of main_class or callback.` | You gave neither. |
| `Loader definition callback must be callable.` | The function does not exist when `register()` runs. |
| `Unsupported loader definition platform: <value>.` | `platform` is not `'wordpress'` or `'woocommerce'`. |
| `Loader definition supported_features must be an array.` | `supported_features` is not an array. |
| `Loader definition type must be the string "shipping" when provided.` | `type` has another value. |
| `Missing required loader requirement: <php or wordpress>.` | `requirements` lacks `php` or `wordpress`. |
| `WooCommerce loader definitions require a woocommerce requirement.` | A WooCommerce plugin has no `requirements['woocommerce']`. |
| `Duplicate plugin_id: <id>.` | Another plugin already registered this ID. |
| `Loader definition main_class does not exist: <class>.` | The class is missing when the loader tries to build it. |

A fatal `Class not found` error, rather than a notice, almost always means the definition used a framework constant; see [Literals only](#literals-only).
