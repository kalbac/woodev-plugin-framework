<?php
/**
 * Guards the live Yandex source's refusal to call out without a bearer token.
 *
 * WHY THIS IS ITS OWN FILE: the behaviour under test is "the token constant is NOT
 * defined", and a PHP constant cannot be un-defined once set. Its sibling
 * `TestLiveYandexPointSourceTest` defines a dummy token for the whole class, so asserting
 * the absent-token path from inside that class is impossible, and asserting it from any
 * class in the same process would be order-dependent — passing or failing according to
 * which test file PHPUnit happened to load first. Hence a separate file plus
 * `@runInSeparateProcess` / `@preserveGlobalState disabled`, the same isolation idiom
 * `BootstrapRegistrationTest` uses, and the one
 * `docs-internal/gotchas/brain-monkey-function-pollution.md` prescribes for
 * "this thing is absent" assertions.
 *
 * WHAT IT PROTECTS: the token was briefly committed as a literal into this PUBLIC
 * repository (s55) before being moved behind a constant. The security-relevant behaviour
 * of that fix is not merely "it throws" — it is that NO REQUEST LEAVES when the token is
 * missing, so a misconfigured install cannot silently authenticate as nobody, and so the
 * failure names its own cause instead of surfacing as an empty picker.
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
require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-live-yandex-point-source.php';

/**
 * @covers \Woodev_Test_Live_Yandex_Point_Source
 */
final class TestLiveYandexPointSourceTokenGuardTest extends TestCase {

	/**
	 * With the token constant undefined the source must throw AND must not perform the
	 * request at all.
	 *
	 * `wp_safe_remote_post` is expected `->never()`: asserting only on the exception would
	 * still pass if the guard were moved BELOW the request, which is precisely the mistake
	 * this test exists to prevent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_missing_token_throws_without_performing_any_request(): void {
		$this->assertFalse(
			defined( 'WOODEV_TEST_YANDEX_SANDBOX_TOKEN' ),
			'Process isolation failed: the token constant leaked in from another test file.'
		);

		Functions\expect( 'wp_safe_remote_post' )->never();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();

		$this->expectException( \Woodev_API_Exception::class );
		$this->expectExceptionMessageMatches( '/WOODEV_TEST_YANDEX_SANDBOX_TOKEN/' );

		$source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );
	}
}
