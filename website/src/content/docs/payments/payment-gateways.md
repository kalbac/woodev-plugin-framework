---
title: Payment gateways (legacy)
description: Learn how the legacy payment gateway layer is put together, what each base class requires and where its hook names come from.
---

In this page you will learn the parts of the legacy payment gateway layer: the plugin class, the three gateway base classes, the feature constants and the hook names, with a minimal skeleton to start from.

:::caution[Legacy API]
There is no v2 reference plugin for payments yet, and the layer works with the **classic checkout only**. It has no payment integration for the block checkout. This page will be rewritten after the first v2 payment plugin. Until then, treat it as a map of what exists, not as a recommendation for new work.
:::

## The plugin class

A payment plugin extends `Woodev_Payment_Gateway_Plugin`, which itself extends `\Woodev\Framework\Woocommerce_Plugin`. Everything in [The plugin class](../../core/plugin-class/) applies. The extra arguments go into the third constructor argument, next to the usual ones.

| Argument | Type | What it does |
|---|---|---|
| `gateways` | array | Gateway ID mapped to gateway class name. A plugin may offer several gateways. A credit card gateway must be listed first, because the first one is the default. |
| `currencies` | array | Currency codes the plugin accepts. Empty means all. A gateway takes this list as its own default. |
| `supports` | array | Plugin-level features, listed below. |

The plugin registers its gateways with WooCommerce for you, and it creates each gateway object only when it is needed.

Plugin-level features are constants on `Woodev_Payment_Gateway_Plugin`:

| Constant | Value | Adds |
|---|---|---|
| `FEATURE_CUSTOMER_ID` | `customer_id` | A "Customer ID" area on the admin user profile. |
| `FEATURE_CAPTURE_CHARGE` | `capture_charge` | Capture of authorization-only transactions from the admin order screen. |
| `FEATURE_MY_PAYMENT_METHODS` | `my_payment_methods` | A "My Payment Methods" area on My Account, for gateways that support tokenization. |

## The gateway classes

Every gateway extends `Woodev_Payment_Gateway`, through one of two subclasses. The constructor takes the gateway ID, your plugin object and an array of arguments.

| Class | Use it when | You must provide |
|---|---|---|
| `Woodev_Payment_Gateway` | Neither pattern fits, for example a transfer gateway that makes no remote call. | `get_method_form_fields()`: the settings fields of this gateway. |
| `Woodev_Payment_Gateway_Direct` | The shop talks to the payment provider itself, for example with a card form. | `get_method_form_fields()`, and `get_api()`: an object that implements `Woodev_Payment_Gateway_API`. |
| `Woodev_Payment_Gateway_Hosted` | The customer pays on the provider's page, or in a frame, and the provider reports back. | `get_method_form_fields()`, `get_hosted_pay_page_url( $order = null )` and `get_transaction_response( $request_response_data )`. |

A hosted gateway listens for the provider's reply at a WooCommerce API address built from its class name: `wc-api=` followed by the class name, for example `?wc-api=My_Payments_Gateway`. `get_transaction_response()` receives the request data and must return an object that implements `Woodev_Payment_Gateway_API_Payment_Notification_Response`. Override `get_hosted_pay_page_params( $order )` to add the parameters that go with the redirect.

These gateway arguments are optional:

| Argument | What it sets |
|---|---|
| `method_title`, `method_description` | The names shown in the WooCommerce payment settings. |
| `payment_type` | `PAYMENT_TYPE_CREDIT_CARD` (the default), `PAYMENT_TYPE_MULTIPLE`, `PAYMENT_TYPE_BANK_TRANSFER` or `PAYMENT_TYPE_LOANS`. |
| `supports` | The gateway features, listed below. |
| `card_types` | The card types the gateway can accept. |
| `environments` | Environment ID to display name. `ENVIRONMENT_PRODUCTION` and `ENVIRONMENT_TEST` are the common IDs. |
| `countries`, `currencies` | Where the gateway is offered. `currencies` defaults to the plugin's list. |
| `shared_settings` | Settings shared between the gateways of one plugin. |
| `order_button_text` | The text of the checkout button. |

## Gateway features

Gateway features are constants on `Woodev_Payment_Gateway`. Pass the ones you support in `supports`. A credit card gateway supports `charge` by default.

| Constant | Value | Means the gateway supports |
|---|---|---|
| `FEATURE_PRODUCTS` | `products` | Selling products. |
| `FEATURE_CARD_TYPES` | `card_types` | A choice of accepted card types. |
| `FEATURE_TOKENIZATION` | `tokenization` | Saved payment methods. |
| `FEATURE_CREDIT_CARD_CHARGE` | `charge` | Charging a card in one step. |
| `FEATURE_CREDIT_CARD_AUTHORIZATION` | `authorization` | Reserving funds first. |
| `FEATURE_CREDIT_CARD_CHARGE_VIRTUAL` | `charge-virtual` | Charging orders that contain only virtual products. |
| `FEATURE_CREDIT_CARD_CAPTURE` | `capture_charge` | Capturing a reserved amount later. |
| `FEATURE_CREDIT_CARD_PARTIAL_CAPTURE` | `partial_capture` | Capturing less than the reserved amount. |
| `FEATURE_DETAILED_CUSTOMER_DECLINE_MESSAGES` | `customer_decline_messages` | Showing the customer a detailed reason for a decline. |
| `FEATURE_REFUNDS` | `refunds` | Refunds from the admin order screen. |
| `FEATURE_VOIDS` | `voids` | Voiding a transaction. |
| `FEATURE_PAYMENT_FORM` | `payment_form` | The framework's payment form on the checkout. |
| `FEATURE_CUSTOMER_ID` | `customer_id` | Storing a customer ID with the provider. |
| `FEATURE_ADD_PAYMENT_METHOD` | `add_payment_method` | Adding a payment method from My Account. |
| `FEATURE_TOKEN_EDITOR` | `token_editor` | Editing saved methods in the admin user profile. |

## A minimal skeleton

This skeleton has a plugin class and one hosted gateway. The entry file is the one from [Your first plugin](../../getting-started/your-first-plugin/), with `main_class` set to `My_Payments_Plugin` and the constants renamed to `MY_PAYMENTS_FILE` and `MY_PAYMENTS_VERSION`. Load the gateway file from `init_plugin()`, after the parent constructor has run.

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * The My Payments plugin.
 */
final class My_Payments_Plugin extends \Woodev_Payment_Gateway_Plugin {

	/**
	 * The single instance.
	 *
	 * @var My_Payments_Plugin|null
	 */
	protected static $instance;

	/**
	 * Sets up the plugin and names its gateways.
	 */
	public function __construct() {
		parent::__construct(
			'my-payments',
			MY_PAYMENTS_VERSION,
			[
				'text_domain' => 'my-payments',
				'gateways'    => [
					'my_payments_card' => 'My_Payments_Gateway',
				],
				'currencies'  => [ 'RUB' ],
				'supports'    => [ self::FEATURE_CAPTURE_CHARGE ],
			]
		);
	}

	/**
	 * Gets the single instance.
	 *
	 * @return My_Payments_Plugin
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
		return MY_PAYMENTS_FILE;
	}

	/**
	 * Gets the plugin's display name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return 'My Payments';
	}

	/**
	 * Loads the gateway class once the framework classes are available.
	 *
	 * @return void
	 */
	public function init_plugin(): void {
		require_once plugin_dir_path( MY_PAYMENTS_FILE ) . 'includes/class-my-payments-gateway.php';
	}
}
```

The gateway lives in `includes/class-my-payments-gateway.php`:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * A gateway that sends the customer to the provider's payment page.
 */
class My_Payments_Gateway extends \Woodev_Payment_Gateway_Hosted {

	/**
	 * Sets up the gateway.
	 */
	public function __construct() {
		parent::__construct(
			'my_payments_card',
			My_Payments_Plugin::instance(),
			[
				'method_title'       => __( 'My Payments', 'my-payments' ),
				'method_description' => __( 'Pay on the My Payments page.', 'my-payments' ),
				'supports'           => [ self::FEATURE_REFUNDS ],
				'environments'       => [
					self::ENVIRONMENT_PRODUCTION => __( 'Production', 'my-payments' ),
					self::ENVIRONMENT_TEST       => __( 'Test', 'my-payments' ),
				],
			]
		);
	}

	/**
	 * Gets the settings fields of this gateway.
	 *
	 * @return array
	 */
	protected function get_method_form_fields() {
		return [
			'shop_id' => [
				'title' => __( 'Shop ID', 'my-payments' ),
				'type'  => 'text',
			],
		];
	}

	/**
	 * Gets the address of the provider's payment page.
	 *
	 * @param \WC_Order|null $order The order being paid.
	 * @return string
	 */
	public function get_hosted_pay_page_url( $order = null ) {
		return 'https://pay.example.com/checkout';
	}

	/**
	 * Wraps the provider's reply in a response object.
	 *
	 * @param array $request_response_data The request data the provider sent.
	 * @return \Woodev_Payment_Gateway_API_Payment_Notification_Response
	 */
	protected function get_transaction_response( $request_response_data ) {
		return new My_Payments_Notification( $request_response_data );
	}
}
```

`My_Payments_Notification` is your own class. It implements `Woodev_Payment_Gateway_API_Payment_Notification_Response` and tells the framework which order the reply is for and whether the payment succeeded.

## Where the hook names come from

A gateway fires hooks named after its own ID, so two gateways in one site never share a hook:

- `wc_payment_gateway_{gateway_id}_…` for the order and transaction hooks, such as `wc_payment_gateway_my_payments_card_payment_processed`.
- `wc_{gateway_id}_…` for the payment form, held orders and card types.
- `wc_{plugin_id}_…` and `wc_payment_gateway_{plugin_id}_…` for plugin-wide screens, such as My Payment Methods and the user profile.

The most useful ones are listed under Payments in the [Hooks & filters](../../reference/hooks-and-filters/#payments-legacy) reference.

## Related

- [The plugin class](../../core/plugin-class/) covers the constructor arguments every plugin shares.
- [Calling external APIs](../../core/calling-external-apis/) describes the API client that a direct gateway's `get_api()` can build on.
