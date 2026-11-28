<?php
/**
 * Connection test/handshake result value object.
 *
 * @package Woodev\Framework\Settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Connection_Result' ) ) :

	final class Woodev_Connection_Result {
		/**
		 * @var bool
		 */
		private $success;

		/**
		 * @var string
		 */
		private $message;

		/**
		 * @var bool
		 */
		private $warning;

		/**
		 * @since 2.0.2
		 * @since 2.1.28 `$warning`.
		 * @param bool   $success whether the connection succeeded.
		 * @param string $message human-readable message (Russian source).
		 * @param bool   $warning whether a successful connection has something the merchant must read.
		 */
		private function __construct( bool $success, string $message, bool $warning = false ) {
			$this->success = $success;
			$this->message = $message;
			$this->warning = $warning;
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
		 * A connection that works with limits (a key with part of its rights): the page shows it as a warning, not as a success.
		 *
		 * @since 2.1.28
		 * @param string $message what works and what does not.
		 * @return self
		 */
		public static function warning( string $message ): self {
			return new self( true, $message, true );
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
		 * @since 2.1.28
		 * @return bool
		 */
		public function is_warning(): bool {
			return $this->warning;
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
		 * @since 2.1.28 `warning`, only for a warning.
		 * @return array{success:bool,message:string,warning?:bool}
		 */
		public function to_array(): array {
			$data = [
				'success' => $this->success,
				'message' => $this->message,
			];

			return $this->warning ? $data + [ 'warning' => true ] : $data;
		}
	}

endif;
