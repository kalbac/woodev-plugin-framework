<?php
/**
 * The seller action buttons of the order screen and their dialog.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Dialog_Assets;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;

defined( 'ABSPATH' ) || exit;

/**
 * Prints, inside the details box of a marketplace order, the buttons of the actions offered on the whole order and a list of the
 * order's goods with the buttons offered on one of them. The rule is the table's ({@see Seller_Action_Runner}): a button that
 * cannot run stays visible, off, with the reason as its tooltip, and the server judges every request again
 * ({@see Seller_Action_Ajax}). An action that does not exist for an order or a unit is not drawn for it.
 *
 * One native `<dialog>` serves every button: it shows the action's confirmation and its fields, built by the script
 * (`assets/js/admin/order-actions.js`) with plain DOM calls (`textContent`, never `innerHTML`).
 *
 * @since 2.1.27
 */
final class Seller_Action_Buttons {
	public const DIALOG_ID    = 'woodev-mp-action-dialog';
	public const BUTTON_CLASS = 'woodev-mp-action';

	private const ASSET_HANDLE = 'woodev-marketplace-order-actions';

	/**
	 * @var Screen_Names
	 */
	private $names;

	/**
	 * @var Seller_Action_Runner
	 */
	private $runner;

	/**
	 * Returns the account of an order's posting: `function ( int $order_id ): ?Account`.
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
	 * Plugin version, for the cache of the assets.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * @since 2.1.27
	 *
	 * @param Screen_Names         $names            Names.
	 * @param Seller_Action_Runner $runner           The seller actions.
	 * @param callable             $account_of_order `function ( int $order_id ): ?Account`; null for an order with no posting.
	 * @param string               $assets_url       Folder URL of the module's assets, with a trailing slash.
	 * @param string               $version          Plugin version.
	 */
	public function __construct( Screen_Names $names, Seller_Action_Runner $runner, callable $account_of_order, string $assets_url, string $version ) {
		$this->names            = $names;
		$this->runner           = $runner;
		$this->account_of_order = $account_of_order;
		$this->assets_url       = $assets_url;
		$this->version          = $version;
	}

	/**
	 * Returns the buttons of the actions offered on the whole order.
	 *
	 * @since 2.1.27
	 *
	 * @param \WC_Order $order Order.
	 * @return string HTML, escaped; empty when there are none.
	 */
	public function order_buttons( \WC_Order $order ): string {
		$account = $this->account( $order );

		if ( null === $account ) {
			return '';
		}

		$html = '';

		foreach ( $this->runner->order_states( $account, $order ) as $action_id => $state ) {
			$action = $this->runner->action( (string) $action_id );

			if ( null !== $action ) {
				$html .= ' ' . $this->button( $order, $action, '', $this->own_reason( $order, $state['reason'] ) );
			}
		}

		return trim( $html );
	}

	/**
	 * Returns the list of the order's goods with the buttons offered on one of them, for an order whose units the shop does not
	 * keep itself ({@see Order_Unit_List} lists the ones it does).
	 *
	 * @since 2.1.27
	 *
	 * @param \WC_Order $order Order.
	 * @return string HTML, escaped; empty when no action is offered on a unit.
	 */
	public function unit_list( \WC_Order $order ): string {
		$rows = '';

		foreach ( $this->unit_buttons( $order ) as $row ) {
			$rows .= sprintf(
				'<tr><td>%1$s</td><td>%2$s</td><td class="woodev-mp-units__actions">%3$s</td></tr>',
				esc_html( $row['unit']->label() ),
				esc_html( $row['unit']->status_label() ),
				$row['buttons']
			);
		}

		if ( '' === $rows ) {
			return '';
		}

		$this->enqueue_style(); // The list is styled even when every button in it is off.

		return sprintf(
			'<table class="widefat striped woodev-mp-units"><thead><tr><th>%1$s</th><th>%2$s</th><th>%3$s</th></tr></thead><tbody>%4$s</tbody></table>',
			esc_html__( 'Товар', 'woodev-plugin-framework' ),
			esc_html__( 'Статус', 'woodev-plugin-framework' ),
			esc_html__( 'Действия', 'woodev-plugin-framework' ),
			$rows
		);
	}

	/**
	 * Returns the units the marketplace lists for the order, each with the buttons offered on it.
	 *
	 * @since 2.1.28
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array{unit:Posting_Unit,buttons:string}> Buttons as escaped HTML; empty when no action is offered on a unit.
	 */
	public function unit_buttons( \WC_Order $order ): array {
		$account = $this->account( $order );
		$actions = $this->runner->actions_for( Seller_Action::UNIT );

		if ( null === $account || [] === $actions ) {
			return [];
		}

		$rows = [];

		foreach ( $this->runner->units( $order ) as $unit ) {
			$buttons = '';

			foreach ( $actions as $action ) {
				if ( $unit->has_action( $action->id() ) ) {
					$reason   = $this->own_reason( $order, $this->runner->unit_reason( $account, $action, $order, $unit ) );
					$buttons .= ' ' . $this->button( $order, $action, $unit->id(), $reason );
				}
			}

			$rows[] = [
				'unit'    => $unit,
				'buttons' => $buttons,
			];
		}

		return $rows;
	}

	/**
	 * Prints the dialog (hooked on `admin_footer` by a button that is on).
	 *
	 * @since 2.1.27
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
		<dialog id="<?php echo esc_attr( self::DIALOG_ID ); ?>" class="woodev-dialog woodev-mp-action-dialog" aria-labelledby="woodev-mp-action-title" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-button="<?php echo esc_attr( '.' . self::BUTTON_CLASS ); ?>" data-sending="<?php echo esc_attr__( 'Выполняем…', 'woodev-plugin-framework' ); ?>" data-loading="<?php echo esc_attr__( 'Загружаем список…', 'woodev-plugin-framework' ); ?>" data-choose="<?php echo esc_attr__( 'Выберите…', 'woodev-plugin-framework' ); ?>" data-close="<?php echo esc_attr__( 'Закрыть', 'woodev-plugin-framework' ); ?>" data-failed="<?php echo esc_attr__( 'Не удалось связаться с сайтом. Проверьте интернет и повторите.', 'woodev-plugin-framework' ); ?>">
			<div class="woodev-dialog__header">
				<h2 class="woodev-dialog__title" id="woodev-mp-action-title"></h2>
				<button type="button" class="woodev-dialog__close" data-woodev-dialog-close aria-label="<?php esc_attr_e( 'Закрыть', 'woodev-plugin-framework' ); ?>"></button>
			</div>
			<div class="woodev-dialog__body">
				<p id="woodev-mp-action-text"></p>
				<div id="woodev-mp-action-fields"></div>
				<p id="woodev-mp-action-status" role="status"></p>
				<ul id="woodev-mp-action-lines"></ul>
			</div>
			<div class="woodev-dialog__footer">
				<button type="button" class="button" id="woodev-mp-action-close" data-woodev-dialog-close data-label="<?php esc_attr_e( 'Отмена', 'woodev-plugin-framework' ); ?>"><?php esc_html_e( 'Отмена', 'woodev-plugin-framework' ); ?></button>
				<button type="button" class="button button-primary" id="woodev-mp-action-confirm"></button>
			</div>
		</dialog>
		<?php
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return Account|null The account the order belongs to; null when the user may not edit orders at all.
	 */
	private function account( \WC_Order $order ): ?Account {
		if ( ! current_user_can( Cancel_Ajax::CAPABILITY ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown,WordPress.WP.Capabilities.Undetermined -- WooCommerce's own capability.
			return null;
		}

		$account = call_user_func( $this->account_of_order, $order->get_id() );

		return $account instanceof Account ? $account : null;
	}

	/**
	 * Puts the user's right on this order before any other reason.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Why the action is off otherwise.
	 * @return string
	 */
	private function own_reason( \WC_Order $order, string $reason ): string {
		return current_user_can( 'edit_shop_order', $order->get_id() ) ? $reason : __( 'Нет права изменять этот заказ.', 'woodev-plugin-framework' );
	}

	/**
	 * @param \WC_Order     $order      Order.
	 * @param Seller_Action $action     Action.
	 * @param string        $unit_id    The unit; empty for the whole order.
	 * @param string        $off_reason Why the button is off; empty when it is on.
	 * @return string HTML, escaped.
	 */
	private function button( \WC_Order $order, Seller_Action $action, string $unit_id, string $off_reason ): string {
		$text = '' === $action->short_label() ? $action->label() : $action->short_label();

		if ( '' !== $off_reason ) {
			// No script hook and no dialog: no request is possible.
			return sprintf(
				'<span class="woodev-mp-action-off" title="%1$s"><button type="button" class="button" disabled aria-disabled="true">%2$s</button></span>',
				esc_attr( $off_reason ),
				esc_html( $text )
			);
		}

		$this->enqueue_assets();
		add_action( 'admin_footer', [ $this, 'print_dialog' ] );

		return sprintf(
			'<button type="button" class="button %1$s" data-order-id="%2$d" data-nonce="%3$s" data-run-action="%4$s" data-options-action="%5$s" data-unit="%6$s" data-config="%7$s">%8$s</button>',
			esc_attr( self::BUTTON_CLASS ),
			$order->get_id(),
			esc_attr( wp_create_nonce( $this->names->seller_nonce( $order->get_id() ) ) ),
			esc_attr( $this->names->seller_action() ),
			esc_attr( $this->names->seller_options_action() ),
			esc_attr( $unit_id ),
			esc_attr( (string) wp_json_encode( self::config( $action ) ) ),
			esc_html( $text )
		);
	}

	/**
	 * What the dialog needs to draw itself.
	 *
	 * @param Seller_Action $action Action.
	 * @return array<string,mixed>
	 */
	private static function config( Seller_Action $action ): array {
		$fields = [];

		foreach ( $action->fields() as $field ) {
			$fields[] = [
				'id'       => $field->id(),
				'label'    => $field->label(),
				'type'     => $field->type(),
				'required' => $field->is_required(),
				'help'     => $field->help(),
				'options'  => $field->options(),
				'source'   => $field->has_options_source(),
			];
		}

		return [
			'id'           => $action->id(),
			'label'        => $action->label(),
			'confirmation' => $action->confirmation(),
			'danger'       => 'danger' === $action->tone(),
			'fields'       => $fields,
		];
	}

	/**
	 * Enqueues the dialog's script and the stylesheet (in the footer: the buttons are printed in the page body).
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		$dialog = Dialog_Assets::enqueue( $this->assets_url, $this->version );

		$this->enqueue_style();
		wp_enqueue_script( self::ASSET_HANDLE, $this->assets_url . 'js/admin/order-actions' . \Woodev_Plugin::get_assets_suffix() . '.js', [ $dialog ], $this->version, true );
	}

	/**
	 * Enqueues the stylesheet of the buttons and of the lists they stand in.
	 *
	 * @since 2.1.28 Public.
	 *
	 * @return void
	 */
	public function enqueue_style(): void {
		wp_enqueue_style( self::ASSET_HANDLE, $this->assets_url . 'css/admin/order-actions' . \Woodev_Plugin::get_assets_suffix() . '.css', [], $this->version );
	}
}
