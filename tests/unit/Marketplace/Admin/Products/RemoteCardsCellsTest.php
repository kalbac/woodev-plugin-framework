<?php
namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Cells;
use Woodev\Tests\Unit\TestCase;

/**
 * The cells of a card that come straight from its fields: a missing field is a dash or nothing, never a broken page.
 */
class RemoteCardsCellsTest extends TestCase {

	public function test_the_name_is_a_link_to_the_cabinet_when_the_adapter_knows_the_address(): void {
		$seen  = null;
		$cells = new Remote_Cards_Cells(
			static function ( array $ids ) use ( &$seen ): string {
				$seen = $ids;

				return 'https://seller.example.com/app/products/' . $ids['product_id'] . '/edit';
			}
		);

		$name = $cells->name( [ 'name' => 'Кружка', 'remote_ids' => [ 'product_id' => 5001 ] ] );

		$this->assertSame( [ 'product_id' => 5001 ], $seen );
		$this->assertSame( [ [ 'type' => 'link', 'value' => [ 'label' => 'Кружка', 'url' => 'https://seller.example.com/app/products/5001/edit' ] ] ], $name );
	}

	public function test_without_an_address_the_name_is_text_and_a_card_with_no_name_has_no_cell(): void {
		$cells = new Remote_Cards_Cells();

		$this->assertSame( [ [ 'type' => 'text', 'value' => 'Кружка' ] ], $cells->name( [ 'name' => 'Кружка' ] ) );
		$this->assertNull( $cells->name( [ 'name' => '  ' ] ) );
		$this->assertSame( [ [ 'type' => 'text', 'value' => 'Кружка' ] ], ( new Remote_Cards_Cells( static fn( array $ids ): string => '' ) )->name( [ 'name' => 'Кружка' ] ), 'The adapter has no address for a card without numbers yet.' );
	}

	public function test_a_stray_address_costs_the_card_its_link_and_its_photo_not_the_list(): void {
		Functions\when( 'wc_placeholder_img_src' )->justReturn( 'https://example.test/placeholder.png' );
		$cells       = new Remote_Cards_Cells( static fn( array $ids ): string => 'javascript:alert(1)' );
		$placeholder = [ [ 'type' => 'image', 'value' => [ 'url' => 'https://example.test/placeholder.png', 'alt' => 'Кружка' ] ] ];

		$this->assertSame( 'text', $cells->name( [ 'name' => 'Кружка' ] )[0]['type'] );
		$this->assertSame( $placeholder, $cells->image( [ 'name' => 'Кружка', 'image_url' => 'ftp://example.test/a.jpg' ] ) );
		$this->assertSame( $placeholder, $cells->image( [ 'name' => 'Кружка', 'image_url' => 'https://example.test/with space.jpg' ] ) );
	}

	public function test_the_photo_is_a_picture_item_described_by_the_name_and_a_card_without_one_shows_the_placeholder(): void {
		Functions\when( 'wc_placeholder_img_src' )->justReturn( 'https://example.test/placeholder.png' );
		$cells = new Remote_Cards_Cells();

		$this->assertSame( [ [ 'type' => 'image', 'value' => [ 'url' => 'https://example.test/a.jpg', 'alt' => 'Кружка' ] ] ], $cells->image( [ 'name' => 'Кружка', 'image_url' => ' https://example.test/a.jpg ' ] ) );
		$this->assertSame( [ [ 'type' => 'image', 'value' => [ 'url' => 'https://example.test/placeholder.png', 'alt' => 'Кружка' ] ] ], $cells->image( [ 'name' => 'Кружка' ] ), 'No empty cell: WooCommerce\'s placeholder stands in.' );
	}

	public function test_the_card_address_is_the_one_the_adapter_gives_and_empty_without_it(): void {
		$cards = new Remote_Cards_Cells( static fn( array $ids ): string => 'https://seller.example.com/app/products/' . $ids['product_id'] );

		$this->assertSame( 'https://seller.example.com/app/products/5001', $cards->card_address( [ 'remote_ids' => [ 'product_id' => 5001 ] ] ) );
		$this->assertSame( '', ( new Remote_Cards_Cells() )->card_address( [ 'remote_ids' => [ 'product_id' => 5001 ] ] ) );
	}

	public function test_price_and_stock_are_a_dash_until_the_store_provides_them(): void {
		$cells = new Remote_Cards_Cells();

		$this->assertSame( [ '—', '—' ], [ $cells->price( [] ), $cells->stock( [] ) ] );
		$this->assertSame( [ '—', '—' ], [ $cells->price( [ 'price' => '' ] ), $cells->stock( [ 'stock' => null ] ) ] );
		$this->assertSame( '0', $cells->stock( [ 'stock' => 0 ] ), 'Zero stock is a number, not a missing value.' );
	}

	public function test_a_price_is_money_in_the_currency_of_the_cabinet_like_the_orders_total(): void {
		$seen = [];
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_price' )->alias(
			static function ( $amount, array $args = [] ) use ( &$seen ): string {
				$seen = [ $amount, $args ];

				return '<span class="amount"><bdi>' . number_format( (float) $amount, 2, ',', "\u{a0}" ) . '&nbsp;<span>&#8381;</span></bdi></span>';
			}
		);

		$price = ( new Remote_Cards_Cells() )->price( [ 'price' => '154866.00', 'currency' => 'RUB' ] );

		$this->assertSame( "154\u{a0}866,00\u{a0}₽", $price, 'Digits grouped, kopecks and the symbol: the way the orders table says an amount, not the bare «154866».' );
		$this->assertSame( [ '154866.00', [ 'currency' => 'RUB' ] ], $seen );
	}

	public function test_a_card_without_a_currency_is_priced_in_the_currency_of_the_shop(): void {
		$seen = null;
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_price' )->alias(
			static function ( $amount, array $args = [] ) use ( &$seen ): string {
				$seen = $args;

				return $amount . ' ₽';
			}
		);

		$this->assertSame( '1250.50 ₽', ( new Remote_Cards_Cells() )->price( [ 'price' => '1250.50', 'currency' => '  ' ] ) );
		$this->assertSame( [], $seen, 'No currency key at all: WooCommerce\'s own currency applies.' );
	}

	public function test_the_identifiers_have_the_merchants_names_and_an_unknown_one_keeps_its_own(): void {
		$lines = ( new Remote_Cards_Cells() )->id_lines( [ 'product_id' => 5001, 'sku' => 7001, 'model_id' => 9, 'nmID' => 4 ] );

		$this->assertSame( [ 'ID товара: 5001', 'SKU: 7001', 'Модель: 9', 'nmID: 4' ], $lines );
	}

	public function test_the_identifier_lines_skip_empty_numbers_and_keep_five(): void {
		$lines = ( new Remote_Cards_Cells() )->id_lines( [ 'product_id' => 5001, 'sku' => 0, 'model_id' => '', 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5 ] );

		$this->assertSame( [ 'ID товара: 5001', 'a: 1', 'b: 2', 'c: 3', 'd: 4' ], $lines );
	}
}
