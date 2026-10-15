---
title: Typed settings
description: Define settings with types, defaults and validation, and read them back safely.
---

In this page you will learn how to declare a plugin's settings with `Woodev_Abstract_Settings`, which setting and control types exist, what each argument does, and how to read and write values from PHP.

## Two registrations per field

Every field on the settings page takes two calls inside `register_settings()`:

| Call | Declares |
|---|---|
| `register_setting( $id, $type, $args )` | The **value**: its id, type, default, validation rules and visibility. |
| `register_control( $id, $type, $args )` | The **control**: what the merchant sees and types into. |

```php
<?php
defined( 'ABSPATH' ) || exit;

class My_Carrier_Settings extends \Woodev_Abstract_Settings {

	protected function register_settings(): void {
		$this->register_setting( 'mode', \Woodev_Setting::TYPE_STRING, [ 'name' => __( 'Mode', 'my-carrier' ) ] );
		$this->register_control( 'mode', \Woodev_Control::TYPE_SELECT );
	}
}
```

Register a control for every setting you want to show. A setting only appears if a section lists its id (see [Tabs, sections & cards](../tabs-sections-and-cards/)), and without a control the page infers a plain one from the type: a toggle for a boolean, a select when there are options, otherwise a text input. State the control you mean and the intent is clear.

:::caution
Both `register_*` methods report a mistake through `_doing_it_wrong()` and skip the call. A typo in a type, a duplicate id or a control that does not fit the setting therefore shows up as a missing field, not as an error on the page. Run with `WP_DEBUG` on while you write them.
:::

## Setting types

`register_setting()` takes one of the `Woodev_Setting::TYPE_*` constants.

| Constant | Value | Stored and read back as |
|---|---|---|
| `Woodev_Setting::TYPE_STRING` | `string` | a string |
| `Woodev_Setting::TYPE_URL` | `url` | a string that must be a valid `http://` or `https://` URL |
| `Woodev_Setting::TYPE_EMAIL` | `email` | a string that must be a valid e-mail address |
| `Woodev_Setting::TYPE_INTEGER` | `integer` | an `int`; numeric strings from the form are converted |
| `Woodev_Setting::TYPE_FLOAT` | `float` | a `float`; numeric strings from the form are converted |
| `Woodev_Setting::TYPE_BOOLEAN` | `boolean` | a `bool`, stored as `yes` or `no` |

## Setting arguments

All arguments are optional.

| Argument | Meaning |
|---|---|
| `name` | The label shown above the field. A control can override it. |
| `description` | Help text shown under the field. A control can override it. |
| `default` | The value used while nothing is stored. Defaults to `null`. |
| `options` | Allowed values for a choice, as `key => label`. A flat list such as `[ 'red', 'green' ]` is also accepted for strings. |
| `is_multi` | `true` when the setting holds a list of values. Each element is validated on its own. |
| `required` | `true` to reject an empty value. Has no effect on a toggle, a checkbox or a range, which always carry a value. |
| `validate` | A callable `fn( $value ): bool`, see [Custom validation](#custom-validation). |
| `validate_message` | The message shown when `validate` returns anything but `true`. |
| `show_if` | Conditions that decide when the field is shown, see [Showing a field conditionally](#showing-a-field-conditionally). |
| `disabled_if` | Conditions that decide when the field is disabled. Same format as `show_if`. |
| `sensitive` | `true` for a secret. The saved value is never sent to the browser. |
| `constant_name` | The name of a PHP constant that supplies the value, see [Secrets](#secrets). |

Declare `options` on the setting. A control may narrow the list but cannot add keys the setting does not have.

## Control types

`register_control()` takes one of the `Woodev_Control::TYPE_*` constants. Pick the one that fits the setting's type.

| Constant | Value | Use it for |
|---|---|---|
| `TYPE_TEXT` | `text` | A short string. |
| `TYPE_TEXTAREA` | `textarea` | A longer string. Accepts `rows`. |
| `TYPE_NUMBER` | `number` | An integer or float. Honours `min`, `max` and `step`. |
| `TYPE_EMAIL` | `email` | An e-mail address. |
| `TYPE_TEL` | `tel` | A phone number. |
| `TYPE_URL` | `url` | A URL. |
| `TYPE_PASSWORD` | `password` | A secret string, shown masked. |
| `TYPE_DATE` | `date` | A date, stored as a string. |
| `TYPE_CHECKBOX` | `checkbox` | A boolean. |
| `TYPE_TOGGLE` | `toggle` | A boolean, drawn as a switch. Accepts `description_on`. |
| `TYPE_RADIO` | `radio` | One choice from a short list. |
| `TYPE_SELECT` | `select` | One choice from a list. |
| `TYPE_MULTISELECT` | `multiselect` | Several choices; the setting needs `is_multi`. |
| `TYPE_SEARCH_SELECT` | `search-select` | One choice found by searching on the server, for lists too long to show. |
| `TYPE_FILE` | `file` | Accepted, but the settings page has no file picker and draws a text input. Do not use it yet. |
| `TYPE_COLOR` | `color` | A colour, stored as a string such as `#06aedd`. |
| `TYPE_RANGE` | `range` | A number chosen with a slider. Honours `min`, `max` and `step`. |
| `TYPE_RICHTEXT` | `richtext` | Formatted text. The value is passed through `wp_kses_post()` before it is saved. |
| `TYPE_BOXES_TABLE` | `boxes-table` | The table of boxes used by shipping plugins. See [Packaging and box packing](../../shipping/packaging-and-box-packing/). |
| `TYPE_LOCATION_PICKER` | `location-picker` | The default-locality search used by shipping plugins. See [Location and checkout fields](../../shipping/location-and-checkout-fields/). |

A toggle or checkbox only fits a single boolean. Registering one for a boolean setting with `is_multi` is refused.

### Control arguments

| Argument | Meaning |
|---|---|
| `name`, `description` | Override the setting's label and help text for this control. |
| `tooltip` | A short explanation shown in an info bubble next to the label. |
| `placeholder` | Grey hint text inside an empty input. |
| `options` | Narrow the setting's `options` to a subset. |
| `min`, `max`, `step` | Bounds for `number` and `range`. They are also enforced on the server. |
| `native_bounds` | `true` to also pass `min`, `max` and `step` to the browser's own input. Off by default. |
| `rows` | The height of a `textarea`, in rows. |
| `disabled` | `true` to show the control read-only. A disabled control's stored value is left untouched on save. |
| `disabled_reason` | The reason shown to the merchant while the control is disabled. Always give one. |
| `description_on` | A second line shown under a toggle or checkbox only while it is switched on. |
| `search_callback`, `label_callback` | Required for `search-select`, see below. |

A `search-select` needs a scalar string or integer setting and two callables. `search_callback( string $term )` returns `[ [ 'value' => 12, 'label' => 'Warehouse A' ], … ]`. `label_callback( $value )` returns the label of a saved value, or `''` if it is unknown.

## A complete handler

This handler uses most of the arguments above.

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The settings of the My Carrier plugin.
 */
class My_Carrier_Settings extends \Woodev_Abstract_Settings {

	/**
	 * Declares the settings and their controls.
	 *
	 * @return void
	 */
	protected function register_settings(): void {

		// A choice with a default.
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
		$this->register_control(
			'mode',
			\Woodev_Control::TYPE_SELECT,
			[ 'tooltip' => __( 'In test mode no real shipments are created.', 'my-carrier' ) ]
		);

		// A required secret, shown only in live mode, that may come from wp-config.php.
		$this->register_setting(
			'api_key',
			\Woodev_Setting::TYPE_STRING,
			[
				'name'          => __( 'API key', 'my-carrier' ),
				'required'      => true,
				'sensitive'     => true,
				'constant_name' => 'MY_CARRIER_API_KEY',
				'show_if'       => [
					'setting' => 'mode',
					'value'   => 'live',
				],
			]
		);
		$this->register_control(
			'api_key',
			\Woodev_Control::TYPE_PASSWORD,
			[
				'tooltip'     => __( 'Find the key in your carrier account.', 'my-carrier' ),
				'description' => __( 'The key is stored hidden and never shown again.', 'my-carrier' ),
			]
		);

		// A bounded number.
		$this->register_setting(
			'markup',
			\Woodev_Setting::TYPE_INTEGER,
			[
				'name'    => __( 'Markup, %', 'my-carrier' ),
				'default' => 10,
			]
		);
		$this->register_control(
			'markup',
			\Woodev_Control::TYPE_NUMBER,
			[
				'min'           => 0,
				'max'           => 100,
				'native_bounds' => true,
			]
		);

		// A switch with a line that appears only when it is on.
		$this->register_setting(
			'notify',
			\Woodev_Setting::TYPE_BOOLEAN,
			[
				'name'    => __( 'Notify customers', 'my-carrier' ),
				'default' => true,
			]
		);
		$this->register_control(
			'notify',
			\Woodev_Control::TYPE_TOGGLE,
			[
				'description_on' => __( 'Customers get an e-mail when the parcel is handed to the carrier.', 'my-carrier' ),
			]
		);

		// A custom rule.
		$this->register_setting(
			'support_phone',
			\Woodev_Setting::TYPE_STRING,
			[
				'name'             => __( 'Support phone', 'my-carrier' ),
				'validate'         => static function ( $value ): bool {
					return 11 === strlen( (string) preg_replace( '/\D/', '', (string) $value ) );
				},
				'validate_message' => __( 'Enter a phone number with 11 digits.', 'my-carrier' ),
			]
		);
		$this->register_control( 'support_phone', \Woodev_Control::TYPE_TEL, [ 'placeholder' => '+7 999 123-45-67' ] );

		// Several choices.
		$this->register_setting(
			'methods',
			\Woodev_Setting::TYPE_STRING,
			[
				'name'     => __( 'Delivery methods', 'my-carrier' ),
				'is_multi' => true,
				'options'  => [
					'pickup'  => __( 'Pickup point', 'my-carrier' ),
					'courier' => __( 'Courier', 'my-carrier' ),
				],
				'default'  => [ 'pickup' ],
			]
		);
		$this->register_control( 'methods', \Woodev_Control::TYPE_MULTISELECT );

		// A longer text.
		$this->register_setting( 'comment', \Woodev_Setting::TYPE_STRING, [ 'name' => __( 'Order comment', 'my-carrier' ) ] );
		$this->register_control( 'comment', \Woodev_Control::TYPE_TEXTAREA, [ 'rows' => 4 ] );
	}
}
```

## Labels and help texts

Keep a field's **label short**: the essence in one line, usually two words, three at most. A toggle already says "on or off", so write «Notify customers», not «Enable customer notifications».

Put the explanation in `tooltip`, which is the default home for it, and keep `description` for text that must be impossible to miss, easy to copy or that carries a link. The two work together, so a field may have both.

:::caution
`description` and `description_on` are rendered as HTML, so that links work. They must be strings you wrote. Never put a stored value, an API response or an error message from a remote service into them. To explain why a control is unavailable at run time, use `disabled_reason`, which is escaped.
:::

## Showing a field conditionally

`show_if` hides a field until another field has a given value. The simplest form is one condition:

```php
<?php
$this->register_setting(
	'sandbox_url',
	\Woodev_Setting::TYPE_URL,
	[
		'name'    => __( 'Sandbox URL', 'my-carrier' ),
		'show_if' => [
			'setting'  => 'mode',
			'operator' => '=',
			'value'    => 'test',
		],
	]
);
```

Several conditions go in a list, joined by `'relation' => 'AND'` (the default) or `'OR'`:

```php
<?php
$this->register_setting(
	'tracking_prefix',
	\Woodev_Setting::TYPE_STRING,
	[
		'name'    => __( 'Tracking prefix', 'my-carrier' ),
		'show_if' => [
			'relation' => 'AND',
			[
				'setting' => 'notify',
				'value'   => true,
			],
			[
				'setting'  => 'mode',
				'operator' => 'in',
				'value'    => [ 'test', 'live' ],
			],
		],
	]
);
```

| Operator | Matches when |
|---|---|
| `=` (default) | the controlling value equals `value` |
| `!=` | it does not equal `value` |
| `in` | it is one of the values in the `value` list |
| `not_in` | it is none of the values in the `value` list |

Rules worth knowing:

- Values are compared as strings. For a select or radio, compare with the option **key**. For a toggle, use `true` or `false`.
- An unknown operator hides the field.
- Say "show when the mode is live" as `= 'live'`, not `!= 'test'`: the second form also shows the field while the mode has never been set.
- A hidden field is neither validated nor saved. The form and the server evaluate the same rule, so they agree.
- `show_if` and `disabled_if` may also be a callable that takes the field id and returns the condition list. It runs on the server and cannot see values the merchant has just typed.

## Custom validation

The server checks a submitted value in this order:

1. If `required` is set and the value is empty, it fails with «Обязательное поле» (Required field).
2. An empty optional value is valid. Nothing else runs.
3. A `validate` callable, when set, decides. It runs only on a non-empty value and must return exactly `true` to pass; any other return is a failure and shows `validate_message`.
4. Otherwise the built-in checks run: the format of an `email`, `url`, `tel`, `number` or `range` control and its `min` and `max`, then the setting's type, then the allowed `options`.

Note step 3: a `validate` callable **replaces** the built-in format, type and options checks for that field. If the setting has `options`, your callable has to check them itself.

## Secrets

Mark a secret with `sensitive => true`. The saved value is never sent to the browser. The field shows that a value exists, and the merchant can replace it.

`constant_name` lets a site owner define the value in `wp-config.php` instead:

```php
<?php
define( 'MY_CARRIER_API_KEY', 'abc123' );
```

While the constant is defined, `get_value()` returns it, the field is read-only with a note that explains why, and the value is never written to the database. A field with `constant_name` is treated as a secret even while the constant is not defined.

## Reading and writing values

The handler is the one place that reads and writes values.

```php
<?php
$settings = My_Carrier_Plugin::instance()->get_settings_handler();

// The stored value, or the default when nothing is stored.
$mode = $settings->get_value( 'mode' );

// The stored value only: null when nothing is stored.
$raw = $settings->get_value( 'mode', false );

// Validate, store and save one value. Throws Woodev_Plugin_Exception when it is invalid.
try {
	$settings->update_value( 'markup', 15 );
} catch ( \Woodev_Plugin_Exception $e ) {
	// $e->getMessage() is a message you can show.
}

// Remove the stored value, so the default applies again.
$settings->delete_value( 'markup' );
```

`get_value()` throws `Woodev_Plugin_Exception` for an id that was never registered, so keep ids in constants if you read them from several files.

`update_value()` runs the same checks as the settings page, so it throws on an invalid value. It does nothing for a setting backed by a defined constant.

:::caution
Write through `update_value()`, not through `update_option()`. A handler reads each value once, when it is constructed, and keeps it. A direct `update_option()` is invisible to the handler for the rest of the request.
:::

## Extending the accepted types

Three filters change what `register_setting()` and `register_control()` accept. `{id}` is the id you gave the handler.

| Filter | Receives | Effect |
|---|---|---|
| `woodev_{id}_settings_api_setting_types` | the list of setting types, the handler | The list of types `register_setting()` accepts. |
| `woodev_{id}_settings_api_control_types` | the list of control types, the handler | The list of control types `register_control()` accepts. |
| `woodev_{id}_settings_api_setting_control_types` | `[]`, the setting type, the setting, the handler | The control types allowed for one setting. An empty list means no restriction. |

A filter must return an array; anything else is ignored. The third filter is the useful one. It lets a plugin keep a type of setting on controls that make sense, for instance:

```php
<?php
add_filter(
	'woodev_my-carrier_settings_api_setting_control_types',
	static function ( array $control_types, string $setting_type ): array {
		if ( \Woodev_Setting::TYPE_EMAIL === $setting_type ) {
			return [ \Woodev_Control::TYPE_EMAIL, \Woodev_Control::TYPE_TEXT ];
		}

		return $control_types;
	},
	10,
	2
);
```

A control type that the page does not know is drawn as a plain text input, so adding a name to the first two filters does not give you a new kind of control.
