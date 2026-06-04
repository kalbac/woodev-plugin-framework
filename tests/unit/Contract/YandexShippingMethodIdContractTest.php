<?php
/**
 * Contract guard: the yandex shipping method IDs are exactly
 * 'yandex_delivery_express' and 'yandex_delivery_other_day'.
 *
 * @package Woodev\Tests\Unit\Contract
 */

namespace Woodev\Tests\Unit\Contract;

use Woodev\Tests\Unit\TestCase;

/**
 * Guards the installed-site yandex shipping-method-ID data contract (second pilot).
 *
 * WHY a source-level guard (not a runtime read): the contract IS the literal string
 * assigned to $this->id in each shipping-method class. Reading that literal from its
 * canonical source verifies the contract directly, with zero WooCommerce/WordPress
 * runtime coupling -- the real method classes extend \WC_Shipping_Method, absent in unit
 * context. The guard extracts whatever value the source declares and asserts it equals
 * the contract, so ANY change to either ID flips this test RED. mutation-check.ps1 proves
 * that via the paired mutation-recipe (the express ID is the demonstrated flip; the
 * other-day assertion below guards the second ID by the same mechanism).
 *
 * Mutation-recipe: tests/unit/Contract/recipes/yandex-shipping-method-id.recipe.json
 */
final class YandexShippingMethodIdContractTest extends TestCase {

	/**
	 * Canonical read-only reference source declaring a shipping-method ID.
	 *
	 * @param string $class_file Basename of the method class file.
	 * @return string
	 */
	private static function source_file( string $class_file ): string {
		return dirname( __DIR__, 3 )
			. '/plugins-reference/woocommerce-yandex-delivery/includes/' . $class_file;
	}

	/**
	 * Extracts the single `$this->id = '...'` literal declared in a source file.
	 *
	 * @param string $class_file Basename of the method class file.
	 * @return string
	 */
	private function declared_method_id( string $class_file ): string {
		$file = self::source_file( $class_file );
		$this->assertFileExists( $file, 'Canonical shipping-method source must exist: ' . $class_file );

		$source = (string) file_get_contents( $file );

		$this->assertSame(
			1,
			preg_match( "/\\\$this->id\\s*=\\s*'([^']*)'/", $source, $matches ),
			'A $this->id string assignment must exist in the canonical source: ' . $class_file
		);

		return $matches[1];
	}

	/**
	 * Express method ID must be exactly 'yandex_delivery_express'.
	 *
	 * Merchants' WooCommerce shipping-zone rows persist `method_id =
	 * yandex_delivery_express`; renaming it orphans every configured express zone on a
	 * live site. Release-blocking installed-site data contract (clean-break policy
	 * "never break" list; yandex data-preservation checklist).
	 *
	 * @return void
	 */
	public function test_express_method_id_is_exactly_yandex_delivery_express(): void {
		$this->assertSame(
			'yandex_delivery_express',
			$this->declared_method_id( 'class-shipping-method-express.php' ),
			'Installed-site contract: express shipping method ID must be exactly "yandex_delivery_express".'
		);
	}

	/**
	 * Other-day method ID must be exactly 'yandex_delivery_other_day'.
	 *
	 * Merchants' WooCommerce shipping-zone rows persist `method_id =
	 * yandex_delivery_other_day`; renaming it orphans every configured other-day zone.
	 * Release-blocking installed-site data contract (yandex data-preservation checklist).
	 *
	 * @return void
	 */
	public function test_other_day_method_id_is_exactly_yandex_delivery_other_day(): void {
		$this->assertSame(
			'yandex_delivery_other_day',
			$this->declared_method_id( 'class-shipping-method-other-day.php' ),
			'Installed-site contract: other-day shipping method ID must be exactly "yandex_delivery_other_day".'
		);
	}
}
