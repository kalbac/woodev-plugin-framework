<?php
/**
 * Connection test/handshake result value object.
 *
 * @package Woodev\Framework\Settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Connection_Result' ) ) :

	/**
	 * Immutable result of a connection test or handshake (the plugin produces it;
	 * the framework only transports it to the React block).
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Connection_Result {

		/** @var bool */
		private $success;

		/** @var string */
		private $message;

		/**
		 * @since 2.0.2
		 * @param bool   $success whether the connection succeeded.
		 * @param string $message human-readable message (Russian source).
		 */
		private function __construct( bool $success, string $message ) {
			$this->success = $success;
			$this->message = $message;
		}

		/**
		 * @since 2.0.2
		 * @param string $message optional message.
		 * @return self
		 */
		public static function success( string $message = '' ): self {
			return new self( true, $message );
		}

		/**
		 * @since 2.0.2
		 * @param string $message failure message.
		 * @return self
		 */
		public static function failure( string $message ): self {
			return new self( false, $message );
		}

		/**
		 * @since 2.0.2
		 * @return bool
		 */
		public function is_success(): bool {
			return $this->success;
		}

		/**
		 * @since 2.0.2
		 * @return string
		 */
		public function get_message(): string {
			return $this->message;
		}

		/**
		 * REST payload shape.
		 *
		 * @since 2.0.2
		 * @return array{success:bool,message:string}
		 */
		public function to_array(): array {
			return [
				'success' => $this->success,
				'message' => $this->message,
			];
		}
	}

endif;
