<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Script_Handler' ) ) :


	/**
	 * Script Handler Abstract Class
	 *
	 * Handles initializing the payment registered JavaScripts
	 */
	abstract class Woodev_Script_Handler {

		use \Woodev\Framework\Http\Rest_Rate_Limit_Trait;

		/** @var string JS handler base class name */
		protected $js_handler_base_class_name = '';

		/**
		 * Maximum nopriv log-event requests allowed per IP per rate-limit window.
		 *
		 * The 60-SECOND window is inherited: {@see \Woodev\Framework\Http\Rest_Rate_Limit_Trait::is_rate_limited()}'s
		 * `$window` parameter defaults to 60 and this call leaves it alone. The 60-REQUEST
		 * budget is NOT inherited — `is_rate_limited()`'s `$max` has no default, so every
		 * caller picks its own — and it is precedent-anchored, not measured: no production
		 * traffic figure exists for this nopriv logging endpoint. It targets the trait's
		 * nearest comparable public, read-shaped buckets —
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Field_Source_Controller::RATE_LIMIT_MAX},
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::SUGGEST_RATE_LIMIT_MAX}
		 * and {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::LIST_RATE_LIMIT_MAX}
		 * — all 60/min — against a 15-240/min spread across the nine buckets that predate
		 * it, so 60 sits mid-range for a read-shaped budget rather than at either extreme
		 * (issue #577).
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const LOG_RATE_LIMIT_MAX = 60;

		/**
		 * Script_Handler constructor.
		 */
		public function __construct() {
			// add the action and filter hooks
			$this->add_hooks();
		}


		/**
		 * Adds the action and filter hooks.
		 */
		protected function add_hooks() {
			add_action( 'wp_ajax_wc_' . $this->get_id() . '_log_script_event', array( $this, 'ajax_log_event' ) );
			add_action(
				'wp_ajax_nopriv_wc_' . $this->get_id() . '_log_script_event',
				array(
					$this,
					'ajax_log_event',
				)
			);
		}


		/**
		 * Returns the JS handler class name.
		 *
		 * @return string
		 */
		protected function get_js_handler_class_name() {
			return $this->js_handler_base_class_name;
		}


		/**
		 * Returns the JS handler object name.
		 *
		 * @return string
		 */
		protected function get_js_handler_object_name() {
			return 'wc_' . $this->get_id() . '_handler';
		}


		/**
		 * Gets the JS event triggered after the JS handler class is loaded.
		 *
		 * @return string
		 */
		protected function get_js_loaded_event() {
			return sprintf( '%s_loaded', strtolower( $this->get_js_handler_class_name() ) );
		}


		/**
		 * Gets the handler instantiation JS wrapped in a safe load technique.
		 *
		 * @param array  $additional_args additional handler arguments, if any
		 * @param string $handler_name handler name, if different from Woodev_Script_Handler::get_js_handler_class_name()
		 * @param string $object_name object name, if different from Woodev_Script_Handler::get_js_handler_object_name()
		 *
		 * @return string
		 */
		protected function get_safe_handler_js( array $additional_args = array(), $handler_name = '', $object_name = '' ) {

			if ( ! $handler_name ) {
				$handler_name = $this->get_js_handler_class_name();
			}

			$load_function = 'load_' . $this->get_id() . '_handler';

			ob_start();

			?>
			function <?php echo esc_js( $load_function ); ?>() {
				<?php echo $this->get_handler_js( $additional_args, $handler_name, $object_name ); ?>
			}

			try {

				if ( 'undefined' !== typeof <?php echo esc_js( $handler_name ); ?> ) {
					<?php echo esc_js( $load_function ); ?>();
				} else {
					window.jQuery( document.body ).on( '<?php echo esc_js( $this->get_js_loaded_event() ); ?>', <?php echo esc_js( $load_function ); ?> );
				}

			} catch ( err ) {
				<?php echo $this->get_js_handler_event_debug_log_request(); ?>
			}
			<?php

			return ob_get_clean();
		}


		/**
		 * Gets the handler instantiation JS.
		 *
		 * @param array  $additional_args additional handler arguments, if any
		 * @param string $handler_name handler name, if different from self::get_js_handler_class_name()
		 * @param string $object_name object name, if different from self::get_js_handler_object_name()
		 *
		 * @return string
		 */
		protected function get_handler_js( array $additional_args = array(), $handler_name = '', $object_name = '' ) {

			$args = array_merge( $additional_args, $this->get_js_handler_args() );

			/**
			 * Filters the JavaScript handler arguments.
			 *
			 * @param array $args arguments to pass to the JS handler
			 * @param Woodev_Script_Handler $handler script handler instance
			 */
			$args = apply_filters( 'wc_' . $this->get_id() . '_js_args', $args, $this );

			if ( ! $handler_name ) {
				$handler_name = $this->get_js_handler_class_name();
			}

			if ( ! $object_name ) {
				$object_name = $this->get_js_handler_object_name();
			}

			return sprintf( 'window.%1$s = new %2$s( %3$s );', esc_js( $object_name ), esc_js( $handler_name ), wp_json_encode( $args ) );
		}


		/**
		 * Gets the JS handler arguments.
		 *
		 * @return array
		 */
		protected function get_js_handler_args() {
			return array();
		}


		/**
		 * Gets inline JavaScript code to issue an AJAX request to log a script error event.
		 *
		 * @return string
		 */
		protected function get_js_handler_event_debug_log_request() {

			ob_start();

			?>

			var errorName    = '',
			errorMessage = '';

			if ( 'undefined' === typeof err || 0 === err.length || ! err ) {
				errorName    = '<?php echo esc_js( 'A script error has occurred.' ); ?>';
				errorMessage = '<?php echo esc_js( sprintf( 'The script %s could not be loaded.', $this->get_js_handler_class_name() ) ); ?>';
			} else {
				errorName    = 'undefined' !== typeof err.name    ? err.name    : '';
				errorMessage = 'undefined' !== typeof err.message ? err.message : '';
			}

			<?php if ( $this->is_logging_enabled() ) : ?>

				console.log( [ errorName, errorMessage ].filter( Boolean ).join( ' ' ) );

			<?php endif; ?>

			jQuery.post( '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
				action:   '<?php echo esc_js( 'wc_' . $this->get_id() . '_log_script_event' ); ?>',
				security: '<?php echo esc_js( wp_create_nonce( 'wc-' . $this->get_id_dasherized() . '-log-script-event' ) ); ?>',
				name:     errorName,
				message:  errorMessage
			} );

			<?php

			return ob_get_clean();
		}


		/**
		 * Logs an event via AJAX.
		 *
		 * @internal
		 */
		public function ajax_log_event() {

			// silently bail if nothing should be logged
			if ( ! $this->is_logging_enabled() ) {
				return;
			}

			try {

				// Gate 1: rate-limit — a flooded window short-circuits with no further work.
				// Own key prefix (issue #577): this bucket must never share a budget with a
				// shipping REST route's, or one workload could exhaust the other's.
				if ( $this->is_rate_limited( 'woodev_script_log_rl_', self::LOG_RATE_LIMIT_MAX ) ) {
					throw new Woodev_Plugin_Exception( 'Too many requests. Please slow down.' );
				}

				if ( ! wp_verify_nonce( Woodev_Helper::get_posted_value( 'security' ), 'wc-' . $this->get_id_dasherized() . '-log-script-event' ) ) {
					throw new Woodev_Plugin_Exception( 'Invalid nonce.' );
				}

				$name    = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? self::sanitize_log_field( $_POST['name'] ) : '';
				$message = isset( $_POST['message'] ) && is_string( $_POST['message'] ) ? self::sanitize_log_field( $_POST['message'] ) : '';

				if ( ! $message ) {
					throw new Woodev_Plugin_Exception( 'A message is required.' );
				}

				if ( $name ) {
					$message = "{$name} {$message}";
				}

				$this->log_event( $message );

				wp_send_json_success();

			} catch ( Woodev_Plugin_Exception $exception ) {

				wp_send_json_error( $exception->getMessage() );
			}
		}


		/**
		 * Makes one posted field safe to put on a line of the plugin log (issue #402).
		 *
		 * THE LINE IS THE RECORD. A log file has no framing beyond the newline, so a value
		 * carrying one does not get logged *containing* a line break — it APPENDS A LINE, and
		 * the forged line is indistinguishable from anything the framework itself wrote. This
		 * endpoint is registered for `nopriv` as well (`wp_ajax_nopriv_wc_{id}_log_script_event`)
		 * and its only gate is a nonce the server prints into the front-end script, so every
		 * guest on the checkout holds one. `trim()`, which is all this used to do, removes
		 * leading and trailing whitespace and leaves every interior newline in place.
		 *
		 * Every C0/C1 control character is replaced, not just \n and \r: a bare \r
		 * rewrites a line in a terminal, `\x1b` opens an ANSI escape, and `\x00` truncates
		 * the string for anything reading it with C semantics. Replaced with a SPACE rather
		 * than removed, so `A\nB` cannot silently become the single token `AB`.
		 *
		 * The cap is the other half of the same defect. An unauthenticated caller could
		 * otherwise put a megabyte on one line; 500 characters is well past any real script
		 * event (the longest this framework emits is a stack-free error string) and bounds
		 * what a single request can cost. It does NOT bound how MANY requests arrive — that
		 * is the flood half of #402, which needed a rate-limit policy rather than a cap; see
		 * {@see self::ajax_log_event()}'s own {@see \Woodev\Framework\Http\Rest_Rate_Limit_Trait}
		 * gate (issue #577).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param string $value raw posted value.
		 * @return string
		 */
		protected static function sanitize_log_field( string $value ): string {

			$stripped = preg_replace( '/[\x00-\x1f\x7f-\x9f]+/u', ' ', $value );

			// A malformed-UTF-8 payload makes the `/u` pass return NULL, and a caller who can
			// post one is exactly the caller this function exists for. Fall back to a
			// byte-wise pattern against the ORIGINAL value — never against the null result,
			// which would quietly reduce every mis-encoded message to an empty string and
			// hand the attacker a way to make this function do nothing.
			//
			// THE TWO PATTERNS MUST COVER THE SAME RANGE. This fallback used to stop at
			//  and omit C1 (-), so on this branch a raw  — the single-byte
			// ANSI CSI — was never replaced.
			//
			// MEASURED, and the measurement corrected the report it came from. A critic pass
			// found the gap by probing the two patterns in ISOLATION. Driving the whole
			// function instead shows the byte did not in fact reach the log: `mb_substr()` at
			// the end rewrites invalid UTF-8, so `c328419b42` came out as `3f28413f42`. The
			// gap was real and MASKED — by an optional extension doing sanitising work nobody
			// asked it for. Without mbstring the old byte-wise cap did no such rewriting and
			// the byte survived, which is the same defect as the cap's (see
			// {@see self::cap_characters()}) wearing a different hat.
			//
			// So the rule is: this function must be correct on its own, never because an
			// undeclared extension happens to be loaded.
			//
			// Without /u the class is BYTES, which is what this branch wants: the input is not
			// valid UTF-8, so there are no codepoints to speak of, and a raw C1 byte is
			// precisely what has to go.
			$value = null !== $stripped ? $stripped : (string) preg_replace( '/[\x00-\x1f\x7f-\x9f]+/', ' ', $value );

			$value = trim( $value );

			return self::cap_characters( $value, 500 );
		}

		/**
		 * Caps `$value` to `$max` CHARACTERS without ever cutting a UTF-8 sequence in half.
		 *
		 * `mb_substr()` when mbstring is there. When it is not — and it is NOT a declared
		 * requirement of this package, so "it is always there" was an assumption, not a fact —
		 * the fallback used to be a byte-wise `substr()`, which cuts mid-character: 499 ASCII
		 * characters followed by «я» came back as 500 bytes ending in a lone `0xd1`, i.e.
		 * invalid UTF-8 written into the log. Found by a critic pass and reproduced.
		 *
		 * The regex fallback counts UTF-8 SEQUENCES rather than bytes, so it caps where
		 * `mb_substr()` would and can only cut on a character boundary. `/u` is safe here
		 * because everything reaching this point has already been through
		 * {@see self::sanitize_log_field()}'s own malformed-input branch.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param string $value sanitised value.
		 * @param int    $max   maximum length in characters.
		 * @return string
		 */
		private static function cap_characters( string $value, int $max ) {

			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $value, 0, $max );
			}

			$matched = [];

			if ( preg_match( '/^.{0,' . (int) $max . '}/us', $value, $matched ) ) {
				return $matched[0];
			}

			// Only reachable if the value is somehow still not valid UTF-8. Byte-cap rather
			// than return it whole — an uncapped log line is the defect this exists for — and
			// drop a trailing partial sequence so the result cannot itself be invalid.
			return (string) preg_replace( '/[\x80-\xbf]+$/', '', substr( $value, 0, $max ) );
		}

		/**
		 * Adds a log entry.
		 *
		 * @param string $message message to log
		 */
		abstract protected function log_event( $message );

		/**
		 * Determines whether logging is enabled.
		 *
		 * @return bool
		 */
		protected function is_logging_enabled() {
			return false;
		}

		/**
		 * Gets the ID of this script handler.
		 *
		 * @return string
		 */
		abstract public function get_id();

		/**
		 * Gets the ID, but dasherized.
		 *
		 * @return string
		 */
		public function get_id_dasherized() {
			return str_replace( '_', '-', $this->get_id() );
		}
	}

endif;
