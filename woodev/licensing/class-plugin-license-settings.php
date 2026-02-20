<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_License_Settings' ) ) :

	class Woodev_License_Settings {

		private $plugin;

		public function __construct( Woodev_Plugin $plugin ) {

			$this->plugin = $plugin;

			//Register license settings page
			add_action( 'admin_init', [ $this, 'register_license_settings' ] );
			add_filter( 'woocommerce_screen_ids', [ $this, 'set_wc_screen_ids' ] );
		}

		public function set_wc_screen_ids( array $screen ): array {

			$current_screen = Woodev_Helper::get_current_screen();

			if( $current_screen && ! empty( $current_screen->id ) ) {
				return array_merge( $screen, [ $current_screen->id ] );
			}

			return $screen;
		}

		/**
		 * Add license field to settings
		 *
		 * @return void
		 */
		public function register_license_settings() {

			$license_key_option_name = $this->plugin->get_plugin_option_name( 'license_key' );

			add_settings_field(
				$license_key_option_name,
				sprintf( '<span>%s</span>', $this->plugin->get_plugin_name() ),
				[ $this, 'do_license_fields' ],
				'woodev_licenses_page',
				'woodev_licenses_section',
				[ 'class' => 'license-container' ]
			);

			register_setting( 'woodev_license_fields_group', $license_key_option_name, function ( $input ) {

				$beta_version = $this->plugin->get_plugin_option_name( 'beta_version' );
				$verify       = $this->plugin->get_plugin_option_name( 'verify' );
				$license_key  = sanitize_text_field( $input );

				if ( isset( $_REQUEST[ $beta_version ] ) && 'yes' === sanitize_text_field( $_REQUEST[ $beta_version ] ) ) {
					update_option( $beta_version, 'yes' );
				} else {
					delete_option( $beta_version );
				}

				if ( isset( $_REQUEST[ $verify ] ) && ! empty( $license_key ) ) {
					$this->plugin->get_license_instance()->verify_license( $license_key );
				}

				return $license_key;
			} );
		}

		public function do_license_fields() {

			$woodev_license = new Woodev_License( $this->plugin->get_id_underscored() );

			echo '<div class="license-item">';
			echo '<div class="details">';

			printf(
				'<input type="text" name="%1$s" value="%2$s" placeholder="%3$s" %4$s class="%5$s" data-tip="%6$s"/>',
				$this->plugin->get_plugin_option_name( 'license_key' ),
				$this->plugin->get_license_instance()->get_license(),
				esc_html__( 'Enter your valid license key', 'woodev-plugin-framework' ),
				wp_readonly( $this->plugin->get_license_instance()->is_license_valid(), true, false ),
				$this->plugin->get_license_instance()->is_license_valid() ? 'tips' : '',
				esc_html__( 'Before change your license key you have to deactivate current license', 'woodev-plugin-framework' )
			);

			echo '<span class="dashicons dashicons-privacy"></span>';

			printf(
				'<input type="submit" name="%1$s" value="%2$s" class="button-secondary" %3$s />',
				$this->plugin->get_plugin_option_name( 'verify' ),
				__( 'Verify', 'woodev-plugin-framework' ),
				disabled( empty( $woodev_license->get_license_key() ), true, false )
			);

			$message_classes = [ 'woodev-licenses-data' ];

			if ( 'valid' == $woodev_license->license && ! empty( $woodev_license->expires ) && 'lifetime' !== $woodev_license->expires ) {
				$now = current_time( 'timestamp' );
				$expires = is_numeric( $woodev_license->expires ) ?: strtotime( $woodev_license->expires, $now );
				if ( ( $expires > $now ) && ( ( $expires - $now ) < MONTH_IN_SECONDS ) ) {
					$message_classes[] = 'woodev-licenses-status-expires-soon';
				}
			} else {
				$message_classes[] = sprintf( 'woodev-licenses-status-%s', $woodev_license->license ?: 'info' );
			}

			echo sprintf( '<div class="%s">', implode( ' ', $message_classes ) );

			echo wp_kses_post( ( new Woodev_License_Messages( $woodev_license ) )->get_message() );

			if ( in_array( false, [
					$woodev_license->license,
					$woodev_license->success
				], true ) || $woodev_license->error ) {
				printf( '<p>%s</p>', sprintf( __( 'To receive updates and support, please enter your valid license key for <strong>%s</strong>', 'woodev-plugin-framework' ), $this->plugin->get_plugin_name() ) );
			}

			echo '</div><!-- end .woodev-licenses-data -->';

			echo '</div><!-- end .details -->';

			echo '<div class="actions">';

			echo '<div class="actions-buttons">';

			if ( $this->plugin->get_license_instance()->is_license_valid() ) {
				printf(
					'<input type="submit" name="%1$s" value="%2$s" class="button" />',
					$this->plugin->get_plugin_option_name( 'deactivate' ),
					__( 'Deactivate', 'woodev-plugin-framework' )
				);
			} elseif ( ! $this->plugin->get_license_instance()->is_active() ) {
				printf( '<span class="status">%s</span>', $this->plugin->get_license_instance()->get_license_status( $woodev_license->license ) );
			}

			echo '</div><!-- end .actions-buttons -->';

			echo '<div class="actions-beta">';

			printf(
				'<div class="toggle-control"><input type="checkbox" id="%1$s" name="%1$s" value="yes" %2$s /><label for="%1$s" class="help_tip" data-tip="%3$s"></label></div>',
				$this->plugin->get_plugin_option_name( 'beta_version' ),
				checked( true, $this->plugin->is_beta_allowed(), false ),
				__( 'Allow to upload a beta version', 'woodev-plugin-framework' )
			);

			echo '</div><!-- end .actions-beta -->';

			echo '</div><!-- end .actions -->';

			$nonce_name = sprintf( '%s-nonce', $this->plugin->get_id_dasherized() );

			wp_nonce_field( $nonce_name, $nonce_name, false );

			echo '</div><!-- end license-item-->';
		}
	}

endif;