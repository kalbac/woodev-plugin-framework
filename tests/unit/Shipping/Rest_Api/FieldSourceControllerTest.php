<?php
/**
 * Tests for Field_Source_Controller — the woodev/v1 checkout field-source
 * REST controller's WC-free core dispatch, input normalization and response
 * escaping (spec §6, §11 Codex hardening HIGH #1).
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Rest_Api\Field_Source_Controller;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-field-source-controller.php';

/**
 * Probe subclass that exposes the protected normalization seams and lets the
 * unit tests stub the WC-country validation + bypass the rate limiter.
 */
class Field_Source_Controller_Probe extends Field_Source_Controller {

	/**
	 * Whether {@see is_valid_country()} should treat everything as valid.
	 *
	 * @var bool
	 */
	public bool $country_valid = true;

	/**
	 * Exposes normalize_context() for direct assertions.
	 *
	 * @param array<string, mixed> $raw raw request params.
	 *
	 * @return array<string, string>
	 */
	public function normalize_context_public( array $raw ): array {
		return $this->normalize_context( $raw );
	}

	/**
	 * Exposes normalize_options() for direct assertions.
	 *
	 * @param array<int, mixed> $options raw source options.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function normalize_options_public( array $options ): array {
		return $this->normalize_options( $options );
	}

	/**
	 * Stubs the WC-country check so tests need no WooCommerce.
	 *
	 * @param string $code country code.
	 *
	 * @return bool
	 */
	protected function is_valid_country( string $code ): bool {
		return $this->country_valid;
	}

	/**
	 * Never rate-limits in unit tests.
	 *
	 * @return bool
	 */
	protected function is_rate_limited(): bool {
		return false;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Field_Source_Controller
 */
class FieldSourceControllerTest extends TestCase {

	/**
	 * Stubs the sanitizers the normalizers lean on so the WC-free path is exercised.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_clean' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : $value;
			}
		);

		// stubEscapeFunctions() returns the input verbatim; override esc_html so the
		// escaping contract is actually exercised.
		Functions\when( 'esc_html' )->alias(
			static function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES );
			}
		);
	}

	public function test_dispatch_returns_source_options(): void {
		$fields = Checkout_Fields::from_array(
			[
				Field::create( 'billing_city' )
					->set_source( static fn( $ctx ) => [ [ 'value' => 'msk', 'label' => 'Москва' ] ], 'suggest' )
					->to_array(),
			]
		);
		$ctrl   = new Field_Source_Controller( $fields, 'carrier' );

		$this->assertSame(
			[ [ 'value' => 'msk', 'label' => 'Москва' ] ],
			$ctrl->get_field_source( 'billing_city', [ 'q' => 'Мос' ] )
		);
	}

	public function test_dispatch_unknown_field_returns_empty(): void {
		$ctrl = new Field_Source_Controller( Checkout_Fields::from_array( [] ), 'carrier' );

		$this->assertSame( [], $ctrl->get_field_source( 'nope', [] ) );
	}

	public function test_dispatch_field_without_source_returns_empty(): void {
		$fields = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->to_array() ] );
		$ctrl   = new Field_Source_Controller( $fields, 'carrier' );

		$this->assertSame( [], $ctrl->get_field_source( 'billing_city', [] ) );
	}

	public function test_dispatch_passes_context_to_source(): void {
		$captured = null;
		$fields   = Checkout_Fields::from_array(
			[
				Field::create( 'billing_city' )
					->set_source(
						static function ( $ctx ) use ( &$captured ) {
							$captured = $ctx;
							return [];
						},
						'suggest'
					)
					->to_array(),
			]
		);
		$ctrl     = new Field_Source_Controller( $fields, 'carrier' );

		$ctrl->get_field_source( 'billing_city', [ 'q' => 'abc' ] );

		$this->assertSame( [ 'q' => 'abc' ], $captured );
	}

	public function test_normalize_context_caps_q_and_parent_to_128_chars(): void {
		$probe = new Field_Source_Controller_Probe( Checkout_Fields::from_array( [] ), 'carrier' );

		$long = str_repeat( 'a', 200 );
		$ctx  = $probe->normalize_context_public( [ 'q' => $long, 'parent' => $long ] );

		$this->assertSame( 128, strlen( $ctx['q'] ) );
		$this->assertSame( 128, strlen( $ctx['parent'] ) );
	}

	public function test_normalize_context_uppercases_valid_country(): void {
		$probe                = new Field_Source_Controller_Probe( Checkout_Fields::from_array( [] ), 'carrier' );
		$probe->country_valid = true;

		$ctx = $probe->normalize_context_public( [ 'country' => 'ru' ] );

		$this->assertSame( 'RU', $ctx['country'] );
	}

	public function test_normalize_context_drops_invalid_country(): void {
		$probe                = new Field_Source_Controller_Probe( Checkout_Fields::from_array( [] ), 'carrier' );
		$probe->country_valid = false;

		$ctx = $probe->normalize_context_public( [ 'country' => 'ZZ' ] );

		$this->assertSame( '', $ctx['country'] );
	}

	public function test_normalize_options_escapes_label_and_stringifies_value(): void {
		$probe = new Field_Source_Controller_Probe( Checkout_Fields::from_array( [] ), 'carrier' );

		$out = $probe->normalize_options_public(
			[
				[ 'value' => 42, 'label' => '<script>alert(1)</script>' ],
			]
		);

		$this->assertSame( '42', $out[0]['value'] );
		$this->assertStringContainsString( '&lt;script&gt;', $out[0]['label'] );
		$this->assertStringNotContainsString( '<script>', $out[0]['label'] );
	}

	public function test_normalize_options_drops_malformed_items(): void {
		$probe = new Field_Source_Controller_Probe( Checkout_Fields::from_array( [] ), 'carrier' );

		$out = $probe->normalize_options_public(
			[
				'not-an-array',
				[ 'label' => 'no value key' ],
				[ 'value' => 'ok', 'label' => 'Good' ],
			]
		);

		$this->assertCount( 1, $out );
		$this->assertSame( 'ok', $out[0]['value'] );
	}
}
