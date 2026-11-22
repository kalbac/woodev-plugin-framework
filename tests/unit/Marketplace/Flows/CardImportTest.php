<?php
/**
 * Importing one card: the five rows of the decision, and the four places a run can be cut.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Category_Finder;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Flows\Card_Import;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;
use Woodev\Framework\Marketplace\Flows\Import_Category;
use Woodev\Framework\Marketplace\Flows\Import_Mapping;
use Woodev\Framework\Marketplace\Flows\Product_Writer;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Card_Import
 */
final class CardImportTest extends Export_Test_Case {
	private const ARTICLE = 'OW-IMPORT-1';
	private const PREFIX  = 'fake_seller_';

	/**
	 * Options of the test, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * Product ids by SKU, as WooCommerce's SKU lookup answers.
	 *
	 * @var array<string,int>
	 */
	private $by_sku = [];

	/**
	 * What the writer was asked to do: `[ 'write' | 'resume', article, publish, create categories ]`.
	 *
	 * @var array<int,array{0:string,1:string,2:bool,3:bool|null}>
	 */
	private $writes = [];

	/**
	 * Warnings the writer reports for a created product.
	 *
	 * @var string[]
	 */
	private $warnings = [];

	/**
	 * When set, the writer throws this after it created and marked the product (a cut between the two saves).
	 *
	 * @var \Throwable|null
	 */
	private $cut_after_first_save;

	/**
	 * When set, the writer throws this before it creates anything (a cut before the first save).
	 *
	 * @var \Throwable|null
	 */
	private $cut_before_first_save;

	/**
	 * Next product id the writer makes.
	 *
	 * @var int
	 */
	private $next_id = 900;

	/**
	 * The linker the next import writes through; null builds the plain one (switches off).
	 *
	 * @var Card_Linker|null
	 */
	private $linker;

	/**
	 * First sends the linker recorded: `[ 'stock'|'price', product id ]`.
	 *
	 * @var array<int,array{0:string,1:int}>
	 */
	private $first_sends = [];

	/**
	 * Products the writer's first save made and its second half has not seen yet, by id.
	 *
	 * @var array<int,bool>
	 */
	private $started = [];

	/**
	 * What gives the category of a created product its mapping; null builds an importer that leaves the mappings alone.
	 *
	 * @var Import_Mapping|null
	 */
	private $category_mapping;

	/**
	 * The shop's categories the import finds or makes: `[ id, name ]`, one level under the root.
	 *
	 * @var array<int,array{0:int,1:string}>
	 */
	private $categories = [];

	/**
	 * Stubs the options, the SKU lookup and the writer's world.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->options               = [];
		$this->by_sku                = [];
		$this->writes                = [];
		$this->warnings              = [];
		$this->cut_after_first_save  = null;
		$this->cut_before_first_save = null;
		$this->next_id               = 900;
		$this->started               = [];
		$this->category_mapping      = null;
		$this->categories            = [];

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
		Functions\when( 'wc_get_product_id_by_sku' )->alias(
			function ( string $sku ): int {
				return $this->by_sku[ $sku ] ?? 0;
			}
		);
	}

	/**
	 * Builds the importer over the test doubles of the writer.
	 *
	 * @return Card_Import
	 */
	private function importer(): Card_Import {
		return new Card_Import(
			$this->links(),
			$this->linker ?? new Card_Linker( $this->links() ),
			$this->notes(),
			new Effective_Settings(
				Sqlite_Tables::MARKETPLACE_ID,
				function ( string $name, $fallback = false ) {
					return $this->options[ $name ] ?? $fallback;
				}
			),
			new Group_Assembler( $this->links() ),
			function ( Account $account, Card_Content $content, bool $create_categories ): \WC_Product {
				return $this->start( $content, $create_categories );
			},
			function ( Account $account, Card_Content $content, \WC_Product $product, bool $publish, callable $renew ): array {
				return $this->complete( $content, $product, $publish );
			},
			$this->category_mapping
		);
	}

	/**
	 * Makes the next import create the mapping of the category of a created product, over a shop whose categories are
	 * `$this->categories`.
	 *
	 * @return void
	 */
	private function mapping_categories(): void {
		Functions\when( 'get_terms' )->alias(
			function ( array $args ): array {
				return array_map( 'intval', array_column( array_filter( $this->categories, static function ( array $term ) use ( $args ): bool { return $term[1] === $args['name']; } ), 0 ) );
			}
		);
		Functions\when( 'wp_insert_term' )->alias(
			function ( string $name ): array {
				$this->categories[] = [ 200 + count( $this->categories ), $name ];

				return [ 'term_id' => end( $this->categories )[0] ];
			}
		);
		Functions\when( 'get_term_field' )->alias(
			function ( string $field, int $term_id ): string {
				return (string) ( array_column( $this->categories, 1, 0 )[ $term_id ] ?? '' );
			}
		);

		$mappings               = $this->mappings();
		$this->category_mapping = new Import_Mapping( $mappings, new Import_Category( $mappings ), new Category_Finder( new Import_Fake_Schema() ) );
	}

	/**
	 * Builds a card of a type of the fake catalogue, in a category of the given name.
	 *
	 * @param array<string,int> $type     The card's type.
	 * @param string            $category Name of its category.
	 * @param string            $article  Article of the card.
	 * @return Card_Content
	 */
	private function typed_card( array $type, string $category = 'Смартфоны', string $article = self::ARTICLE ): Card_Content {
		return new Card_Content(
			$this->card( [ 'offer_id' => $article ] )->card(),
			[
				'name'            => 'Блокнот',
				'remote_category' => $type,
				'category_path'   => [ $category ],
			]
		);
	}

	/**
	 * Plays the writer's first save: the product exists, with the article as its SKU.
	 *
	 * @param Card_Content $content           Card.
	 * @param bool         $create_categories Create categories.
	 * @return \WC_Product
	 */
	private function start( Card_Content $content, bool $create_categories ): \WC_Product {
		$article        = (string) $content->card()['offer_id'];
		$this->writes[] = [ 'write', $article, null, $create_categories ];

		if ( null !== $this->cut_before_first_save ) {
			throw $this->cut_before_first_save;
		}

		$product = $this->shop_product( $this->next_id++, [ 'sku' => $article ] );

		$this->started[ (int) $product->get_id() ] = true;

		return $product;
	}

	/**
	 * Plays the rest of the writer. On the product the same call started it is the second half of a write; on any other
	 * product it is the resume of a run that was cut short.
	 *
	 * @param Card_Content $content Card.
	 * @param \WC_Product  $product The started product.
	 * @param bool         $publish Publish at once.
	 * @return string[]
	 */
	private function complete( Card_Content $content, \WC_Product $product, bool $publish ): array {
		$id = (int) $product->get_id();

		if ( empty( $this->started[ $id ] ) ) {
			$this->writes[] = [ 'resume', (string) $content->card()['offer_id'], $publish, null ];

			return $this->warnings;
		}

		unset( $this->started[ $id ] );
		$this->writes[ count( $this->writes ) - 1 ][2] = $publish;

		if ( null !== $this->cut_after_first_save ) {
			$this->mark( $product );

			throw $this->cut_after_first_save;
		}

		return $this->warnings;
	}

	/**
	 * Notes.
	 *
	 * @return Link_Report_Store
	 */
	private function notes(): Link_Report_Store {
		return new Link_Report_Store( self::PREFIX );
	}

	/**
	 * Builds a shop product double and makes it findable by its SKU.
	 *
	 * @param int                 $id    Product id.
	 * @param array<string,mixed> $props `sku`, `parent_id`, `type`.
	 * @return \WC_Product
	 */
	private function shop_product( int $id, array $props = [] ): \WC_Product {
		$props = array_merge(
			[
				'sku'       => self::ARTICLE,
				'parent_id' => 0,
				'type'      => 'simple',
			],
			$props
		);

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'get_parent_id' )->andReturn( (int) $props['parent_id'] );
		$product->shouldReceive( 'is_type' )->andReturnUsing(
			static function ( $type ) use ( $props ): bool {
				return $props['type'] === $type;
			}
		);

		$this->products[ $id ]        = $product;
		$this->by_sku[ $props['sku'] ] = $id;

		return $product;
	}

	/**
	 * Gives a product the import marker, as the writer's first save does.
	 *
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	private function mark( \WC_Product $product ): void {
		$product->shouldReceive( 'get_meta' )->with( Product_Writer::marker_key( Sqlite_Tables::MARKETPLACE_ID ) )->andReturn( '1' );
	}

	/**
	 * Gives a product no marker.
	 *
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	private function unmarked( \WC_Product $product ): void {
		$product->shouldReceive( 'get_meta' )->andReturn( '' );
	}

	/**
	 * Inserts the link of a product to a card.
	 *
	 * @param int    $product_id Product id.
	 * @param string $offer_id   Article of the card.
	 * @return void
	 */
	private function link_row( int $product_id, string $offer_id ): void {
		$this->links()->insert_if_absent(
			Link_Record::offer(
				$this->account_id,
				$product_id,
				0,
				$offer_id,
				[
					'card_state' => Link_Record::STATE_CREATED,
					'remote_ids' => [ 'card_ref' => 5 ],
				]
			)
		);
	}

	/**
	 * Builds a card as an adapter reads it.
	 *
	 * @param array<string,mixed> $card Link card values that replace the defaults.
	 * @return Card_Content
	 */
	private function card( array $card = [] ): Card_Content {
		return new Card_Content(
			array_merge(
				[
					'offer_id'   => self::ARTICLE,
					'remote_ids' => [ 'card_ref' => 77 ],
					'state'      => Link_Record::STATE_CREATED,
					'is_created' => true,
					'archived'   => false,
				],
				$card
			),
			[ 'name' => 'Блокнот' ]
		);
	}

	/**
	 * A fence of the run that holds the account.
	 *
	 * @return Claim_Fence
	 */
	private function fence(): Claim_Fence {
		$fence = $this->job_gate()->claim( $this->account_id );

		$this->assertNotNull( $fence );

		return $fence;
	}

	/**
	 * Imports the card with a claim the run holds.
	 *
	 * @param Card_Content|null $content Card.
	 * @param Claim_Fence|null  $fence   Claim of the run.
	 * @return string
	 */
	private function import( ?Card_Content $content = null, ?Claim_Fence $fence = null ): string {
		$held = $fence ?? $this->fence();

		try {
			return $this->importer()->import( $this->account_now(), $content ?? $this->card(), $held, static function (): void {} );
		} finally {
			$this->job_gate()->release( $this->account_id, $held ); // Every run of the test is a run of its own.
		}
	}

	/**
	 * The note of the card, null when it has none.
	 *
	 * @return array{offer_id:string,text:string,detail:string}|null
	 */
	private function note(): ?array {
		foreach ( $this->notes()->import_notes( $this->account_id ) as $note ) {
			if ( self::ARTICLE === $note['offer_id'] ) {
				return $note;
			}
		}

		return null;
	}

	/**
	 * Row 1: a linked card is left alone, and its old note goes.
	 *
	 * @return void
	 */
	public function test_a_card_that_has_a_link_is_left_alone(): void {
		$this->link_row( 301, self::ARTICLE );
		$this->notes()->set_import_note( $this->account_id, self::ARTICLE, 'Старая заметка' );

		$this->assertSame( Card_Import::ALREADY_LINKED, $this->import() );
		$this->assertSame( [], $this->writes, 'no product written' );
		$this->assertNull( $this->note(), 'the card is on the site: a stale note goes' );
		$this->assertSame( 1, $this->links()->count( $this->account_id ) );
	}

	/**
	 * Row 2: an archived card makes no product and no link, whichever way the adapter says it is archived.
	 *
	 * @return void
	 */
	public function test_an_archived_card_creates_no_product_and_no_link(): void {
		foreach ( [ [ 'archived' => true ], [ 'state' => Link_Record::STATE_ARCHIVED ] ] as $how ) {
			$this->assertSame( Card_Import::SKIPPED, $this->import( $this->card( $how ) ) );
		}

		$this->assertSame( [], $this->writes );
		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
		$this->assertSame( 'Карточка в архиве — не импортируем.', $this->note()['text'] );
	}

	/**
	 * Row 3: a product that carries the article is linked and its content is not touched.
	 *
	 * @return void
	 */
	public function test_a_product_with_the_article_is_linked_and_not_rewritten(): void {
		$product = $this->shop_product( 401 );
		$this->unmarked( $product );

		$this->assertSame( Card_Import::LINKED_EXISTING, $this->import() );
		$this->assertSame( [], $this->writes, 'the product is neither created nor resumed' );

		$row = $this->links()->find( $this->account_id, 401 );

		$this->assertNotNull( $row );
		$this->assertSame( self::ARTICLE, $row->offer_id() );
		$this->assertSame( 0, $row->wc_parent_id() );
		$this->assertFalse( $row->sync_stock() );
		$this->assertFalse( $row->sync_price() );
		$this->assertNull( $this->note() );
	}

	/**
	 * Makes the next import write through a linker that switches synchronisation on (or not) and records its first sends.
	 *
	 * @param bool $on What the account's «sync on link» setting says.
	 * @return void
	 */
	private function sync_on_link( bool $on ): void {
		$this->first_sends = [];
		$this->linker      = new Card_Linker(
			$this->links(),
			static function () use ( $on ): bool {
				return $on;
			},
			function ( Link_Record $link ): void {
				$this->first_sends[] = [ 'stock', $link->wc_product_id() ];
			},
			function ( int $account_id, Link_Record $link ): void {
				$this->first_sends[] = [ 'price', $link->wc_product_id() ];
			}
		);
	}

	/**
	 * A product the importer linked to an existing shop product gets sync on and the full first send, stock and price (#1793).
	 *
	 * @return void
	 */
	public function test_an_existing_product_is_linked_with_sync_on_and_both_first_sends(): void {
		$this->sync_on_link( true );
		$product = $this->shop_product( 401 );
		$this->unmarked( $product );

		$this->assertSame( Card_Import::LINKED_EXISTING, $this->import() );

		$row = $this->links()->find( $this->account_id, 401 );

		$this->assertTrue( $row->sync_stock() );
		$this->assertTrue( $row->sync_price() );
		$this->assertSame( [ [ 'stock', 401 ], [ 'price', 401 ] ], $this->first_sends );
	}

	/**
	 * A product made from the card gets sync on and only the first stock send: its price came from the card (#1793).
	 *
	 * @return void
	 */
	public function test_a_created_product_is_linked_with_sync_on_and_only_the_stock_first_send(): void {
		$this->sync_on_link( true );

		$this->assertSame( Card_Import::CREATED, $this->import() );

		$row = $this->links()->find( $this->account_id, 900 );

		$this->assertTrue( $row->sync_stock() );
		$this->assertTrue( $row->sync_price() );
		$this->assertSame( [ [ 'stock', 900 ] ], $this->first_sends, 'no price send for a product created from the card' );
	}

	/**
	 * With the setting off the import links as before: switches off, nothing recorded.
	 *
	 * @return void
	 */
	public function test_with_the_setting_off_an_imported_card_links_with_sync_off(): void {
		$this->sync_on_link( false );
		$product = $this->shop_product( 401 );
		$this->unmarked( $product );

		$this->import();
		$this->import( $this->card( [ 'offer_id' => 'OTHER-ARTICLE' ] ) );

		$this->assertFalse( $this->links()->find( $this->account_id, 401 )->sync_stock() );
		$this->assertSame( [], $this->first_sends );
	}

	/**
	 * Row 3, the trap: a variable parent with the card's SKU and no link row gets no link, and the merchant is told.
	 *
	 * @return void
	 */
	public function test_a_variable_parent_with_the_article_gets_no_link(): void {
		$parent = $this->shop_product( 410, [ 'type' => 'variable' ] );
		$this->unmarked( $parent );

		$this->assertNull( $this->links()->find( $this->account_id, 410 ), 'the parent has no row to start with' );
		$this->assertSame( Card_Import::SKIPPED, $this->import() );

		$this->assertNull( $this->links()->find( $this->account_id, 410 ), 'no link was inserted onto the parent' );
		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
		$this->assertSame( [], $this->writes );
		$this->assertSame( 'К вариативному товару нельзя привязать карточку. Выберите один из его вариантов через «Связать с…».', $this->note()['text'] );
	}

	/**
	 * Row 3: a variation that carries the article is linked with its real parent id.
	 *
	 * @return void
	 */
	public function test_a_variation_with_the_article_is_linked_with_its_real_parent(): void {
		$variation = $this->shop_product( 421, [ 'parent_id' => 420, 'type' => 'variation' ] );
		$this->unmarked( $variation );

		$this->assertSame( Card_Import::LINKED_EXISTING, $this->import() );

		$row = $this->links()->find( $this->account_id, 421 );

		$this->assertNotNull( $row );
		$this->assertSame( 420, $row->wc_parent_id() );
	}

	/**
	 * Row 3: what the linker refuses becomes the note; nothing is rewritten.
	 *
	 * @return void
	 */
	public function test_a_product_that_is_linked_to_another_card_is_a_note(): void {
		$product = $this->shop_product( 430 );
		$this->unmarked( $product );
		$this->link_row( 430, 'OTHER-ARTICLE' );

		$this->assertSame( Card_Import::SKIPPED, $this->import() );
		$this->assertSame( Card_Linker::message( Card_Linker::PRODUCT_LINKED ), $this->note()['text'] );
		$this->assertSame( 'OTHER-ARTICLE', $this->links()->find( $this->account_id, 430 )->offer_id() );
	}

	/**
	 * Row 5: no product → the writer creates it as a draft with categories created, then the card is linked.
	 *
	 * @return void
	 */
	public function test_a_card_with_no_product_creates_one_and_links_it(): void {
		$this->assertSame( Card_Import::CREATED, $this->import() );
		$this->assertSame( [ [ 'write', self::ARTICLE, false, true ] ], $this->writes, 'a draft, categories on: the defaults' );

		$row = $this->links()->find( $this->account_id, 900 );

		$this->assertNotNull( $row );
		$this->assertSame( self::ARTICLE, $row->offer_id() );
		$this->assertSame( 77, $row->remote_id( 'card_ref' ) );
		$this->assertFalse( $row->sync_stock(), 'importing never switches synchronisation on' );
		$this->assertFalse( $row->sync_price() );
	}

	/**
	 * The two import settings decide the status and the categories.
	 *
	 * @return void
	 */
	public function test_the_settings_decide_the_status_and_the_categories(): void {
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [
			'import_publish'           => true,
			'import_create_categories' => false,
		];

		$this->import();

		$this->assertSame( [ [ 'write', self::ARTICLE, true, false ] ], $this->writes );
	}

	/**
	 * A warning about a created product is a note, the product exists; a later clean import clears it.
	 *
	 * @return void
	 */
	public function test_a_warning_on_a_created_product_is_a_note_and_a_clean_import_clears_it(): void {
		$this->warnings = [ 'Не удалось загрузить 1 фото — товар создан без него.' ];

		$this->assertSame( Card_Import::CREATED, $this->import() );
		$this->assertSame( 'Не удалось загрузить 1 фото — товар создан без него.', $this->note()['text'] );
		$this->assertNotNull( $this->links()->find( $this->account_id, 900 ), 'the product exists and is linked' );

		$this->notes()->set_import_note( $this->account_id, self::ARTICLE, 'Предупреждение', 'detail' );
		$this->warnings = [];

		$this->assertSame( Card_Import::ALREADY_LINKED, $this->import() );
		$this->assertNull( $this->note() );
	}

	/**
	 * Row 4: a product that carries the marker is finished, not created twice.
	 *
	 * @return void
	 */
	public function test_a_marked_product_is_resumed_and_linked(): void {
		$product = $this->shop_product( 450 );
		$this->mark( $product );

		$this->assertSame( Card_Import::CREATED, $this->import() );
		$this->assertSame( [ [ 'resume', self::ARTICLE, false, null ] ], $this->writes, 'the writer resumes, it does not create' );
		$this->assertNotNull( $this->links()->find( $this->account_id, 450 ) );
	}

	/**
	 * Cut point 1: cut before the first save, nothing exists and the next run starts again.
	 *
	 * @return void
	 */
	public function test_a_run_cut_before_the_first_save_starts_again(): void {
		$this->cut_before_first_save = new \RuntimeException( 'time limit' );

		try {
			$this->import();
			$this->fail( 'the cut reaches the caller' );
		} catch ( \RuntimeException $cut ) {
			$this->assertSame( 'time limit', $cut->getMessage() );
		}

		$this->assertSame( [], $this->by_sku, 'nothing was created' );
		$this->assertSame( 0, $this->links()->count( $this->account_id ) );

		$this->cut_before_first_save = null;

		$this->assertSame( Card_Import::CREATED, $this->import() );
		$this->assertSame( [ 'write', 'write' ], array_column( $this->writes, 0 ), 'the second run creates' );
		$this->assertCount( 1, $this->by_sku );
	}

	/**
	 * Cut point 2: cut between the first and the final save, the next run resumes the same product.
	 *
	 * @return void
	 */
	public function test_a_run_cut_between_the_two_saves_resumes_the_same_product(): void {
		$this->cut_after_first_save = new \RuntimeException( 'time limit during images' );

		try {
			$this->import();
			$this->fail( 'the cut reaches the caller' );
		} catch ( \RuntimeException $cut ) {
			$this->assertSame( 'time limit during images', $cut->getMessage() );
		}

		$this->assertSame( 900, $this->by_sku[ self::ARTICLE ], 'the first save left the product, findable by its SKU' );
		$this->assertSame( 0, $this->links()->count( $this->account_id ) );

		$this->cut_after_first_save = null;

		$this->assertSame( Card_Import::CREATED, $this->import() );
		$this->assertSame( [ 'write', 'resume' ], array_column( $this->writes, 0 ), 'the second run resumes instead of creating' );
		$this->assertNotNull( $this->links()->find( $this->account_id, 900 ) );
		$this->assertCount( 1, $this->by_sku, 'no second product' );
	}

	/**
	 * Cut point 3: cut between the final save and the link, the next run links the finished product.
	 *
	 * @return void
	 */
	public function test_a_run_cut_between_the_final_save_and_the_link_links_the_product(): void {
		$product = $this->shop_product( 460 );
		$this->unmarked( $product ); // The final save removed the marker; the link was never written.

		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
		$this->assertSame( Card_Import::LINKED_EXISTING, $this->import() );
		$this->assertSame( [], $this->writes );
		$this->assertNotNull( $this->links()->find( $this->account_id, 460 ) );
	}

	/**
	 * Cut point 4: a link refused because the claim was lost stops the run; the retry finds the product and links it.
	 *
	 * @return void
	 */
	public function test_a_link_refused_for_a_lost_claim_stops_the_run_and_the_retry_links_it(): void {
		$stale = new Claim_Fence( Sqlite_Tables::table( 'accounts' ), 'a-token-another-run-replaced' );

		try {
			$this->import( null, $stale );
			$this->fail( 'the lost claim stops the run' );
		} catch ( Claim_Lost $lost ) {
			$this->assertSame( 0, $this->links()->count( $this->account_id ), 'nothing was linked' );
		}

		$this->assertSame( 900, $this->by_sku[ self::ARTICLE ], 'the product was made before the link was refused' );

		$this->by_sku_product_is_finished();

		$this->assertSame( Card_Import::LINKED_EXISTING, $this->import() );
		$this->assertNotNull( $this->links()->find( $this->account_id, 900 ) );
		$this->assertSame( [ 'write' ], array_column( $this->writes, 0 ), 'the retry creates nothing' );
	}

	/**
	 * Gives the product the writer made no marker, as its final save does.
	 *
	 * @return void
	 */
	private function by_sku_product_is_finished(): void {
		$this->unmarked( $this->products[ 900 ] );
	}

	/**
	 * A card with no article cannot be imported or noted.
	 *
	 * @return void
	 */
	public function test_a_card_with_no_article_is_skipped(): void {
		$this->assertSame( Card_Import::SKIPPED, $this->import( $this->card( [ 'offer_id' => '' ] ) ) );
		$this->assertSame( [], $this->writes );
	}

	/**
	 * A created card whose category has no mapping leaves the import with one, while the import creates categories.
	 *
	 * @return void
	 */
	public function test_a_created_card_gets_the_mapping_of_its_category(): void {
		$this->mapping_categories();

		$this->assertSame( Card_Import::CREATED, $this->import( $this->typed_card( Import_Fake_Schema::PHONES ) ) );

		$found = $this->mappings()->for_category( Import_Fake_Schema::PHONES );

		$this->assertNotNull( $found );
		$this->assertSame( [ 200 ], $found['term_ids'] );
		$this->assertNull( $this->note(), 'a clean mapping is no note' );
	}

	/**
	 * With «Создавать категории при импорте» off the import chose no category, so it makes no mapping.
	 *
	 * @return void
	 */
	public function test_without_the_create_categories_setting_no_mapping_is_made(): void {
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [ 'import_create_categories' => false ];
		$this->mapping_categories();

		$this->assertSame( Card_Import::CREATED, $this->import( $this->typed_card( Import_Fake_Schema::PHONES ) ) );

		$this->assertNull( $this->mappings()->for_category( Import_Fake_Schema::PHONES ) );
		$this->assertSame( [], $this->categories, 'and no category either' );
	}

	/**
	 * A product the merchant already had is only linked: its categories are the merchant's, never mapped by the import.
	 *
	 * @return void
	 */
	public function test_a_product_that_is_only_linked_gets_no_mapping(): void {
		$product = $this->shop_product( 401 );
		$this->unmarked( $product );
		$this->mapping_categories();

		$this->assertSame( Card_Import::LINKED_EXISTING, $this->import( $this->typed_card( Import_Fake_Schema::PHONES ) ) );

		$this->assertNull( $this->mappings()->for_category( Import_Fake_Schema::PHONES ) );
	}

	/**
	 * A card of another type than the one that mapped its category carries the sentence about it as its note.
	 *
	 * @return void
	 */
	public function test_the_note_of_a_card_of_another_type_says_which_type_mapped_the_category(): void {
		$this->mapping_categories();
		$this->import( $this->typed_card( Import_Fake_Schema::PHONES, 'Гаджеты' ) );

		$this->assertSame( Card_Import::CREATED, $this->import( $this->typed_card( Import_Fake_Schema::TABLETS, 'Гаджеты', 'OW-IMPORT-2' ) ) );

		$notes = array_column( $this->notes()->import_notes( $this->account_id ), 'text', 'offer_id' );

		$this->assertSame( [ 'OW-IMPORT-2' => 'Категория «Гаджеты»: на маркетплейсе карточки разных типов, сопоставление создано по типу «Электроника › Смартфоны».' ], $notes, 'the first card has no note' );
		$this->assertNull( $this->mappings()->for_category( Import_Fake_Schema::TABLETS ) );
	}
}
