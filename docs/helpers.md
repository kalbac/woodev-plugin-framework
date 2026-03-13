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
<?php
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
<?php
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
<?php
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
<?php
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
<?php
// Usage
$ascii_name = Woodev_Helper::str_to_ascii( $cyrillic_name );

// Examples
Woodev_Helper::str_to_ascii( 'Привет' );  // 'Privet'
Woodev_Helper::str_to_ascii( 'Ñoño' );    // 'Nono'
```

### str_to_sane_utf8

Clean a string and ensure it's valid UTF-8.

```php
<?php
// Usage
$clean_text = Woodev_Helper::str_to_sane_utf8( $raw_text );
```

## Array Functions

### array_insert_after

Insert items into an array after a specific key.

```php
<?php
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

Convert an array to XML by recursively generating child elements using an `XMLWriter` instance.

```php
<?php
// Usage
$xml_writer = new XMLWriter();
$xml_writer->openMemory();
$xml_writer->startDocument( '1.0', 'UTF-8' );

$data = [
    'id'    => 123,
    'total' => 100.00,
];

$xml_writer->startElement( 'order' );
foreach ( $data as $key => $value ) {
    Woodev_Helper::array_to_xml( $xml_writer, $key, $value );
}
$xml_writer->endElement();

$xml_writer->endDocument();
$xml_string = $xml_writer->outputMemory();
```

### list_array_items

Format an array as a natural language list.

```php
<?php
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
<?php
// Usage
$text = Woodev_Helper::array_join_natural( $items, 'or' );

// Examples
Woodev_Helper::array_join_natural( [ 'a', 'b', 'c' ], 'or' );
// Output: "a, b, or c"
```

## Formatting Functions

### format_percentage

Format a fraction as a percentage. The second and third parameters are passed to `wc_format_decimal()`.

```php
<?php
// Usage
echo Woodev_Helper::format_percentage( 0.25 );        // '25%'
echo Woodev_Helper::format_percentage( 0.255 );        // '25.5%'
echo Woodev_Helper::format_percentage( 0.255, 1 );     // '25.5%' (1 decimal point)
echo Woodev_Helper::format_percentage( 0.255, 1, true ); // '25.5%' (trim trailing zeros)
```

### number_format

Format a number with 2 decimal points, using a period for the decimal separator and no thousands separator. Commonly used for payment gateways.

```php
<?php
// Usage
echo Woodev_Helper::number_format( 1234.567 );  // '1234.57'
echo Woodev_Helper::number_format( 99.9 );      // '99.90'
echo Woodev_Helper::number_format( 100.0 );     // '100.00'
```

## WooCommerce Helpers

### get_order_line_items

Get order line items (products) as an array of `stdClass` objects.

```php
<?php
// Usage
$items = Woodev_Helper::get_order_line_items( $order );

foreach ( $items as $item ) {
    echo $item->name;        // item name (HTML-encoded)
    echo $item->description; // formatted item meta
    echo $item->quantity;    // item quantity
    echo $item->item_total;  // per-unit total (excl. tax)
    echo $item->line_total;  // line total (excl. tax)
    // Also available: $item->id, $item->meta, $item->product, $item->item
}
```

### is_order_virtual

Check if an order contains only virtual products.

```php
<?php
// Usage
if ( Woodev_Helper::is_order_virtual( $order ) ) {
    echo 'No shipping required';
}
```

### shop_has_virtual_products

Check if the shop has any virtual products.

```php
<?php
// Usage
if ( Woodev_Helper::shop_has_virtual_products() ) {
    echo 'Shop sells virtual products';
}
```

### get_wc_log_file_url

Get the URL to view a WooCommerce log file.

```php
<?php
// Usage
$log_url = Woodev_Helper::get_wc_log_file_url( 'my-plugin-log' );
echo '<a href="' . esc_url( $log_url ) . '">View Log</a>';
```

## Request Helpers

### get_posted_value

Get a value from POST request.

```php
<?php
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
<?php
// Usage
$value = Woodev_Helper::get_requested_value( 'param_name' );
$value = Woodev_Helper::get_requested_value( 'param_name', 'default' );
```

### is_ajax

Check if the current request is an AJAX request.

```php
<?php
// Usage
if ( Woodev_Helper::is_ajax() ) {
    // Handle AJAX request
}
```

### is_rest_api_request

Check if the current request is a REST API request.

```php
<?php
// Usage
if ( Woodev_Helper::is_rest_api_request() ) {
    // Handle REST API request
}
```

## Screen Detection

### get_current_screen

Get the current admin screen object.

```php
<?php
// Usage
$screen = Woodev_Helper::get_current_screen();

if ( $screen && 'shop_order' === $screen->post_type ) {
    echo 'On order screen';
}
```

### is_current_screen

Check if on a specific admin screen.

```php
<?php
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
<?php
// Usage
if ( Woodev_Helper::is_enhanced_admin_screen() ) {
    echo 'Using WooCommerce Admin';
}
```

### is_wc_navigation_enabled

Check if WooCommerce navigation is enabled.

```php
<?php
// Usage
if ( Woodev_Helper::is_wc_navigation_enabled() ) {
    echo 'WC Navigation active';
}
```

## Utility Functions

### multibyte_loaded

Check if the mbstring extension is loaded.

```php
<?php
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
<?php
// Usage
Woodev_Helper::enqueue_js( 'console.log("Hello");' );
```

### print_js

Output all enqueued inline JavaScript (from `enqueue_js()`) wrapped in a `<script>` tag. Takes no parameters.

```php
<?php
// First enqueue JS
Woodev_Helper::enqueue_js( 'console.log("Hello");' );
Woodev_Helper::enqueue_js( 'console.log("World");' );

// Then print all enqueued JS (typically hooked to wp_footer)
Woodev_Helper::print_js();
```

### let_to_num

Convert PHP ini size notation to bytes.

```php
<?php
// Usage
$bytes = Woodev_Helper::let_to_num( '128M' );    // 134217728
$bytes = Woodev_Helper::let_to_num( '1G' );      // 1073741824
$bytes = Woodev_Helper::let_to_num( '256K' );    // 262144
```

### trigger_error

Trigger a PHP error with context.

```php
<?php
// Usage
Woodev_Helper::trigger_error( 'Something went wrong', E_USER_WARNING );
```

### get_escaped_string_list

Get an escaped list of strings.

```php
<?php
// Usage
$escaped = Woodev_Helper::get_escaped_string_list( $strings );
```

### get_escaped_id_list

Get an escaped list of IDs.

```php
<?php
// Usage
$escaped = Woodev_Helper::get_escaped_id_list( $ids );
```

## Translation Helpers

### f__

Gettext `__()` wrapper for framework-translated strings. Uses the hardcoded `woodev-plugin-framework` text domain. Only use for strings already registered in the framework.

```php
<?php
// Usage (text domain is always 'woodev-plugin-framework')
$text = Woodev_Helper::f__( 'Hello World' );
```

### f_e

Gettext `_e()` wrapper for framework-translated strings. Echoes the translated string using the `woodev-plugin-framework` text domain.

```php
<?php
// Usage (text domain is always 'woodev-plugin-framework')
Woodev_Helper::f_e( 'Hello World' );
```

### f_x

Gettext `_x()` wrapper for framework-translated strings with context. Uses the `woodev-plugin-framework` text domain.

```php
<?php
// Usage (text domain is always 'woodev-plugin-framework')
$text = Woodev_Helper::f_x( 'Post', 'noun' );
```

## Render Helpers

### render_select2_ajax

Enqueue JavaScript required for AJAX search with Select2. Takes no parameters; configure via HTML `data-*` attributes on input elements with class `woodev-wc-enhanced-search`.

```php
<?php
// Usage: enqueue the Select2 AJAX script (call once)
Woodev_Helper::render_select2_ajax();

// Then render an input with data attributes:
// <input type="hidden" class="woodev-wc-enhanced-search" name="product_ids"
//     data-action="woocommerce_json_search_products"
//     data-nonce="<?php echo wp_create_nonce( 'search-products' ); ?>"
//     data-placeholder="Search for a product..."
//     data-allow_clear="true"
//     data-multiple="true" />
```

## Site Helpers

### get_site_name

Get the site name.

```php
<?php
// Usage
$site_name = Woodev_Helper::get_site_name();
echo "Welcome to {$site_name}";
```

### get_post

Get a post by ID.

```php
<?php
// Usage
$post = Woodev_Helper::get_post( $post_id );
```

### get_request

Get request data.

```php
<?php
// Usage
$request = Woodev_Helper::get_request();
$value = $request['key'] ?? null;
```

### is_woocommerce_active

Check if WooCommerce is active.

```php
<?php
// Usage
if ( Woodev_Helper::is_woocommerce_active() ) {
    echo 'WooCommerce is active';
}
```

### get_wc_version

Get the WooCommerce version.

```php
<?php
// Usage
$version = Woodev_Helper::get_wc_version();
echo "WooCommerce {$version}";
```

### wc_notice_count

Get the count of WooCommerce notices.

```php
<?php
// Usage
$count = Woodev_Helper::wc_notice_count( 'error' );
```

### wc_add_notice

Add a WooCommerce notice.

```php
<?php
// Usage
Woodev_Helper::wc_add_notice( 'Message', 'error' );
```

### wc_print_notice

Print a WooCommerce notice.

```php
<?php
// Usage (prints directly, returns void)
Woodev_Helper::wc_print_notice( 'Message', 'success' );
```

## String Conversion

### str_convert

Convert a string from Cyrillic to Latin transliteration using `Woodev_String_Conversion`.

```php
<?php
// Usage
$latin = Woodev_Helper::str_convert( $cyrillic_string );

// With context
$latin = Woodev_Helper::str_convert( $cyrillic_string, 'title' );
```

## Compatibility

### convert_country_code

Convert country code between ISO 3166 alpha-2 (2-letter) and alpha-3 (3-letter) formats. Automatically detects direction based on string length.

```php
<?php
// Usage: 2-letter to 3-letter
$iso3 = Woodev_Helper::convert_country_code( 'US' ); // 'USA'
$iso3 = Woodev_Helper::convert_country_code( 'RU' ); // 'RUS'

// Usage: 3-letter to 2-letter
$iso2 = Woodev_Helper::convert_country_code( 'USA' ); // 'US'
$iso2 = Woodev_Helper::convert_country_code( 'RUS' ); // 'RU'
```

### maybe_doing_it_early

Display a `wc_doing_it_wrong` notice if the provided hook has not yet run.

```php
<?php
// Usage
Woodev_Helper::maybe_doing_it_early( 'init', __METHOD__, '1.0.0' );
Woodev_Helper::maybe_doing_it_early( 'woocommerce_init', __METHOD__, '1.2.0' );
```

## Practical Examples

### Example 1: Form Processing

```php
<?php
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
<?php
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
<?php
class Order_Processor {

    public function process_order( WC_Order $order ) {
        // Check if virtual
        if ( Woodev_Helper::is_order_virtual( $order ) ) {
            $order->update_meta_data( '_shipping_required', 'no' );
        }

        // Get line items (returns stdClass[] with ->name, ->quantity, ->line_total, etc.)
        $items = Woodev_Helper::get_order_line_items( $order );

        // Log items
        foreach ( $items as $item ) {
            $this->log_item( $item );
        }

        // Format total (always 2 decimals, no thousands separator)
        $total = Woodev_Helper::number_format( (float) $order->get_total() );
        $order->add_order_note( "Total: {$total}" );
    }
}
```

### Example 4: Admin Screen Detection

```php
<?php
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
<?php
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
<?php
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
<?php
// Get value
$value = Woodev_Helper::get_posted_value( 'field' );

// Sanitize
$value = sanitize_text_field( $value );
```

### 3. Use Multi-byte Safe Functions

```php
<?php
// Helpers automatically use mb_* if available
$truncated = Woodev_Helper::str_truncate( $text, 100 );
```

### 4. Check for WooCommerce

```php
<?php
if ( ! Woodev_Helper::is_woocommerce_active() ) {
    return;
}
```

### 5. Use Formatting Helpers

```php
<?php
// Format numbers consistently (always 2 decimals, no thousands separator)
$total = Woodev_Helper::number_format( (float) $order->get_total() );

// Format percentages
$discount = Woodev_Helper::format_percentage( 0.25 );
```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [Admin Module](admin-module.md) — Admin pages
- [Settings API](settings-api.md) — Settings handling

---

*For more information, see [README.md](README.md).*
