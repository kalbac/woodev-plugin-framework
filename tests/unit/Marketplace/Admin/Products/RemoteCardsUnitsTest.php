<?php
/**
 * The «Карточки на маркетплейсе» table of a marketplace that sells a size inside a card: the sizes are listed under their card.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Ids;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Framework\Marketplace\Flows\Article_Display;
use Woodev\Framework\Marketplace\Flows\Unit_Matcher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Sellable_Units;

require_once dirname( __DIR__, 3 ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php';
require_once dirname( __DIR__ ) . '/Mappings/wp-term-stub.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Units
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Provider
 * @covers \Woodev\Framework\Marketplace\Data\Wpdb_Card_Store
 */
final class RemoteCardsUnitsTest extends Remote_Cards_Test_Case {
	protected function setUp(): void {
		parent::setUp();

		$this->sells_units = true;
		$this->importable  = true;

		Functions\when( 'get_the_title' )->justReturn( 'Пальто' );
		Functions\when( 'wc_price' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$this->put_cards(
			[
				$this->unit( 'coat', 'M', 2, [ 'price' => 100, 'stock' => 2 ] ),
				$this->unit( 'mug', '', 1 ),
				$this->unit( 'coat', 'L', 2, [ 'price' => 150, 'stock' => 3 ] ),
			]
		);
	}

	/**
	 * A list row of a unit.
	 *
	 * @param string              $article Article.
	 * @param string              $size    Size.
	 * @param int                 $sizes   Sizes of the card.
	 * @param array<string,mixed> $more    Keys to override.
	 * @return array<string,mixed>
	 */
	private function unit( string $article, string $size, int $sizes, array $more = [] ): array {
		$unit = Fake_Sellable_Units::card( $article, $size, [ '12345Ejf5', $article . $size ], $sizes );

		return $this->card( $unit['offer_id'], ucfirst( $article ), array_merge( $unit, $more ) );
	}

	/**
	 * The list body, passed through the engine's normaliser.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<string,mixed>
	 */
	private function list_body( array $filters = [] ): array {
		$result = $this->cards_provider()->query(
			$this->context(),
			[
				'page'     => 1,
				'per_page' => 20,
				'search'   => '',
				'filters'  => $filters,
			]
		);
		$body   = Row_Normalizer::page( $this->cards_definition(), $result, 20 );

		$this->assertIsArray( $body, 'The engine accepts the list.' );

		return $body;
	}

	/**
	 * Row id of the parent row of a card.
	 *
	 * @param string $card Card identifier.
	 * @return string
	 */
	private function parent_id( string $card ): string {
		return Remote_Cards_Ids::card_row_id( $this->account_id, Wpdb_Card_Store::key( $card ) );
	}

	public function test_a_card_with_several_sizes_is_one_row_that_sums_them(): void {
		$body = $this->list_body();
		$rows = array_column( $body['rows'], null, 'id' );

		$this->assertSame( 2, $body['total'], 'two cards, three sizes' );
		$this->assertSame( [ $this->parent_id( 'coat' ), $this->card_row_id( 'mug|' ) ], array_keys( $rows ), 'in the marketplace order' );

		$coat = $rows[ $this->parent_id( 'coat' ) ];

		$this->assertTrue( $coat['has_children'] );
		$this->assertTrue( $coat['selectable'] );
		$this->assertSame( 'coat', $coat['cells']['offer_id'] );
		$this->assertSame( '5', $coat['cells']['stock'] );
		$this->assertStringContainsString( '100', $coat['cells']['price'] );
		$this->assertStringContainsString( '150', $coat['cells']['price'] );
		$this->assertSame( '0 из 2', $coat['cells']['on_site'][0]['value'] );
		$this->assertSame( 'Создан · 2', $coat['cells']['state'][0]['value']['label'] );
		$this->assertSame( [ 'import' ], array_column( $coat['actions'], 'id' ) );

		$mug = $rows[ $this->card_row_id( 'mug|' ) ];

		$this->assertFalse( $mug['has_children'], 'a card with one size is an ordinary row' );
		$this->assertSame( 'mug', $mug['cells']['offer_id'], 'the unit key is never shown' );
		$this->assertStringContainsString( 'В списке 2 карточка', str_replace( 'карточек: 2', '2 карточка', $body['messages']['summary'] ) );
	}

	public function test_the_sizes_are_the_children_of_their_card(): void {
		$definition = $this->cards_definition()->to_array();

		$this->assertNotNull( $definition['children'] );
		$this->assertSame( 'Показать варианты', $definition['messages']['expand'] );

		$provider = $this->cards_provider();
		$parent   = $this->parent_id( 'coat' );

		$this->assertTrue( $provider->authorize( 'children', $this->context(), [ $parent ] ) );
		$this->assertFalse( $provider->authorize( 'children', $this->context(), [ $this->card_row_id( 'mug|' ) ] ) );

		$page = Row_Normalizer::children_page(
			$this->cards_definition(),
			$provider->get_children(
				$this->context(),
				$parent,
				[
					'page'     => 1,
					'per_page' => 20,
				]
			),
			20
		);

		$this->assertSame( 2, $page['total'] );
		$this->assertSame( [ 'coat · M', 'coat · L' ], array_column( array_column( $page['rows'], 'cells' ), 'offer_id' ) );
		$this->assertSame( [ false, false ], array_column( $page['rows'], 'has_children' ) );
	}

	public function test_the_counts_and_the_filter_go_by_card_and_a_half_linked_card_is_in_both(): void {
		$product = $this->add_product( [ 'id' => 41, 'sku' => 'coat|M' ] ); // The helper links the product's SKU as the article.
		$this->add_link( $product, Link_Record::STATE_CREATED );

		$body = $this->list_body( [ 'on_site' => 'yes' ] );

		$this->assertSame( 1, $body['total'] );
		$this->assertSame( '1 из 2', $body['rows'][0]['cells']['on_site'][0]['value'], 'the card is listed with all its sizes' );
		$this->assertSame( [ 'no' => 2, 'yes' => 1 ], array_column( $body['facets']['on_site'], 'count', 'value' ) );
	}

	public function test_importing_a_card_row_takes_all_its_sizes(): void {
		$preview = $this->cards_provider()->preview( $this->context(), 'import_selected', [ $this->parent_id( 'coat' ), $this->card_row_id( 'coat|M' ) ], [] );

		$this->assertSame( 2, $preview['eligible'], 'a size ticked beside its card is counted once' );
	}

	public function test_a_conflict_of_the_link_run_is_said_at_the_card_until_a_product_carries_it(): void {
		$this->reports()->replace_conflicts(
			$this->account_id,
			[
				[
					'offer_id'    => 'mug|',
					'reason'      => Unit_Matcher::AMBIGUOUS,
					'product_ids' => [ 7, 8 ],
				],
			]
		);

		$rows = array_column( $this->list_body()['rows'], null, 'id' );

		$this->assertStringContainsString( 'несколько товаров сайта: «Пальто» №7, «Пальто» №8', $rows[ $this->card_row_id( 'mug|' ) ]['cells']['state'][0]['value']['tooltip'] );

		$product = $this->add_product( [ 'id' => 41, 'sku' => 'mug|' ] );
		$this->add_link( $product, Link_Record::STATE_CREATED );

		$rows = array_column( $this->list_body()['rows'], null, 'id' );

		$this->assertStringNotContainsString( 'несколько товаров', $rows[ $this->card_row_id( 'mug|' ) ]['cells']['state'][0]['value']['tooltip'] ?? '' );
	}

	public function test_the_stored_list_keeps_the_barcodes_and_the_unit_byte_for_byte(): void {
		$card = $this->card_store()->find( $this->account_id, Wpdb_Card_Store::key( 'coat|M' ) );

		$this->assertSame( [ '12345Ejf5', 'coatM' ], $card['barcodes'] );
		$this->assertSame(
			[
				'card'    => 'coat',
				'article' => 'coat',
				'count'   => 2,
			],
			$card['unit']
		);
	}

	public function test_a_renamed_article_is_shown_and_searched_as_it_is_now_under_the_frozen_key(): void {
		$renamed = $this->unit( 'mug', '', 1 );

		$renamed['unit']['article'] = 'КРУЖКА-2';
		$this->put_cards( [ $renamed ] );

		$product = $this->add_product( [ 'id' => 41, 'sku' => 'mug|' ] );
		$this->add_link( $product, Link_Record::STATE_CREATED );

		$row = $this->list_body()['rows'][0];

		$this->assertSame( $this->card_row_id( 'mug|' ), $row['id'], 'the same card, the same link' );
		$this->assertSame( 'КРУЖКА-2', $row['cells']['offer_id'] );
		$this->assertSame( 'link', $row['cells']['on_site'][0]['type'] );
		$this->assertSame( 1, $this->card_store()->page( $this->account_id, null, 'КРУЖКА', 1, 20 )['total'] );
		$this->assertSame( 'КРУЖКА-2', ( new Article_Display( new Fake_Sellable_Units(), $this->card_store() ) )->of_link( $this->account_id, 'mug|' ) );
	}

	public function test_a_row_whose_article_does_not_fit_is_left_out_of_the_list(): void {
		$this->put_cards( [ $this->unit( str_repeat( 'a', 191 ), '', 1 ), $this->unit( 'mug', '', 1 ) ] );

		$this->assertSame( 1, $this->list_body()['total'] );
	}
}
