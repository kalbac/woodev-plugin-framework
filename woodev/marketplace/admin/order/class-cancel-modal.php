<?php
/**
 * The cancel button and its reasons dialog.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Dialog_Assets;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;

defined( 'ABSPATH' ) || exit;

/**
 * `echo $modal->button( $order )` prints the button for one order, wherever it appears (the order metabox, a row action). The first
 * call queues the dialog for `admin_footer`, where it is printed once however many buttons the page has, and enqueues the script,
 * which reacts to a click on any `.woodev-mp-cancel` (so rows added to the page later work too).
 *
 * The button is empty when the order is not a marketplace order, its posting is already finished, or the user may not edit orders —
 * the server checks all of it again on every request in {@see Cancel_Ajax}, so hiding the button is a courtesy. It is off, with the
 * reason as its tooltip, when the account's key cannot write or the marketplace does not allow cancelling this posting now.
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
	 * Tells why cancelling the posting of an order is off: `function ( \WC_Order $order ): string`, empty when it is on.
	 *
	 * @var callable|null
	 */
	private $unavailable;

	/**
	 * Creates the modal.
	 *
	 * @since 2.1.2
	 *
	 * @param Screen_Names    $names       Names.
	 * @param Order_Canceller $canceller   The marketplace's cancellation.
	 * @param string          $assets_url  Folder URL of the module's assets, with a trailing slash.
	 * @param string          $version     Plugin version.
	 * @param callable|null   $unavailable `function ( \WC_Order $order ): string` (since 2.1.16): the sentence that says why the account cannot cancel the posting now — its key cannot write, or the marketplace does not allow it — or '' when it can; null asks nothing.
	 */
	public function __construct( Screen_Names $names, Order_Canceller $canceller, string $assets_url, string $version, ?callable $unavailable = null ) {
		$this->names       = $names;
		$this->canceller   = $canceller;
		$this->assets_url  = $assets_url;
		$this->version     = $version;
		$this->unavailable = $unavailable;
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

		$off_reason = null === $this->unavailable ? '' : (string) call_user_func( $this->unavailable, $order );

		if ( '' !== $off_reason ) {
			return $this->off_button( $off_reason );
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
	 * The button of a posting that cannot be cancelled now: off, with the reason as its tooltip, like the label button. It carries no
	 * script hook and the dialog is not queued, so no request is possible.
	 *
	 * @param string $reason The sentence for the merchant.
	 * @return string HTML, escaped.
	 */
	private function off_button( string $reason ): string {
		return sprintf(
			'<span class="woodev-mp-cancel-off" title="%1$s"><button type="button" class="button" disabled aria-disabled="true">%2$s</button></span>',
			esc_attr( $reason ),
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
		<dialog id="<?php echo esc_attr( self::DIALOG_ID ); ?>" class="woodev-dialog woodev-mp-cancel-dialog" aria-labelledby="woodev-mp-cancel-title" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-button="<?php echo esc_attr( '.' . self::BUTTON_CLASS ); ?>" data-loading="<?php echo esc_attr__( 'Загружаем причины…', 'woodev-plugin-framework' ); ?>" data-sending="<?php echo esc_attr__( 'Отменяем…', 'woodev-plugin-framework' ); ?>" data-failed="<?php echo esc_attr__( 'Не удалось связаться с сайтом. Проверьте интернет и повторите.', 'woodev-plugin-framework' ); ?>">
			<div class="woodev-dialog__header">
				<h2 class="woodev-dialog__title" id="woodev-mp-cancel-title"><?php esc_html_e( 'Отменить отправление', 'woodev-plugin-framework' ); ?></h2>
				<button type="button" class="woodev-dialog__close" data-woodev-dialog-close aria-label="<?php esc_attr_e( 'Закрыть', 'woodev-plugin-framework' ); ?>"></button>
			</div>
			<div class="woodev-dialog__body">
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
			</div>
			<div class="woodev-dialog__footer">
				<button type="button" class="button" id="woodev-mp-cancel-close" data-woodev-dialog-close><?php esc_html_e( 'Не отменять', 'woodev-plugin-framework' ); ?></button>
				<button type="button" class="button button-primary" id="woodev-mp-cancel-confirm" disabled><?php esc_html_e( 'Отменить отправление', 'woodev-plugin-framework' ); ?></button>
			</div>
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

		$dialog = Dialog_Assets::enqueue( $this->assets_url, $this->version );

		wp_enqueue_style( self::ASSET_HANDLE, $this->assets_url . 'css/admin/order-cancel' . $suffix . '.css', [ $dialog ], $this->version );
		wp_enqueue_script( self::ASSET_HANDLE, $this->assets_url . 'js/admin/order-cancel' . $suffix . '.js', [ $dialog ], $this->version, true );
	}
}
