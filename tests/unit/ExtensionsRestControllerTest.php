<?php
/**
 * Unit tests for the «Плагины» catalog REST controller's pure normalizer.
 *
 * Covers the raw EDD product → lean UI shape mapping (paid/free, thumbnail
 * fallback chain, category-slug extraction, UTM-decorated permalink, and the
 * id-less reject path). Network/cache paths are exercised at the rig, not here.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-extensions.php';

/**
 * @covers \Woodev_REST_API_Extensions
 */
final class ExtensionsRestControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// TestCase stubs esc_*/__; the normalizer also needs these two.
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$sep = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';
				return $url . $sep . http_build_query( $args );
			}
		);
	}

	public function test_normalize_product_maps_paid_product(): void {
		$raw = (object) array(
			'info'    => (object) array(
				'id'        => 127940,
				'slug'      => 'wb',
				'title'     => 'Интеграция WB',
				'excerpt'   => '<b>desc</b>',
				'permalink' => 'https://woodev.ru/downloads/wb',
				'link'      => 'https://woodev.ru/?p=127940',
				'thumbnails' => (object) array(
					'small'  => 'https://woodev.ru/s.jpg',
					'medium' => 'https://woodev.ru/m.jpg',
				),
				'thumbnail' => 'https://woodev.ru/t.jpg',
				'category'  => array(
					(object) array( 'slug' => 'woocommerce' ),
					(object) array( 'slug' => 'marketing' ),
				),
			),
			'pricing' => (object) array( 'amount' => '12500' ),
		);

		$out = \Woodev_REST_API_Extensions::normalize_product( $raw );

		$this->assertSame( 127940, $out['id'] );
		$this->assertSame( 'wb', $out['slug'] );
		$this->assertSame( 'Интеграция WB', $out['title'] );
		$this->assertSame( 12500, $out['price'] );
		$this->assertFalse( $out['free'] );
		$this->assertSame( 'https://woodev.ru/s.jpg', $out['thumbnail'] );
		$this->assertStringStartsWith( 'https://woodev.ru/downloads/wb', $out['permalink'] );
		$this->assertStringContainsString( 'utm_source=extensionsscreen', $out['permalink'] );
		$this->assertStringContainsString( 'utm_content=wb', $out['permalink'] );
		$this->assertSame( array( 'woocommerce', 'marketing' ), $out['categories'] );
	}

	public function test_normalize_product_free_and_thumbnail_and_link_fallback(): void {
		$raw = (object) array(
			'info'    => (object) array(
				'id'        => 5,
				'slug'      => 'free',
				'title'     => 'Free',
				'link'      => 'https://woodev.ru/?p=5',
				'thumbnail' => 'https://woodev.ru/only.jpg',
			),
			'pricing' => (object) array( 'amount' => '0' ),
		);

		$out = \Woodev_REST_API_Extensions::normalize_product( $raw );

		$this->assertSame( 0, $out['price'] );
		$this->assertTrue( $out['free'] );
		$this->assertSame( 'https://woodev.ru/only.jpg', $out['thumbnail'] );
		$this->assertStringStartsWith( 'https://woodev.ru/?p=5', $out['permalink'] );
		$this->assertSame( array(), $out['categories'] );
	}

	public function test_normalize_product_returns_null_without_id(): void {
		$raw = (object) array( 'info' => (object) array( 'title' => 'x' ) );

		$this->assertNull( \Woodev_REST_API_Extensions::normalize_product( $raw ) );
	}

	public function test_normalize_product_returns_null_without_info(): void {
		$raw = (object) array( 'pricing' => (object) array( 'amount' => '100' ) );

		$this->assertNull( \Woodev_REST_API_Extensions::normalize_product( $raw ) );
	}

	public function test_normalize_product_hides_coming_soon(): void {
		$base = array(
			'info'    => (object) array(
				'id'    => 7,
				'slug'  => 'soon',
				'title' => 'Soon',
				'link'  => 'https://woodev.ru/?p=7',
			),
			'pricing' => (object) array( 'amount' => '500' ),
		);

		// Flag on info._coming_soon hides it.
		$info_flag             = (object) $base;
		$info_flag->info->_coming_soon = true;
		$this->assertNull( \Woodev_REST_API_Extensions::normalize_product( $info_flag ) );

		// Flag on the top-level coming_soon hides it too.
		$top_flag               = (object) array(
			'info'        => (object) array(
				'id'    => 8,
				'slug'  => 'soon2',
				'title' => 'Soon 2',
				'link'  => 'https://woodev.ru/?p=8',
			),
			'pricing'     => (object) array( 'amount' => '500' ),
			'coming_soon' => true,
		);
		$this->assertNull( \Woodev_REST_API_Extensions::normalize_product( $top_flag ) );
	}

	public function test_normalize_product_prefers_product_icon(): void {
		$raw = (object) array(
			'info'    => (object) array(
				'id'            => 9,
				'slug'          => 'icon',
				'title'         => 'Icon',
				'link'          => 'https://woodev.ru/?p=9',
				'_product_icon' => 'https://woodev.ru/icon.svg',
				'thumbnails'    => (object) array( 'small' => 'https://woodev.ru/s.jpg' ),
			),
			'pricing' => (object) array( 'amount' => '500' ),
		);

		$out = \Woodev_REST_API_Extensions::normalize_product( $raw );

		$this->assertSame( 'https://woodev.ru/icon.svg', $out['thumbnail'] );
	}

	/**
	 * Stubs the HTTP layer so each fetched URL yields the given JSON body.
	 *
	 * @param string $categories_json Body returned for the categories URL.
	 * @param string $products_json   Body returned for the products URL.
	 *
	 * @return void
	 */
	private function stub_http( string $categories_json, string $products_json ): void {
		if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
			define( 'WEEK_IN_SECONDS', 604800 );
		}

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'wp_safe_remote_get' )->returnArg();
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $url ) use ( $categories_json, $products_json ) {
				return ( false !== strpos( (string) $url, 'categories' ) ) ? $categories_json : $products_json;
			}
		);
	}

	public function test_get_items_caches_only_complete_fetch(): void {
		$this->stub_http(
			'{"categories":[{"slug":"woocommerce","label":"WC"}]}',
			'{"products":[{"info":{"id":1,"slug":"a","title":"A","link":"https://woodev.ru/a"},"pricing":{"amount":"100"}}]}'
		);

		// A complete fetch (both non-empty) is cached exactly once.
		Functions\expect( 'set_transient' )->once();

		$payload = ( new \Woodev_REST_API_Extensions() )->get_items();

		$this->assertNotEmpty( $payload['products'] );
		$this->assertNotEmpty( $payload['categories'] );
		$this->assertFalse( $payload['stale'] );
	}

	public function test_get_items_does_not_cache_partial_fetch(): void {
		// Categories endpoint blips (empty body → null) while products succeed.
		$this->stub_http(
			'',
			'{"products":[{"info":{"id":1,"slug":"a","title":"A","link":"https://woodev.ru/a"},"pricing":{"amount":"100"}}]}'
		);

		// A partial fetch must NOT be cached — it stays retryable next load.
		Functions\expect( 'set_transient' )->never();

		$payload = ( new \Woodev_REST_API_Extensions() )->get_items();

		// Still served this request: products render, chips just missing.
		$this->assertNotEmpty( $payload['products'] );
		$this->assertSame( array(), $payload['categories'] );
		$this->assertFalse( $payload['stale'] );
	}

	public function test_get_items_marks_stale_when_products_empty(): void {
		// Products endpoint down → stale flag set, nothing cached.
		$this->stub_http(
			'{"categories":[{"slug":"woocommerce","label":"WC"}]}',
			''
		);

		Functions\expect( 'set_transient' )->never();

		$payload = ( new \Woodev_REST_API_Extensions() )->get_items();

		$this->assertSame( array(), $payload['products'] );
		$this->assertTrue( $payload['stale'] );
	}
}
