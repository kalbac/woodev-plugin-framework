<?php
/**
 * Validation outcome of a selection of products.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the {@see Validation_Report} of each selected product and groups the blocked ones by the first check that failed
 * (spec 5.1.1): «12 товаров: нет веса; 3 товара: не сопоставлена категория». The groups are what the validation modal shows;
 * the per-product reports stay available for the detail view.
 *
 * Group shape: `{ code, summary, count, product_ids[], fix }`, ordered by size (largest first), ties by first appearance.
 *
 * @since 2.0.29
 */
final class Bulk_Validation_Report {
	/**
	 * Reports keyed by product id, in the order they were added.
	 *
	 * @var array<int,Validation_Report>
	 */
	private $reports = [];

	/**
	 * Adds the report of one product.
	 *
	 * @since 2.0.29
	 *
	 * @param int               $product_id Product or variation id.
	 * @param Validation_Report $report     Its report.
	 * @return void
	 */
	public function add( int $product_id, Validation_Report $report ): void {
		$this->reports[ $product_id ] = $report;
	}

	/**
	 * Returns the report of one product.
	 *
	 * @since 2.0.29
	 *
	 * @param int $product_id Product or variation id.
	 * @return Validation_Report|null Null when the product was not validated.
	 */
	public function report_for( int $product_id ): ?Validation_Report {
		return $this->reports[ $product_id ] ?? null;
	}

	/**
	 * Returns the ids of the products with no error: they go to the queue.
	 *
	 * @since 2.0.29
	 *
	 * @return int[]
	 */
	public function valid_ids(): array {
		return $this->ids_where( true );
	}

	/**
	 * Returns the ids of the products with an error: they stay out of the queue.
	 *
	 * @since 2.0.29
	 *
	 * @return int[]
	 */
	public function blocked_ids(): array {
		return $this->ids_where( false );
	}

	/**
	 * Returns the ids of the products that may be exported but carry a warning.
	 *
	 * @since 2.0.29
	 *
	 * @return int[]
	 */
	public function warned_ids(): array {
		$ids = [];

		foreach ( $this->reports as $product_id => $report ) {
			if ( $report->is_valid() && [] !== $report->warnings() ) {
				$ids[] = $product_id;
			}
		}

		return $ids;
	}

	/**
	 * Groups the blocked products by their first error.
	 *
	 * @since 2.0.29
	 *
	 * @return array<int,array{code:string,summary:string,count:int,product_ids:int[],fix:?string}>
	 */
	public function groups(): array {
		$groups = [];

		foreach ( $this->reports as $product_id => $report ) {
			$error = $report->first_error();

			if ( null === $error ) {
				continue;
			}

			$key = $error['code'] . '|' . $error['summary'];

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
					'code'        => $error['code'],
					'summary'     => $error['summary'],
					'count'       => 0,
					'product_ids' => [],
					'fix'         => $error['fix'],
				];
			}

			++$groups[ $key ]['count'];
			$groups[ $key ]['product_ids'][] = $product_id;
		}

		$groups = array_values( $groups );

		usort(
			$groups,
			static function ( array $left, array $right ): int {
				return $right['count'] <=> $left['count'];
			}
		);

		return $groups;
	}

	/**
	 * Returns the one-line summary of the blocked products.
	 *
	 * @since 2.0.29
	 *
	 * @return string Empty when nothing is blocked.
	 */
	public function summary(): string {
		$parts = [];

		foreach ( $this->groups() as $group ) {
			$parts[] = $group['count'] . ' ' . self::products( $group['count'] ) . ': ' . $group['summary'];
		}

		return implode( '; ', $parts );
	}

	/**
	 * Returns the Russian word «товар» in the form a number needs.
	 *
	 * @since 2.0.29
	 *
	 * @param int $count Number.
	 * @return string
	 */
	public static function products( int $count ): string {
		$last_two = $count % 100;
		$last     = $count % 10;

		if ( $last_two >= 11 && $last_two <= 14 ) {
			return 'товаров';
		}

		if ( 1 === $last ) {
			return 'товар';
		}

		return $last >= 2 && $last <= 4 ? 'товара' : 'товаров';
	}

	/**
	 * Lists the ids whose report is valid or not.
	 *
	 * @param bool $valid True for the valid ones.
	 * @return int[]
	 */
	private function ids_where( bool $valid ): array {
		$ids = [];

		foreach ( $this->reports as $product_id => $report ) {
			if ( $valid === $report->is_valid() ) {
				$ids[] = $product_id;
			}
		}

		return $ids;
	}
}
