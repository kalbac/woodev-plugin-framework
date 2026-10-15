---
title: "Lifecycle & upgrades"
description: Learn the plugin boot sequence and how to run version-to-version upgrade routines safely.
---

In this page you will learn how to run code when your plugin is installed, updated, activated and deactivated, how to write upgrade routines that are safe to run twice, and which hooks the framework fires along the way.

Covers framework release `%%FRAMEWORK_VERSION%%`.

## How it works

The framework keeps the plugin's installed version in the database. On a wp-admin page load it compares that stored version with the version your plugin reports:

| Stored version | What the framework does |
|---|---|
| None | Runs `install()`, then fires `woodev_{plugin id}_installed`. |
| Lower than the plugin version | Runs `upgrade()`, then fires `woodev_{plugin id}_updated`. |
| The same, or higher | Does nothing. |

After `install()` or `upgrade()` it stores the plugin's current version. You write the routines; the framework decides when to call them.

The work is done by `Woodev_Lifecycle`. Every plugin gets a default one, and you subclass it to add your routines.

:::caution
Install and upgrade routines run only on **wp-admin page loads**, not on the storefront, in AJAX requests, in WP-Cron or in REST requests. After an update they wait for the first admin page load. (`activate()` is the exception: it runs on the first `admin_init` after activation, which can be an admin AJAX request.) Never write a feature that needs an upgrade routine to have run before the storefront works, and do not assume WP-CLI runs them.
:::

## Wire up your lifecycle class

Subclass `Woodev_Lifecycle`, then return your subclass from `init_lifecycle_handler()` in the plugin class:

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
	 * Builds the lifecycle handler that runs install and upgrade routines.
	 *
	 * @return void
	 */
	protected function init_lifecycle_handler() {
		$this->lifecycle_handler = new My_Carrier_Lifecycle( $this );
	}
}
```

This override **replaces** the default handler, so assign `$this->lifecycle_handler`. If you override the method and leave the property empty, the framework builds the default handler and raises a developer notice under `WP_DEBUG`. See [The plugin class](../plugin-class/#override-points).

Load the `My_Carrier_Lifecycle` class file the same way you load the plugin class, before the plugin is built.

## Write the routines

This lifecycle class is complete. It sets defaults on install, migrates a setting in one upgrade, schedules a cron event in another, and cleans up on deactivation.

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Runs My Carrier's install, upgrade, activation and deactivation routines.
 */
class My_Carrier_Lifecycle extends Woodev_Lifecycle {

	/**
	 * The versions that have an upgrade routine, in ascending order.
	 *
	 * @var string[]
	 */
	protected $upgrade_versions = [ '1.1.0', '1.2.0' ];

	/**
	 * Runs once, on the first admin_init request after the first activation.
	 *
	 * @return void
	 */
	protected function install() {
		// add_option() never overwrites a value that is already stored.
		add_option( 'my_carrier_mode', 'test' );
		add_option( 'my_carrier_debug', 'no' );
	}

	/**
	 * Upgrades a site that runs a version below 1.1.0.
	 *
	 * Version 1.1.0 renamed the "token" setting to "api_key". The routine copies the value
	 * and leaves the old one in place, so running it twice changes nothing.
	 *
	 * @param string $installed_version The version that was installed before the update.
	 * @return void
	 */
	protected function upgrade_to_1_1_0( string $installed_version ): void {
		$settings = get_option( 'my_carrier_settings', [] );

		if ( is_array( $settings ) && isset( $settings['token'] ) && ! isset( $settings['api_key'] ) ) {
			$settings['api_key'] = $settings['token'];
			update_option( 'my_carrier_settings', $settings );
		}
	}

	/**
	 * Upgrades a site that runs a version below 1.2.0.
	 *
	 * Version 1.2.0 added an hourly sync. Sites that were activated earlier never ran
	 * activate() for it, so the upgrade schedules it.
	 *
	 * @param string $installed_version The version that was installed before the update.
	 * @return void
	 */
	protected function upgrade_to_1_2_0( string $installed_version ): void {
		$this->schedule_sync();
	}

	/**
	 * Runs when the plugin is activated.
	 *
	 * @return void
	 */
	public function activate() {
		$this->schedule_sync();
	}

	/**
	 * Runs when the plugin is deactivated. The merchant's settings stay in place.
	 *
	 * @return void
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'my_carrier_sync' );
	}

	/**
	 * Schedules the hourly sync unless it is already scheduled.
	 *
	 * @return void
	 */
	private function schedule_sync(): void {
		if ( ! wp_next_scheduled( 'my_carrier_sync' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'my_carrier_sync' );
		}
	}
}
```

### `install()`

Put one-time setup here: default option values and anything else a brand-new site needs. It is `protected` and empty in the base class.

`install_default_settings( array $settings )` writes defaults for you. Each entry is `[ 'id' => 'option_name', 'default' => 'value' ]`:

```php
<?php
$this->install_default_settings(
	[
		[
			'id'      => 'my_carrier_mode',
			'default' => 'test',
		],
	]
);
```

It uses `update_option()`, so it **overwrites** a value that is already stored. If `install()` could ever run on a site that already has settings, use `add_option()` instead, as the example above does. See [Migrating from v1](../migrating-from-v1/#the-version-option-and-a-live-site).

### `upgrade()` and `upgrade_to_X_Y_Z()`

List the versions that need a routine in `$upgrade_versions`, in ascending order. For each entry, write a method named `upgrade_to_` plus the version with dots and dashes turned into underscores:

| Entry in `$upgrade_versions` | Method the framework calls |
|---|---|
| `'1.1.0'` | `upgrade_to_1_1_0( string $installed_version )` |
| `'2.4.0.0'` | `upgrade_to_2_4_0_0( string $installed_version )` |
| `'2.0.0-beta.1'` | `upgrade_to_2_0_0_beta_1( string $installed_version )` |

A routine runs when the installed version is **lower** than its entry. A site that updates from 1.0.0 to 1.2.0 runs both, in the order of the list. A brand-new site runs `install()` and no upgrade routine.

Two rules keep this predictable:

- Do not list a version higher than the version you are releasing. A routine for 1.3.0 would run early, on a 1.2.0 release.
- If a routine is missing for a listed version, the framework skips that entry without an error. Check the method name when a routine does not run.

Each routine is logged at debug level with its start and end.

### `activate()` and `deactivate()`

WordPress only accepts `register_activation_hook()` before `plugins_loaded`, which is too early for a framework plugin. The framework tracks activation instead, with an option, and calls your methods:

- `activate()` runs once on the first `admin_init` request after activation (possibly an admin AJAX request), before `install()` on a new site.
- `deactivate()` runs when the merchant deactivates the plugin.

Deactivation is not uninstallation. Clear your scheduled events in `deactivate()`, and leave the merchant's data alone.

:::caution
Make `activate()` non-destructive. It can run on a site that already uses your plugin, for example the first time after an update from a version that did not track activation.
:::

## Write routines that are safe to run twice

Treat every routine as something that may run more than once:

- **It can be retried.** The framework stores the new version only after the routine returns. If the routine throws, the version is not stored and the routine runs again on the next admin page load. Catch the failures you can recover from.
- **It can overlap.** Two admin requests that arrive together can both see the old version.
- **It must not lose data.** Copy before you change, check before you write, and leave old values in place.

A routine that follows these rules does the same thing the second time, which is nothing. In the example above, `upgrade_to_1_1_0()` copies `token` only when `api_key` is missing, and `schedule_sync()` schedules only when nothing is scheduled.

## Hooks the framework fires

The framework fires these actions. `{id}` is the plugin ID as written, so a plugin with ID `my-carrier` fires `woodev_my-carrier_installed`.

| Action | When | Arguments |
|---|---|---|
| `woodev_{id}_installed` | After `install()`. | None. |
| `woodev_{id}_updated` | After `upgrade()`. | The previous version. |
| `woodev_{id}_activated` | After `activate()`. | None. |
| `woodev_{id}_deactivated` | After `deactivate()`. | None. |

Use them to react to a lifecycle event from outside the lifecycle class:

```php
<?php
add_action(
	'woodev_my-carrier_updated',
	static function ( string $previous_version ): void {
		delete_transient( 'my_carrier_tariffs' );
	}
);
```

Register such a hook in `init_plugin()`, or in the entry file, so it exists before the admin page load that runs the routine.

## What the framework stores

| Option | Holds |
|---|---|
| `woodev_{id}_version` | The installed version. |
| `woodev_{id}_is_active` | Whether the plugin has been activated, so `activate()` runs once. |
| `woodev_{id}_lifecycle_events` | A short history of installs, upgrades and migrations. |
| `woodev_{id}_milestone_messages` | Milestone notices that are waiting to be shown. |
| `woodev_{id}_milestone_version` | The version at which milestone tracking started on this site. |

The name of the version option comes from `get_plugin_version_name()`. Never edit or delete these options by hand: deleting the version option makes the next admin page load run `install()` again.

Read the history with `get_event_history()` on the lifecycle handler. It returns the newest events first, and each event has a `name` (`install`, `upgrade` or `migrate`), a `time` and the `version`. To record that a site moved over from another plugin, call `add_migrate_event( $from_plugin, $from_version, $data )`.

## Milestone notices

A milestone is a short congratulation after a first key action, such as the first printed label. It is usually where a review is requested. Trigger one from the place in your code where the action happens:

```php
<?php
My_Carrier_Plugin::instance()->get_lifecycle_handler()->trigger_milestone(
	'first-label',
	__( 'you printed your first label!', 'my-carrier' ),
	'1.2.0'
);
```

The arguments are an ID, a message and the plugin version that introduced the feature. Write the message as the **middle of a sentence**: the framework adds a short exclamation before it and, after it, a request for a review or an offer of support, depending on which URLs the plugin provides (`get_reviews_url()`, `get_support_url()`).

The third argument keeps old shops quiet. When milestone tracking started on a site after the version you pass, the site does not see the notice for something it did long ago. Calling `trigger_milestone()` again for an ID that is already registered does nothing, and the framework stops adding milestones once a merchant has dismissed more than three of them.

The same call is available as an action, `woodev_{id}_milestone_reached`, with the same three arguments.

## Next steps

- [Migrating from v1](../migrating-from-v1/) shows how to move an existing plugin's data through an upgrade routine.
- [The plugin class](../plugin-class/) lists the other things you can replace.
