<?php
/**
 * Contract guard: yandex v1 shipping-method IDs and the v1 -> v2.0.0 rewrite that replaces them.
 *
 * @package Woodev\Tests\Unit\Contract
 */

namespace Woodev\Tests\Unit\Contract;

use Woodev\Tests\Unit\TestCase;

/**
 * Guards the yandex shipping-method-ID contract of the v1 -> v2.0.0 migration (G4, card #1241, decision D10).
 *
 * The v2 plugin is not in this repository, so no plugin code runs here. Two things are pinned:
 * 1. THE SOURCE SET: the v1 IDs and `tariff` values, read from the committed v1 fixture tests/_fixtures/legacy-v1/woocommerce-yandex-delivery (1.3.7).
 * 2. THE TARGET CONTRACT: the rewrite map is declared, not derived; the v2 migration must implement it (spec §12 step 1).
 *
 * The plugin repository must test: time_interval other-day rows become courier, self_pickup become pickup, a missing or
 * unknown tariff becomes courier and is journalled; express rows are deleted and journalled; the v2 plugin registers
 * exactly the two v2 IDs and no v1 ID.
 *
 * Source-level reads: the v1 method classes extend WooCommerce's shipping-method class, absent in unit context.
 *
 * Spec: docs-internal/specs/2026-10-12-yandex-v2-plugin-design.md §1 (D10), §11 «Method ids», §12 step 1.
 */
final class YandexShippingMethodIdContractTest extends TestCase {

	/** v1 other-day method ID. Source: includes/class-shipping-method-other-day.php. */
	private const V1_OTHER_DAY_ID = 'yandex_delivery_other_day';

	/** v1 express stub ID (not registered in v1). Source: includes/class-shipping-method-express.php. */
	private const V1_EXPRESS_ID = 'yandex_delivery_express';

	/** v2 door-delivery method ID (D10). */
	private const V2_COURIER_ID = 'yandex_delivery_courier';

	/** v2 pickup-point method ID (D10). */
	private const V2_PICKUP_ID = 'yandex_delivery_pickup';

	/** Declared rewrite of a v1 other-day row, keyed by its v1 `tariff` value (spec §12 step 1). */
	private const TARIFF_TARGETS = [
		'time_interval' => self::V2_COURIER_ID,
		'self_pickup'   => self::V2_PICKUP_ID,
	];

	/** Target of an other-day row with a missing or unknown tariff (spec §12 step 1, journalled). */
	private const FALLBACK_TARGET = self::V2_COURIER_ID;

	/** v1 IDs whose rows the migration deletes instead of rewriting (D10). */
	private const DELETED_V1_IDS = [ self::V1_EXPRESS_ID ];

	/**
	 * Reads a file of the frozen v1 plugin source committed under tests/_fixtures/legacy-v1.
	 *
	 * @param string $relative Path inside the v1 reference plugin.
	 * @return string Contents of the v1 source file.
	 */
	private function reference_source( string $relative ): string {
		$file = dirname( __DIR__, 3 ) . '/tests/_fixtures/legacy-v1/woocommerce-yandex-delivery/' . $relative;
		$this->assertFileExists( $file, 'Canonical v1 source must exist: ' . $relative );

		return (string) file_get_contents( $file );
	}

	/**
	 * Extracts the single `$this->id = '...'` literal declared in a v1 method source file.
	 *
	 * @param string $relative Path inside the v1 reference plugin.
	 * @return string
	 */
	private function declared_method_id( string $relative ): string {
		$source = $this->reference_source( $relative );

		$this->assertSame(
			1,
			preg_match( "/\\\$this->id\\s*=\\s*'([^']*)'/", $source, $matches ),
			'A $this->id string assignment must exist in the canonical source: ' . $relative
		);

		return $matches[1];
	}

	/**
	 * The v1 other-day ID is persisted in merchants' zone rows and is the source set of the split.
	 *
	 * @return void
	 */
	public function test_v1_other_day_method_id_is_exactly_yandex_delivery_other_day(): void {
		$this->assertSame(
			self::V1_OTHER_DAY_ID,
			$this->declared_method_id( 'includes/class-shipping-method-other-day.php' ),
			'Installed-site contract: v1 other-day shipping method ID must be exactly "yandex_delivery_other_day".'
		);
	}

	/**
	 * The migration finds and deletes express zone rows by this exact ID.
	 *
	 * @return void
	 */
	public function test_v1_express_method_id_is_exactly_yandex_delivery_express(): void {
		$this->assertSame(
			self::V1_EXPRESS_ID,
			$this->declared_method_id( 'includes/class-shipping-method-express.php' ),
			'Installed-site contract: v1 express shipping method ID must be exactly "yandex_delivery_express".'
		);
	}

	/**
	 * The v1 `tariff` values must be exactly the declared rewrite keys; the v1 default must be the one the fallback
	 * is derived from. A new or renamed v1 tariff turns this RED, and the declared split must be extended before release.
	 *
	 * @return void
	 */
	public function test_v1_other_day_tariff_values_are_exactly_the_declared_rewrite_keys(): void {
		$source = $this->reference_source( 'includes/class-shipping-method-other-day.php' );

		$this->assertSame( 1, preg_match( "/'tariff'\\s*=>\\s*\\[(.*?)'shipment_type'/s", $source, $tariff ), 'The `tariff` field must exist.' );
		$this->assertSame( 1, preg_match( "/'default'\\s*=>\\s*'([^']*)'/", $tariff[1], $default ), 'The `tariff` field must declare a default.' );
		$this->assertSame( 'time_interval', $default[1], 'v1 default tariff must stay time_interval.' );
		$this->assertSame( self::FALLBACK_TARGET, self::TARIFF_TARGETS[ $default[1] ], 'A missing tariff must be rewritten to the fallback target.' );

		$this->assertSame( 1, preg_match( "/'options'\\s*=>\\s*\\[(.*)/s", $tariff[1], $options ), 'The `tariff` field must declare options.' );
		$this->assertGreaterThan( 0, preg_match_all( "/'([a-z_]+)'\\s*=>/", $options[1], $keys ), 'The `tariff` options must list at least one value.' );

		$v1_tariffs = $keys[1];
		sort( $v1_tariffs );
		$declared = array_keys( self::TARIFF_TARGETS );
		sort( $declared );

		$this->assertSame( $declared, $v1_tariffs, 'Installed-site contract: v1 tariff values must be exactly the declared rewrite keys.' );
	}

	/**
	 * The map must be exactly time_interval -> courier and self_pickup -> pickup, and the fallback exactly courier.
	 * A target-set check alone passes a coordinated swap (tariffs exchanged, fallback moved with them).
	 *
	 * @return void
	 */
	public function test_rewrite_map_is_exactly_time_interval_to_courier_and_self_pickup_to_pickup(): void {
		$this->assertSame(
			[ 'time_interval' => self::V2_COURIER_ID, 'self_pickup' => self::V2_PICKUP_ID ],
			self::TARIFF_TARGETS,
			'Migration contract (D10): time_interval -> yandex_delivery_courier, self_pickup -> yandex_delivery_pickup.'
		);
		$this->assertSame(
			self::V2_COURIER_ID,
			self::FALLBACK_TARGET,
			'Migration contract (D10): a missing or unknown tariff falls back to yandex_delivery_courier.'
		);
	}

	/**
	 * Targets are exactly the two v2 IDs and none is a v1 ID: a row left on a v1 ID would lose its rate silently.
	 *
	 * @return void
	 */
	public function test_declared_migration_targets_are_exactly_the_v2_ids_and_never_a_v1_id(): void {
		$targets = array_values( array_unique( array_merge( array_values( self::TARIFF_TARGETS ), [ self::FALLBACK_TARGET ] ) ) );
		sort( $targets );

		$this->assertSame( [ self::V2_COURIER_ID, self::V2_PICKUP_ID ], $targets, 'Migration must rewrite into exactly the two v2 IDs.' );

		foreach ( $targets as $target ) {
			$this->assertNotContains( $target, array_merge( [ self::V1_OTHER_DAY_ID ], self::DELETED_V1_IDS ), 'No target may be a v1 ID: ' . $target );
		}
	}

	/**
	 * Express is deleted, never rewritten: it is neither a rewrite source nor a target.
	 *
	 * @return void
	 */
	public function test_v1_express_is_deleted_and_is_not_a_rewrite_source_or_target(): void {
		$this->assertSame(
			[ self::V1_EXPRESS_ID ],
			self::DELETED_V1_IDS,
			'Installed-site contract: the only deleted v1 ID is yandex_delivery_express (D10).'
		);
		$this->assertNotContains( self::V1_EXPRESS_ID, array_values( self::TARIFF_TARGETS ) );
	}
}
