---
title: REST endpoints
description: Expose plugin actions to the admin UI through REST routes.
---

In this page you will learn how to add your own REST routes to a plugin safely: which namespace to use, how to protect a route, how to validate its input, and how to add your plugin's data to the WooCommerce system status report.

## Use your own namespace

A REST route lives under a namespace, the first part of its address. Give your plugin its own, built from the plugin id and a version:

```text
/wp-json/my-carrier/v1/status
```

:::caution
`woodev/v1` belongs to the framework. Never register a route there, even if the name you want looks free. The framework adds and changes routes in that namespace between releases, so a route of yours could collide with one of theirs and stop working after an update.
:::

A shipping plugin already has a ready-made value: `Shipping_REST_API::get_namespace()` returns the plugin id with dashes, such as `my-carrier`. For any other plugin, `$this->get_id_dasherized()` gives the same string. Add your own version suffix: `my-carrier/v1`. When you change a route in a way that breaks callers, publish it under `v2` and keep `v1` working for as long as it has users.

## Register routes where REST can see them

REST requests are not admin requests. `is_admin()` is `false` for them, and WooCommerce does not load in the way it does on an admin page. So register routes on `rest_api_init`, from code that always runs, never from code that only loads in wp-admin.

```php
<?php
defined( 'ABSPATH' ) || exit;

final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	// ...the constructor, instance(), get_file() and the rest from "Your first plugin"...

	/**
	 * Registers the plugin's hooks.
	 *
	 * @return void
	 */
	public function init_plugin(): void {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Registers the plugin's REST controllers.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new My_Carrier_Status_Controller() )->register_routes();
	}
}
```

## Always set a `permission_callback`

Every route needs a `permission_callback`. It is the only thing between the internet and your code.

- **An admin route** checks a capability: `manage_woocommerce` for anything a shop manager does, `manage_options` for anything only an administrator should do.
- **A route for a visitor** may return `true`, but only when the data is public and read-only. Say why in a comment.
- **A webhook from another service** checks a signature or a secret that you and the sender share.

Leaving `permission_callback` out makes WordPress log a notice and treat the route as public. Do not rely on that.

## A complete controller

A controller extends `\WP_REST_Controller`. This one has two routes: a read route that reports the plugin's mode, and a write route that runs a connection check. Both are for shop managers.

`includes/class-my-carrier-status-controller.php`

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The My Carrier REST routes.
 */
final class My_Carrier_Status_Controller extends \WP_REST_Controller {

	/**
	 * Sets the namespace and the base of the routes.
	 */
	public function __construct() {
		$this->namespace = 'my-carrier/v1';
		$this->rest_base = 'status';
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/check',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'run_check' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => [
						'mode' => [
							'description'       => 'Which environment to check.',
							'type'              => 'string',
							'enum'              => [ 'test', 'live' ],
							'default'           => 'test',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						],
					],
				],
			]
		);
	}

	/**
	 * Lets shop managers in and everyone else out.
	 *
	 * @return bool|\WP_Error
	 */
	public function permissions_check() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			'Недостаточно прав для этого действия.',
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * Returns the current mode.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ) {
		$handler = My_Carrier_Plugin::instance()->get_settings_handler();

		return rest_ensure_response(
			[
				'mode'       => (string) $handler->get_value( 'mode' ),
				'configured' => My_Carrier_Plugin::instance()->is_configured(),
			]
		);
	}

	/**
	 * Runs a connection check for the requested mode.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_check( $request ) {
		$mode = (string) $request->get_param( 'mode' );

		// Call the carrier here. This sketch only reports the mode it was asked to check.
		return rest_ensure_response(
			[
				'ok'      => true,
				'mode'    => $mode,
				'message' => 'Проверка выполнена.',
			]
		);
	}

	/**
	 * Describes the shape of the status resource.
	 *
	 * @return array<string,mixed>
	 */
	public function get_item_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'my-carrier-status',
			'type'       => 'object',
			'properties' => [
				'mode'       => [
					'type' => 'string',
					'enum' => [ 'test', 'live' ],
				],
				'configured' => [
					'type' => 'boolean',
				],
			],
		];
	}
}
```

The `args` array is where input is checked. WordPress validates and sanitises each declared argument before your callback runs, so the callback can trust `$request->get_param( 'mode' )`. Declare a `type`, an `enum` or `pattern` where it fits, and a `sanitize_callback`. Never read `$_POST` or `$_GET` directly in a callback.

The `schema` entry on the read route describes the response. It appears in the route's `OPTIONS` response and helps tools and clients understand the data.

## Call a route from the admin UI

Scripts in wp-admin call routes with `wp.apiFetch`. It adds the nonce for you. Add `wp-api-fetch` to your script's dependencies.

```js
wp.apiFetch( {
	path: '/my-carrier/v1/status/check',
	method: 'POST',
	data: { mode: 'live' },
} ).then( function ( result ) {
	console.log( result.message );
} );
```

## The framework's REST handler

Every plugin has a REST handler, `Woodev_REST_API`, built by `init_rest_api_handler()` in the plugin constructor. For a WooCommerce plugin it does two things for you:

- it adds a block of your plugin's data to the WooCommerce system status response, under the key `wc_{id}`;
- if your plugin has a settings handler, it exposes the settings through `wc/v3/{settings id}/settings`, where the settings id is the id you gave the handler. The route is for users who may manage WooCommerce.

It does nothing when WooCommerce is not active.

### Add data to the system status

The system status response is what support staff read to understand a shop. Add your plugin's facts with the filter `wc_{id}_rest_api_system_status_data`. It receives the data array, the response and the request; return the array with your additions.

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds My Carrier facts to the WooCommerce system status.
 *
 * @param array $data The data the framework already collected.
 * @return array
 */
function my_carrier_system_status_data( $data ) {
	$plugin = My_Carrier_Plugin::instance();

	$data['mode']       = (string) $plugin->get_settings_handler()->get_value( 'mode' );
	$data['configured'] = $plugin->is_configured();

	return $data;
}
add_filter( 'wc_my-carrier_rest_api_system_status_data', 'my_carrier_system_status_data' );
```

A request to `GET /wp-json/wc/v3/system_status` then contains:

```json
{
	"wc_my-carrier": {
		"is_payment_gateway": false,
		"mode": "live",
		"configured": true
	}
}
```

Only put in what is safe to show to anyone who can read the system status. Never include keys, tokens or customer data.

### Replace the handler

When you want the handler itself to register your routes and build the status data, extend it and return your subclass from `init_rest_api_handler()`:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The My Carrier REST handler.
 */
final class My_Carrier_REST_API extends \Woodev_REST_API {

	/**
	 * Gets the data added to the WooCommerce system status.
	 *
	 * @return array
	 */
	protected function get_system_status_data() {
		return [
			'mode' => (string) My_Carrier_Plugin::instance()->get_settings_handler()->get_value( 'mode' ),
		];
	}

	/**
	 * Registers the framework's routes, then the plugin's own.
	 *
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		( new My_Carrier_Status_Controller() )->register_routes();
	}
}
```

And in the main class:

```php
<?php
defined( 'ABSPATH' ) || exit;

final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	// ...the rest of the class...

	/**
	 * Uses the plugin's own REST handler.
	 *
	 * @return void
	 */
	protected function init_rest_api_handler() {
		$this->rest_api_handler = new My_Carrier_REST_API( $this );
	}
}
```

Always call `parent::register_routes()` first. The parent registers the settings route. The handler is built inside the plugin constructor, so its class must already be loaded by then. Load `My_Carrier_REST_API` and the controller the same way you load the main class (see [Plugin layout & conventions](../../getting-started/plugin-layout-and-conventions/#loading-your-own-files)).

A shipping plugin uses `Shipping_REST_API`, which also registers each controller returned by its `get_rest_controllers()` method, under the plugin's own namespace.

## Checklist

- The namespace is `{plugin-id}/v1`, never `woodev/v1`.
- Routes are registered on `rest_api_init`, not behind `is_admin()`.
- Every route has a real `permission_callback`.
- Every argument has a `type`, and a `sanitize_callback` or `validate_callback` where it matters.
- Nothing secret appears in a response.

## Where to go next

- [Typed settings](../typed-settings/) explains the settings handler behind the settings route.
- [Connection tests](../connection-tests/) shows the ready-made way to check credentials from the settings page, which saves you writing a route for it.
- [The plugin class](../../core/plugin-class/) covers `init_plugin()` and the other hooks.
