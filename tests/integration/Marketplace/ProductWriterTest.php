<?php
/**
 * The product writer on a real WordPress, WooCommerce and MySQL.
 *
 * What the unit suite cannot show: that the first save stores the SKU, the marker and the categories together; that a profile-level
 * markup is seen because the price is worked out after the categories are stored; that WooCommerce's image helpers run in a
 * background job with no user; and that the one download is bounded and its filter is gone afterwards. Images are served from a
 * local file through `pre_http_request`, so nothing reaches the internet.
 *
 * Like PersistenceTest, the test creates real tables of its own before the WordPress test case opens its transaction (a DDL
 * statement commits) and drops them after the rollback.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Article_Policy;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Export_Facts;
use Woodev\Framework\Marketplace\Flows\Import_Category;
use Woodev\Framework\Marketplace\Flows\Link_Context_Resolver;
use Woodev\Framework\Marketplace\Flows\Product_Writer;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Sync\Shop_Price_Writer;
use Woodev\Tests\Integration\TestCase;

class ProductWriterTest extends TestCase {

	private const MARKETPLACE = 'itest';

	private const IMAGE_HOST = 'https://img.example.test/';

	/**
	 * Schema under test.
	 *
	 * @var Schema
	 */
	private $schema;

	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * Profiles.
	 *
	 * @var Wpdb_Profile_Store
	 */
	private $profiles;

	/**
	 * The writer under test, over the real price context.
	 *
	 * @var Product_Writer
	 */
	private $writer;

	/**
	 * Downloads the fake HTTP layer served: `[ url, timeout ]` each.
	 *
	 * @var array<int,array{url:string,timeout:mixed}>
	 */
	private $downloads = [];

	/**
	 * Creates real tables, lets the WordPress test case open its transaction, and wires the writer.
	 */
	protected function setUp(): void {
		$this->schema = new Schema( self::MARKETPLACE );
		$this->drop_tables();
		( new Lifecycle( $this->get_test_plugin(), $this->schema ) )->create_tables();

		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		global $wpdb;

		$this->mappings = new Wpdb_Mapping_Store( $wpdb, $this->schema->table_name( 'mappings', $wpdb->prefix ), $this->schema->table_name( 'mapping_terms', $wpdb->prefix ) );
		$this->profiles = new Wpdb_Profile_Store( $wpdb, $this->schema->table_name( 'profiles', $wpdb->prefix ), Wpdb_Profile_Store::price_changed_action( self::MARKETPLACE ) );
		$links          = new Wpdb_Link_Store( $wpdb, $this->schema->table_name( 'links', $wpdb->prefix ), $this->schema->table_name( 'link_ids', $wpdb->prefix ) );
		$context        = new Export_Context_Builder( new Link_Context_Resolver( $links, $this->mappings ), $this->profiles, $links, $this->facts(), $this->policy(), new Pinned_Offer_Id( self::MARKETPLACE, $this->policy() ) );

		$this->writer = new Product_Writer( new Import_Category( $this->mappings ), $this->profiles, new Shop_Price_Writer( $context ) );

		wp_set_current_user( 0 ); // A background job has no user.
		add_filter( 'pre_http_request', [ $this, 'serve_image' ], 10, 3 );
	}

	/**
	 * Lets the WordPress test case roll back, then drops the real tables.
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'serve_image' ], 10 );
		parent::tearDown();

		$this->drop_tables();
	}

	/**
	 * The fake HTTP layer: a local PNG for every image URL, a transport error for a URL that contains `bad`.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string,mixed>  $args Request arguments, after `http_request_args`.
	 * @param string               $url  Request URL.
	 * @return mixed
	 */
	public function serve_image( $pre, $args, $url ) {
		if ( 0 !== strpos( (string) $url, self::IMAGE_HOST ) ) {
			return $pre;
		}

		$this->downloads[] = [
			'url'     => (string) $url,
			'timeout' => $args['timeout'] ?? null,
		];

		if ( false !== strpos( (string) $url, 'bad' ) ) {
			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}

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
	 * The first save is the whole of step 1: a run cut right after it leaves a draft that is found by its SKU, carries the
	 * marker and is already in its categories.
	 */
	public function test_the_first_save_stores_the_sku_the_marker_and_the_category_together(): void {
		$term_id = $this->mapped_category( [ 'type_id' => 91565 ], null );

		$this->writer->start( $this->account(), $this->card( [ 'remote_category' => [ 'type_id' => 91565 ] ] ), false );

		// The run is cut here: nothing after the first save has run.
		$found = wc_get_product_id_by_sku( 'ART-1' );
		$this->assertGreaterThan( 0, $found, 'the product is found by its SKU' );

		$product = wc_get_product( $found );
		$this->assertTrue( Product_Writer::is_marked( $product, self::MARKETPLACE ) );
		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( [ $term_id ], wc_get_product_term_ids( $found, 'product_cat' ) );
		$this->assertSame( '', $product->get_description(), 'step 2 has not run' );
		$this->assertEmpty( $product->get_image_id(), 'step 3 has not run' );
	}

	/**
	 * What `$prepare` sets on the unsaved product is stored by the same first save as the SKU and the marker: a run cut right
	 * after that save has not lost it. The importer puts the group of the card there.
	 */
	public function test_the_first_save_also_stores_what_the_caller_prepared(): void {
		$this->writer->start(
			$this->account(),
			$this->card( [ 'offer_id' => 'ART-PREP' ] ),
			false,
			static function ( \WC_Product $product ): void {
				$product->update_meta_data( '_itest_prepared', 'group-7' );
			}
		);

		// The run is cut here.
		$product = wc_get_product( wc_get_product_id_by_sku( 'ART-PREP' ) );

		$this->assertSame( 'group-7', $product->get_meta( '_itest_prepared' ) );
		$this->assertTrue( Product_Writer::is_marked( $product, self::MARKETPLACE ) );
	}

	/**
	 * A variation is never a draft — WooCommerce lists only the ones that are switched on or off — so the final save of a
	 * variation that is not published stores `private`, in the same save that removes the marker.
	 */
	public function test_a_variation_that_is_not_published_is_finished_switched_off(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Родитель' );
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_status( 'private' );
		$variation->set_sku( 'ART-VAR' );
		$variation->update_meta_data( Product_Writer::marker_key( self::MARKETPLACE ), '1' );
		$variation->save();

		$this->writer->complete( $this->account(), $this->card( [ 'offer_id' => 'ART-VAR' ] ), $variation, false, $this->renew() );

		$stored = wc_get_product( $variation->get_id() );

		$this->assertSame( 'private', $stored->get_status() );
		$this->assertFalse( Product_Writer::is_marked( $stored, self::MARKETPLACE ) );
		$this->assertEqualsWithDelta( 1100.0, (float) $stored->get_regular_price(), 0.001 );

		$this->writer->complete( $this->account(), $this->card( [ 'offer_id' => 'ART-VAR' ] ), $stored, true, $this->renew() );

		$this->assertSame( 'publish', wc_get_product( $variation->get_id() )->get_status() );
	}

	/**
	 * The price is worked out after the categories are stored: the mapping's profile carries +10 %, the account none, so the
	 * shop's regular price is the card's price divided by 1.1. A shop price rule worked out before the save would be the account's.
	 */
	public function test_a_profile_level_markup_is_applied_to_the_price(): void {
		$profile_id = $this->profiles->create(
			'Наценка 10 %',
			[
				'price_rule' => [
					'base'        => 'active',
					'markup_kind' => 'percent',
					'markup'      => '10',
				],
			]
		);
		$this->mapped_category( [ 'type_id' => 91565 ], $profile_id );

		$result = $this->writer->write( $this->account(), $this->card( [ 'remote_category' => [ 'type_id' => 91565 ] ] ), true, false, $this->renew() );

		$this->assertSame( [], $result['warnings'] );
		$this->assertEqualsWithDelta( 1000.0, (float) $result['product']->get_regular_price(), 0.001 );
		$this->assertSame( '', (string) $result['product']->get_sale_price() );
		$this->assertSame( 'publish', wc_get_product( $result['product']->get_id() )->get_status() );
	}

	/**
	 * A card with an old price becomes a sale: the old price is the regular price, the price is the sale price.
	 */
	public function test_an_old_price_becomes_the_regular_price_and_the_price_the_sale(): void {
		$card = $this->card(
			[
				'prices' => new Price_Pair( Money::from_string( '900', get_woocommerce_currency() ), Money::from_string( '1200', get_woocommerce_currency() ) ),
			]
		);

		$result = $this->writer->write( $this->account(), $card, false, false, $this->renew() );

		$this->assertEqualsWithDelta( 1200.0, (float) $result['product']->get_regular_price(), 0.001 );
		$this->assertEqualsWithDelta( 900.0, (float) $result['product']->get_sale_price(), 0.001 );
	}

	/**
	 * Weight and dimensions arrive in the shop's units, the description where the profile reads it, and the marker is gone
	 * after the final save while a draft stays a draft.
	 */
	public function test_fields_go_into_the_shop_units_and_the_final_save_clears_the_marker(): void {
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );
		$profile_id = $this->profiles->create( 'Краткое описание', [ 'description_source' => 'short_description' ] );
		$this->mapped_category( [ 'type_id' => 3 ], $profile_id );

		$card   = $this->card(
			[
				'remote_category' => [ 'type_id' => 3 ],
				'description'     => '<p>Текст</p><script>alert(1)</script>',
				'weight_g'        => 1500,
				'length_mm'       => 200,
				'width_mm'        => 100,
				'height_mm'       => 50,
			]
		);
		$result = $this->writer->write( $this->account(), $card, false, false, $this->renew() );
		$loaded = wc_get_product( $result['product']->get_id() );

		$this->assertSame( '<p>Текст</p>alert(1)', $loaded->get_short_description() );
		$this->assertSame( '', $loaded->get_description() );
		$this->assertEqualsWithDelta( 1.5, (float) $loaded->get_weight(), 0.0001 );
		$this->assertEqualsWithDelta( 20.0, (float) $loaded->get_length(), 0.0001 );
		$this->assertEqualsWithDelta( 10.0, (float) $loaded->get_width(), 0.0001 );
		$this->assertEqualsWithDelta( 5.0, (float) $loaded->get_height(), 0.0001 );
		$this->assertFalse( Product_Writer::is_marked( $loaded, self::MARKETPLACE ) );
		$this->assertSame( 'draft', $loaded->get_status() );
	}

	/**
	 * The card's characteristics are stored as the product's own visible attributes, none of them for variations, and a second
	 * `complete()` on the same product — a resumed run — writes none of them twice.
	 */
	public function test_the_characteristics_are_stored_as_visible_product_attributes_once(): void {
		$card = $this->card(
			[
				'characteristics' => [
					[
						'name'   => 'Бренд',
						'values' => [ 'Acme' ],
					],
					[
						'name'   => 'Материал',
						'values' => [ 'сталь', 'алюминий' ],
					],
				],
			]
		);

		$result  = $this->writer->write( $this->account(), $card, false, false, $this->renew() );
		$product = $result['product'];

		$this->writer->complete( $this->account(), $card, wc_get_product( $product->get_id() ), false, $this->renew() );

		$attributes = array_values( wc_get_product( $product->get_id() )->get_attributes() );

		$this->assertCount( 2, $attributes );
		$this->assertSame( [ 'Бренд', [ 'Acme' ], true, false ], [ $attributes[0]->get_name(), $attributes[0]->get_options(), $attributes[0]->get_visible(), $attributes[0]->get_variation() ] );
		$this->assertSame( [ 'сталь', 'алюминий' ], $attributes[1]->get_options() );
	}

	/**
	 * One profile governs the price and the fields: a card whose own category is in no mapping, created under a category the
	 * merchant mapped, is written with the profile of that ancestor — the profile the price and the first export read.
	 */
	public function test_the_profile_of_a_mapped_ancestor_governs_the_fields_as_well_as_the_price(): void {
		$profile_id = $this->profiles->create(
			'Краткое описание и наценка',
			[
				'description_source' => 'short_description',
				'price_rule'         => [
					'base'        => 'active',
					'markup_kind' => 'percent',
					'markup'      => '10',
				],
			]
		);
		$parent = $this->mapped_category( [ 'type_id' => 1 ], $profile_id, 'Электроника' );
		$card   = $this->card(
			[
				'remote_category' => [ 'type_id' => 999 ],
				'category_path'   => [ 'Электроника', 'Телефоны' ],
				'description'     => 'Описание',
			]
		);

		$result = $this->writer->write( $this->account(), $card, false, true, $this->renew() );
		$loaded = wc_get_product( $result['product']->get_id() );
		$leaf   = get_term( $loaded->get_category_ids()[0], 'product_cat' );

		$this->assertSame( $parent, (int) $leaf->parent, 'the card sits under the mapped category' );
		$this->assertSame( 'Описание', $loaded->get_short_description(), 'the ancestor profile reads the short description' );
		$this->assertSame( '', $loaded->get_description() );
		$this->assertEqualsWithDelta( 1000.0, (float) $loaded->get_regular_price(), 0.001, 'and the same profile gives the price its markup' );
	}

	/**
	 * Weight and dimensions go into the profile's own units when it names them (the export reads the numbers in those), else
	 * into the shop's.
	 */
	public function test_weight_and_dimensions_follow_the_units_of_the_profile(): void {
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );
		$profile_id = $this->profiles->create(
			'Граммы и миллиметры',
			[
				'units' => [
					'weight'    => 'g',
					'dimension' => 'mm',
				],
			]
		);
		$this->mapped_category( [ 'type_id' => 8 ], $profile_id );

		$fields = [
			'weight_g'  => 1500,
			'length_mm' => 200,
			'width_mm'  => 100,
			'height_mm' => 50,
		];
		$own    = $this->writer->write( $this->account(), $this->card( array_merge( $fields, [ 'remote_category' => [ 'type_id' => 8 ] ] ) ), false, false, $this->renew() )['product'];
		$shop   = $this->writer->write( $this->account(), $this->card( array_merge( $fields, [ 'offer_id' => 'ART-2' ] ) ), false, false, $this->renew() )['product'];

		$this->assertEqualsWithDelta( 1500.0, (float) $own->get_weight(), 0.0001, 'grams, as the profile says' );
		$this->assertEqualsWithDelta( 200.0, (float) $own->get_length(), 0.0001, 'millimetres, as the profile says' );
		$this->assertEqualsWithDelta( 50.0, (float) $own->get_height(), 0.0001 );
		$this->assertEqualsWithDelta( 1.5, (float) $shop->get_weight(), 0.0001, 'no profile: the shop kilograms' );
		$this->assertEqualsWithDelta( 20.0, (float) $shop->get_length(), 0.0001, 'no profile: the shop centimetres' );
	}

	/**
	 * A source that is a custom field gets the image links and downloads nothing; `none` leaves the gallery out.
	 */
	public function test_custom_field_sources_write_the_links_and_download_nothing(): void {
		$profile_id = $this->profiles->create(
			'Поля',
			[
				'primary_image_source' => 'meta:_photo_link',
				'images_source'        => 'meta:_photo_links',
			]
		);
		$this->mapped_category( [ 'type_id' => 4 ], $profile_id );

		$result = $this->writer->write( $this->account(), $this->card( $this->with_images( [ 'remote_category' => [ 'type_id' => 4 ] ] ) ), false, false, $this->renew() );

		$this->assertSame( [], $this->downloads );
		$this->assertSame( self::IMAGE_HOST . 'main.png', $result['product']->get_meta( '_photo_link' ) );
		$this->assertSame( self::IMAGE_HOST . 'one.png' . "\n" . self::IMAGE_HOST . 'two.png', $result['product']->get_meta( '_photo_links' ) );
		$this->assertEmpty( $result['product']->get_image_id() );

		$none = $this->profiles->create( 'Без галереи', [ 'images_source' => 'none' ] );
		$this->mapped_category( [ 'type_id' => 5 ], $none );
		$second = $this->writer->write( $this->account(), $this->card( $this->with_images( [ 'offer_id' => 'ART-2', 'remote_category' => [ 'type_id' => 5 ] ] ) ), false, false, $this->renew() );

		$this->assertCount( 1, $this->downloads, 'only the featured image was downloaded' );
		$this->assertSame( [], $second['product']->get_gallery_image_ids() );
		$this->assertGreaterThan( 0, $second['product']->get_image_id() );
	}

	/**
	 * Images are downloaded one at a time: the main one is featured, the rest are the gallery, and each remembers its source.
	 */
	public function test_images_are_downloaded_and_remember_their_source(): void {
		$result  = $this->writer->write( $this->account(), $this->card( $this->with_images() ), true, false, $this->renew() );
		$product = wc_get_product( $result['product']->get_id() );

		$this->assertSame( [], $result['warnings'] );
		$this->assertCount( 3, $this->downloads );
		$this->assertGreaterThan( 0, $product->get_image_id() );
		$this->assertCount( 2, $product->get_gallery_image_ids() );
		$this->assertSame( self::IMAGE_HOST . 'main.png', get_post_meta( $product->get_image_id(), '_wc_attachment_source', true ) );
	}

	/**
	 * A run cut during the images is resumed on the marked product: the images already fetched are found by their source URL,
	 * so nothing is downloaded twice; `$renew` runs before each image and a throw from it stops the writer.
	 */
	public function test_a_cut_run_resumes_on_the_marked_product_without_a_second_download(): void {
		$account = $this->account();
		$card    = $this->card( $this->with_images() );
		$product = $this->writer->start( $account, $card, false );
		$renewed = 0;
		$cut     = static function () use ( &$renewed ): void {
			if ( ++$renewed > 1 ) {
				throw new \RuntimeException( 'claim lost' );
			}
		};

		try {
			$this->writer->complete( $account, $card, $product, true, $cut );
			$this->fail( 'the writer must stop when $renew throws' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'claim lost', $e->getMessage() );
		}

		$this->assertSame( 2, $renewed, 'renew ran before the second image and the writer stopped there' );
		$this->assertCount( 1, $this->downloads, 'one image was fetched before the cut' );

		$marked = wc_get_product( wc_get_product_id_by_sku( 'ART-1' ) );
		$this->assertTrue( Product_Writer::is_marked( $marked, self::MARKETPLACE ), 'the cut product still carries its marker' );

		$renewed = 0;
		$this->writer->complete( $account, $card, $marked, true, $this->renew( $renewed ) );

		$this->assertCount( 3, $this->downloads, 'the resume fetched the two missing images and not the first again' );
		$this->assertSame( 3, $renewed, 'renew ran before each of the three images' );
		$this->assertFalse( Product_Writer::is_marked( wc_get_product( $marked->get_id() ), self::MARKETPLACE ) );
		$this->assertSame( 1, count( get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_sku', 'meta_value' => 'ART-1', 'fields' => 'ids' ] ) ), 'one product only' ); // phpcs:ignore WordPress.DB.SlowDBQuery -- A test lookup.
	}

	/**
	 * One image that cannot be loaded does not fail the card: the product is kept, finished, and the warning counts the image.
	 */
	public function test_a_failed_image_keeps_the_product(): void {
		$card   = $this->card(
			[
				'primary_image' => self::IMAGE_HOST . 'main.png',
				'images'        => [ self::IMAGE_HOST . 'bad.png', self::IMAGE_HOST . 'two.png' ],
			]
		);
		$result = $this->writer->write( $this->account(), $card, true, false, $this->renew() );
		$loaded = wc_get_product( $result['product']->get_id() );

		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( '1', $result['warnings'][0] );
		$this->assertSame( 'publish', $loaded->get_status() );
		$this->assertFalse( Product_Writer::is_marked( $loaded, self::MARKETPLACE ) );
		$this->assertGreaterThan( 0, $loaded->get_image_id() );
		$this->assertCount( 1, $loaded->get_gallery_image_ids(), 'the good gallery image is in' );
	}

	/**
	 * One download is bounded at IMAGE_TIMEOUT whatever WordPress's default is, and the filter that bounds it is gone afterwards,
	 * on success and on failure.
	 */
	public function test_the_download_is_cut_at_the_image_timeout_and_the_filter_is_removed(): void {
		$before = $this->http_args_filters();

		$this->writer->write( $this->account(), $this->card( [ 'primary_image' => self::IMAGE_HOST . 'main.png' ] ), false, false, $this->renew() );
		$this->writer->write( $this->account(), $this->card( [ 'offer_id' => 'ART-2', 'primary_image' => self::IMAGE_HOST . 'bad.png' ] ), false, false, $this->renew() );

		$this->assertCount( 2, $this->downloads );
		foreach ( $this->downloads as $download ) {
			$this->assertSame( Product_Writer::IMAGE_TIMEOUT, $download['timeout'] );
		}

		$this->assertSame( 30, Product_Writer::IMAGE_TIMEOUT );
		$this->assertSame( $before, $this->http_args_filters(), 'the filter is not left behind' );

		$other = wp_parse_args( apply_filters( 'http_request_args', [ 'timeout' => 300 ], 'https://example.test/' ) );
		$this->assertSame( 300, $other['timeout'], 'a later request keeps its own timeout' );
	}

	/**
	 * The helpers of WooCommerce work in an Action Scheduler callback with no user: a product with an image is created there.
	 */
	public function test_a_product_with_an_image_is_created_inside_an_action_scheduler_callback(): void {
		$created = null;
		$hook    = 'woodev_itest_import_probe';

		add_action(
			$hook,
			function () use ( &$created ): void {
				$created = $this->writer->write( $this->account(), $this->card( [ 'primary_image' => self::IMAGE_HOST . 'main.png' ] ), true, false, $this->renew() );
			}
		);

		$action_id = as_schedule_single_action( time() - 5, $hook, [], 'woodev-itest' );
		$this->assertGreaterThan( 0, $action_id );

		\ActionScheduler::runner()->process_action( (string) $action_id );

		$this->assertSame( \ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler::store()->get_status( (string) $action_id ), 'the callback ran to its end' );
		$this->assertNotNull( $created );

		$product = wc_get_product( wc_get_product_id_by_sku( 'ART-1' ) );
		$this->assertSame( 'publish', $product->get_status() );
		$this->assertGreaterThan( 0, $product->get_image_id() );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Categories: the mapping's terms win; with none, the card's path is created by name (an existing category with the same name
	 * and parent is reused, a same-named child of another parent is not), and with creation off the product has no category.
	 */
	public function test_categories_come_from_the_mapping_or_are_created_by_name(): void {
		$resolver = new Import_Category( $this->mappings );
		$path     = $this->card( [ 'category_path' => [ 'Электроника', 'Телефоны' ] ] );

		$this->assertSame( [], $resolver->term_ids( $path, false ) );

		$created = $resolver->term_ids( $path, true );
		$this->assertCount( 1, $created );
		$leaf = get_term( $created[0], 'product_cat' );
		$this->assertSame( 'Телефоны', $leaf->name );
		$this->assertSame( 'Электроника', get_term( $leaf->parent, 'product_cat' )->name );
		$this->assertSame( $created, $resolver->term_ids( $path, true ), 'the second run reuses the categories' );

		$other  = wp_insert_term( 'Аксессуары', 'product_cat' );
		$child  = wp_insert_term( 'Телефоны', 'product_cat', [ 'parent' => $other['term_id'] ] );
		$again  = $resolver->term_ids( $path, true );
		$this->assertSame( $created, $again, 'a same-named child of another parent is not reused' );
		$this->assertNotSame( (int) $child['term_id'], $again[0] );

		$mapped = $this->mapped_category( [ 'type_id' => 77 ], null );
		$card   = $this->card( [ 'remote_category' => [ 'type_id' => 77 ], 'category_path' => [ 'Другое' ] ] );
		$this->assertSame( [ $mapped ], $resolver->term_ids( $card, true ) );
	}

	/**
	 * `for_category()` on real MySQL: the descriptor matches a stored mapping whatever its key order.
	 */
	public function test_for_category_matches_whatever_the_key_order(): void {
		$term = $this->mapped_category( [ 'description_category_id' => 17028973, 'type_id' => 91565 ], null );

		$found = $this->mappings->for_category( [ 'type_id' => 91565, 'description_category_id' => 17028973 ] );

		$this->assertNotNull( $found );
		$this->assertSame( [ $term ], $found['term_ids'] );
		$this->assertNull( $this->mappings->for_category( [ 'type_id' => 91565 ] ) );
	}

	/**
	 * Makes a product category and a term-bound mapping that owns it.
	 *
	 * @param array<string,mixed> $descriptor Remote category descriptor.
	 * @param int|null            $profile_id Profile id.
	 * @param string              $name       Category name, a generated one when empty.
	 * @return int The product category's term id.
	 */
	private function mapped_category( array $descriptor, ?int $profile_id, string $name = '' ): int {
		$term       = wp_insert_term( '' !== $name ? $name : 'Категория ' . wp_generate_password( 6, false ), 'product_cat' );
		$mapping_id = $this->mappings->create( 'Сопоставление', null, $descriptor, $profile_id, [] );
		$this->mappings->link_term_if_absent( (int) $term['term_id'], $mapping_id );

		return (int) $term['term_id'];
	}

	/**
	 * A card with the article `ART-1`, the price 1100 in the shop's currency and the given fields.
	 *
	 * @param array<string,mixed> $fields Fields, `offer_id` goes to the link card.
	 * @return Card_Content
	 */
	private function card( array $fields = [] ): Card_Content {
		$offer_id = (string) ( $fields['offer_id'] ?? 'ART-1' );
		unset( $fields['offer_id'] );

		return new Card_Content(
			[
				'offer_id'   => $offer_id,
				'remote_ids' => [ 'product_id' => '777' ],
				'state'      => 'created',
			],
			array_merge(
				[
					'name'   => 'Карточка ' . $offer_id,
					'prices' => new Price_Pair( Money::from_string( '1100', get_woocommerce_currency() ) ),
				],
				$fields
			)
		);
	}

	/**
	 * Adds a main image and two more to card fields.
	 *
	 * @param array<string,mixed> $fields Fields.
	 * @return array<string,mixed>
	 */
	private function with_images( array $fields = [] ): array {
		return array_merge(
			$fields,
			[
				'primary_image' => self::IMAGE_HOST . 'main.png',
				'images'        => [ self::IMAGE_HOST . 'one.png', self::IMAGE_HOST . 'two.png' ],
			]
		);
	}

	/**
	 * A `$renew` that counts its calls.
	 *
	 * @param int|null $calls Receives the number of calls.
	 * @return callable
	 */
	private function renew( ?int &$calls = null ): callable {
		$calls = 0;

		return static function () use ( &$calls ): void {
			++$calls;
		};
	}

	/**
	 * An account of the marketplace under test.
	 *
	 * @return Account
	 */
	private function account(): Account {
		return new Account( 5, self::MARKETPLACE, 'Кабинет', true, [ 'token' => 'dummy-token-for-tests' ] );
	}

	/**
	 * Counts the callbacks on `http_request_args`.
	 *
	 * @return int
	 */
	private function http_args_filters(): int {
		global $wp_filter;

		$count = 0;
		foreach ( isset( $wp_filter['http_request_args'] ) ? $wp_filter['http_request_args']->callbacks : [] as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
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
