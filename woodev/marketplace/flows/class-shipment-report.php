<?php
/**
 * What an action did to the units of a shipment.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * The units an action added or moved, those the marketplace refused or did not answer for, and those that were never sent, each
 * with the sentence for the merchant. An example names one unit as the merchant knows it: «№ 123: Футболка, M».
 *
 * @since 2.1.28
 */
final class Shipment_Report {
	/**
	 * @var string
	 */
	private $shipment_key;

	/**
	 * @var int
	 */
	private $done = 0;

	/**
	 * @var array<int,array{reason:string,example:string}>
	 */
	private $failed = [];

	/**
	 * @var array<int,array{reason:string,example:string}>
	 */
	private $skipped = [];

	/**
	 * @since 2.1.28
	 *
	 * @param string $shipment_key Key of the shipment the units went into; empty when none was created.
	 */
	public function __construct( string $shipment_key = '' ) {
		$this->shipment_key = $shipment_key;
	}

	/**
	 * @since 2.1.28
	 *
	 * @param string $key Key of the shipment the units went into.
	 * @return void
	 */
	public function into( string $key ): void {
		$this->shipment_key = $key;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return void
	 */
	public function add_done(): void {
		++$this->done;
	}

	/**
	 * @since 2.1.28
	 *
	 * @param string $reason  Sentence.
	 * @param string $example The unit.
	 * @return void
	 */
	public function add_failed( string $reason, string $example ): void {
		$this->failed[] = compact( 'reason', 'example' );
	}

	/**
	 * @since 2.1.28
	 *
	 * @param string $reason  Sentence.
	 * @param string $example The unit.
	 * @return void
	 */
	public function add_skipped( string $reason, string $example ): void {
		$this->skipped[] = compact( 'reason', 'example' );
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Empty when no shipment took part.
	 */
	public function shipment_key(): string {
		return $this->shipment_key;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return int Units added or moved.
	 */
	public function done(): int {
		return $this->done;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return array<int,array{reason:string,example:string}>
	 */
	public function failed(): array {
		return $this->failed;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return array<int,array{reason:string,example:string}>
	 */
	public function skipped(): array {
		return $this->skipped;
	}
}
