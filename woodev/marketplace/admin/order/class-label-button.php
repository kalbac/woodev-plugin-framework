<?php
/**
 * The «Этикетка» button of a marketplace order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Ids;

defined( 'ABSPATH' ) || exit;

/**
 * `echo $button->button( $order )` prints the button that makes the label of one posting and downloads it. It asks for nothing the «Заказы
 * маркетплейсов» table does not: the script (`assets/js/admin/order-label.js`) calls the table's own records routes — the `label` row
 * action, and the activity read that says whether the label is being made — with the REST nonce, so the capability, the object check,
 * the licence gate and the one-task-per-posting rule are the table's ({@see \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Provider}).
 *
 * The button is empty for an order without a posting. A user who may not edit the order sees it off, with the reason the table gives.
 * While the marketplace is making the label the script turns it off with the table's own sentence, and downloads the PDF once it is ready.
 *
 * @since 2.1.13
 */
final class Label_Button {
	public const BUTTON_CLASS = 'woodev-mp-label';

	private const ASSET_HANDLE = 'woodev-marketplace-order-label';

	/**
	 * URL of the «Заказы» records table: `…/woodev/v1/admin/records/{plugin id}/orders`, without a trailing slash.
	 *
	 * @var string
	 */
	private $table_url;

	/**
	 * Returns the account of an order's posting: `function ( int $order_id ): int`, 0 for none.
	 *
	 * @var callable
	 */
	private $account_of_order;

	/**
	 * Folder URL of the module's assets, with a trailing slash.
	 *
	 * @var string
	 */
	private $assets_url;

	/**
	 * Plugin version, for the cache of the script.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Creates the button.
	 *
	 * @since 2.1.13
	 *
	 * @param string   $table_url        URL of the «Заказы» records table, without a trailing slash.
	 * @param callable $account_of_order `function ( int $order_id ): int`, the account of the order's posting; 0 for none.
	 * @param string   $assets_url       Folder URL of the module's assets, with a trailing slash.
	 * @param string   $version          Plugin version.
	 */
	public function __construct( string $table_url, callable $account_of_order, string $assets_url, string $version ) {
		$this->table_url        = $table_url;
		$this->account_of_order = $account_of_order;
		$this->assets_url       = $assets_url;
		$this->version          = $version;
	}

	/**
	 * Returns the button for an order, or '' when the order has no posting.
	 *
	 * @since 2.1.13
	 *
	 * @param \WC_Order $order Order.
	 * @return string HTML, escaped.
	 */
	public function button( \WC_Order $order ): string {
		$account_id = (int) call_user_func( $this->account_of_order, $order->get_id() );

		if ( $account_id < 1 ) {
			return '';
		}

		$can_edit = current_user_can( 'edit_shop_order', $order->get_id() );

		wp_enqueue_script( self::ASSET_HANDLE, $this->assets_url . 'js/admin/order-label' . \Woodev_Plugin::get_assets_suffix() . '.js', [], $this->version, true );

		return sprintf(
			'<span class="woodev-mp-label-wrap"><button type="button" class="button %1$s%2$s" aria-disabled="%3$s"%4$s data-url="%5$s" data-account="%6$d" data-row="%7$s" data-nonce="%8$s" data-preparing="%9$s" data-waiting="%10$s" data-failed="%11$s">%12$s</button><span class="description woodev-mp-label__status" role="status"></span></span>',
			esc_attr( self::BUTTON_CLASS ),
			$can_edit ? '' : ' disabled',
			$can_edit ? 'false' : 'true',
			$can_edit ? '' : ' title="' . esc_attr__( 'Нет права изменять этот заказ.', 'woodev-plugin-framework' ) . '"',
			esc_url( $this->table_url ),
			$account_id,
			esc_attr( Site_Orders_Ids::row_id( $account_id, $order->get_id() ) ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
			esc_attr__( 'Маркетплейс готовит этикетку…', 'woodev-plugin-framework' ),
			esc_attr__( 'Этикетка ещё готовится. Обновите страницу чуть позже и нажмите кнопку снова.', 'woodev-plugin-framework' ),
			esc_attr__( 'Не удалось связаться с сайтом. Проверьте интернет и повторите.', 'woodev-plugin-framework' ),
			esc_html__( 'Этикетка', 'woodev-plugin-framework' )
		);
	}
}
