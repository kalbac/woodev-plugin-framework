<?php
/**
 * What a seller action did, unit by unit.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Every unit asked for is in exactly one list: done, failed (the marketplace refused it or did not answer for it) or skipped
 * (the screens do not offer the action on it, so it was never sent).
 *
 * @since 2.1.27
 */
final class Seller_Action_Report {
	/**
	 * @var Seller_Unit_Result[]
	 */
	private $results;

	/**
	 * @var array<int,array{order:\WC_Order,unit:string,reason:string}>
	 */
	private $skipped;

	/**
	 * @since 2.1.27
	 *
	 * @param Seller_Unit_Result[]                                        $results One answer per unit sent.
	 * @param array<int,array{order:\WC_Order,unit:string,reason:string}> $skipped Units never sent.
	 */
	public function __construct( array $results, array $skipped ) {
		$this->results = array_values( $results );
		$this->skipped = array_values( $skipped );
	}

	/**
	 * @since 2.1.27
	 *
	 * @return Seller_Unit_Result[] Units the marketplace accepted.
	 */
	public function done(): array {
		return array_values(
			array_filter(
				$this->results,
				static function ( Seller_Unit_Result $result ): bool {
					return $result->is_done();
				}
			)
		);
	}

	/**
	 * @since 2.1.27
	 *
	 * @return Seller_Unit_Result[] Units refused or unanswered, each with its sentence.
	 */
	public function failed(): array {
		return array_values(
			array_filter(
				$this->results,
				static function ( Seller_Unit_Result $result ): bool {
					return ! $result->is_done();
				}
			)
		);
	}

	/**
	 * @since 2.1.27
	 *
	 * @return array<int,array{order:\WC_Order,unit:string,reason:string}> Units never sent, with the reason.
	 */
	public function skipped(): array {
		return $this->skipped;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return int Units asked for.
	 */
	public function requested(): int {
		return count( $this->results ) + count( $this->skipped );
	}
}
