<?php
/**
 * Unit tests for Dadata_Api_Client / Dadata_Api_Request / Dadata_Api_Response —
 * the framework API-layer plumbing under Dadata_Provider (Task 7).
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Client;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/interface-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/abstract-api-json-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-client.php';

/**
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Client
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Request
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Response
 */
final class DadataApiClientTest extends TestCase {

	/** @var array{url: string, args: array<string, mixed>}|null */
	private ?array $last_request = null;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->last_request = null;
	}

	private function stub_http_response( int $code, string $body ): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 200 === $code ? 'OK' : 'Error' );

		Functions\when( 'wp_safe_remote_request' )->alias(
			function ( $url, $args ) {
				$this->last_request = [
					'url'  => $url,
					'args' => $args,
				];

				return [];
			}
		);
	}

	// -------------------------------------------------------------------------
	// Headers
	// -------------------------------------------------------------------------

	public function test_authorization_header_carries_the_token(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );
		( self::client( 'my-token' ) )->suggest_address( 'q' );

		$this->assertSame( 'Token my-token', $this->last_request['args']['headers']['Authorization'] );
	}

	public function test_x_secret_header_is_absent_without_a_secret(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );
		( self::client( 'tok' ) )->suggest_address( 'q' );

		$this->assertArrayNotHasKey( 'X-Secret', $this->last_request['args']['headers'] );
	}

	public function test_x_secret_header_is_sent_when_a_secret_is_configured(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );
		( self::client( 'tok', 'my-secret' ) )->suggest_address( 'q' );

		$this->assertSame( 'my-secret', $this->last_request['args']['headers']['X-Secret'] );
	}

	// -------------------------------------------------------------------------
	// Broadcast — the woodev_location_dadata_api_request_performed action must
	// never carry the Clean secret in plaintext. Masking is now the base class's
	// job (issue #288: Woodev_API_Base::get_sanitized_request_headers() masks
	// every header named in get_secret_header_names(), which already
	// includes X-Secret by default) — this client no longer needs its own
	// override; these tests pin that the shared default still covers it.
	// -------------------------------------------------------------------------

	/**
	 * Pins the literal secret VALUE, not just the header key — a mask that
	 * happens to leave the key present-and-starred would satisfy a
	 * key-only assertion while the value still leaked through some other
	 * field (e.g. a different header, or the body).
	 */
	public function test_the_clean_secret_never_appears_anywhere_in_the_broadcast_payload(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );

		$secret    = 'top-secret-clean-value';
		$broadcast = null;

		Actions\expectDone( 'woodev_location_dadata_api_request_performed' )
			->once()
			->whenHappen(
				function ( $request_data, $response_data, $instance ) use ( &$broadcast ) {
					$broadcast = [ $request_data, $response_data ];
				}
			);

		( self::client( 'tok', $secret ) )->suggest_address( 'q' );

		$this->assertNotNull( $broadcast, 'the broadcast action must have fired' );

		$serialized = print_r( $broadcast, true );
		$this->assertStringNotContainsString( $secret, $serialized, 'the Clean secret leaked into the broadcast payload' );
	}

	/**
	 * The masked X-Secret header must follow the SAME masking convention the
	 * base class already applies to Authorization: the fixed
	 * {@see \Woodev_API_Base::SECRET_VALUE_MASK} placeholder, not a
	 * length-revealing run of `*` (#395 Round 3) and not merely absent.
	 */
	public function test_the_masked_x_secret_header_matches_the_authorization_masking_style(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );

		$secret    = 'my-secret';
		$broadcast = null;

		Actions\expectDone( 'woodev_location_dadata_api_request_performed' )
			->once()
			->whenHappen(
				function ( $request_data ) use ( &$broadcast ) {
					$broadcast = $request_data;
				}
			);

		( self::client( 'tok', $secret ) )->suggest_address( 'q' );

		$this->assertSame( \Woodev_API_Base::SECRET_VALUE_MASK, $broadcast['headers']['X-Secret'] );
	}

	// -------------------------------------------------------------------------
	// Endpoint / path / body construction
	// -------------------------------------------------------------------------

	public function test_suggest_address_posts_to_the_suggestions_host(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );
		( self::client( 'tok' ) )->suggest_address( 'Моск', [ 'count' => 5 ] );

		$this->assertSame( 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address', $this->last_request['url'] );
		$this->assertSame( 'POST', $this->last_request['args']['method'] );

		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( 'Моск', $body['query'] );
		$this->assertSame( 5, $body['count'] );
	}

	public function test_iplocate_address_gets_with_ip_in_the_query_string(): void {
		$this->stub_http_response( 200, '{"location":{"value":"x","data":{}}}' );
		( self::client( 'tok' ) )->iplocate_address( '1.2.3.4' );

		$this->assertSame( 'GET', $this->last_request['args']['method'] );
		$this->assertSame( '', $this->last_request['args']['body'] );
	}

	public function test_find_by_id_address_posts_the_fias_id_as_query(): void {
		$this->stub_http_response( 200, '{"suggestions":[{"value":"x","data":{}}]}' );
		( self::client( 'tok' ) )->find_by_id_address( 'fias-1' );

		$this->assertSame( 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/address', $this->last_request['url'] );
		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( 'fias-1', $body['query'] );
	}

	public function test_find_by_id_address_returns_null_for_an_empty_suggestions_array(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );

		$this->assertNull( ( self::client( 'tok' ) )->find_by_id_address( 'unknown-fias' ) );
	}

	public function test_clean_address_posts_to_the_cleaner_host_with_an_array_body(): void {
		$this->stub_http_response( 200, '[{"result":"x"}]' );
		( self::client( 'tok', 'sec' ) )->clean_address( 'мск сухонска 11/-89' );

		$this->assertSame( 'https://cleaner.dadata.ru/api/v1/clean/address', $this->last_request['url'] );

		// The body must be a JSON ARRAY (batch shape), not an object.
		$decoded = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( [ 'мск сухонска 11/-89' ], $decoded );
		$this->assertStringStartsWith( '[', $this->last_request['args']['body'] );
	}

	// -------------------------------------------------------------------------
	// Response parsing — array-wrapped vs bare-object clean result
	// -------------------------------------------------------------------------

	public function test_clean_address_accepts_an_array_wrapped_response(): void {
		$this->stub_http_response( 200, '[{"result":"г Москва"}]' );

		$this->assertSame( [ 'result' => 'г Москва' ], ( self::client( 'tok', 'sec' ) )->clean_address( 'q' ) );
	}

	public function test_clean_address_accepts_a_bare_object_response(): void {
		$this->stub_http_response( 200, '{"result":"г Москва"}' );

		$this->assertSame( [ 'result' => 'г Москва' ], ( self::client( 'tok', 'sec' ) )->clean_address( 'q' ) );
	}

	public function test_suggest_address_converts_stdclass_tree_to_plain_arrays(): void {
		$this->stub_http_response( 200, '{"suggestions":[{"value":"x","data":{"region":"Москва","nested":{"a":1}}}]}' );

		$suggestions = ( self::client( 'tok' ) )->suggest_address( 'q' );

		$this->assertIsArray( $suggestions[0]['data'] );
		$this->assertSame( 'Москва', $suggestions[0]['data']['region'] );
		$this->assertIsArray( $suggestions[0]['data']['nested'] );
		$this->assertSame( 1, $suggestions[0]['data']['nested']['a'] );
	}

	// -------------------------------------------------------------------------
	// Error handling
	// -------------------------------------------------------------------------

	public function test_a_200_response_does_not_throw(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );

		$this->assertSame( [], ( self::client( 'tok' ) )->suggest_address( 'q' ) );
	}

	public function test_a_401_response_throws_a_woodev_api_exception(): void {
		$this->stub_http_response( 401, '' );

		$this->expectException( \Woodev_API_Exception::class );
		( self::client( 'bad-token' ) )->suggest_address( 'q' );
	}

	public function test_a_500_response_throws_a_woodev_api_exception(): void {
		$this->stub_http_response( 500, 'Internal Server Error' );

		$this->expectException( \Woodev_API_Exception::class );
		( self::client( 'tok' ) )->suggest_address( 'q' );
	}

	public function test_a_network_level_wp_error_throws_a_woodev_api_exception(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_safe_remote_request' )->justReturn( new \WP_Error( 'timeout', 'Timed out' ) );

		$this->expectException( \Woodev_API_Exception::class );
		( self::client( 'tok' ) )->suggest_address( 'q' );
	}

	// -------------------------------------------------------------------------
	// #451 — the reason phrase this client embeds in its exception message is
	// free text off the wire, and the message reaches error_log() through
	// Pickup_Controller. Redaction happens at assignment in
	// Woodev_API_Base::handle_response(), so what this pins is that the client
	// really reads the redacted field rather than the raw phrase.
	// -------------------------------------------------------------------------

	public function test_a_reason_phrase_carrying_a_secret_never_reaches_the_exception_message(): void {
		$secret = 'super-secret-token-value';

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 'provider said token=' . $secret );
		Functions\when( 'wp_safe_remote_request' )->justReturn( [] );

		try {
			( self::client( 'tok' ) )->suggest_address( 'q' );
			$this->fail( 'A 500 response must throw.' );
		} catch ( \Woodev_API_Exception $e ) {
			$this->assertStringNotContainsString(
				$secret,
				$e->getMessage(),
				'the exception text is handed to error_log() by Pickup_Controller — it must not carry the secret'
			);
		}
	}

	public function test_an_ordinary_reason_phrase_still_reaches_the_exception_message(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 'Internal Server Error' );
		Functions\when( 'wp_safe_remote_request' )->justReturn( [] );

		try {
			( self::client( 'tok' ) )->suggest_address( 'q' );
			$this->fail( 'A 500 response must throw.' );
		} catch ( \Woodev_API_Exception $e ) {
			$this->assertStringContainsString(
				'Internal Server Error',
				$e->getMessage(),
				'control: the message really is built from the reason phrase, so the test above proves redaction and not an empty field'
			);
		}
	}

	// -------------------------------------------------------------------------
	// Response language (operator, s70) — DaData answers in Russian by default and
	// transliterates the WHOLE payload for `en`, while `fias_id` stays put, so the
	// locale may switch at any time without stranding a stored locality.
	// -------------------------------------------------------------------------

	public function test_suggest_asks_for_russian_under_a_russian_locale(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );

		self::client_in_locale( 'ru_RU' )->suggest_address( 'Моск' );

		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( 'ru', $body['language'] );
	}

	public function test_suggest_asks_for_english_under_a_non_russian_locale(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );

		self::client_in_locale( 'en_US' )->suggest_address( 'Kazan' );

		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( 'en', $body['language'] );
	}

	public function test_a_regional_russian_locale_still_asks_for_russian(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );

		self::client_in_locale( 'ru' )->suggest_address( 'Моск' );

		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( 'ru', $body['language'] );
	}

	public function test_find_by_id_carries_the_language_too(): void {
		$this->stub_http_response( 200, '{"suggestions":[{"value":"x","data":{}}]}' );

		self::client_in_locale( 'en_GB' )->find_by_id_address( 'fias-1' );

		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( 'en', $body['language'] );
	}

	public function test_iplocate_carries_the_language_in_its_query_string(): void {
		$this->stub_http_response( 200, '{"location":{"value":"x","data":{}}}' );

		self::client_in_locale( 'en_US' )->iplocate_address( '1.2.3.4' );

		$this->assertStringContainsString( 'language=en', (string) $this->last_request['url'] );
	}

	public function test_the_filter_overrides_the_derived_language_and_receives_the_locale(): void {
		$seen_locale = null;

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, $locale = null ) use ( &$seen_locale ) {
				if ( 'woodev_location_dadata_language' === $hook ) {
					$seen_locale = $locale;

					return 'ru';
				}

				return $value;
			}
		);

		$this->stub_http_response( 200, '{"suggestions":[]}' );

		self::client_in_locale( 'en_US' )->suggest_address( 'Моск' );

		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( 'ru', $body['language'] );
		$this->assertSame( 'en_US', $seen_locale );
	}

	public function test_a_language_dadata_does_not_know_is_coerced_rather_than_sent(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'woodev_location_dadata_language' === $hook ? 'de' : $value;
			}
		);

		$this->stub_http_response( 200, '{"suggestions":[]}' );

		self::client_in_locale( 'de_DE' )->suggest_address( 'Berlin' );

		$body = json_decode( (string) $this->last_request['args']['body'], true );

		// DaData rejects an unknown value outright, and a failed suggest reads to the
		// customer as a broken field — so an unusable filter result degrades, never ships.
		$this->assertSame( 'en', $body['language'] );
	}

	public function test_an_explicitly_passed_language_is_not_overwritten(): void {
		$this->stub_http_response( 200, '{"suggestions":[]}' );

		self::client_in_locale( 'en_US' )->suggest_address( 'Моск', [ 'language' => 'ru' ] );

		$body = json_decode( (string) $this->last_request['args']['body'], true );
		$this->assertSame( 'ru', $body['language'] );
	}

	/**
	 * A client whose locale seam is PINNED to `''` — the "express no opinion" value
	 * {@see Dadata_Api_Client::with_language()} reads as "send no `language` key".
	 *
	 * Every test in this class that does not care about the response language goes
	 * through here rather than `new Dadata_Api_Client(...)`. A bare construction
	 * reaches {@see Dadata_Api_Client::current_locale()}, which branches on
	 * `function_exists( 'get_locale' )` — and whether that function EXISTS depends on
	 * whether some earlier test class stubbed it, because Brain Monkey defines a
	 * stubbed function process-wide and PHP cannot un-define it. That made this suite
	 * green by ALPHABETICAL ACCIDENT: `tests/unit/handlers/` sorts last only because
	 * it is lowercase, and `--order-by=reverse` turned 55 tests in this layer red
	 * (issue #606). Pinning the seam removes the dependency on traversal order.
	 *
	 * @param string $token
	 * @param string $secret
	 *
	 * @return Dadata_Api_Client
	 */
	private static function client( string $token, string $secret = '' ): Dadata_Api_Client {
		return new class( $token, $secret ) extends Dadata_Api_Client {

			protected function current_locale(): string {
				return '';
			}
		};
	}

	/**
	 * A client pinned to `$locale` through the {@see Dadata_Api_Client::current_locale()}
	 * SEAM.
	 *
	 * Deliberately not `Functions\when( 'get_user_locale' )`: a Brain Monkey stub DEFINES
	 * the function for the whole process, so it survives into every later test class and
	 * flips any `function_exists()` branch there. Measured — stubbing it here turned 25
	 * unrelated `Dadata_Provider` tests red the moment the directory ran in one process,
	 * while each file still passed alone (gotcha
	 * `brain-monkey-wc-mock-defines-the-function-globally`).
	 *
	 * @param string $locale
	 *
	 * @return Dadata_Api_Client
	 */
	private static function client_in_locale( string $locale ): Dadata_Api_Client {
		return new class( $locale ) extends Dadata_Api_Client {

			private string $test_locale;

			public function __construct( string $locale ) {
				parent::__construct( 'tok' );

				$this->test_locale = $locale;
			}

			protected function current_locale(): string {
				return $this->test_locale;
			}
		};
	}
}
