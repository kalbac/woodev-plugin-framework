<?php
namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Shared\Plain_Price;
use Woodev\Tests\Unit\TestCase;

/**
 * An amount as plain text: the one way the orders table and the marketplace cards table write money.
 *
 * @covers \Woodev\Framework\Marketplace\Admin\Shared\Plain_Price
 */
final class PlainPriceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_price' )->alias(
			static function ( $amount, array $args = [] ): string {
				$symbol = 'USD' === ( $args['currency'] ?? '' ) ? '&#36;' : '&#8381;';

				return '<span class="woocommerce-Price-amount amount"><bdi>' . number_format( (float) $amount, 2, ',', '&nbsp;' ) . '&nbsp;<span class="woocommerce-Price-currencySymbol">' . $symbol . '</span></bdi></span>';
			}
		);
	}

	public function test_the_markup_goes_and_the_entities_become_characters(): void {
		$this->assertSame( "2\u{a0}120,00\u{a0}₽", Plain_Price::format( '2120' ) );
		$this->assertSame( "2\u{a0}120,00\u{a0}₽", Plain_Price::format( 2120.0, '' ) );
	}

	public function test_a_currency_is_handed_to_the_shop_s_formatter(): void {
		$this->assertSame( "10,00\u{a0}$", Plain_Price::format( 10, 'USD' ) );
	}
}
