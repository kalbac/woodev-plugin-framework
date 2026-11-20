<?php
/**
 * The order screen of a marketplace order: read-only.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Flows\Order_Meta_Contract;

defined( 'ABSPATH' ) || exit;

/**
 * An order that came from a marketplace is the marketplace's, so WooCommerce's order screen shows it as one that cannot be edited
 * (the same on both order storages): the boxes that edit or act on the order are removed, the marketplace's own details box
 * ({@see Order_Details_Box}) takes the top, the items stay visible but locked by WooCommerce's own «not editable» filter, and the
 * notes stay. A shop order is never touched.
 *
 * Which orders are the marketplace's is decided by `created_via`, the value its order writer stamps ({@see Order_Meta_Contract::created_via()}).
 *
 * WooCommerce saves the removed boxes without asking whether they are there (the order data box throws when its status field is
 * missing), so the savers are unhooked for such an order: pressing «Обновить» can neither fail nor change it.
 *
 * @since 2.1.13
 */
final class Order_Screen {
	/**
	 * Priority of the box arrangement: after WooCommerce has added its own order boxes (30).
	 */
	public const BOXES_PRIORITY = 50;

	/**
	 * Boxes that edit or act on the order, with the context each sits in: `id => context`.
	 */
	private const REMOVED_BOXES = [
		'woocommerce-order-data'      => 'normal',
		'woocommerce-order-downloads' => 'normal',
		'woocommerce-order-actions'   => 'side',
		'postcustom'                  => 'normal',
		'order_custom'                => 'normal',
	];

	/**
	 * Savers WooCommerce hooks for the order screen: `callable => priority`.
	 */
	private const SAVERS = [
		'WC_Meta_Box_Order_Items::save'     => 10,
		'WC_Meta_Box_Order_Downloads::save' => 30,
		'WC_Meta_Box_Order_Data::save'      => 40,
		'WC_Meta_Box_Order_Actions::save'   => 50,
	];

	/**
	 * The marketplace's order meta.
	 *
	 * @var Order_Meta_Contract
	 */
	private $meta;

	/**
	 * The marketplace's box.
	 *
	 * @var Order_Details_Box
	 */
	private $box;

	/**
	 * Creates the screen policy.
	 *
	 * @since 2.1.13
	 *
	 * @param Order_Meta_Contract $meta The marketplace's order meta.
	 * @param Order_Details_Box   $box  The marketplace's box.
	 */
	public function __construct( Order_Meta_Contract $meta, Order_Details_Box $box ) {
		$this->meta = $meta;
		$this->box  = $box;
	}

	/**
	 * Hooks the policy.
	 *
	 * @since 2.1.13
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'arrange_boxes' ], self::BOXES_PRIORITY, 2 );
		add_action( 'woocommerce_process_shop_order_meta', [ $this, 'unhook_savers' ], 1 );
		add_filter( 'wc_order_is_editable', [ $this, 'filter_editable' ], 10, 2 );
	}

	/**
	 * Removes the editing boxes and adds the marketplace's box when the order shown is a marketplace order.
	 *
	 * WooCommerce fires `add_meta_boxes` with a `WP_Post` on the posts table and with a `WC_Order` with HPOS, and the screen is
	 * `shop_order` or `woocommerce_page_wc-orders`, so the boxes are changed on exactly the screen being shown.
	 *
	 * @since 2.1.13
	 *
	 * @param mixed $screen_or_post_type Screen id or post type (unused: the order screen of the active storage is asked for).
	 * @param mixed $post_or_order       The order (HPOS) or its post (posts table).
	 * @return void
	 */
	public function arrange_boxes( $screen_or_post_type = null, $post_or_order = null ): void {
		$order = $this->marketplace_order( $post_or_order );

		if ( null === $order ) {
			return;
		}

		$screen = wc_get_page_screen_id( 'shop-order' );

		foreach ( self::REMOVED_BOXES as $id => $context ) {
			remove_meta_box( $id, $screen, $context );
		}

		$this->box->add( $order, $screen );
	}

	/**
	 * Unhooks WooCommerce's savers of the order screen for a marketplace order.
	 *
	 * @since 2.1.13
	 *
	 * @param mixed $order_id Order id.
	 * @return void
	 */
	public function unhook_savers( $order_id = 0 ): void {
		if ( null === $this->marketplace_order( $order_id ) ) {
			return;
		}

		foreach ( self::SAVERS as $saver => $priority ) {
			remove_action( 'woocommerce_process_shop_order_meta', $saver, $priority );
		}
	}

	/**
	 * Tells WooCommerce that a marketplace order is not editable: the screen then shows its items without the edit controls.
	 *
	 * @since 2.1.13
	 *
	 * @param mixed $editable Whether WooCommerce thinks the order is editable.
	 * @param mixed $order    The order.
	 * @return mixed
	 */
	public function filter_editable( $editable, $order = null ) {
		return $this->is_marketplace_order( $order ) ? false : $editable;
	}

	/**
	 * Tells whether an order is the marketplace's.
	 *
	 * @param mixed $order The order.
	 * @return bool
	 */
	private function is_marketplace_order( $order ): bool {
		$created_via = $this->meta->created_via();

		return '' !== $created_via && $order instanceof \WC_Order && $created_via === $order->get_created_via();
	}

	/**
	 * Resolves the order of whatever WooCommerce hands a hook (an order, a post, an id) and keeps it only when it is the marketplace's.
	 *
	 * @param mixed $source A `WC_Order`, a `WP_Post`, an order id or anything else.
	 * @return \WC_Order|null
	 */
	private function marketplace_order( $source ): ?\WC_Order {
		if ( $source instanceof \WP_Post ) {
			$source = $source->ID;
		}

		$order = is_int( $source ) || ( is_string( $source ) && ctype_digit( $source ) ) ? wc_get_order( (int) $source ) : $source;

		return $this->is_marketplace_order( $order ) ? $order : null;
	}
}
