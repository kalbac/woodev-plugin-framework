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
		$this->assertSame( 'https://woodev.ru/m.jpg', $out['thumbnail'] );
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
}
