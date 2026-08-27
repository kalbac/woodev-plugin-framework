<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Script_Handler' ) ) :


	/**
	 * Script Handler Abstract Class
	 *
	 * Handles initializing the payment registered JavaScripts
	 */
	abstract class Woodev_Script_Handler {


		/** @var string JS handler base class name */
		protected $js_handler_base_class_name = '';

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
		 * is the flood half of #402, which needs a rate-limit policy rather than a cap, and
		 * is filed separately.
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
			// post one is exactly the caller this function exists for. Fall back to the
			// byte-wise pattern against the ORIGINAL value — never against the null result,
			// which would quietly reduce every mis-encoded message to an empty string and
			// hand the attacker a way to make this function do nothing.
			$value = null !== $stripped ? $stripped : (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $value );

			$value = trim( $value );

			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 500 ) : substr( $value, 0, 500 );
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
