<?php
/**
 * The icon group of a card's row, by state: a card a product carries is opened on the marketplace, opened as a product or
 * unlinked; a card no product carries is opened on the marketplace, imported or linked.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Rows;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Rows
 */
final class RemoteCardsRowActionsTest extends TestCase {

	/**
	 * @param bool $importable Whether the table offers «Импортировать».
	 * @param bool $address    Whether the adapter knows where a card is in the cabinet.
	 * @return Remote_Cards_Rows
	 */
	private function rows( bool $importable, bool $address = true ): Remote_Cards_Rows {
		return new Remote_Cards_Rows(
			static function ( int $id ): array {
				return [
					'title' => 'Товар ' . $id,
					'url'   => 'https://shop.test/edit/' . $id,
				];
			},
			null,
			$importable,
			$address ? static fn( array $ids ): string => 'https://seller.example.com/app/products/' . $ids['product_id'] : null
		);
	}

	/**
	 * @param int $product_id Product that carries the card, 0 for none.
	 * @return array<string,mixed>
	 */
	private function card( int $product_id ): array {
		return [
			'offer_id'      => 'A-1',
			'name'          => 'Кружка',
			'details_ok'    => true,
			'remote_ids'    => [ 'product_id' => 5001 ],
			'wc_product_id' => $product_id,
		];
	}

	public function test_a_card_a_product_carries_is_opened_on_the_marketplace_opened_as_a_product_or_unlinked(): void {
		$actions = $this->rows( true )->row( 1, $this->card( 41 ) )['actions'];

		$this->assertSame( [ 'open_remote', 'open_product', 'unlink' ], array_column( $actions, 'id' ) );
		$this->assertSame( 'https://seller.example.com/app/products/5001', $actions[0]['url'] );
		$this->assertSame( 'https://shop.test/edit/41', $actions[1]['url'] );
		$this->assertTrue( $actions[2]['enabled'] );
	}

	public function test_a_card_no_product_carries_is_opened_on_the_marketplace_imported_or_linked(): void {
		$actions = $this->rows( true )->row( 1, $this->card( 0 ) )['actions'];

		$this->assertSame( [ 'open_remote', 'import', 'link_to' ], array_column( $actions, 'id' ) );
		$this->assertSame( [ 'open_remote', 'link_to' ], array_column( $this->rows( false )->row( 1, $this->card( 0 ) )['actions'], 'id' ), 'A table that cannot import does not offer it.' );
	}

	public function test_without_a_cabinet_address_there_is_no_open_on_the_marketplace(): void {
		$this->assertSame( [ 'open_product', 'unlink' ], array_column( $this->rows( true, false )->row( 1, $this->card( 41 ) )['actions'], 'id' ) );
	}
}
