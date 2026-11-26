<?php
/**
 * The article preview of the mapping form.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Article_Preview;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Products;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Controller;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Choices;
use Woodev\Framework\Marketplace\Catalog\Article_Preview;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Export_Facts;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
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
	 * Returns the article.
	 *
	 * @param \WC_Product $product Product.
	 * @param Mapping     $mapping Mapping.
	 * @param Profile     $profile Profile.
	 * @return string
	 */
	public function preview_article( \WC_Product $product, Mapping $mapping, Profile $profile ): string {
		$this->asked[] = [ $product->get_id(), (int) $mapping->id(), $profile->name() ];

		return $this->articles[ $product->get_id() ] ?? '';
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
	 * Article already sent, by link id.
	 *
	 * @var array<int,string>
	 */
	private $sent = [];

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
		$this->mapping_id = $this->h->mappings->create( 'Футболки', null, [], null, [] );
		$this->h->mappings->link_term_if_absent( 5, $this->mapping_id );
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
			$this->shop[ $child_id ] = $this->wc_product( $child_id, 'Вариация ' . $child_id, 'variation', [] );
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
		$product->shouldReceive( 'get_children' )->andReturn( $children );
		$product->shouldReceive( 'is_type' )->andReturnUsing(
			static function ( $asked ) use ( $type ) {
				return $asked === $type;
			}
		);

		return $product;
	}

	/**
	 * Stores a link of a product or variation.
	 *
	 * @param int    $product_id Product or variation.
	 * @param int    $parent_id  Parent, 0 for a product.
	 * @param string $state      Card state.
	 * @return int Link id.
	 */
	private function link( int $product_id, int $parent_id, string $state ): int {
		$this->links->insert_if_absent( Link_Record::offer( 1, $product_id, $parent_id, 'key-' . $product_id, [ 'card_state' => $state ] ) );

		return (int) $this->links->find( 1, $product_id )->link_id();
	}

	/**
	 * Builds the preview.
	 *
	 * @return Mapping_Article_Preview
	 */
	private function preview(): Mapping_Article_Preview {
		$ledger = Mockery::mock( Task_Ledger::class );
		$ledger->shouldReceive( 'sent_offer_id' )->andReturnUsing(
			function ( $link_id ) {
				return $this->sent[ $link_id ] ?? null;
			}
		);
		$facts = Mockery::mock( Export_Facts::class );
		$facts->shouldReceive( 'is_exportable' )->andReturnUsing(
			function ( $product ) {
				return ! in_array( $product->get_id(), $this->not_exportable, true );
			}
		);

		return new Mapping_Article_Preview(
			$this->articles,
			$this->h->mappings,
			$this->h->profiles,
			new Mapping_Products( [ $this->h->mappings, 'terms_by_mapping' ] ),
			$this->links,
			$ledger,
			$facts
		);
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
							'variations' => 'Вариация 11; Вариация 12',
						],
						[
							'article'    => 'tee-blue',
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
							'variations' => '',
						],
					],
				],
			],
			$answer['products']
		);
		$this->assertSame( 0, $answer['next_offset'] );
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
		$this->sent[ $this->link( 12, 10, Link_Record::STATE_UNLINKED ) ] = 'key-12';
		$this->link( 13, 10, Link_Record::STATE_UNLINKED );
		$this->link( 20, 0, Link_Record::STATE_SUBMITTED );

		$products = $this->preview()->read( $this->mapping_id )['products'];

		$this->assertSame( [ 10 ], array_column( $products, 'id' ) );
		$this->assertSame( [ 13 ], array_column( $this->articles->asked, 0 ) );
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
	 * One answer lists a page of products and says where the next one starts.
	 *
	 * @return void
	 */
	public function test_the_next_products_are_read_from_where_the_page_ended(): void {
		for ( $id = 1; $id <= Mapping_Article_Preview::PRODUCTS + 3; $id++ ) {
			$this->product( $id, 'Товар ' . $id );
		}

		$first = $this->preview()->read( $this->mapping_id );
		$rest  = $this->preview()->read( $this->mapping_id, $first['next_offset'] );

		$this->assertCount( Mapping_Article_Preview::PRODUCTS, $first['products'] );
		$this->assertSame( Mapping_Article_Preview::PRODUCTS, $first['next_offset'] );
		$this->assertSame( [ 21, 22, 23 ], array_column( $rest['products'], 'id' ) );
		$this->assertSame( 0, $rest['next_offset'] );
	}

	/**
	 * One request reads a bounded number of products, however few of them need a card.
	 *
	 * @return void
	 */
	public function test_one_request_reads_a_bounded_number_of_products(): void {
		for ( $id = 1; $id <= Mapping_Article_Preview::SCAN + 1; $id++ ) {
			$this->product( $id, 'Товар ' . $id );
			$this->not_exportable[] = $id;
		}

		$first = $this->preview()->read( $this->mapping_id );
		$rest  = $this->preview()->read( $this->mapping_id, $first['next_offset'] );

		$this->assertSame( [], $first['products'] );
		$this->assertSame( Mapping_Article_Preview::SCAN, $first['next_offset'] );
		$this->assertSame( 0, $rest['next_offset'] );
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
	 * @param int $mapping_id Mapping.
	 * @return \WP_REST_Request
	 */
	private function request( int $mapping_id ): \WP_REST_Request {
		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_query_params' )->andReturn( [] );
		$request->shouldReceive( 'get_url_params' )->andReturn( [ 'id' => (string) $mapping_id ] );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( $name ) use ( $mapping_id ) {
				return 'id' === $name ? (string) $mapping_id : null;
			}
		);

		return $request;
	}
}
