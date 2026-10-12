<?php
/**
 * The User-Agent product token is always `woodev-<plugin id>` and stays a valid ASCII HTTP token (s160, s175).
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
	 * @dataProvider provide_ids
	 *
	 * @param string $id       Dasherized plugin id.
	 * @param string $expected Product token.
	 */
	public function test_the_product_token_is_the_woodev_prefixed_plugin_id( string $id, string $expected ): void {
		$method = new \ReflectionMethod( \Woodev_API_Base::class, 'get_user_agent_product' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame( $expected, $method->invoke( null, $id ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function provide_ids(): array {
		return [
			'plain id gets the prefix'           => [ 'edostavka', 'woodev-edostavka' ],
			'dasherized id gets the prefix'      => [ 'yandex-delivery', 'woodev-yandex-delivery' ],
			'id with woodev is used as is'       => [ 'woodev-test-plugin', 'woodev-test-plugin' ],
			'woodev match is case-insensitive'   => [ 'my-WooDev-add-on', 'my-WooDev-add-on' ],
			'empty id keeps the fallback'        => [ '', 'woodev-plugin' ],
			'id that is not a token falls back'  => [ 'a/b', 'woodev-plugin' ],
		];
	}
}
