<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Helper' ) ) :

	/**
	 * Woodev Helper Class
	 *
	 * The purpose of this class is to centralize common utility functions that
	 * are commonly used in Woodev plugins
	 *
	 * @since 2.2.0
	 */
	class Woodev_Helper {

		/** encoding used for mb_*() string functions */
		const MB_ENCODING = 'UTF-8';

		/** String manipulation functions (all multi-byte safe) ***************/

		/**
		 * Returns true if the haystack string starts with needle
		 *
		 * Note: case-sensitive
		 *
		 * @param string $haystack
		 * @param string $needle
		 *
		 * @return bool
		 */
		public static function str_starts_with( string $haystack, string $needle ): bool {

			if ( self::multibyte_loaded() ) {

				if ( '' === $needle ) {
					return true;
				}

				return 0 === mb_strpos( $haystack, $needle, 0, self::MB_ENCODING );

			} else {

				$needle = self::str_to_ascii( $needle );

				if ( '' === $needle ) {
					return true;
				}

				return 0 === strpos( self::str_to_ascii( $haystack ), self::str_to_ascii( $needle ) );
			}
		}

		/**
		 * Return true if the haystack string ends with needle
		 *
		 * Note: case-sensitive
		 *
		 * @param string $haystack
		 * @param string $needle
		 *
		 * @return bool
		 */

		public static function str_ends_with( string $haystack, string $needle ): bool {

			if ( '' === $needle ) {
				return true;
			}

			if ( self::multibyte_loaded() ) {

				return mb_substr( $haystack, - mb_strlen( $needle, self::MB_ENCODING ), null, self::MB_ENCODING ) === $needle;

			} else {

				$haystack = self::str_to_ascii( $haystack );
				$needle   = self::str_to_ascii( $needle );

				return substr( $haystack, - strlen( $needle ) ) === $needle;
			}
		}

		/**
		 * Returns true if the needle exists in haystack
		 *
		 * Note: case-sensitive
		 *
		 * @param string $haystack
		 * @param string $needle
		 *
		 * @return bool
		 */

		public static function str_exists( string $haystack, string $needle ): bool {

			if ( self::multibyte_loaded() ) {

				if ( '' === $needle ) {
					return false;
				}

				return false !== mb_strpos( $haystack, $needle, 0, self::MB_ENCODING );

			} else {

				$needle = self::str_to_ascii( $needle );

				if ( '' === $needle ) {
					return false;
				}

				return false !== strpos( self::str_to_ascii( $haystack ), self::str_to_ascii( $needle ) );
			}
		}

		/**
		 * Truncates a given $string after a given $length if string is longer than
		 * $length. The last characters will be replaced with the $omission string
		 * for a total length not exceeding $length
		 *
		 * @param string $string   text to truncate
		 * @param int    $length   total desired length of string, including omission
		 * @param string $omission omission text, defaults to '...'
		 *
		 * @return string
		 */

		public static function str_truncate( string $string, int $length, string $omission = '...' ): string {

			if ( self::multibyte_loaded() ) {

				// bail if string doesn't need to be truncated
				if ( mb_strlen( $string, self::MB_ENCODING ) <= $length ) {
					return $string;
				}

				$length -= mb_strlen( $omission, self::MB_ENCODING );

				return mb_substr( $string, 0, $length, self::MB_ENCODING ) . $omission;

			} else {

				$string = self::str_to_ascii( $string );

				// bail if string doesn't need to be truncated
				if ( strlen( $string ) <= $length ) {
					return $string;
				}

				$length -= strlen( $omission );

				return substr( $string, 0, $length ) . $omission;
			}
		}

		/**
		 * Returns a string with all non-ASCII characters removed. This is useful
		 * for any string functions that expect only ASCII chars and can't
		 * safely handle UTF-8. Note this only allows ASCII chars in the range
		 * 33-126 (newlines/carriage returns are stripped)
		 *
		 * @param string $string string to make ASCII
		 *
		 * @return string
		 */
		public static function str_to_ascii( string $string ): string {

			// strip ASCII chars 32 and under
			$string = filter_var( $string, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW );

			// strip ASCII chars 127 and higher
			return filter_var( $string, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH );
		}

		/**
		 * Return a string with insane UTF-8 characters removed, like invisible
		 * characters, unused code points, and other weirdness. It should
		 * accept the common types of characters defined in Unicode.
		 *
		 * The following are allowed characters:
		 *
		 * p{L} - any kind of letter from any language
		 * p{Mn} - a character intended to be combined with another character without taking up extra space (e.g. accents, umlauts, etc.)
		 * p{Mc} - a character intended to be combined with another character that takes up extra space (vowel signs in many Eastern languages)
		 * p{Nd} - a digit zero through nine in any script except ideographic scripts
		 * p{Zs} - a whitespace character that is invisible, but does take up space
		 * p{P} - any kind of punctuation character
		 * p{Sm} - any mathematical symbol
		 * p{Sc} - any currency sign
		 *
		 * pattern definitions from http://www.regular-expressions.info/unicode.html
		 *
		 *
		 * @param string $string
		 *
		 * @return string
		 */
		public static function str_to_sane_utf8( string $string ): string {
			$sane_string = preg_replace( '/[^\p{L}\p{Mn}\p{Mc}\p{Nd}\p{Zs}\p{P}\p{Sm}\p{Sc}]/u', '', $string );

			return ( is_null( $sane_string ) || false === $sane_string ) ? $string : $sane_string;
		}

		/**
		 * Formats a number as a percentage.
		 *
		 * @NOTE The second and third parameter below are directly passed to {@see wc_format_decimal()} in case the decimal output or rounding needs to be tweaked.
		 *
		 * @param float|int|string $fraction       the fraction to format as percentage
		 * @param int|string|false $decimal_points number of decimal points to use, empty string to use {@see woocommerce_price_num_decimals(), or false to avoid rounding (optional, default).
		 * @param bool             $trim_zeros     from end of string (optional, default false)
		 *
		 * @return string fraction formatted as percentage
		 */
		public static function format_percentage( $fraction, $decimal_points = false, bool $trim_zeros = false ): string {
			return sprintf( '%s%%', (string) wc_format_decimal( $fraction * 100, $decimal_points, $trim_zeros ) );
		}

		/**
		 * Helper method to check if the multibyte extension is loaded, which
		 * indicates it's safe to use the mb_*() string methods
		 *
		 * @return bool
		 */
		protected static function multibyte_loaded(): bool {
			return extension_loaded( 'mbstring' );
		}

		/** Array functions ***************************************************/


		/**
		 * Insert the given element after the given key in the array
		 *
		 * Sample usage:
		 *
		 * given
		 *
		 * array( 'item_1' => 'foo', 'item_2' => 'bar' )
		 *
		 * array_insert_after( $array, 'item_1', array( 'item_1.5' => 'w00t' ) )
		 *
		 * becomes
		 *
		 * array( 'item_1' => 'foo', 'item_1.5' => 'w00t', 'item_2' => 'bar' )
		 *
		 * @param array  $array      array to insert the given element into
		 * @param string $insert_key key to insert given element after
		 * @param array  $element    element to insert into array
		 *
		 * @return array
		 */
		public static function array_insert_after( array $array, string $insert_key, array $element ): array {

			$new_array = array();

			foreach ( $array as $key => $value ) {

				$new_array[ $key ] = $value;

				if ( $insert_key == $key ) {

					foreach ( $element as $k => $v ) {
						$new_array[ $k ] = $v;
					}
				}
			}

			return $new_array;
		}

		/**
		 * Convert array into XML by recursively generating child elements
		 *
		 * First instantiate a new XML writer object:
		 *
		 * $xml = new XMLWriter();
		 *
		 * Open in memory (alternatively you can use a local URI for file output)
		 *
		 * $xml->openMemory();
		 *
		 * Then start the document
		 *
		 * $xml->startDocument( '1.0', 'UTF-8' );
		 *
		 * Don't forget to end the document and output the memory
		 *
		 * $xml->endDocument();
		 *
		 * $your_xml_string = $xml->outputMemory();
		 *
		 * @param XMLWriter    $xml_writer    XML writer instance
		 * @param string|array $element_key   name for element, e.g. <per_page>
		 * @param string|array $element_value value for element, e.g. 100
		 */

		public static function array_to_xml( XMLWriter $xml_writer, $element_key, $element_value = array() ) {

			if ( is_array( $element_value ) ) {

				// handle attributes
				if ( '@attributes' === $element_key ) {

					foreach ( $element_value as $attribute_key => $attribute_value ) {

						$xml_writer->startAttribute( $attribute_key );
						$xml_writer->text( $attribute_value );
						$xml_writer->endAttribute();
					}

					return;
				}

				// handle multi-elements (e.g. multiple <Order> elements)
				if ( is_numeric( key( $element_value ) ) ) {

					// recursively generate child elements
					foreach ( $element_value as $child_element_key => $child_element_value ) {

						$xml_writer->startElement( $element_key );

						foreach ( $child_element_value as $sibling_element_key => $sibling_element_value ) {
							self::array_to_xml( $xml_writer, $sibling_element_key, $sibling_element_value );
						}

						$xml_writer->endElement();
					}

				} else {

					// start root element
					$xml_writer->startElement( $element_key );

					// recursively generate child elements
					foreach ( $element_value as $child_element_key => $child_element_value ) {
						self::array_to_xml( $xml_writer, $child_element_key, $child_element_value );
					}

					// end root element
					$xml_writer->endElement();
				}

			} else {

				// handle single elements
				if ( '@value' === $element_key ) {

					$xml_writer->text( $element_value );

				} else {

					// wrap element in CDATA tags if it contains illegal characters
					if ( false !== strpos( $element_value, '<' ) || false !== strpos( $element_value, '>' ) ) {

						$xml_writer->startElement( $element_key );
						$xml_writer->writeCdata( $element_value );
						$xml_writer->endElement();

					} else {

						$xml_writer->writeElement( $element_key, $element_value );
					}
				}
			}
		}

		/**
		 * Lists an array as text.
		 *
		 * Takes an array and returns a list like "one, two, three, and four"
		 * with a (mandatory) oxford comma.
		 *
		 * @param array       $items       items to list
		 * @param string|null $conjunction coordinating conjunction, like "or" or "and"
		 * @param string      $separator   list separator, like a comma
		 *
		 * @return string
		 */
		public static function list_array_items( array $items, string $conjunction = null, string $separator = '' ): string {

			if ( ! is_string( $conjunction ) ) {
				$conjunction = _x( 'and', 'coordinating conjunction for a list of items: a, b, and c', 'woodev-plugin-framework' );
			}

			// append the conjunction to the last item
			if ( count( $items ) > 1 ) {

				$last_item = array_pop( $items );

				array_push( $items, trim( "{$conjunction} {$last_item}" ) );

				// only use a comma if needed and no separator was passed
				if ( count( $items ) < 3 ) {
					$separator = ' ';
				} elseif ( ! is_string( $separator ) || '' === $separator ) {
					$separator = ', ';
				}
			}

			return implode( $separator, $items );
		}

		/**
		 * Joins the array elements into a string using natural language.
		 *
		 * For example, the array `['Russia', 'Ukraine', 'US']` would become `'Russia, Ukraine, and US'`.
		 *
		 * When using this method to create user-facing text, it is recommended to supply a localized conjunction.
		 *
		 * @since 1.3.0
		 *
		 * @param array<scalar> $array
		 * @param string|null $conjunction one of 'and' or 'or'
		 * @param string|null $pattern a custom sprintf pattern, with placeholders %1$s and %2$s
		 * @return string
		 */
		public static function array_join_natural( array $array, ?string $conjunction = 'and', ?string $pattern = '' ) : string {
			$last = array_pop( $array );

			if ( $array ) {
				if ( ! $pattern ) {
					switch ( $conjunction ) {
						case 'or':
							$pattern = _n( '%1$s or %2$s', '%1$s, or %2$s', count( $array ), 'woodev-plugin-framework' );
							break;

						case 'and':
						default:
							$pattern = _n( '%1$s and %2$s', '%1$s, and %2$s', count( $array ), 'woodev-plugin-framework' );
							break;
					}
				}

				return sprintf( $pattern, implode( ', ', $array ), $last );
			}

			return (string) $last;
		}

		/** Number helper functions *******************************************/


		/**
		 * Format a number with 2 decimal points, using a period for the decimal
		 * separator and no thousands separator.
		 *
		 * Commonly used for payment gateways which require amounts in this format.
		 *
		 * @param float $number
		 *
		 * @return string
		 */
		public static function number_format( float $number ): string {
			return number_format( ( float ) $number, 2, '.', '' );
		}

		/** WooCommerce helper functions **************************************/


		/**
		 * Gets order line items (products) as an array of objects.
		 *
		 * Object properties:
		 *
		 * + id          - item ID
		 * + name        - item name, usually product title, processed through htmlentities()
		 * + description - formatted item meta (e.g. Size: Medium, Color: blue), processed through htmlentities()
		 * + quantity    - item quantity
		 * + item_total  - item total (line total divided by quantity, excluding tax & rounded)
		 * + line_total  - line item total (excluding tax & rounded)
		 * + meta        - formatted item meta array
		 * + product     - item product or null if getting product from item failed
		 * + item        - raw item array
		 *
		 * @param WC_Order $order
		 *
		 * @return stdClass[] array of line item objects
		 */
		public static function get_order_line_items( WC_Order $order ): array {

			$line_items = [];

			/** @var WC_Order_Item_Product $item */
			foreach ( $order->get_items() as $id => $item ) {

				$line_item = new stdClass();
				$product   = $item->get_product();
				$name      = $item->get_name();
				$quantity  = $item->get_quantity();
				$sku       = $product instanceof WC_Product ? $product->get_sku() : '';
				$item_desc = [];

				// add SKU to description if available
				if ( ! empty( $sku ) ) {
					$item_desc[] = sprintf( 'SKU: %s', $sku );
				}

				$meta_data = $item->get_formatted_meta_data( '-', true );
				$item_meta = [];

				foreach ( $meta_data as $meta ) {
					$item_meta[] = array(
						'label' => $meta->display_key,
						'value' => $meta->value,
					);
				}

				if ( ! empty( $item_meta ) ) {
					foreach ( $item_meta as $meta ) {
						$item_desc[] = sprintf( '%s: %s', $meta['label'], $meta['value'] );
					}
				}

				$item_desc = implode( ', ', $item_desc );

				$line_item->id          = $id;
				$line_item->name        = htmlentities( $name, ENT_QUOTES, 'UTF-8', false );
				$line_item->description = htmlentities( $item_desc, ENT_QUOTES, 'UTF-8', false );
				$line_item->quantity    = $quantity;
				$line_item->item_total  = isset( $item['recurring_line_total'] ) ? $item['recurring_line_total'] : $order->get_item_total( $item );
				$line_item->line_total  = $order->get_line_total( $item );
				$line_item->meta        = $item_meta;
				$line_item->product     = is_object( $product ) ? $product : null;
				$line_item->item        = $item;

				$line_items[] = $line_item;
			}

			return $line_items;
		}

		/**
		 * Determines if an order contains only virtual products.
		 *
		 * @param WC_Order $order the order object
		 *
		 * @return bool
		 */
		public static function is_order_virtual( WC_Order $order ): bool {

			$is_virtual = true;

			/** @var WC_Order_Item_Product $item */
			foreach ( $order->get_items() as $item ) {

				$product = $item->get_product();

				// once we've found one non-virtual product we know we're done, break out of the loop
				if ( $product && ! $product->is_virtual() ) {

					$is_virtual = false;
					break;
				}
			}

			return $is_virtual;
		}

		/**
		 * Determines if a shop has any published virtual products.
		 *
		 * @return bool
		 */
		public static function shop_has_virtual_products(): bool {

			$virtual_products = wc_get_products( array(
				'virtual' => true,
				'status'  => 'publish',
				'limit'   => 1,
			) );

			return sizeof( $virtual_products ) > 0;
		}

		/**
		 * Safely gets a value from $_POST.
		 *
		 * If the expected data is a string also trims it.
		 *
		 * @param string                           $key     posted data key
		 * @param int|float|array|bool|null|string $default default data type to return (default empty string)
		 *
		 * @return int|float|array|bool|null|string posted data value if key found, or default
		 */
		public static function get_posted_value( string $key, $default = '' ) {

			$value = $default;

			if ( isset( $_POST[ $key ] ) ) {
				$value = is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $_POST[ $key ];
			}

			return $value;
		}

		/**
		 * Safely gets a value from $_REQUEST.
		 *
		 * If the expected data is a string also trims it.
		 *
		 * @param string                           $key     posted data key
		 * @param int|float|array|bool|null|string $default default data type to return (default empty string)
		 *
		 * @return int|float|array|bool|null|string posted data value if key found, or default
		 */
		public static function get_requested_value( string $key, $default = '' ) {

			$value = $default;

			if ( isset( $_REQUEST[ $key ] ) ) {
				$value = is_string( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : $_REQUEST[ $key ];
			}

			return $value;
		}

		/**
		 * Get the count of notices added, either for all notices (default) or for one
		 * particular notice type specified by $notice_type.
		 *
		 * WC notice functions are not available in the admin
		 *
		 * @param string $notice_type The name of the notice type - either error, success or notice. [optional]
		 *
		 * @return int
		 */
		public static function wc_notice_count( string $notice_type = '' ): int {

			if ( function_exists( 'wc_notice_count' ) ) {
				return wc_notice_count( $notice_type );
			}

			return 0;
		}


		/**
		 * Add and store a notice.
		 *
		 * WC notice functions are not available in the admin
		 *
		 * @param string $message     The text to display in the notice.
		 * @param string $notice_type The singular name of the notice type - either error, success or notice. [optional]
		 */
		public static function wc_add_notice( string $message, string $notice_type = 'success' ): void {

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $message, $notice_type );
			}
		}

		/**
		 * Print a single notice immediately
		 *
		 * WC notice functions are not available in the admin
		 *
		 * @param string $message     The text to display in the notice.
		 * @param string $notice_type The singular name of the notice type - either error, success or notice. [optional]
		 */
		public static function wc_print_notice( string $message, string $notice_type = 'success' ): void {

			if ( function_exists( 'wc_print_notice' ) ) {
				wc_print_notice( $message, $notice_type );
			}
		}


		/**
		 * Gets the full URL to the log file for a given $handle
		 *
		 * @param string $handle log handle
		 *
		 * @return string URL to the WC log file identified by $handle
		 */
		public static function get_wc_log_file_url( string $handle ): string {
			return admin_url( sprintf( 'admin.php?page=wc-status&tab=logs&log_file=%s-%s-log', $handle, sanitize_file_name( wp_hash( $handle ) ) ) );
		}


		/**
		 * Gets the current WordPress site name.
		 *
		 * This is helpful for retrieving the actual site name instead of the
		 * network name on multisite installations.
		 *
		 * @return string
		 */
		public static function get_site_name(): string {
			return ( is_multisite() ) ? get_blog_details()->blogname : get_bloginfo( 'name' );
		}

		/**
		 * Safely gets a value from $_POST.
		 *
		 * @param string $key posted data key
		 *
		 * @deprecated 1.3.0
		 */
		public static function get_post( string $key ) {

			wc_deprecated_function( __METHOD__, '1.3.0', __CLASS__ . '::get_posted_value( $key )' );

			return static::get_posted_value( $key );
		}

		/**
		 * Safely gets a value from $_REQUEST.
		 *
		 * @param string $key posted data key
		 *
		 * @deprecated 1.3.0
		 */
		public static function get_request( $key ) {

			wc_deprecated_function( __METHOD__, '1.3.0', __CLASS__ . '::get_requested_value( $key )' );

			return static::get_requested_value( $key );
		}

		/**
		 * Checks if is active Woocommerce
		 *
		 * @return bool
		 */
		public static function is_woocommerce_active(): bool {

			$active_plugins = (array) get_option( 'active_plugins', array() );

			if ( is_multisite() ) {
				$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
			}

			return in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins );
		}

		/**
		 * Returns the Woocommerce current version if it is active
		 *
		 * @return null|string
		 */
		public static function get_wc_version(): ?string {

			if ( self::is_woocommerce_active() ) {
				if ( defined( 'WC_VERSION' ) && WC_VERSION ) {
					return WC_VERSION;
				}
				if ( defined( 'WOOCOMMERCE_VERSION' ) && WOOCOMMERCE_VERSION ) {
					return WOOCOMMERCE_VERSION;
				}
			}

			return null;
		}

		/**
		 * Checks, current request is AJAX or not
		 *
		 * @return bool
		 */
		public static function is_ajax(): bool {
			return function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : defined( 'DOING_AJAX' );
		}

		public static function enqueue_js( $code ) {
			global $woodev_queued_js;

			if ( empty( $woodev_queued_js ) ) {
				$woodev_queued_js = '';
			}

			$woodev_queued_js .= "\n" . $code . "\n";
		}

		public static function print_js() {
			global $woodev_queued_js;

			if ( ! empty( $woodev_queued_js ) ) {

				$woodev_queued_js = wp_check_invalid_utf8( $woodev_queued_js );
				$woodev_queued_js = preg_replace( '/&#(x)?0*(?(1)27|39);?/i', "'", $woodev_queued_js );
				$woodev_queued_js = str_replace( "\r", '', $woodev_queued_js );

				$js = "<!-- Woodev JavaScript -->\n<script type=\"text/javascript\">\njQuery(function($) { $woodev_queued_js });\n</script>\n";

				echo apply_filters( 'woodev_queued_js', $js );

				unset( $woodev_queued_js );
			}
		}

		public static function let_to_num( $size ) {
			$l    = substr( $size, - 1 );
			$ret  = substr( $size, 0, - 1 );
			$byte = 1024;

			switch ( strtoupper( $l ) ) {
				case 'P':
					$ret *= 1024;
				case 'T':
					$ret *= 1024;
				case 'G':
					$ret *= 1024;
				case 'M':
					$ret *= 1024;
				case 'K':
					$ret *= 1024;
			}

			return $ret;
		}

		/**
		 * Triggers a PHP error.
		 *
		 * This wrapper method ensures AJAX isn't broken in the process.
		 *
		 * @param string $message the error message
		 * @param int    $type    Optional. The error type. Defaults to E_USER_NOTICE
		 */
		public static function trigger_error( $message, $type = E_USER_NOTICE ) {

			if ( is_callable( array( __CLASS__, 'is_ajax' ) ) && self::is_ajax() ) {

				switch ( $type ) {

					case E_USER_NOTICE:
						$prefix = 'Уведомление: ';
						break;

					case E_USER_WARNING:
						$prefix = 'Предупреждение: ';
						break;

					default:
						$prefix = '';
				}

				error_log( $prefix . $message );

			} else {
				trigger_error( $message, $type );
			}
		}

		/**
		 * Converts an array of strings to a comma separated list of strings, escaped for SQL use.
		 *
		 * This can be safely used in SQL IN clauses.
		 *
		 *
		 * @param string[] $values
		 *
		 * @return string
		 */
		public static function get_escaped_string_list( array $values ): string {
			global $wpdb;

			return (string) $wpdb->prepare( implode( ', ', array_fill( 0, count( $values ), '%s' ) ), $values );
		}


		/**
		 * Converts an array of numerical integers into a comma separated list of IDs.
		 *
		 * This can be safely used for SQL IN clauses.
		 *
		 *
		 * @param int[] $ids
		 *
		 * @return string
		 */
		public static function get_escaped_id_list( array $ids ): string {

			return implode( ',', array_unique( array_map( 'intval', $ids ) ) );
		}

		/**
		 * Converts input string $string from Cyrillic to Latin
		 *
		 * @param string $string String need to convert
		 * @param string $context
		 *
		 * @return string
		 */
		public static function str_convert( string $string, string $context = '' ): string {

			if ( ! class_exists( 'Woodev_String_Conversion' ) ) {
				require_once( 'class-string-conversation.php' );
			}

			return Woodev_String_Conversion::sanitize_title( $string, $context );
		}

		/** Framework translation functions ***********************************/


		/**
		 * Gettext `__()` wrapper for framework-translated strings
		 *
		 * Warning! This function should only be used if an existing
		 * translation from the framework is to be used. It should
		 * never be called for plugin-specific or untranslated strings!
		 * Untranslated = not registered via string literal.
		 *
		 * @param string $text
		 *
		 * @return string translated text
		 */
		public static function f__( string $text ): string {

			return __( $text, 'woodev-plugin-framework' );
		}


		/**
		 * Gettext `_e()` wrapper for framework-translated strings
		 *
		 * Warning! This function should only be used if an existing
		 * translation from the framework is to be used. It should
		 * never be called for plugin-specific or untranslated strings!
		 * Untranslated = not registered via string literal.
		 *
		 * @param string $text
		 */
		public static function f_e( string $text ): void {

			_e( $text, 'woodev-plugin-framework' );
		}


		/**
		 * Gettext `_x()` wrapper for framework-translated strings
		 *
		 * Warning! This function should only be used if an existing
		 * translation from the framework is to be used. It should
		 * never be called for plugin-specific or untranslated strings!
		 * Untranslated = not registered via string literal.
		 *
		 * @param string $text
		 * @param string $context
		 *
		 * @return string translated text
		 */
		public static function f_x( string $text, string $context ): string {

			return _x( $text, $context, 'woodev-plugin-framework' );
		}

		/** JavaScript helper functions ***************************************/


		/**
		 * Enhanced search JavaScript (Select2)
		 *
		 * Enqueues JavaScript required for AJAX search with Select2.
		 *
		 * Example usage:
		 *    <input type="hidden" class="woodev-wc-enhanced-search" name="category_ids" data-multiple="true" style="min-width: 300px;"
		 *       data-action="wc_cart_notices_json_search_product_categories"
		 *       data-nonce="<?php echo wp_create_nonce( 'search-categories' ); ?>"
		 *       data-request_data = "<?php echo esc_attr( json_encode( array( 'field_name' => 'something_exciting', 'default' => 'default_label' ) ) ) ?>"
		 *       data-placeholder="<?php esc_attr_e( 'Search for a category&hellip;', 'wc-cart-notices' ) ?>"
		 *       data-allow_clear="true"
		 *       data-selected="<?php
		 *          $json_ids    = array();
		 *          if ( isset( $notice->data['categories'] ) ) {
		 *             foreach ( $notice->data['categories'] as $value => $title ) {
		 *                $json_ids[ esc_attr( $value ) ] = esc_html( $title );
		 *             }
		 *          }
		 *          echo esc_attr( json_encode( $json_ids ) );
		 *       ?>"
		 *       value="<?php echo implode( ',', array_keys( $json_ids ) ); ?>" />
		 *
		 * - `data-selected` can be a json encoded associative array like Array( 'key' => 'value' )
		 * - `value` should be a comma-separated list of selected keys
		 * - `data-request_data` can be used to pass any additional data to the AJAX request
		 */
		public static function render_select2_ajax() {

			if ( ! did_action( 'woodev_wc_select2_ajax_rendered' ) ) {

				$javascript = "( function(){
				if ( ! $().select2 ) return;
			";

				$javascript .= "

				function getEnhancedSelectFormatString() {

					if ( 'undefined' !== typeof wc_select_params ) {
						wc_enhanced_select_params = wc_select_params;
					}

					if ( 'undefined' === typeof wc_enhanced_select_params ) {
						return {};
					}

					var formatString = {
						formatMatches: function( matches ) {
							if ( 1 === matches ) {
								return wc_enhanced_select_params.i18n_matches_1;
							}

							return wc_enhanced_select_params.i18n_matches_n.replace( '%qty%', matches );
						},
						formatNoMatches: function() {
							return wc_enhanced_select_params.i18n_no_matches;
						},
						formatAjaxError: function( jqXHR, textStatus, errorThrown ) {
							return wc_enhanced_select_params.i18n_ajax_error;
						},
						formatInputTooShort: function( input, min ) {
							var number = min - input.length;

							if ( 1 === number ) {
								return wc_enhanced_select_params.i18n_input_too_short_1
							}

							return wc_enhanced_select_params.i18n_input_too_short_n.replace( '%qty%', number );
						},
						formatInputTooLong: function( input, max ) {
							var number = input.length - max;

							if ( 1 === number ) {
								return wc_enhanced_select_params.i18n_input_too_long_1
							}

							return wc_enhanced_select_params.i18n_input_too_long_n.replace( '%qty%', number );
						},
						formatSelectionTooBig: function( limit ) {
							if ( 1 === limit ) {
								return wc_enhanced_select_params.i18n_selection_too_long_1;
							}

							return wc_enhanced_select_params.i18n_selection_too_long_n.replace( '%qty%', number );
						},
						formatLoadMore: function( pageNumber ) {
							return wc_enhanced_select_params.i18n_load_more;
						},
						formatSearching: function() {
							return wc_enhanced_select_params.i18n_searching;
						}
					};

					return formatString;
				}
			";

				if ( version_compare( self::get_wc_version(), '3.0', '>=' ) ) {

					$javascript .= "

					$( 'select.woodev-wc-enhanced-search' ).filter( ':not(.enhanced)' ).each( function() {

						var select2_args = {
							allowClear:         $( this ).data( 'allow_clear' ) ? true : false,
							placeholder:        $( this ).data( 'placeholder' ),
							minimumInputLength: $( this ).data( 'minimum_input_length' ) ? $( this ).data( 'minimum_input_length' ) : '3',
							escapeMarkup:       function( m ) {
								return m;
							},
							ajax:               {
								url:            '" . esc_js( admin_url( 'admin-ajax.php' ) ) . "',
								dataType:       'json',
								cache:          true,
								delay:          250,
								data:           function( params ) {
									return {
										term:         params.term,
										request_data: $( this ).data( 'request_data' ) ? $( this ).data( 'request_data' ) : {},
										action:       $( this ).data( 'action' ) || 'woocommerce_json_search_products_and_variations',
										security:     $( this ).data( 'nonce' )
									};
								},
								processResults: function( data, params ) {
									var terms = [];
									if ( data ) {
										$.each( data, function( id, text ) {
											terms.push( { id: id, text: text } );
										});
									}
									return { results: terms };
								}
							}
						};

						select2_args = $.extend( select2_args, getEnhancedSelectFormatString() );

						$( this ).select2( select2_args ).addClass( 'enhanced' );
					} );
				";

				} else {

					$javascript .= "

					$( ':input.woodev-wc-enhanced-search' ).filter( ':not(.enhanced)' ).each( function() {

						var select2_args = {
							allowClear:         $( this ).data( 'allow_clear' ) ? true : false,
							placeholder:        $( this ).data( 'placeholder' ),
							minimumInputLength: $( this ).data( 'minimum_input_length' ) ? $( this ).data( 'minimum_input_length' ) : '3',
							escapeMarkup:       function( m ) {
								return m;
							},
							ajax:               {
								url:         '" . esc_js( admin_url( 'admin-ajax.php' ) ) . "',
								dataType:    'json',
								cache:       true,
								quietMillis: 250,
								data:        function( term, page ) {
									return {
										term:         term,
										request_data: $( this ).data( 'request_data' ) ? $( this ).data( 'request_data' ) : {},
										action:       $( this ).data( 'action' ) || 'woocommerce_json_search_products_and_variations',
										security:     $( this ).data( 'nonce' )
									};
								},
								results:     function( data, page ) {
									var terms = [];
									if ( data ) {
										$.each( data, function( id, text ) {
											terms.push( { id: id, text: text } );
										});
									}
									return { results: terms };
								}
							}
						};

						if ( $( this ).data( 'multiple' ) === true ) {

							select2_args.multiple        = true;
							select2_args.initSelection   = function( element, callback ) {
								var data     = $.parseJSON( element.attr( 'data-selected' ) );
								var selected = [];

								$( element.val().split( ',' ) ).each( function( i, val ) {
									selected.push( { id: val, text: data[ val ] } );
								} );
								return callback( selected );
							};
							select2_args.formatSelection = function( data ) {
								return '<div class=\"selected-option\" data-id=\"' + data.id + '\">' + data.text + '</div>';
							};

						} else {

							select2_args.multiple        = false;
							select2_args.initSelection   = function( element, callback ) {
								var data = {id: element.val(), text: element.attr( 'data-selected' )};
								return callback( data );
							};
						}

						select2_args = $.extend( select2_args, getEnhancedSelectFormatString() );

						$( this ).select2( select2_args ).addClass( 'enhanced' );
					} );
				";
				}

				$javascript .= "} )();";

				self::enqueue_js( $javascript );

				do_action( 'woodev_wc_select2_ajax_rendered' );
			}
		}

		/**
		 * Gets the WordPress current screen.
		 *
		 * @return WP_Screen|null
		 * @see get_current_screen() replacement which is always available, unlike the WordPress core function
		 *
		 */
		public static function get_current_screen(): ?WP_Screen {
			global $current_screen;

			return $current_screen ?: null;
		}

		/**
		 * Checks if the current screen matches a specified ID.
		 *
		 * This helps avoiding using the get_current_screen() function which is not always available,
		 * or setting the substitute global $current_screen every time a check needs to be performed.
		 *
		 *
		 * @param string $id   id (or property) to compare
		 * @param string $prop optional property to compare, defaults to screen id
		 *
		 * @return bool
		 */
		public static function is_current_screen( string $id, string $prop = 'id' ): bool {
			$current_screen = static::get_current_screen();

			return isset( $current_screen->$prop ) && $id === $current_screen->$prop;
		}

		/**
		 * Determines if viewing an enhanced admin screen.
		 *
		 * @return bool
		 */
		public static function is_enhanced_admin_screen(): bool {

			return is_admin() && Woodev_Plugin_Compatibility::is_enhanced_admin_available() && class_exists( '\Automattic\WooCommerce\Admin\PageController' ) && ( \Automattic\WooCommerce\Admin\PageController::is_admin_page() || \Automattic\WooCommerce\Admin\PageController::is_embed_page() );
		}


		/**
		 * Determines whether the new WooCommerce enhanced navigation is supported and enabled.
		 *
		 * @return bool
		 */
		public static function is_wc_navigation_enabled(): bool {

			return
				is_callable( array(
					\Automattic\WooCommerce\Admin\Features\Navigation\Screen::class,
					'register_post_type'
				) ) &&
				is_callable( array(
					\Automattic\WooCommerce\Admin\Features\Navigation\Menu::class,
					'add_plugin_item'
				) ) &&
				is_callable( array(
					\Automattic\WooCommerce\Admin\Features\Navigation\Menu::class,
					'add_plugin_category'
				) ) &&
				is_callable( array( \Automattic\WooCommerce\Admin\Features\Features::class, 'is_enabled' ) ) &&
				\Automattic\WooCommerce\Admin\Features\Features::is_enabled( 'navigation' );
		}


		/**
		 * Determines if the current request is for a WC REST API endpoint.
		 *
		 * @return bool
		 * @see WooCommerce::is_rest_api_request()
		 *
		 */
		public static function is_rest_api_request(): bool {

			if ( is_callable( 'WC' ) && is_callable( [ WC(), 'is_rest_api_request' ] ) ) {
				return (bool) WC()->is_rest_api_request();
			}

			if ( empty( $_SERVER['REQUEST_URI'] ) || ! function_exists( 'rest_get_url_prefix' ) ) {
				return false;
			}

			$rest_prefix         = trailingslashit( rest_get_url_prefix() );
			$is_rest_api_request = false !== strpos( $_SERVER['REQUEST_URI'], $rest_prefix );

			/* applies WooCommerce core filter */

			return (bool) apply_filters( 'woocommerce_is_rest_api_request', $is_rest_api_request );
		}


		/**
		 * Displays a notice if the provided hook has not yet run.
		 *
		 * @param string $hook    action hook to check
		 * @param string $method  method/function name
		 * @param string $version version the notice was added
		 */
		public static function maybe_doing_it_early( string $hook, string $method, string $version ): void {

			if ( ! did_action( $hook ) ) {
				wc_doing_it_wrong( $method, "This should only be called after '{$hook}'", $version );
			}
		}

		public static function convert_country_code( $code ) {

			$countries = array(
				'AF' => 'AFG',
				'AL' => 'ALB',
				'DZ' => 'DZA',
				'AD' => 'AND',
				'AO' => 'AGO',
				'AG' => 'ATG',
				'AR' => 'ARG',
				'AM' => 'ARM',
				'AU' => 'AUS',
				'AT' => 'AUT',
				'AZ' => 'AZE',
				'BS' => 'BHS',
				'BH' => 'BHR',
				'BD' => 'BGD',
				'BB' => 'BRB',
				'BY' => 'BLR',
				'BE' => 'BEL',
				'BZ' => 'BLZ',
				'BJ' => 'BEN',
				'BT' => 'BTN',
				'BO' => 'BOL',
				'BA' => 'BIH',
				'BW' => 'BWA',
				'BR' => 'BRA',
				'BN' => 'BRN',
				'BG' => 'BGR',
				'BF' => 'BFA',
				'BI' => 'BDI',
				'KH' => 'KHM',
				'CM' => 'CMR',
				'CA' => 'CAN',
				'CV' => 'CPV',
				'CF' => 'CAF',
				'TD' => 'TCD',
				'CL' => 'CHL',
				'CN' => 'CHN',
				'CO' => 'COL',
				'KM' => 'COM',
				'CD' => 'COD',
				'CG' => 'COG',
				'CR' => 'CRI',
				'CI' => 'CIV',
				'HR' => 'HRV',
				'CU' => 'CUB',
				'CY' => 'CYP',
				'CZ' => 'CZE',
				'DK' => 'DNK',
				'DJ' => 'DJI',
				'DM' => 'DMA',
				'DO' => 'DOM',
				'EC' => 'ECU',
				'EG' => 'EGY',
				'SV' => 'SLV',
				'GQ' => 'GNQ',
				'ER' => 'ERI',
				'EE' => 'EST',
				'ET' => 'ETH',
				'FJ' => 'FJI',
				'FI' => 'FIN',
				'FR' => 'FRA',
				'GA' => 'GAB',
				'GM' => 'GMB',
				'GE' => 'GEO',
				'DE' => 'DEU',
				'GH' => 'GHA',
				'GR' => 'GRC',
				'GD' => 'GRD',
				'GT' => 'GTM',
				'GN' => 'GIN',
				'GW' => 'GNB',
				'GY' => 'GUY',
				'HT' => 'HTI',
				'HN' => 'HND',
				'HU' => 'HUN',
				'IS' => 'ISL',
				'IN' => 'IND',
				'ID' => 'IDN',
				'IR' => 'IRN',
				'IQ' => 'IRQ',
				'IE' => 'IRL',
				'IL' => 'ISR',
				'IT' => 'ITA',
				'JM' => 'JAM',
				'JP' => 'JPN',
				'JO' => 'JOR',
				'KZ' => 'KAZ',
				'KE' => 'KEN',
				'KI' => 'KIR',
				'KP' => 'PRK',
				'KR' => 'KOR',
				'KW' => 'KWT',
				'KG' => 'KGZ',
				'LA' => 'LAO',
				'LV' => 'LVA',
				'LB' => 'LBN',
				'LS' => 'LSO',
				'LR' => 'LBR',
				'LY' => 'LBY',
				'LI' => 'LIE',
				'LT' => 'LTU',
				'LU' => 'LUX',
				'MK' => 'MKD',
				'MG' => 'MDG',
				'MW' => 'MWI',
				'MY' => 'MYS',
				'MV' => 'MDV',
				'ML' => 'MLI',
				'MT' => 'MLT',
				'MH' => 'MHL',
				'MR' => 'MRT',
				'MU' => 'MUS',
				'MX' => 'MEX',
				'FM' => 'FSM',
				'MD' => 'MDA',
				'MC' => 'MCO',
				'MN' => 'MNG',
				'ME' => 'MNE',
				'MA' => 'MAR',
				'MZ' => 'MOZ',
				'MM' => 'MMR',
				'NA' => 'NAM',
				'NR' => 'NRU',
				'NP' => 'NPL',
				'NL' => 'NLD',
				'NZ' => 'NZL',
				'NI' => 'NIC',
				'NE' => 'NER',
				'NG' => 'NGA',
				'NO' => 'NOR',
				'OM' => 'OMN',
				'PK' => 'PAK',
				'PW' => 'PLW',
				'PA' => 'PAN',
				'PG' => 'PNG',
				'PY' => 'PRY',
				'PE' => 'PER',
				'PH' => 'PHL',
				'PL' => 'POL',
				'PT' => 'PRT',
				'QA' => 'QAT',
				'RO' => 'ROU',
				'RU' => 'RUS',
				'RW' => 'RWA',
				'KN' => 'KNA',
				'LC' => 'LCA',
				'VC' => 'VCT',
				'WS' => 'WSM',
				'SM' => 'SMR',
				'ST' => 'STP',
				'SA' => 'SAU',
				'SN' => 'SEN',
				'RS' => 'SRB',
				'SC' => 'SYC',
				'SL' => 'SLE',
				'SG' => 'SGP',
				'SK' => 'SVK',
				'SI' => 'SVN',
				'SB' => 'SLB',
				'SO' => 'SOM',
				'ZA' => 'ZAF',
				'ES' => 'ESP',
				'LK' => 'LKA',
				'SD' => 'SDN',
				'SR' => 'SUR',
				'SZ' => 'SWZ',
				'SE' => 'SWE',
				'CH' => 'CHE',
				'SY' => 'SYR',
				'TJ' => 'TJK',
				'TZ' => 'TZA',
				'TH' => 'THA',
				'TL' => 'TLS',
				'TG' => 'TGO',
				'TO' => 'TON',
				'TT' => 'TTO',
				'TN' => 'TUN',
				'TR' => 'TUR',
				'TM' => 'TKM',
				'TV' => 'TUV',
				'UG' => 'UGA',
				'UA' => 'UKR',
				'AE' => 'ARE',
				'GB' => 'GBR',
				'US' => 'USA',
				'UY' => 'URY',
				'UZ' => 'UZB',
				'VU' => 'VUT',
				'VA' => 'VAT',
				'VE' => 'VEN',
				'VN' => 'VNM',
				'YE' => 'YEM',
				'ZM' => 'ZMB',
				'ZW' => 'ZWE',
				'TW' => 'TWN',
				'CX' => 'CXR',
				'CC' => 'CCK',
				'HM' => 'HMD',
				'NF' => 'NFK',
				'NC' => 'NCL',
				'PF' => 'PYF',
				'YT' => 'MYT',
				'GP' => 'GLP',
				'PM' => 'SPM',
				'WF' => 'WLF',
				'TF' => 'ATF',
				'BV' => 'BVT',
				'CK' => 'COK',
				'NU' => 'NIU',
				'TK' => 'TKL',
				'GG' => 'GGY',
				'IM' => 'IMN',
				'JE' => 'JEY',
				'AI' => 'AIA',
				'BM' => 'BMU',
				'IO' => 'IOT',
				'VG' => 'VGB',
				'KY' => 'CYM',
				'FK' => 'FLK',
				'GI' => 'GIB',
				'MS' => 'MSR',
				'PN' => 'PCN',
				'SH' => 'SHN',
				'GS' => 'SGS',
				'TC' => 'TCA',
				'MP' => 'MNP',
				'PR' => 'PRI',
				'AS' => 'ASM',
				'UM' => 'UMI',
				'GU' => 'GUM',
				'VI' => 'VIR',
				'HK' => 'HKG',
				'MO' => 'MAC',
				'FO' => 'FRO',
				'GL' => 'GRL',
				'GF' => 'GUF',
				'MQ' => 'MTQ',
				'RE' => 'REU',
				'AX' => 'ALA',
				'AW' => 'ABW',
				'AN' => 'ANT',
				'SJ' => 'SJM',
				'AC' => 'ASC',
				'TA' => 'TAA',
				'AQ' => 'ATA',
			);

			if ( 3 === strlen( $code ) ) {
				$countries = array_flip( $countries );
			}

			return $countries[ $code ] ?? $code;
		}
	}

endif;
?>