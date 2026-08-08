<?php
/**
 * Guards `Woodev_Test_Live_Pochta_Point_Source`'s refusal to call the LISTING endpoint
 * without an account/settings id, and separately proves `fetch_details()` needs neither
 * constant at all — see that method's own docblock for why.
 *
 * WHY THIS IS ITS OWN FILE, AND WHY EACH METHOD IS ITS OWN PROCESS: the behaviour under test
 * hinges on which of `WOODEV_TEST_POCHTA_ACCOUNT_ID` / `WOODEV_TEST_POCHTA_SETTINGS_ID` is
 * defined, and a PHP constant cannot be un-defined once set. The sibling
 * `TestLivePochtaPointSourceTest` defines `WOODEV_TEST_POCHTA_SETTINGS_ID` for its whole
 * class, so asserting "only ACCOUNT_ID is defined" or "neither is defined" from inside that
 * class — or even from a different class in the SAME PHP process — is impossible/order
 * dependent. Hence `@runInSeparateProcess` / `@preserveGlobalState disabled` on every method
 * here, the same isolation idiom `TestLiveYandexPointSourceTokenGuardTest` uses.
 *
 * WHAT THE FIRST TWO TESTS PROTECT: the fixture reads `accountId`/`settings_id` from
 * constants, never a literal (see `docs-internal/gotchas/public-repo-third-party-credentials.md`).
 * The security-relevant behaviour is not merely "it throws" — it is that NO REQUEST LEAVES
 * when neither constant resolves, so a misconfigured install cannot silently call out with an
 * empty identity, and the failure names its own cause instead of surfacing as an empty map.
 *
 * WHAT THE THIRD TEST PROTECTS: `fetch_details()` deliberately does NOT require either
 * constant, because the measured `GET /api/pvz/{id}` contract carries no account-identifying
 * parameter — gating it the same way would guard a dependency that does not exist. This test
 * pins that as a deliberate design choice, not an oversight: with BOTH constants absent, a
 * stubbed `fetch_details()` call must still reach the (stubbed) transport, not throw.
 *
 * @package Woodev_Framework_Tests
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-live-pochta-point-source.php';

/**
 * @covers \Woodev_Test_Live_Pochta_Point_Source
 */
final class TestLivePochtaPointSourceAccountGuardTest extends TestCase {

	/**
	 * With NEITHER constant defined, `fetch_points()` must throw AND must not perform any
	 * request at all — `wp_safe_remote_post` is expected `->never()`, not merely asserted via
	 * the exception, which would still pass if the guard were moved BELOW the request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_missing_both_constants_throws_without_performing_any_request(): void {
		$this->assertFalse(
			defined( 'WOODEV_TEST_POCHTA_SETTINGS_ID' ),
			'Process isolation failed: the settings-id constant leaked in from another test file.'
		);
		$this->assertFalse(
			defined( 'WOODEV_TEST_POCHTA_ACCOUNT_ID' ),
			'Process isolation failed: the account-id constant leaked in from another test file.'
		);

		Functions\expect( 'wp_safe_remote_post' )->never();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();

		$this->expectException( \Woodev_API_Exception::class );
		$this->expectExceptionMessageMatches( '/WOODEV_TEST_POCHTA_SETTINGS_ID.*WOODEV_TEST_POCHTA_ACCOUNT_ID/' );

		$source->fetch_points( Point_Query::from_request( [ 'bbox' => '55.72,37.55,55.80,37.70' ] ) );
	}

	/**
	 * With only `WOODEV_TEST_POCHTA_ACCOUNT_ID` defined, `fetch_points()` resolves the
	 * `settings_id` via `POST /api/sites/public_show`, caches it, and proceeds to the listing
	 * call — the slow/resolution path this file's sibling never exercises (it always defines
	 * `SETTINGS_ID` directly).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_account_id_resolves_settings_id_via_public_show_and_caches_it(): void {
		$this->assertFalse(
			defined( 'WOODEV_TEST_POCHTA_SETTINGS_ID' ),
			'Process isolation failed: the settings-id constant leaked in from another test file.'
		);

		define( 'WOODEV_TEST_POCHTA_ACCOUNT_ID', 'dummy-account-for-tests' );

		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		// The settings cache starts empty; the points cache also starts empty.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->atLeast()->once();

		Functions\expect( 'wp_safe_remote_post' )->once()->with(
			'https://widget.pochta.ru/api/sites/public_show',
			\Mockery::on(
				static function ( array $args ): bool {
					$body = json_decode( $args['body'], true );

					return 'dummy-account-for-tests' === $body['accountId'] && 'wordpress' === $body['accountType'];
				}
			)
		)->andReturn( [ 'call' => 'settings' ] );

		Functions\expect( 'wp_safe_remote_post' )->once()->with(
			'https://widget.pochta.ru/api/pvz',
			\Mockery::on(
				static function ( array $args ): bool {
					$body = json_decode( $args['body'], true );

					return 41353 === $body['settings_id'];
				}
			)
		)->andReturn( [ 'call' => 'listing' ] );

		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				if ( 'settings' === ( $response['call'] ?? null ) ) {
					return json_encode( [ 'id' => 41353, 'showPostamat' => true ] );
				}

				return json_encode( [ 'data' => [], 'totalPages' => 1 ] );
			}
		);

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'bbox' => '55.72,37.55,55.80,37.70' ] ) );

		$this->assertSame( [], $points );
	}

	/**
	 * `fetch_details()` needs neither constant — see the file docblock's own explanation.
	 * With BOTH constants absent, a stubbed successful details call must still succeed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_fetch_details_needs_no_account_constants(): void {
		$this->assertFalse( defined( 'WOODEV_TEST_POCHTA_SETTINGS_ID' ) );
		$this->assertFalse( defined( 'WOODEV_TEST_POCHTA_ACCOUNT_ID' ) );

		Functions\when( 'wp_safe_remote_get' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				[
					'id'      => 26600,
					'type'    => 'russian_post',
					'geo'     => [ 'coordinates' => [ 37.855554, 55.740522 ] ],
					'address' => [ 'place' => 'Москва', 'street' => 'Новокосинская', 'house' => '17' ],
					'deliveryPointIndex' => 111673,
					'cashPayment'        => true,
				]
			)
		);

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$point  = $source->fetch_details( '26600' );

		$this->assertNotNull( $point );
		$this->assertTrue( $point->get_accepts_cod() );
	}
}
