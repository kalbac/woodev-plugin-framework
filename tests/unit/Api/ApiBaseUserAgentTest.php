<?php
/**
 * The User-Agent product token stays a valid ASCII HTTP token (s160).
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Woodev_API_Base::get_user_agent_product
 */
class ApiBaseUserAgentTest extends TestCase {

	/**
	 * @dataProvider provide_names
	 *
	 * @param string $name     Plugin name.
	 * @param string $id       Dasherized plugin id.
	 * @param string $expected Product token.
	 */
	public function test_the_product_token_is_ascii( string $name, string $id, string $expected ): void {
		$method = new \ReflectionMethod( \Woodev_API_Base::class, 'get_user_agent_product' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame( $expected, $method->invoke( null, $name, $id ) );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public function provide_names(): array {
		return [
			'latin name keeps its shape'    => [ 'CDEK WooCommerce Shipping Method', 'edostavka', 'CDEK-WooCommerce-Shipping-Method' ],
			'cyrillic name falls back to id' => [ 'СДЭК для WooCommerce', 'edostavka', 'edostavka' ],
			'name with a slash falls back'   => [ 'A/B plugin', 'a-b', 'a-b' ],
			'empty name falls back'          => [ '', 'some-plugin', 'some-plugin' ],
			'nothing at all'                 => [ '', '', 'woodev-plugin' ],
		];
	}
}
