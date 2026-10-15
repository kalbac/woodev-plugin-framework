---
title: Helpers reference
description: Look up the static helper methods the framework provides for strings, arrays, numbers, requests, WooCommerce data and orders.
---

In this page you will learn which static helpers the framework offers, grouped by purpose, with the signature and a one-line description of each, so that you do not write a second copy of a helper that already exists.

All helpers are static methods. You call them without creating an object, and they are available as soon as your plugin has started.

| Class | Use it for |
|---|---|
| `Woodev_Helper` | Strings, arrays, numbers, request input, WooCommerce environment checks, screens. |
| `Woodev_String_Conversion` | Transliterating Cyrillic text to Latin. |
| `Woodev_Plugin_Compatibility` | WooCommerce version checks and HPOS detection. |
| `Woodev_Order_Compatibility` | Reading and writing order data and URLs on both order storages. |
| `\Woodev\Framework\Woocommerce_Helper` | Order line items, virtual products. Available in plugins that extend `Woocommerce_Plugin`. |

## Strings

They match case-sensitively. With the `mbstring` extension loaded they handle UTF-8 text; without it they fall back to ASCII and drop non-ASCII characters, so declare `mbstring` in your plugin's `dependencies` if you use them on Cyrillic or other UTF-8 text.

| Method | Description |
|---|---|
| `Woodev_Helper::str_starts_with( string $haystack, string $needle ): bool` | Whether `$haystack` begins with `$needle`. |
| `Woodev_Helper::str_ends_with( string $haystack, string $needle ): bool` | Whether `$haystack` ends with `$needle`. |
| `Woodev_Helper::str_exists( string $haystack, string $needle ): bool` | Whether `$needle` occurs in `$haystack`. An empty needle gives `false`. |
| `Woodev_Helper::str_truncate( string $string, int $length, string $omission = '...' ): string` | Shorten a string to at most `$length` characters, including the omission. |
| `Woodev_Helper::str_to_sane_utf8( string $string ): string` | Remove invisible and unassigned characters, keeping letters, digits, punctuation, symbols and spaces. |
| `Woodev_Helper::str_convert( string $string, string $context = '' ): string` | Transliterate Cyrillic to Latin; see [Transliteration](#transliteration). |
| `Woodev_Helper::format_percentage( $fraction, $decimal_points = false, bool $trim_zeros = false ): string` | Format a fraction as a percentage string. |

```php
<?php
Woodev_Helper::str_starts_with( 'Привет, мир', 'Привет' ); // true
Woodev_Helper::str_truncate( 'Hello wonderful world', 10 ); // 'Hello w...'
Woodev_Helper::format_percentage( 0.255, 1 );               // '25.5%'
```

## Arrays and lists

| Method | Description |
|---|---|
| `Woodev_Helper::array_insert_after( array $array, string $insert_key, array $element ): array` | Insert `$element` right after `$insert_key`, keeping keys. Handy for adding a column or a settings field at a given place. |
| `Woodev_Helper::list_array_items( array $items, ?string $conjunction = null, string $separator = '' ): string` | Join items into "a, b, and c", with the conjunction translated. |
| `Woodev_Helper::array_join_natural( array $array, ?string $conjunction = 'and', ?string $pattern = '' ): string` | Join items in natural language; the conjunction is `'and'` or `'or'`. |
| `Woodev_Helper::array_to_xml( XMLWriter $xml_writer, $element_key, $element_value = [] )` | Write a nested array into an `XMLWriter`; the building block of XML requests. |

```php
<?php
Woodev_Helper::array_insert_after( [ 'a' => 1, 'c' => 3 ], 'a', [ 'b' => 2 ] ); // [ 'a' => 1, 'b' => 2, 'c' => 3 ]
Woodev_Helper::list_array_items( [ 'one', 'two', 'three' ] );                    // 'one, two, and three'
Woodev_Helper::array_join_natural( [ 'A', 'B' ], 'or' );                         // 'A or B'
```

`array_to_xml()` reads the array like this: a plain key is an element, `@attributes` holds attributes (put it first), `@value` is the text of an element that has attributes, a list repeats the element, and a value containing `<` or `>` is wrapped in CDATA. [Calling external APIs](../calling-external-apis/#xml-services) shows it inside a request class.

## Numbers

| Method | Description |
|---|---|
| `Woodev_Helper::number_format( float $number ): string` | Two decimals, a point as the separator, no thousands separator. The format payment and carrier APIs usually expect. |
| `Woodev_Helper::let_to_num( $size )` | Convert a PHP shorthand size such as `8M` to bytes. |

```php
<?php
Woodev_Helper::number_format( 1234.5 ); // '1234.50'
Woodev_Helper::let_to_num( '8M' );      // 8388608
```

## Request input

| Method | Description |
|---|---|
| `Woodev_Helper::get_posted_value( string $key, $default = '' )` | A value from `$_POST`; a string is unslashed and sanitised with `sanitize_text_field()`. |
| `Woodev_Helper::get_requested_value( string $key, $default = '' )` | The same for `$_REQUEST`. |

Neither method checks a nonce or a capability. Do that yourself before you act on the value. An array value is returned as it arrived, so sanitise its entries.

## WooCommerce and WordPress environment

| Method | Description |
|---|---|
| `Woodev_Helper::is_woocommerce_active(): bool` | Whether WooCommerce is among the active plugins (also network-wide). |
| `Woodev_Helper::get_wc_version(): ?string` | The WooCommerce version, or `null` when it is not active. |
| `Woodev_Helper::is_ajax(): bool` | Whether this is an AJAX request. |
| `Woodev_Helper::is_rest_api_request(): bool` | Whether this is a WooCommerce REST request. |
| `Woodev_Helper::get_site_name(): string` | The name of the current site, also on a multisite network. |
| `Woodev_Helper::get_wc_log_file_url( string $handle ): string` | The admin URL of the WooCommerce log file for a source name. |
| `Woodev_Helper::wc_add_notice( string $message, string $notice_type = 'success' ): void` | Add a storefront notice. Does nothing where WooCommerce notices are unavailable, such as the admin. |
| `Woodev_Helper::wc_print_notice( string $message, string $notice_type = 'success' ): void` | Print a notice at once. Same guard. |
| `Woodev_Helper::wc_notice_count( string $notice_type = '' ): int` | The number of queued notices, `0` where they are unavailable. |
| `Woodev_Helper::maybe_doing_it_early( string $hook, string $method, string $version ): void` | Report a call that happened before `$hook` has run. |
| `Woodev_Helper::convert_country_code( $code )` | Convert an ISO 3166 two-letter code to three letters, or the other way round. Returns the input when unknown. |

```php
<?php
Woodev_Helper::convert_country_code( 'RU' );  // 'RUS'
Woodev_Helper::convert_country_code( 'KAZ' ); // 'KZ'
```

## Admin screens and inline JavaScript

| Method | Description |
|---|---|
| `Woodev_Helper::get_current_screen(): ?WP_Screen` | The current admin screen, available earlier and more safely than WordPress's own function. |
| `Woodev_Helper::is_current_screen( string $id, string $prop = 'id' ): bool` | Whether a property of the current screen equals `$id`. |
| `Woodev_Helper::is_enhanced_admin_screen(): bool` | Whether the page is a WooCommerce Admin page. |
| `Woodev_Helper::is_wc_navigation_enabled(): bool` | Whether the WooCommerce navigation feature is on. |
| `Woodev_Helper::enqueue_js( $code )` | Queue inline JavaScript, printed once in the footer inside `jQuery( function ( $ ) { ... } )`. |

```php
<?php
Woodev_Helper::enqueue_js( "$( '#my-carrier-token' ).on( 'change', function () { /* ... */ } );" );
```

## Database lists

Use these to build the inside of an `IN ( ... )` clause.

| Method | Description |
|---|---|
| `Woodev_Helper::get_escaped_string_list( array $values ): string` | A comma-separated list of quoted, escaped strings. |
| `Woodev_Helper::get_escaped_id_list( array $ids ): string` | A comma-separated list of unique integers. |

## Filters you can repoint

`Woodev_Helper::filtered_url( string $hook, string $default, ...$extra_args ): string` applies a filter to a URL and checks the answer. A value that is not an `http(s)` URL falls back to the default, and the result has no trailing slash. Use it for an address that a developer should be able to repoint to a test server, without a faulty filter breaking the plugin:

```php
<?php
$base_url = Woodev_Helper::filtered_url( 'my_carrier_api_base_url', 'https://api.example.com/v1' );
```

The check covers the syntax only. It does not make an address trustworthy.

## Framework translation wrappers

`Woodev_Helper::f__()`, `f_e()` and `f_x()` are `__()`, `_e()` and `_x()` bound to the framework's text domain. They are for texts that already exist in the framework's catalogue. Never use them for your own strings; see [Internationalisation](../internationalisation/).

## Transliteration

`Woodev_String_Conversion::sanitize_title( $title, $context = '' )` replaces Cyrillic letters with Latin ones, using the table for the site's locale: Russian by default, plus Belarusian, Ukrainian, Bulgarian, Macedonian, Georgian, Kazakh and Hebrew. It decodes a URL-encoded title first, and the `query` context returns the title unchanged. `Woodev_Helper::str_convert()` is the shorter way to call it:

```php
<?php
Woodev_Helper::str_convert( 'Москва, улица Ленина' ); // 'Moskva, ulicza Lenina'
```

## WooCommerce versions and HPOS

`Woodev_Plugin_Compatibility` answers questions about the installed WooCommerce.

| Method | Description |
|---|---|
| `get_wc_version(): ?string` | The WooCommerce version, or `null` when undetermined. |
| `is_wc_version( string $version ): bool` | The installed version equals `$version`. |
| `is_wc_version_gte( string $version ): bool` | The installed version is `$version` or newer. |
| `is_wc_version_gt( string $version ): bool` | The installed version is newer than `$version`. |
| `is_wc_version_lt( string $version ): bool` | The installed version is older than `$version`. |
| `is_hpos_enabled(): bool` | Whether the shop stores orders in the HPOS tables. |
| `normalize_wc_screen_id( string $slug = 'wc-settings' ): string` | The screen ID of a WooCommerce page, for example `woocommerce_page_wc-settings`. |
| `convert_hr_to_bytes( string $value ): int` | Convert a shorthand size such as `128M` to bytes. |

```php
<?php
if ( Woodev_Plugin_Compatibility::is_wc_version_gte( '8.0' ) ) {
	// Use a newer WooCommerce API.
}
```

## Orders on both storages

A shop keeps orders either as posts or in the HPOS tables. `Woodev_Order_Compatibility` hides the difference. Prefer it over `get_post_meta()` and friends on orders; see [HPOS & Blocks compatibility](../hpos-and-blocks-compatibility/).

| Method | Description |
|---|---|
| `get_order_meta( $order, string $meta_key, bool $single = true )` | Read order meta. `$order` is an order object or an ID. |
| `update_order_meta( $order, string $meta_key, $meta_value )` | Set order meta. |
| `add_order_meta( $order, string $meta_key, $meta_value, bool $unique = false )` | Add order meta. |
| `delete_order_meta( $order, string $meta_key, $meta_value = '' )` | Delete order meta. |
| `order_meta_exists( $order, string $meta_key ): bool` | Whether the meta key exists. |
| `is_order( $post_order_or_id, $order_type = 'shop_order' ): bool` | Whether a value is an order of the given type. |
| `is_refund( $order_post_or_id ): bool` | Whether a value is a refund. |
| `get_edit_order_url( $order ): string` | The admin URL for editing an order. |
| `get_orders_screen_url(): string` | The admin URL of the orders list. |
| `is_orders_screen(): bool`, `is_order_edit_screen(): bool`, `is_order_screen(): bool` | Whether the current admin page is the orders list, the order editor, or either. |
| `is_orders_screen_for_status( $status ): bool` | Whether the orders list shows the given status. |
| `get_order_id_for_order_edit_screen(): ?int` | The ID of the order open in the editor. |
| `get_order_screen_id(): string` | The screen ID of the orders page. |
| `get_orders_table(): string`, `get_orders_meta_table(): string` | The names of the table and meta table that hold orders. |
| `get_order_post_types(): array` | The post types that hold orders. |

```php
<?php
Woodev_Order_Compatibility::update_order_meta( $order_id, '_my_carrier_shipment_id', $shipment_id );
$shipment_id = Woodev_Order_Compatibility::get_order_meta( $order_id, '_my_carrier_shipment_id' );
```

## WooCommerce data

`\Woodev\Framework\Woocommerce_Helper` works on WooCommerce objects.

| Method | Description |
|---|---|
| `get_order_line_items( \WC_Order $order ): array` | The order's products as objects with `id`, `name`, `description`, `quantity`, `item_total`, `line_total`, `meta`, `product` and `item`. |
| `is_order_virtual( \WC_Order $order ): bool` | Whether every product in the order is virtual. |
| `shop_has_virtual_products(): bool` | Whether the shop has a published virtual product. |
| `render_select2_ajax()` | Enable AJAX search on `select.woodev-wc-enhanced-search` elements; see below. |

```php
<?php
use Woodev\Framework\Woocommerce_Helper;

foreach ( Woocommerce_Helper::get_order_line_items( $order ) as $line ) {
	printf( '%s × %d', $line->name, $line->quantity );
}
```

`render_select2_ajax()` queues the script that turns every `select.woodev-wc-enhanced-search` element into a searchable select. Configure each element with data attributes: `data-action` is the AJAX action that returns a JSON object of `id => label`, `data-nonce` is its nonce, and `data-placeholder`, `data-allow_clear`, `data-minimum_input_length` and `data-request_data` adjust the behaviour.
