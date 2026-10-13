<?php
/**
 * Tests for the log-versus-screen split of the API request/response broadcast — #1252.
 *
 * `Woodev_API_Base::broadcast_request()` feeds the `woodev_{api_id}_api_request_performed`
 * action. Its consumers split into two kinds:
 *
 * - LOG only — the shipping plugin's {@see \Woodev_Plugin::log_api_request()} override and the
 *   licensing API's direct call. Their headers, JSON body secrets and URI query secrets are
 *   shown partially (`Bearer y0_A****xQ9z`), the operator's table from #1250. This is an explicit
 *   OPT-IN: the default (base plugin, unknown or missing plugin) is the full mask.
 * - LOG AND SCREEN — {@see \Woodev_Payment_Gateway::log_api_request()}, which writes the very
 *   same text to the log and to the storefront debug notice through `add_debug_message()`.
 *   It must keep getting the full `[REDACTED]` mask.
 *
 * The choice is {@see \Woodev_Plugin::is_api_broadcast_log_only()}, false in the base and true
 * only in the shipping plugin. Token-shaped literals are built at runtime so the secret scanner
 * has nothing to match.
 *
 * @package Woodev\Tests\Unit\Api
 */

namespace {

	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		/**
		 * Minimal WooCommerce gateway base for the gateway test double.
		 */
		class WC_Payment_Gateway {}
	}

	require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-request.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/api/interface-api-response.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/api/class-api-base.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/payment-gateway/api/interface-payment-gateway-api-response.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/payment-gateway/exceptions/class-payment-gateway-exception.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/payment-gateway/class-payment-gateway.php';

	if ( ! class_exists( 'Woodev_Test_Gateway_For_Broadcast_Mask', false ) ) {
		/**
		 * Concrete gateway double: add_debug_message() and log_api_request() are inherited unchanged.
		 */
		class Woodev_Test_Gateway_For_Broadcast_Mask extends \Woodev_Payment_Gateway {

			/** @var string */
			public $id = 'broadcast-gateway';

			/** Skips the real constructor (settings/hooks bootstrap). */
			public function __construct() {}

			/**
			 * @return array
			 */
			protected function get_method_form_fields(): array {
				return [];
			}

			/**
			 * @return string
			 */
			public function get_method_title() {
				return 'Test Gateway';
			}
		}
	}
}

namespace Woodev\Tests\Unit\Api {

	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * Plugin double recording what reaches its log.
	 */
	class Broadcast_Mask_Plugin extends \Woodev_Plugin {

		/** @var array<int, string> */
		public $logged = [];

		/** @return string */
		protected function get_file() {
			return 'acme-plugin/acme-plugin.php';
		}

		/** @return string */
		public function get_plugin_name() {
			return 'Acme Plugin';
		}

		/** @return int */
		public function get_download_id() {
			return 0;
		}

		/**
		 * @param string      $message Message.
		 * @param string|null $log_id  Log id.
		 * @return void
		 */
		public function log( $message, $log_id = null ) {
			$this->logged[] = $message;
		}
	}

	/**
	 * A plugin family that opted in: its broadcast reaches a log only.
	 */
	class Broadcast_Mask_Log_Only_Plugin extends Broadcast_Mask_Plugin {

		/** @return bool */
		public function is_api_broadcast_log_only(): bool {
			return true;
		}
	}

	/**
	 * JSON-style request: `to_string_safe()` masks in full, exactly like Woodev_API_JSON_Request.
	 */
	class Broadcast_Mask_Request implements \Woodev_API_Request {

		/** @var array<string, mixed> */
		private $params;

		/** @var string */
		private $path;

		/**
		 * @param array<string, mixed> $params Body params.
		 * @param string               $path   Request path.
		 */
		public function __construct( array $params, string $path ) {
			$this->params = $params;
			$this->path   = $path;
		}

		/** @return string */
		public function get_method() {
			return 'POST';
		}

		/** @return string */
		public function get_path() {
			return $this->path;
		}

		/** @return string */
		public function to_string() {
			return json_encode( $this->params );
		}

		/** @return string */
		public function to_string_safe() {
			return json_encode( \Woodev_API_Base::mask_secret_values( $this->params, \Woodev_API_Base::get_default_secret_param_names() ) );
		}
	}

	/**
	 * API double that fires the real broadcast.
	 */
	class Broadcast_Mask_Api extends \Woodev_API_Base {

		/** @var object */
		public $plugin;

		/**
		 * @param object $plugin Plugin double.
		 */
		public function __construct( $plugin ) {
			$this->plugin      = $plugin;
			$this->request_uri = 'https://carrier.example';
		}

		/**
		 * Seeds a performed exchange and fires the broadcast.
		 *
		 * @param array<string, mixed>  $request_params   Request body params.
		 * @param string                $path             Request path.
		 * @param array<string, string> $request_headers  Request headers.
		 * @param array<string, string> $response_headers Response headers.
		 * @param string                $response_body    Raw response body.
		 * @return void
		 */
		public function perform( array $request_params, string $path, array $request_headers, array $response_headers, string $response_body ): void {
			$this->request          = new Broadcast_Mask_Request( $request_params, $path );
			$this->request_headers  = $request_headers;
			$this->request_method   = 'POST';
			$this->request_duration = 0.1;
			$this->response_code    = 200;
			$this->response_headers = $response_headers;
			$this->response         = new class( $response_body ) {

				/** @var string */
				private $body;

				/** @param string $body Body. */
				public function __construct( string $body ) {
					$this->body = $body;
				}

				/** @return string */
				public function to_string_safe() {
					return $this->body;
				}
			};

			$this->broadcast_request();
		}

		/**
		 * Exposes the sanitizers a screen or cache consumer would call outside a broadcast.
		 *
		 * @return array<string, string>|null
		 */
		public function sanitized_request_headers_outside_broadcast() {
			return $this->get_sanitized_request_headers();
		}

		/** @return string */
		public function sanitized_request_body_outside_broadcast() {
			return $this->get_sanitized_request_body();
		}

		/** @return string */
		protected function get_request_user_agent() {
			return 'acme-test';
		}

		/** @return string */
		protected function get_api_id() {
			return 'acme-plugin';
		}

		/**
		 * @param array $args Unused.
		 * @return null
		 */
		protected function get_new_request( $args = [] ) {
			return null;
		}

		/** @return object */
		protected function get_plugin() {
			return $this->plugin;
		}
	}

	/**
	 * An API whose broadcast builders are overridden, as a plugin's own API class may do.
	 */
	class Broadcast_Mask_Api_With_Overridden_Builders extends Broadcast_Mask_Api {

		/** @return array<string, mixed> */
		protected function get_request_data_for_broadcast() {
			return array_merge( parent::get_request_data_for_broadcast(), [ 'extra' => 'kept' ] );
		}
	}

	/**
	 * Class ApiBaseBroadcastLogMaskTest.
	 */
	class ApiBaseBroadcastLogMaskTest extends TestCase {

		/**
		 * The shape of a real OAuth token, 40 characters, assembled at runtime.
		 *
		 * @return string
		 */
		private function token(): string {
			return 'y0_A' . str_repeat( 'k', 32 ) . 'xQ9z';
		}

		/**
		 * Runs one exchange through the broadcast and returns what the action received.
		 *
		 * @param Broadcast_Mask_Api $api API double.
		 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
		 */
		private function broadcast( Broadcast_Mask_Api $api ): array {
			$captured = [];

			Actions\expectDone( 'woodev_acme-plugin_api_request_performed' )
				->once()
				->whenHappen(
					static function ( $request, $response ) use ( &$captured ) {
						$captured = [ $request, $response ];
					}
				);

			$api->perform(
				[
					'token' => $this->token(),
					'city'  => 'Moscow',
				],
				'/v1/orders?api_key=' . $this->token(),
				[
					'Authorization' => 'Bearer ' . $this->token(),
					'X-Api-Key'     => $this->token(),
					'Accept'        => 'application/json',
				],
				[
					'Set-Cookie'   => [ $this->token() ],
					'Content-Type' => 'application/json',
				],
				json_encode( [ 'access_token' => $this->token(), 'status' => 'ok' ] )
			);

			return $captured;
		}

		/**
		 * A plugin that opted in to partial masking.
		 *
		 * @param class-string<Broadcast_Mask_Plugin> $class Plugin double class.
		 * @return Broadcast_Mask_Plugin
		 */
		private function log_plugin( string $class = Broadcast_Mask_Log_Only_Plugin::class ): Broadcast_Mask_Plugin {
			$plugin   = ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
			$property = new \ReflectionProperty( \Woodev_Plugin::class, 'id' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $plugin, 'acme-plugin' );

			return $plugin;
		}

		/**
		 * A plugin whose broadcast reaches a screen too, like the payment-gateway plugin.
		 *
		 * @return object
		 */
		private function screen_plugin() {
			return new class() {

				/** @return string */
				public function get_id() {
					return 'acme-plugin';
				}

				/** @return bool */
				public function is_api_broadcast_log_only(): bool {
					return false;
				}
			};
		}

		/**
		 * The log line carries the partial values for a header, a response header, a JSON body
		 * secret in both directions and a URI query secret.
		 *
		 * @return void
		 */
		public function test_log_consumer_gets_partial_masks_for_headers_bodies_and_uri(): void {
			$plugin = $this->log_plugin();

			[ $request, $response ] = $this->broadcast( new Broadcast_Mask_Api( $plugin ) );

			$plugin->log_api_request( $request, $response );
			$log = implode( "\n", $plugin->logged );

			$partial = 'y0_A****xQ9z';

			$this->assertStringContainsString( '[Authorization] => Bearer ' . $partial, $log );
			$this->assertStringContainsString( '[X-Api-Key] => ' . $partial, $log );
			$this->assertStringContainsString( '"token":"' . $partial . '"', $log, 'request body secret' );
			$this->assertStringContainsString( '"access_token":"' . $partial . '"', $log, 'response body secret' );
			$this->assertStringContainsString( 'api_key=' . $partial, $log, 'uri query secret' );
			$this->assertStringContainsString( '[0] => ' . $partial, $log, 'response Set-Cookie' );
			$this->assertStringNotContainsString( $this->token(), $log );
			$this->assertStringNotContainsString( '[REDACTED]', $log );
			$this->assertStringContainsString( '"city":"Moscow"', $log, 'a non-secret body field is untouched' );
		}

		/**
		 * A request whose own `to_string_safe()` already masked in full is still shown partially
		 * in the log, taken from the original body — and a non-string secret stays fully masked.
		 *
		 * @return void
		 */
		public function test_partial_body_secret_is_taken_from_the_original_body(): void {
			$plugin = $this->log_plugin();
			$api    = new Broadcast_Mask_Api( $plugin );

			$captured = null;
			Actions\expectDone( 'woodev_acme-plugin_api_request_performed' )
				->once()
				->whenHappen(
					static function ( $request ) use ( &$captured ) {
						$captured = $request;
					}
				);
			$api->perform( [ 'password' => 12345, 'n' => [ 'secret' => $this->token() ] ], '/v1/x', [], [], '' );

			$this->assertSame( '{"password":"[REDACTED]","n":{"secret":"y0_A****xQ9z"}}', $captured['body'] );
		}

		/**
		 * The payment-gateway plugin keeps the full mask in the broadcast, and the gateway notice
		 * built from it shows `[REDACTED]` only.
		 *
		 * @return void
		 */
		public function test_screen_consumer_keeps_the_full_mask(): void {
			[ $request, $response ] = $this->broadcast( new Broadcast_Mask_Api( $this->screen_plugin() ) );

			$this->assertSame( 'Bearer [REDACTED]', $request['headers']['Authorization'] );
			$this->assertSame( '[REDACTED]', $request['headers']['X-Api-Key'] );
			$this->assertSame( '{"token":"[REDACTED]","city":"Moscow"}', $request['body'] );
			$this->assertSame( [ '[REDACTED]' ], $response['headers']['Set-Cookie'] );
			$this->assertSame( '{"access_token":"[REDACTED]","status":"ok"}', $response['body'] );
			$this->assertStringContainsString( 'api_key=[REDACTED]', $request['uri'] );
			$this->assertStringNotContainsString( 'y0_A', print_r( [ $request, $response ], true ) );
		}

		/**
		 * End to end through the real gateway logger: the storefront notice shows `[REDACTED]`
		 * and no part of the token, and neither does the log.
		 *
		 * @return void
		 */
		public function test_gateway_notice_shows_redacted_for_the_same_exchange(): void {
			[ $request, $response ] = $this->broadcast( new Broadcast_Mask_Api( $this->screen_plugin() ) );

			$logged = [];
			$plugin = \Mockery::mock( \Woodev_Plugin::class )->makePartial();
			$plugin->shouldReceive( 'log' )->andReturnUsing(
				static function ( $message ) use ( &$logged ) {
					$logged[] = $message;
				}
			);

			$gateway = new \Woodev_Test_Gateway_For_Broadcast_Mask();
			$this->set_private( $gateway, 'debug_mode', \Woodev_Payment_Gateway::DEBUG_MODE_BOTH );
			$this->set_private( $gateway, 'plugin', $plugin );

			$GLOBALS['wp_current_filter'] = [];
			Functions\when( 'is_admin' )->justReturn( false );

			$notices = [];
			Functions\when( 'wc_add_notice' )->alias(
				static function ( $message ) use ( &$notices ) {
					$notices[] = $message;
				}
			);

			$gateway->log_api_request( $request, $response );

			$this->assertNotEmpty( $notices );
			$notice = implode( ' ', $notices );
			$this->assertStringContainsString( 'Bearer [REDACTED]', $notice );
			$this->assertStringContainsString( '[REDACTED]', $notice );
			$this->assertStringNotContainsString( 'y0_A', $notice );
			$this->assertStringNotContainsString( 'y0_A', implode( ' ', $logged ) );
		}

		/**
		 * The partial mask is confined to the broadcast: the same sanitizers called afterwards,
		 * by a cache or any other consumer, are back to the full mask.
		 *
		 * @return void
		 */
		public function test_partial_mode_does_not_outlive_the_broadcast(): void {
			$api = new Broadcast_Mask_Api( $this->log_plugin() );

			$this->broadcast( $api );

			$this->assertSame( 'Bearer [REDACTED]', $api->sanitized_request_headers_outside_broadcast()['Authorization'] );
			$this->assertSame( '{"token":"[REDACTED]","city":"Moscow"}', $api->sanitized_request_body_outside_broadcast() );
		}

		/**
		 * A plugin API that overrides the broadcast builders still gets the partial masks.
		 *
		 * @return void
		 */
		public function test_overridden_broadcast_builders_still_get_partial_masks(): void {
			[ $request ] = $this->broadcast( new Broadcast_Mask_Api_With_Overridden_Builders( $this->log_plugin() ) );

			$this->assertSame( 'kept', $request['extra'] );
			$this->assertSame( 'Bearer y0_A****xQ9z', $request['headers']['Authorization'] );
		}

		/**
		 * Plugin defaults: the base plugin and the payment-gateway plugin are NOT log-only; partial
		 * masking is an explicit opt-in.
		 *
		 * @return void
		 */
		public function test_plugin_defaults_to_full_mask_and_log_only_is_an_opt_in(): void {
			$this->assertFalse( $this->log_plugin( Broadcast_Mask_Plugin::class )->is_api_broadcast_log_only() );
			$this->assertTrue( $this->log_plugin()->is_api_broadcast_log_only() );

			require_once dirname( __DIR__, 3 ) . '/woodev/payment-gateway/class-payment-gateway-plugin.php';
			$gateway_plugin = \Mockery::mock( \Woodev_Payment_Gateway_Plugin::class )->makePartial();

			$this->assertFalse( $gateway_plugin->is_api_broadcast_log_only() );
		}

		/**
		 * A plugin that did not opt in (inherits the base default) gets the full mask everywhere.
		 *
		 * @return void
		 */
		public function test_base_plugin_gets_the_full_mask(): void {
			[ $request, $response ] = $this->broadcast( new Broadcast_Mask_Api( $this->log_plugin( Broadcast_Mask_Plugin::class ) ) );

			$this->assertSame( 'Bearer [REDACTED]', $request['headers']['Authorization'] );
			$this->assertSame( '{"token":"[REDACTED]","city":"Moscow"}', $request['body'] );
			$this->assertSame( '{"access_token":"[REDACTED]","status":"ok"}', $response['body'] );
			$this->assertStringNotContainsString( 'y0_A', print_r( [ $request, $response ], true ) );
		}

		/**
		 * Missing or unknown plugins are treated as FULL: null, an object without the method, and a
		 * method that returns something other than `true`.
		 *
		 * @return void
		 */
		public function test_missing_or_unknown_plugin_gets_the_full_mask(): void {
			$plugins = [
				'null'            => null,
				'plain object'    => new \stdClass(),
				'no method'       => new class() {

					/** @return string */
					public function get_id() {
						return 'acme-plugin';
					}
				},
				'non-true answer' => new class() {

					/** @return int */
					public function is_api_broadcast_log_only() {
						return 1;
					}
				},
			];

			foreach ( $plugins as $label => $plugin ) {
				[ $request, $response ] = $this->broadcast( new Broadcast_Mask_Api( $plugin ) );

				$this->assertSame( 'Bearer [REDACTED]', $request['headers']['Authorization'], $label );
				$this->assertSame( '{"token":"[REDACTED]","city":"Moscow"}', $request['body'], $label );
				$this->assertSame( [ '[REDACTED]' ], $response['headers']['Set-Cookie'], $label );
				$this->assertStringContainsString( 'api_key=[REDACTED]', $request['uri'], $label );
			}
		}

		/**
		 * Sets a private property via reflection, walking up to the declaring class.
		 *
		 * @param object $object   Target.
		 * @param string $property Property.
		 * @param mixed  $value    Value.
		 * @return void
		 */
		private function set_private( $object, string $property, $value ): void {
			for ( $class = get_class( $object ); $class; $class = get_parent_class( $class ) ) {
				if ( property_exists( $class, $property ) ) {
					break;
				}
			}

			$reflection = new \ReflectionProperty( $class, $property );
			if ( PHP_VERSION_ID < 80100 ) {
				$reflection->setAccessible( true );
			}
			$reflection->setValue( $object, $value );
		}
	}
}
