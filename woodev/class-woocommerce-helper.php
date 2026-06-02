<?php
/**
 * WooCommerce platform helper.
 *
 * Holds the static helper surface that operates on WooCommerce domain objects
 * (WC_Order, WC_Product, WC_Order_Item_Product, etc.) and was previously part
 * of the platform-neutral {@see \Woodev_Helper} base.
 *
 * The base class keeps deprecated shims that delegate here. New code should
 * call this class directly.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Woocommerce_Helper::class, false ) ) :

	/**
	 * Woodev WooCommerce Helper.
	 *
	 * Centralizes the helper surface that depends on WooCommerce runtime
	 * classes. The class is intentionally non-final so that plugin-specific
	 * WC helpers can extend it; all members are static.
	 *
	 * Loaded conditionally by the framework resolver alongside
	 * {@see Woocommerce_Plugin} (only when a plugin's loader definition
	 * requires the WooCommerce base).
	 *
	 * @since 2.0.0
	 */
	class Woocommerce_Helper {

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
		 * @param \WC_Order $order
		 *
		 * @return \stdClass[] array of line item objects
		 */
		public static function get_order_line_items( \WC_Order $order ): array {

			$line_items = [];

			/** @var \WC_Order_Item_Product $item */
			foreach ( $order->get_items() as $id => $item ) {

				$line_item = new \stdClass();
				$product   = $item->get_product();
				$name      = $item->get_name();
				$quantity  = $item->get_quantity();
				$sku       = $product instanceof \WC_Product ? $product->get_sku() : '';
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
		 * @param \WC_Order $order the order object
		 *
		 * @return bool
		 */
		public static function is_order_virtual( \WC_Order $order ): bool {

			$is_virtual = true;

			/** @var \WC_Order_Item_Product $item */
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

			if ( ! function_exists( 'wc_get_products' ) ) {
				return false;
			}

			$virtual_products = wc_get_products(
				array(
					'virtual' => true,
					'status'  => 'publish',
					'limit'   => 1,
				)
			);

			return count( $virtual_products ) > 0;
		}

		/**
		 * Enhanced search JavaScript (Select2).
		 *
		 * Enqueues JavaScript required for AJAX search with Select2.
		 *
		 * Example usage:
		 *    <input type="hidden" class="woodev-wc-enhanced-search" name="category_ids" data-multiple="true" style="min-width: 300px;"
		 *       data-action="wc_cart_notices_json_search_product_categories"
		 *       data-nonce="<?php echo wp_create_nonce( 'search-categories' ); ?>"
		 *       data-request_data = "<?php echo esc_attr( wp_json_encode( array( 'field_name' => 'something_exciting', 'default' => 'default_label' ) ) ) ?>"
		 *       data-placeholder="<?php esc_attr_e( 'Search for a category&hellip;', 'wc-cart-notices' ) ?>"
		 *       data-allow_clear="true"
		 *       data-selected="<?php
		 *          $json_ids    = array();
		 *          if ( isset( $notice->data['categories'] ) ) {
		 *             foreach ( $notice->data['categories'] as $value => $title ) {
		 *                $json_ids[ esc_attr( $value ) ] = esc_html( $title );
		 *             }
		 *          }
		 *          echo esc_attr( wp_json_encode( $json_ids ) ) );
		 *       ?>"
		 *       value="<?php echo implode( ',', array_keys( $json_ids ) ); ?>" />
		 *
		 * - `data-selected` can be a json encoded associative array like Array( 'key' => 'value' )
		 * - `value` should be a comma-separated list of selected keys
		 * - `data-request_data` can be used to pass any additional data to the AJAX request
		 *
		 * @return void
		 */
		public static function render_select2_ajax() {

			if ( ! did_action( 'woodev_wc_select2_ajax_rendered' ) ) {

				$javascript = '( function(){
			if ( ! $().select2 ) return;
		';

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

				$javascript .= '} )();';

				\Woodev_Helper::enqueue_js( $javascript );

				do_action( 'woodev_wc_select2_ajax_rendered' );
			}
		}
	}

endif;
