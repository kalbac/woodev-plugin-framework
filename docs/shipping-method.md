# Shipping Method Module

The Shipping Method module provides the infrastructure for building WooCommerce shipping plugins. It includes base classes for shipping plugins, shipping methods, and rate calculations.

## Overview

The Shipping Method module handles:

- Shipping plugin base class
- Shipping method registration
- Rate calculation
- Pickup points integration
- Order export and tracking
- Checkout integration

## Key Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Shipping_Plugin` | `shipping-method/class-shipping-plugin.php` | Base shipping plugin class |
| `Shipping_Method` | `shipping-method/class-shipping-method.php` | Base shipping method class |
| `Shipping_Method_Courier` | `shipping-method/class-shipping-method-courier.php` | Courier method |
| `Shipping_Method_Pickup` | `shipping-method/class-shipping-method-pickup.php` | Pickup method |
| `Shipping_Method_Postal` | `shipping-method/class-shipping-method-postal.php` | Postal method |
| `Shipping_Rate` | `shipping-method/class-shipping-rate.php` | Shipping rate class |
| `Shipping_Integration` | `shipping-method/settings/class-shipping-integration.php` | Settings integration |
| `Shipping_API` | `shipping-method/api/interface-shipping-api.php` | Shipping API interface |

## Creating a Shipping Plugin

### Basic Plugin Structure

```php
<?php
/**
 * Plugin Name: My Shipping
 * Plugin URI: https://example.com/my-shipping
 * Description: WooCommerce shipping plugin
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: my-shipping
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
add_action( 'plugins_loaded', 'init_my_shipping', 0 );

function init_my_shipping() {
    Woodev_Plugin_Bootstrap::instance()->register_plugin(
        '1.4.0',
        'My Shipping',
        __FILE__,
        'my_shipping_init',
        [
            'minimum_wc_version'   => '8.0',
            'minimum_wp_version'   => '5.9',
            'load_shipping_method' => true,  // Important!
        ]
    );
}

function my_shipping_init() {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-my-shipping.php';
    return My_Shipping_Plugin::instance();
}
```

### Plugin Class

```php
<?php

namespace Woodev\Framework\Shipping;

defined( 'ABSPATH' ) || exit;

final class My_Shipping_Plugin extends Shipping_Plugin {

    private static $instance;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        parent::__construct(
            'my-shipping',
            '1.0.0',
            [
                'text_domain' => 'my-shipping',
                'supports'    => [ 'tracking', 'pickup-points' ],
                'currencies'  => [ 'RUB', 'USD', 'EUR' ],
                'countries'   => [ 'RU', 'US', 'DE' ],
            ]
        );
    }

    /**
     * Get shipping method classes.
     *
     * @since 1.0.0
     *
     * @return class-string<Shipping_Method>[]
     */
    protected function get_shipping_method_classes(): array {
        return [
            My_Courier_Method::class,
            My_Pickup_Method::class,
            My_Postal_Method::class,
        ];
    }

    /**
     * Get API instance.
     *
     * @since 1.0.0
     *
     * @return Shipping_API|null
     */
    public function get_api(): ?Shipping_API {
        return new My_Shipping_API();
    }

    public function get_file(): string {
        return __FILE__;
    }

    public function get_plugin_name(): string {
        return __( 'My Shipping', 'my-shipping' );
    }

    public function get_download_id(): int {
        return 0;
    }
}
```

## Creating Shipping Methods

### Base Shipping Method

The base `Shipping_Method` class is abstract and extends `WC_Shipping_Method`. Its constructor
already calls `init_form_fields()`, `init_settings()`, and registers the admin options hook.
Subclasses must implement `get_method_id()` (static), `get_delivery_type()`, `calculate_rate()`,
`get_plugin()`, and `get_method_form_fields()`.

```php
<?php

namespace Woodev\Framework\Shipping;

defined( 'ABSPATH' ) || exit;

class My_Courier_Method extends Shipping_Method {

    /**
     * Gets the unique method identifier.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function get_method_id(): string {
        return 'my_courier';
    }

    /**
     * Gets the delivery type.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function get_delivery_type(): string {
        return self::TYPE_COURIER;
    }

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param int $instance_id Shipping method instance ID.
     */
    public function __construct( int $instance_id = 0 ) {

        $this->method_title       = __( 'Courier Delivery', 'my-shipping' );
        $this->method_description = __( 'Courier delivery service', 'my-shipping' );

        // Parent constructor sets $this->id from get_method_id(),
        // calls init_form_fields(), init_settings(), and registers
        // the woocommerce_update_options_shipping hook.
        parent::__construct( $instance_id );
    }

    /**
     * Returns method-specific form fields.
     *
     * These are merged into instance_form_fields by the parent init_form_fields().
     *
     * @since 1.0.0
     *
     * @return array
     */
    protected function get_method_form_fields(): array {
        return [
            'api_key' => [
                'title'       => __( 'API Key', 'my-shipping' ),
                'type'        => 'text',
                'description' => __( 'Your API key', 'my-shipping' ),
                'default'     => '',
            ],
        ];
    }

    /**
     * Gets the plugin instance that owns this shipping method.
     *
     * @since 1.0.0
     *
     * @return Shipping_Plugin
     */
    protected function get_plugin(): Shipping_Plugin {
        return My_Shipping_Plugin::instance();
    }

    /**
     * Calculate the shipping rate for this package.
     *
     * This is the method to implement -- calculate_shipping() is final
     * and calls this method internally.
     *
     * @since 1.0.0
     *
     * @param array $package WooCommerce package array.
     * @return Shipping_Rate|null Rate object, or null to add no rate.
     */
    protected function calculate_rate( array $package ): ?Shipping_Rate {
        $country  = $package['destination']['country'];
        $postcode = $package['destination']['postcode'];
        $weight   = WC()->cart->get_cart_contents_weight();

        $api = My_Shipping_Plugin::instance()->get_api();

        if ( ! $api ) {
            return null;
        }

        $response = $api->calculate_rates( [
            'origin'      => get_option( 'woocommerce_store_postcode' ),
            'destination' => $postcode,
            'packages'    => [
                [ 'weight' => $weight ],
            ],
            'currency'    => get_woocommerce_currency(),
        ] );

        // Build a Shipping_Rate from the API response
        return new Shipping_Rate(
            self::get_method_id(),
            self::get_method_id() . ':standard',
            __( 'Courier Delivery', 'my-shipping' ),
            (string) $response->get_cost(),
            false,
            [
                'delivery_time' => $response->get_delivery_time(),
            ]
        );
    }
}
```

### Courier Method

Using the `Shipping_Method_Courier` base class, `get_delivery_type()` is already implemented
(returns `TYPE_COURIER`). You must still implement `get_method_id()`, `calculate_rate()`,
`get_plugin()`, and `get_method_form_fields()`.

```php
<?php

namespace Woodev\Framework\Shipping;

class My_Courier_Method extends Shipping_Method_Courier {

    /**
     * Gets the unique method identifier.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function get_method_id(): string {
        return 'my_courier';
    }

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param int $instance_id Shipping method instance ID.
     */
    public function __construct( int $instance_id = 0 ) {

        $this->method_title       = __( 'Courier Delivery', 'my-shipping' );
        $this->method_description = __( 'Delivery by courier to your door', 'my-shipping' );

        parent::__construct( $instance_id );
    }

    /**
     * Returns method-specific form fields.
     *
     * @since 1.0.0
     *
     * @return array
     */
    protected function get_method_form_fields(): array {
        return [];
    }

    /**
     * Gets the plugin instance.
     *
     * @since 1.0.0
     *
     * @return Shipping_Plugin
     */
    protected function get_plugin(): Shipping_Plugin {
        return My_Shipping_Plugin::instance();
    }

    /**
     * Calculate the courier rate for this package.
     *
     * @since 1.0.0
     *
     * @param array $package WooCommerce package array.
     * @return Shipping_Rate|null
     */
    protected function calculate_rate( array $package ): ?Shipping_Rate {
        $api = My_Shipping_Plugin::instance()->get_api();

        if ( ! $api ) {
            return null;
        }

        $response = $api->calculate_rates( [
            'destination' => $package['destination']['postcode'],
            'packages'    => [ [ 'weight' => WC()->cart->get_cart_contents_weight() ] ],
            'currency'    => get_woocommerce_currency(),
        ] );

        return new Shipping_Rate(
            self::get_method_id(),
            self::get_method_id() . ':standard',
            __( 'Courier Delivery', 'my-shipping' ),
            (string) $response->get_cost()
        );
    }
}
```

### Pickup Method

Using the `Shipping_Method_Pickup` base class, `get_delivery_type()` is already implemented
(returns `TYPE_PICKUP`). You must still implement the same abstract methods.

```php
<?php

namespace Woodev\Framework\Shipping;

class My_Pickup_Method extends Shipping_Method_Pickup {

    /**
     * Gets the unique method identifier.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function get_method_id(): string {
        return 'my_pickup';
    }

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param int $instance_id Shipping method instance ID.
     */
    public function __construct( int $instance_id = 0 ) {

        $this->method_title       = __( 'Pickup Point', 'my-shipping' );
        $this->method_description = __( 'Pick up from our locations', 'my-shipping' );

        parent::__construct( $instance_id );
    }

    /**
     * Returns method-specific form fields.
     *
     * @since 1.0.0
     *
     * @return array
     */
    protected function get_method_form_fields(): array {
        return [];
    }

    /**
     * Gets the plugin instance.
     *
     * @since 1.0.0
     *
     * @return Shipping_Plugin
     */
    protected function get_plugin(): Shipping_Plugin {
        return My_Shipping_Plugin::instance();
    }

    /**
     * Calculate the pickup rate for this package.
     *
     * @since 1.0.0
     *
     * @param array $package WooCommerce package array.
     * @return Shipping_Rate|null
     */
    protected function calculate_rate( array $package ): ?Shipping_Rate {
        $api = My_Shipping_Plugin::instance()->get_api();

        if ( ! $api ) {
            return null;
        }

        $response = $api->calculate_rates( [
            'destination' => $package['destination']['postcode'],
            'packages'    => [ [ 'weight' => WC()->cart->get_cart_contents_weight() ] ],
            'currency'    => get_woocommerce_currency(),
        ] );

        return new Shipping_Rate(
            self::get_method_id(),
            self::get_method_id() . ':pickup',
            __( 'Pickup Point', 'my-shipping' ),
            (string) $response->get_cost()
        );
    }
}
```

### Postal Method

Using the `Shipping_Method_Postal` base class, `get_delivery_type()` is already implemented
(returns `TYPE_POSTAL`). The postal base also provides `is_available()` which checks for a
postal code when the `require_postal_code` setting is enabled.

```php
<?php

namespace Woodev\Framework\Shipping;

class My_Postal_Method extends Shipping_Method_Postal {

    /**
     * Gets the unique method identifier.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function get_method_id(): string {
        return 'my_postal';
    }

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param int $instance_id Shipping method instance ID.
     */
    public function __construct( int $instance_id = 0 ) {

        $this->method_title       = __( 'Postal Service', 'my-shipping' );
        $this->method_description = __( 'Delivery via national postal service', 'my-shipping' );

        parent::__construct( $instance_id );
    }

    /**
     * Returns method-specific form fields.
     *
     * @since 1.0.0
     *
     * @return array
     */
    protected function get_method_form_fields(): array {
        return [];
    }

    /**
     * Gets the plugin instance.
     *
     * @since 1.0.0
     *
     * @return Shipping_Plugin
     */
    protected function get_plugin(): Shipping_Plugin {
        return My_Shipping_Plugin::instance();
    }

    /**
     * Calculate the postal rate for this package.
     *
     * @since 1.0.0
     *
     * @param array $package WooCommerce package array.
     * @return Shipping_Rate|null
     */
    protected function calculate_rate( array $package ): ?Shipping_Rate {
        $api = My_Shipping_Plugin::instance()->get_api();

        if ( ! $api ) {
            return null;
        }

        $response = $api->calculate_rates( [
            'destination' => $package['destination']['postcode'],
            'packages'    => [ [ 'weight' => WC()->cart->get_cart_contents_weight() ] ],
            'currency'    => get_woocommerce_currency(),
        ] );

        return new Shipping_Rate(
            self::get_method_id(),
            self::get_method_id() . ':postal',
            __( 'Postal Service', 'my-shipping' ),
            (string) $response->get_cost()
        );
    }
}
```

## Shipping Rate Class

`Shipping_Rate` is an immutable value object. Its constructor signature is:

```
__construct( string $method_id, string $id, string $label, $cost = '0', $package = null, array $meta_data = [] )
```

All properties are private with getter methods. Use `to_array()` to convert to WooCommerce
format for `WC_Shipping_Method::add_rate()`.

### Creating Rates

```php
<?php
use Woodev\Framework\Shipping\Shipping_Rate;

$rate = new Shipping_Rate(
    'my_courier',                                // Method ID
    'my_courier:standard',                       // Unique rate ID
    __( 'Standard Delivery', 'my-shipping' ),    // Label
    '500.00',                                    // Cost (string)
    false,                                       // Package flag
    [
        'delivery_time' => '3-5 days',
        'carrier'       => 'My Carrier',
    ]
);

// The framework's calculate_shipping() calls $this->add_rate( $rate->to_array() )
// automatically, but if needed manually:
$this->add_rate( $rate->to_array() );

// Getter methods:
$rate->get_method_id(); // 'my_courier'
$rate->get_id();        // 'my_courier:standard'
$rate->get_label();     // 'Standard Delivery'
$rate->get_cost();      // '500.00'
$rate->get_meta_data(); // [ 'delivery_time' => '3-5 days', 'carrier' => 'My Carrier' ]
```

### Rate with Options

```php
<?php
use Woodev\Framework\Shipping\Shipping_Rate;

$rate = new Shipping_Rate(
    'my_courier',
    'my_courier:express',
    __( 'Express Delivery', 'my-shipping' ),
    '1000.00',
    false,
    [
        'delivery_time' => '1-2 days',
        'carrier'       => 'Express Carrier',
        'tracking'      => true,
    ]
);

// Immutable modifications -- returns a new instance:
$discounted = $rate->with_cost( '800.00' );
$enriched   = $rate->with_meta_data( [ 'promo' => 'SAVE20' ] );
```

## API Integration

### Shipping API Interface

The `Shipping_API` interface is defined in `shipping-method/api/interface-shipping-api.php`.
All shipping API implementations must conform to this contract. The actual interface methods
use typed response objects, not plain arrays.

```php
<?php

namespace Woodev\Framework\Shipping;

interface Shipping_API {

    /**
     * Calculate shipping rates.
     *
     * @param array $params Rate calculation parameters.
     * @return \Woodev_Shipping_API_Rate_Response
     * @throws \Woodev_Shipping_Exception
     */
    public function calculate_rates( array $params ): \Woodev_Shipping_API_Rate_Response;

    /**
     * Get pickup points.
     *
     * @param array $params Search parameters.
     * @return \Woodev_Shipping_API_Pickup_Points_Response
     * @throws \Woodev_Shipping_Exception
     */
    public function get_pickup_points( array $params ): \Woodev_Shipping_API_Pickup_Points_Response;

    /**
     * Create a shipping order.
     *
     * @param \Woodev_Exportable_Order $order Order to export.
     * @return \Woodev_Shipping_API_Order_Response
     * @throws \Woodev_Shipping_Exception
     */
    public function create_order( \Woodev_Exportable_Order $order ): \Woodev_Shipping_API_Order_Response;

    /**
     * Get order status.
     *
     * @param string $order_id Carrier-assigned order ID.
     * @return \Woodev_Shipping_API_Order_Response
     * @throws \Woodev_Shipping_Exception
     */
    public function get_order( string $order_id ): \Woodev_Shipping_API_Order_Response;

    /**
     * Cancel a shipping order.
     *
     * @param string $order_id Carrier-assigned order ID.
     * @return \Woodev_Shipping_API_Order_Response
     * @throws \Woodev_Shipping_Exception
     */
    public function cancel_order( string $order_id ): \Woodev_Shipping_API_Order_Response;

    /**
     * Get tracking information.
     *
     * @param string $tracking_number Carrier-assigned tracking number.
     * @return \Woodev_Shipping_API_Tracking_Response
     * @throws \Woodev_Shipping_Exception
     */
    public function get_tracking( string $tracking_number ): \Woodev_Shipping_API_Tracking_Response;

    /**
     * Get the most recent request object.
     *
     * @return \Woodev_API_Request
     */
    public function get_request(): \Woodev_API_Request;

    /**
     * Get the most recent response object.
     *
     * @return \Woodev_API_Response
     */
    public function get_response(): \Woodev_API_Response;
}
```

### API Implementation

```php
<?php

namespace Woodev\Framework\Shipping;

class My_Shipping_API implements Shipping_API {

    private \Woodev_API_Base $api;

    public function __construct() {
        $this->api = new My_API_Client();
    }

    public function calculate_rates( array $params ): \Woodev_Shipping_API_Rate_Response {
        // Send request via the API client and return a typed response object.
        // The actual implementation depends on your carrier's API.
        return $this->api->perform_request( new My_Rate_Request( $params ) );
    }

    public function get_pickup_points( array $params ): \Woodev_Shipping_API_Pickup_Points_Response {
        return $this->api->perform_request( new My_Pickup_Points_Request( $params ) );
    }

    public function create_order( \Woodev_Exportable_Order $order ): \Woodev_Shipping_API_Order_Response {
        return $this->api->perform_request( new My_Create_Order_Request( $order ) );
    }

    public function get_order( string $order_id ): \Woodev_Shipping_API_Order_Response {
        return $this->api->perform_request( new My_Get_Order_Request( $order_id ) );
    }

    public function cancel_order( string $order_id ): \Woodev_Shipping_API_Order_Response {
        return $this->api->perform_request( new My_Cancel_Order_Request( $order_id ) );
    }

    public function get_tracking( string $tracking_number ): \Woodev_Shipping_API_Tracking_Response {
        return $this->api->perform_request( new My_Tracking_Request( $tracking_number ) );
    }

    public function get_request(): \Woodev_API_Request {
        return $this->api->get_request();
    }

    public function get_response(): \Woodev_API_Response {
        return $this->api->get_response();
    }
}
```

## Settings Integration

### Shipping Integration Class

`Shipping_Integration` extends `WC_Integration` and is abstract. Its constructor accepts
a `?Shipping_Plugin` argument, automatically sets `$this->id`, `$this->method_title`, calls
`init_form_fields()` and `init_settings()`, and registers the admin options hook. Subclasses
must implement `get_method_form_fields()` and `init_plugin()`.

```php
<?php

namespace Woodev\Framework\Shipping;

class My_Shipping_Integration extends Shipping_Integration {

    /**
     * Returns method-specific form fields.
     *
     * These are merged with the base fields (debug mode, environment)
     * by the parent init_form_fields() method.
     *
     * @since 1.0.0
     *
     * @return array
     */
    protected function get_method_form_fields(): array {
        return [
            'api_key' => [
                'title'       => __( 'API Key', 'my-shipping' ),
                'type'        => 'text',
                'description' => __( 'Get from dashboard', 'my-shipping' ),
                'default'     => '',
            ],
            'api_secret' => [
                'title'       => __( 'API Secret', 'my-shipping' ),
                'type'        => 'password',
                'description' => __( 'Get from dashboard', 'my-shipping' ),
                'default'     => '',
            ],
            'default_weight' => [
                'title'       => __( 'Default Weight (kg)', 'my-shipping' ),
                'type'        => 'number',
                'description' => __( 'Used when product weight is missing', 'my-shipping' ),
                'default'     => '1.0',
            ],
        ];
    }

    /**
     * Initialize the parent plugin instance.
     *
     * Fallback used when the integration is instantiated by WooCommerce
     * without a plugin argument.
     *
     * @since 1.0.0
     *
     * @return Shipping_Plugin
     */
    protected function init_plugin(): Shipping_Plugin {
        return My_Shipping_Plugin::instance();
    }

    /**
     * Checks if the integration is fully configured.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function is_configured(): bool {
        return ! empty( $this->get_option( 'api_key' ) )
            && ! empty( $this->get_option( 'api_secret' ) );
    }
}
```

## Order Export

### Export Handler

```php
<?php
class Order_Export_Handler {

    private My_Shipping_Plugin $plugin;

    public function __construct( My_Shipping_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Export order to shipping service.
     *
     * @since 1.0.0
     *
     * @param WC_Order $order WooCommerce order.
     * @return \Woodev_Shipping_API_Order_Response
     * @throws Exception If API is not available.
     */
    public function export_order( WC_Order $order ): \Woodev_Shipping_API_Order_Response {
        $api = $this->plugin->get_api();

        if ( ! $api ) {
            throw new Exception( __( 'API not available', 'my-shipping' ) );
        }

        $response = $api->create_order( $order );

        // Save tracking number
        $order->update_meta_data( '_tracking_number', $response->get_tracking_number() );
        $order->update_meta_data( '_shipment_id', $response->get_order_id() );
        $order->save();

        // Add order note
        $order->add_order_note( sprintf(
            __( 'Shipment created. Tracking: %s', 'my-shipping' ),
            $response->get_tracking_number()
        ) );

        return $response;
    }
}
```

## Tracking

### Tracking Handler

```php
<?php
class Tracking_Handler {

    private My_Shipping_Plugin $plugin;

    public function __construct( My_Shipping_Plugin $plugin ) {
        $this->plugin = $plugin;

        add_action( 'woocommerce_order_details_after_order_table', [ $this, 'display_tracking' ] );
        add_action( 'woocommerce_email_after_order_table', [ $this, 'email_tracking' ] );
    }

    /**
     * Display tracking on order page.
     *
     * @since 1.0.0
     *
     * @param WC_Order $order WooCommerce order.
     */
    public function display_tracking( WC_Order $order ): void {
        $tracking_number = $order->get_meta( '_tracking_number' );

        if ( ! $tracking_number ) {
            return;
        }

        $tracking_url = $this->get_tracking_url( $tracking_number );

        ?>
        <section class="woocommerce-order-tracking">
            <h2><?php esc_html_e( 'Tracking', 'my-shipping' ); ?></h2>
            <p>
                <?php printf(
                    __( 'Tracking number: %s', 'my-shipping' ),
                    '<strong>' . esc_html( $tracking_number ) . '</strong>'
                ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" class="button">
                    <?php esc_html_e( 'Track Package', 'my-shipping' ); ?>
                </a>
            </p>
        </section>
        <?php
    }

    /**
     * Add tracking to email.
     *
     * @since 1.0.0
     *
     * @param WC_Order $order        WooCommerce order.
     * @param bool     $sent_to_admin Whether sent to admin.
     * @param bool     $plain_text   Whether plain text email.
     */
    public function email_tracking( WC_Order $order, bool $sent_to_admin, bool $plain_text ): void {
        $tracking_number = $order->get_meta( '_tracking_number' );

        if ( ! $tracking_number ) {
            return;
        }

        $tracking_url = $this->get_tracking_url( $tracking_number );

        if ( $plain_text ) {
            echo "\n" . __( 'Tracking number:', 'my-shipping' ) . ' ' . $tracking_number . "\n";
            echo __( 'Track at:', 'my-shipping' ) . ' ' . $tracking_url . "\n";
        } else {
            echo '<h2>' . __( 'Tracking Information', 'my-shipping' ) . '</h2>';
            echo '<p>' . sprintf(
                __( 'Tracking number: <a href="%s">%s</a>', 'my-shipping' ),
                esc_url( $tracking_url ),
                esc_html( $tracking_number )
            ) . '</p>';
        }
    }

    /**
     * Get tracking URL from API.
     *
     * @since 1.0.0
     *
     * @param string $tracking_number Tracking number.
     * @return string
     */
    private function get_tracking_url( string $tracking_number ): string {
        $api = $this->plugin->get_api();

        if ( ! $api ) {
            return '#';
        }

        $tracking = $api->get_tracking( $tracking_number );

        return $tracking->get_tracking_url() ?? '#';
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

// Plugin initialization
add_action( 'plugins_loaded', 'init_my_shipping', 0 );

function init_my_shipping() {
    Woodev_Plugin_Bootstrap::instance()->register_plugin(
        '1.4.0',
        'My Shipping',
        __FILE__,
        'my_shipping_init',
        [
            'load_shipping_method' => true,
        ]
    );
}

function my_shipping_init() {
    require_once __DIR__ . '/includes/class-my-shipping-plugin.php';
    require_once __DIR__ . '/includes/class-my-courier-method.php';
    require_once __DIR__ . '/includes/class-my-pickup-method.php';
    require_once __DIR__ . '/includes/class-my-shipping-api.php';

    $plugin = My_Shipping_Plugin::instance();

    // Initialize handlers
    new Order_Export_Handler( $plugin );
    new Tracking_Handler( $plugin );

    return $plugin;
}
```

## Best Practices

### 1. Declare Supported Features

The `supports` array in the `Shipping_Plugin` constructor accepts arbitrary string feature
flags. The `Shipping_Method` class defines these built-in feature constants:

- `Shipping_Method::FEATURE_SHIPPING_ZONES` -- enabled by default
- `Shipping_Method::FEATURE_INSTANCE_SETTINGS` -- enabled by default
- `Shipping_Method::FEATURE_SHIPPING_CLASSES` -- opt-in via `add_support()`

For plugin-level features (like tracking or pickup points), pass custom strings
in the `supports` array:

```php
<?php
parent::__construct(
    'my-shipping',
    '1.0.0',
    [
        'supports' => [ 'tracking', 'pickup-points' ],
    ]
);
```

### 2. Use Caching for API Calls

```php
<?php
$rates = get_transient( 'my_shipping_rates_' . md5( $cache_key ) );

if ( false === $rates ) {
    $rates = $api->calculate_rates( $params );
    set_transient( 'my_shipping_rates_' . md5( $cache_key ), $rates, HOUR_IN_SECONDS );
}
```

### 3. Log API Requests

```php
<?php
if ( $this->get_option( 'enable_debug' ) === 'yes' ) {
    $this->plugin->log( 'API Request: ' . print_r( $request, true ) );
    $this->plugin->log( 'API Response: ' . print_r( $response, true ) );
}
```

### 4. Handle API Errors Gracefully

```php
<?php
try {
    $rates = $api->calculate_rates( $params );
} catch ( \Woodev_Shipping_Exception $e ) {
    wc_add_notice( __( 'Unable to calculate shipping', 'my-shipping' ), 'error' );
    $this->plugin->log( 'Error: ' . $e->getMessage() );
}
```

## Related Documentation

- [Core Framework](core-framework.md) -- Plugin base class
- [Box Packer](box-packer.md) -- Package calculations
- [API Module](api-module.md) -- HTTP client

---

*For more information, see [README.md](README.md).*
