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
                'supports'    => [
                    Shipping_Plugin::FEATURE_TRACKING,
                    Shipping_Plugin::FEATURE_PICKUP_POINTS,
                ],
                'currencies'  => [ 'RUB', 'USD', 'EUR' ],
                'countries'   => [ 'RU', 'US', 'DE' ],
            ]
        );
    }

    /**
     * Get shipping method classes
     */
    protected function get_shipping_method_classes(): array {
        return [
            My_Courier_Method::class,
            My_Pickup_Method::class,
            My_Postal_Method::class,
        ];
    }

    /**
     * Get API instance
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

```php
<?php

namespace Woodev\Framework\Shipping;

defined( 'ABSPATH' ) || exit;

class My_Courier_Method extends Shipping_Method {

    /**
     * Method ID
     */
    const METHOD_ID = 'my_courier';

    /**
     * Method title
     */
    const METHOD_TITLE = 'Courier Delivery';

    /**
     * Constructor
     */
    public function __construct( int $instance_id = 0 ) {
        parent::__construct( $instance_id );

        $this->id                 = self::METHOD_ID;
        $this->method_title       = self::METHOD_TITLE;
        $this->method_description = __( 'Courier delivery service', 'my-shipping' );

        $this->init();
    }

    /**
     * Initialize settings
     */
    public function init(): void {
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option( 'title' );
        $this->enabled = $this->get_option( 'enabled', 'yes' );

        add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    /**
     * Settings fields
     */
    public function init_form_fields(): array {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable/Disable', 'my-shipping' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this shipping method', 'my-shipping' ),
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __( 'Title', 'my-shipping' ),
                'type'        => 'text',
                'description' => __( 'Title shown to customers', 'my-shipping' ),
                'default'     => __( 'Courier', 'my-shipping' ),
                'desc_tip'    => true,
            ],
            'api_key' => [
                'title'       => __( 'API Key', 'my-shipping' ),
                'type'        => 'text',
                'description' => __( 'Your API key', 'my-shipping' ),
                'default'     => '',
            ],
        ];
    }

    /**
     * Calculate the shipping rate for this package.
     *
     * This is the method to implement — calculate_shipping() is final
     * and calls this method internally.
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

        $rates = $api->get_rates( [
            'country'  => $country,
            'postcode' => $postcode,
            'weight'   => $weight,
        ] );

        return $rates[0] ?? null;
    }
}
```

### Courier Method

```php
<?php
class My_Courier_Method extends Shipping_Method_Courier {

    const METHOD_ID = 'my_courier';

    public function __construct( int $instance_id = 0 ) {
        parent::__construct( $instance_id );

        $this->method_title       = __( 'Courier Delivery', 'my-shipping' );
        $this->method_description = __( 'Delivery by courier to your door', 'my-shipping' );
    }

    /**
     * Calculate the courier rate for this package.
     *
     * @param array $package WooCommerce package array.
     * @return Shipping_Rate|null
     */
    protected function calculate_rate( array $package ): ?Shipping_Rate {
        $api = My_Shipping_Plugin::instance()->get_api();

        if ( ! $api ) {
            return null;
        }

        return $api->get_courier_rate( $package );
    }
}
```

### Pickup Method

```php
<?php
class My_Pickup_Method extends Shipping_Method_Pickup {

    const METHOD_ID = 'my_pickup';

    public function __construct( int $instance_id = 0 ) {
        parent::__construct( $instance_id );

        $this->method_title       = __( 'Pickup Point', 'my-shipping' );
        $this->method_description = __( 'Pick up from our locations', 'my-shipping' );
    }

    /**
     * Get pickup points
     */
    public function get_pickup_points(): array {
        $api = My_Shipping_Plugin::instance()->get_api();

        if ( ! $api ) {
            return [];
        }

        return $api->get_pickup_points( [
            'country'  => WC()->customer->get_shipping_country(),
            'postcode' => WC()->customer->get_shipping_postcode(),
            'city'     => WC()->customer->get_shipping_city(),
        ] );
    }
}
```

### Postal Method

```php
<?php
class My_Postal_Method extends Shipping_Method_Postal {

    const METHOD_ID = 'my_postal';

    public function __construct( int $instance_id = 0 ) {
        parent::__construct( $instance_id );

        $this->method_title       = __( 'Postal Service', 'my-shipping' );
        $this->method_description = __( 'Delivery via national postal service', 'my-shipping' );
    }
}
```

## Shipping Rate Class

### Creating Rates

```php
<?php
use Woodev\Framework\Shipping\Shipping_Rate;

$rate = new Shipping_Rate(
    'my_courier:1',      // Rate ID
    __( 'Standard Delivery', 'my-shipping' ),
    500.00,              // Cost
    [
        'delivery_time' => '3-5 days',
        'carrier'       => 'My Carrier',
    ]
);

// Add rate
$this->add_rate( [
    'id'        => $rate->id,
    'label'     => $rate->label,
    'cost'      => $rate->cost,
    'meta_data' => $rate->meta_data,
] );
```

### Rate with Options

```php
<?php
$rate = new Shipping_Rate(
    'my_courier:express',
    __( 'Express Delivery', 'my-shipping' ),
    1000.00,
    [
        'delivery_time' => '1-2 days',
        'carrier'       => 'Express Carrier',
        'tracking'      => true,
    ]
);
```

## API Integration

### Shipping API Interface

```php
<?php
interface Shipping_API {

    /**
     * Get shipping rates
     */
    public function get_rates( array $params ): array;

    /**
     * Get pickup points
     */
    public function get_pickup_points( array $params ): array;

    /**
     * Create shipment
     */
    public function create_shipment( array $order_data ): array;

    /**
     * Get tracking info
     */
    public function get_tracking( string $tracking_number ): array;
}
```

### API Implementation

```php
<?php
class My_Shipping_API implements Shipping_API {

    private Woodev_API_Base $api;

    public function __construct() {
        $this->api = new My_API_Client();
    }

    public function get_rates( array $params ): array {
        $response = $this->api->get( '/rates', $params );

        return array_map( function( $rate_data ) {
            return new Shipping_Rate(
                $rate_data['id'],
                $rate_data['name'],
                $rate_data['cost'],
                [
                    'delivery_time' => $rate_data['delivery_time'],
                    'carrier'       => $rate_data['carrier'],
                ]
            );
        }, $response['rates'] ?? [] );
    }

    public function get_pickup_points( array $params ): array {
        $response = $this->api->get( '/pickup-points', $params );

        return $response['points'] ?? [];
    }

    public function create_shipment( array $order_data ): array {
        return $this->api->post( '/shipments', $order_data );
    }

    public function get_tracking( string $tracking_number ): array {
        return $this->api->get( '/tracking/' . $tracking_number );
    }
}
```

## Settings Integration

### Shipping Integration Class

```php
<?php
class My_Shipping_Integration extends Shipping_Integration {

    /**
     * Initialize settings
     */
    public function init(): void {
        $this->id          = 'my_shipping';
        $this->method_id   = 'my_shipping';
        $this->method_title = __( 'My Shipping', 'my-shipping' );

        $this->init_form_fields();
        $this->init_settings();

        add_action( 'woocommerce_update_options_integration_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    /**
     * Settings fields
     */
    public function init_form_fields(): void {
        $this->form_fields = [
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
            'debug_mode' => [
                'title'   => __( 'Debug Mode', 'my-shipping' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable debug logging', 'my-shipping' ),
                'default' => 'no',
            ],
            'default_weight' => [
                'title'       => __( 'Default Weight (kg)', 'my-shipping' ),
                'type'        => 'number',
                'description' => __( 'Used when product weight is missing', 'my-shipping' ),
                'default'     => '1.0',
            ],
        ];
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
     * Export order to shipping service
     */
    public function export_order( WC_Order $order ): array {
        $api = $this->plugin->get_api();

        if ( ! $api ) {
            throw new Exception( __( 'API not available', 'my-shipping' ) );
        }

        $shipment_data = $this->prepare_shipment_data( $order );

        $response = $api->create_shipment( $shipment_data );

        // Save tracking number
        $order->update_meta_data( '_tracking_number', $response['tracking_number'] );
        $order->update_meta_data( '_shipment_id', $response['shipment_id'] );
        $order->save();

        // Add order note
        $order->add_order_note( sprintf(
            __( 'Shipment created. Tracking: %s', 'my-shipping' ),
            $response['tracking_number']
        ) );

        return $response;
    }

    /**
     * Prepare shipment data
     */
    private function prepare_shipment_data( WC_Order $order ): array {
        return [
            'order_id'      => $order->get_id(),
            'recipient'     => [
                'name'     => $order->get_formatted_shipping_full_name(),
                'phone'    => $order->get_shipping_phone(),
                'email'    => $order->get_billing_email(),
                'address'  => [
                    'country'  => $order->get_shipping_country(),
                    'city'     => $order->get_shipping_city(),
                    'postcode' => $order->get_shipping_postcode(),
                    'address'  => $order->get_shipping_address_1(),
                    'address2' => $order->get_shipping_address_2(),
                ],
            ],
            'packages'      => $this->get_packages( $order ),
            'service_type'  => $this->get_service_type( $order ),
        ];
    }

    /**
     * Get package data
     */
    private function get_packages( WC_Order $order ): array {
        $packages = [];

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();

            $packages[] = [
                'weight' => (float) $product->get_weight(),
                'length' => (float) $product->get_length(),
                'width'  => (float) $product->get_width(),
                'height' => (float) $product->get_height(),
                'value'  => (float) $order->get_item_total( $item ),
            ];
        }

        return $packages;
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
     * Display tracking on order page
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
     * Add tracking to email
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
     * Get tracking URL
     */
    private function get_tracking_url( string $tracking_number ): string {
        $api = $this->plugin->get_api();

        if ( ! $api ) {
            return '#';
        }

        return $api->get_tracking_url( $tracking_number );
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

```php
<?php
parent::__construct(
    'my-shipping',
    '1.0.0',
    [
        'supports' => [
            Shipping_Plugin::FEATURE_TRACKING,
            Shipping_Plugin::FEATURE_PICKUP_POINTS,
        ],
    ]
);
```

### 2. Use Caching for API Calls

```php
<?php
$rates = get_transient( 'my_shipping_rates_' . md5( $cache_key ) );

if ( false === $rates ) {
    $rates = $api->get_rates( $params );
    set_transient( 'my_shipping_rates_' . md5( $cache_key ), $rates, HOUR_IN_SECONDS );
}
```

### 3. Log API Requests

```php
<?php
if ( $this->get_option( 'debug_mode' ) === 'yes' ) {
    $this->plugin->log( 'API Request: ' . print_r( $request, true ) );
    $this->plugin->log( 'API Response: ' . print_r( $response, true ) );
}
```

### 4. Handle API Errors Gracefully

```php
<?php
try {
    $rates = $api->get_rates( $params );
} catch ( Exception $e ) {
    wc_add_notice( __( 'Unable to calculate shipping', 'my-shipping' ), 'error' );
    $this->plugin->log( 'Error: ' . $e->getMessage() );
}
```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [Box Packer](box-packer.md) — Package calculations
- [API Module](api-module.md) — HTTP client

---

*For more information, see [README.md](README.md).*
