# Payment Gateway Module

The Payment Gateway module provides the infrastructure for building WooCommerce payment gateway plugins. It includes base classes for payment plugins, gateways, and transaction handling.

## Overview

The Payment Gateway module handles:

- Payment gateway plugin base class
- Gateway registration and management
- Transaction processing
- Tokenization support
- Admin order and user handlers
- My Payment Methods integration

## Key Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Woodev_Payment_Gateway_Plugin` | `payment-gateway/class-payment-gateway-plugin.php` | Base payment plugin class |
| `Woodev_Payment_Gateway` | `payment-gateway/class-payment-gateway.php` | Abstract base gateway class |
| `Woodev_Payment_Gateway_Direct` | `payment-gateway/class-payment-gateway-direct.php` | Direct (onsite) gateway class |
| `Woodev_Payment_Gateway_Hosted` | `payment-gateway/class-payment-gateway-hosted.php` | Hosted (offsite) gateway class |
| `Woodev_Payment_Gateway_Admin_Order` | `payment-gateway/admin/class-payment-gateway-admin-order.php` | Admin order handler |
| `Woodev_Payment_Gateway_Admin_User_Handler` | `payment-gateway/admin/class-payment-gateway-admin-user-handler.php` | Admin user handler |
| `Woodev_Payment_Gateway_My_Payment_Methods` | `payment-gateway/class-payment-gateway-my-payment-methods.php` | Customer payment methods |
| `Woodev_Payment_Gateway_Payment_Form` | `payment-gateway/class-payment-gateway-payment-form.php` | Checkout payment form |
| `Woodev_Payment_Gateway_Payment_Token` | `payment-gateway/payment-tokens/class-payment-gateway-payment-token.php` | Payment token representation |
| `Woodev_Payment_Gateway_Payment_Tokens_Handler` | `payment-gateway/payment-tokens/class-payment-gateway-payment-tokens-handler.php` | Token storage and retrieval |

## Creating a Payment Plugin

### Basic Plugin Structure

```php
<?php
/**
 * Plugin Name: My Payment Gateway
 * Plugin URI: https://example.com/my-payment
 * Description: WooCommerce payment gateway
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: my-payment
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * WC requires at least: 5.6
 */

defined( 'ABSPATH' ) || exit;

// Include bootstrap
if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'woodev/bootstrap.php';
}

// Register plugin
add_action( 'plugins_loaded', 'init_my_payment', 0 );

function init_my_payment() {
    Woodev_Plugin_Bootstrap::instance()->register_plugin(
        '1.4.0',
        'My Payment Gateway',
        __FILE__,
        'my_payment_init',
        [
            'minimum_wc_version' => '8.0',
            'minimum_wp_version' => '5.9',
            'is_payment_gateway' => true,  // Important!
        ]
    );
}

function my_payment_init() {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-my-payment-plugin.php';
    return My_Payment_Plugin::instance();
}
```

### Plugin Class

```php
<?php

defined( 'ABSPATH' ) || exit;

final class My_Payment_Plugin extends Woodev_Payment_Gateway_Plugin {

    private static $instance;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        parent::__construct(
            'my-payment',
            '1.0.0',
            [
                'text_domain' => 'my-payment',
                'gateways'    => [
                    'my_credit_card' => 'My_Credit_Card_Gateway',
                ],
                'currencies'  => [ 'USD', 'EUR', 'GBP' ],
                'supports'    => [
                    Woodev_Payment_Gateway_Plugin::FEATURE_CAPTURE_CHARGE,
                    Woodev_Payment_Gateway_Plugin::FEATURE_CUSTOMER_ID,
                    Woodev_Payment_Gateway_Plugin::FEATURE_MY_PAYMENT_METHODS,
                ],
                'require_ssl' => true,
            ]
        );
    }

    protected function get_file(): string {
        return __FILE__;
    }

    public function get_plugin_name(): string {
        return __( 'My Payment Gateway', 'my-payment' );
    }

    public function get_download_id(): int {
        return 0;
    }
}
```

## Creating Payment Gateways

Gateways extend `Woodev_Payment_Gateway_Direct` (for onsite payment processing) or `Woodev_Payment_Gateway_Hosted` (for offsite/redirect payment processing). Both inherit from `Woodev_Payment_Gateway`, which extends `WC_Payment_Gateway`.

The gateway constructor signature is `__construct( $id, $plugin, $args )`. The framework handles form fields, settings, and payment form rendering automatically. You provide gateway-specific settings by overriding `get_method_form_fields()`.

### Credit Card Gateway

```php
<?php

class My_Credit_Card_Gateway extends Woodev_Payment_Gateway_Direct {

    /**
     * Returns gateway-specific form fields.
     *
     * These are merged with common fields (enabled, title, description,
     * environment, debug mode) that the framework provides automatically.
     *
     * @since 1.0.0
     *
     * @return array
     */
    protected function get_method_form_fields() {
        return [
            'api_key' => [
                'title'       => __( 'API Key', 'my-payment' ),
                'type'        => 'text',
                'description' => __( 'Your API key', 'my-payment' ),
                'default'     => '',
            ],
            'api_secret' => [
                'title'       => __( 'API Secret', 'my-payment' ),
                'type'        => 'password',
                'description' => __( 'Your API secret', 'my-payment' ),
                'default'     => '',
            ],
        ];
    }

    /**
     * Returns the API instance for this gateway.
     *
     * @since 1.0.0
     *
     * @return My_Payment_API
     */
    public function get_api() {
        if ( ! isset( $this->api ) ) {
            $this->api = new My_Payment_API( $this );
        }

        return $this->api;
    }
}
```

> **Note:** The framework's `Woodev_Payment_Gateway` base class already provides `payment_fields()` rendering via `Woodev_Payment_Gateway_Payment_Form`, and `Woodev_Payment_Gateway_Direct` already implements `process_payment()` with full transaction handling, token management, and error handling. You do not need to override these unless you require custom behavior.

## Transaction Handling

### Capture Charge

To enable capture charge support, include `Woodev_Payment_Gateway_Plugin::FEATURE_CAPTURE_CHARGE` in the plugin's `supports` array. The framework's `Woodev_Payment_Gateway_Admin_Order` class automatically adds a capture button to the admin order screen when this feature is enabled.

```php
<?php
parent::__construct(
    'my-payment',
    '1.0.0',
    [
        'gateways' => [
            'my_credit_card' => 'My_Credit_Card_Gateway',
        ],
        'supports' => [
            Woodev_Payment_Gateway_Plugin::FEATURE_CAPTURE_CHARGE,
        ],
    ]
);
```

### Manual Capture

The `Woodev_Payment_Gateway_Admin_Order` handles capture via AJAX automatically. To capture programmatically, use the gateway's API instance:

```php
<?php
$gateway = My_Payment_Plugin::instance()->get_gateway( 'my_credit_card' );
$api     = $gateway->get_api();

try {
    $response = $api->capture( $transaction_id, $order->get_total() );
    $order->add_order_note( __( 'Charge captured', 'my-payment' ) );
} catch ( Woodev_API_Exception $e ) {
    $order->add_order_note(
        sprintf( __( 'Capture failed: %s', 'my-payment' ), $e->getMessage() )
    );
}
```

## Tokenization

The framework uses its own tokenization system through `Woodev_Payment_Gateway_Payment_Token` and `Woodev_Payment_Gateway_Payment_Tokens_Handler` (not WooCommerce's `WC_Payment_Token` classes). Tokens are stored in user meta, keyed by environment.

Enable tokenization by adding `Woodev_Payment_Gateway::FEATURE_TOKENIZATION` to your gateway's supported features.

### Working with Tokens

```php
<?php
// Get the token handler from the gateway.
$gateway       = My_Payment_Plugin::instance()->get_gateway( 'my_credit_card' );
$token_handler = $gateway->get_payment_tokens_handler();

// Retrieve all tokens for a user in the current environment.
$tokens = $token_handler->get_tokens( $user_id );

// Add a new token.
$token = new Woodev_Payment_Gateway_Payment_Token( $remote_token_id, [
    'type'      => 'credit_card',
    'last_four' => '4242',
    'card_type' => 'visa',
    'exp_month' => '12',
    'exp_year'  => '2028',
] );

$token_handler->add_token( $user_id, $token );

// Update an existing token.
$token_handler->update_token( $user_id, $token );

// Delete a token.
$token_handler->delete_token( $user_id, $token );
```

## Admin Handlers

### Order Handler

`Woodev_Payment_Gateway_Admin_Order` is instantiated internally by `Woodev_Payment_Gateway_Plugin` and is not meant to be extended. It automatically provides:

- Capture charge button (when `FEATURE_CAPTURE_CHARGE` is supported)
- Bulk capture order action
- AJAX capture processing

The handler receives the plugin instance via its constructor:

```php
<?php
// The framework instantiates this automatically in Woodev_Payment_Gateway_Plugin::init_admin().
// Access it via the plugin:
$admin_order_handler = My_Payment_Plugin::instance()->get_admin_order_handler();
```

### User Handler

`Woodev_Payment_Gateway_Admin_User_Handler` is also instantiated internally. It automatically provides:

- Customer ID fields on the admin user profile page (when `FEATURE_CUSTOMER_ID` is supported)
- Token editor for each tokenized gateway
- Save/update hooks for profile fields

```php
<?php
// The framework instantiates this automatically in Woodev_Payment_Gateway_Plugin::init_admin().
// Access it via the plugin:
$admin_user_handler = My_Payment_Plugin::instance()->get_admin_user_handler();
```

## My Payment Methods

`Woodev_Payment_Gateway_My_Payment_Methods` extends `Woodev_Script_Handler` and renders the My Payment Methods table on the My Account page. It is initialized automatically when `FEATURE_MY_PAYMENT_METHODS` is included in the plugin's `supports` array.

The class receives the plugin instance via its constructor and handles:

- Loading and displaying saved tokens for all tokenized gateways
- Token editing (nickname) via AJAX
- Token deletion
- Setting a default payment method
- Enqueuing required scripts and styles

### Customizing My Payment Methods

To customize, override `get_my_payment_methods_instance()` in your plugin class:

```php
<?php
class My_Payment_Plugin extends Woodev_Payment_Gateway_Plugin {

    /**
     * Returns the My Payment Methods instance.
     *
     * @since 1.0.0
     *
     * @return My_Payment_Methods
     */
    protected function get_my_payment_methods_instance() {
        return new My_Payment_Methods( $this );
    }
}
```

```php
<?php
class My_Payment_Methods extends Woodev_Payment_Gateway_My_Payment_Methods {

    /**
     * Customizes the table headers.
     *
     * @since 1.0.0
     *
     * @return array
     */
    protected function get_table_headers() {

        $headers = parent::get_table_headers();

        // Add a custom column.
        $headers['custom'] = __( 'Status', 'my-payment' );

        return $headers;
    }
}
```

## API Client

The framework provides `Woodev_API_Base` as the abstract base for all API communication. Key points:

- `get_plugin()` is abstract and must return the plugin instance
- `get_new_request( $args )` is abstract and must return a `Woodev_API_Request` instance
- `perform_request( $request )` is protected and returns a `Woodev_API_Response` object (not raw data)
- `get_api_id()` is not abstract; by default it returns the plugin ID
- Request/response logging is handled automatically via the `woodev_{plugin_id}_api_request_performed` action

### Payment API

```php
<?php
class My_Payment_API extends Woodev_API_Base {

    const API_URL = 'https://api.mypayment.com';

    /** @var Woodev_Payment_Gateway gateway instance */
    private $gateway;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param My_Credit_Card_Gateway $gateway the gateway instance
     */
    public function __construct( $gateway ) {
        $this->gateway = $gateway;

        $this->set_request_content_type_header( 'application/json' );
        $this->set_request_accept_header( 'application/json' );
    }

    /**
     * Returns the plugin instance.
     *
     * @since 1.0.0
     *
     * @return Woodev_Payment_Gateway_Plugin
     */
    protected function get_plugin() {
        return $this->gateway->get_plugin();
    }

    /**
     * Returns a new request object.
     *
     * @since 1.0.0
     *
     * @param array $args request arguments
     * @return My_Payment_API_Request
     */
    protected function get_new_request( $args = [] ) {
        return new My_Payment_API_Request( self::API_URL, $args );
    }

    /**
     * Charge payment.
     *
     * @since 1.0.0
     *
     * @param array $data payment data
     * @return Woodev_API_Response
     * @throws Woodev_API_Exception on API error
     */
    public function charge( array $data ) {
        $request = $this->get_new_request( [
            'method' => 'POST',
            'path'   => '/v1/charges',
        ] );

        $request->set_data( $data );

        return $this->perform_request( $request );
    }

    /**
     * Capture a previously authorized charge.
     *
     * @since 1.0.0
     *
     * @param string $transaction_id transaction identifier
     * @param float  $amount amount to capture
     * @return Woodev_API_Response
     * @throws Woodev_API_Exception on API error
     */
    public function capture( string $transaction_id, float $amount ) {
        $request = $this->get_new_request( [
            'method' => 'POST',
            'path'   => '/v1/charges/' . $transaction_id . '/capture',
        ] );

        $request->set_data( [ 'amount' => $amount ] );

        return $this->perform_request( $request );
    }
}
```

## Complete Example

```php
<?php
/**
 * Plugin Name: My Payment Gateway
 * Version: 1.0.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

// Include the framework bootstrap.
if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'woodev/bootstrap.php';
}

// Register plugin with the framework bootstrap.
Woodev_Plugin_Bootstrap::instance()->register_plugin(
    '1.4.0',
    'My Payment Gateway',
    __FILE__,
    'my_payment_init',
    [
        'minimum_wc_version' => '8.0',
        'minimum_wp_version' => '5.9',
        'is_payment_gateway' => true,
    ]
);

/**
 * Initializes the plugin.
 *
 * @return My_Payment_Plugin
 */
function my_payment_init() {
    require_once __DIR__ . '/includes/class-my-payment-plugin.php';
    require_once __DIR__ . '/includes/class-my-credit-card-gateway.php';
    require_once __DIR__ . '/includes/class-my-payment-api.php';

    return My_Payment_Plugin::instance();
}
```

## Best Practices

### 1. Use SSL for Production

```php
<?php
parent::__construct(
    'my-payment',
    '1.0.0',
    [
        'require_ssl' => true,
    ]
);
```

### 2. Log All Transactions

```php
<?php
// Use the plugin's log() method. It writes to WooCommerce logs.
$this->get_plugin()->log( 'Processing payment for order #' . $order_id );

try {
    $response = $this->get_api()->charge( $data );
    $this->get_plugin()->log( 'Payment successful' );
} catch ( Woodev_API_Exception $e ) {
    $this->get_plugin()->log( 'Payment failed: ' . $e->getMessage() );
    throw $e;
}
```

### 3. Handle Errors Gracefully

```php
<?php
try {
    $response = $api->charge( $data );
} catch ( Woodev_API_Exception $e ) {
    wc_add_notice( __( 'Payment failed. Please try again.', 'my-payment' ), 'error' );
    return [ 'result' => 'failure' ];
}
```

### 4. Support Tokenization

Tokenization is enabled via the gateway constructor args, not the WC `$supports` array:

```php
<?php
// In your gateway constructor args, or by calling add_support():
$this->add_support( Woodev_Payment_Gateway::FEATURE_TOKENIZATION );
```

### 5. Add Transaction Links

Override `get_transaction_url()` (from `WC_Payment_Gateway`) in your gateway class, and include `'transaction_link'` in your plugin's `supports` array:

```php
<?php
// In your gateway class:
public function get_transaction_url( $order ) {
    $this->view_transaction_url = 'https://dashboard.mypayment.com/tx/%s';
    return parent::get_transaction_url( $order );
}
```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [REST API](rest-api.md) — REST endpoints
- [Admin Module](admin-module.md) — Admin pages

---

*For more information, see [README.md](README.md).*
