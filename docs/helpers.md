# Helpers

The Helpers module provides a collection of static utility functions used throughout the Woodev Framework. These helpers centralize common operations for string manipulation, array handling, WooCommerce integration, and request processing.

## Overview

`Woodev_Helper` is a static utility class that provides:

- Multi-byte safe string functions
- Array manipulation utilities
- WooCommerce helper functions
- Request and response helpers
- Formatting utilities

## String Functions

All string functions are multi-byte safe when the `mbstring` extension is available.

### str_starts_with

Check if a string starts with a given substring.

```php
// Usage
if ( Woodev_Helper::str_starts_with( $filename, 'export-' ) ) {
    echo 'This is an export file';
}

// Examples
Woodev_Helper::str_starts_with( 'hello world', 'hello' ); // true
Woodev_Helper::str_starts_with( 'hello world', 'world' ); // false
Woodev_Helper::str_starts_with( 'hello world', '' );      // true
```

### str_ends_with

Check if a string ends with a given substring.

```php
// Usage
if ( Woodev_Helper::str_ends_with( $email, '.ru' ) ) {
    echo 'Russian email domain';
}

// Examples
Woodev_Helper::str_ends_with( 'hello world', 'world' ); // true
Woodev_Helper::str_ends_with( 'hello world', 'hello' ); // false
Woodev_Helper::str_ends_with( 'hello world', '' );      // true
```

### str_exists

Check if a substring exists within a string.

```php
// Usage
if ( Woodev_Helper::str_exists( $content, 'keyword' ) ) {
    echo 'Keyword found';
}

// Examples
Woodev_Helper::str_exists( 'hello world', 'lo wo' ); // true
Woodev_Helper::str_exists( 'hello world', 'xyz' );   // false
```

### str_truncate

Truncate a string to a specified length.

```php
// Usage
$short_text = Woodev_Helper::str_truncate( $long_text, 100, '...' );

// Examples
Woodev_Helper::str_truncate( 'Hello World', 8 );       // 'Hello...'
Woodev_Helper::str_truncate( 'Hello World', 20 );      // 'Hello World'
Woodev_Helper::str_truncate( 'Hello World', 8, '>>' ); // 'Hello>>'
```

### str_to_ascii

Convert a string to ASCII (removes accents and special characters).

```php
// Usage
$ascii_name = Woodev_Helper::str_to_ascii( $cyrillic_name );

// Examples
Woodev_Helper::str_to_ascii( 'Привет' );  // 'Privet'
Woodev_Helper::str_to_ascii( 'Ñoño' );    // 'Nono'
```

### str_to_sane_utf8

Clean a string and ensure it's valid UTF-8.

```php
// Usage
$clean_text = Woodev_Helper::str_to_sane_utf8( $raw_text );
```

## Array Functions

### array_insert_after

Insert items into an array after a specific key.

```php
// Usage
$new_array = Woodev_Helper::array_insert_after(
    $array,
    'existing_key',
    [ 'new_key' => 'new_value' ]
);

// Example
$array = [ 'a' => 1, 'b' => 2, 'c' => 3 ];
$result = Woodev_Helper::array_insert_after( $array, 'b', [ 'x' => 10 ] );
// Result: [ 'a' => 1, 'b' => 2, 'x' => 10, 'c' => 3 ]
```

### array_to_xml

Convert an array to XML format.

```php
// Usage
$xml = Woodev_Helper::array_to_xml( $data );

// Example
$data = [
    'order' => [
        'id'    => 123,
        'total' => 100.00,
    ],
];
$xml = Woodev_Helper::array_to_xml( $data );
```

### list_array_items

Format an array as a natural language list.

```php
// Usage
$text = Woodev_Helper::list_array_items( $items );

// Examples
Woodev_Helper::list_array_items( [ 'apple' ] );
// Output: "apple"

Woodev_Helper::list_array_items( [ 'apple', 'banana' ] );
// Output: "apple and banana"

Woodev_Helper::list_array_items( [ 'apple', 'banana', 'cherry' ] );
// Output: "apple, banana, and cherry"
```

### array_join_natural

Join array items with natural language conjunctions.

```php
// Usage
$text = Woodev_Helper::array_join_natural( $items, 'or' );

// Examples
Woodev_Helper::array_join_natural( [ 'a', 'b', 'c' ], 'or' );
// Output: "a, b, or c"
```

## Formatting Functions

### format_percentage

Format a number as a percentage.

```php
// Usage
echo Woodev_Helper::format_percentage( 0.25 );    // 25%
echo Woodev_Helper::format_percentage( 0.255 );   // 25.5%
echo Woodev_Helper::format_percentage( 0.255, 1); // 25.5%
```

### number_format

Format a number with localized decimals.

```php
// Usage
echo Woodev_Helper::number_format( 1234.567 );    // 1,234.57
echo Woodev_Helper::number_format( 1234.567, 3 ); // 1,234.567
```

## WooCommerce Helpers

### get_order_line_items

Get formatted line items from an order.

```php
// Usage
$items = Woodev_Helper::get_order_line_items( $order );

foreach ( $items as $item ) {
    echo $item->get_name();
    echo $item->get_quantity();
    echo $item->get_total();
}
```

### is_order_virtual

Check if an order contains only virtual products.

```php
// Usage
if ( Woodev_Helper::is_order_virtual( $order ) ) {
    echo 'No shipping required';
}
```

### shop_has_virtual_products

Check if the shop has any virtual products.

```php
// Usage
if ( Woodev_Helper::shop_has_virtual_products() ) {
    echo 'Shop sells virtual products';
}
```

### get_wc_log_file_url

Get the URL to view a WooCommerce log file.

```php
// Usage
$log_url = Woodev_Helper::get_wc_log_file_url( 'my-plugin-log' );
echo '<a href="' . esc_url( $log_url ) . '">View Log</a>';
```

## Request Helpers

### get_posted_value

Get a value from POST request.

```php
// Usage
$value = Woodev_Helper::get_posted_value( 'field_name' );
$value = Woodev_Helper::get_posted_value( 'field_name', 'default' );

// With sanitization
$email = Woodev_Helper::get_posted_value( 'email' );
$email = sanitize_email( $email );
```

### get_requested_value

Get a value from GET or POST request.

```php
// Usage
$value = Woodev_Helper::get_requested_value( 'param_name' );
$value = Woodev_Helper::get_requested_value( 'param_name', 'default' );
```

### is_ajax

Check if the current request is an AJAX request.

```php
// Usage
if ( Woodev_Helper::is_ajax() ) {
    // Handle AJAX request
}
```

### is_rest_api_request

Check if the current request is a REST API request.

```php
// Usage
if ( Woodev_Helper::is_rest_api_request() ) {
    // Handle REST API request
}
```

## Screen Detection

### get_current_screen

Get the current admin screen object.

```php
// Usage
$screen = Woodev_Helper::get_current_screen();

if ( $screen && 'shop_order' === $screen->post_type ) {
    echo 'On order screen';
}
```

### is_current_screen

Check if on a specific admin screen.

```php
// Usage
if ( Woodev_Helper::is_current_screen( 'woocommerce_page_wc-orders' ) ) {
    echo 'On orders page';
}

if ( Woodev_Helper::is_current_screen( 'shop_order' ) ) {
    echo 'On order edit screen';
}
```

### is_enhanced_admin_screen

Check if on WooCommerce enhanced admin screen.

```php
// Usage
if ( Woodev_Helper::is_enhanced_admin_screen() ) {
    echo 'Using WooCommerce Admin';
}
```

### is_wc_navigation_enabled

Check if WooCommerce navigation is enabled.

```php
// Usage
if ( Woodev_Helper::is_wc_navigation_enabled() ) {
    echo 'WC Navigation active';
}
```

## Utility Functions

### multibyte_loaded

Check if the mbstring extension is loaded.

```php
// Usage
if ( Woodev_Helper::multibyte_loaded() ) {
    // Use multi-byte functions
} else {
    // Use fallback functions
}
```

### enqueue_js

Enqueue inline JavaScript.

```php
// Usage
Woodev_Helper::enqueue_js( 'console.log("Hello");' );
```

### print_js

Output inline JavaScript.

```php
// Usage
Woodev_Helper::print_js( 'alert("Hello");' );
```

### let_to_num

Convert PHP ini size notation to bytes.

```php
// Usage
$bytes = Woodev_Helper::let_to_num( '128M' );    // 134217728
$bytes = Woodev_Helper::let_to_num( '1G' );      // 1073741824
$bytes = Woodev_Helper::let_to_num( '256K' );    // 262144
```

### trigger_error

Trigger a PHP error with context.

```php
// Usage
Woodev_Helper::trigger_error( 'Something went wrong', E_USER_WARNING );
```

### get_escaped_string_list

Get an escaped list of strings.

```php
// Usage
$escaped = Woodev_Helper::get_escaped_string_list( $strings );
```

### get_escaped_id_list

Get an escaped list of IDs.

```php
// Usage
$escaped = Woodev_Helper::get_escaped_id_list( $ids );
```

## Translation Helpers

### f__

Translate a string (returns translation).

```php
// Usage
$text = f__( 'Hello World', 'my-plugin' );
```

### f_e

Translate and echo a string.

```php
// Usage
f_e( 'Hello World', 'my-plugin' );
```

### f_x

Translate with context.

```php
// Usage
$text = f_x( 'Post', 'noun', 'my-plugin' );
```

## Render Helpers

### render_select2_ajax

Render Select2 AJAX dropdown.

```php
// Usage
Woodev_Helper::render_select2_ajax(
    'product_id',
    __( 'Select Product', 'my-plugin' ),
    [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'action'   => 'search_products',
    ]
);
```

## Site Helpers

### get_site_name

Get the site name.

```php
// Usage
$site_name = Woodev_Helper::get_site_name();
echo "Welcome to {$site_name}";
```

### get_post

Get a post by ID.

```php
// Usage
$post = Woodev_Helper::get_post( $post_id );
```

### get_request

Get request data.

```php
// Usage
$request = Woodev_Helper::get_request();
$value = $request['key'] ?? null;
```

### is_woocommerce_active

Check if WooCommerce is active.

```php
// Usage
if ( Woodev_Helper::is_woocommerce_active() ) {
    echo 'WooCommerce is active';
}
```

### get_wc_version

Get the WooCommerce version.

```php
// Usage
$version = Woodev_Helper::get_wc_version();
echo "WooCommerce {$version}";
```

### wc_notice_count

Get the count of WooCommerce notices.

```php
// Usage
$count = Woodev_Helper::wc_notice_count( 'error' );
```

### wc_add_notice

Add a WooCommerce notice.

```php
// Usage
Woodev_Helper::wc_add_notice( 'Message', 'error' );
```

### wc_print_notice

Print a WooCommerce notice.

```php
// Usage
echo Woodev_Helper::wc_print_notice( 'Message', 'success' );
```

## String Conversion

### str_convert

Convert string encoding.

```php
// Usage
$converted = Woodev_Helper::str_convert( $string, 'UTF-8', 'ISO-8859-1' );
```

## Compatibility

### convert_country_code

Convert country code between formats.

```php
// Usage
$iso2 = Woodev_Helper::convert_country_code( $code, 'ISO3166-2' );
```

### maybe_doing_it_early

Check if doing it early (before init).

```php
// Usage
if ( Woodev_Helper::maybe_doing_it_early() ) {
    // Too early for certain operations
}
```

## Practical Examples

### Example 1: Form Processing

```php
class Form_Handler {

    public function handle_submit() {
        // Get and sanitize input
        $name = Woodev_Helper::get_posted_value( 'name' );
        $name = sanitize_text_field( $name );

        $email = Woodev_Helper::get_posted_value( 'email' );
        $email = sanitize_email( $email );

        // Validate
        if ( empty( $name ) || empty( $email ) ) {
            Woodev_Helper::wc_add_notice( 'Please fill all fields', 'error' );
            return;
        }

        // Process
        $this->process_form( $name, $email );

        // Success message
        Woodev_Helper::wc_add_notice( 'Form submitted successfully!', 'success' );
    }
}
```

### Example 2: String Processing

```php
class Text_Processor {

    public function process_title( string $title ): string {
        // Truncate if too long
        if ( strlen( $title ) > 100 ) {
            $title = Woodev_Helper::str_truncate( $title, 100 );
        }

        // Convert to ASCII for URL slug
        $slug = Woodev_Helper::str_to_ascii( $title );
        $slug = sanitize_title( $slug );

        return $title;
    }

    public function format_list( array $items ): string {
        return Woodev_Helper::array_join_natural( $items, 'and' );
    }
}
```

### Example 3: Order Processing

```php
class Order_Processor {

    public function process_order( WC_Order $order ) {
        // Check if virtual
        if ( Woodev_Helper::is_order_virtual( $order ) ) {
            $order->update_meta_data( '_shipping_required', 'no' );
        }

        // Get line items
        $items = Woodev_Helper::get_order_line_items( $order );

        // Log items
        foreach ( $items as $item ) {
            $this->log_item( $item );
        }

        // Format total
        $total = Woodev_Helper::number_format( $order->get_total() );
        $order->add_order_note( "Total: {$total}" );
    }
}
```

### Example 4: Admin Screen Detection

```php
class Admin_Handler {

    public function enqueue_assets() {
        // Only on order edit screen
        if ( ! Woodev_Helper::is_current_screen( 'shop_order' ) ) {
            return;
        }

        wp_enqueue_script( 'my-plugin-orders' );
        wp_enqueue_style( 'my-plugin-orders' );
    }

    public function add_meta_box() {
        // Check screen
        $screen = Woodev_Helper::get_current_screen();

        if ( ! $screen || 'shop_order' !== $screen->id ) {
            return;
        }

        add_meta_box(
            'my-plugin-meta',
            'Plugin Data',
            [ $this, 'render_meta_box' ],
            $screen->id,
            'side'
        );
    }
}
```

### Example 5: Array Manipulation

```php
class Settings_Page {

    public function get_form_fields(): array {
        $fields = [
            'general' => [
                'title'  => 'General',
                'fields' => [],
            ],
            'shipping' => [
                'title'  => 'Shipping',
                'fields' => [],
            ],
        ];

        // Insert payment section after shipping
        $fields = Woodev_Helper::array_insert_after(
            $fields,
            'shipping',
            [
                'payment' => [
                    'title'  => 'Payment',
                    'fields' => [],
                ],
            ]
        );

        return $fields;
    }

    public function render_section_list( array $sections ): string {
        $names = wp_list_pluck( $sections, 'title' );
        return Woodev_Helper::array_join_natural( $names, 'and' );
    }
}
```

## Best Practices

### 1. Use Helper Functions Instead of Reinventing

```php
// ❌ Don't do this
if ( substr( $string, 0, strlen( $prefix ) ) === $prefix ) {
    // ...
}

// ✅ Use helper
if ( Woodev_Helper::str_starts_with( $string, $prefix ) ) {
    // ...
}
```

### 2. Always Sanitize Input

```php
// Get value
$value = Woodev_Helper::get_posted_value( 'field' );

// Sanitize
$value = sanitize_text_field( $value );
```

### 3. Use Multi-byte Safe Functions

```php
// Helpers automatically use mb_* if available
$truncated = Woodev_Helper::str_truncate( $text, 100 );
```

### 4. Check for WooCommerce

```php
if ( ! Woodev_Helper::is_woocommerce_active() ) {
    return;
}
```

### 5. Use Formatting Helpers

```php
// Format numbers consistently
$total = Woodev_Helper::number_format( $order->get_total() );

// Format percentages
$discount = Woodev_Helper::format_percentage( 0.25 );
```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [Admin Module](admin-module.md) — Admin pages
- [Settings API](settings-api.md) — Settings handling

---

*For more information, see [README.md](README.md).*
