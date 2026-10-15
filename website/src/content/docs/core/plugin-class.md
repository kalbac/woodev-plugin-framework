---
title: The plugin class
description: Learn how to extend the base plugin class and what it provides out of the box.
---

In this page you will learn which base class to extend, what the constructor takes, which methods you must write, and which parts of the plugin you can replace with your own.

Covers framework release `%%FRAMEWORK_VERSION%%`.

## Pick a base class

The type of a plugin is the class it extends. There is no flag to set.

| Your plugin | Extend | `platform` in the loader definition |
|---|---|---|
| Pure WordPress, no WooCommerce code | `Woodev_Plugin` | `'wordpress'` |
| WooCommerce | `\Woodev\Framework\Woocommerce_Plugin` | `'woocommerce'` |
| Shipping carrier | `\Woodev\Framework\Shipping\Shipping_Plugin` | `'woocommerce'` |
| Payment gateway | `Woodev_Payment_Gateway_Plugin` | `'woocommerce'` |

Each class in the lower rows extends the one above it, so a shipping plugin has everything a WooCommerce plugin has, plus carriers, rates and shipments. Pick the most specific class that fits and you inherit its subsystems.

`Woodev_Plugin` itself knows nothing about WooCommerce. The WooCommerce logger, the HPOS declaration and the Cart/Checkout Blocks handler all come from `Woocommerce_Plugin`.

The shipping and payment classes have their own required methods and constructor arguments; see [Shipping › Plugin anatomy](../../shipping/plugin-anatomy/) and [Payment gateways](../../payments/payment-gateways/). The rest of this page applies to all four.

## The smallest plugin class

A concrete plugin class does four things:

1. It extends one of the base classes above.
2. Its constructor calls `parent::__construct()`.
3. It implements `get_file()`.
4. It implements `get_plugin_name()`.

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The My Carrier plugin.
 */
final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	/**
	 * The single instance.
	 *
	 * @var My_Carrier_Plugin|null
	 */
	protected static $instance;

	/**
	 * Sets up the plugin.
	 */
	public function __construct() {
		parent::__construct(
			'my-carrier',
			MY_CARRIER_VERSION,
			[
				'text_domain'  => 'my-carrier',
				'dependencies' => [
					'php_extensions' => [ 'mbstring' ],
				],
			]
		);
	}

	/**
	 * Gets the single instance.
	 *
	 * @return My_Carrier_Plugin
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Gets the full path of the plugin's entry file.
	 *
	 * @return string
	 */
	protected function get_file(): string {
		return MY_CARRIER_FILE;
	}

	/**
	 * Gets the plugin's display name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return 'My Carrier';
	}
}
```

`MY_CARRIER_FILE` and `MY_CARRIER_VERSION` are the constants that the entry file defines in [Your first plugin](../../getting-started/your-first-plugin/).

### Constructor arguments

`parent::__construct( string $id, string $version, array $args = [] )` takes:

| Argument | Meaning |
|---|---|
| `$id` | The plugin ID. It **must equal** the `plugin_id` in the loader definition. |
| `$version` | The plugin's own version, usually a constant. |
| `$args['text_domain']` | Your text domain. The framework loads your translations from it. See [Internationalisation](../internationalisation/). |
| `$args['dependencies']` | PHP extensions, functions and settings your plugin needs. See [Dependencies & environment checks](../dependencies-and-environment-checks/). |

A WooCommerce plugin also accepts `supported_features`, but do not use it: HPOS and Blocks support is declared in the loader definition, which always wins. See [HPOS & Blocks compatibility](../hpos-and-blocks-compatibility/).

### The two required methods

- `get_file()` returns the full path of the plugin's **entry file**, not the class file. The framework derives the plugin folder, the plugin URL and the row on the Plugins screen from it. When the class lives in `includes/`, return a constant that the entry file defines.
- `get_plugin_name()` returns the display name shown in notices and admin pages.

### The `instance()` accessor

Declare `protected static $instance` and a public static `instance()`, as above. The loader calls `instance()` when the class has one and falls back to `new` otherwise. Without an accessor, other code has no way to reach your plugin, and a second `new` builds every subsystem twice.

Cloning and unserialising a plugin object are blocked with a developer notice.

## Run your code at the right time

Your constructor runs while the loader starts your plugin on `plugins_loaded`. WooCommerce classes, such as `WC_Order`, may not be ready yet. Put wiring that needs WordPress or WooCommerce in one of two methods that WordPress calls for you:

| Method | Runs on | Use it for |
|---|---|---|
| `init_plugin()` | `plugins_loaded`, priority 15 | Hooks and handlers that need WordPress and WooCommerce. |
| `init_admin()` | `admin_init`, priority 0 (wp-admin only) | Admin-only wiring. |

Both are public and empty in the base class. [A fuller example](#a-fuller-example) below registers one hook in each.

The framework registers its own hooks for you: the two above, script loading, admin notices and the plugin-row links. Do not register those again.

:::note
A hook registered as `[ $this, 'method' ]` from an object that every Woodev plugin builds fires once **per plugin**. If a hook is meant for the whole site, such as one that the framework itself registers, use a static callback.
:::

## What the constructor builds

`parent::__construct()` builds these in order, and each is reachable through a getter afterwards:

| Subsystem | Getter | Replace it by overriding |
|---|---|---|
| Dependency checks | `get_dependency_handler()` | `$args['dependencies']` |
| Admin message handler | `get_message_handler()` | `init_admin_message_handler()` |
| Admin notice handler | `get_admin_notice_handler()` | Not replaceable |
| Licence handling | n/a | Handled by the framework |
| Deprecated-hook notices | n/a | `get_deprecated_hooks()` |
| Lifecycle handler | `get_lifecycle_handler()` | `init_lifecycle_handler()` |
| Translation handler | n/a | `init_translation_handler()` |
| Cron handler | n/a | `init_cron_handler()` |
| REST API handler | n/a | `init_rest_api_handler()` |
| Setup wizard (optional) | `get_setup_wizard_handler()` | `build_setup_wizard_handler()` |
| Settings page registration | n/a | `get_settings_providers()` |
| Blocks handler (WooCommerce plugins) | `get_blocks_handler()` | `init_blocks_handler()` |

The framework also adds the shared «Woodev» menu once for the whole site, whichever plugin builds it first.

## Override points

Override only what you need. All `init_*` methods are `protected`; keep that visibility.

:::caution
An `init_*` override **replaces** the default, it does not extend it. If you override `init_lifecycle_handler()` and forget to assign `$this->lifecycle_handler`, you have no lifecycle handler. For the lifecycle handler the framework notices, raises a developer notice under `WP_DEBUG` and builds the default one for you, but do not rely on that. Call `parent::init_…()` first when you only want to add to the default.
:::

| To customise | Override | Notes |
|---|---|---|
| Install and upgrade routines | `init_lifecycle_handler()` | Assign `$this->lifecycle_handler`. See [Lifecycle & upgrades](../lifecycle-and-upgrades/). |
| Settings tabs | `get_settings_providers()` | Public. Return `Settings_Provider` objects. See [Settings overview](../../settings/overview/). |
| An onboarding wizard | `build_setup_wizard_handler()` | Return your `Setup_Wizard` subclass, or `null` for no wizard. See [Setup wizard](../../settings/setup-wizard/). |
| REST routes | `init_rest_api_handler()` | Assign `$this->rest_api_handler` a subclass of `Woodev_REST_API`. See [REST endpoints](../../settings/rest-endpoints/). |
| Renamed or removed hooks | `get_deprecated_hooks()` | See [Migrating from v1](../migrating-from-v1/#renamed-hooks). |
| The "not configured" warning | `is_configured()` | See [Dependencies & environment checks](../dependencies-and-environment-checks/#the-not-configured-notice). |
| Cart/Checkout Blocks integration | `init_blocks_handler()` | Assign `$this->blocks_handler` a subclass of `Woodev_Blocks_Handler`. See [HPOS & Blocks compatibility](../hpos-and-blocks-compatibility/). |
| A plugin sold without a licence key | `is_need_license()` | Return `false`. |

Do not override `get_download_id()`: it reads the loader definition.

### Links on the Plugins screen

The framework adds action links to the plugin's row on the Plugins screen, in this order: «Настройки» (Settings), «Документация» (Documentation), «Поддержка» (Support), «Оставить отзыв» (Leave a review) and a licence link. A link appears only when the plugin returns a value for it:

| Method | Returns | Link |
|---|---|---|
| `get_settings_url()` | A URL string. The default is `''`. | «Настройки» |
| `get_documentation_url()` | A URL string or `null` (the default). | «Документация» |
| `get_support_url()` | A URL string or `null` (the default). | «Поддержка» |
| `get_sales_page_url()` | A URL string. The default is `''`. | The review link is derived from it. |

The review link is built from the sales page URL, so a plugin with no sales page URL has no review link.

A plugin that keeps its settings on the shared «Woodev → Настройки» (Settings) page points `get_settings_url()` at its own tab. The tab ID is the ID of your settings provider.

### A fuller example

This class is complete. It adds the overrides that most plugins need. `My_Carrier_Lifecycle` is the lifecycle class from [Lifecycle & upgrades](../lifecycle-and-upgrades/).

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The My Carrier plugin.
 */
final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	/**
	 * The single instance.
	 *
	 * @var My_Carrier_Plugin|null
	 */
	protected static $instance;

	/**
	 * Sets up the plugin.
	 */
	public function __construct() {
		parent::__construct(
			'my-carrier',
			MY_CARRIER_VERSION,
			[
				'text_domain' => 'my-carrier',
			]
		);
	}

	/**
	 * Gets the single instance.
	 *
	 * @return My_Carrier_Plugin
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Gets the full path of the plugin's entry file.
	 *
	 * @return string
	 */
	protected function get_file(): string {
		return MY_CARRIER_FILE;
	}

	/**
	 * Gets the plugin's display name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return 'My Carrier';
	}

	/**
	 * Registers hooks once WordPress and WooCommerce are ready.
	 *
	 * @return void
	 */
	public function init_plugin(): void {
		add_action( 'woocommerce_new_order', [ $this, 'handle_new_order' ] );
	}

	/**
	 * Registers wp-admin-only hooks.
	 *
	 * @return void
	 */
	public function init_admin(): void {
		add_action( 'admin_notices', [ $this, 'handle_admin_notices' ] );
	}

	/**
	 * Adds an order note to a new order.
	 *
	 * @param int $order_id The new order's ID.
	 * @return void
	 */
	public function handle_new_order( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			$order->add_order_note( __( 'Order received by My Carrier.', 'my-carrier' ) );
		}
	}

	/**
	 * Prints a notice in wp-admin.
	 *
	 * @return void
	 */
	public function handle_admin_notices(): void {
		echo '<div class="notice notice-info"><p>' . esc_html__( 'My Carrier is active.', 'my-carrier' ) . '</p></div>';
	}

	/**
	 * Builds the lifecycle handler that runs install and upgrade routines.
	 *
	 * @return void
	 */
	protected function init_lifecycle_handler() {
		$this->lifecycle_handler = new My_Carrier_Lifecycle( $this );
	}

	/**
	 * Tells the framework whether the plugin has what it needs to work.
	 *
	 * While this returns false, wp-admin shows a "not configured" warning.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== (string) get_option( 'my_carrier_api_key', '' );
	}

	/**
	 * Gets the URL of the plugin's settings tab.
	 *
	 * @param string|null $plugin_id Unused. Present for compatibility with the base signature.
	 * @return string
	 */
	public function get_settings_url( $plugin_id = null ): string {
		return admin_url( 'admin.php?page=woodev-settings&tab=my-carrier' );
	}

	/**
	 * Gets the documentation URL for the Plugins screen.
	 *
	 * @return string
	 */
	public function get_documentation_url() {
		return 'https://example.com/docs/my-carrier/';
	}

	/**
	 * Gets the support URL for the Plugins screen.
	 *
	 * @return string
	 */
	public function get_support_url() {
		return 'https://example.com/support/';
	}
}
```

## Helpers on the plugin object

Every plugin object has these getters.

| Getter | Returns |
|---|---|
| `get_id()` | The plugin ID as written, such as `my-carrier`. |
| `get_id_dasherized()` | The ID with dashes: `my-carrier`. Use it for CSS classes, handles and element IDs. |
| `get_id_underscored()` | The ID with underscores: `my_carrier`. Use it for PHP identifiers and option keys. |
| `get_version()` | The plugin version you passed to the constructor. |
| `get_textdomain()` | The text domain you passed to the constructor. |
| `get_plugin_path()` | The plugin folder, without a trailing slash. |
| `get_plugin_url()` | The plugin folder URL, without a trailing slash. |
| `get_template_path()` | The `templates/` folder inside the plugin folder. |
| `get_plugin_option_name( $key )` | `woodev_{id_underscored}_{key}`, such as `woodev_my_carrier_api_key`. |
| `get_plugin_version_name()` | The option that stores the installed version: `woodev_{id}_version`, with the ID as written. |
| `get_supported_features()` | The HPOS and Blocks flags from the loader definition. |
| `get_assets_suffix()` | `''` when `SCRIPT_DEBUG` is on, `'.min'` otherwise. Static. |
| `get_assets_version()` | The plugin version, or the current time while `SCRIPT_DEBUG` or `WP_DEBUG` is on, so browsers do not cache your assets during development. |
| `load_template( $template, $args )` | Loads a template through `wc_get_template()` in a WooCommerce plugin, defaulting to your `templates/` folder. |

The two ID forms matter: the version option uses the ID **as written** (`woodev_my-carrier_version`), while `get_plugin_option_name()` uses the underscored form (`woodev_my_carrier_…`). Check which one you need before you store a value.

### Loading your own assets

If you ship each script as `name.js` with a minified `name.min.js` next to it, ask the plugin for the suffix instead of spelling `.min`:

```php
<?php
wp_enqueue_script(
	'my-carrier-admin',
	$this->get_plugin_url() . '/assets/js/admin' . self::get_assets_suffix() . '.js',
	[ 'jquery' ],
	$this->get_assets_version(),
	true
);
```

`SCRIPT_DEBUG` alone switches to the readable file, like WooCommerce. Ship both files and make your build write the minified one. Put this call in a method of your plugin class, hooked to `wp_enqueue_scripts` or `admin_enqueue_scripts`.

### Loading a template

In a WooCommerce plugin, `load_template()` is a thin wrapper around `wc_get_template()` with your `templates/` folder as the default path. The template file lives at `my-carrier/templates/tracking-link.php` in your plugin, and a theme can override it:

```php
<?php
$this->load_template(
	'tracking-link.php',
	[ 'tracking_number' => $tracking_number ],
	'my-carrier/'
);
```

The third argument is the folder inside the theme that holds overrides. In a pure `Woodev_Plugin`, `load_template()` is an empty method that you can override.

## Next steps

- [Lifecycle & upgrades](../lifecycle-and-upgrades/) shows the handler that `init_lifecycle_handler()` returns.
- [Dependencies & environment checks](../dependencies-and-environment-checks/) covers the `dependencies` argument and `is_configured()`.
- [HPOS & Blocks compatibility](../hpos-and-blocks-compatibility/) covers the features declared in the loader definition.
