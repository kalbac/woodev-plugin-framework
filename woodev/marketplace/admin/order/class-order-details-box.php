<?php
/**
 * The read-only details box of a marketplace order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Text;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * The one box a marketplace order shows on top of its screen ({@see Order_Screen}): the posting, its status on the marketplace, the
 * dates, how it is paid and delivered and what it adds up to — and the marketplace's own actions as buttons, the cancellation when the
 * adapter can cancel ({@see Order_Canceller}) and the link to the page that makes labels and documents when it can.
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
	 * The page that makes labels and documents; empty when the adapter has none.
	 *
	 * @var string
	 */
	private $labels_url;

	/**
	 * Creates the box.
	 *
	 * @since 2.1.13
	 *
	 * @param Screen_Names        $names         Names.
	 * @param Marketplace_Adapter $adapter       The adapter.
	 * @param callable            $remote_status `function ( int $order_id ): string`, the marketplace's status of an order, '' for none.
	 * @param Cancel_Modal|null   $modal         The cancel button, when the adapter can cancel.
	 * @param string              $labels_url    URL of the page that makes labels and documents; '' for an adapter without them.
	 */
	public function __construct( Screen_Names $names, Marketplace_Adapter $adapter, callable $remote_status, ?Cancel_Modal $modal, string $labels_url ) {
		$this->names         = $names;
		$this->adapter       = $adapter;
		$this->remote_status = $remote_status;
		$this->modal         = $modal;
		$this->labels_url    = $labels_url;
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
		$heading = trim( $this->posting_number( $order ) . ' ' . $this->status_label( $order ) );

		echo '<div class="panel-wrap woocommerce"><div id="order_data" class="panel woocommerce-order-data">';

		if ( '' !== $heading ) {
			echo '<h2 class="woocommerce-order-data__heading">' . esc_html( $heading ) . '</h2>';
		}

		echo '<div class="order_data_column_container">';
		$this->column( __( 'Основное', 'woodev-plugin-framework' ), $this->general_rows( $order ) );
		$this->column( __( 'Суммы', 'woodev-plugin-framework' ), $this->amount_rows( $order ) );
		echo '</div><div class="clear"></div>';
		echo $this->actions( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		echo '</div></div>';
	}

	/**
	 * Builds the rows of the first column.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<string,string> Label => HTML.
	 */
	private function general_rows( \WC_Order $order ): array {
		$rows = [
			__( 'Номер отправления', 'woodev-plugin-framework' ) => esc_html( $this->posting_number( $order ) ),
			__( 'Номер заказа на сайте', 'woodev-plugin-framework' ) => esc_html( (string) $order->get_order_number() ),
			__( 'Статус на маркетплейсе', 'woodev-plugin-framework' ) => esc_html( $this->status_label( $order ) ),
			__( 'Статус на сайте', 'woodev-plugin-framework' ) => esc_html( html_entity_decode( (string) wc_get_order_status_name( $order->get_status() ), ENT_QUOTES, 'UTF-8' ) ),
			__( 'Создан', 'woodev-plugin-framework' ) => esc_html( $this->date( $order->get_date_created() ) ),
			__( 'Обновлён', 'woodev-plugin-framework' ) => esc_html( $this->date( $order->get_date_modified() ) ),
			__( 'Способ оплаты', 'woodev-plugin-framework' ) => esc_html( (string) $order->get_payment_method_title() ),
			__( 'Доставка', 'woodev-plugin-framework' ) => esc_html( (string) $order->get_shipping_method() ),
			__( 'Адрес', 'woodev-plugin-framework' ) => wp_kses_post( (string) $order->get_formatted_billing_address( '' ) ),
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
	 * Builds the marketplace's actions: the cancel button (or the reason there is none) and the link to labels and documents.
	 *
	 * @param \WC_Order $order The order.
	 * @return string HTML, escaped; empty when the marketplace offers no action.
	 */
	private function actions( \WC_Order $order ): string {
		$html = null === $this->modal ? '' : $this->modal->button( $order );

		if ( '' === $html && $this->adapter instanceof Order_Canceller && $this->adapter->is_finished( $order ) ) {
			$html = '<span class="description">' . esc_html__( 'Отправление завершено или отменено — отменить его нельзя.', 'woodev-plugin-framework' ) . '</span>';
		}

		if ( '' !== $this->labels_url ) {
			$html .= ' <a class="button" href="' . esc_url( $this->labels_url ) . '">' . esc_html__( 'Этикетки и документы', 'woodev-plugin-framework' ) . '</a>';
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
	 * Returns the merchant-facing name of the marketplace's status of an order.
	 *
	 * @param \WC_Order $order The order.
	 * @return string Empty when the marketplace has recorded none.
	 */
	private function status_label( \WC_Order $order ): string {
		$status = (string) call_user_func( $this->remote_status, $order->get_id() );

		return '' === $status ? '' : Posting_Status_Text::label( $status, $this->adapter instanceof Posting_Status_Names ? $this->adapter : null );
	}

	/**
	 * Formats a date the way WooCommerce does.
	 *
	 * @param \WC_DateTime|null $date Date.
	 * @return string Empty for none.
	 */
	private function date( $date ): string {
		return $date instanceof \WC_DateTime ? $date->date_i18n( wc_date_format() . ' ' . wc_time_format() ) : '';
	}
}
