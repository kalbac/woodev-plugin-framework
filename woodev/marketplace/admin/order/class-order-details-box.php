<?php
/**
 * The read-only details box of a marketplace order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Date_Text;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Text;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;

defined( 'ABSPATH' ) || exit;

/**
 * The one box a marketplace order shows on top of its screen ({@see Order_Screen}): the posting with its status on the marketplace as a badge, the
 * dates, how it is paid and delivered and what it adds up to — and the marketplace's own actions as buttons, the cancellation when the
 * adapter can cancel ({@see Order_Canceller}) and the «Этикетка» button ({@see Label_Button}) when it has label calls.
 *
 * It shows what the WooCommerce order and the postings registry hold, so a field only the marketplace's own plugin knows (a
 * warehouse, a ship-by date) comes through the `woodev_marketplace_{id}_order_details` filter as one more `label => HTML` row.
 *
 * @since 2.1.13
 */
final class Order_Details_Box {
	/**
	 * Names.
	 *
	 * @var Screen_Names
	 */
	private $names;

	/**
	 * The adapter: the posting of an order, its finished state and the names of statuses are asked of it when it offers them.
	 *
	 * @var Marketplace_Adapter
	 */
	private $adapter;

	/**
	 * Returns the marketplace's own status of an order: `function ( int $order_id ): string`.
	 *
	 * @var callable
	 */
	private $remote_status;

	/**
	 * The cancel button, when the adapter can cancel.
	 *
	 * @var Cancel_Modal|null
	 */
	private $modal;

	/**
	 * The label button, when the adapter has label calls and the plugin has the orders page.
	 *
	 * @var Label_Button|null
	 */
	private $label_button;

	/**
	 * Folder URL of the module's assets, with a trailing slash.
	 *
	 * @var string
	 */
	private $assets_url;

	/**
	 * Plugin version, for the cache of the stylesheet.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * The seller action buttons, when the adapter declares actions.
	 *
	 * @var Seller_Action_Buttons|null
	 */
	private $seller;

	/**
	 * The unit list of a grouped order.
	 *
	 * @var Order_Unit_List|null
	 */
	private $units;

	/**
	 * Creates the box.
	 *
	 * @since 2.1.13
	 * @since 2.1.27 Takes the seller action buttons.
	 * @since 2.1.28 Takes the unit list.
	 *
	 * @param Screen_Names               $names         Names.
	 * @param Marketplace_Adapter        $adapter       The adapter.
	 * @param callable                   $remote_status `function ( int $order_id ): string`, the marketplace's status of an order, '' for none.
	 * @param Cancel_Modal|null          $modal         The cancel button, when the adapter can cancel.
	 * @param Label_Button|null          $label_button  The label button, when the adapter has label calls.
	 * @param string                     $assets_url    Folder URL of the module's assets, with a trailing slash.
	 * @param string                     $version       Plugin version.
	 * @param Seller_Action_Buttons|null $seller        The seller action buttons, when the adapter declares actions.
	 * @param Order_Unit_List|null       $units         The unit list of a grouped order.
	 */
	public function __construct( Screen_Names $names, Marketplace_Adapter $adapter, callable $remote_status, ?Cancel_Modal $modal, ?Label_Button $label_button, string $assets_url, string $version, ?Seller_Action_Buttons $seller = null, ?Order_Unit_List $units = null ) {
		$this->units         = $units;
		$this->names         = $names;
		$this->adapter       = $adapter;
		$this->remote_status = $remote_status;
		$this->modal         = $modal;
		$this->label_button  = $label_button;
		$this->assets_url    = $assets_url;
		$this->version       = $version;
		$this->seller        = $seller;
	}

	/**
	 * Adds the box on top of the order screen.
	 *
	 * @since 2.1.13
	 *
	 * @param \WC_Order $order  The order shown.
	 * @param string    $screen Screen id of the order screen.
	 * @return void
	 */
	public function add( \WC_Order $order, string $screen ): void {
		add_meta_box(
			'woodev-mp-' . $this->names->id() . '-order',
			sprintf( /* translators: %s: marketplace name. */ __( 'Заказ %s — сведения', 'woodev-plugin-framework' ), $this->names->name() ),
			function () use ( $order ): void {
				$this->render( $order );
			},
			$screen,
			'normal',
			'high'
		);
	}

	/**
	 * Prints the box.
	 *
	 * @since 2.1.13
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	public function render( \WC_Order $order ): void {
		$this->enqueue_style();

		echo '<div class="panel-wrap woocommerce woodev-mp-details"><div id="order_data" class="panel woocommerce-order-data">';
		echo $this->heading( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		echo '<div class="order_data_column_container">';
		$this->column( __( 'Основное', 'woodev-plugin-framework' ), $this->general_rows( $order ) );
		$this->column( __( 'Суммы', 'woodev-plugin-framework' ), $this->amount_rows( $order ) );
		echo '</div><div class="clear"></div>';
		echo $this->unit_list( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		echo $this->actions( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		echo '</div></div>';
	}

	/**
	 * Builds the list of the order's goods: the units the order keeps, else the ones the marketplace offers actions on.
	 *
	 * @param \WC_Order $order The order.
	 * @return string HTML, escaped; empty when there is none to show.
	 */
	private function unit_list( \WC_Order $order ): string {
		$kept = null === $this->units ? '' : $this->units->render( $order );

		if ( '' !== $kept || null === $this->seller ) {
			return $kept;
		}

		return $this->seller->unit_list( $order );
	}

	/**
	 * Enqueues the stylesheet (in the footer: the box is printed in the page body).
	 *
	 * @return void
	 */
	private function enqueue_style(): void {
		wp_enqueue_style( 'woodev-marketplace-order-details', $this->assets_url . 'css/admin/order-details' . \Woodev_Plugin::get_assets_suffix() . '.css', [], $this->version );
	}

	/**
	 * Builds the rows of the first column.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<string,string> Label => HTML.
	 */
	private function general_rows( \WC_Order $order ): array {
		/* translators: %s: the marketplace's word for a posting in the genitive, «отправления». */
		$number_label = sprintf( __( 'Номер %s', 'woodev-plugin-framework' ), $this->names->posting_noun()->of_one() );

		$rows = [
			$number_label => esc_html( $this->posting_number( $order ) ),
			__( 'Номер заказа на сайте', 'woodev-plugin-framework' ) => esc_html( (string) $order->get_order_number() ),
			__( 'Статус на сайте', 'woodev-plugin-framework' ) => esc_html( html_entity_decode( (string) wc_get_order_status_name( $order->get_status() ), ENT_QUOTES, 'UTF-8' ) ),
			__( 'Создан', 'woodev-plugin-framework' ) => esc_html( $this->date( $order->get_date_created() ) ),
			__( 'Обновлён', 'woodev-plugin-framework' ) => esc_html( $this->date( $order->get_date_modified() ) ),
			__( 'Способ оплаты', 'woodev-plugin-framework' ) => esc_html( (string) $order->get_payment_method_title() ),
			__( 'Доставка', 'woodev-plugin-framework' ) => esc_html( (string) $order->get_shipping_method() ),
			__( 'Адрес', 'woodev-plugin-framework' ) => wp_kses_post( (string) $order->get_formatted_billing_address( '' ) ),
			__( 'Примечание покупателя', 'woodev-plugin-framework' ) => nl2br( esc_html( (string) $order->get_customer_note() ) ),
		];

		/**
		 * Adds the rows only the marketplace's plugin knows to the details box of an order.
		 *
		 * @since 2.1.13
		 *
		 * @param array<string,string> $rows  Label => HTML (escape the value).
		 * @param \WC_Order            $order The order.
		 */
		$filtered = apply_filters( 'woodev_marketplace_' . $this->names->id() . '_order_details', $rows, $order );

		return is_array( $filtered ) ? $filtered : $rows;
	}

	/**
	 * Builds the rows of the amounts column: what the order adds up to, as WooCommerce holds it.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<string,string> Label => HTML.
	 */
	private function amount_rows( \WC_Order $order ): array {
		$currency = [ 'currency' => $order->get_currency() ];

		return [
			__( 'Товары', 'woodev-plugin-framework' )  => wc_price( (float) $order->get_subtotal(), $currency ),
			__( 'Доставка', 'woodev-plugin-framework' ) => wc_price( (float) $order->get_shipping_total(), $currency ),
			__( 'Итого', 'woodev-plugin-framework' )   => wc_price( (float) $order->get_total(), $currency ),
		];
	}

	/**
	 * Prints one column of label/value rows; a row without a value is left out.
	 *
	 * @param string               $title Column title.
	 * @param array<string,string> $rows  Label => HTML.
	 * @return void
	 */
	private function column( string $title, array $rows ): void {
		echo '<div class="order_data_column"><h3>' . esc_html( $title ) . '</h3><table cellspacing="0"><tbody>';

		foreach ( $rows as $label => $html ) {
			if ( '' !== trim( wp_strip_all_tags( (string) $html ) ) ) {
				echo '<tr><th>' . esc_html( $label ) . ':</th><td>' . wp_kses_post( (string) $html ) . '</td></tr>';
			}
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Builds the marketplace's actions: the cancel button (or the reason there is none) and the label button.
	 *
	 * @param \WC_Order $order The order.
	 * @return string HTML, escaped; empty when the marketplace offers no action.
	 */
	private function actions( \WC_Order $order ): string {
		$cancel = null === $this->modal ? '' : $this->modal->button( $order );

		if ( '' === $cancel && $this->adapter instanceof Order_Canceller && $this->adapter->is_finished( $order ) ) {
			/* translators: %s: the marketplace's word for a posting, capitalised: «Отправление». */
			$text   = sprintf( __( '%s завершено или отменено — отменить его нельзя.', 'woodev-plugin-framework' ), Posting_Noun::capitalised( $this->names->posting_noun()->one() ) );
			$cancel = '<span class="description">' . esc_html( $text ) . '</span>';
		}

		$html = null === $this->seller ? '' : $this->seller->order_buttons( $order ) . ' ';
		$html .= $cancel;

		if ( null !== $this->label_button ) {
			$html .= ' ' . $this->label_button->button( $order );
		}

		return '' === trim( $html ) ? '' : '<div class="actions">' . $html . '</div>';
	}

	/**
	 * Returns the posting number, when the adapter keeps one on its orders.
	 *
	 * @param \WC_Order $order The order.
	 * @return string
	 */
	private function posting_number( \WC_Order $order ): string {
		return $this->adapter instanceof Order_Canceller ? $this->adapter->posting_number( $order ) : '';
	}

	/**
	 * Builds the heading of the box: the posting number and the marketplace's status of it as a badge — the badge of the status column
	 * of «Заказы маркетплейсов» (the same name and tone, {@see Posting_Status_Text}).
	 *
	 * @param \WC_Order $order The order.
	 * @return string HTML, escaped; empty when the order has neither.
	 */
	private function heading( \WC_Order $order ): string {
		$content = trim( esc_html( $this->posting_number( $order ) ) . ' ' . $this->status_badge( $order ) );

		return '' === $content ? '' : '<h2 class="woocommerce-order-data__heading woodev-mp-details__heading">' . $content . '</h2>';
	}

	/**
	 * Builds the badge of the marketplace's status of an order.
	 *
	 * @param \WC_Order $order The order.
	 * @return string HTML, escaped; empty when the marketplace has recorded no status.
	 */
	private function status_badge( \WC_Order $order ): string {
		$status = (string) call_user_func( $this->remote_status, $order->get_id() );

		if ( '' === $status ) {
			return '';
		}

		return sprintf(
			'<span class="woodev-mp-badge is-%1$s">%2$s</span>',
			esc_attr( Posting_Status_Text::tone( $status ) ),
			esc_html( Posting_Status_Text::label( $status, $this->adapter instanceof Posting_Status_Names ? $this->adapter : null ) )
		);
	}

	/**
	 * Formats a date like every marketplace screen does ({@see Date_Text}).
	 *
	 * @param \WC_DateTime|null $date Date.
	 * @return string Empty for none.
	 */
	private function date( $date ): string {
		return $date instanceof \WC_DateTime ? Date_Text::datetime( $date->getTimestamp() ) : '';
	}
}
