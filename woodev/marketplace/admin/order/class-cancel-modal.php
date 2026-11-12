<?php
/**
 * The cancel button and its reasons dialog.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;

defined( 'ABSPATH' ) || exit;

/**
 * `echo $modal->button( $order )` prints the button for one order, wherever it appears (the order metabox, a row action). The first
 * call queues the dialog for `admin_footer`, where it is printed once however many buttons the page has, and enqueues the script,
 * which reacts to a click on any `.woodev-mp-cancel` (so rows added to the page later work too).
 *
 * The button is empty when the order is not a marketplace order, its posting is already finished, or the user may not edit orders —
 * the server checks all of it again on every request in {@see Cancel_Ajax}, so hiding the button is a courtesy.
 *
 * The dialog is the native `<dialog>` element; the script (`assets/js/admin/order-cancel.js`) uses plain DOM calls (`textContent`,
 * never `innerHTML`). A successful cancellation reloads the page, which then shows the order as the marketplace reports it.
 *
 * @since 2.1.2
 */
final class Cancel_Modal {
	public const DIALOG_ID    = 'woodev-mp-cancel-dialog';
	public const BUTTON_CLASS = 'woodev-mp-cancel';

	private const ASSET_HANDLE = 'woodev-marketplace-order-cancel';

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
	 * Folder URL of the module's assets, with a trailing slash.
	 *
	 * @var string
	 */
	private $assets_url;

	/**
	 * Plugin version, for the cache of the assets.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Creates the modal.
	 *
	 * @since 2.1.2
	 *
	 * @param Screen_Names    $names      Names.
	 * @param Order_Canceller $canceller  The marketplace's cancellation.
	 * @param string          $assets_url Folder URL of the module's assets, with a trailing slash.
	 * @param string          $version    Plugin version.
	 */
	public function __construct( Screen_Names $names, Order_Canceller $canceller, string $assets_url, string $version ) {
		$this->names      = $names;
		$this->canceller  = $canceller;
		$this->assets_url = $assets_url;
		$this->version    = $version;
	}

	/**
	 * Returns the button for an order, or '' when there is nothing to cancel or the user may not.
	 *
	 * @since 2.1.2
	 *
	 * @param \WC_Order $order Order.
	 * @return string HTML, escaped.
	 */
	public function button( \WC_Order $order ): string {
		if ( ! current_user_can( Cancel_Ajax::CAPABILITY ) // phpcs:ignore WordPress.WP.Capabilities.Unknown,WordPress.WP.Capabilities.Undetermined -- WooCommerce's own capability.
			|| '' === $this->canceller->posting_number( $order )
			|| $this->canceller->is_finished( $order ) ) {
			return '';
		}

		$this->enqueue_assets();
		add_action( 'admin_footer', [ $this, 'print_dialog' ] );

		return sprintf(
			'<button type="button" class="button %1$s" data-order-id="%2$d" data-nonce="%3$s" data-reasons-action="%4$s" data-cancel-action="%5$s">%6$s</button>',
			esc_attr( self::BUTTON_CLASS ),
			$order->get_id(),
			esc_attr( wp_create_nonce( $this->names->cancel_nonce( $order->get_id() ) ) ),
			esc_attr( $this->names->cancel_reasons_action() ),
			esc_attr( $this->names->cancel_action() ),
			esc_html( sprintf( /* translators: %s: marketplace name. */ __( 'Отменить: %s', 'woodev-plugin-framework' ), $this->names->name() ) )
		);
	}

	/**
	 * Prints the dialog (hooked on `admin_footer` by {@see self::button()}).
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function print_dialog(): void {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$printed = true;
		?>
		<dialog id="<?php echo esc_attr( self::DIALOG_ID ); ?>" class="woodev-mp-cancel-dialog" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-button="<?php echo esc_attr( '.' . self::BUTTON_CLASS ); ?>" data-loading="<?php echo esc_attr__( 'Загружаем причины…', 'woodev-plugin-framework' ); ?>" data-sending="<?php echo esc_attr__( 'Отменяем…', 'woodev-plugin-framework' ); ?>" data-failed="<?php echo esc_attr__( 'Не удалось связаться с сайтом. Проверьте интернет и повторите.', 'woodev-plugin-framework' ); ?>">
			<h2><?php esc_html_e( 'Отменить отправление', 'woodev-plugin-framework' ); ?></h2>
			<p><?php esc_html_e( 'Отправление будет отменено на маркетплейсе. Это нельзя отменить: покупатель получит уведомление, а товар вернётся на склад сайта.', 'woodev-plugin-framework' ); ?></p>
			<p>
				<label for="woodev-mp-cancel-reason"><?php esc_html_e( 'Причина отмены', 'woodev-plugin-framework' ); ?></label><br>
				<select id="woodev-mp-cancel-reason" disabled></select>
			</p>
			<p id="woodev-mp-cancel-message-row" hidden>
				<label for="woodev-mp-cancel-message"><?php esc_html_e( 'Напишите, что случилось', 'woodev-plugin-framework' ); ?></label><br>
				<textarea id="woodev-mp-cancel-message" class="large-text" rows="3"></textarea>
			</p>
			<p id="woodev-mp-cancel-status" role="status"></p>
			<p>
				<button type="button" class="button button-primary" id="woodev-mp-cancel-confirm" disabled><?php esc_html_e( 'Отменить отправление', 'woodev-plugin-framework' ); ?></button>
				<button type="button" class="button" id="woodev-mp-cancel-close"><?php esc_html_e( 'Не отменять', 'woodev-plugin-framework' ); ?></button>
			</p>
		</dialog>
		<?php
	}

	/**
	 * Enqueues the stylesheet and the script (in the footer: the buttons are printed in the page body).
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		$suffix = \Woodev_Plugin::get_assets_suffix();

		wp_enqueue_style( self::ASSET_HANDLE, $this->assets_url . 'css/admin/order-cancel' . $suffix . '.css', [], $this->version );
		wp_enqueue_script( self::ASSET_HANDLE, $this->assets_url . 'js/admin/order-cancel' . $suffix . '.js', [], $this->version, true );
	}
}
