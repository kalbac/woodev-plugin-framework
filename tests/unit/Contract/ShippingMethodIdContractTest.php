<?php
/**
 * Contract guard: the edostavka shipping method ID is exactly 'edostavka'.
 *
 * @package Woodev\Tests\Unit\Contract
 */

namespace Woodev\Tests\Unit\Contract;

use Woodev\Tests\Unit\TestCase;

/**
 * Guards the installed-site shipping-method-ID data contract.
 *
 * WHY a source-level guard (not a runtime read): the contract IS the literal string
 * declared as a class constant. Reading that literal from its canonical source file
 * verifies the contract directly, with zero WooCommerce/WordPress runtime coupling --
 * the real Shipping_Method extends \WC_Shipping_Method, which is absent in unit
 * context, so loading the class would require WC stubs and make this permanent CI
 * guard fragile. The guard instead extracts whatever value the source declares and
 * asserts it equals the contract, so ANY change to the constant flips this test RED.
 * tools/autodev/mutation-check.ps1 proves that via the paired mutation-recipe.
 *
 * Mutation-recipe: tests/unit/Contract/recipes/shipping-method-id-edostavka.recipe.json
 */
final class ShippingMethodIdContractTest extends TestCase {

	/**
	 * Canonical source declaring the shipping method ID constant.
	 *
	 * @return string
	 */
	private static function source_file(): string {
		return dirname( __DIR__, 2 )
			. '/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-shipping-method.php';
	}

	/**
	 * The METHOD_ID constant must be exactly 'edostavka'.
	 *
	 * Merchants' WooCommerce shipping-zone rows persist `method_id = edostavka`;
	 * renaming it orphans every configured shipping zone on a live site. This is a
	 * release-blocking installed-site data contract (clean-break policy "never break"
	 * list; edostavka data-preservation checklist).
	 *
	 * @return void
	 */
	public function test_shipping_method_id_is_exactly_edostavka(): void {
		$file = self::source_file();
		$this->assertFileExists( $file, 'Canonical shipping-method source must exist.' );

		$source = (string) file_get_contents( $file );

		$this->assertSame(
			1,
			preg_match( "/const\\s+METHOD_ID\\s*=\\s*'([^']*)'/", $source, $matches ),
			'A METHOD_ID string-constant declaration must exist in the canonical source.'
		);
		$this->assertSame(
			'edostavka',
			$matches[1],
			'Installed-site contract: shipping method ID must be exactly "edostavka".'
		);
	}
}
