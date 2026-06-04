<?php
/**
 * Guard test: the platform-neutral base must declare no WooCommerce-named method.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

/**
 * Class PlatformNeutralBaseHasNoWcMethodTest.
 *
 * Enforces audit §6.1.1 / the P6 neutrality gate: {@see \Woodev_Plugin}
 * is the platform-neutral base, so it must not expose any method whose
 * name references WooCommerce. WooCommerce hook wiring belongs on
 * {@see \Woodev\Framework\Woocommerce_Plugin}, not on the base.
 */
class PlatformNeutralBaseHasNoWcMethodTest extends TestCase {

	/**
	 * No method declared on the platform-neutral base may be WooCommerce-named.
	 *
	 * @return void
	 */
	public function test_base_declares_no_woocommerce_named_method(): void {
		$reflection = new \ReflectionClass( \Woodev_Plugin::class );

		$forbidden_terms = [
			'woocommerce',
			'hpos',
		];

		foreach ( $reflection->getMethods() as $method ) {

			// Only assert on methods actually declared on the base, regardless of visibility.
			if ( \Woodev_Plugin::class !== $method->getDeclaringClass()->getName() ) {
				continue;
			}

			foreach ( $forbidden_terms as $term ) {
				$this->assertStringNotContainsStringIgnoringCase(
					$term,
					$method->getName(),
					'Woodev_Plugin (platform-neutral base) must declare no WooCommerce/HPOS method'
				);
			}
		}
	}
}
