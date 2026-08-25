<?php
/**
 * Woodev Tool Result
 *
 * Immutable result of running one «Инструменты» tool (issue #505). Deliberately
 * NOT {@see \Woodev_Connection_Result}, even though the shapes coincide
 * (`success` + `message`): D1 of the shipping-tools-section spec draws the
 * neighbourhood line on purpose — a tool is an ordinary merchant action, a
 * connection test is a credential handshake, and the two must stay free to
 * diverge (e.g. a tool result later carrying structured counts) without
 * dragging the connection-test contract along.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Tool_Result' ) ) :

	/**
	 * Immutable outcome of one {@see Shipping_Tool} run.
	 *
	 * Built only via {@see self::success()} / {@see self::failure()}.
	 *
	 * @since 2.0.2
	 */
	final class Tool_Result {

		/** @var bool */
		private bool $success;

		/** @var string */
		private string $message;

		/**
		 * @since 2.0.2
		 *
		 * @param bool   $success whether the tool run succeeded.
		 * @param string $message human-readable message (Russian source).
		 */
		private function __construct( bool $success, string $message ) {
			$this->success = $success;
			$this->message = $message;
		}

		/**
		 * @since 2.0.2
		 *
		 * @param string $message optional message.
		 *
		 * @return self
		 */
		public static function success( string $message = '' ): self {
			return new self( true, $message );
		}

		/**
		 * @since 2.0.2
		 *
		 * @param string $message failure message.
		 *
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
