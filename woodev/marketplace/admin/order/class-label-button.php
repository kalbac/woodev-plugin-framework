<?php
/**
 * The «Этикетка» button of a marketplace order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Ids;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Availability;

defined( 'ABSPATH' ) || exit;

/**
 * `echo $button->button( $order )` prints the button that makes the label of one posting and downloads it. It asks for nothing the «Заказы
 * маркетплейсов» table does not: the script (`assets/js/admin/order-label.js`) calls the table's own records routes — the `label` row
 * action, and the activity read that says whether the label is being made — with the REST nonce, so the capability, the object check,
 * the licence gate and the one-task-per-posting rule are the table's ({@see \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Provider}).
 *
 * The button is empty for an order without a posting. It is off, with the reason as its tooltip, for a user who may not edit the order
 * and for a posting that cannot have a label ({@see Label_Availability}: finished, or not ready by the adapter's own word), so no
 * request is possible. While the marketplace is making the label the script turns it off with the table's own sentence, and downloads
 * the PDF once it is ready.
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
	 * Returns the posting of an order: `function ( int $order_id ): ?array{account_id:int,status_raw:string,is_terminal:bool}`.
	 *
	 * @var callable
	 */
	private $posting_of_order;

	/**
	 * Whether a label can be asked for a posting.
	 *
	 * @var Label_Availability
	 */
	private $availability;

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
	 * @param string             $table_url        URL of the «Заказы» records table, without a trailing slash.
	 * @param callable           $posting_of_order `function ( int $order_id ): ?array{account_id:int,status_raw:string,is_terminal:bool}`; null for an order with no posting.
	 * @param Label_Availability $availability     Whether a label can be asked for a posting.
	 * @param string             $assets_url       Folder URL of the module's assets, with a trailing slash.
	 * @param string             $version          Plugin version.
	 */
	public function __construct( string $table_url, callable $posting_of_order, Label_Availability $availability, string $assets_url, string $version ) {
		$this->table_url        = $table_url;
		$this->posting_of_order = $posting_of_order;
		$this->availability     = $availability;
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
		$posting = call_user_func( $this->posting_of_order, $order->get_id() );

		if ( ! is_array( $posting ) ) {
			return '';
		}

		$off_reason = $this->off_reason( $order, $posting );

		wp_enqueue_script( self::ASSET_HANDLE, $this->assets_url . 'js/admin/order-label' . \Woodev_Plugin::get_assets_suffix() . '.js', [], $this->version, true );

		return sprintf(
			'<span class="woodev-mp-label-wrap"><button type="button" class="button %1$s%2$s" aria-disabled="%3$s"%4$s data-url="%5$s" data-account="%6$d" data-row="%7$s" data-nonce="%8$s" data-preparing="%9$s" data-waiting="%10$s" data-failed="%11$s">%12$s</button><span class="description woodev-mp-label__status" role="status"></span></span>',
			esc_attr( self::BUTTON_CLASS ),
			'' === $off_reason ? '' : ' disabled',
			'' === $off_reason ? 'false' : 'true',
			'' === $off_reason ? '' : ' title="' . esc_attr( $off_reason ) . '"',
			esc_url( $this->table_url ),
			$posting['account_id'],
			esc_attr( Site_Orders_Ids::row_id( $posting['account_id'], $order->get_id() ) ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
			esc_attr__( 'Маркетплейс готовит этикетку…', 'woodev-plugin-framework' ),
			esc_attr__( 'Этикетка ещё готовится. Обновите страницу чуть позже и нажмите кнопку снова.', 'woodev-plugin-framework' ),
			esc_attr__( 'Не удалось связаться с сайтом. Проверьте интернет и повторите.', 'woodev-plugin-framework' ),
			esc_html__( 'Этикетка', 'woodev-plugin-framework' )
		);
	}

	/**
	 * Tells why the button is off: the user may not edit the order, or the posting cannot have a label.
	 *
	 * @param \WC_Order                                                $order   Order.
	 * @param array{account_id:int,status_raw:string,is_terminal:bool} $posting Its posting.
	 * @return string The sentence for the merchant; empty when the button is on.
	 */
	private function off_reason( \WC_Order $order, array $posting ): string {
		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return __( 'Нет права изменять этот заказ.', 'woodev-plugin-framework' );
		}

		return $this->availability->reason( $posting['status_raw'], $posting['is_terminal'] );
	}
}
