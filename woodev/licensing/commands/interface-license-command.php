<?php
/**
 * License command handler contract.
 *
 * Every v1 vocabulary entry implements this interface. The dispatcher
 * calls execute() exactly once per successfully-claimed nonce. A clean
 * return is the terminal ack status string; a Throwable propagates
 * out so the dispatcher can wrap it as a retryable 'failed' result
 * (nonce stays 'processing' for §9.1 takeover retry).
 *
 * BINDING registry contract (plan §9.2): all registered commands MUST
 * be idempotent — re-executing the same payload on a stuck/taken-over
 * nonce must be safe. A future non-idempotent command must first replace
 * the takeover rule in Woodev_License_Command_Nonce_Store.
 *
 * @package Woodev\Framework\Licensing\Commands
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'Woodev_License_Command' ) ) :

	/**
	 * Interface Woodev_License_Command
	 *
	 * @since 2.0.0
	 */
	interface Woodev_License_Command {

		/**
		 * Returns the command vocabulary name (matches envelope payload.command).
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_name(): string;

		/**
		 * Executes the command against the target license engine.
		 *
		 * Returns one of the terminal ack status strings frozen in §9.8:
		 * 'executed', 'already', 'network_active_unsupported'.
		 * Throws on unrecoverable failure so the dispatcher records 'failed'
		 * (retryable, §9.6) and the nonce stays in 'processing'.
		 *
		 * @since 2.0.0
		 *
		 * @param Woodev_Plugins_License $target  The resolved target license engine.
		 * @param array<string, mixed>   $payload The verified signed payload.
		 * @return string Terminal ack status.
		 *
		 * @throws \Throwable On unrecoverable execution failure.
		 */
		public function execute( Woodev_Plugins_License $target, array $payload ): string;
	}

endif;
