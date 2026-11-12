<?php
/**
 * The marketplace metabox of the order edit screen.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a side metabox with the posting number and the cancel button to the order edit screen of **both** order storages.
 *
 * `wc_get_page_screen_id( 'shop-order' )` answers `shop_order` on the posts table and `woocommerce_page_wc-orders` with HPOS, and
 * WooCommerce fires `add_meta_boxes` with a `WP_Post` in the first case and a `WC_Order` in the second, so the box is added for
 * exactly the screen being shown, and only for an order that carries a posting of this marketplace.
 *
 * @since 2.1.2
 */
final class Cancel_Metabox {
	/**
	 * Names.
	 *
	 * @var Screen_Names
	 */
	private $names;

	/**
	 * The marketplace's cancellation.
	 *
	 * @var Order_Canceller
	 */
	private $canceller;

	/**
	 * The button.
	 *
	 * @var Cancel_Modal
	 */
	private $modal;

	/**
	 * Creates the metabox.
	 *
	 * @since 2.1.2
	 *
	 * @param Screen_Names    $names     Names.
	 * @param Order_Canceller $canceller The marketplace's cancellation.
	 * @param Cancel_Modal    $modal     The button.
	 */
	public function __construct( Screen_Names $names, Order_Canceller $canceller, Cancel_Modal $modal ) {
		$this->names     = $names;
		$this->canceller = $canceller;
		$this->modal     = $modal;
	}

	/**
	 * Hooks the metabox.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ], 10, 2 );
	}

	/**
	 * Adds the metabox when the order shown is a marketplace order.
	 *
	 * @since 2.1.2
	 *
	 * @param mixed $screen_or_post_type Screen id or post type (unused: the box goes to the order screen of the active storage).
	 * @param mixed $post_or_order       The order (HPOS) or its post (posts table).
	 * @return void
	 */
	public function add( $screen_or_post_type = null, $post_or_order = null ): void {
		$order = $this->order_of( $post_or_order );

		if ( null === $order || '' === $this->canceller->posting_number( $order ) || ! current_user_can( Cancel_Ajax::CAPABILITY ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown,WordPress.WP.Capabilities.Undetermined -- WooCommerce's own capability.
			return;
		}

		add_meta_box(
			'woodev-mp-' . $this->names->id() . '-order',
			$this->names->name(),
			[ $this, 'render' ],
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	/**
	 * Prints the box.
	 *
	 * @since 2.1.2
	 *
	 * @param mixed $post_or_order The order (HPOS) or its post (posts table).
	 * @return void
	 */
	public function render( $post_or_order = null ): void {
		$order = $this->order_of( $post_or_order );

		if ( null === $order ) {
			return;
		}

		$posting = $this->canceller->posting_number( $order );

		echo '<p>' . esc_html( sprintf( /* translators: %s: posting number. */ __( 'Отправление: %s', 'woodev-plugin-framework' ), $posting ) ) . '</p>';

		$button = $this->modal->button( $order );

		if ( '' === $button ) {
			echo '<p class="description">' . esc_html__( 'Отправление завершено или отменено — отменить его нельзя.', 'woodev-plugin-framework' ) . '</p>';
			return;
		}

		echo $button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Cancel_Modal::button().
	}

	/**
	 * Resolves the order of whatever WooCommerce hands a metabox.
	 *
	 * @param mixed $post_or_order A `WC_Order`, a `WP_Post` or anything else.
	 * @return \WC_Order|null
	 */
	private function order_of( $post_or_order ): ?\WC_Order {
		if ( $post_or_order instanceof \WC_Order ) {
			return $post_or_order;
		}

		$order = $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : false;

		return $order instanceof \WC_Order ? $order : null;
	}
}
