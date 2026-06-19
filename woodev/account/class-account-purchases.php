<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Account_Purchases' ) ) :

	/**
	 * Pure normalizer for the connector's /purchases response.
	 *
	 * Maps the raw connector payload ({ purchases: [ { download_id, slug, title,
	 * icon, date }, ... ] }) to the lean UI list the «Мои покупки» tab renders,
	 * and collects the deduped positive-integer download ids the catalog uses to
	 * badge «Куплено». PURE: depends only on its input and WP escaping helpers, so
	 * it is unit-tested without a live connection; the REST controller feeds it the
	 * decoded response. Defensive against a hostile/malformed issuer reply: any
	 * non-array item or non-positive id is skipped, missing keys default to ''.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Account_Purchases {

		/**
		 * Normalizes a connector /purchases response into the lean UI list.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $response Decoded connector response (expects ['purchases' => [...]]).
		 *
		 * @return array<int,array<string,mixed>> List of { id, title, icon, date }.
		 */
		public static function normalize( $response ): array {

			$items = ( is_array( $response ) && isset( $response['purchases'] ) && is_array( $response['purchases'] ) )
				? $response['purchases']
				: array();

			$seen = array();
			$out  = array();

			foreach ( $items as $item ) {

				if ( ! is_array( $item ) ) {
					continue;
				}

				$id = isset( $item['download_id'] ) ? (int) $item['download_id'] : 0;

				if ( $id <= 0 || isset( $seen[ $id ] ) ) {
					continue;
				}

				$seen[ $id ] = true;

				$out[] = array(
					'id'    => $id,
					'title' => isset( $item['title'] ) ? (string) $item['title'] : '',
					'icon'  => isset( $item['icon'] ) ? esc_url_raw( (string) $item['icon'] ) : '',
					'date'  => isset( $item['date'] ) ? (string) $item['date'] : '',
				);
			}

			return $out;
		}

		/**
		 * Deduped positive-integer download ids from a normalized purchase list —
		 * the «Куплено» badge set.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,array<string,mixed>> $purchases Normalized purchases (each with an 'id').
		 *
		 * @return array<int,int> Deduped, order-preserving list of download ids.
		 */
		public static function download_ids( array $purchases ): array {

			$ids = array();

			foreach ( $purchases as $purchase ) {

				$id = ( is_array( $purchase ) && isset( $purchase['id'] ) ) ? (int) $purchase['id'] : 0;

				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}

			return array_values( $ids );
		}
	}

endif;
