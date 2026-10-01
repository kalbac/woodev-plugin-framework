<?php
/**
 * Unit tests for Dadata_Provider::get_access_denied_cause() — the balance-API
 * explanation of a recorded suggestions 403 (#1060). The HTTP layer is stubbed;
 * a request log proves how many requests each scenario really made.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Providers\Dadata_Api_Client;
use Woodev\Framework\Shipping\Location\Providers\Dadata_Provider;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/interface-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/abstract-api-json-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-request.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-response.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-api-client.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/providers/class-dadata-provider.php';

/**
 * @covers \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::get_access_denied_cause
 */
final class DadataAccessDeniedCauseTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	/** @var array<string, mixed> */
	private array $transients = [];

	/** @var list<string> URLs of every HTTP request made. */
	private array $requests = [];

	/** @var array<string, int> TTLs passed to set_transient(). */
	private array $ttls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options    = [
			'woodev_location_token'                 => 'tok',
			'woodev_location_clean_secret'          => 'sec',
			Dadata_Api_Client::OPTION_ACCESS_DENIED => 1700000000,
		];
		$this->transients = [];
		$this->requests   = [];
		$this->ttls       = [];

		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'get_transient' )->alias( fn( $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl ) {
				$this->transients[ $name ] = $value;
				$this->ttls[ $name ]       = $ttl;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );

				return true;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( 'x' );
	}

	private function answer( int $code, string $body ): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_safe_remote_request' )->alias(
			function ( $url ) {
				$this->requests[] = $url;

				return [];
			}
		);
	}

	private static function provider(): Dadata_Provider {
		return new class extends Dadata_Provider {

			protected function make_client( string $token, string $secret ): Dadata_Api_Client {
				return new class( $token, $secret ) extends Dadata_Api_Client {

					protected function current_locale(): string {
						return '';
					}
				};
			}
		};
	}

	public function test_a_zero_or_negative_balance_means_the_balance_is_exhausted(): void {
		foreach ( [ '0', '-3.5' ] as $balance ) {
			$this->transients = [];
			$this->answer( 200, '{"balance": ' . $balance . '}' );

			$this->assertSame( Dadata_Provider::CAUSE_BALANCE_EXHAUSTED, self::provider()->get_access_denied_cause(), $balance );
		}
	}

	public function test_a_positive_balance_means_balance_positive(): void {
		$this->answer( 200, '{"balance": 41.2}' );

		$this->assertSame( Dadata_Provider::CAUSE_BALANCE_POSITIVE, self::provider()->get_access_denied_cause() );
		$this->assertSame( 12 * HOUR_IN_SECONDS, $this->ttls[ Dadata_Api_Client::TRANSIENT_ACCESS_DENIED_CAUSE ] );
	}

	public function test_a_balance_401_or_403_means_the_keys_are_rejected(): void {
		foreach ( [ 401, 403 ] as $code ) {
			$this->transients = [];
			$this->answer( $code, '' );

			$this->assertSame( Dadata_Provider::CAUSE_KEYS_REJECTED, self::provider()->get_access_denied_cause(), (string) $code );
		}
	}

	public function test_a_5xx_or_unreadable_answer_is_unknown_and_cached_for_a_short_while(): void {
		foreach ( [ [ 500, '' ], [ 200, 'not json' ] ] as [ $code, $body ] ) {
			$this->transients = [];
			$this->answer( $code, $body );

			$this->assertSame( Dadata_Provider::CAUSE_UNKNOWN, self::provider()->get_access_denied_cause() );
			$this->assertSame( 15 * MINUTE_IN_SECONDS, $this->ttls[ Dadata_Api_Client::TRANSIENT_ACCESS_DENIED_CAUSE ] );
		}
	}

	public function test_a_failed_lookup_is_not_retried_inside_the_cache_window(): void {
		$this->answer( 500, '' );

		self::provider()->get_access_denied_cause();
		self::provider()->get_access_denied_cause();
		self::provider()->get_access_denied_cause();

		$this->assertCount( 1, $this->requests );
	}

	public function test_a_cache_hit_makes_no_second_request(): void {
		$this->answer( 200, '{"balance": 0}' );

		self::provider()->get_access_denied_cause();
		$again = self::provider()->get_access_denied_cause();

		$this->assertSame( Dadata_Provider::CAUSE_BALANCE_EXHAUSTED, $again );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_cached_answer_for_an_older_state_is_not_reused(): void {
		$this->transients[ Dadata_Api_Client::TRANSIENT_ACCESS_DENIED_CAUSE ] = [
			'since' => 1600000000,
			'cause' => Dadata_Provider::CAUSE_KEYS_REJECTED,
		];
		$this->answer( 200, '{"balance": 0}' );

		$this->assertSame( Dadata_Provider::CAUSE_BALANCE_EXHAUSTED, self::provider()->get_access_denied_cause() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_without_a_secret_there_is_no_request_and_no_cache_entry(): void {
		$this->options['woodev_location_clean_secret'] = '';
		$this->answer( 200, '{"balance": 0}' );

		$this->assertSame( Dadata_Provider::CAUSE_UNKNOWN, self::provider()->get_access_denied_cause() );
		$this->assertSame( [], $this->requests );
		$this->assertSame( [], $this->transients );
	}

	public function test_without_a_recorded_403_there_is_no_request(): void {
		unset( $this->options[ Dadata_Api_Client::OPTION_ACCESS_DENIED ] );
		$this->answer( 200, '{"balance": 0}' );

		$this->assertSame( Dadata_Provider::CAUSE_UNKNOWN, self::provider()->get_access_denied_cause() );
		$this->assertSame( [], $this->requests );
	}

	public function test_the_suggestions_path_never_requests_the_balance(): void {
		$this->answer( 403, '' );

		try {
			self::provider()->suggest( 'Москва', Location_Scope::for_country( 'RU', 'region' ) );
		} catch ( \Throwable $e ) {
			// A refused suggest degrades or throws; which one is not what this pins.
			unset( $e );
		}

		$this->assertNotSame( [], $this->requests, 'the suggest request itself went out' );

		foreach ( $this->requests as $url ) {
			$this->assertStringNotContainsString( 'profile/balance', $url );
		}
	}
}
