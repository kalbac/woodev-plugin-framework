<?php
/**
 * Unit: Abstract_Webhook_Handler's delivery-status sync-freshness recording (SP-10
 * spec D9, #828) — handle_request() is one of the two writers Delivery_Sync_Status
 * has, the other being a carrier's own cron (covered on Delivery_Sync_Status itself).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace {

	/**
	 * Minimal WP_REST_Response stand-in for unit context (no WordPress loaded).
	 * Guarded so a real class (or one defined by a sibling test) wins.
	 */
	if ( ! class_exists( 'WP_REST_Response', false ) ) {
		class WP_REST_Response {

			/** @var mixed */
			public $data;

			/** @var int */
			public $status;

			/**
			 * @param mixed $data   response body.
			 * @param int   $status HTTP status code.
			 */
			public function __construct( $data = null, int $status = 200 ) {
				$this->data   = $data;
				$this->status = $status;
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Filters;
	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Order\Abstract_Webhook_Handler;
	use Woodev\Framework\Shipping\Order\Delivery_Sync_Status;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * Minimal concrete handler. `$parsed_payload` is a test-controlled seam so a single
	 * fixture can drive different parsed shapes without a real carrier's wire format.
	 */
	final class Webhook_Handler_Sync_Fixture extends Abstract_Webhook_Handler {

		/** @var array<string,mixed> */
		public array $parsed_payload = [ 'status' => 'in_transit' ];

		protected function verify_signature( string $raw_body, array $headers ): bool {
			return true;
		}

		protected function parse_payload( string $raw_body ): array {
			return $this->parsed_payload;
		}

		protected function get_namespace(): string {
			return 'test-plugin/v1';
		}

		protected function get_route(): string {
			return '/webhook';
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Webhook_Handler::handle_request
	 */
	final class AbstractWebhookHandlerSyncTest extends TestCase {

		/**
		 * In-memory fake of the wp_options table, keyed by option name.
		 *
		 * @var array<string,mixed>
		 */
		private array $options = [];

		protected function setUp(): void {
			parent::setUp();

			$this->options = [];

			Functions\when( 'sanitize_key' )->alias(
				static function ( $key ) {
					$key = strtolower( (string) $key );
					return preg_replace( '/[^a-z0-9_\-]/', '', $key );
				}
			);

			Functions\when( 'update_option' )->alias(
				function ( $name, $value ) {
					$this->options[ $name ] = $value;
					return true;
				}
			);

			Functions\when( 'get_option' )->alias(
				function ( $name, $default = false ) {
					return $this->options[ $name ] ?? $default;
				}
			);

		}

		/**
		 * `time()` is a native PHP function Patchwork does not redefine by default
		 * (only `function_exists`/`error_log` are configured in patchwork.json), so
		 * this pins the recorded value via a real wall-clock bracket.
		 */
		public function test_processing_a_webhook_records_the_sync_for_the_hook_prefix(): void {
			$before  = time();
			$handler = new Webhook_Handler_Sync_Fixture( 'cdek' );

			$handler->handle_request( new \WP_REST_Request( [], [], '{}' ) );

			$after    = time();
			$recorded = Delivery_Sync_Status::get_last_updated( 'cdek' );

			$this->assertNotNull( $recorded );
			$this->assertGreaterThanOrEqual( $before, $recorded );
			$this->assertLessThanOrEqual( $after, $recorded );
		}

		/**
		 * A handler constructed with no `hook_prefix` (the constructor's own default) has
		 * no carrier id to key the timestamp under — it must not fatal, and it must not
		 * record anything reachable under a guessed key.
		 */
		public function test_a_blank_hook_prefix_records_nothing(): void {
			$handler = new Webhook_Handler_Sync_Fixture();

			Functions\expect( 'update_option' )->never();

			$handler->handle_request( new \WP_REST_Request( [], [], '{}' ) );
		}

		/**
		 * The `..._webhook_touches_delivery_status` filter is the escape hatch for a
		 * carrier whose webhook route also carries payloads that are NOT a status
		 * change — returning false must suppress the recording.
		 */
		public function test_the_touches_delivery_status_filter_can_suppress_recording(): void {
			Filters\expectApplied( 'woodev_shipping_cdek_webhook_touches_delivery_status' )
				->once()
				->andReturn( false );

			$handler = new Webhook_Handler_Sync_Fixture( 'cdek' );
			$handler->handle_request( new \WP_REST_Request( [], [], '{}' ) );

			$this->assertNull( Delivery_Sync_Status::get_last_updated( 'cdek' ) );
		}

		public function test_handle_request_still_acknowledges_the_webhook(): void {
			$handler = new Webhook_Handler_Sync_Fixture( 'cdek' );

			$response = $handler->handle_request( new \WP_REST_Request( [], [], '{}' ) );

			$this->assertInstanceOf( \WP_REST_Response::class, $response );
		}
	}
}
