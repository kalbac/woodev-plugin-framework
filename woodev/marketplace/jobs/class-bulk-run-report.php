<?php
/**
 * The bounded report of a bulk run.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Groups of reasons with a count and a few examples, merged part after part (#1795). It is bounded so that a 50 000-offer run on a
 * heterogeneous catalogue cannot grow the row without limit and still fits the records engine's answer
(at most 50 groups of {@see self::EXAMPLES} examples):
 *
 * - at most {@see self::DISTINCT} distinct reasons are kept; the later ones merge into one group «Другие причины» whose count is the
 *   sum of the merged ones, so the counts of the report always add up to what was skipped and what failed;
 * - a reason and an example are cut to {@see self::REASON_LENGTH} and {@see self::EXAMPLE_LENGTH} characters, which keeps the stored
 *   JSON of a full report under {@see self::MAX_BYTES} (the test proves it on the worst case).
 *
 * A group is the records engine's shape: `kind` (`skipped` or `failed`), `reason`, `count`, `fix_label`, `fix_url`, `examples`.
 *
 * @since 2.1.8
 */
final class Bulk_Run_Report {
	/**
	 * Distinct reasons kept before the overflow group takes over; with the overflow group the report has at most 50 entries, the most
	 * the records engine's answer carries.
	 */
	public const DISTINCT = 49;

	/**
	 * Examples kept under a reason: what the records engine's answer carries.
	 */
	public const EXAMPLES = 5;

	/**
	 * Longest stored reason and example, in characters.
	 */
	public const REASON_LENGTH  = 200;
	public const EXAMPLE_LENGTH = 60;

	/**
	 * What the stored JSON of a full report must stay under (a proposal; the unit test proves it).
	 */
	public const MAX_BYTES = 65536;

	/**
	 * Merges the groups of one more part into the report.
	 *
	 * @since 2.1.8
	 *
	 * @param array<int,array<string,mixed>> $report Stored groups.
	 * @param array<int,array<string,mixed>> $groups Groups of the part.
	 * @return array<int,array<string,mixed>>
	 */
	public static function merge( array $report, array $groups ): array {
		$named    = [];
		$overflow = null;

		foreach ( $report as $group ) {
			if ( self::overflow_reason() === $group['reason'] ) {
				$overflow = $group;
				continue;
			}

			$named[ self::key( $group ) ] = $group;
		}

		foreach ( $groups as $group ) {
			$group = self::cut( $group );
			$key   = self::key( $group );

			if ( isset( $named[ $key ] ) ) {
				$named[ $key ] = self::added( $named[ $key ], $group );
			} elseif ( count( $named ) < self::DISTINCT ) {
				$named[ $key ] = $group;
			} else {
				$overflow = self::overflowed( $overflow, $group );
			}
		}

		$merged = array_values( $named );

		if ( null !== $overflow ) {
			$merged[] = $overflow;
		}

		return $merged;
	}

	/**
	 * Adds up what a report counts.
	 *
	 * @since 2.1.8
	 *
	 * @param array<int,array<string,mixed>> $report Groups.
	 * @return int
	 */
	public static function total( array $report ): int {
		return (int) array_sum( array_map( 'intval', array_column( $report, 'count' ) ) );
	}

	/**
	 * The reason of the group that holds every reason past the {@see self::DISTINCT}th.
	 *
	 * @since 2.1.8
	 *
	 * @return string
	 */
	public static function overflow_reason(): string {
		return __( 'Другие причины', 'woodev-plugin-framework' );
	}

	/**
	 * @param array<string,mixed> $group Group.
	 * @return string
	 */
	private static function key( array $group ): string {
		return $group['kind'] . '|' . $group['reason'];
	}

	/**
	 * @param array<string,mixed> $group Group.
	 * @return array<string,mixed> The group with a short reason and short examples.
	 */
	private static function cut( array $group ): array {
		$group['reason']   = mb_substr( (string) $group['reason'], 0, self::REASON_LENGTH );
		$group['count']    = (int) $group['count'];
		$group['examples'] = array_map(
			static function ( $example ): string {
				return mb_substr( (string) $example, 0, self::EXAMPLE_LENGTH );
			},
			array_slice( array_values( (array) ( $group['examples'] ?? [] ) ), 0, self::EXAMPLES )
		);

		return $group;
	}

	/**
	 * @param array<string,mixed> $kept     Group already in the report.
	 * @param array<string,mixed> $incoming Group of the part with the same reason.
	 * @return array<string,mixed>
	 */
	private static function added( array $kept, array $incoming ): array {
		$kept['count']   += $incoming['count'];
		$kept['examples'] = array_slice( array_merge( $kept['examples'], $incoming['examples'] ), 0, self::EXAMPLES );

		return $kept;
	}

	/**
	 * @param array<string,mixed>|null $overflow The overflow group so far.
	 * @param array<string,mixed>      $group    A group with no room of its own.
	 * @return array<string,mixed>
	 */
	private static function overflowed( ?array $overflow, array $group ): array {
		$overflow = $overflow ?? [
			'kind'      => 'skipped',
			'reason'    => self::overflow_reason(),
			'count'     => 0,
			'fix_label' => '',
			'fix_url'   => '',
			'examples'  => [],
		];

		$overflow['count'] += $group['count'];
		$overflow['kind']   = 'failed' === $group['kind'] ? 'failed' : $overflow['kind'];

		return $overflow;
	}
}
