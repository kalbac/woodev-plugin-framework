---
title: Settings overview
description: Learn where a Woodev plugin's settings live, how a plugin gets a tab on the shared settings page, and how the values are stored.
---

In this page you will learn where a plugin's settings live, how your plugin adds a tab to the shared settings page, which capability a user needs to open it, and how the values end up in the database.

## One page for every Woodev plugin

All Woodev plugins on a site share one settings page: **«Woodev → Настройки»** (Settings). It is a submenu of the top-level «Woodev» item, and its slug is `woodev-settings`, so its address is `wp-admin/admin.php?page=woodev-settings`.

Each plugin contributes one or more **tabs** to that page. A merchant who runs three Woodev plugins finds three tabs in one place, with the same look, the same save button and the same validation messages. The page appears as soon as at least one active plugin contributes a tab.

:::caution
Do not build your own top-level settings page for a plugin, and do not put its settings on a page of your own under another menu. A plugin that does so breaks the one promise this page makes to merchants: "all Woodev settings are in one place".
:::

WooCommerce also has an «Интеграции» (Integrations) route under its own settings. It exists only for special cases and is not where a new plugin's settings go.

## The three pieces

A settings tab is built from three kinds of object. This page shows how they connect. The next pages cover each one in detail.

| Piece | Class | Job |
|---|---|---|
| **Handler** | a subclass of `Woodev_Abstract_Settings` | Declares the settings (id, type, default, validation) and their controls, and reads and writes the values. See [Typed settings](../typed-settings/). |
| **Provider** | `\Woodev\Framework\Settings\Settings_Provider` | Describes one **tab**: its id, label, the handler behind it and its sections. |
| **Sections** | `\Woodev\Framework\Settings\Settings_Section` | Split a tab into labelled parts, optionally with grouped cards. See [Tabs, sections & cards](../tabs-sections-and-cards/). |

## Add a tab to your plugin

Your main class overrides `get_settings_providers()` and returns one provider per tab. The framework registers your plugin with the settings page for you; there is nothing else to call.

The example below defines a handler with two settings, then returns one tab with one section. It extends `\Woodev\Framework\Woocommerce_Plugin` like the plugin in [Your first plugin](../../getting-started/your-first-plugin/); the other members of the class are the same as there.

`my-carrier/includes/class-my-carrier-settings.php`

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The settings of the My Carrier plugin.
 */
class My_Carrier_Settings extends \Woodev_Abstract_Settings {

	/**
	 * Declares the settings and the controls that show them.
	 *
	 * @return void
	 */
	protected function register_settings(): void {
		$this->register_setting(
			'mode',
			\Woodev_Setting::TYPE_STRING,
			[
				'name'    => __( 'Mode', 'my-carrier' ),
				'options' => [
					'test' => __( 'Test', 'my-carrier' ),
					'live' => __( 'Live', 'my-carrier' ),
				],
				'default' => 'test',
			]
		);
		$this->register_control( 'mode', \Woodev_Control::TYPE_SELECT );

		$this->register_setting(
			'api_key',
			\Woodev_Setting::TYPE_STRING,
			[
				'name'      => __( 'API key', 'my-carrier' ),
				'required'  => true,
				'sensitive' => true,
			]
		);
		$this->register_control( 'api_key', \Woodev_Control::TYPE_PASSWORD );
	}
}
```

`my-carrier/includes/class-my-carrier-plugin.php` (only the settings part)

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
	 * The settings handler, built on first use.
	 *
	 * @var My_Carrier_Settings|null
	 */
	private $settings_handler;

	/**
	 * Gets the settings handler. Always returns the same object.
	 *
	 * @return My_Carrier_Settings
	 */
	public function get_settings_handler() {
		if ( null === $this->settings_handler ) {
			$this->settings_handler = new My_Carrier_Settings( $this->get_id() );
		}

		return $this->settings_handler;
	}

	/**
	 * Returns the tabs this plugin adds to «Woodev → Настройки».
	 *
	 * @return \Woodev\Framework\Settings\Settings_Provider[]
	 */
	public function get_settings_providers(): array {
		return [
			\Woodev\Framework\Settings\Settings_Provider::create_with_sections(
				'my-carrier',
				__( 'My Carrier', 'my-carrier' ),
				$this->get_settings_handler(),
				[],
				\Woodev\Framework\Settings\Settings_Section::create(
					'general',
					__( 'General', 'my-carrier' ),
					[ 'mode', 'api_key' ]
				)
			),
		];
	}
}
```

Three rules keep this safe:

- **Return the same handler every time.** The handler registers its settings and loads their stored values in its constructor, so build it once and keep it in a property, as `get_settings_handler()` does above.
- **Build the handler late.** It calls `__()` while it registers settings. Do not create it in your plugin's constructor, which runs before WordPress has loaded translations. The settings page asks for it later, when translations are ready.
- **Pass the plugin id to the handler.** The id is part of every option name (see below) and must equal the `plugin_id` in your loader definition, which is what `$this->get_id()` returns.

:::note
A shipping plugin does not override `get_settings_providers()`. It overrides `get_tab_settings_providers()`, and the framework adds its own shipping sections to the same tab. See [Plugin anatomy](../../shipping/plugin-anatomy/).
:::

## Who can open a tab

Each tab has a capability, and a user sees only the tabs they may open. The framework chooses it like this:

1. The `capability` you pass in the provider's `$args` array wins.
2. Otherwise a WooCommerce plugin (one that extends `Woocommerce_Plugin`) uses `manage_woocommerce`, so shop managers can open it.
3. Otherwise the tab uses `manage_options`, which means administrators only.

The page itself opens for a user who may open at least one tab. To restrict a tab to administrators, pass the capability explicitly:

```php
<?php
\Woodev\Framework\Settings\Settings_Provider::create_with_sections(
	'my-carrier',
	__( 'My Carrier', 'my-carrier' ),
	$this->get_settings_handler(),
	[ 'capability' => 'manage_options' ],
	\Woodev\Framework\Settings\Settings_Section::create( 'general', __( 'General', 'my-carrier' ), [ 'mode', 'api_key' ] )
);
```

If a plugin moves its settings from an old WooCommerce page, pass `'legacy_page'` in the same `$args` array, for example `'wc-settings&tab=shipping&section=my-carrier'`. Links to the old address then redirect to the new tab.

Two providers must not share a tab id. The framework keeps the first and reports the second through `_doing_it_wrong()`.

## How values are stored

Every setting is **its own WordPress option**, named `woodev_{id}_{setting_id}`, where `{id}` is the id you gave the handler. The `api_key` setting of `my-carrier` is stored in the option `woodev_my-carrier_api_key`.

- Booleans are stored as the strings `yes` and `no`, and read back as real booleans.
- Integers and floats are read back as numbers.
- A setting that was never saved has no option row, and reading it returns its `default`.
- Saving `null` deletes the option.

:::caution
These option names are a contract with every installed site the moment you ship. Choose setting ids once and never rename them; a renamed id silently starts again from the default.
:::

## Where to go next

- [Typed settings](../typed-settings/) — every setting type, control type and argument.
- [Tabs, sections & cards](../tabs-sections-and-cards/) — lay a tab out in sections and grouped cards.
- [Connection tests](../connection-tests/) — add a «check the connection» button.
- [Setup wizard](../setup-wizard/) — the same settings, asked step by step on first run.
