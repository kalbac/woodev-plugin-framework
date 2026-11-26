<?php
/**
 * The reasons of a shipment action in the records engine's shape.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Shipments;

use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Marketplace\Flows\Shipment_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a {@see Shipment_Report} into the groups of a plan or a report.
 *
 * @since 2.1.28
 */
final class Shipment_Report_Groups {
	/**
	 * The reasons in the records engine's shape, the refusals first: one group per sentence with the first few units it concerns.
	 * The engine takes a few groups, so sentences beyond that share the last one.
	 *
	 * @since 2.1.28
	 *
	 * @param Shipment_Report $report What an action did.
	 * @return array<int,array<string,mixed>>
	 */
	public static function of( Shipment_Report $report ): array {
		$entries = [];

		foreach ( [
			'failed'  => $report->failed(),
			'skipped' => $report->skipped(),
		] as $kind => $found ) {
			foreach ( $found as $entry ) {
				$entries[] = [ $kind, mb_substr( $entry['reason'], 0, Records_Definition::MAX_STRING_LENGTH ), $entry['example'] ];
			}
		}

		$groups = [];

		foreach ( $entries as list( $kind, $reason, $example ) ) {
			$key = $kind . '|' . $reason;

			if ( ! isset( $groups[ $key ] ) && count( $groups ) >= Action_Response::MAX_GROUPS - 1 ) {
				$reason = __( 'Другие причины: выберите меньше товаров, чтобы увидеть каждую.', 'woodev-plugin-framework' );
				$key    = 'rest';
			}

			$groups[ $key ] = $groups[ $key ] ?? [
				'kind'     => $kind,
				'reason'   => $reason,
				'count'    => 0,
				'examples' => [],
			];

			++$groups[ $key ]['count'];

			if ( count( $groups[ $key ]['examples'] ) < Action_Response::MAX_EXAMPLES ) {
				$groups[ $key ]['examples'][] = mb_substr( $example, 0, Action_Response::MAX_SHORT_LENGTH );
			}
		}

		return array_values( $groups );
	}
}
