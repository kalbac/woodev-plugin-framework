<?php
/**
 * Unit tests for the redaction of the HTTP REASON PHRASE — #451.
 *
 * #427 (see ApiBaseSanitizedResponseBodyTest.php) closed the response BODY,
 * and closed it completely. The reason phrase is a different field that leaves
 * the class by different routes, so it survived that fix untouched:
 *
 * - the BROADCAST route — `get_response_data_for_broadcast()` puts it in
 *   `message`, `broadcast_request()` fires it, and the logging consumers
 *   ({@see \Woodev_Plugin::log_api_request()}, and the licensing API's own
 *   listener) write it out;
 * - the EXCEPTION-TEXT route — a provider reads `get_response_message()`
 *   directly and puts it in a `Woodev_API_Exception`, whose `getMessage()`
 *   callers hand to `error_log()`.
 *
 * The fix redacts AT ASSIGNMENT in `handle_response()` rather than at each of
 * those boundaries, so these tests drive `handle_response()` itself — the
 * point the value enters the object — and then check BOTH exits. Redacting per
 * boundary would have had to cover both, plus every boundary added later.
 *
 * The phrase DOES have a behavioural reader — `plugins-reference/` ships one,
 * woocommerce-edostavka's DaData client, which branches on
 * `str_starts_with( strtolower( $message ), 'unauthorized' )`. What makes
 * assignment safe is not the absence of such a reader but the SHAPE of the
 * redaction: it replaces only the value after a secret `name=`, or between
 * `<name></name>`, and leaves every other byte in place. The last two tests
 * here pin that, because it is the property the donor plugin depends on.
 *
 * Scope note: these doubles stop at the payload builder and the exception
 * constructor. They do not run `do_action()`, `Woodev_Plugin::log_api_request()`
 * or the licensing logger, so what they prove is that the value handed to those
 * consumers is already redacted — not that the loggers themselves behave.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';

/**
 * Drives the real `handle_response()` with the transport stubbed out.
 *
 * `get_parsed_response()` and `broadcast_request()` are overridden because
 * they need response-handler and request machinery this fix does not touch;
 * the broadcast override still builds its payload through the REAL
 * `get_response_data_for_broadcast()`, so the `message` value under test is
 * the one the production path would have fired.
 */
class Testable_Api_Base_For_Response_Message_Test extends \Woodev_API_Base {

	/** @var array<string, mixed>|null Payload the (stubbed) broadcast built. */
	public $broadcast_payload;

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
	 * Exposes the stored reason phrase — the value both exception sites read.
	 *
	 * @return string|null
	 */
	public function get_response_message_for_test() {
		return $this->get_response_message();
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
	 * Builds the payload through the real builder, without firing do_action().
	 *
	 * @return void
	 */
	protected function broadcast_request() {
		$this->broadcast_payload = $this->get_response_data_for_broadcast();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $args Request args.
	 * @return null
	 */
	protected function get_new_request( $args = [] ) {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return null
	 */
	protected function get_plugin() {
		return null;
	}
}

/**
 * Class ApiBaseResponseMessageRedactionTest.
 */
final class ApiBaseResponseMessageRedactionTest extends TestCase {

	/**
	 * The secret a hostile/echoing provider embeds in its reason phrase.
	 *
	 * @var string
	 */
	private const SECRET = 'super-secret-token-value';

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
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
	}

	/**
	 * Seeds a reason phrase and runs the real handle_response() over it.
	 *
	 * @param string $reason_phrase The phrase the transport reports.
	 * @return Testable_Api_Base_For_Response_Message_Test
	 */
	private function handle( string $reason_phrase ): Testable_Api_Base_For_Response_Message_Test {

		Functions\when( 'wp_remote_retrieve_response_message' )->justReturn( $reason_phrase );

		$api = new Testable_Api_Base_For_Response_Message_Test();
		$api->handle_response_for_test( [ 'stubbed' => true ] );

		return $api;
	}

	// -------------------------------------------------------------------------
	// Route 1 — the broadcast, which the logging consumers write out.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_reason_phrase_secret_does_not_reach_the_broadcast_payload(): void {

		$api = $this->handle( 'provider said token=' . self::SECRET );

		$this->assertIsArray( $api->broadcast_payload );
		$this->assertArrayHasKey( 'message', $api->broadcast_payload );
		$this->assertStringNotContainsString(
			self::SECRET,
			(string) $api->broadcast_payload['message'],
			'a reason phrase carrying a secret must not reach the broadcast payload intact'
		);
		$this->assertStringNotContainsString(
			self::SECRET,
			print_r( $api->broadcast_payload, true ),
			'the secret leaked somewhere else in the response broadcast payload'
		);
	}

	// -------------------------------------------------------------------------
	// Route 2 — the stored field every exception site reads directly.
	// -------------------------------------------------------------------------

	/**
	 * Both exception sites (the licensing API and the DaData client) build
	 * their message from `get_response_message()`, not from the broadcast, so
	 * the stored value itself has to be clean — that is the whole reason the
	 * redaction sits at assignment rather than in the broadcast builder.
	 *
	 * @return void
	 */
	public function test_reason_phrase_secret_does_not_survive_in_the_stored_field(): void {

		$api = $this->handle( 'provider said token=' . self::SECRET );

		$this->assertStringNotContainsString(
			self::SECRET,
			(string) $api->get_response_message_for_test(),
			'get_response_message() is what the exception sites embed — it must already be redacted'
		);
	}

	/**
	 * The same, for the `<tag>value</tag>` shape the redactor also understands,
	 * so the phrase is not merely covered for the `name=value` case.
	 *
	 * @return void
	 */
	public function test_reason_phrase_secret_in_xml_shape_is_masked(): void {

		$api = $this->handle( '<token>' . self::SECRET . '</token> rejected' );

		$this->assertStringNotContainsString(
			self::SECRET,
			(string) $api->get_response_message_for_test()
		);
	}

	// -------------------------------------------------------------------------
	// Controls — the redaction must not eat an ordinary reason phrase.
	// -------------------------------------------------------------------------

	/**
	 * Without a control here, a redactor that simply blanked the field would
	 * pass every assertion above.
	 *
	 * @return void
	 */
	public function test_an_ordinary_reason_phrase_passes_through_untouched(): void {

		$api = $this->handle( 'Internal Server Error' );

		$this->assertSame( 'Internal Server Error', $api->get_response_message_for_test() );
		$this->assertSame( 'Internal Server Error', $api->broadcast_payload['message'] );
	}

	/**
	 * A non-secret `name=value` pair in the phrase must survive: the redactor
	 * masks known secret names, never every parameter it can see.
	 *
	 * @return void
	 */
	public function test_a_non_secret_param_in_the_reason_phrase_is_preserved(): void {

		$api = $this->handle( 'rejected item_id=42' );

		$this->assertSame( 'rejected item_id=42', $api->get_response_message_for_test() );
	}

	/**
	 * An empty reason phrase stays empty rather than becoming a mask.
	 *
	 * @return void
	 */
	public function test_an_empty_reason_phrase_stays_empty(): void {

		$api = $this->handle( '' );

		$this->assertSame( '', $api->get_response_message_for_test() );
	}

	// -------------------------------------------------------------------------
	// The invariant a behavioural reader depends on — #451 critic pass.
	//
	// woocommerce-edostavka's DaData client (plugins-reference/) picks which
	// message the merchant sees with
	// `str_starts_with( strtolower( $phrase ), 'unauthorized' )`. Redaction runs
	// before that branch once the donor is migrated onto this framework, so the
	// branch is only safe if redaction cannot rewrite the LEADING text.
	// -------------------------------------------------------------------------

	/**
	 * The realistic case: an `Unauthorized` phrase that also carries a secret.
	 * The secret goes, the prefix the donor branches on stays.
	 *
	 * @return void
	 */
	public function test_redaction_preserves_a_leading_prefix_a_caller_branches_on(): void {

		$api    = $this->handle( 'Unauthorized token=' . self::SECRET . ' scope' );
		$phrase = (string) $api->get_response_message_for_test();

		$this->assertStringNotContainsString( self::SECRET, $phrase );
		$this->assertTrue(
			0 === strpos( strtolower( $phrase ), 'unauthorized' ),
			'redaction must not disturb the leading text: a downstream prefix test branches on it'
		);
		$this->assertStringEndsWith( ' scope', $phrase, 'text after the masked value must survive too' );
	}

	/**
	 * The adversarial case: a phrase that BEGINS with a secret pair. Redaction
	 * rewrites the value here, but it cannot flip the prefix test either way,
	 * because such a phrase does not start with `unauthorized` before redaction
	 * and does not start with it after — the param NAME is preserved.
	 *
	 * @return void
	 */
	public function test_a_phrase_beginning_with_a_secret_pair_cannot_flip_the_prefix_test(): void {

		$raw = 'token=' . self::SECRET . ' unauthorized';

		$api    = $this->handle( $raw );
		$phrase = (string) $api->get_response_message_for_test();

		$this->assertStringNotContainsString( self::SECRET, $phrase );
		$this->assertSame(
			0 === strpos( strtolower( $raw ), 'unauthorized' ),
			0 === strpos( strtolower( $phrase ), 'unauthorized' ),
			'the prefix test must return the same answer before and after redaction'
		);
		$this->assertStringStartsWith( 'token=', $phrase, 'the param name itself is never masked, only its value' );
	}
}
