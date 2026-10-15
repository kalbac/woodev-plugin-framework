---
title: Connection tests
description: Add a button that checks the connection to a remote service from the settings page.
---

In this page you will learn how to add a «check the connection» block to a settings tab: how to declare it, how to implement the test against your API, and how to show the last known status when the page opens.

## What the merchant sees

A **connection section** shows the credential fields you choose, and below them one button, for example «Check connection». When the merchant presses it:

1. The page sends the values currently in the form, saved or not, to your handler.
2. Your handler tries the connection and returns a result.
3. The result message appears next to the button, in green for success and in red for failure.

The button stays disabled until every field of the section has a value. A saved secret counts as filled even though its input looks empty, and so does a field backed by a constant in `wp-config.php`. Editing any field clears the old result, so a stale «Connected» never sits beside changed credentials.

The framework only draws the button and carries the result. **Everything else is yours**: how to authenticate, which request to send and what counts as success.

## Declare the section

Add a connection section to your tab with `Settings_Section::create_connection()`:

```php
<?php
\Woodev\Framework\Settings\Settings_Section::create_connection(
	'api',
	__( 'Connection', 'my-carrier' ),
	[ 'login', 'password' ],
	__( 'Check connection', 'my-carrier' ),
	__( 'The credentials from your carrier account.', 'my-carrier' )
);
```

The arguments are the section id, its heading, the ids of the credential settings, the button label and an optional description. The button label is required, because a button without one has no name. The id list may be empty for a block that needs no input.

The id you give the section, here `api`, is the **connection id**. Your handler receives it, so a handler with several connection blocks can tell them apart.

## Implement the test

Make your settings handler implement `Woodev_Settings_Connection_Test`. Its one method receives the connection id and the form values and returns a `Woodev_Connection_Result`.

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The settings of the My Carrier plugin.
 */
class My_Carrier_Settings extends \Woodev_Abstract_Settings implements \Woodev_Settings_Connection_Test {

	/**
	 * Declares the credential settings.
	 *
	 * @return void
	 */
	protected function register_settings(): void {
		$this->register_setting( 'login', \Woodev_Setting::TYPE_STRING, [ 'name' => __( 'Login', 'my-carrier' ) ] );
		$this->register_control( 'login', \Woodev_Control::TYPE_TEXT );

		$this->register_setting(
			'password',
			\Woodev_Setting::TYPE_STRING,
			[
				'name'      => __( 'Password', 'my-carrier' ),
				'sensitive' => true,
			]
		);
		$this->register_control( 'password', \Woodev_Control::TYPE_PASSWORD );
	}

	/**
	 * Checks the credentials against the carrier's API.
	 *
	 * @param string              $connection_id The id of the connection section.
	 * @param array<string,mixed> $values        The form values merged with the stored ones.
	 * @return \Woodev_Connection_Result
	 */
	public function test_connection( string $connection_id, array $values ): \Woodev_Connection_Result {

		if ( 'api' !== $connection_id ) {
			return \Woodev_Connection_Result::failure( __( 'Unknown connection.', 'my-carrier' ) );
		}

		$response = wp_remote_get(
			'https://api.my-carrier.example/v1/account',
			[
				'timeout' => 10,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $values['login'] . ':' . $values['password'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return \Woodev_Connection_Result::failure( __( 'The carrier did not answer. Try again in a minute.', 'my-carrier' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return \Woodev_Connection_Result::failure( __( 'The login or password is wrong.', 'my-carrier' ) );
		}

		if ( 200 !== $code ) {
			return \Woodev_Connection_Result::failure( __( 'The carrier answered with an error.', 'my-carrier' ) );
		}

		return \Woodev_Connection_Result::success( __( 'Connected.', 'my-carrier' ) );
	}
}
```

Build the result with the two named constructors: `Woodev_Connection_Result::success( $message )` and `Woodev_Connection_Result::failure( $message )`. The message is plain text shown to the merchant.

Things to know:

- **The values are complete.** `$values` holds an entry for every setting id of the section. Fields the merchant has typed into are taken as typed. A secret the merchant did not touch is filled in from the stored value, so you can use it even though the browser never saw it.
- **Nothing is saved.** The test does not store the form values. The merchant still has to press the page's save button.
- **Catch your own errors.** If your method throws, the merchant sees only a generic message, «Ошибка при проверке подключения» (Connection check failed), with no detail. Return `failure()` with a message that helps them instead.
- **Keep it quick.** The merchant is watching a spinner. Give the request a short timeout, as above.
- **Never put a secret in the message.** Do not echo back the password or a token, or the raw answer of the remote service.

:::caution
If the handler does not implement `Woodev_Settings_Connection_Test`, the section shows its fields but **no button**. Implement the interface on the handler that owns the connection's settings, not on the plugin class.
:::

## Show a status when the page opens

By default the result area is empty until the merchant presses the button. To show the last known state straight away, such as «Connected as shop-17», also implement `Woodev_Settings_Connection_Status`:

```php
<?php
defined( 'ABSPATH' ) || exit;

class My_Carrier_Settings extends \Woodev_Abstract_Settings implements \Woodev_Settings_Connection_Test, \Woodev_Settings_Connection_Status {

	/**
	 * Gets the last known state of a connection.
	 *
	 * @param string $connection_id The id of the connection section.
	 * @return \Woodev_Connection_Result|null
	 */
	public function get_connection_status( string $connection_id ): ?\Woodev_Connection_Result {

		$status = get_option( 'my_carrier_connection_status' );

		if ( ! is_array( $status ) ) {
			return null;
		}

		return ! empty( $status['connected'] )
			? \Woodev_Connection_Result::success( (string) $status['message'] )
			: \Woodev_Connection_Result::failure( (string) $status['message'] );
	}

	// register_settings() and test_connection() as in the previous example.
}
```

Return `null` when you have nothing to say. The framework stores nothing, so remember the outcome yourself: write it with `update_option()` at the end of `test_connection()`, or whenever your plugin talks to the API and learns that the credentials work or stopped working.

:::caution
`get_connection_status()` runs every time the settings page loads. Read a stored value; never call the remote service from it.
:::

## Putting it together

1. Register the credential settings and controls in the handler ([Typed settings](../typed-settings/)).
2. Add a `create_connection()` section to the tab ([Tabs, sections & cards](../tabs-sections-and-cards/)).
3. Implement `Woodev_Settings_Connection_Test` on the handler, and optionally `Woodev_Settings_Connection_Status`.
4. Open «Woodev → Настройки» (Settings), fill in the credentials and press the button.
