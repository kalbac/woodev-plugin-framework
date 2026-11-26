<?php
/**
 * The article preview of the mapping form.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Article_Preview;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Products;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Controller;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Choices;
use Woodev\Framework\Marketplace\Catalog\Article_Preview;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Export_Facts;
use Woodev\Framework\Marketplace\Flows\Link_Context_Resolver;
use Woodev\Framework\Marketplace\Flows\Sellable_Units;
use Woodev\Framework\Marketplace\Flows\Shown_Article;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Mappings_Harness.php';

/**
 * Answers the article a test wrote down for a product, and records what it was asked.
 */
final class Fake_Article_Preview implements Article_Preview {
	/**
	 * Article by product or variation id; an id without one answers ''.
	 *
	 * @var array<int,string>
	 */
	public $articles = [];

	/**
	 * Every question: `[ product id, mapping id, profile name ]`.
	 *
	 * @var array<int,array{0:int,1:int,2:string}>
	 */
	public $asked = [];

	/**
	 * The mappings the questions came with, in order.
	 *
	 * @var Mapping[]
	 */
	public $mappings = [];

	/**
	 * Mapping id => text put before the article of a product sent by that mapping.
	 *
	 * @var array<int,string>
	 */
	public $prefixes = [];

	/**
	 * Answers instead of the table when a test sets it: `function ( \WC_Product, Mapping, Profile ): string`.
	 *
	 * @var callable|null
	 */
	public $answer;

	/**
	 * Returns the article.
	 *
	 * @param \WC_Product $product Product.
	 * @param Mapping     $mapping Mapping.
	 * @param Profile     $profile Profile.
	 * @return string
	 */
	public function preview_article( \WC_Product $product, Mapping $mapping, Profile $profile ): string {
		$this->asked[]    = [ $product->get_id(), (int) $mapping->id(), $profile->name() ];
		$this->mappings[] = $mapping;

		if ( null !== $this->answer ) {
			return (string) call_user_func( $this->answer, $product, $mapping, $profile );
		}

		return ( $this->prefixes[ (int) $mapping->id() ] ?? '' ) . ( $this->articles[ $product->get_id() ] ?? '' );
	}
}

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Article_Preview
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Controller
 */
final class MappingArticlePreviewTest extends TestCase {
	/**
	 * Harness.
	 *
	 * @var Mappings_Harness
	 */
	private $h;

	/**
	 * The adapter's answer.
	 *
	 * @var Fake_Article_Preview
	 */
	private $articles;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Shop products by id.
	 *
	 * @var array<int,\WC_Product>
	 */
	private $shop = [];

	/**
	 * Task ledger.
	 *
	 * @var Wpdb_Task_Ledger
	 */
	private $ledger;

	/**
	 * Parent of each variation.
	 *
	 * @var array<int,int>
	 */
	private $parents = [];

	/**
	 * The id lists whose posts were read at once, in order.
	 *
	 * @var array<int,int[]>
	 */
	private $primed = [];

	/**
	 * Ids of products that become no card.
	 *
	 * @var int[]
	 */
	private $not_exportable = [];

	/**
	 * Mapping of the shop category 5.
	 *
	 * @var int
	 */
	private $mapping_id;

	/**
	 * Enabled accounts.
	 *
	 * @var Account[]
	 */
	private $accounts = [];

	/**
	 * Article typed at a product, by `{product id}:{account id}`.
	 *
	 * @var array<string,string>
	 */
	private $typed = [];

	/**
	 * Shop category of each product or variation; the mapping's own when not set.
	 *
	 * @var array<int,int>
	 */
	private $categories = [];

	/**
	 * What the export does with the article it is given: the typed one is taken unless a test says otherwise.
	 *
	 * @var callable|null
	 */
	private $offer_id_for;

	/**
	 * Builds a shop with one mapping.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_Query::$ids     = [];
		\WP_Query::$queries = [];
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_term_children' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key ) {
				$this->assertContains( (int) $id, array_merge( ...$this->primed ), 'a product whose meta was not read at once costs a read of its own' );

				return $this->typed_meta( (int) $id, $key );
			}
		);
		Functions\when( '_prime_post_caches' )->alias(
			function ( array $ids ): void {
				$this->primed[] = array_values( $ids );
			}
		);
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				return $this->shop[ (int) $id ] ?? false;
			}
		);
		Functions\when( 'wc_get_formatted_variation' )->alias(
			static function ( $variation ) {
				return $variation->get_name();
			}
		);
		Functions\when( 'get_edit_post_link' )->alias(
			static function ( $id ) {
				return 'https://shop.test/edit/' . $id;
			}
		);

		$this->h          = new Mappings_Harness();
		$this->articles   = new Fake_Article_Preview();
		$this->links      = new Wpdb_Link_Store( $this->h->db, Sqlite_Tables::table( 'links' ), Sqlite_Tables::table( 'link_ids' ) );
		$this->ledger     = new Wpdb_Task_Ledger( $this->h->db, Sqlite_Tables::table( 'tasks' ), Sqlite_Tables::table( 'task_items' ) );
		$this->mapping_id = $this->h->mappings->create( 'Футболки', null, [], null, [] );
		$this->h->mappings->link_term_if_absent( 5, $this->mapping_id );
		$this->accounts = [ self::account( 1, 'Основной' ) ];
	}

	/**
	 * Builds an account.
	 *
	 * @param int    $id   Id.
	 * @param string $name Name.
	 * @return Account
	 */
	private static function account( int $id, string $name ): Account {
		return new Account( $id, 'fake', $name, true );
	}

	/**
	 * Adds a product to the shop and to the mapping.
	 *
	 * @param int    $id       Product id.
	 * @param string $name     Name.
	 * @param int[]  $children Variation ids; a product with any is variable. Each gets the name «Вариация {id}».
	 * @return void
	 */
	private function product( int $id, string $name, array $children = [] ): void {
		\WP_Query::$ids[]  = $id;
		$this->shop[ $id ] = $this->wc_product( $id, $name, [] === $children ? 'simple' : 'variable', $children );

		foreach ( $children as $child_id ) {
			$this->parents[ $child_id ] = $id;
			$this->shop[ $child_id ]    = $this->wc_product( $child_id, 'Вариация ' . $child_id, 'variation', [] );
		}
	}

	/**
	 * Builds a product double.
	 *
	 * @param int    $id       Product id.
	 * @param string $name     Name.
	 * @param string $type     Product type.
	 * @param int[]  $children Variation ids.
	 * @return \WC_Product
	 */
	private function wc_product( int $id, string $name, string $type, array $children ): \WC_Product {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'get_name' )->andReturn( $name );
		$product->shouldReceive( 'get_parent_id' )->andReturnUsing(
			function () use ( $id ) {
				return $this->parents[ $id ] ?? 0;
			}
		);
		// The export reads a typed article from the product; the preview from the primed post meta, the same stored value.
		$product->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( $key ) use ( $id ) {
				return $this->typed_meta( $id, $key );
			}
		);
		$product->shouldReceive( 'get_children' )->andReturn( $children );
		$product->shouldReceive( 'is_type' )->andReturnUsing(
			static function ( $asked ) use ( $type ) {
				return $asked === $type;
			}
		);

		return $product;
	}

	/**
	 * The stored typed article of a product, by its meta key.
	 *
	 * @param int    $id  Product or variation.
	 * @param string $key Meta key, which ends with the account id.
	 * @return string
	 */
	private function typed_meta( int $id, string $key ): string {
		return $this->typed[ $id . ':' . substr( $key, strrpos( $key, '_' ) + 1 ) ] ?? '';
	}

	/**
	 * Stores a link of a product or variation.
	 *
	 * @param int    $product_id Product or variation.
	 * @param int    $parent_id  Parent, 0 for a product.
	 * @param string              $state      Card state.
	 * @param array<string,mixed> $columns    Any other column of the link.
	 * @return int Link id.
	 */
	private function link( int $product_id, int $parent_id, string $state, array $columns = [] ): int {
		$this->links->insert_if_absent( Link_Record::offer( 1, $product_id, $parent_id, 'key-' . $product_id, [ 'card_state' => $state ] + $columns ) );

		return (int) $this->links->find( 1, $product_id )->link_id();
	}

	/**
	 * Builds the marketplace's facts.
	 *
	 * @return Export_Facts
	 */
	private function facts(): Export_Facts {
		$facts = Mockery::mock( Export_Facts::class );
		$facts->shouldReceive( 'is_exportable' )->andReturnUsing(
			function ( $product ) {
				return ! in_array( $product->get_id(), $this->not_exportable, true );
			}
		);
		$facts->shouldReceive( 'offer_id_for' )->andReturnUsing(
			$this->offer_id_for ?? static function ( $frozen ) {
				return (string) $frozen;
			}
		);

		return $facts;
	}

	/**
	 * The category terms of a product, as the shop has them.
	 *
	 * @return callable
	 */
	private function term_ids(): callable {
		return function ( $product ): array {
			return [ $this->categories[ $product->get_id() ] ?? 5 ];
		};
	}

	/**
	 * Builds the preview.
	 *
	 * @param Sellable_Units|null $units Unit rules of the marketplace.
	 * @return Mapping_Article_Preview
	 */
	private function preview( ?Sellable_Units $units = null ): Mapping_Article_Preview {
		return new Mapping_Article_Preview(
			$this->articles,
			$this->facts(),
			$this->h->mappings,
			$this->h->profiles,
			new Mapping_Products( [ $this->h->mappings, 'terms_by_mapping' ] ),
			$this->links,
			$this->ledger,
			new Pinned_Offer_Id( 'fake', new Fake_Article_Policy() ),
			function (): array {
				return $this->accounts;
			},
			$units,
			$this->term_ids()
		);
	}

	/**
	 * Records that a request carried a link's article.
	 *
	 * @param int $link_id Link id.
	 * @return void
	 */
	private function mark_sent( int $link_id ): void {
		$task_id = $this->ledger->open( new Async_Task( 1, Async_Task::KIND_IMPORT ), [ $link_id => 'sent-' . $link_id ] );
		$this->ledger->mark_sending( $task_id, 'request-' . $task_id, [ $link_id ] );
	}

	/**
	 * Reads the whole mapping, one request after another.
	 *
	 * @return array{requests:int,products:array<int,array<string,mixed>>,statements:int}
	 *         The requests made, every product listed by them and the most SQL statements one of them ran.
	 */
	private function read_all(): array {
		$preview    = $this->preview();
		$next       = [
			'offset' => 0,
			'skip'   => 0,
		];
		$requests   = 0;
		$products   = [];
		$statements = 0;

		while ( null !== $next && $requests < 100 ) {
			$this->h->db->statements = [];
			$answer                  = $preview->read( $this->mapping_id, $next['offset'], $next['skip'] );
			$statements              = max( $statements, count( $this->h->db->statements ) );
			$products                = array_merge( $products, $answer['products'] );
			$next                    = $answer['next'];
			++$requests;
		}

		return [
			'requests'   => $requests,
			'products'   => $products,
			'statements' => $statements,
		];
	}

	/**
	 * Variations of one article share a line; a simple product has no variation names.
	 *
	 * @return void
	 */
	public function test_variations_of_one_article_share_a_line(): void {
		$this->product( 10, 'Футболка', [ 11, 12, 13 ] );
		$this->product( 20, 'Кепка' );
		$this->articles->articles = [
			11 => 'tee-red',
			12 => 'tee-red',
			13 => 'tee-blue',
			20 => 'cap',
		];

		$answer = $this->preview()->read( $this->mapping_id );

		$this->assertSame(
			[
				[
					'id'       => 10,
					'name'     => 'Футболка',
					'url'      => 'https://shop.test/edit/10',
					'articles' => [
						[
							'article'    => 'tee-red',
							'note'       => '',
							'variations' => 'Вариация 11; Вариация 12',
						],
						[
							'article'    => 'tee-blue',
							'note'       => '',
							'variations' => 'Вариация 13',
						],
					],
				],
				[
					'id'       => 20,
					'name'     => 'Кепка',
					'url'      => 'https://shop.test/edit/20',
					'articles' => [
						[
							'article'    => 'cap',
							'note'       => '',
							'variations' => '',
						],
					],
				],
			],
			$answer['products']
		);
		$this->assertNull( $answer['next'] );
		$this->assertSame( [ 11, $this->mapping_id, '' ], $this->articles->asked[0], 'a mapping without a profile gives an empty one' );
	}

	/**
	 * Names reach the view as text: it escapes them itself.
	 *
	 * @return void
	 */
	public function test_names_are_given_as_plain_text(): void {
		$this->product( 20, 'Носки &amp; гольфы' );

		$this->assertSame( 'Носки & гольфы', $this->preview()->read( $this->mapping_id )['products'][0]['name'] );
	}

	/**
	 * A numeric article stays a text, and an article the adapter cannot work out is passed on empty.
	 *
	 * @return void
	 */
	public function test_a_numeric_article_stays_a_text_and_an_unknown_one_is_empty(): void {
		$this->product( 20, 'Кепка' );
		$this->product( 21, 'Без артикула' );
		$this->articles->articles = [ 20 => '12345' ];

		$products = $this->preview()->read( $this->mapping_id )['products'];

		$this->assertSame( '12345', $products[0]['articles'][0]['article'] );
		$this->assertSame( '', $products[1]['articles'][0]['article'] );
	}

	/**
	 * A unit whose article is kept — by the state of its link, or because a request already carried it — is not asked about, a
	 * unit with a link that was never sent is, and a product with nothing left is not listed.
	 *
	 * @return void
	 */
	public function test_units_with_a_card_are_left_out(): void {
		$this->product( 10, 'Футболка', [ 11, 12, 13 ] );
		$this->product( 20, 'Кепка' );
		$this->link( 11, 10, Link_Record::STATE_SUBMITTED );
		$this->mark_sent( $this->link( 12, 10, Link_Record::STATE_UNLINKED ) );
		$this->link( 13, 10, Link_Record::STATE_UNLINKED );
		$this->link( 20, 0, Link_Record::STATE_SUBMITTED );

		$products = $this->preview()->read( $this->mapping_id )['products'];

		$this->assertSame( [ 10 ], array_column( $products, 'id' ) );
		$this->assertSame( [ 13 ], array_column( $this->articles->asked, 0 ) );
	}

	/**
	 * An article typed at a product wins over the mapping's, and the line says so; the other variations are not touched.
	 *
	 * @return void
	 */
	public function test_an_article_typed_at_the_product_is_shown_with_the_reason(): void {
		$this->product( 10, 'Футболка', [ 11, 12 ] );
		$this->articles->articles = [
			11 => 'tee-red',
			12 => 'tee-red',
		];
		$this->typed              = [ '12:1' => 'MY-OWN' ];

		$this->assertSame(
			[
				[
					'article'    => 'tee-red',
					'note'       => '',
					'variations' => 'Вариация 11',
				],
				[
					'article'    => 'MY-OWN',
					'note'       => 'Артикул вписан в самом товаре',
					'variations' => 'Вариация 12',
				],
			],
			$this->preview()->read( $this->mapping_id )['products'][0]['articles']
		);
	}

	/**
	 * A marketplace of units reads a typed key the way the export does, and shows its article.
	 *
	 * @return void
	 */
	public function test_a_typed_key_of_units_is_shown_as_its_article(): void {
		$this->product( 20, 'Кепка' );
		$this->articles->articles = [ 20 => 'cap' ];
		$this->typed              = [ '20:1' => 'own|M' ];
		$units                    = Mockery::mock( Sellable_Units::class );
		$units->shouldReceive( 'shown_article' )->with( 'own|M' )->andReturn( new Shown_Article( 'own', 'M' ) );

		$line = $this->preview( $units )->read( $this->mapping_id )['products'][0]['articles'][0];

		$this->assertSame( 'own', $line['article'] );
		$this->assertSame( 'Артикул вписан в самом товаре', $line['note'] );
	}

	/**
	 * An export that refuses what was typed and sends its own article shows that article, with nothing to say.
	 *
	 * @return void
	 */
	public function test_a_typed_article_the_export_refuses_is_not_shown(): void {
		$this->product( 20, 'Кепка' );
		$this->articles->articles = [ 20 => 'cap' ];
		$this->typed              = [ '20:1' => 'not a key' ];
		$this->offer_id_for       = static function () {
			return 'cap';
		};

		$this->assertSame(
			[
				[
					'article'    => 'cap',
					'note'       => '',
					'variations' => '',
				],
			],
			$this->preview()->read( $this->mapping_id )['products'][0]['articles']
		);
	}

	/**
	 * Accounts that agree make one line; accounts that disagree get a line each, naming them.
	 *
	 * @return void
	 */
	public function test_accounts_that_disagree_get_a_line_each(): void {
		$this->accounts = [ self::account( 1, 'Основной' ), self::account( 2, 'Второй' ), self::account( 3, 'Третий' ) ];
		$this->product( 20, 'Кепка' );
		$this->articles->articles = [ 20 => 'cap' ];

		$agreed = $this->preview()->read( $this->mapping_id )['products'][0]['articles'];

		$this->typed = [ '20:2' => 'CAP-2' ];
		$split       = $this->preview()->read( $this->mapping_id )['products'][0]['articles'];

		$this->assertCount( 1, $agreed );
		$this->assertSame( '', $agreed[0]['note'] );
		$this->assertSame( [ 'cap', 'CAP-2' ], array_column( $split, 'article' ) );
		$this->assertSame( [ 'Для: «Основной», «Третий»', 'Для: «Второй». Артикул вписан в самом товаре' ], array_column( $split, 'note' ) );
	}

	/**
	 * A product that names another mapping is asked about with that one and its profile, and the line names it. A variation follows
	 * the mapping its parent names.
	 *
	 * @return void
	 */
	public function test_a_mapping_chosen_at_the_product_wins(): void {
		$profile_id = $this->h->profiles->create( 'Особый', [] );
		$other      = $this->h->mappings->create( 'Шапки', null, [], $profile_id, [] );
		$this->product( 10, 'Футболка', [ 11 ] );
		$this->product( 20, 'Кепка' );
		$this->links->insert_if_absent( Link_Record::parent_row( 1, 10, [ 'mapping_id' => $other ] ) );
		$this->link( 20, 0, Link_Record::STATE_UNLINKED, [ 'mapping_id' => $other ] );
		$this->articles->articles = [
			11 => 'tee',
			20 => 'cap',
		];
		$this->articles->prefixes = [ $other => 'hat-' ];

		$products = $this->preview()->read( $this->mapping_id )['products'];

		$this->assertSame( 'hat-tee', $products[0]['articles'][0]['article'] );
		$this->assertSame( 'hat-cap', $products[1]['articles'][0]['article'] );
		$this->assertSame( 'Товар выгружается по другому сопоставлению: «Шапки»', $products[1]['articles'][0]['note'] );
		$this->assertSame( [ 20, $other, 'Особый' ], $this->articles->asked[1] );
	}

	/**
	 * The mapping of a nearer category is the product's, as the export resolves it.
	 *
	 * @return void
	 */
	public function test_the_mapping_of_the_products_category_wins(): void {
		$other = $this->h->mappings->create( 'Шапки', null, [], null, [] );
		$this->h->mappings->link_term_if_absent( 9, $other );
		$this->product( 20, 'Кепка' );
		$this->categories         = [ 20 => 9 ];
		$this->articles->articles = [ 20 => 'cap' ];

		$line = $this->preview()->read( $this->mapping_id )['products'][0]['articles'][0];

		$this->assertSame( $other, $this->articles->asked[0][1] );
		$this->assertSame( 'Товар выгружается по другому сопоставлению: «Шапки»', $line['note'] );
	}

	/**
	 * A value set at the product reaches the adapter laid over the mapping.
	 *
	 * @return void
	 */
	public function test_values_set_at_the_product_reach_the_adapter(): void {
		$this->product( 20, 'Кепка' );
		$this->link( 20, 0, Link_Record::STATE_UNLINKED, [ 'attribute_overrides' => wp_json_encode( [ '7' => [ [ 'value' => 'XL' ] ] ] ) ] );

		$this->preview()->read( $this->mapping_id );

		$this->assertSame( 'XL', $this->articles->mappings[0]->source_for( '7' )->value() );
	}

	/**
	 * A product that names a mapping which is gone gets no article: nothing would be sent.
	 *
	 * @return void
	 */
	public function test_a_product_with_a_mapping_that_is_gone_shows_no_article(): void {
		$this->product( 20, 'Кепка' );
		$this->articles->articles = [ 20 => 'cap' ];
		$this->link( 20, 0, Link_Record::STATE_UNLINKED, [ 'mapping_id' => 404 ] );

		$line = $this->preview()->read( $this->mapping_id )['products'][0]['articles'][0];

		$this->assertSame( '', $line['article'] );
		$this->assertSame( 'Сопоставление, выбранное в товаре, не найдено', $line['note'] );
		$this->assertSame( [], $this->articles->asked );
	}

	/**
	 * Without an enabled account no product has settings of its own, and the mapping on the screen answers.
	 *
	 * @return void
	 */
	public function test_without_an_account_the_mapping_on_the_screen_answers(): void {
		$this->accounts = [];
		$this->product( 20, 'Кепка' );
		$this->articles->articles = [ 20 => 'cap' ];
		$this->typed              = [ '20:1' => 'MY-OWN' ];

		$line = $this->preview()->read( $this->mapping_id )['products'][0]['articles'][0];

		$this->assertSame( [ 'cap', '' ], [ $line['article'], $line['note'] ] );
		$this->assertSame( $this->mapping_id, $this->articles->asked[0][1] );
	}

	/**
	 * What becomes no card is not asked about.
	 *
	 * @return void
	 */
	public function test_what_becomes_no_card_is_left_out(): void {
		$this->product( 20, 'Файл' );
		$this->not_exportable = [ 20 ];

		$this->assertSame( [], $this->preview()->read( $this->mapping_id )['products'] );
		$this->assertSame( [], $this->articles->asked );
	}

	/**
	 * A variable product without variations makes no card: it is not listed and its own SKU is never shown as an article.
	 *
	 * @return void
	 */
	public function test_a_variable_product_without_variations_shows_no_article(): void {
		\WP_Query::$ids[]  = 10;
		$this->shop[ 10 ]  = $this->wc_product( 10, 'Без вариаций', 'variable', [] );
		$this->articles->articles = [ 10 => 'PARENT-SKU' ];
		$this->product( 20, 'Кепка' );

		$answer = $this->preview()->read( $this->mapping_id );

		$this->assertSame( [ 20 ], array_column( $answer['products'], 'id' ) );
		$this->assertSame( [ 20 ], array_column( $this->articles->asked, 0 ) );
		$this->assertNull( $answer['next'] );
	}

	/**
	 * One answer lists a page of products and says where the next one starts.
	 *
	 * @return void
	 */
	public function test_the_next_products_are_read_from_where_the_page_ended(): void {
		for ( $id = 1; $id <= Mapping_Article_Preview::PRODUCTS + 3; $id++ ) {
			$this->product( $id, 'Товар ' . $id );
		}

		$first = $this->preview()->read( $this->mapping_id );
		$rest  = $this->preview()->read( $this->mapping_id, $first['next']['offset'], $first['next']['skip'] );

		$this->assertCount( Mapping_Article_Preview::PRODUCTS, $first['products'] );
		$this->assertSame(
			[
				'offset' => Mapping_Article_Preview::PRODUCTS,
				'skip'   => 0,
			],
			$first['next']
		);
		$this->assertSame( [ 21, 22, 23 ], array_column( $rest['products'], 'id' ) );
		$this->assertNull( $rest['next'] );
	}

	/**
	 * The units of one request are bounded across products: the product that does not fit continues in the next request, and every
	 * variation is asked about exactly once.
	 *
	 * @return void
	 */
	public function test_a_product_whose_variations_do_not_fit_continues_in_the_next_request(): void {
		$this->product( 1, 'Первый', range( 1001, 1060 ) );
		$this->product( 2, 'Второй', range( 2001, 2060 ) );

		$first = $this->preview()->read( $this->mapping_id );
		$asked = array_column( $this->articles->asked, 0 );
		$rest  = $this->preview()->read( $this->mapping_id, $first['next']['offset'], $first['next']['skip'] );

		$this->assertSame( array_merge( range( 1001, 1060 ), range( 2001, 2040 ) ), $asked );
		$this->assertSame(
			[
				'offset' => 1,
				'skip'   => 40,
			],
			$first['next']
		);
		$this->assertSame( [ 2 ], array_column( $rest['products'], 'id' ) );
		$this->assertSame( range( 2041, 2060 ), array_slice( array_column( $this->articles->asked, 0 ), count( $asked ) ) );
		$this->assertNull( $rest['next'] );
	}

	/**
	 * A product with hundreds of variations and two accounts: each request reads a bounded number of them and runs the same few
	 * statements, with no link rows or with a row for every variation on every account.
	 *
	 * @dataProvider provide_link_rows
	 *
	 * @param bool $with_rows Whether every variation has a link row on both accounts, some of them sent.
	 * @return void
	 */
	public function test_a_large_product_costs_a_bounded_number_of_reads( bool $with_rows ): void {
		$this->accounts = [ self::account( 1, 'Основной' ), self::account( 2, 'Второй' ) ];
		$variations     = range( 1001, 1500 );
		$this->product( 10, 'Футболка', $variations );
		$expected = $variations;

		if ( $with_rows ) {
			foreach ( $variations as $variation_id ) {
				$this->link( $variation_id, 10, Link_Record::STATE_UNLINKED );
				$this->links->insert_if_absent( Link_Record::offer( 2, $variation_id, 10, 'second-' . $variation_id, [ 'card_state' => Link_Record::STATE_UNLINKED ] ) );
			}

			$this->mark_sent( (int) $this->links->find( 1, 1001 )->link_id() );
			$expected = array_slice( $variations, 1 );
		}

		$this->primed            = [];
		$this->h->db->statements = [];
		$first                   = $this->preview()->read( $this->mapping_id );

		$this->assertLessThanOrEqual( 12, count( $this->h->db->statements ) );
		$this->assertLessThanOrEqual( Mapping_Article_Preview::UNITS, count( $this->articles->asked ), 'one question per variation, for two accounts that agree' );
		$this->assertLessThanOrEqual( Mapping_Article_Preview::UNITS, max( array_map( 'count', $this->primed ) ) );
		$this->assertSame(
			[
				'offset' => 0,
				'skip'   => Mapping_Article_Preview::UNITS,
			],
			$first['next']
		);

		$this->articles->asked = [];
		$all                   = $this->read_all();

		$this->assertSame( 5, $all['requests'] );
		$this->assertLessThanOrEqual( 12, $all['statements'] );
		$this->assertSame( $expected, array_column( $this->articles->asked, 0 ) );
	}

	/**
	 * Without and with link rows.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public function provide_link_rows(): array {
		return [
			'no link rows'            => [ false ],
			'a row for every variation' => [ true ],
		];
	}

	/**
	 * Variations that each hold their own values and name a mapping cost no read of their own: each mapping is read once.
	 *
	 * @return void
	 */
	public function test_values_and_mappings_set_at_each_variation_cost_no_reads_of_their_own(): void {
		$this->accounts = [ self::account( 1, 'Основной' ), self::account( 2, 'Второй' ) ];
		$profile_id     = $this->h->profiles->create( 'Особый', [] );
		$other          = $this->h->mappings->create( 'Шапки', null, [], $profile_id, [] );
		$variations     = range( 1001, 1000 + Mapping_Article_Preview::UNITS );
		$this->product( 10, 'Футболка', $variations );

		foreach ( $variations as $variation_id ) {
			$this->link(
				$variation_id,
				10,
				Link_Record::STATE_UNLINKED,
				[
					'mapping_id'          => $other,
					'attribute_overrides' => wp_json_encode( [ '7' => [ [ 'value' => 'v' . $variation_id ] ] ] ),
				]
			);
		}

		$this->h->db->statements = [];
		$this->preview()->read( $this->mapping_id );

		$this->assertLessThanOrEqual( 14, count( $this->h->db->statements ) );

		$seen = [];

		foreach ( $this->articles->mappings as $position => $mapping ) {
			$source = $mapping->source_for( '7' );

			if ( null !== $source ) {
				$seen[ $this->articles->asked[ $position ][0] ] = $source->value();
			}
		}

		$this->assertCount( Mapping_Article_Preview::UNITS, $seen );
		$this->assertSame( 'v1042', $seen[1042] );
	}

	/**
	 * A large catalogue that already has its cards: one request reads a bounded number of products, lists none and says where to go
	 * on, so nothing reads the whole shop by itself.
	 *
	 * @return void
	 */
	public function test_one_request_over_a_large_exported_catalogue_is_bounded(): void {
		for ( $id = 1; $id <= 450; $id++ ) {
			$this->product( $id, 'Товар ' . $id );
			$this->link( $id, 0, Link_Record::STATE_SUBMITTED );
		}

		$loaded = [];
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( &$loaded ) {
				$loaded[] = (int) $id;

				return $this->shop[ (int) $id ] ?? false;
			}
		);
		$this->h->db->statements = [];
		$first                   = $this->preview()->read( $this->mapping_id );

		$this->assertSame( [], $first['products'] );
		$this->assertSame( range( 1, Mapping_Article_Preview::UNITS ), $loaded );
		$this->assertLessThanOrEqual( 12, count( $this->h->db->statements ) );
		$this->assertSame(
			[
				'offset' => Mapping_Article_Preview::UNITS,
				'skip'   => 0,
			],
			$first['next']
		);

		$all = $this->read_all();

		$this->assertSame( 5, $all['requests'] );
		$this->assertSame( [], $all['products'] );
		$this->assertLessThanOrEqual( 12, $all['statements'] );
	}

	/**
	 * Products that share a category and have no settings of their own resolve one mapping, however many they are.
	 *
	 * @return void
	 */
	public function test_the_reads_do_not_grow_with_the_products(): void {
		$this->product( 1, 'Товар 1' );
		$this->h->db->statements = [];
		$this->preview()->read( $this->mapping_id );
		$one = count( $this->h->db->statements );

		for ( $id = 2; $id <= Mapping_Article_Preview::PRODUCTS; $id++ ) {
			$this->product( $id, 'Товар ' . $id );
		}

		$this->h->db->statements = [];
		$this->preview()->read( $this->mapping_id );

		$this->assertCount( $one, $this->h->db->statements );
	}

	/**
	 * The preview resolves a unit for an account as the export does: the articles it shows are the ones the export builds.
	 *
	 * @return void
	 */
	public function test_the_preview_agrees_with_the_export(): void {
		$this->accounts = [ self::account( 1, 'Основной' ), self::account( 2, 'Второй' ) ];
		$profile_id     = $this->h->profiles->create( 'Особый', [] );
		$other          = $this->h->mappings->create( 'Шапки', null, [], $profile_id, [] );
		$this->h->mappings->link_term_if_absent( 9, $this->h->mappings->create( 'Носки', null, [], null, [] ) );
		$this->product( 10, 'Футболка', [ 11, 12 ] );
		$this->product( 20, 'Кепка' );
		$this->product( 30, 'Носки' );
		$this->links->insert_if_absent( Link_Record::parent_row( 2, 10, [ 'mapping_id' => $other ] ) );
		$this->link( 20, 0, Link_Record::STATE_UNLINKED, [ 'mapping_id' => $other ] );
		$this->categories = [ 30 => 9 ];
		$this->typed      = [ '12:1' => 'TYPED' ];

		$by_profile             = static function ( $product, Profile $profile ): string {
			return $profile->name() . '-' . $product->get_id();
		};
		$this->offer_id_for     = static function ( $frozen, $product, Profile $profile ) use ( $by_profile ): string {
			return $frozen ?? $by_profile( $product, $profile );
		};
		$this->articles->answer = static function ( $product, Mapping $mapping, Profile $profile ) use ( $by_profile ): string {
			return $by_profile( $product, $profile );
		};

		$contexts = new Export_Context_Builder(
			new Link_Context_Resolver( $this->links, $this->h->mappings ),
			$this->h->profiles,
			$this->links,
			$this->facts(),
			new Fake_Article_Policy(),
			new Pinned_Offer_Id( 'fake', new Fake_Article_Policy() ),
			null,
			$this->term_ids(),
			null,
			$this->ledger
		);
		$sent     = [];

		foreach ( [ 11, 12, 20, 30 ] as $unit_id ) {
			foreach ( $this->accounts as $account ) {
				$link   = $this->links->find( $account->id(), $unit_id ) ?? Link_Record::offer( $account->id(), $unit_id, $this->parents[ $unit_id ] ?? 0, '' );
				$sent[] = $contexts->offer_id( $account, $this->shop[ $unit_id ], $link );
			}
		}

		$shown = [];

		foreach ( $this->preview()->read( $this->mapping_id )['products'] as $product ) {
			$shown = array_merge( $shown, array_column( $product['articles'], 'article' ) );
		}

		$sent  = array_values( array_unique( $sent ) );
		$shown = array_values( array_unique( $shown ) );
		sort( $sent );
		sort( $shown );

		$this->assertSame( $sent, $shown );
		$this->assertContains( 'TYPED', $shown );
		$this->assertContains( 'Особый-20', $shown );
	}

	/**
	 * The preview is made with the profile the saved mapping chose, and a mapping that does not exist answers null.
	 *
	 * @return void
	 */
	public function test_the_profile_of_the_mapping_is_passed_on(): void {
		$profile_id = $this->h->profiles->create( 'Одежда', [] );
		$mapping_id = $this->h->mappings->create( 'Платья', null, [], $profile_id, [] );
		$this->h->mappings->link_term_if_absent( 8, $mapping_id );
		$this->product( 20, 'Платье' );
		$this->categories = [ 20 => 8 ];

		$this->preview()->read( $mapping_id );

		$this->assertSame( [ 20, $mapping_id, 'Одежда' ], $this->articles->asked[0] );
		$this->assertNull( $this->preview()->read( 404 ) );
	}

	/**
	 * Only a controller with a preview registers the route and tells the view about it.
	 *
	 * @return void
	 */
	public function test_the_route_and_the_view_flag_exist_only_with_a_preview(): void {
		require_once dirname( __DIR__, 5 ) . '/woodev/rest-api/class-rest-v1-registrar.php';

		$routes = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $pattern ) use ( &$routes ): void {
				$routes[] = $pattern;
			}
		);

		$this->controller( $this->preview() )->register_routes();

		$this->assertContains( '/admin/marketplace/fake-plugin/mappings/mappings/(?P<id>[1-9][0-9]{0,18})/articles', $routes );

		$routes = [];
		$this->h->controller->register_routes();

		$this->assertSame( [], preg_grep( '~/articles$~', $routes ) );
	}

	/**
	 * The route answers the products of the mapping, and 404 for a mapping that does not exist.
	 *
	 * @return void
	 */
	public function test_the_route_answers_the_products_of_the_mapping(): void {
		$this->product( 20, 'Кепка' );
		$this->articles->articles = [ 20 => 'cap' ];
		$controller               = $this->controller( $this->preview() );

		$answer  = $controller->get_articles( $this->request( $this->mapping_id ) );
		$missing = $controller->get_articles( $this->request( 404 ) );

		$this->assertSame( 'cap', $answer->get_data()['products'][0]['articles'][0]['article'] );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}

	/**
	 * The route reads from where the read before it ended: the product and the variation.
	 *
	 * @return void
	 */
	public function test_the_route_reads_from_the_cursor(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		$this->product( 5, 'Первый' );
		$this->product( 10, 'Футболка', [ 11, 12 ] );
		$request = $this->request(
			$this->mapping_id,
			[
				'offset' => '1',
				'skip'   => '1',
			]
		);

		$data = $this->controller( $this->preview() )->get_articles( $request )->get_data();

		$this->assertSame( [ 10 ], array_column( $data['products'], 'id' ) );
		$this->assertSame( [ 12 ], array_column( $this->articles->asked, 0 ) );
		$this->assertNull( $data['next'] );
	}

	/**
	 * Builds a controller with a preview.
	 *
	 * @param Mapping_Article_Preview $preview Preview.
	 * @return Mappings_Controller
	 */
	private function controller( Mapping_Article_Preview $preview ): Mappings_Controller {
		return new Mappings_Controller( 'fake-plugin', 'manage_woocommerce', $this->h->editor, $this->h->profile_editor, $this->h->check, $this->h->finder, Profile_Choices::standard(), null, [], $preview );
	}

	/**
	 * Builds the request of a mapping's articles.
	 *
	 * @param int                  $mapping_id Mapping.
	 * @param array<string,string> $query      Query parameters.
	 * @return \WP_REST_Request
	 */
	private function request( int $mapping_id, array $query = [] ): \WP_REST_Request {
		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_query_params' )->andReturn( $query );
		$request->shouldReceive( 'get_url_params' )->andReturn( [ 'id' => (string) $mapping_id ] );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( $name ) use ( $mapping_id ) {
				return 'id' === $name ? (string) $mapping_id : null;
			}
		);

		return $request;
	}
}
