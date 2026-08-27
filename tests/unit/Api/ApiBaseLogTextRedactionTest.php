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
 * {@see \Woodev_API_Base::redact_secret_log_text()} is the seam these
 * boundaries route through instead of each hand-redacting the same free text
 * independently — an OPEN list, not an exhaustive one (see the method's own
 * docblock and issue #594 for known sinks that do not route through it yet):
 *
 * - {@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller::log_carrier_failure()}
 *   (covered functionally in PickupControllerTest.php);
 * - {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::log_carrier_failure()}
 *   (covered functionally in PickupHandlerTest.php);
 * - {@see \Woodev_Plugin_Updater::get_version_from_remote()}'s catch block
 *   (covered functionally in UpdaterLogRedactionTest.php);
 * - {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::log_failure()}
 *   (covered functionally in LocationControllerTest.php — #593).
 *
 * This file tests the seam itself: reuse of the existing
 * {@see \Woodev_API_Base::redact_secret_query_params()} scan, the
 * $extra_secret_names parameter, the
 * `woodev_api_log_text_secret_param_names` filter, the fatal-safety fix for a
 * malformed filter return (#585 critic round 2), and the scan's actual
 * name-based (not shape-based) coverage — both what it catches and what it
 * deliberately does not.
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

	// -------------------------------------------------------------------------
	// BLOCKING (#585 critic round 2) — a malformed filter return or a
	// malformed $extra_secret_names member must never throw, and must never
	// silently disable redaction entirely. Reproduced by the critic with
	// exactly `[ new stdClass() ]` reaching canonicalize_secret_param_name().
	// -------------------------------------------------------------------------

	public function test_a_filter_returning_an_unstringable_member_does_not_throw_and_falls_back_to_defaults(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				if ( 'woodev_api_log_text_secret_param_names' === $tag ) {
					return [ new \stdClass() ];
				}
				return $value;
			}
		);

		$result = \Woodev_API_Base::redact_secret_log_text( 'carrier rejected api_key=' . self::SECRET );

		$this->assertSame(
			'carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK,
			$result,
			'a filter returning an unusable member must fall back to the default list and still redact, never fatal'
		);
	}

	public function test_a_filter_returning_a_non_array_falls_back_to_defaults_and_still_redacts(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				if ( 'woodev_api_log_text_secret_param_names' === $tag ) {
					return false; // a misbehaving filter, e.g. `return false;` by mistake.
				}
				return $value;
			}
		);

		$result = \Woodev_API_Base::redact_secret_log_text( 'carrier rejected api_key=' . self::SECRET );

		$this->assertSame( 'carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK, $result );
	}

	public function test_extra_secret_names_with_malformed_members_does_not_throw(): void {
		$result = \Woodev_API_Base::redact_secret_log_text(
			'carrier rejected api_key=' . self::SECRET,
			[ 'dadata_key', new \stdClass(), null, [ 'nested' ] ]
		);

		$this->assertSame(
			'carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK,
			$result,
			'a malformed $extra_secret_names member must be dropped, never thrown on — the default list still applies'
		);
	}

	// -------------------------------------------------------------------------
	// Pinned COVERAGE — the two shapes the scan DOES catch, exact rendered
	// output. Both cases the critic confirmed ARE redacted.
	// -------------------------------------------------------------------------

	public function test_a_secret_in_a_query_string_is_redacted_exactly(): void {
		$result = \Woodev_API_Base::redact_secret_log_text( 'https://x.test/?token=' . self::SECRET . '&next=1' );

		$this->assertSame( 'https://x.test/?token=' . \Woodev_API_Base::SECRET_VALUE_MASK . '&next=1', $result );
	}

	public function test_a_name_equals_value_secret_is_redacted_exactly(): void {
		$result = \Woodev_API_Base::redact_secret_log_text( 'api_key=' . self::SECRET );

		$this->assertSame( 'api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK, $result );
	}

	// -------------------------------------------------------------------------
	// Pinned LIMITATION — these carry a real secret and are NOT redacted,
	// because the scan is a NAME-based `name=value` / `<name>value</name>`
	// scan, not a shape-based one — see the method's own docblock. Pinned
	// here so a future strengthening of the routine has a test telling it
	// exactly what changed, per the critic's explicit ask.
	// -------------------------------------------------------------------------

	public function test_a_bearer_token_with_no_name_prefix_is_not_redacted(): void {
		$text = 'Bearer eyJhbGciOi...';
		$this->assertSame( $text, \Woodev_API_Base::redact_secret_log_text( $text ) );
	}

	public function test_an_authorization_header_line_is_not_redacted(): void {
		$text = 'Authorization: Bearer xyz';
		$this->assertSame( $text, \Woodev_API_Base::redact_secret_log_text( $text ) );
	}

	public function test_a_json_shaped_secret_using_a_colon_is_not_redacted(): void {
		$text = '{"api_key":"LIVE"}';
		$this->assertSame( $text, \Woodev_API_Base::redact_secret_log_text( $text ) );
	}

	public function test_a_colon_separated_name_value_pair_is_not_redacted(): void {
		$text = 'api_key: LIVE';
		$this->assertSame( $text, \Woodev_API_Base::redact_secret_log_text( $text ) );
	}

	public function test_a_secret_named_only_in_prose_is_not_redacted(): void {
		$text = 'the key ' . self::SECRET . ' was rejected';
		$this->assertSame( $text, \Woodev_API_Base::redact_secret_log_text( $text ) );
	}

	public function test_a_bare_token_with_no_name_attached_at_all_is_not_redacted(): void {
		$text = self::SECRET . 'TOKENVALUE';
		$this->assertSame( $text, \Woodev_API_Base::redact_secret_log_text( $text ) );
	}

	// -------------------------------------------------------------------------
	// Pinned OVER-REDACTION — the scan runs over the WHOLE message, so
	// harmless prose matching the `name=value` shape is masked too, and
	// adjacent trailing punctuation is consumed into the masked value. This
	// is the accepted trade for a diagnostic log line, but it is a real,
	// visible behaviour — pinned so it is not discovered only while
	// debugging.
	// -------------------------------------------------------------------------

	public function test_harmless_prose_matching_the_name_equals_value_shape_is_over_redacted(): void {
		$result = \Woodev_API_Base::redact_secret_log_text( 'the retry token=next is enabled' );

		$this->assertSame( 'the retry token=' . \Woodev_API_Base::SECRET_VALUE_MASK . ' is enabled', $result );
	}

	public function test_trailing_punctuation_adjacent_to_a_redacted_value_is_consumed(): void {
		$result = \Woodev_API_Base::redact_secret_log_text( 'api_key=abc,' );

		$this->assertSame( 'api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK, $result );
	}
}
