<?php
/**
 * Unit tests for Woodev_API_Base::redact_secret_log_text() — #585.
 *
 * #451 closed the HTTP reason phrase at its point of assignment inside
 * Woodev_API_Base, which covers everything that class itself derives from a
 * response. It does NOT cover the text of an arbitrary `\Throwable` thrown by
 * code that may never pass through Woodev_API_Base at all — a plugin's
 * `Point_Source` / carrier client is an extension seam free to throw its own
 * exception, built from a live third-party SDK this class never sees.
 *
 * {@see \Woodev_API_Base::redact_secret_log_text()} is the single reusable
 * seam three boundaries route through instead of hand-redacting the same
 * free text independently:
 *
 * - {@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller::log_carrier_failure()}
 *   (covered functionally in PickupControllerTest.php);
 * - {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::log_carrier_failure()}
 *   (covered functionally in PickupHandlerTest.php);
 * - {@see \Woodev_Plugin_Updater::get_version_from_remote()}'s catch block
 *   (covered functionally in UpdaterLogRedactionTest.php).
 *
 * This file tests the seam itself: reuse of the existing
 * {@see \Woodev_API_Base::redact_secret_query_params()} scan, the
 * $extra_secret_names parameter, and the
 * `woodev_api_log_text_secret_param_names` filter — the two ways a plugin
 * author can extend the secret-name list for their own carrier.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace Woodev\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';

/**
 * Class ApiBaseLogTextRedactionTest.
 */
final class ApiBaseLogTextRedactionTest extends TestCase {

	/**
	 * The secret a hostile/foreign exception embeds in its message.
	 *
	 * @var string
	 */
	private const SECRET = 'LIVESECRET';

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	// -------------------------------------------------------------------------
	// The default list already redacts a known secret name.
	// -------------------------------------------------------------------------

	public function test_a_default_secret_name_is_redacted(): void {
		$result = \Woodev_API_Base::redact_secret_log_text( 'carrier rejected api_key=' . self::SECRET );

		$this->assertStringNotContainsString( self::SECRET, $result );
		$this->assertStringContainsString( \Woodev_API_Base::SECRET_VALUE_MASK, $result );
	}

	// -------------------------------------------------------------------------
	// Control — no control here and a redactor that blanked the string would
	// pass every other test in this file too.
	// -------------------------------------------------------------------------

	public function test_a_message_without_a_secret_passes_through_unchanged(): void {
		$this->assertSame(
			'carrier unreachable, retry later',
			\Woodev_API_Base::redact_secret_log_text( 'carrier unreachable, retry later' )
		);
	}

	public function test_an_empty_message_stays_empty(): void {
		$this->assertSame( '', \Woodev_API_Base::redact_secret_log_text( '' ) );
	}

	// -------------------------------------------------------------------------
	// $extra_secret_names — a caller can widen the list for its own call.
	// -------------------------------------------------------------------------

	public function test_extra_secret_names_param_extends_the_default_list(): void {
		$result = \Woodev_API_Base::redact_secret_log_text(
			'carrier rejected dadata_key=' . self::SECRET,
			[ 'dadata_key' ]
		);

		$this->assertStringNotContainsString( self::SECRET, $result );
	}

	public function test_a_name_not_in_the_default_or_extra_list_is_left_alone(): void {
		$result = \Woodev_API_Base::redact_secret_log_text(
			'carrier said dadata_key=' . self::SECRET,
			[ 'some_other_name' ]
		);

		// dadata_key was never added to the checked list, so its value survives.
		$this->assertStringContainsString( self::SECRET, $result );
	}

	// -------------------------------------------------------------------------
	// woodev_api_log_text_secret_param_names — the framework hook a plugin
	// author extends once, for every call site, instead of passing
	// $extra_secret_names at each one (#585's own point 3).
	// -------------------------------------------------------------------------

	public function test_the_filter_actually_extends_the_name_list(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				if ( 'woodev_api_log_text_secret_param_names' === $tag ) {
					$value[] = 'dadata_key';
				}
				return $value;
			}
		);

		$result = \Woodev_API_Base::redact_secret_log_text( 'carrier rejected dadata_key=' . self::SECRET );

		$this->assertStringNotContainsString( self::SECRET, $result );
	}

	public function test_the_filter_receives_the_default_list_and_the_text(): void {
		$captured_names = null;
		$captured_text  = null;

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null, $text = null ) use ( &$captured_names, &$captured_text ) {
				if ( 'woodev_api_log_text_secret_param_names' === $tag ) {
					$captured_names = $value;
					$captured_text  = $text;
				}
				return $value;
			}
		);

		\Woodev_API_Base::redact_secret_log_text( 'plain message' );

		$this->assertSame( \Woodev_API_Base::get_default_secret_param_names(), $captured_names );
		$this->assertSame( 'plain message', $captured_text );
	}

	/**
	 * Without the filter running at all, a NON-default name is left alone —
	 * proves the filter test above is actually exercising the filter, not a
	 * default list that already happened to contain 'dadata_key'.
	 */
	public function test_dadata_key_is_not_redacted_by_default(): void {
		$this->assertStringContainsString(
			self::SECRET,
			\Woodev_API_Base::redact_secret_log_text( 'carrier rejected dadata_key=' . self::SECRET )
		);
	}
}
