---
title: "Requirements & bundling"
description: Learn which PHP, WordPress and WooCommerce versions the framework needs and how to bundle it into your plugin.
---

In this page you will learn which platform versions the framework needs, how to put the framework into your plugin, how to update it, and what happens when other plugins bundle a different version.

## Platform requirements

| Component | Minimum | Why |
|---|---|---|
| PHP | 7.4 | The framework uses typed properties and `??=`. `composer.json` allows `>=7.4 <9.0`, and the unit suite runs on PHP 7.4 to 8.3. |
| WordPress | 6.6 | The framework's React admin screens load the `react-jsx-runtime` script, which core registers from WordPress 6.6. |
| WooCommerce | 7.0 | Only for plugins with `'platform' => 'woocommerce'`. HPOS support needs WooCommerce 7.6 or newer; on older versions the framework declares your plugin as not HPOS-compatible. |

A plugin may require **more** than this, never less. Your own minimums go into the `requirements` field of the loader definition; see [Loader definition reference](../loader-definition-reference/#requirements). When a site does not meet them, the plugin does not start and the merchant sees an admin notice that names the missing version. Nothing breaks.

## Get the framework

Each framework release publishes a ZIP on GitHub. It contains only the `woodev/` folder, including the compiled admin assets, so you can use it as it is.

```sh
curl -LO https://github.com/kalbac/woodev-plugin-framework/releases/download/v%%FRAMEWORK_VERSION%%/woodev-plugin-framework-%%FRAMEWORK_VERSION%%.zip
unzip -q woodev-plugin-framework-%%FRAMEWORK_VERSION%%.zip -d my-carrier/
```

The second command extracts the archive into your plugin folder, so you end up with `my-carrier/woodev/`. Always take `woodev/` from a release ZIP. A copy taken from a raw checkout of the repository has no compiled assets and is incomplete.

## Where the folder goes

Put `woodev/` in the root of your plugin, next to the main file:

```text
my-carrier/
├── my-carrier.php        main plugin file
└── woodev/               the framework, exactly as released
    ├── loader.php
    └── ...
```

Your main file loads the framework from there:

```php
<?php
require_once plugin_dir_path( __FILE__ ) . 'woodev/loader.php';
```

Treat `woodev/` as read-only. Never edit files inside it and never add your own files to it: the next update replaces the whole folder.

## No Composer at runtime

The framework needs no Composer autoloader and no `vendor/` folder in a shipped plugin. It loads its own classes from a generated class map. You do not list framework files and you do not `require` them. Your own files are your business; see [Plugin layout & conventions](../plugin-layout-and-conventions/#loading-your-own-files).

## Update the bundled copy

1. Download the ZIP of the new release, as shown above.
2. **Delete the old `woodev/` folder**, then extract the new one. Replacing the folder wholesale matters: files that the new release no longer ships would otherwise stay behind from the old one.
3. Set `framework_version` in your loader definition to the new version.
4. Change `backwards_compatible` only if you have a reason to; see [Loader definition reference](../loader-definition-reference/#framework_version-and-backwards_compatible).
5. Run your tests, then release your plugin.

## When other plugins bundle a different version

Merchants run several Woodev plugins, each with its own `woodev/` copy. On every request the framework picks the copy with the **highest** `framework_version` and serves every plugin from it. Three practical rules follow.

**Your code runs against the newest framework on the site.** Test with the version you bundle, and assume a newer one may be in charge.

**Declare the oldest framework you still support.** `backwards_compatible` is how the newest copy decides whether an older plugin may continue. A plugin that bundles a framework older than that value does not start; the merchant sees a notice asking them to update it.

**Guard any API newer than your `backwards_compatible`.** If you call a method that does not exist in every framework you declare as compatible, check for it first. `get_assets_suffix()`, for example, arrived in 2.0.2:

```php
<?php
$suffix = method_exists( 'Woodev_Plugin', 'get_assets_suffix' )
	? Woodev_Plugin::get_assets_suffix()
	: '.min';
```

Apply the same care to anything global that you declare yourself. Another plugin's copy of a helper function or class can already be loaded, so wrap such declarations in `function_exists()` or `class_exists( '...', false )`.

:::caution
A plugin must register itself while its main file is being included, at the top level of the file. A registration made after the `plugins_loaded` hook has run is silently ignored: no error appears and the plugin simply never starts. [Your first plugin](../your-first-plugin/) shows the right shape.
:::
