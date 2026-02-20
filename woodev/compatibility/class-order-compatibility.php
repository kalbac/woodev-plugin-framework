<?php

use \Automattic\WooCommerce\Admin\Overrides\Order;
use \Automattic\WooCommerce\Internal\Admin\Orders\PageController;
use \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use \Automattic\WooCommerce\Internal\Utilities\COTMigrationUtil;
use \Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Order_Compatibility' ) ) :

	/**
	 * WooCommerce order compatibility class.
	 */

	class Woodev_Order_Compatibility extends Woodev_Data_Compatibility {

		/**
		 * Gets the formatted metadata for an order item.
		 *
		 * @param WC_Order_Item $item        order item object
		 * @param string        $hide_prefix prefix for meta that is considered hidden
		 * @param bool          $include_all whether to include all meta (attributes, etc...), or just custom fields
		 *
		 * @return array $item_meta {
		 *     @type string     $label       meta field label
		 *     @type mixed      $value       meta value
		 * }
		 *
		 * @deprecated 1.3.0 prefer using {@see WC_Order_Item::get_formatted_meta_data()}
		 *
		 */
		public static function get_item_formatted_meta_data( WC_Order_Item $item, string $hide_prefix = '_', bool $include_all = false ): array {

			if ( $item instanceof WC_Order_Item && Woodev_Plugin_Compatibility::is_wc_version_gte( '3.1' ) ) {

				$meta_data = $item->get_formatted_meta_data( $hide_prefix, $include_all );
				$item_meta = [];

				foreach ( $meta_data as $meta ) {

					$item_meta[] = array(
						'label' => $meta->display_key,
						'value' => $meta->value,
					);
				}

			} else {

				$item_meta = new WC_Order_Item_Meta( $item );
				$item_meta = $item_meta->get_formatted( $hide_prefix );
			}

			return $item_meta;
		}


		/**
		 * Gets the orders screen admin URL according to HPOS availability.
		 *
		 * @return string
		 */
		public static function get_orders_screen_url() : string {

			if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
				return add_query_arg( array( 'page' => 'wc-orders' ), admin_url( 'admin.php' ) );
			}

			return add_query_arg( array( 'post_type' => 'shop_order' ), admin_url( 'edit.php' ) );
		}


		/**
		 * Gets the admin Edit screen URL for an order according to HPOS compatibility.
		 *
		 * @NOTE consider using {@see WC_Order::get_edit_order_url()} whenever possible
		 *
		 * @see OrderUtil::get_order_admin_edit_url()
		 * @see PageController::get_edit_url()
		 *
		 * @param WC_Order|int $order order object or ID
		 * @return string
		 */
		public static function get_edit_order_url( $order ) : string {

			$order_id = $order instanceof WC_Order ? $order->get_id() : $order;
			$order_id = max( ( int ) $order_id, 0);

			if ( Woodev_Plugin_Compatibility::is_wc_version_gte( '3.3' ) ) {
				$order_url = OrderUtil::get_order_admin_edit_url( $order_id );
			} else {
				$order_url = apply_filters( 'woocommerce_get_edit_order_url', add_query_arg( array( 'post' => absint( $order_id ), 'action' => 'edit' ), admin_url( 'post.php' ) ), $order );
			}

			return $order_url;
		}


		/**
		 * Determines if the current admin screen is for the orders.
		 *
		 * @return bool
		 */
		public static function is_orders_screen() : bool {

			$current_screen = Woodev_Helper::get_current_screen();

			if ( ! $current_screen ) {
				return false;
			}

			if ( ! Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
				return 'edit-shop_order' === $current_screen->id;
			}

			return static::get_order_screen_id() === $current_screen->id
			       && isset( $_GET['page'] )
			       && $_GET['page'] === 'wc-orders'
			       && ! static::is_order_edit_screen();
		}


		/**
		 * Determines if the current orders screen is for orders of a specific status.
		 *
		 * @param string|string[] $status one or more statuses to compare
		 * @return bool
		 */
		public static function is_orders_screen_for_status( $status ) : bool {
			global $post_type, $post_status;

			if ( ! Woodev_Plugin_Compatibility::is_hpos_enabled() ) {

				if ( 'shop_order' !== $post_type ) {
					return false;
				}

				return empty( $status ) || in_array( $post_status, (array) $status, true );
			}

			if ( ! static::is_orders_screen() ) {
				return false;
			}

			return empty( $status ) || ( isset( $_GET['status'] ) && in_array( $_GET['status'], (array) $status, true ) );
		}


		/**
		 * Determines if the current admin screen is for adding or editing an order.
		 *
		 * @return bool
		 */
		public static function is_order_edit_screen() : bool {

			$current_screen = Woodev_Helper::get_current_screen();

			if ( ! $current_screen ) {
				return false;
			}

			if ( ! Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
				return 'shop_order' === $current_screen->id;
			}

			return static::get_order_screen_id() === $current_screen->id
			       && isset( $_GET['page'], $_GET['action'] )
			       && $_GET['page'] === 'wc-orders'
			       && in_array( $_GET['action'], [ 'new', 'edit' ], true );
		}


		/**
		 * Determines if the current admin page is for any kind of order screen.
		 *
		 * @return bool
		 */
		public static function is_order_screen() : bool {
			return static::is_orders_screen() || static::is_order_edit_screen();
		}


		/**
		 * Gets the ID of the order for the current edit screen.
		 *
		 * @return int|null
		 */
		public static function get_order_id_for_order_edit_screen() : ?int {
			global $post, $theorder;

			if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
				return $theorder instanceof WC_Abstract_Order && static::is_order_edit_screen() ? $theorder->get_id() : null;
			}

			return $post->ID ?? null;
		}


		/**
		 * Gets the admin screen ID for orders.
		 *
		 * This method detects the expected orders screen ID according to HPOS availability.
		 * `shop_order` as a registered post type as the screen ID is no longer used when HPOS is active.
		 *
		 * @see OrderUtil::get_order_admin_screen()
		 * @see COTMigrationUtil::get_order_admin_screen()
		 *
		 * @return string
		 */
		public static function get_order_screen_id() : string {

			if ( is_callable( OrderUtil::class . '::get_order_admin_screen' ) ) {
				return OrderUtil::get_order_admin_screen();
			} elseif ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
				return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
			}

			return 'shop_order';
		}


		/**
		 * Gets the orders table.
		 *
		 * @return string
		 */
		public static function get_orders_table() : string {
			global $wpdb;
			return Woodev_Plugin_Compatibility::is_hpos_enabled() ? OrdersTableDataStore::get_orders_table_name() : $wpdb->posts;
		}


		/**
		 * Gets the orders meta table.
		 *
		 * @return string
		 */
		public static function get_orders_meta_table() : string {
			global $wpdb;

			return Woodev_Plugin_Compatibility::is_hpos_enabled() ? OrdersTableDataStore::get_meta_table_name() : $wpdb->postmeta;
		}


		/**
		 * Determines whether a given identifier is a WooCommerce order or not, according to HPOS availability.
		 *
		 * @see OrderUtil::get_order_type()
		 *
		 * @param int|WP_Post|WC_Order|null $post_order_or_id identifier of a possible order
		 * @param string|string[] $order_type the order type, defaults to shop_order, can specify multiple types
		 * @return bool
		 */
		public static function is_order( $post_order_or_id, $order_type = 'shop_order' ) : bool {

			if ( ! $post_order_or_id ) {
				return false;
			}

			if ( $post_order_or_id instanceof WC_Abstract_Order ) {

				$found_type = $post_order_or_id->get_type();

			} elseif ( ! Woodev_Plugin_Compatibility::is_hpos_enabled() ) {

				$found_type = is_numeric( $post_order_or_id ) || $post_order_or_id instanceof WP_Post ? get_post_type( $post_order_or_id ) : null;

			} else {

				$found_type = OrderUtil::get_order_type( $post_order_or_id );
			}

			return $found_type && in_array( $found_type, (array) $order_type, true );
		}


		/**
		 * Determines whether a given identifier is a WooCommerce refund or not, according to HPOS availability.
		 *
		 * @param int|WP_Post|WC_Order|null $order_post_or_id identifier of a possible order
		 * @return bool
		 */
		public static function is_refund( $order_post_or_id ) : bool {
			return static::is_order( $order_post_or_id, 'shop_order_refund' );
		}


		/**
		 * Gets the order meta according to HPOS availability.
		 *
		 * Uses {@see WC_Order::get_meta()} if HPOS is enabled, otherwise it uses the WordPress {@see get_post_meta()} function.
		 *
		 * @param int|WC_Order $order order ID or object
		 * @param string $meta_key meta key
		 * @param bool $single return the first found meta with key (true), or all meta sharing the same key (default true)
		 * @return mixed
		 */
		public static function get_order_meta( $order, string $meta_key, bool $single = true ) {

			if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {

				$value = $single ? '' : [];
				$order = is_numeric( $order ) && $order > 0 ? wc_get_order( (int) $order ) : $order;

				if ( $order instanceof WC_Order ) {
					$value = $order->get_meta( $meta_key, $single );
				}

			} else {

				$order_id = $order instanceof WC_Order ? $order->get_id() : $order;

				$value = is_numeric( $order_id ) && $order_id > 0 ? get_post_meta( (int) $order_id, $meta_key, $single ) : false;
			}

			return $value;
		}


		/**
		 * Updates the order meta according to HPOS availability.
		 *
		 * Uses {@see WC_Order::update_meta_data()} if HPOS is enabled, otherwise it uses the WordPress {@see update_meta_data()} function.
		 *
		 * @param int|WC_Order $order order ID or object
		 * @param string $meta_key meta key
		 * @param mixed $meta_value meta value
		 */
		public static function update_order_meta( $order, string $meta_key, $meta_value ) {

			if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {

				$order = is_numeric( $order ) && $order > 0 ? wc_get_order( (int) $order ) : $order;

				if ( $order instanceof WC_Order ) {
					$order->update_meta_data( $meta_key, $meta_value );
					$order->save_meta_data();
				}

			} else {

				$order_id = $order instanceof WC_Order ? $order->get_id() : $order;

				if ( is_numeric( $order_id ) && $order_id > 0 ) {
					update_post_meta( (int) $order_id, $meta_key, $meta_value );
				}
			}
		}


		/**
		 * Adds the order meta according to HPOS availability.
		 *
		 * Uses {@see WC_Order::add_meta_data()} if HPOS is enabled, otherwise it uses the WordPress {@see add_meta_data()} function.
		 *
		 * @param int|WC_Order $order order ID or object
		 * @param string $meta_key meta key
		 * @param mixed $meta_value meta value
		 * @param bool $unique optional - whether the same key should not be added (default false)
		 */
		public static function add_order_meta( $order, string $meta_key, $meta_value, bool $unique = false ) {

			if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {

				$order = is_numeric( $order ) && $order > 0 ? wc_get_order( (int) $order ) : $order;

				if ( $order instanceof WC_Order ) {
					$order->add_meta_data( $meta_key, $meta_value, $unique );
					$order->save_meta_data();
				}

			} else {

				$order_id = $order instanceof WC_Order ? $order->get_id() : $order;

				if ( is_numeric( $order_id ) && $order_id > 0 ) {
					add_post_meta( (int) $order_id, $meta_key, $meta_value, $unique );
				}
			}
		}


		/**
		 * Deletes the order meta according to HPOS availability.
		 *
		 * Uses {@see WC_Order::delete_meta_data()} if HPOS is enabled, otherwise it uses the WordPress {@see delete_meta_data()} function.
		 *
		 * @param int|WC_Order $order order ID or object
		 * @param string $meta_key meta key
		 * @param mixed $meta_value optional (applicable if HPOS is inactive)
		 */
		public static function delete_order_meta( $order, string $meta_key, $meta_value = '' ) {

			if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {

				$order = is_numeric( $order ) && $order > 0 ? wc_get_order( (int) $order ) : $order;

				if ( $order instanceof WC_Order ) {
					$order->delete_meta_data( $meta_key);
					$order->save_meta_data();
				}

			} else {

				$order_id = $order instanceof WC_Order ? $order->get_id() : $order;

				if ( is_numeric( $order_id ) && $order_id > 0 ) {
					delete_post_meta( (int) $order_id, $meta_key, $meta_value );
				}
			}
		}


		/**
		 * Determines if an order meta exists according to HPOS availability.
		 *
		 * Uses {@see WC_Order::meta_exists()} if HPOS is enabled, otherwise it uses the WordPress {@see metadata_exists()} function.
		 *
		 * @param int|WC_Order $order order ID or object
		 * @param string $meta_key meta key
		 * @return bool
		 */
		public static function order_meta_exists( $order, string $meta_key ) : bool {

			if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {

				$order = is_numeric( $order ) && $order > 0 ? wc_get_order( (int) $order ) : $order;

				if ( $order instanceof WC_Order ) {
					return $order->meta_exists( $meta_key );
				}

			} else {

				$order_id = $order instanceof WC_Order ? $order->get_id() : $order;

				if ( is_numeric( $order_id ) && $order_id > 0 ) {
					return metadata_exists( 'post', (int) $order_id, $meta_key );
				}
			}

			return false;
		}


		/**
		 * Gets the list of order post types.
		 *
		 * @return string[]
		 */
		public static function get_order_post_types(): array {

			$order_post_types = ['shop_order'];

			/** @see \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer */
			if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
				$order_post_types[] = 'shop_order_placehold';
			}

			return $order_post_types;
		}

	}

endif;
