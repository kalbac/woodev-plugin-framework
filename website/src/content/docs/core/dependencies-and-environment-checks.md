---
title: Dependencies & environment checks
description: Learn how to declare the PHP extensions, functions and settings your plugin needs, and how the "not configured" warning works.
---

In this page you will learn how to tell the framework what your plugin needs from the server, what the merchant sees when something is missing, and how a plugin reports that it still needs to be configured.

Covers framework release `%%FRAMEWORK_VERSION%%`.

## Two layers of checks

A plugin is checked in two places. They answer different questions, so declare each requirement in the right one.

| | `requirements` | `dependencies` |
|---|---|---|
| Declared in | The loader definition, in the entry file. | The `dependencies` argument of `parent::__construct()`. |
| Checks | The minimum PHP, WordPress and WooCommerce **versions**. | PHP **extensions**, **functions** and **settings** (`php.ini` values). |
| Runs | Before your plugin class is built. | After your plugin starts, and shows its notices on wp-admin screens. |
| If unmet | The plugin does not start. wp-admin shows a notice, such as «My Carrier requires WooCommerce 7.0 or newer». | The plugin **keeps running**. wp-admin shows a notice. |

Use `requirements` for anything whose absence would make the plugin unsafe to run, which is a version gap. Use `dependencies` for a feature that can be switched off when the server lacks something. See the [Loader definition reference](../../getting-started/loader-definition-reference/#requirements) for the `requirements` field.

Because a `dependencies` check never stops the plugin, your code decides what to do when something is missing. [Degrade gracefully](#degrade-gracefully) below shows how.

## Declare dependencies

Pass a `dependencies` array in the constructor's third argument. All three keys are optional:

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
					'php_extensions' => [ 'mbstring', 'curl' ],
					'php_functions'  => [ 'iconv' ],
					'php_settings'   => [
						'max_input_vars' => 1000,
					],
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

| Key | Value | Check |
|---|---|---|
| `php_extensions` | A list of extension names. | `extension_loaded()` for each. |
| `php_functions` | A list of function names. | `function_exists()` for each. |
| `php_settings` | A map of `php.ini` setting to expected value. | An **integer** is a minimum; any other value must match exactly. |

For an integer minimum, the framework understands shorthand sizes such as `128M` in the server's value, so write the expected value in bytes. WordPress defines `MB_IN_BYTES` for that.

Three limits to know:

- A setting whose current value is empty or `0` is skipped, so this check cannot catch a setting that is switched off.
- A `memory_limit` of `-1` (unlimited) is compared as the number `-1` and reported as too low. Do not declare `memory_limit`.
- Declaring any `php_settings` also checks the Suhosin limits that the framework knows about. They apply only on servers that run Suhosin.

### What the merchant sees

| Problem | Where | Message |
|---|---|---|
| Missing extension | Every wp-admin screen, as an error. | «My Carrier requires the **mbstring** PHP extension to function. Contact your host or server administrator to install and configure the missing extension.» |
| Missing function | Every wp-admin screen, as an error. | «My Carrier requires the **iconv** PHP function to exist. Contact your host or server administrator to install and configure the missing function.» |
| Setting below the expected value | WooCommerce → Settings screens only, as a warning. | A list of the settings and the values they need. |
| Setting below the expected value | WooCommerce → Status, in the environment table. | A row that shows what is expected. |

The messages are in English and are translated with the framework's text domain. When several are missing, one notice lists them all. Separately, the framework warns on any server that runs a PHP version older than 7.4.

## Degrade gracefully

Because the plugin keeps running, guard code that needs a dependency. The dependency handler answers the same questions the notices use:

| Method | Returns |
|---|---|
| `get_dependency_handler()->get_missing_php_extensions()` | The extensions that are not loaded. |
| `get_dependency_handler()->get_missing_php_functions()` | The functions that do not exist. |
| `get_dependency_handler()->get_incompatible_php_settings()` | The settings below the expected value, with the actual and the expected value. |

Use them in `init_plugin()` to keep a feature off:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The My Carrier plugin, with a feature that needs the mbstring extension.
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

	/**
	 * Registers the plugin's hooks, unless the server cannot run them.
	 *
	 * @return void
	 */
	public function init_plugin(): void {
		// The merchant already sees a notice that names the missing extension.
		if ( [] !== $this->get_dependency_handler()->get_missing_php_extensions() ) {
			return;
		}

		add_action( 'woocommerce_new_order', [ $this, 'handle_new_order' ] );
	}

	/**
	 * Adds the shortened customer name to the order note.
	 *
	 * @param int $order_id The new order's ID.
	 * @return void
	 */
	public function handle_new_order( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			$order->add_order_note( mb_substr( $order->get_formatted_billing_full_name(), 0, 40 ) );
		}
	}
}
```

Declaring `mbstring` only produces a notice. Code that calls `mb_substr()` still fails on a server without the extension, so either guard it, as above, or avoid optional extensions.

## The "not configured" notice

Some plugins do nothing until the merchant enters a key or picks a mode. A plugin tells the framework about that with one method, `is_configured()`. It returns `true` by default, so a plugin that needs no setup never warns.

While it returns `false`, wp-admin shows this warning:

> Плагин **My Carrier** не настроен. Перейдите в настройки », чтобы плагин начал работать.
>
> (Plugin My Carrier is not configured. Go to the settings » to make the plugin start working.)

The notice appears in wp-admin, even before the merchant has enabled anything, so a fresh install is told what to do. It cannot be dismissed: it disappears by itself on the first page load after `is_configured()` starts to return `true`, for example after the merchant saves the settings.

Override `is_configured()` and answer from your own settings:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The My Carrier plugin, with a "not configured" warning.
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
	 * Tells the framework whether the plugin has what it needs to work.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		$settings = get_option( 'my_carrier_settings', [] );

		return is_array( $settings ) && ! empty( $settings['api_key'] );
	}

	/**
	 * Gets the page that the warning links to.
	 *
	 * @return string
	 */
	protected function get_not_configured_notice_url(): string {
		return admin_url( 'admin.php?page=woodev-settings&tab=my-carrier' );
	}
}
```

How the notice is decided:

- **The link.** It goes to `get_settings_url()` by default. Override `get_not_configured_notice_url()` when your settings are somewhere else. If the URL is empty, the text appears without a link.
- **Who sees it.** Only users who can open the plugin's own settings, which is `manage_woocommerce` for a WooCommerce plugin and `manage_options` otherwise, unless your settings provider declares another capability.
- **Cost.** The framework asks on every wp-admin page, after a settings save of the same request. Keep `is_configured()` fast and free of remote calls.

A shipping plugin answers this question from its carrier's declared credentials, so you do not override it there. A payment gateway plugin keeps its own, richer notice for each gateway, and leaves `is_configured()` alone.

## Next steps

- [The plugin class](../plugin-class/) lists the other override points.
- [Admin notices & messages](../../settings/admin-notices-and-messages/) covers notices of your own.
