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
| `Woodev_Payment_Gateway` | `payment-gateway/abstract-payment-gateway.php` | Base gateway class |
| `Woodev_Payment_Gateway_Admin_Order` | `payment-gateway/class-admin-order-handler.php` | Admin order handler |
| `Woodev_Payment_Gateway_Admin_User_Handler` | `payment-gateway/class-admin-user-handler.php` | Admin user handler |
| `Woodev_Payment_Gateway_My_Payment_Methods` | `payment-gateway/class-my-payment-methods.php` | Customer payment methods |

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
                    'credit_card' => 'My_Credit_Card_Gateway',
                    'bank_transfer' => 'My_Bank_Transfer_Gateway',
                ],
                'currencies'  => [ 'USD', 'EUR', 'GBP' ],
                'supports'    => [
                    'tokenization',
                    'transaction_link',
                    'customer_id',
                    'capture_charge',
                ],
                'require_ssl' => true,
            ]
        );
    }

    public function get_file(): string {
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

### Credit Card Gateway

```php
<?php

class My_Credit_Card_Gateway extends Woodev_Payment_Gateway {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'my_credit_card';
        $this->icon               = apply_filters( 'woocommerce_my_credit_card_icon', '' );
        $this->has_fields         = true;
        $this->method_title       = __( 'Credit Card', 'my-payment' );
        $this->method_description = __( 'Accept credit card payments', 'my-payment' );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->enabled      = $this->get_option( 'enabled' );
        $this->test_mode    = $this->get_option( 'test_mode' ) === 'yes';
        $this->api_key      = $this->get_option( 'api_key' );
        $this->api_secret   = $this->get_option( 'api_secret' );

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'payment_scripts' ] );

        // Tokenization
        $this->supports = [
            'products',
            'tokenization',
        ];
    }

    /**
     * Settings fields
     */
    public function init_form_fields(): array {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable/Disable', 'my-payment' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable credit card payments', 'my-payment' ),
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __( 'Title', 'my-payment' ),
                'type'        => 'text',
                'description' => __( 'Title shown to customers', 'my-payment' ),
                'default'     => __( 'Credit Card', 'my-payment' ),
            ],
            'description' => [
                'title'       => __( 'Description', 'my-payment' ),
                'type'        => 'textarea',
                'description' => __( 'Description shown to customers', 'my-payment' ),
                'default'     => __( 'Pay securely with your credit card', 'my-payment' ),
            ],
            'test_mode' => [
                'title'   => __( 'Test Mode', 'my-payment' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable test mode', 'my-payment' ),
                'default' => 'yes',
            ],
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
     * Payment form on checkout
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        $this->tokenization_script();
        $this->saved_payment_methods();

        ?>
        <fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form">
            <div class="form-row form-row-wide">
                <label><?php esc_html_e( 'Card Number', 'my-payment' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" placeholder="•••• •••• •••• ••••"
                       id="<?php echo esc_attr( $this->id ); ?>-card-number" />
            </div>

            <div class="form-row form-row-first">
                <label><?php esc_html_e( 'Expiry Date', 'my-payment' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" placeholder="MM / YY"
                       id="<?php echo esc_attr( $this->id ); ?>-card-expiry" />
            </div>

            <div class="form-row form-row-last">
                <label><?php esc_html_e( 'CVV', 'my-payment' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" placeholder="123"
                       id="<?php echo esc_attr( $this->id ); ?>-card-cvv" />
            </div>

            <div class="clear"></div>
        </fieldset>
        <?php
    }

    /**
     * Process payment
     */
    public function process_payment( int $order_id ): array {
        $order = wc_get_order( $order_id );

        try {
            // Get payment token
            $token = $this->get_payment_token( $order );

            // Process payment via API
            $result = $this->process_api_payment( $order, $token );

            if ( $result['success'] ) {
                // Payment successful
                $order->payment_complete( $result['transaction_id'] );

                // Add transaction data
                $order->update_meta_data( '_transaction_id', $result['transaction_id'] );
                $order->update_meta_data( '_payment_method_title', $this->get_title() );

                // Save token if requested
                if ( ! empty( $_POST[ 'wc-' . $this->id . '-new-payment-method' ] ) ) {
                    $this->save_token( $order->get_customer_id(), $token );
                }

                // Empty cart
                WC()->cart->empty_cart();

                // Return result
                return [
                    'result'   => 'success',
                    'redirect' => $this->get_return_url( $order ),
                ];
            } else {
                throw new Exception( $result['message'] ?? __( 'Payment failed', 'my-payment' ) );
            }

        } catch ( Exception $e ) {
            wc_add_notice( $e->getMessage(), 'error' );
            return [ 'result' => 'failure' ];
        }
    }

    /**
     * Process API payment
     */
    private function process_api_payment( WC_Order $order, $token ): array {
        $api = $this->get_api_client();

        $payment_data = [
            'amount'      => $order->get_total(),
            'currency'    => $order->get_currency(),
            'token'       => $token,
            'customer_id' => $order->get_customer_id(),
            'order_id'    => $order->get_id(),
        ];

        return $api->charge( $payment_data );
    }

    /**
     * Enqueue payment scripts
     */
    public function payment_scripts(): void {
        if ( ! is_checkout() ) {
            return;
        }

        wp_enqueue_script(
            'my-payment-gateway',
            plugins_url( 'assets/js/gateway.js', __FILE__ ),
            [ 'jquery' ],
            '1.0.0',
            true
        );

        wp_localize_script( 'my-payment-gateway', 'my_payment_params', [
            'key'       => $this->test_mode ? 'test_key' : 'live_key',
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
        ] );
    }
}
```

### Bank Transfer Gateway

```php
<?php

class My_Bank_Transfer_Gateway extends WC_Payment_Gateway_BACS {

    public function __construct() {
        parent::__construct();

        $this->id                 = 'my_bank_transfer';
        $this->method_title       = __( 'My Bank Transfer', 'my-payment' );
        $this->method_description = __( 'Make a bank transfer payment', 'my-payment' );
    }
}
```

## Transaction Handling

### Capture Charge

```php
class My_Payment_Plugin extends Woodev_Payment_Gateway_Plugin {

    public function __construct() {
        parent::__construct(
            'my-payment',
            '1.0.0',
            [
                'supports' => [
                    'capture_charge',
                    // ...
                ],
            ]
        );
    }
}

// Admin order handler will add capture button
$gateway = new My_Credit_Card_Gateway();
```

### Manual Capture

```php
class Admin_Order_Handler {

    public function capture_charge( int $order_id ): bool {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return false;
        }

        $transaction_id = $order->get_transaction_id();

        if ( ! $transaction_id ) {
            return false;
        }

        $api = My_Payment_Plugin::instance()->get_gateway( 'credit_card' )->get_api_client();

        $result = $api->capture( $transaction_id, $order->get_total() );

        if ( $result['success'] ) {
            $order->add_order_note( __( 'Charge captured', 'my-payment' ) );
            return true;
        }

        return false;
    }
}
```

## Tokenization

### Save Payment Token

```php
class My_Credit_Card_Gateway extends Woodev_Payment_Gateway {

    /**
     * Save token
     */
    public function save_token( int $user_id, $token_data ): ?WC_Payment_Token {
        $token = new WC_Payment_Token_CC();

        $token->set_token( $token_data['token'] );
        $token->set_gateway_id( $this->id );
        $token->set_card_type( $token_data['card_type'] );
        $token->set_last4( $token_data['last4'] );
        $token->set_expiry_month( $token_data['expiry_month'] );
        $token->set_expiry_year( $token_data['expiry_year'] );
        $token->set_user_id( $user_id );

        if ( $token->validate() ) {
            $token->save();
            return $token;
        }

        return null;
    }

    /**
     * Get customer tokens
     */
    public function get_customer_tokens( int $user_id ): array {
        return WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );
    }

    /**
     * Delete token
     */
    public function delete_token( int $token_id ): bool {
        $token = WC_Payment_Tokens::get( $token_id );

        if ( $token && $token->get_gateway_id() === $this->id ) {
            $token->delete();
            return true;
        }

        return false;
    }
}
```

## Admin Handlers

### Order Handler

```php
class My_Admin_Order_Handler extends Woodev_Payment_Gateway_Admin_Order {

    /**
     * Add transaction link
     */
    public function get_transaction_url( WC_Order $order ): string {
        $transaction_id = $order->get_transaction_id();

        if ( ! $transaction_id ) {
            return '';
        }

        return sprintf(
            'https://dashboard.mypayment.com/transactions/%s',
            $transaction_id
        );
    }

    /**
     * Add meta box
     */
    public function add_meta_box( WC_Order $order ): void {
        $transaction_id = $order->get_meta( '_transaction_id' );
        $payment_status = $order->get_meta( '_payment_status' );

        ?>
        <div class="wc-order-meta-box-content">
            <p>
                <strong><?php esc_html_e( 'Transaction ID:', 'my-payment' ); ?></strong>
                <?php echo esc_html( $transaction_id ); ?>
            </p>
            <p>
                <strong><?php esc_html_e( 'Payment Status:', 'my-payment' ); ?></strong>
                <?php echo esc_html( $payment_status ); ?>
            </p>
        </div>
        <?php
    }
}
```

### User Handler

```php
class My_Admin_User_Handler extends Woodev_Payment_Gateway_Admin_User_Handler {

    /**
     * Add customer ID field
     */
    public function add_customer_id_field( int $user_id ): void {
        $customer_id = get_user_meta( $user_id, '_my_payment_customer_id', true );

        ?>
        <tr>
            <th><label for="my_payment_customer_id"><?php esc_html_e( 'Payment Customer ID', 'my-payment' ); ?></label></th>
            <td>
                <input type="text" id="my_payment_customer_id" name="my_payment_customer_id"
                       value="<?php echo esc_attr( $customer_id ); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e( 'Customer ID from payment gateway', 'my-payment' ); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Save customer ID
     */
    public function save_customer_id_field( int $user_id ): void {
        if ( isset( $_POST['my_payment_customer_id'] ) ) {
            update_user_meta(
                $user_id,
                '_my_payment_customer_id',
                sanitize_text_field( $_POST['my_payment_customer_id'] )
            );
        }
    }
}
```

## My Payment Methods

### Customer Payment Methods Page

```php
class My_Payment_Methods extends Woodev_Payment_Gateway_My_Payment_Methods {

    /**
     * Initialize
     */
    public function init(): void {
        parent::init();

        add_action( 'woocommerce_account_payment-methods_endpoint', [ $this, 'add_custom_content' ] );
    }

    /**
     * Add custom content
     */
    public function add_custom_content(): void {
        $tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id() );

        if ( empty( $tokens ) ) {
            return;
        }

        ?>
        <h2><?php esc_html_e( 'My Payment Methods', 'my-payment' ); ?></h2>

        <table class="woocommerce-MyAccount-payment-methods table shop_table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Type', 'my-payment' ); ?></th>
                    <th><?php esc_html_e( 'Details', 'my-payment' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'my-payment' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $tokens as $token ) : ?>
                    <tr>
                        <td><?php echo esc_html( $token->get_display_name() ); ?></td>
                        <td>
                            <?php
                            if ( $token instanceof WC_Payment_Token_CC ) {
                                printf(
                                    '%s •••• %s',
                                    esc_html( $token->get_card_type() ),
                                    esc_html( $token->get_last4() )
                                );
                            }
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( wc_get_endpoint_url( 'delete-payment-method', $token->get_id() ) ); ?>"
                               class="button"
                               onclick="return confirm('<?php esc_html_e( 'Are you sure?', 'my-payment' ); ?>')">
                                <?php esc_html_e( 'Delete', 'my-payment' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
```

## API Client

### Payment API

```php
class My_Payment_API extends Woodev_API_Base {

    const API_URL = 'https://api.mypayment.com';

    protected function get_plugin(): Woodev_Plugin {
        return My_Payment_Plugin::instance();
    }

    public function get_api_id(): string {
        return 'my_payment_api';
    }

    /**
     * Charge payment
     */
    public function charge( array $data ): array {
        $request = $this->get_new_request( [
            'method' => 'POST',
            'path'   => '/v1/charges',
            'body'   => json_encode( $data ),
        ] );

        $response = $this->perform_request( $request );

        return $this->get_parsed_response( $response );
    }

    /**
     * Capture charge
     */
    public function capture( string $transaction_id, float $amount ): array {
        $request = $this->get_new_request( [
            'method' => 'POST',
            'path'   => '/v1/charges/' . $transaction_id . '/capture',
            'body'   => json_encode( [ 'amount' => $amount ] ),
        ] );

        $response = $this->perform_request( $request );

        return $this->get_parsed_response( $response );
    }

    /**
     * Refund payment
     */
    public function refund( string $transaction_id, float $amount ): array {
        $request = $this->get_new_request( [
            'method' => 'POST',
            'path'   => '/v1/charges/' . $transaction_id . '/refund',
            'body'   => json_encode( [ 'amount' => $amount ] ),
        ] );

        $response = $this->perform_request( $request );

        return $this->get_parsed_response( $response );
    }

    /**
     * Get transaction
     */
    public function get_transaction( string $transaction_id ): array {
        $request = $this->get_new_request( [
            'method' => 'GET',
            'path'   => '/v1/transactions/' . $transaction_id,
        ] );

        $response = $this->perform_request( $request );

        return $this->get_parsed_response( $response );
    }
}
```

## Complete Example

```php
<?php
/**
 * Main plugin file
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'init_my_payment', 0 );

function init_my_payment() {
    Woodev_Plugin_Bootstrap::instance()->register_plugin(
        '1.4.0',
        'My Payment Gateway',
        __FILE__,
        'my_payment_init',
        [
            'is_payment_gateway' => true,
        ]
    );
}

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
public function process_payment( int $order_id ): array {
    $this->plugin->log( 'Processing payment for order #' . $order_id );

    try {
        $result = $this->api->charge( $data );
        $this->plugin->log( 'Payment successful: ' . $result['transaction_id'] );
    } catch ( Exception $e ) {
        $this->plugin->log( 'Payment failed: ' . $e->getMessage() );
        throw $e;
    }
}
```

### 3. Handle Errors Gracefully

```php
try {
    $result = $api->charge( $data );
} catch ( Woodev_API_Exception $e ) {
    wc_add_notice( __( 'Payment failed. Please try again.', 'my-payment' ), 'error' );
    return [ 'result' => 'failure' ];
}
```

### 4. Support Tokenization

```php
$this->supports = [
    'products',
    'tokenization',
];
```

### 5. Add Transaction Links

```php
public function get_transaction_url( WC_Order $order ): string {
    return 'https://dashboard.mypayment.com/tx/' . $order->get_transaction_id();
}
```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [REST API](rest-api.md) — REST endpoints
- [Admin Module](admin-module.md) — Admin pages

---

*For more information, see [README.md](README.md).*
