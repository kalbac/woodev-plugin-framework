<?php
/**
 * The LICENSING route of #451 — the third one the card names.
 *
 * `Woodev_Licensing_API::do_post_parse_response_validation()` throws a
 * `Woodev_API_Exception` built from `get_response_message()` on any >= 400,
 * and `Woodev_Plugin_Updater::get_version_from_remote()` hands that message
 * to `error_log()`. So the reason phrase reaches a log here by a path that
 * has nothing to do with the broadcast the other tests cover.
 *
 * This drives the REAL `handle_response()` — which is what applies the
 * redaction, and which calls the licensing validation itself — rather than
 * seeding the field and asserting the throw echoes it. Seeding would only
 * have proved the two halves separately; this proves the whole path.
 *
 * The message does not stop at a log, either: {@see Woodev_REST_API_License}'s
 * `respond()` returns a caught Throwable's message to the caller as a
 * WP_Error, so the React licence page shows it to an administrator.
 *
 * Scope note: the double bypasses the constructor and the broadcast, so this
 * proves the exception is BUILT from a redacted value. It does not exercise
 * the updater's `error_log()` call or the REST responder themselves.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/licensing/api/class-licensing-api.php';

/**
 * The real licensing API with only the two collaborators this test cannot
 * supply stubbed: the response handler (needs a parsed-response class) and
 * the broadcast (needs a Woodev_Plugin). The constructor is bypassed for the
 * same reason — it only wires those collaborators, and none of them takes
 * part in building the exception message under test.
 */
class Testable_Licensing_Api_For_Reason_Phrase extends \Woodev_Licensing_API {

	/**
	 * Bypasses the real constructor, which needs a Woodev_Plugin.
	 */
	public function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- deliberately does NOT call parent.
	}

	/**
	 * Runs the real handle_response() over a stubbed transport result.
	 *
	 * @param mixed $response Whatever the transport returned.
	 * @return mixed
	 */
	public function handle_response_for_test( $response ) {
		return $this->handle_response( $response );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $raw_response_body Raw body.
	 * @return object
	 */
	protected function get_parsed_response( $raw_response_body ) {
		return new \stdClass();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function broadcast_request() {
	}
}

/**
 * Class LicensingApiReasonPhraseRedactionTest.
 */
final class LicensingApiReasonPhraseRedactionTest extends TestCase {

	/**
	 * @var string
	 */
	private const SECRET = 'super-secret-license-value';

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 400 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
	}

	/**
	 * Runs the real path and returns the exception it threw.
	 *
	 * @param string $reason_phrase The phrase the transport reports.
	 * @return \Woodev_API_Exception
	 */
	private function thrown_for( string $reason_phrase ): \Woodev_API_Exception {

		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( $reason_phrase );

		try {
			( new Testable_Licensing_Api_For_Reason_Phrase() )->handle_response_for_test( [ 'stubbed' => true ] );
		} catch ( \Woodev_API_Exception $e ) {
			return $e;
		}

		$this->fail( 'A >= 400 response must throw a Woodev_API_Exception.' );
	}

	/**
	 * The updater logs this message verbatim.
	 *
	 * @return void
	 */
	public function test_a_reason_phrase_carrying_a_secret_never_reaches_the_logged_exception(): void {

		$e = $this->thrown_for( 'rejected license=' . self::SECRET );

		$this->assertStringNotContainsString(
			self::SECRET,
			$e->getMessage(),
			'Woodev_Plugin_Updater hands this message straight to error_log()'
		);
	}

	/**
	 * Control: without it, a change that emptied the message would pass the
	 * assertion above while destroying the diagnostic.
	 *
	 * @return void
	 */
	public function test_an_ordinary_reason_phrase_still_reaches_the_exception(): void {

		$e = $this->thrown_for( 'Bad Request' );

		$this->assertSame( 'Bad Request', $e->getMessage() );
	}
}
