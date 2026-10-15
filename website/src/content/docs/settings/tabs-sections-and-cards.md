---
title: "Tabs, sections & cards"
description: Organise a settings page into tabs, sections and cards.
---

In this page you will learn how a plugin's settings tab is organised: one **tab** per provider, **sections** inside the tab, and titled **cards** (groups) inside a section. It ends with a complete example of a tab with two sections and grouped cards.

## The building blocks

```text
Tab         "My Carrier"                    one Settings_Provider
├─ Section  "General"                       Settings_Section::create()
│  ├─ Card  "Account"                       Settings_Group
│  └─ Card  "Notifications"                 Settings_Group
├─ Section  "Orders"                        Settings_Section::create()
│  └─ Card  "Statuses"                      fields and a button
└─ Section  "Connection"                    Settings_Section::create_connection()
```

| Level | Class | Created with |
|---|---|---|
| Tab | `\Woodev\Framework\Settings\Settings_Provider` | `Settings_Provider::create_with_sections()` |
| Section | `\Woodev\Framework\Settings\Settings_Section` | `create()`, `create_connection()` or `create_tools()` |
| Card | `\Woodev\Framework\Settings\Settings_Group` | `Settings_Group::create()`, then `with_fields()` and `with_actions()` |

A section holds **setting ids**. The fields themselves stay on your handler, so values, validation and the save button work the same whatever the layout.

## A tab

`Settings_Provider::create_with_sections( $id, $label, $handler, $args, ...$sections )` builds one tab.

| Parameter | Meaning |
|---|---|
| `$id` | The tab id, used in the page address (`&tab=my-carrier`). It must be unique. Leave it empty to use the handler's id. |
| `$label` | The text on the tab. |
| `$handler` | Your `Woodev_Abstract_Settings` subclass. Return the same object every time. |
| `$args` | Optional: `capability`, `legacy_page`. See the [Settings overview](../overview/#who-can-open-a-tab). |
| `...$sections` | The sections, in the order they appear. |

Pass the sections as arguments, not as an array. A value that is not a `Settings_Section` then raises a `TypeError` at your call, instead of a section silently going missing. A tab with no sections at all is valid.

## The three kinds of section

The kinds are mutually exclusive, so each has its own constructor.

| Constructor | Use it for |
|---|---|
| `Settings_Section::create( $id, $label, $setting_ids, $description )` | A labelled group of fields. This is the usual kind. |
| `Settings_Section::create_connection( $id, $label, $setting_ids, $action_label, $description )` | Credentials plus one button that checks or establishes a connection. See [Connection tests](../connection-tests/). The id list may be empty. |
| `Settings_Section::create_tools( $id, $label, $tools, $description )` | A block of action buttons with no fields. It takes `Shipping_Tool` descriptors and is mainly used by shipping plugins. |

Things to remember:

- **An empty id list means no fields.** `create( 'general', 'General', [] )` is a section without fields, not a section with all of them.
- The `$description` is shown under the section's heading. It may contain links and basic HTML; everything else is removed.
- Section ids must be unique within a tab.

## Ordering

Order comes from the order you write things in:

- **Tabs** appear in the order the plugins register, and a plugin's own tabs in the order `get_settings_providers()` returns them.
- **Sections** appear in the order you pass them to `create_with_sections()`.
- **Fields** appear in the order of the id list of their section.
- **Cards** appear where the first field of the card sits in that list. A card with no fields comes after all the fields.

Inside a card the fields follow the section's order, whatever order the card names them in. Fields that no card names are drawn as usual, in their place.

## Cards

A card is a titled box that gathers related fields and buttons.

```php
<?php
$account = \Woodev\Framework\Settings\Settings_Group::create(
	'account',
	__( 'Account', 'my-carrier' ),
	__( 'Where to find these values: your carrier account.', 'my-carrier' )
)->with_fields( [ 'mode', 'api_key' ] );
```

`Settings_Group` is immutable: every `with_*()` method returns a copy, so chain them or assign the result.

| Method | Effect |
|---|---|
| `Settings_Group::create( $id, $title, $description )` | An empty card. The id must be unique within its section. The description is optional and may contain basic HTML. |
| `with_fields( array $setting_ids )` | The fields in the card. |
| `with_actions( array $action_ids )` | The section's buttons that sit in the card, in one row with one shared result line. |
| `with_notice( string $notice )` | A plain-text line shown once under the card's buttons. |

A field or button belongs to **one** card. If two cards name it, the first card keeps it. An id the section does not declare is ignored, and a card left with nothing in it is not shown.

Attach cards to a section with `with_groups()`. Like the card methods it returns a copy, and it only works on an ordinary section; on a connection or tools section it is ignored with a developer notice.

### Buttons under the fields

`with_actions()` on a section adds buttons below its fields, for a one-off job such as «Update statuses now». Each button is a `Shipping_Tool` descriptor and its callback returns a `Tool_Result`:

```php
<?php
\Woodev\Framework\Shipping\Settings\Shipping_Tool::create(
	'sync_now',
	__( 'Update statuses', 'my-carrier' ),
	__( 'Fetches the latest status of every open shipment.', 'my-carrier' ),
	__( 'Update now', 'my-carrier' ),
	static function ( array $input ): \Woodev\Framework\Shipping\Settings\Tool_Result {
		return \Woodev\Framework\Shipping\Settings\Tool_Result::success( __( 'Statuses updated.', 'my-carrier' ) );
	}
);
```

The arguments are, in order: an id (unique within the tab), a title, a description, the button label and the callback. Two optional arguments follow: `$disabled` to show the button but refuse the action, and `$status_text` for the reason or a live status.

## A complete tab

The example below is a tab for My Carrier with two sections. The first has two cards. The second has one card that holds a field and a button.

`my-carrier/includes/class-my-carrier-plugin.php` (only the settings part)

```php
<?php
defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Settings\Settings_Group;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Framework\Shipping\Settings\Shipping_Tool;
use Woodev\Framework\Shipping\Settings\Tool_Result;

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
	 * Returns the tab this plugin adds to «Woodev → Настройки».
	 *
	 * @return Settings_Provider[]
	 */
	public function get_settings_providers(): array {

		$general = Settings_Section::create(
			'general',
			__( 'General', 'my-carrier' ),
			[ 'mode', 'api_key', 'notify', 'notify_email' ],
			__( 'The basics of the integration.', 'my-carrier' )
		)->with_groups(
			[
				Settings_Group::create( 'account', __( 'Account', 'my-carrier' ) )
					->with_fields( [ 'mode', 'api_key' ] ),
				Settings_Group::create(
					'notifications',
					__( 'Notifications', 'my-carrier' ),
					__( 'Who hears about a shipment, and when.', 'my-carrier' )
				)->with_fields( [ 'notify', 'notify_email' ] ),
			]
		);

		$orders = Settings_Section::create(
			'orders',
			__( 'Orders', 'my-carrier' ),
			[ 'default_status' ]
		)->with_actions(
			[
				Shipping_Tool::create(
					'sync_now',
					__( 'Update statuses', 'my-carrier' ),
					__( 'Fetches the latest status of every open shipment.', 'my-carrier' ),
					__( 'Update now', 'my-carrier' ),
					static function ( array $input ): Tool_Result {
						return Tool_Result::success( __( 'Statuses updated.', 'my-carrier' ) );
					}
				),
			]
		)->with_groups(
			[
				Settings_Group::create( 'statuses', __( 'Statuses', 'my-carrier' ) )
					->with_fields( [ 'default_status' ] )
					->with_actions( [ 'sync_now' ] )
					->with_notice( __( 'Statuses are also updated every hour.', 'my-carrier' ) ),
			]
		);

		return [
			Settings_Provider::create_with_sections(
				'my-carrier',
				__( 'My Carrier', 'my-carrier' ),
				$this->get_settings_handler(),
				[],
				$general,
				$orders
			),
		];
	}
}
```

The handler behind it declares the five settings. Each id in a section list must exist here.

`my-carrier/includes/class-my-carrier-settings.php`

```php
<?php
defined( 'ABSPATH' ) || exit;

class My_Carrier_Settings extends \Woodev_Abstract_Settings {

	/**
	 * Declares the settings and their controls.
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

		$this->register_setting(
			'notify',
			\Woodev_Setting::TYPE_BOOLEAN,
			[
				'name'    => __( 'Notify customers', 'my-carrier' ),
				'default' => true,
			]
		);
		$this->register_control( 'notify', \Woodev_Control::TYPE_TOGGLE );

		$this->register_setting(
			'notify_email',
			\Woodev_Setting::TYPE_EMAIL,
			[
				'name'    => __( 'Sender address', 'my-carrier' ),
				'show_if' => [
					'setting' => 'notify',
					'value'   => true,
				],
			]
		);
		$this->register_control( 'notify_email', \Woodev_Control::TYPE_EMAIL );

		$this->register_setting(
			'default_status',
			\Woodev_Setting::TYPE_STRING,
			[
				'name'    => __( 'Status after handover', 'my-carrier' ),
				'options' => [
					'wc-processing' => __( 'Processing', 'my-carrier' ),
					'wc-completed'  => __( 'Completed', 'my-carrier' ),
				],
				'default' => 'wc-processing',
			]
		);
		$this->register_control( 'default_status', \Woodev_Control::TYPE_SELECT );
	}
}
```

The merchant sees one tab «My Carrier» with two sections. «General» shows the cards «Account» and «Notifications»; «Orders» shows the card «Statuses» with one field, a button and a short notice.

:::note
Add a `create_connection()` section to the same tab for a block that checks the credentials. It is a section of its own and cannot sit inside a card. [Connection tests](../connection-tests/) shows how.
:::
