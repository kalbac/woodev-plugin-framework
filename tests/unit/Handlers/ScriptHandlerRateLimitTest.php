<?php
/**
 * Woodev_Script_Handler nopriv rate-limit tests (issue #577).
 *
 * `ajax_log_event()` is registered for `wp_ajax_nopriv_wc_{id}_log_script_event` as well as the
 * authenticated action, and — before this fix — its only gate was a nonce the server itself
 * prints into the front-end script, so every guest who opens the checkout holds one. Issue #402
 * bounded what a SINGLE request could cost (control characters stripped, 500-character cap); it
 * said nothing about how MANY requests arrive. While logging is enabled, an unauthenticated
 * caller could otherwise flood the plugin log without limit.
 *
 * The fix reuses `Rest_Rate_Limit_Trait` — relocated out of the shipping-only namespace into
 * `Woodev\Framework\Http` for exactly this reuse (see `woodev/http/trait-rest-rate-limit.php`'s
 * own docblock) — as a Gate 1 check in `ajax_log_event()`, under a key prefix
 * (`woodev_script_log_rl_`) distinct from every shipping REST route's own bucket, so neither can
 * spend the other's budget.
 *
 * Deliberately does NOT override `is_rate_limited()` the way the shipping controllers' own
 * "Limited_Probe" test fixtures do — the trait's fixed-window math is already exhaustively
 * pinned in `tests/unit/Http/RestRateLimitTraitTest.php`; what this suite proves is that
 * `ajax_log_event()` is actually WIRED to the real trait, with the real prefix and the real
 * ceiling, end to end.
 *
 * @package Woodev\Tests\Unit\Handlers
 */

namespace Woodev\Tests\Unit\Handlers;

use Woodev\Tests\Unit\TestCase;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 3 ) . '/woodev/handlers/script-handler.php';

/**
 * Concrete handler driving the real `ajax_log_event()`, including the real rate-limit trait.
 */
class Script_Handler_Rate_Limit_Probe extends \Woodev_Script_Handler {

	/** @var string[] every message that reached log_event(), in order. */
	public $logged = [];

	/** @return string */
	public function get_id() {
		return 'probe';
	}

	/** @return string */
	public function get_id_dasherized() {
		return 'probe';
	}

	/** @param string $message */
	protected function log_event( $message ) {
		$this->logged[] = $message;
	}

	/** @return bool */
	protected function is_logging_enabled() {
		return true;
	}
}

/**
 * A second, independent trait consumer standing in for a shipping REST controller — just
 * enough to prove two different key prefixes over the SAME shared storage never spend each
 * other's budget, without constructing a real `Pickup_Controller` (a `WP_REST_Controller`,
 * heavy to build in isolation from its route registration).
 */
class Script_Handler_Rate_Limit_Shipping_Bucket_Probe {

	use \Woodev\Framework\Http\Rest_Rate_Limit_Trait;

	/**
	 * @param string $key_prefix transient key prefix.
	 * @param int    $max        requests allowed per window.
	 *
	 * @return bool
	 */
	public function check( string $key_prefix, int $max ): bool {
		return $this->is_rate_limited( $key_prefix, $max );
	}
}

/**
 * @covers \Woodev_Script_Handler::ajax_log_event
 */
class ScriptHandlerRateLimitTest extends TestCase {

	/** @var array<string, mixed> in-memory fake transient store, shared across probes exactly
	 *  like the real options table would be. */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();

		$this->store = [];

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_clean' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : $value;
			}
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				$this->store[ $key ] = $value;
				return true;
			}
		);

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$_POST = [];
		parent::tearDown();
	}

	/**
	 * Fires `ajax_log_event()` `$times` times with a valid nonce and message, and reports how
	 * many succeeded vs. the exact refusal messages of the rest.
	 *
	 * @param \Woodev_Script_Handler $handler handler under test.
	 * @param int                    $times   number of requests to fire.
	 *
	 * @return array{0: int, 1: string[]} [success count, refusal messages in order]
	 */
	private function fire_requests( \Woodev_Script_Handler $handler, int $times ): array {
		$successes = 0;
		$errors    = [];

		Functions\when( 'wp_send_json_success' )->alias(
			static function () use ( &$successes ) {
				++$successes;
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $message = null ) use ( &$errors ) {
				$errors[] = $message;
			}
		);

		for ( $i = 0; $i < $times; $i++ ) {
			$_POST = [
				'security' => 'nonce',
				'message'  => 'script load failed',
			];

			$handler->ajax_log_event();
		}

		return [ $successes, $errors ];
	}

	// -----------------------------------------------------------------------------
	// Under budget — the control. Without this, a limiter that refused everything
	// would also make the "refuses once exceeded" test below pass for the wrong reason.
	// -----------------------------------------------------------------------------

	public function test_requests_within_the_budget_still_log(): void {
		$handler = new Script_Handler_Rate_Limit_Probe();

		[ $successes, $errors ] = $this->fire_requests( $handler, 5 );

		$this->assertSame( 5, $successes );
		$this->assertSame( [], $errors );
		$this->assertCount( 5, $handler->logged, 'every accepted request must still reach log_event()' );
	}

	// -----------------------------------------------------------------------------
	// Over budget — the refusal, in the trait's own shape
	// -----------------------------------------------------------------------------

	/**
	 * self::LOG_RATE_LIMIT_MAX is `protected`, so it cannot be read from outside the class
	 * hierarchy; 60 is asserted directly here and its precedent is argued in the constant's own
	 * docblock (woodev/handlers/script-handler.php).
	 */
	public function test_the_nopriv_endpoint_refuses_once_the_limit_is_exceeded(): void {
		$handler = new Script_Handler_Rate_Limit_Probe();

		[ $successes, $errors ] = $this->fire_requests( $handler, 61 );

		$this->assertSame( 60, $successes, 'exactly LOG_RATE_LIMIT_MAX requests may pass' );
		$this->assertCount( 1, $errors, 'only the one request over budget is refused' );
		$this->assertSame(
			'Too many requests. Please slow down.',
			$errors[0],
			"the trait's own refusal shape, adapted to this handler's wp_send_json_error() response — same wording Field_Source_Controller's REST refusal uses"
		);
		$this->assertCount( 60, $handler->logged, 'the throttled request must never reach log_event()' );
	}

	// -----------------------------------------------------------------------------
	// Bucket isolation — the script-event bucket must never spend, or be spent by,
	// a shipping route's own budget
	// -----------------------------------------------------------------------------

	public function test_the_script_event_bucket_and_a_shipping_bucket_do_not_share_a_budget(): void {
		$handler  = new Script_Handler_Rate_Limit_Probe();
		$shipping = new Script_Handler_Rate_Limit_Shipping_Bucket_Probe();

		// Exhaust the script-log bucket completely, through the real handler, using the exact
		// prefix ajax_log_event() spends ('woodev_script_log_rl_').
		[ $successes ] = $this->fire_requests( $handler, 60 );
		$this->assertSame( 60, $successes, 'sanity: the script-log bucket really is exhausted' );

		// Pickup_Controller::handle_select_request()'s own literal prefix and ceiling
		// (SELECT_RATE_LIMIT_MAX = 15) — a real shipping bucket, over the SAME shared storage.
		// If the two ever collided on one budget, this would already read exhausted.
		$this->assertFalse(
			$shipping->check( 'woodev_pickup_sel_rl_', 15 ),
			"exhausting the script-log bucket must not spend a shipping route's own budget"
		);
	}
}
