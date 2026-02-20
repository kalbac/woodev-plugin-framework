<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Payment_Gateway_Plugin_Setup_Wizard' ) ) :


	/**
	 * The payment gateway plugin Setup Wizard class.
	 *
	 * Extends the base plugin class to add common gateway functionality.
	 *
	 */
	abstract class Woodev_Payment_Gateway_Plugin_Setup_Wizard extends Woodev_Plugin_Setup_Wizard {


		/**
		 * Adds a 'Setup' link to the plugin action links if the wizard hasn't been completed.
		 *
		 * This will override the plugin's standard "Configure {gateway}" links with a link to
		 * this setup wizard.
		 *
		 * @param array $action_links plugin action links
		 *
		 * @return array
		 * @internal
		 *
		 */
		public function add_setup_link( $action_links ) {

			foreach ( $this->get_plugin()->get_gateways() as $gateway ) {
				unset( $action_links[ 'configure_' . $gateway->get_id() ] );
			}

			return parent::add_setup_link( $action_links );
		}


	}


endif;
