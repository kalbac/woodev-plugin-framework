<?php
/**
 * Optional persistent connection-status seam for a settings handler.
 *
 * @package Woodev\Framework\Settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'Woodev_Settings_Connection_Status' ) ) :

	/**
	 * A settings handler optionally implements this to drive an on-load status
	 * badge. The framework stores nothing — the plugin caches as it sees fit.
	 *
	 * @since 2.0.2
	 */
	interface Woodev_Settings_Connection_Status {

		/**
		 * Current known status for one block, or null if unknown / not applicable.
		 *
		 * @since 2.0.2
		 * @param string $connection_id the connection section id.
		 * @return \Woodev_Connection_Result|null
		 */
		public function get_connection_status( string $connection_id ): ?\Woodev_Connection_Result;
	}

endif;
