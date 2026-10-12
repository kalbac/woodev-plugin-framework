<?php
/**
 * Contract guard: yandex v1 shipping-method IDs and the v1 -> v2.0.0 rewrite that replaces them.
 *
 * @package Woodev\Tests\Unit\Contract
 */

namespace Woodev\Tests\Unit\Contract;

use Woodev\Tests\Unit\TestCase;

/**
 * Guards the yandex shipping-method-ID data contract of the v1 -> v2.0.0 migration (framework G4, card #1241).
 *
 * Decision D10 («old names are rewritten into new»), as CDEK v2 did it. The v2 plugin is not in this repository,
 * so this guard does not exercise plugin code. It guards two things:
 *
 * 1. THE SOURCE SET. The v1 IDs and the v1 `tariff` values are read from the v1 reference source
 *    (plugins-reference/woocommerce-yandex-delivery, 1.3.7). If v1 renamed one, the migration's input would drift
 *    silently; this guard turns that drift RED.
 * 2. THE TARGET CONTRACT. The mapping below is declared, not derived. It is what the v2 migration must implement
 *    (spec §12 step 1).
 *
 * WHAT THE PLUGIN REPOSITORY MUST TEST (not covered here):
 * - Split: a v1 `yandex_delivery_other_day` row with tariff `time_interval` becomes `yandex_delivery_courier`; with
 *   `self_pickup` it becomes `yandex_delivery_pickup`; a missing or unknown tariff becomes courier and is journalled.
 * - Express: rows of `yandex_delivery_express` are deleted and listed in the journal.
 * - The v2 plugin registers exactly the two v2 IDs and no v1 ID.
 *
 * WHY source-level reads: the same reason as the other contract guards in this directory. The v1 method classes
 * extend WooCommerce's shipping-method class, which is absent in unit context; the literal assigned to `$this->id`
 * is the contract and can be read without WooCommerce coupling.
 *
 * Spec: docs-internal/specs/2026-10-12-yandex-v2-plugin-design.md §1 (D10), §11 «Method ids», §12 step 1.
 *
 * Mutation-recipes: tests/unit/Contract/recipes/yandex-shipping-method-id.recipe.json (express ID),
 *                   tests/unit/Contract/recipes/yandex-other-day-method-id.recipe.json (other-day ID).
 */
final class YandexShippingMethodIdContractTest extends TestCase {

	/**
	 * v1 method ID of the other-day method. Source: includes/class-shipping-method-other-day.php.
	 */
	private const V1_OTHER_DAY_ID = 'yandex_delivery_other_day';

	/**
	 * v1 method ID of the express stub (not registered in v1). Source: includes/class-shipping-method-express.php.
	 */
	private const V1_EXPRESS_ID = 'yandex_delivery_express';

	/**
	 * v2 method ID for the door-delivery tariff (D10).
	 */
	private const V2_COURIER_ID = 'yandex_delivery_courier';

	/**
	 * v2 method ID for the pickup-point tariff (D10).
	 */
	private const V2_PICKUP_ID = 'yandex_delivery_pickup';

	/**
	 * Declared rewrite of a v1 other-day row, keyed by its v1 `tariff` value (spec §12 step 1).
	 */
	private const TARIFF_TARGETS = [
		'time_interval' => self::V2_COURIER_ID,
		'self_pickup'   => self::V2_PICKUP_ID,
	];

	/**
	 * Target of an other-day row whose tariff is missing or unknown (spec §12 step 1, journalled).
	 */
	private const FALLBACK_TARGET = self::V2_COURIER_ID;

	/**
	 * v1 IDs whose rows the migration deletes instead of rewriting (D10).
	 */
	private const DELETED_V1_IDS = [ self::V1_EXPRESS_ID ];

	/**
	 * Skips when the v1 reference copy is absent (it is gitignored).
	 *
	 * @param string $relative Path inside the v1 reference plugin.
	 * @return string Contents of the v1 source file.
	 */
	private function reference_source( string $relative ): string {
		if ( ! is_dir( dirname( __DIR__, 3 ) . '/plugins-reference/woocommerce-yandex-delivery' ) ) {
			$this->markTestSkipped( 'plugins-reference/woocommerce-yandex-delivery is not present (gitignored); this yandex contract guard runs where the reference plugin copy exists.' );
		}

		$file = dirname( __DIR__, 3 ) . '/plugins-reference/woocommerce-yandex-delivery/' . $relative;
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
	 * The v1 other-day ID must be exactly 'yandex_delivery_other_day'.
	 *
	 * Merchants' WooCommerce shipping-zone rows persist `method_id = yandex_delivery_other_day`. This is the source set
	 * of the v1 -> v2 split (spec §12 step 1); renaming it in v1 would silently empty that set.
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
	 * The v1 express ID must be exactly 'yandex_delivery_express'.
	 *
	 * Express rows are deleted by the migration (D10). The stub is not registered in v1, but merchants may still hold
	 * zone rows keyed on this ID, so the migration must find them under this exact name.
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
	 * The v1 `tariff` values of the other-day method must be exactly the keys of the declared rewrite, and the default
	 * must be the one the rewrite falls back on.
	 *
	 * v1 reads a missing tariff as `time_interval` (`get_option( 'tariff', 'time_interval' )`), so the fallback target
	 * of the migration is the target of that default. If v1 adds or renames a tariff, this guard goes RED and the
	 * declared split must be extended before release.
	 *
	 * @return void
	 */
	public function test_v1_other_day_tariff_values_are_exactly_the_declared_rewrite_keys(): void {
		$source = $this->reference_source( 'includes/class-shipping-method-other-day.php' );

		$this->assertSame(
			1,
			preg_match( "/'tariff'\\s*=>\\s*\\[(.*?)'shipment_type'/s", $source, $tariff ),
			'The `tariff` field must exist in the other-day instance form fields.'
		);
		$this->assertSame(
			1,
			preg_match( "/'default'\\s*=>\\s*'([^']*)'/", $tariff[1], $default ),
			'The `tariff` field must declare a default.'
		);
		$this->assertSame( 'time_interval', $default[1], 'v1 default tariff must stay time_interval.' );
		$this->assertSame(
			self::FALLBACK_TARGET,
			self::TARIFF_TARGETS[ $default[1] ],
			'A missing tariff (v1 default) must be rewritten to the fallback target.'
		);

		$this->assertSame(
			1,
			preg_match( "/'options'\\s*=>\\s*\\[(.*)/s", $tariff[1], $options ),
			'The `tariff` field must declare options.'
		);
		$this->assertGreaterThan(
			0,
			preg_match_all( "/'([a-z_]+)'\\s*=>/", $options[1], $keys ),
			'The `tariff` options must list at least one value.'
		);

		$v1_tariffs = $keys[1];
		sort( $v1_tariffs );
		$declared = array_keys( self::TARIFF_TARGETS );
		sort( $declared );

		$this->assertSame(
			$declared,
			$v1_tariffs,
			'Installed-site contract: v1 tariff values must be exactly the keys of the declared time_interval/self_pickup rewrite.'
		);
	}

	/**
	 * The migration's targets must be exactly the two v2 IDs, and none of them may be a v1 ID.
	 *
	 * "Old names are rewritten into new": a row left on a v1 ID after the migration would be a method the v2 plugin
	 * does not register, so the merchant's zone would silently lose its rate. The deleted express ID must not be a
	 * target either.
	 *
	 * @return void
	 */
	public function test_declared_migration_targets_are_exactly_the_v2_ids_and_never_a_v1_id(): void {
		$targets = array_values( array_unique( array_merge( array_values( self::TARIFF_TARGETS ), [ self::FALLBACK_TARGET ] ) ) );
		sort( $targets );

		$this->assertSame(
			[ self::V2_COURIER_ID, self::V2_PICKUP_ID ],
			$targets,
			'The v1 -> v2 migration must rewrite into exactly yandex_delivery_courier and yandex_delivery_pickup.'
		);

		foreach ( $targets as $target ) {
			$this->assertNotContains(
				$target,
				array_merge( [ self::V1_OTHER_DAY_ID ], self::DELETED_V1_IDS ),
				'No migration target may be a v1 ID: ' . $target
			);
		}
	}

	/**
	 * Express is deleted by the migration, never rewritten: it must be in the deleted set and must not be a rewrite
	 * source or a rewrite target.
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
