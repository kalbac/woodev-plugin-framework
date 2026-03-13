# Compatibility Module

The Compatibility module provides a stable, version-agnostic API for working with WooCommerce features that change across major releases. The primary focus is HPOS (High-Performance Order Storage) compatibility.

## Overview

The Compatibility module handles:

- HPOS (Custom Order Tables) compatibility
- WooCommerce version detection
- Order meta operations
- Admin screen detection

## Key Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Woodev_Order_Compatibility` | `compatibility/class-order-compatibility.php` | HPOS-safe order operations |
| `Woodev_Plugin_Compatibility` | `compatibility/class-plugin-compatibility.php` | WC version checks |

## Declaring HPOS Support

### Plugin Declaration

Declare HPOS support in your plugin constructor:

```php
<?php
parent::__construct(
    'my-plugin',
    '1.0.0',
    [
        'text_domain'        => 'my-plugin',
        'supported_features' => [
            'hpos' => true,  // Declare HPOS compatibility
        ],
    ]
);
```

`Woodev_Plugin` automatically registers the `before_woocommerce_init` hook based on this flag.

### Full Example

```php
<?php
class My_Plugin extends Woodev_Plugin {

    private static $instance;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        parent::__construct(
            'my-plugin',
            '1.0.0',
            [
                'text_domain' => 'my-plugin',
                'supported_features' => [
                    'hpos' => true,
                ],
            ]
        );
    }

    public function get_file(): string {
        return __FILE__;
    }

    public function get_plugin_name(): string {
        return __( 'My Plugin', 'my-plugin' );
    }

    public function get_download_id(): int {
        return 0;
    }
}
```

## Order Meta Operations

`Woodev_Order_Compatibility` provides static methods that work with both HPOS and classic order storage.

### Reading Order Meta

```php
<?php
// Woodev_Order_Compatibility is a global class (no namespace required)

$order = wc_get_order( $order_id );

// Get single meta value
$tracking = Woodev_Order_Compatibility::get_order_meta( $order, '_tracking_number' );

// Get all values for a key
$all_values = Woodev_Order_Compatibility::get_order_meta( $order, '_tracking_numbers', false );

```

**Parameters:**

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `$order` | `WC_Order\|int` | required | Order object or ID |
| `$meta_key` | `string` | required | Meta key to retrieve |
| `$single` | `bool` | `true` | Return single value or array |

### Writing Order Meta

```php
<?php
$order = wc_get_order( $order_id );

// Add meta (allows duplicates)
Woodev_Order_Compatibility::add_order_meta( $order, '_shipment_id', '12345' );

// Update meta (single value)
Woodev_Order_Compatibility::update_order_meta( $order, '_tracking_number', 'ABC123' );

// Update sync status
Woodev_Order_Compatibility::update_order_meta( $order, '_sync_status', 'completed' );
```

### Deleting Order Meta

```php
<?php
// Delete all meta with key
Woodev_Order_Compatibility::delete_order_meta( $order, '_temporary_data' );

// Delete specific value
Woodev_Order_Compatibility::delete_order_meta( $order, '_shipment_id', '12345' );
```

### Checking Meta Existence

```php
<?php
if ( Woodev_Order_Compatibility::order_meta_exists( $order, '_tracking_number' ) ) {
    $tracking = Woodev_Order_Compatibility::get_order_meta( $order, '_tracking_number' );
    echo "Tracking: {$tracking}";
} else {
    echo "No tracking number";
}
```

### Complete Example

```php
<?php
class Order_Meta_Manager {

    /**
     * Add tracking to order
     */
    public function add_tracking( WC_Order $order, string $tracking, string $carrier ): void {
        Woodev_Order_Compatibility::update_order_meta(
            $order,
            '_tracking_number',
            sanitize_text_field( $tracking )
        );

        Woodev_Order_Compatibility::update_order_meta(
            $order,
            '_carrier',
            sanitize_text_field( $carrier )
        );

        Woodev_Order_Compatibility::update_order_meta(
            $order,
            '_tracking_added',
            current_time( 'mysql' )
        );

        $order->add_order_note( sprintf(
            __( 'Tracking %1$s added via %2$s', 'my-plugin' ),
            $tracking,
            $carrier
        ) );
    }

    /**
     * Get tracking from order
     */
    public function get_tracking( WC_Order $order ): array {
        return [
            'number'  => Woodev_Order_Compatibility::get_order_meta( $order, '_tracking_number' ),
            'carrier' => Woodev_Order_Compatibility::get_order_meta( $order, '_carrier' ),
            'added'   => Woodev_Order_Compatibility::get_order_meta( $order, '_tracking_added' ),
        ];
    }

    /**
     * Remove tracking
     */
    public function remove_tracking( WC_Order $order ): void {
        Woodev_Order_Compatibility::delete_order_meta( $order, '_tracking_number' );
        Woodev_Order_Compatibility::delete_order_meta( $order, '_carrier' );
        Woodev_Order_Compatibility::delete_order_meta( $order, '_tracking_added' );

        $order->add_order_note( __( 'Tracking removed', 'my-plugin' ) );
    }
}
```

## Admin Screen Detection

### Orders Screen

```php
<?php
// Woodev_Order_Compatibility is a global class (no namespace required)

// Check if on orders list
if ( Woodev_Order_Compatibility::is_orders_screen() ) {
    // Enqueue orders-specific assets
    wp_enqueue_style( 'my-plugin-orders' );
}

// Check for specific status
if ( Woodev_Order_Compatibility::is_orders_screen_for_status( 'processing' ) ) {
    // Show processing orders notice
}
```

### Order Edit Screen

```php
<?php
// Check if on order edit screen
if ( Woodev_Order_Compatibility::is_order_edit_screen() ) {
    // Show order meta fields
    add_action( 'admin_footer', [ $this, 'render_order_meta_fields' ] );
}

// Get order ID from screen
$order_id = Woodev_Order_Compatibility::get_order_id_for_order_edit_screen();

if ( $order_id ) {
    $order = wc_get_order( $order_id );
    // Work with order
}
```

### Order Screen URLs

```php
<?php
// Get orders screen URL
$orders_url = Woodev_Order_Compatibility::get_orders_screen_url();

// Get edit URL for order
$edit_url = Woodev_Order_Compatibility::get_edit_order_url( $order_id );

// Get order screen ID
$screen_id = Woodev_Order_Compatibility::get_order_screen_id();

// Use in add_meta_box
add_meta_box(
    'my-plugin-order-data',
    __( 'Shipping Info', 'my-plugin' ),
    [ $this, 'render_shipping_meta_box' ],
    Woodev_Order_Compatibility::get_order_screen_id(),
    'side',
    'default'
);
```

## Database Table Names

For raw SQL queries:

```php
<?php
global $wpdb;

// Get correct table names
$orders_table = Woodev_Order_Compatibility::get_orders_table();
$meta_table = Woodev_Order_Compatibility::get_orders_meta_table();

// Query orders with custom meta
$results = $wpdb->get_results( $wpdb->prepare(
    "SELECT o.id, m.meta_value as tracking
     FROM {$orders_table} o
     INNER JOIN {$meta_table} m ON o.id = m.order_id
     WHERE m.meta_key = %s AND m.meta_value != ''",
    '_tracking_number'
) );

// Get order post types
$post_types = Woodev_Order_Compatibility::get_order_post_types();
```

## Order Type Detection

```php
<?php
$order = wc_get_order( $order_id );

// Check if object is an order
if ( Woodev_Order_Compatibility::is_order( $order ) ) {
    // Work with order
}

// Check if object is a refund
if ( Woodev_Order_Compatibility::is_refund( $order ) ) {
    // Handle refund
}
```

## Formatted Meta Data

```php
<?php
$order = wc_get_order( $order_id );

// Get formatted meta data for display
$meta_data = Woodev_Order_Compatibility::get_item_formatted_meta_data( $order_item );

foreach ( $meta_data as $meta ) {
    echo esc_html( $meta->display_key ) . ': ' . esc_html( $meta->value );
}
```

## WooCommerce Version Checks

`Woodev_Plugin_Compatibility` provides version comparison helpers.

### Version Comparison

```php
<?php
// Woodev_Plugin_Compatibility is a global class (no namespace required)

// Check if WC >= version
if ( Woodev_Plugin_Compatibility::is_wc_version_gte( '8.0' ) ) {
    // Use WC 8.0+ API
}

// Check if WC < version
if ( Woodev_Plugin_Compatibility::is_wc_version_lt( '7.0' ) ) {
    // Fall back to older API
}

// Check if WC > version
if ( Woodev_Plugin_Compatibility::is_wc_version_gt( '8.5' ) ) {
    // Use new feature
}

// Check exact version
if ( Woodev_Plugin_Compatibility::is_wc_version( '8.0' ) ) {
    // Exact match
}
```

### Getting Version Information

```php
<?php
// Get current WC version
$version = Woodev_Plugin_Compatibility::get_wc_version();
echo "WooCommerce {$version}";  // e.g., "9.4.1"

// Get latest WC versions
$versions = Woodev_Plugin_Compatibility::get_latest_wc_versions();
// Returns: ['9.4.1', '9.4.0', '9.3.2', ...]

// Check if HPOS is enabled
if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
    // HPOS is active
}
```

### Version-Specific Features

```php
<?php
class WC_Version_Handler {

    public function init() {
        // Use new HPOS CRUD API (WC 7.6+)
        if ( Woodev_Plugin_Compatibility::is_wc_version_gte( '7.6' ) ) {
            add_action( 'woocommerce_order_updated', [ $this, 'handle_update_new' ] );
        } else {
            add_action( 'woocommerce_update_order', [ $this, 'handle_update_old' ] );
        }

        // Use new email API (WC 8.0+)
        if ( Woodev_Plugin_Compatibility::is_wc_version_gte( '8.0' ) ) {
            add_filter( 'woocommerce_email_headers', [ $this, 'add_headers' ], 10, 4 );
        } else {
            add_filter( 'woocommerce_email_headers', [ $this, 'add_headers_old' ], 10, 3 );
        }
    }
}
```

## Practical Examples

### Example 1: Custom Order Meta Box

```php
<?php
class Order_Meta_Box {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_meta' ] );
    }

    public function add_meta_box() {
        add_meta_box(
            'my-plugin-delivery-date',
            __( 'Delivery Date', 'my-plugin' ),
            [ $this, 'render_meta_box' ],
            Woodev_Order_Compatibility::get_order_screen_id(),
            'side',
            'default'
        );
    }

    public function render_meta_box( $post_or_order ) {
        $order = ( $post_or_order instanceof WP_Post )
            ? wc_get_order( $post_or_order->ID )
            : $post_or_order;

        if ( ! $order ) {
            return;
        }

        $delivery_date = Woodev_Order_Compatibility::get_order_meta(
            $order,
            '_delivery_date',
            true
        );

        wp_nonce_field( 'my-plugin-save-order-meta', 'my_plugin_order_meta_nonce' );

        ?>
        <p>
            <label for="delivery_date"><?php esc_html_e( 'Delivery Date:', 'my-plugin' ); ?></label>
            <input type="date"
                   id="delivery_date"
                   name="delivery_date"
                   value="<?php echo esc_attr( $delivery_date ); ?>"
                   min="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>"
                   class="regular-text" />
        </p>
        <?php
    }

    public function save_meta( int $order_id ): void {
        if ( ! isset( $_POST['my_plugin_order_meta_nonce'] ) ||
             ! wp_verify_nonce( $_POST['my_plugin_order_meta_nonce'], 'my-plugin-save-order-meta' ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        if ( isset( $_POST['delivery_date'] ) ) {
            $date = sanitize_text_field( $_POST['delivery_date'] );

            if ( ! empty( $date ) ) {
                Woodev_Order_Compatibility::update_order_meta(
                    $order,
                    '_delivery_date',
                    $date
                );
            } else {
                Woodev_Order_Compatibility::delete_order_meta(
                    $order,
                    '_delivery_date'
                );
            }
        }
    }
}
```

### Example 2: Orders List Column

```php
<?php
class Orders_List_Columns {

    public function __construct() {
        // HPOS
        add_filter( 'woocommerce_shop_order_list_table_columns', [ $this, 'add_column' ] );
        add_action( 'woocommerce_shop_order_list_table_custom_column', [ $this, 'render_column' ], 10, 2 );

        // Classic (fallback)
        add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_column_classic' ] );
        add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_column_classic' ] );
    }

    public function add_column( $columns ) {
        $new_columns = [];

        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;

            if ( 'order_status' === $key ) {
                $new_columns['tracking_number'] = __( 'Tracking', 'my-plugin' );
            }
        }

        return $new_columns;
    }

    public function render_column( $column, $order ) {
        if ( 'tracking_number' !== $column ) {
            return;
        }

        $tracking = Woodev_Order_Compatibility::get_order_meta(
            $order,
            '_tracking_number',
            true
        );

        if ( $tracking ) {
            echo esc_html( $tracking );
        } else {
            echo '<span class="na">—</span>';
        }
    }

    // Classic compatibility
    public function add_column_classic( $columns ) {
        return $this->add_column( $columns );
    }

    public function render_column_classic( $column ) {
        global $post;
        $order = wc_get_order( $post->ID );
        $this->render_column( $column, $order );
    }
}
```

### Example 3: Order Search

```php
<?php
class Order_Search {

    public function __construct() {
        add_filter( 'woocommerce_shop_order_search_fields', [ $this, 'add_search_fields' ] );
        add_filter( 'woocommerce_shop_order_search_results', [ $this, 'search_by_tracking' ], 10, 3 );
    }

    public function add_search_fields( $search_fields ) {
        $search_fields[] = '_tracking_number';
        return $search_fields;
    }

    public function search_by_tracking( $order_ids, $term, $search_fields ) {
        global $wpdb;

        $meta_table = Woodev_Order_Compatibility::get_orders_meta_table();

        $tracking_orders = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT order_id FROM {$meta_table}
             WHERE meta_key = '_tracking_number'
             AND meta_value LIKE %s",
            '%' . $wpdb->esc_like( $term ) . '%'
        ) );

        return array_merge( $order_ids, $tracking_orders );
    }
}
```

### Example 4: Bulk Actions

```php
<?php
class Order_Bulk_Actions {

    public function __construct() {
        // HPOS
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $this, 'add_actions' ] );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $this, 'handle' ], 10, 3 );

        // Classic
        add_filter( 'bulk-actions-edit-shop_order', [ $this, 'add_actions' ] );
        add_filter( 'handle_bulk_actions-edit-shop_order', [ $this, 'handle' ], 10, 3 );
    }

    public function add_actions( $actions ) {
        $actions['mark_shipped'] = __( 'Mark as Shipped', 'my-plugin' );
        return $actions;
    }

    public function handle( $redirect, $action, $order_ids ) {
        if ( 'mark_shipped' !== $action ) {
            return $redirect;
        }

        $count = 0;

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                continue;
            }

            Woodev_Order_Compatibility::update_order_meta(
                $order,
                '_shipped_date',
                current_time( 'mysql' )
            );

            $order->add_order_note( __( 'Marked as shipped', 'my-plugin' ) );

            $count++;
        }

        return add_query_arg( 'shipped_count', $count, $redirect );
    }
}

// Show notice
add_action( 'admin_notices', function() {
    if ( isset( $_GET['shipped_count'] ) ) {
        $count = absint( $_GET['shipped_count'] );
        echo '<div class="updated"><p>';
        printf(
            _n( '%d order marked as shipped', '%d orders marked as shipped', $count, 'my-plugin' ),
            $count
        );
        echo '</p></div>';
    }
} );
```

## Best Practices

### 1. Use Compatibility Helpers

```php
<?php
// ❌ Don't - breaks with HPOS
$tracking = get_post_meta( $order_id, '_tracking_number', true );

// ✅ Do - works with both
$tracking = Woodev_Order_Compatibility::get_order_meta( $order, '_tracking_number', true );
```

### 2. Type-Hint Order Parameters

```php
<?php
function process_order( WC_Order $order ) {
    $tracking = Woodev_Order_Compatibility::get_order_meta( $order, '_tracking_number' );
}
```

### 3. Handle Both Post and Order Objects

```php
<?php
function render_meta_box( $post_or_order ) {
    $order = ( $post_or_order instanceof WP_Post )
        ? wc_get_order( $post_or_order->ID )
        : $post_or_order;

    if ( ! $order instanceof WC_Order ) {
        return;
    }
}
```

### 4. Use Screen Detection

```php
<?php
add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
    if ( ! Woodev_Order_Compatibility::is_order_edit_screen() ) {
        return;
    }

    wp_enqueue_script( 'my-plugin-orders' );
} );
```

### 5. Cache Version Checks

```php
<?php
class Feature_Flags {

    private static $is_wc_gte_8;

    public static function is_wc_8_or_higher(): bool {
        if ( null === self::$is_wc_gte_8 ) {
            self::$is_wc_gte_8 = Woodev_Plugin_Compatibility::is_wc_version_gte( '8.0' );
        }
        return self::$is_wc_gte_8;
    }
}
```

## Troubleshooting

### Meta Not Saving

1. **Verify HPOS declaration:**

   ```php
<?php
   add_action( 'before_woocommerce_init', function() {
       if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
           \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
               'custom_order_tables',
               __FILE__,
               true
           );
       }
   } );
   ```

2. **Check meta key format:**

   ```php
<?php
   // Use underscore prefix for hidden meta
   Woodev_Order_Compatibility::update_order_meta( $order, '_my_meta', $value );
   ```

### Screen Detection Not Working

```php
<?php
// Check at right time
add_action( 'load-woocommerce_page_wc-orders', function() {
    // Runs on orders page
} );

add_action( 'load-post.php', function() {
    // Runs on order edit (classic)
} );
```

### SQL Queries Empty

```php
<?php
// Verify table names
global $wpdb;

$orders_table = Woodev_Order_Compatibility::get_orders_table();
$meta_table = Woodev_Order_Compatibility::get_orders_meta_table();

error_log( "Orders table: {$orders_table}" );
error_log( "Meta table: {$meta_table}" );
```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [Admin Module](admin-module.md) — Admin pages
- [Helpers](helpers.md) — Utility functions

---

*For more information, see [README.md](README.md).*
