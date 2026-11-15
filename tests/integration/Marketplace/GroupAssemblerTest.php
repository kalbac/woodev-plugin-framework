<?php
/**
 * The cards of one marketplace group imported on a real WordPress, WooCommerce and MySQL.
 *
 * What the unit suite cannot show: that WooCommerce really turns a stored simple product into a variable one in place; that the
 * first variation takes the SKU without a duplicate-SKU refusal and the price a variable product no longer answers for; that a
 * variation made from a card's aspects is the one the shop finds for that colour; that the unique indexes of the links table
 * accept the moved offer row and the new parent row; and that an order made while the product was simple still reduces and
 * restores the stock of its card through WooCommerce's own stock functions. Images are served from a local file through `pre_http_request`.
 *
 * Like ProductWriterTest, the test creates real tables of its own before the WordPress test case opens its transaction (a DDL
 * statement commits) and drops them after the rollback. A link write runs in a transaction of its own, which ends the test
 * case's: what a test stored before its last link write is stored for real, so each test uses articles of its own and
 * {@see self::delete_what_was_stored()} removes its products, images and notes before the tables go.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Catalog\Article_Policy;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Flows\Card_Import;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Export_Facts;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;
use Woodev\Framework\Marketplace\Flows\Import_Category;
use Woodev\Framework\Marketplace\Flows\Legacy_Order_Lines;
use Woodev\Framework\Marketplace\Flows\Link_Context_Resolver;
use Woodev\Framework\Marketplace\Flows\Product_Writer;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Sync\Shop_Price_Writer;
use Woodev\Tests\Integration\TestCase;

class GroupAssemblerTest extends TestCase {

	private const MARKETPLACE = 'itest';

	private const IMAGE_HOST = 'https://img.example.test/';

	/**
	 * Schema under test.
	 *
	 * @var Schema
	 */
	private $schema;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * The account the cards belong to.
	 *
	 * @var Account
	 */
	private $account;

	/**
	 * The claim the run holds on the account.
	 *
	 * @var Claim_Fence
	 */
	private $fence;

	/**
	 * The importer under test, over the real writer and the real assembler.
	 *
	 * @var Card_Import
	 */
	private $import;

	/**
	 * URLs the fake HTTP layer served.
	 *
	 * @var string[]
	 */
	private $downloads = [];

	/**
	 * Articles of the cards the test built.
	 *
	 * @var string[]
	 */
	private $articles = [];

	/**
	 * Orders the test made.
	 *
	 * @var int[]
	 */
	private $orders = [];

	/**
	 * The resolver of the order lines made before a conversion, hooked as the wiring hooks it.
	 *
	 * @var Legacy_Order_Lines
	 */
	private $lines;

	/**
	 * Creates real tables, lets the WordPress test case open its transaction, and wires the importer.
	 */
	protected function setUp(): void {
		$this->schema = new Schema( self::MARKETPLACE );
		$this->drop_tables();
		( new Lifecycle( $this->get_test_plugin(), $this->schema ) )->create_tables();

		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		global $wpdb;

		$table       = function ( string $name ) use ( $wpdb ): string {
			return $this->schema->table_name( $name, $wpdb->prefix );
		};
		$mappings    = new Wpdb_Mapping_Store( $wpdb, $table( 'mappings' ), $table( 'mapping_terms' ) );
		$profiles    = new Wpdb_Profile_Store( $wpdb, $table( 'profiles' ), Wpdb_Profile_Store::price_changed_action( self::MARKETPLACE ) );
		$this->links = new Wpdb_Link_Store( $wpdb, $table( 'links' ), $table( 'link_ids' ) );
		$context     = new Export_Context_Builder( new Link_Context_Resolver( $this->links, $mappings ), $profiles, $this->links, $this->facts(), $this->policy(), new Pinned_Offer_Id( self::MARKETPLACE, $this->policy() ) );
		$writer      = new Product_Writer( new Import_Category( $mappings ), $profiles, new Shop_Price_Writer( $context ) );
		$accounts    = new Wpdb_Account_Store( $wpdb, $table( 'accounts' ), new Secret_Cipher( 'integration-salt' ), self::MARKETPLACE, new Credential_Fields( 'cabinet', [ 'token' ] ) );

		$account_id = $accounts->save(
			new Account(
				0,
				self::MARKETPLACE,
				'Кабинет',
				true,
				[
					'cabinet' => 'cab-1',
					'token'   => 'dummy-token-for-tests',
				]
			)
		);

		$this->account = $accounts->get( $account_id );
		$this->fence   = new Claim_Fence( $table( 'accounts' ), (string) $accounts->claim( $account_id, 600 ) );
		$this->import  = new Card_Import(
			$this->links,
			new Card_Linker( $this->links ),
			new Link_Report_Store( 'itest_' ),
			Effective_Settings::for_adapter( self::MARKETPLACE ),
			new Group_Assembler( $this->links ),
			[ $writer, 'start' ],
			[ $writer, 'complete' ]
		);

		$this->lines = new Legacy_Order_Lines( self::MARKETPLACE );
		$this->lines->register();

		wp_set_current_user( 0 ); // A background job has no user.
		add_filter( 'pre_http_request', [ $this, 'serve_image' ], 10, 3 );
	}

	/**
	 * Lets the WordPress test case roll back, then drops the real tables.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'serve_image' ], 10 );
		remove_filter( 'woocommerce_order_item_product', [ $this->lines, 'resolve' ], 10 );
		$this->delete_what_was_stored();
		parent::tearDown();

		$this->drop_tables();
	}

	/**
	 * The fake HTTP layer: a local PNG for every image URL.
	 *
	 * @param mixed               $pre  Short-circuit value.
	 * @param array<string,mixed> $args Request arguments.
	 * @param string              $url  Request URL.
	 * @return mixed
	 */
	public function serve_image( $pre, $args, $url ) {
		if ( 0 !== strpos( (string) $url, self::IMAGE_HOST ) ) {
			return $pre;
		}

		$this->downloads[] = (string) $url;

		file_put_contents( $args['filename'], base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixture image.

		return [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => $args['filename'],
		];
	}

	/**
	 * Red, then black a week later, is the same variable product as red and black at once: the same attribute, the same two
	 * variations with their own SKU, price and colour, the same links.
	 */
	public function test_red_then_black_equals_red_and_black_together(): void {
		// Together: the marketplace says the group has two cards when the red one arrives.
		$this->import( $this->card( 'A-RED', 'красный', 'ga', 2, '1100' ) );
		$this->import( $this->card( 'A-BLACK', 'чёрный', 'ga', 2, '1300' ) );

		// A week apart: the red card is alone in its group when it is imported; the black one is created later.
		$this->import( $this->card( 'B-RED', 'красный', 'gb', 1, '1100' ) );

		$simple = wc_get_product( wc_get_product_id_by_sku( 'B-RED' ) );
		$this->assertSame( 'simple', $simple->get_type(), 'the only card of its group is a simple product' );

		$this->import( $this->card( 'B-BLACK', 'чёрный', 'gb', 2, '1300' ) );

		$together = $this->shape( 'A-RED' );
		$apart    = $this->shape( 'B-RED' );

		$this->assertSame( 'variable', $together['type'] );
		$this->assertSame( [ 'красный', 'чёрный' ], array_keys( $together['variations'] ) );
		$this->assertSame( [ [ 'Цвет', [ 'красный', 'чёрный' ], true, false ] ], $together['attributes'], 'one custom attribute that varies, not a global taxonomy' );
		$this->assertEqualsWithDelta( 1300.0, (float) $together['variations']['чёрный']['price'], 0.001 );
		$this->assertSame( $together, $apart );
		$this->assertSame( $simple->get_id(), wc_get_product( wc_get_product_id_by_sku( 'B-RED' ) )->get_parent_id(), 'the simple product became the parent: its id is kept' );
	}

	/**
	 * The five steps on stored data: the product keeps its id and becomes variable, its SKU, price and stock move to the first
	 * variation without a duplicate-SKU refusal, the link row is re-pointed and a parent row with no article is inserted.
	 */
	public function test_the_conversion_keeps_the_product_moves_its_sku_and_repoints_its_link(): void {
		$this->import( $this->card( 'C-RED', 'красный', 'g1', 1, '1100' ) );

		$product_id = wc_get_product_id_by_sku( 'C-RED' );
		$before     = $this->links->find( $this->account->id(), $product_id );

		// The merchant's week with the simple product: published, stock managed.
		$simple = wc_get_product( $product_id );
		$simple->set_status( 'publish' );
		$simple->set_manage_stock( true );
		$simple->set_stock_quantity( 7 );
		$simple->save();

		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'C-BLACK', 'чёрный', 'g1', 2, '1300' ) ) );

		\WC_Post_Data::do_deferred_product_sync(); // What WooCommerce does at the end of the request.

		$parent = wc_get_product( $product_id );
		$red    = wc_get_product( wc_get_product_id_by_sku( 'C-RED' ) );
		$black  = wc_get_product( wc_get_product_id_by_sku( 'C-BLACK' ) );

		$this->assertSame( 'variable', \WC_Product_Factory::get_product_type( $product_id ), 'the stored product is variable' );
		$this->assertSame( [ '', 'publish', false ], [ $parent->get_sku(), $parent->get_status(), $parent->get_manage_stock() ] );
		$this->assertSame( [ 'variation', $product_id ], [ $red->get_type(), $red->get_parent_id() ], 'the former SKU is the first variation' );
		$this->assertEqualsWithDelta( 1100.0, (float) $red->get_regular_price(), 0.001, 'with the price the product had' );
		$this->assertSame( [ true, 7, 'instock' ], [ $red->get_manage_stock(), $red->get_stock_quantity(), $red->get_stock_status() ], 'and its stock' );
		$this->assertSame( [ 1100 ], array_map( 'intval', get_post_meta( $product_id, '_price' ) ), 'the product now shows the price of the variation that is on sale, not a price of its own' );
		$this->assertSame( 'instock', $parent->get_stock_status() );
		$this->assertCount( 2, $parent->get_children() );

		$after = $this->links->find_by_offer_id( $this->account->id(), 'C-RED' );

		$this->assertSame( $before->link_id(), $after->link_id(), 'the same link row' );
		$this->assertSame( [ $red->get_id(), $product_id ], [ $after->wc_product_id(), $after->wc_parent_id() ] );

		$parent_row = $this->links->find( $this->account->id(), $product_id );

		$this->assertSame( [ false, null ], [ $parent_row->is_offer(), $parent_row->offer_id() ], 'the variable product has a parent row with no article' );
		$black_link = $this->links->find_by_offer_id( $this->account->id(), 'C-BLACK' );

		$this->assertSame( [ $black->get_id(), $product_id ], [ $black_link->wc_product_id(), $black_link->wc_parent_id() ] );
		$this->assertSame( 'private', $black->get_status(), 'a card that joins a published product is switched off until the merchant looks' );
	}

	/**
	 * An order made while the product was simple names the product and no variation. After the conversion WooCommerce's own
	 * stock functions still reach the card's stock, now on the first variation: paying an order that was unpaid reduces it,
	 * cancelling one that was paid restores it.
	 */
	public function test_an_order_made_before_the_conversion_still_moves_the_stock_of_its_card(): void {
		$this->import( $this->card( 'E-RED', 'красный', 'g3', 1, '1100' ) );

		$product_id = wc_get_product_id_by_sku( 'E-RED' );
		$simple     = wc_get_product( $product_id );
		$simple->set_status( 'publish' );
		$simple->set_manage_stock( true );
		$simple->set_stock_quantity( 10 );
		$simple->save();

		$paid   = $this->order( $product_id, 2 );
		$unpaid = $this->order( $product_id, 3 );

		wc_get_order( $paid )->update_status( 'processing' );
		$this->assertEquals( 8, wc_get_product( $product_id )->get_stock_quantity(), 'the paid order took two from the simple product' );

		$this->import( $this->card( 'E-BLACK', 'чёрный', 'g3', 2, '1300' ) );

		$red_id = wc_get_product_id_by_sku( 'E-RED' );

		$this->assertNotSame( $product_id, $red_id );
		$this->assertEquals( 8, wc_get_product( $red_id )->get_stock_quantity(), 'the stock moved to the first variation' );
		$this->assertFalse( wc_get_product( $product_id )->get_manage_stock() );
		$this->assertSame( $red_id, array_values( wc_get_order( $unpaid )->get_items() )[0]->get_product()->get_id(), 'the old line is the red variation\'s' );

		wc_get_order( $unpaid )->update_status( 'processing' );
		$this->assertEquals( 5, wc_get_product( $red_id )->get_stock_quantity(), 'paying an order made before the conversion reduces the card\'s stock' );

		wc_get_order( $paid )->update_status( 'cancelled' );
		$this->assertEquals( 7, wc_get_product( $red_id )->get_stock_quantity(), 'cancelling one restores it' );
	}

	/**
	 * A lone card of a group of several is already a variable product with one variation; the product takes every image of
	 * its first card, and a later variation one image only.
	 */
	public function test_a_lone_card_of_a_group_of_several_is_variable_and_a_variation_takes_one_image(): void {
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'D-RED', 'красный', 'g2', 3, '1100', true ) ) );

		$red    = wc_get_product( wc_get_product_id_by_sku( 'D-RED' ) );
		$parent = wc_get_product( $red->get_parent_id() );

		$this->assertSame( [ 'variation', 'variable', 'draft' ], [ $red->get_type(), $parent->get_type(), $parent->get_status() ] );
		$this->assertCount( 2, $parent->get_gallery_image_ids(), 'the product keeps the gallery of the card it was made from' );
		$this->assertGreaterThan( 0, (int) $parent->get_image_id() );
		$this->assertSame( (int) $parent->get_image_id(), (int) $red->get_image_id( 'edit' ), 'and its first variation shows the same main image' );
		$this->assertFalse( Product_Writer::is_marked( $red, self::MARKETPLACE ) );
		$this->assertSame( '', (string) $parent->get_meta( Group_Assembler::variant_key( self::MARKETPLACE ) ), 'the conversion is closed' );

		$this->downloads = [];
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'D-BLACK', 'чёрный', 'g2', 3, '1300', true ) ) );

		$black  = wc_get_product( wc_get_product_id_by_sku( 'D-BLACK' ) );
		$parent = wc_get_product( $red->get_parent_id() );

		$this->assertSame( [ self::IMAGE_HOST . 'D-BLACK.png' ], $this->downloads, 'one image for a variation' );
		$this->assertGreaterThan( 0, (int) $black->get_image_id( 'edit' ) );
		$this->assertSame( 'publish', $black->get_status(), 'a variation of a draft product is switched on' );
		$this->assertEqualsWithDelta( 1300.0, (float) $black->get_regular_price(), 0.001 );
		$this->assertFalse( Product_Writer::is_marked( $black, self::MARKETPLACE ) );
		$this->assertSame( [ 'красный', 'чёрный' ], array_values( $parent->get_attributes() )[0]->get_options() );

		// The shop finds each variation by its colour.
		$store = \WC_Data_Store::load( 'product' );
		$key   = 'attribute_' . sanitize_title( 'Цвет' );

		$this->assertSame( $red->get_id(), $store->find_matching_product_variation( $parent, [ $key => 'красный' ] ) );
		$this->assertSame( $black->get_id(), $store->find_matching_product_variation( $parent, [ $key => 'чёрный' ] ) );
	}

	/**
	 * Imports a card under the run's claim.
	 *
	 * @param Card_Content $content Card.
	 * @return string
	 */
	private function import( Card_Content $content ): string {
		return $this->import->import( $this->account, $content, $this->fence, static function (): void {} );
	}

	/**
	 * Makes an unpaid order for a product, as the shop's checkout does.
	 *
	 * @param int $product_id Product id.
	 * @param int $quantity   Quantity.
	 * @return int Order id.
	 */
	private function order( int $product_id, int $quantity ): int {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), $quantity );
		$order->calculate_totals();
		$order->save();

		$this->orders[] = $order->get_id();

		return $order->get_id();
	}

	/**
	 * Builds a card of a group.
	 *
	 * @param string $article Article.
	 * @param string $colour  Colour.
	 * @param string $group   Group key.
	 * @param int    $size    Cards the marketplace says the group has.
	 * @param string $price   Price.
	 * @param bool   $images  Whether the card has a main image and two more.
	 * @return Card_Content
	 */
	private function card( string $article, string $colour, string $group, int $size, string $price, bool $images = false ): Card_Content {
		$this->articles[] = $article;

		return new Card_Content(
			[
				'offer_id'   => $article,
				'remote_ids' => [ 'product_id' => (string) ( crc32( $article ) % 100000 ) ],
				'state'      => 'created',
				'is_created' => true,
			],
			[
				'name'          => 'Футболка ' . $colour,
				'description'   => 'Хлопок, ' . $colour,
				'prices'        => new Price_Pair( Money::from_string( $price, get_woocommerce_currency() ) ),
				'primary_image' => $images ? self::IMAGE_HOST . $article . '.png' : '',
				'images'        => $images ? [ self::IMAGE_HOST . $article . '-2.png', self::IMAGE_HOST . $article . '-3.png' ] : [],
				'group'         => [
					'key'     => $group,
					'size'    => $size,
					'aspects' => [ 'Цвет' => $colour ],
				],
			]
		);
	}

	/**
	 * What the shop and the links table hold for the product a card belongs to, without ids and articles.
	 *
	 * @param string $article Article of one card of the product.
	 * @return array<string,mixed>
	 */
	private function shape( string $article ): array {
		$parent     = wc_get_product( wc_get_product( wc_get_product_id_by_sku( $article ) )->get_parent_id() );
		$parent_row = $this->links->find( $this->account->id(), $parent->get_id() );
		$variations = [];

		foreach ( $parent->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			$link      = $this->links->find( $this->account->id(), $child_id );
			$colour    = (string) current( $variation->get_attributes() );

			$variations[ $colour ] = [
				'article'    => substr( $variation->get_sku(), 2 ),
				'attributes' => $variation->get_attributes(),
				'price'      => (string) (float) $variation->get_regular_price(),
				'status'     => $variation->get_status(),
				'link'       => null === $link ? null : [ $link->is_offer(), substr( (string) $link->offer_id(), 2 ), $link->wc_parent_id() === $parent->get_id() ],
			];
		}

		ksort( $variations );

		return [
			'type'       => $parent->get_type(),
			'sku'        => $parent->get_sku(),
			'status'     => $parent->get_status(),
			'name'       => $parent->get_name(),
			'attributes' => array_map(
				static function ( \WC_Product_Attribute $attribute ): array {
					return [ $attribute->get_name(), $attribute->get_options(), $attribute->get_variation(), $attribute->is_taxonomy() ];
				},
				array_values( $parent->get_attributes() )
			),
			'pending'    => (string) wp_json_encode( $parent->get_meta( Group_Assembler::variant_key( self::MARKETPLACE ) ) ),
			'parent_row' => null === $parent_row ? null : [ $parent_row->is_offer(), $parent_row->offer_id() ],
			'variations' => $variations,
		];
	}

	/**
	 * Deletes the products, the orders, the images and the notes of the test, and commits: the test case's rollback would
	 * bring them back.
	 *
	 * @return void
	 */
	private function delete_what_was_stored(): void {
		global $wpdb;

		foreach ( $this->orders as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order ) {
				$order->delete( true );
			}
		}

		foreach ( array_unique( $this->articles ) as $article ) {
			$product = wc_get_product( wc_get_product_id_by_sku( $article ) );

			if ( $product ) {
				wp_delete_post( $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id(), true );
			}
		}

		$images = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_wc_attachment_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The images of this test.
				'meta_value'     => self::IMAGE_HOST, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The images of this test.
				'meta_compare'   => 'LIKE',
			]
		);

		array_map(
			static function ( int $attachment_id ): void {
				wp_delete_attachment( $attachment_id, true );
			},
			$images
		);

		( new Link_Report_Store( 'itest_' ) )->forget_account( $this->account->id() );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Ends the transaction the clean-up ran in.
	}

	/**
	 * Export facts of the marketplace under test: the cabinet counts in the shop's currency.
	 *
	 * @return Export_Facts
	 */
	private function facts(): Export_Facts {
		return new class() implements Export_Facts {
			public function facts( Account $account, \WC_Product $product, Mapping $mapping, Profile $profile ): array {
				return [];
			}

			public function account_currency( Account $account ): string {
				return get_woocommerce_currency();
			}

			public function offer_id_for( ?string $frozen, \WC_Product $product, Profile $profile ): string {
				return $frozen ?? $product->get_sku();
			}

			public function is_exportable( \WC_Product $product ): bool {
				return true;
			}
		};
	}

	/**
	 * Article rules of the marketplace under test.
	 *
	 * @return Article_Policy
	 */
	private function policy(): Article_Policy {
		return new class() implements Article_Policy {
			public function max_length(): int {
				return 100;
			}

			public function normalise( string $sku ): string {
				return $sku;
			}

			public function fallback( int $product_id ): string {
				return 'P' . $product_id;
			}
		};
	}

	/**
	 * Drops the real tables.
	 */
	private function drop_tables(): void {
		global $wpdb;

		foreach ( $this->schema->table_names( $wpdb->prefix ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are this test's own.
		}
	}
}
