<?php
/**
 * Linking and importing the units of a marketplace that sells a size inside a card.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Import_Plan;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Flows\Card_Import;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;
use Woodev\Framework\Marketplace\Flows\Import_Category;
use Woodev\Framework\Marketplace\Flows\Product_Writer;
use Woodev\Framework\Marketplace\Flows\Unit_Product_Source;
use Woodev\Framework\Marketplace\Flows\Unit_Products;
use Woodev\Framework\Marketplace\Links\Unit_Key;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Sync\Shop_Price_Writer;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Sellable_Units;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Shop;

/**
 * The importer, the writer's second half, the assembler, the linker and the product lookup run for real, over the links table, a shop in memory
 * ({@see Fake_Shop}) and the three WordPress tables the lookup reads, rebuilt from the shop after every save.
 *
 * @covers \Woodev\Framework\Marketplace\Flows\Card_Import
 * @covers \Woodev\Framework\Marketplace\Flows\Unit_Products
 * @covers \Woodev\Framework\Marketplace\Flows\Unit_Product_Source
 * @covers \Woodev\Framework\Marketplace\Flows\Product_Writer
 * @covers \Woodev\Framework\Marketplace\Data\Wpdb_Link_Store::barcodes_of
 */
final class UnitImportTest extends Export_Test_Case {
	private const PREFIX = 'fake_seller_';
	private const GTIN   = '4006381333931';

	/**
	 * The shop.
	 *
	 * @var Fake_Shop
	 */
	private $shop;

	/**
	 * Options of the test, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options          = [];
		$this->shop             = new Fake_Shop();
		$this->shop->after_save = function (): void {
			$this->mirror();
		};
		$this->shop->install();

		$this->db->posts    = 'wp_posts';
		$this->db->postmeta = 'wp_postmeta';
		$this->db->define( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_parent INTEGER, post_type TEXT, post_status TEXT)' );
		$this->db->define( 'CREATE TABLE wp_postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
		$this->db->define( 'CREATE TABLE wp_wc_product_meta_lookup (product_id INTEGER PRIMARY KEY, sku TEXT)' );

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wc_get_product_term_ids' )->justReturn( [] );
		Functions\when( 'get_the_title' )->alias(
			function ( int $id ): string {
				return (string) ( $this->shop->stored[ $id ]['props']['name'] ?? '' );
			}
		);
	}

	/**
	 * Rebuilds the WordPress tables from the shop.
	 *
	 * @return void
	 */
	private function mirror(): void {
		foreach ( [ 'wp_posts', 'wp_postmeta', 'wp_wc_product_meta_lookup' ] as $table ) {
			$this->db->query( 'DELETE FROM ' . $table );
		}

		foreach ( $this->shop->stored as $id => $row ) {
			$type = 'variation' === $row['type'] ? 'product_variation' : 'product';
			$meta = $row['meta'] + [ '_global_unique_id' => $row['props']['global_unique_id'] ];

			$this->db->query( $this->db->prepare( 'INSERT INTO wp_posts VALUES (%d, %d, %s, %s)', $id, $row['props']['parent_id'], $type, $row['props']['status'] ) );
			$this->db->query( $this->db->prepare( 'INSERT INTO wp_wc_product_meta_lookup VALUES (%d, %s)', $id, $row['props']['sku'] ) );

			foreach ( $meta as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$this->db->query( $this->db->prepare( 'INSERT INTO wp_postmeta VALUES (%d, %s, %s)', $id, $key, (string) $value ) );
				}
			}
		}
	}

	/**
	 * The unit lookup over the shop.
	 *
	 * @return Unit_Products
	 */
	private function unit_products(): Unit_Products {
		return new Unit_Products( new Fake_Sellable_Units(), new Unit_Product_Source( $this->db, $this->profiles(), Sqlite_Tables::MARKETPLACE_ID ), $this->links() );
	}

	/**
	 * The importer with the real writer.
	 *
	 * @return Card_Import
	 */
	private function importer(): Card_Import {
		$units  = new Fake_Sellable_Units();
		$writer = new Product_Writer( new Import_Category( $this->mappings() ), $this->profiles(), new Shop_Price_Writer( $this->contexts() ) );

		return new Card_Import(
			$this->links(),
			new Card_Linker( $this->links() ),
			new Link_Report_Store( self::PREFIX ),
			new Effective_Settings(
				Sqlite_Tables::MARKETPLACE_ID,
				function ( string $name, $fallback = false ) {
					return $this->options[ $name ] ?? $fallback;
				}
			),
			new Group_Assembler( $this->links(), $units ),
			function ( Account $account, Card_Content $content, bool $create_categories, ?callable $prepare = null, ?string $sku = null ): \WC_Product {
				return $this->start( $content, $prepare, (string) $sku );
			},
			[ $writer, 'complete' ],
			null,
			$this->unit_products()
		);
	}

	/**
	 * Plays `Product_Writer::start()`, which news up a WooCommerce class: a draft with the name, the given SKU, the marker and what
	 * `$prepare` sets, in one save. The second half of the writer is the real one.
	 *
	 * @param Card_Content  $content Card.
	 * @param callable|null $prepare Called on the unsaved product.
	 * @param string        $sku     SKU the importer chose.
	 * @return \WC_Product
	 */
	private function start( Card_Content $content, ?callable $prepare, string $sku ): \WC_Product {
		$product = $this->shop->product( 'simple' );

		$product->set_name( $content->name() );
		$product->set_status( 'draft' );
		$product->set_sku( $sku );
		$product->update_meta_data( Product_Writer::marker_key( Sqlite_Tables::MARKETPLACE_ID ), '1' );

		if ( null !== $prepare ) {
			$prepare( $product );
		}

		$product->save();

		return $product;
	}

	/**
	 * Builds a card as the adapter reads it whole.
	 *
	 * @param string   $article  Article.
	 * @param string   $size     Size.
	 * @param string[] $barcodes Barcodes.
	 * @param int      $sizes    Sizes of the card.
	 * @param string   $group    Group key, '' for a product of its own.
	 * @param int      $members  Cards of the group.
	 * @return Card_Content
	 */
	private function card( string $article, string $size, array $barcodes = [], int $sizes = 1, string $group = '', int $members = 0 ): Card_Content {
		return new Card_Content(
			Fake_Sellable_Units::card( $article, $size, $barcodes, $sizes ) + [
				'state'      => Link_Record::STATE_CREATED,
				'is_created' => true,
			],
			[
				'name'  => 'Пальто',
				'group' => [
					'key'     => $group,
					'size'    => $members,
					'aspects' => '' === $size ? [] : [ 'Размер' => $size ],
				],
			]
		);
	}

	/**
	 * Imports a card in a run of its own.
	 *
	 * @param Card_Content $content Card.
	 * @return string Outcome.
	 */
	private function import( Card_Content $content ): string {
		$fence = $this->job_gate()->claim( $this->account_id );
		$this->assertNotNull( $fence );

		try {
			return $this->importer()->import( $this->account_now(), $content, $fence, static function (): void {} );
		} finally {
			$this->job_gate()->release( $this->account_id, $fence );
		}
	}

	/**
	 * Saves a product the merchant made.
	 *
	 * @param string              $sku   SKU.
	 * @param string              $name  Name.
	 * @param array<string,mixed> $meta  Meta.
	 * @return int Product id.
	 */
	private function merchant_product( string $sku, string $name = 'Мой товар', array $meta = [] ): int {
		$product = $this->shop->product( 'simple' );
		$product->set_name( $name );
		$product->set_sku( $sku );

		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}

		return $product->save();
	}

	/**
	 * The stored product that carries a card.
	 *
	 * @param string $article Article.
	 * @param string $size    Size.
	 * @return array{type:string,props:array<string,mixed>,meta:array<string,mixed>}
	 */
	private function product_of( string $article, string $size ): array {
		$link = $this->links()->find_by_offer_id( $this->account_id, Unit_Key::encode( $article, $size ) );
		$this->assertNotNull( $link, $article . ' / ' . $size );

		return $this->shop->stored[ $link->wc_product_id() ];
	}

	/**
	 * The note the merchant reads at a card.
	 *
	 * @param string $article Article.
	 * @param string $size    Size.
	 * @return string
	 */
	private function note( string $article, string $size ): string {
		$notes = array_column( ( new Link_Report_Store( self::PREFIX ) )->import_notes( $this->account_id ), 'text', 'offer_id' );

		return (string) ( $notes[ Unit_Key::encode( $article, $size ) ] ?? '' );
	}

	/**
	 * A barcode that is no GTIN, and a size with two barcodes, survive the import byte for byte; the GTIN field stays empty.
	 *
	 * @return void
	 */
	public function test_free_barcodes_are_kept_whole_and_never_written_as_a_gtin(): void {
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'coat', 'M', [ '12345Ejf5', '4006381333932' ] ) ) );

		$link = $this->links()->find_by_offer_id( $this->account_id, 'coat|M' );

		$this->assertSame( [ '12345Ejf5', '4006381333932' ], $this->links()->barcodes_of( (int) $link->link_id() ) );
		$this->assertSame( '', $this->product_of( 'coat', 'M' )['props']['global_unique_id'] );
		$this->assertSame( 'coat', $this->product_of( 'coat', 'M' )['props']['sku'], 'the unit key is never a SKU' );
	}

	/**
	 * The first barcode that is a GTIN goes into the field; the whole list stays with the link.
	 *
	 * @return void
	 */
	public function test_the_first_real_gtin_is_written(): void {
		$this->import( $this->card( 'coat', '', [ '12345Ejf5', self::GTIN, '96385074' ] ) );

		$this->assertSame( self::GTIN, $this->product_of( 'coat', '' )['props']['global_unique_id'] );
		$this->assertArrayNotHasKey( Unit_Products::pending_key( Sqlite_Tables::MARKETPLACE_ID ), $this->product_of( 'coat', '' )['meta'], 'the pending mark goes with the link' );
	}

	/**
	 * Wildberries' own barcodes are restricted-circulation codes with a correct check digit: kept with the link, never a GTIN.
	 *
	 * @return void
	 */
	public function test_a_restricted_circulation_barcode_is_not_a_gtin(): void {
		$this->import( $this->card( 'coat', '', [ '2000000000008' ] ) );

		$link = $this->links()->find_by_offer_id( $this->account_id, 'coat|' );

		$this->assertSame( [ '2000000000008' ], $this->links()->barcodes_of( (int) $link->link_id() ) );
		$this->assertSame( '', $this->product_of( 'coat', '' )['props']['global_unique_id'] );
	}

	/**
	 * Sizes of one group become one variable product; the GTIN and the SKU of the first size move to its variation.
	 *
	 * @return void
	 */
	public function test_the_sizes_of_a_group_become_variations(): void {
		$this->import( $this->card( 'coat', 'M', [ self::GTIN ], 2, 'g1', 2 ) );
		$this->import( $this->card( 'coat', 'L', [ '96385074' ], 2, 'g1', 2 ) );

		$first  = $this->product_of( 'coat', 'M' );
		$second = $this->product_of( 'coat', 'L' );
		$parent = $this->shop->stored[ $first['props']['parent_id'] ];

		$this->assertSame( 'variable', $parent['type'] );
		$this->assertSame( $first['props']['parent_id'], $second['props']['parent_id'] );
		$this->assertSame( [ 'coat-M', self::GTIN ], [ $first['props']['sku'], $first['props']['global_unique_id'] ] );
		$this->assertSame( [ 'coat-L', '96385074' ], [ $second['props']['sku'], $second['props']['global_unique_id'] ] );
		$this->assertSame( '', $parent['props']['sku'] );
		$this->assertSame( '', $parent['props']['global_unique_id'] );
		$this->assertArrayNotHasKey( Unit_Products::pending_key( Sqlite_Tables::MARKETPLACE_ID ), $parent['meta'] );
		$this->assertArrayNotHasKey( Unit_Products::pending_key( Sqlite_Tables::MARKETPLACE_ID ), $first['meta'] );
	}

	/**
	 * The two collision pairs of the import SKU: the second unit is imported with no SKU and told why — never a shared SKU.
	 *
	 * @return array<string,array{0:array{0:string,1:string,2:int},1:array{0:string,1:string,2:int},2:string}>
	 */
	public function collisions(): array {
		return [
			'a size against a one-size article' => [ [ 'coat', 'M', 2 ], [ 'coat-M', '', 1 ], 'coat-M' ],
			'the other way round'               => [ [ 'coat-M', '', 1 ], [ 'coat', 'M', 2 ], 'coat-M' ],
			'a dash in either component'        => [ [ 'coat', 'M-L', 2 ], [ 'coat-M', 'L', 2 ], 'coat-M-L' ],
		];
	}

	/**
	 * @dataProvider collisions
	 * @param array{0:string,1:string,2:int} $first  Article, size, sizes of the card.
	 * @param array{0:string,1:string,2:int} $second The same.
	 * @param string                         $sku    The SKU both want.
	 * @return void
	 */
	public function test_a_taken_sku_is_not_written_and_the_conflict_is_said( array $first, array $second, string $sku ): void {
		$this->import( $this->card( $first[0], $first[1], [], $first[2] ) );
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( $second[0], $second[1], [], $second[2] ) ) );

		$taken = $this->product_of( $second[0], $second[1] );

		$this->assertSame( $sku, $this->product_of( $first[0], $first[1] )['props']['sku'] );
		$this->assertSame( '', $taken['props']['sku'] );
		$this->assertStringContainsString( 'Артикул «' . $sku . '» уже занят товаром «Пальто»', $this->note( $second[0], $second[1] ) );
		$this->assertSame( $sku, $taken['meta'][ Unit_Products::sku_conflict_key( Sqlite_Tables::MARKETPLACE_ID ) ] );
		$this->assertStringNotContainsString( 'уже занят', $this->note( $first[0], $first[1] ) );
	}

	/**
	 * A product the merchant made, found by the article and the size, is linked as it is.
	 *
	 * @return void
	 */
	public function test_the_one_matching_product_is_linked_not_copied(): void {
		$mine = $this->merchant_product( 'coat_M' );

		$this->assertSame( Card_Import::LINKED_EXISTING, $this->import( $this->card( 'coat', 'M', [ '12345Ejf5' ], 2 ) ) );
		$this->assertCount( 1, $this->shop->stored );
		$this->assertSame( $mine, $this->links()->find_by_offer_id( $this->account_id, 'coat|M' )->wc_product_id() );
		$this->assertSame( 'coat_M', $this->shop->stored[ $mine ]['props']['sku'] );
	}

	/**
	 * Several matching products: nothing is imported and nothing is guessed.
	 *
	 * @return void
	 */
	public function test_an_ambiguous_card_is_skipped_with_a_note(): void {
		$this->merchant_product( 'coat_M', 'Первый' );
		$this->merchant_product( 'coat/M', 'Второй' );

		$this->assertSame( Card_Import::SKIPPED, $this->import( $this->card( 'coat', 'M', [], 2 ) ) );
		$this->assertCount( 2, $this->shop->stored );
		$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'coat|M' ) );
		$this->assertStringContainsString( '«Первый»', $this->note( 'coat', 'M' ) );
		$this->assertStringContainsString( '«Второй»', $this->note( 'coat', 'M' ) );
	}

	/**
	 * Empties the shop and the link tables.
	 *
	 * @return void
	 */
	private function start_over(): void {
		$this->shop->stored       = [];
		$this->shop->saves        = 0;
		$this->shop->fail_save_at = 0;

		foreach ( [ 'links', 'link_ids', 'link_barcodes' ] as $table ) {
			$this->db->query( 'DELETE FROM ' . Sqlite_Tables::table( $table ) );
		}

		$this->mirror();
	}

	/**
	 * Imports a card, and once more when the first run was cut.
	 *
	 * @param Card_Content $content Card.
	 * @return void
	 */
	private function import_with_retry( Card_Content $content ): void {
		try {
			$this->import( $content );
		} catch ( \RuntimeException $e ) {
			$this->import( $content );
		}
	}

	/**
	 * A run cut at any save is finished by the retry: one variation per unit, found without any SKU of its key.
	 *
	 * @return void
	 */
	public function test_a_cut_group_import_is_finished_without_a_second_product(): void {
		foreach ( range( 1, 14 ) as $cut_at ) {
			$this->start_over();
			$this->shop->fail_save_at = $cut_at;

			foreach ( [ 'M', 'L' ] as $size ) {
				$this->import_with_retry( $this->card( 'coat', $size, [ 'M' === $size ? self::GTIN : '96385074' ], 2, 'g1', 2 ) );
			}

			$this->assertCount( 3, $this->shop->stored, "cut at save {$cut_at}: the parent and two variations" );
			$this->assertSame( [ 'coat-M', self::GTIN ], [ $this->product_of( 'coat', 'M' )['props']['sku'], $this->product_of( 'coat', 'M' )['props']['global_unique_id'] ], "cut at save {$cut_at}" );
			$this->assertSame( [ 'coat-L', '96385074' ], [ $this->product_of( 'coat', 'L' )['props']['sku'], $this->product_of( 'coat', 'L' )['props']['global_unique_id'] ], "cut at save {$cut_at}" );

			foreach ( $this->shop->stored as $row ) {
				$this->assertArrayNotHasKey( Unit_Products::pending_key( Sqlite_Tables::MARKETPLACE_ID ), $row['meta'], "cut at save {$cut_at}" );
			}
		}
	}

	/**
	 * A cut import of a card whose SKU is taken is found again by its pending mark, and the conflict is still said.
	 *
	 * @return void
	 */
	public function test_a_cut_import_without_a_sku_is_resumed(): void {
		foreach ( range( 1, 4 ) as $cut_at ) {
			$this->start_over();
			$this->import( $this->card( 'coat', 'M', [], 2 ) );
			$this->shop->fail_save_at = $this->shop->saves + $cut_at;

			$this->import_with_retry( $this->card( 'coat-M', '' ) );

			$this->assertCount( 2, $this->shop->stored, "cut at save {$cut_at}" );
			$this->assertSame( '', $this->product_of( 'coat-M', '' )['props']['sku'], "cut at save {$cut_at}" );
			$this->assertStringContainsString( 'уже занят товаром', $this->note( 'coat-M', '' ), "cut at save {$cut_at}" );
		}
	}

	/**
	 * «Снять связь» on the product of a card.
	 *
	 * @param string $article Article.
	 * @param string $size    Size.
	 * @return int The product that carried the card.
	 */
	private function unlink( string $article, string $size ): int {
		$link = $this->links()->find_by_offer_id( $this->account_id, Unit_Key::encode( $article, $size ) );

		$this->assertTrue( $this->links()->detach( $this->account_id, (int) $link->link_id() ) );

		return $link->wc_product_id();
	}

	/**
	 * A unit imported without a SKU has nothing a key could find, yet importing it again after «Снять связь» goes into the same
	 * product.
	 *
	 * @return void
	 */
	public function test_importing_an_unlinked_unit_without_a_sku_does_not_make_a_second_product(): void {
		$this->import( $this->card( 'coat', 'M', [], 2 ) );
		$this->import( $this->card( 'coat-M', '', [ '12345Ejf5' ] ) );

		$product = $this->unlink( 'coat-M', '' );
		$lookup  = Remote_Cards_Import_Plan::unit_lookup( $this->unit_products(), Product_Writer::marker_key( Sqlite_Tables::MARKETPLACE_ID ) );

		$this->assertSame( [ 'coat-M|' => Remote_Cards_Import_Plan::SKU_PLAIN ], $lookup( $this->account_id, [ Fake_Sellable_Units::card( 'coat-M', '', [ '12345Ejf5' ] ) ] ), 'the preview says «link», like the job' );
		$this->assertSame( Card_Import::LINKED_EXISTING, $this->import( $this->card( 'coat-M', '', [ '12345Ejf5' ] ) ) );
		$this->assertCount( 2, $this->shop->stored );
		$this->assertSame( $product, $this->links()->find_by_offer_id( $this->account_id, 'coat-M|' )->wc_product_id() );
		$this->assertStringContainsString( 'уже занят товаром', $this->note( 'coat-M', '' ) );
	}

	/**
	 * The same for a size that became a variation, the first of its group — which was a simple product when it was made — and a
	 * later one.
	 *
	 * @return void
	 */
	public function test_an_unlinked_variation_is_found_again_after_the_conversion_of_its_group(): void {
		$this->merchant_product( 'coat-M', 'Занял M' );
		$this->merchant_product( 'coat-L', 'Занял L' );

		// The merchant's products are linked to other cards, so the sizes cannot be them and get no SKU.
		( new Card_Linker( $this->links() ) )->link( $this->account_id, 900, 0, Fake_Sellable_Units::card( 'other', 'M' ) );
		( new Card_Linker( $this->links() ) )->link( $this->account_id, 901, 0, Fake_Sellable_Units::card( 'other', 'L' ) );

		$this->import( $this->card( 'coat', 'M', [], 2, 'g1', 2 ) );
		$this->import( $this->card( 'coat', 'L', [], 2, 'g1', 2 ) );

		$products = count( $this->shop->stored );

		foreach ( [ 'M', 'L' ] as $size ) {
			$variation = $this->unlink( 'coat', $size );

			$this->assertSame( '', $this->shop->stored[ $variation ]['props']['sku'] );
			$this->assertSame( Card_Import::LINKED_EXISTING, $this->import( $this->card( 'coat', $size, [], 2, 'g1', 2 ) ), $size );
			$this->assertSame( $variation, $this->links()->find_by_offer_id( $this->account_id, 'coat|' . $size )->wc_product_id() );
		}

		$this->assertCount( $products, $this->shop->stored );
	}

	/**
	 * A copy of an imported product (WooCommerce copies the meta) is not guessed between.
	 *
	 * @return void
	 */
	public function test_two_products_made_for_one_unit_are_a_conflict(): void {
		$this->import( $this->card( 'coat', '' ) );
		$original = $this->unlink( 'coat', '' );
		$this->merchant_product( '', 'Копия', [ Unit_Products::made_for_key( Sqlite_Tables::MARKETPLACE_ID ) => $this->shop->stored[ $original ]['meta'][ Unit_Products::made_for_key( Sqlite_Tables::MARKETPLACE_ID ) ] ] );

		$this->assertSame( Card_Import::SKIPPED, $this->import( $this->card( 'coat', '' ) ) );
		$this->assertCount( 2, $this->shop->stored );
	}

	/**
	 * Another account's half-made product is never adopted, even when its mark `{account}:{unit key}` reads like a unit key of
	 * this account: account 99 importing `coat|M`, and this account's article `99:coat` with size `M`.
	 *
	 * @return void
	 */
	public function test_another_accounts_half_made_product_is_never_adopted(): void {
		$mark    = Unit_Products::mark_value( 99, 'coat|M' );
		$foreign = $this->merchant_product(
			'99:coat-M',
			'Чужой черновик',
			[
				Unit_Products::pending_key( Sqlite_Tables::MARKETPLACE_ID )  => $mark,
				Unit_Products::made_for_key( Sqlite_Tables::MARKETPLACE_ID ) => $mark,
				Product_Writer::marker_key( Sqlite_Tables::MARKETPLACE_ID )  => '1',
			]
		);

		$this->assertSame( $mark, Unit_Key::encode( '99:coat', 'M' ), 'the two texts are the same' );
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( '99:coat', 'M', [], 2 ) ) );
		$this->assertNotSame( $foreign, $this->links()->find_by_offer_id( $this->account_id, '99:coat|M' )->wc_product_id() );
		$this->assertSame( $mark, $this->shop->stored[ $foreign ]['meta'][ Unit_Products::pending_key( Sqlite_Tables::MARKETPLACE_ID ) ], 'left as it was' );
	}

	/**
	 * A key longer than the link table holds is refused before any write.
	 *
	 * @return void
	 */
	public function test_a_key_too_long_creates_nothing(): void {
		$article = str_repeat( 'a', 191 );

		$this->assertSame( Card_Import::SKIPPED, $this->import( $this->card( $article, '' ) ) );
		$this->assertSame( [], $this->shop->stored );
	}

	/**
	 * The preview of «Импортировать» asks the job's own decision.
	 *
	 * @return void
	 */
	public function test_the_preview_decides_like_the_job(): void {
		$this->merchant_product( 'one-M' );
		$this->merchant_product( 'two_M' );
		$this->merchant_product( 'two/M' );

		$lookup = Remote_Cards_Import_Plan::unit_lookup( $this->unit_products(), Product_Writer::marker_key( Sqlite_Tables::MARKETPLACE_ID ) );

		$this->assertSame(
			[
				'one|M' => Remote_Cards_Import_Plan::SKU_PLAIN,
				'two|M' => Remote_Cards_Import_Plan::SKU_SEVERAL,
				'new|M' => Remote_Cards_Import_Plan::SKU_NONE,
			],
			$lookup(
				$this->account_id,
				[
					Fake_Sellable_Units::card( 'one', 'M', [], 2 ),
					Fake_Sellable_Units::card( 'two', 'M', [], 2 ),
					Fake_Sellable_Units::card( 'new', 'M', [], 2 ),
				]
			)
		);
	}
}
