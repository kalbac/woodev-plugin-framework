<?php
/**
 * Connection-test seam for a settings handler.
 *
 * @package Woodev\Framework\Settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'Woodev_Settings_Connection_Test' ) ) :

	/**
	 * A settings handler implements this to provide the per-connection-block test/
	 * connect action. ALL carrier behavior (token exchange, header building, GUID
	 * handshake, the API call) lives in the implementation — the framework only
	 * renders the button, plumbs REST, and transports the result.
	 *
	 * @since 2.0.2
	 */
	interface Woodev_Settings_Connection_Test {

		/**
		 * Tests / performs the connection for one block.
		 *
		 * @since 2.0.2
		 * @param string              $connection_id the connection section id.
		 * @param array<string,mixed> $values        merged field values (POSTed ∪ stored).
		 * @return \Woodev_Connection_Result
		 */
		public function test_connection( string $connection_id, array $values ): \Woodev_Connection_Result;
	}

endif;
