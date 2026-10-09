<?php
/**
 * Shipping orders — the small badges a row can carry under its tracking number
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Order_Row_Flags' ) ) :

	/**
	 * The row flags of the orders page (s164): a short list of `{ label, tone }` badges a carrier plugin
	 * adds to an order — «Нужно вызвать курьера», «Курьер вызван на 12.10» — drawn under the tracking number
	 * on the orders page and under the details table of the order's metabox.
	 *
	 * The framework adds none itself. A carrier fills the list through the `woodev_shipping_order_row_flags`
	 * filter, and {@see self::sanitize()} re-validates whatever came back, so the client only ever meets a
	 * well-formed list.
	 *
	 * ⚠ **The filter runs for EVERY row of a page** (and again for every row rebuilt after an action), so a
	 * callback has to be CHEAP: order meta and options it can read without a request. A carrier API call per row
	 * is exactly what makes the table crawl — a plugin that needs remote state keeps its own copy and updates it
	 * when it talks to the carrier anyway.
	 *
	 * @since 2.0.2
	 */
	final class Order_Row_Flags {

		/**
		 * The tones a flag may use — the same five the delivery-status badge has, so a flag and a status read as one
		 * palette. An unknown tone falls back to {@see self::DEFAULT_TONE}.
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		public const TONES = [ 'ok', 'warn', 'error', 'info', 'muted' ];

		/**
		 * The tone of a flag that names none.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const DEFAULT_TONE = 'muted';

		/**
		 * The most flags one row shows; the rest are dropped. A row is not a to-do list, and a cell that grows with
		 * every plugin's opinion pushes the table apart.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		public const MAX_FLAGS = 3;

		/**
		 * The longest a flag's label may be, in characters; a longer one is cut.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		public const MAX_LABEL_LENGTH = 60;

		/** Not instantiable — a namespace of pure functions. */
		private function __construct() {}

		/**
		 * The flags one order carries, after the carrier plugins had their say.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null when it could not be resolved.
		 * @return array<int,array{label:string,tone:string,title?:string,icon?:string}> see {@see self::sanitize()}.
		 */
		public static function for_order( \WC_Order $order, ?Orders_Provider $provider ): array {
			/**
			 * Filters the badges shown under an order's tracking number (orders page and order metabox).
			 *
			 * Each flag is `[ 'label' => string (required, short), 'tone' => 'ok'|'warn'|'error'|'info'|'muted'
			 * (optional, default `muted`), 'title' => string (optional tooltip), 'icon' => string (optional
			 * Dashicons slug, e.g. `warning`) ]`. A flag with an icon is drawn icon-only — coloured by its tone,
			 * right after the tracking number, its tooltip the `title` (else the label); one without stays a
			 * badge under the number. A malformed flag, a repeated label, and anything past
			 * {@see Order_Row_Flags::MAX_FLAGS} is dropped.
			 *
			 * ⚠ Runs for every row of every page: keep the callback CHEAP — meta and options only, never a
			 * request to the carrier. A plugin serving several carriers checks `$provider->get_id()` first.
			 *
			 * @since 2.0.2
			 *
			 * @param array<int,array<string,mixed>> $flags    the flags so far; `[]`.
			 * @param \WC_Order                       $order    the order.
			 * @param Orders_Provider|null            $provider the matched carrier, or null.
			 */
			return self::sanitize( apply_filters( 'woodev_shipping_order_row_flags', [], $order, $provider ) );
		}

		/**
		 * Re-validates a filtered flag list, dropping what does not fit rather than shipping it to the page.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $flags the filtered value, of unknown shape.
		 * @return array<int,array{label:string,tone:string,title?:string,icon?:string}> each flag has `label` and `tone`; `title` and `icon` only when declared (a usable Dashicons slug).
		 */
		public static function sanitize( $flags ): array {
			if ( ! is_array( $flags ) ) {
				return [];
			}

			$clean = [];
			$seen  = [];

			foreach ( $flags as $flag ) {
				if ( count( $clean ) >= self::MAX_FLAGS ) {
					break;
				}

				if ( ! is_array( $flag ) ) {
					continue;
				}

				$label = isset( $flag['label'] ) && is_string( $flag['label'] )
					? mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $flag['label'] ) ) ), 0, self::MAX_LABEL_LENGTH )
					: '';

				if ( '' === $label || isset( $seen[ $label ] ) ) {
					continue;
				}

				$seen[ $label ] = true;
				$entry          = [
					'label' => $label,
					'tone'  => isset( $flag['tone'] ) && is_string( $flag['tone'] ) && in_array( $flag['tone'], self::TONES, true )
						? $flag['tone']
						: self::DEFAULT_TONE,
				];

				$title = Order_Action_Fields::sanitize_help( $flag['title'] ?? null );

				if ( '' !== $title ) {
					$entry['title'] = $title;
				}

				$icon = Order_Actions::sanitize_icon( $flag['icon'] ?? null );

				if ( '' !== $icon ) {
					$entry['icon'] = $icon;
				}

				$clean[] = $entry;
			}

			return $clean;
		}
	}

endif;
