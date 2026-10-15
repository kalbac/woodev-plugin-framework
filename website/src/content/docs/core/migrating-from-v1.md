---
title: Migrating from v1
description: Learn what changes when you move an existing plugin to framework v2, and how to keep every merchant's stored data intact.
---

In this page you will learn what changes in your code when an existing plugin moves from framework v1 to v2, which stored data must stay exactly as it is, and how to migrate the rest safely in an upgrade routine.

Covers framework release `%%FRAMEWORK_VERSION%%`.

## What changes for a plugin author

These are the changes you make in code:

| Area | In v2 |
|---|---|
| Registration | Call `Woodev_Loader::register( __FILE__, [ … ] )` with a [loader definition](../../getting-started/loader-definition-reference/), in place of the positional v1 call `register_plugin( $framework_version, $plugin_name, $path, $callback, $args )`. |
| Features | Declare HPOS and Cart/Checkout Blocks support in the loader definition's `supported_features`, and nowhere else. See [HPOS & Blocks compatibility](../hpos-and-blocks-compatibility/). |
| Base class | A WooCommerce plugin extends `\Woodev\Framework\Woocommerce_Plugin`. `Woodev_Plugin` is platform-neutral. See [The plugin class](../plugin-class/). |
| Requirements | Declare the minimum PHP, WordPress and WooCommerce versions in `requirements` in the loader definition. See [Dependencies & environment checks](../dependencies-and-environment-checks/). |
| Settings | Contribute a tab to the framework's shared page, «Woodev → Настройки» (Settings), through `get_settings_providers()`. See [Settings overview](../../settings/overview/). |
| Shipping classes | Use the classes in the `\Woodev\Framework\Shipping` namespace, such as `Shipping_Plugin` and `Shipping_Method`. See [Shipping › Plugin anatomy](../../shipping/plugin-anatomy/). |
| Install and upgrade | Subclass `Woodev_Lifecycle`. See [Lifecycle & upgrades](../lifecycle-and-upgrades/). |

A plugin that still calls `register_plugin()` is not started by a v2 framework, and the merchant sees a notice that names the plugin to update. Move the registration first; everything else can follow.

On the v2 line, the framework's internal API can change between releases. Bundle a fixed framework version, and read the release notes before you bump it. What can **never** change is the data that is stored on a merchant's site.

## Data on installed sites must survive

A merchant who updates your plugin keeps the same options, orders, shipping zones, scheduled events and customisations. Your rewrite must read all of it exactly as before. Rename classes, methods and files as much as you like in code. Never rename anything that has been written to the database or published to the outside world.

| What | Why it must not change |
|---|---|
| Option keys and the shape of settings arrays | Saved settings would silently reset to defaults. |
| Shipping method IDs and instance setting keys | WooCommerce stores shipping zones by method ID. A new ID empties every zone that used the old one. |
| Payment gateway IDs | Saved gateway settings, orders and tokens refer to the ID. |
| Order, order-item and session meta keys | Existing orders would lose their shipment data. |
| Public hook names | The merchant's own code, and other plugins, hook into them. |
| Cron hook names, schedules and payload shape | Events that are already scheduled would never run, or run with the wrong data. |
| REST namespaces and routes, AJAX action names | Saved integrations and the plugin's own scripts call them. |
| Admin page slugs | Bookmarks and links in emails would break. |
| Custom database tables and their columns | The rows already exist. |
| Log source names, background job IDs, email IDs | They identify existing records and templates. |

### Build the inventory from the old code

Before you write a line of v2, scan the v1 plugin for everything it persists or publishes, and keep the result as a checklist in your repository:

```bash
grep -rnE "get_option|update_option|add_option|apply_filters|do_action|register_rest_route|wp_schedule_|wp_ajax_|add_menu_page|add_submenu_page|update_meta_data|update_post_meta|add_meta_box" includes/
```

For every hit, decide one of two things: the v2 plugin uses the same name, or the v2 plugin reads the old name once and moves the data in a migration. Check the list again before each release.

## The version option and a live site

The framework decides between install and upgrade with the `woodev_{id}_version` option, as described in [Lifecycle & upgrades](../lifecycle-and-upgrades/). A v1 plugin kept its version somewhere else. On the first v2 load, the framework finds no version and runs `install()` on a site that already has data.

There are two safe answers. Either make `install()` harmless over existing data (use `add_option()`, never `update_option()`), or hand the old version to the framework first. This lifecycle class does the second, then migrates in an upgrade routine:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Runs My Carrier's routines, including the move from the v1 plugin.
 */
class My_Carrier_Lifecycle extends Woodev_Lifecycle {

	/**
	 * The versions that have an upgrade routine, in ascending order.
	 *
	 * @var string[]
	 */
	protected $upgrade_versions = [ '2.0.0' ];

	/**
	 * Treats a site that has v1 data as an upgrade, not a fresh install.
	 *
	 * @return void
	 */
	public function init() {
		if ( '' === $this->get_installed_version() ) {
			$v1_version = get_option( 'my_carrier_v1_version', '' );

			if ( is_string( $v1_version ) && '' !== $v1_version ) {
				$this->set_installed_version( $v1_version );
			}
		}

		parent::init();
	}

	/**
	 * Moves the v1 settings to the v2 settings array.
	 *
	 * @param string $installed_version The version that was installed before the update.
	 * @return void
	 */
	protected function upgrade_to_2_0_0( string $installed_version ): void {
		// A flag of its own makes the routine safe to run twice.
		if ( 'yes' === get_option( 'my_carrier_migrated_to_2_0_0' ) ) {
			return;
		}

		$old = get_option( 'woocommerce_my_carrier_settings', null );

		// Copy, never move: the old option stays where it is.
		if ( is_array( $old ) && false === get_option( 'my_carrier_settings' ) ) {
			add_option(
				'my_carrier_settings',
				[
					'api_key' => (string) ( $old['token'] ?? '' ),
					'mode'    => (string) ( $old['mode'] ?? 'test' ),
				]
			);
		}

		update_option( 'my_carrier_migrated_to_2_0_0', 'yes' );
	}
}
```

Wire it into the plugin with `init_lifecycle_handler()`, as shown in [Lifecycle & upgrades](../lifecycle-and-upgrades/#wire-up-your-lifecycle-class).

The rules behind this example:

- **Copy, do not move.** Leave the old value in place. A merchant who rolls back the plugin keeps working settings.
- **Guard the routine with its own flag.** The flag is also a key that must never be renamed afterwards.
- **Idempotent writes.** `add_option()` writes only when nothing is stored yet, so a second run cannot overwrite what the merchant changed after the first.
- **The framework stores the version only after the routine returns.** If the routine throws, it runs again on the next admin page load, so a failed migration is retried and not lost.

:::caution
Decide whether to migrate from the **data**, not only from the version. If the v1 plugin used the same plugin ID as the v2 plugin, a site that ran any earlier v2 build is already stamped with a v2 version, and `upgrade_to_2_0_0()` never runs for it. In that case call your migration method from `init()` before `parent::init()` whenever v1 data exists and the migration flag is not set. Because the method is idempotent, calling it from both places is safe.
:::

## Settings

The settings in the example above stay in the option the plugin uses. A settings tab on the shared page can also name the old option to import from, and the old admin page to redirect from, so merchants who bookmarked the old screen land on the new one. Those two arguments are `legacy_option_key` and `legacy_page`; see [Tabs, sections & cards](../../settings/tabs-sections-and-cards/).

## Renamed hooks

When v2 no longer fires a hook that merchants' code may use, declare it instead of deleting it silently. Override `get_deprecated_hooks()` in the plugin class and list the old name with its replacement:

```php
<?php
defined( 'ABSPATH' ) || exit;

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
	 * Lists the hooks that v2 renamed or removed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_deprecated_hooks() {
		return [
			// Renamed, and old callbacks keep working through the new hook.
			'wc_my_carrier_rate_label'   => [
				'version'     => '2.0.0',
				'removed'     => true,
				'replacement' => 'my_carrier_rate_label',
				'map'         => true,
			],
			// Removed with no replacement.
			'wc_my_carrier_legacy_debug' => [
				'version'     => '2.0.0',
				'removed'     => true,
				'replacement' => false,
			],
		];
	}
}
```

Each entry is keyed by the **old** hook name:

| Key | Meaning |
|---|---|
| `version` | The plugin version that deprecated or removed the hook. |
| `removed` | `true` if the hook no longer fires, `false` if it still does and is only discouraged. |
| `replacement` | The new hook name, or `false` if there is none. |
| `map` | `true` to pass values through callbacks that are still attached to the old name whenever the new hook runs. It needs `removed` and a `replacement`. |

What the merchant's developer sees: while `WP_DEBUG` is on, a notice such as `My Carrier: action/filter "wc_my_carrier_rate_label" was removed in version 2.0.0. Use my_carrier_rate_label instead.` appears for every listed hook that some code has attached a callback to. Return `false` from the `woodev_plugin_framework_show_deprecated_hook_notices` filter to silence them.

List what shop code really used, not every internal hook. The more entries you list, the more there is to keep correct. The framework builds this list while the plugin constructor runs, so do not read properties that your own constructor sets after `parent::__construct()`.

## Before you ship the migrated plugin

- Every item of your inventory is either unchanged or migrated by an idempotent routine.
- The plugin ID and `download_id` are the ones installed sites already have. The ID derives the framework's own option and hook names, and the framework handles licences and updates for the same product.
- The version seeding works: activate the new build on a copy of a real v1 site and check that `install()` did not run (the `woodev_{id}_lifecycle_events` option records `upgrade`, not `install`).
- The migration runs twice with the same result. Run it twice on that copy and compare the options.
- Load every class you subclass against the framework version you bundle. PHP reports a mismatched method signature when the class is declared, which takes the whole site down, so test in a real WordPress and not only with mocked unit tests.
