<?php

defined( 'ABSPATH' ) or exit;

if( ! class_exists( 'Woodev_Plugin_Setup_Wizard' ) ) :

	/**
	 * The plugin Setup Wizard class.
	 *
	 * This creates a setup wizard so that plugins can provide a user-friendly
	 * step-by-step interaction for configuring critical plugin options.
	 *
	 * Based on WooCommerce's WC_Admin_Setup_Wizard
	 *
	 */
	abstract class Woodev_Plugin_Setup_Wizard {

		/** the "finish" step ID */
		const ACTION_FINISH = 'finish';

		/** @var string the user capability required to use this wizard */
		protected $required_capability = 'manage_woocommerce';

		/** @var string the current step ID */
		protected $current_step = '';

		/** @var array registered steps to be displayed */
		protected $steps = array();

		/** @var string setup handler ID */
		private $id;

		/** @var Woodev_Plugin plugin instance */
		private $plugin;


		/**
		 * Constructs the class.
		 *
		 * @param Woodev_Plugin $plugin plugin instance
		 */
		public function __construct( Woodev_Plugin $plugin ) {

			// sanity check for admin and permissions
			if( ! is_admin() || ! current_user_can( $this->required_capability ) ) {
				return;
			}

			$this->id     = $plugin->get_id();
			$this->plugin = $plugin;

			// register the steps
			$this->register_steps();

			/**
			 * Filters the registered setup wizard steps.
			 *
			 * @param array $steps registered steps
			 *
			 */
			$this->steps = apply_filters( "wc_{$this->id}_setup_wizard_steps", $this->steps, $this );

			// only continue if there are registered steps
			if( $this->has_steps() ) {

				// if requesting the wizard
				if( $this->is_setup_page() ) {

					$this->init_setup();

					// otherwise, add the hooks for customizing the regular admin
				} else {

					$this->add_hooks();

					// mark the wizard as complete if specifically requested
					if( Woodev_Helper::get_requested_value( "wc_{$this->id}_setup_wizard_complete" ) ) {
						$this->complete_setup();
					}
				}
			}
		}


		/**
		 * Registers the setup steps.
		 *
		 * Plugins should extend this to register their own steps.
		 */
		abstract protected function register_steps();


		/**
		 * Adds the action & filter hooks.
		 */
		protected function add_hooks() {

			// add any admin notices
			add_action( 'admin_notices', array( $this, 'add_admin_notices' ) );

			// add a 'Setup' link to the plugin action links if the wizard hasn't been completed
			if( ! $this->is_complete() ) {
				add_filter( 'plugin_action_links_' . plugin_basename( $this->get_plugin()->get_plugin_file() ), array(
					$this,
					'add_setup_link'
				), 20 );
			}
		}


		/**
		 * Adds any admin notices.
		 */
		public function add_admin_notices() {

			if( Woodev_Helper::is_current_screen( 'plugins' ) || $this->get_plugin()->is_plugin_settings() ) {

				if( $this->is_complete() && $this->get_documentation_notice_message() ) {
					$notice_id = "wc_{$this->id}_docs";
					$message   = $this->get_documentation_notice_message();
				} else {
					$notice_id = "wc_{$this->id}_setup";
					$message   = $this->get_setup_notice_message();
				}

				$this->get_plugin()->get_admin_notice_handler()->add_admin_notice( $message, $notice_id, array(
					'always_show_on_settings' => false,
				) );
			}
		}


		/**
		 * Gets the new installation documentation notice message.
		 *
		 * This prompts users to read the docs and is displayed if the wizard has
		 * already been completed.
		 *
		 * @return string
		 */
		protected function get_documentation_notice_message() {

			if( $this->get_plugin()->get_documentation_url() ) {

				$message = sprintf(
				/** translators: Placeholders: %1$s - plugin name, %2$s - <a> tag, %3$s - </a> tag */
					__( 'Thanks for installing %1$s! To get started, take a minute to %2$sread the documentation%3$s :)', 'woodev-plugin-framework' ),
					esc_html( $this->get_plugin()->get_plugin_name() ),
					'<a href="' . esc_url( $this->get_plugin()->get_documentation_url() ) . '" target="_blank">', '</a>'
				);

			} else {

				$message = '';
			}

			return $message;
		}


		/**
		 * Gets the new installation setup notice message.
		 *
		 * This prompts users to start the setup wizard and is displayed if the
		 * wizard has not yet been completed.
		 *
		 * @return string
		 */
		protected function get_setup_notice_message() {

			return sprintf(
			/** translators: Placeholders: %1$s - plugin name, %2$s - <a> tag, %3$s - </a> tag */
				__( 'Thanks for installing %1$s! To get started, take a minute to complete these %2$squick and easy setup steps%3$s :)', 'woodev-plugin-framework' ),
				esc_html( $this->get_plugin()->get_plugin_name() ),
				'<a href="' . esc_url( $this->get_setup_url() ) . '">', '</a>'
			);
		}


		/**
		 * Adds a 'Setup' link to the plugin action links if the wizard hasn't been completed.
		 *
		 * This will override the plugin's standard "Configure" link with a link to this setup wizard.
		 *
		 * @param array $action_links plugin action links
		 *
		 * @return array
		 * @internal
		 *
		 */
		public function add_setup_link( $action_links ) {

			// remove the standard plugin "Configure" link
			unset( $action_links['configure'] );

			$setup_link = array(
				'setup' => sprintf( '<a href="%s">%s</a>', $this->get_setup_url(), esc_html__( 'Setup', 'woodev-plugin-framework' ) ),
			);

			return array_merge( $setup_link, $action_links );
		}


		/**
		 * Initializes setup.
		 */
		protected function init_setup() {

			// get a step ID from $_GET
			$current_step   = sanitize_key( Woodev_Helper::get_requested_value( 'step' ) );
			$current_action = sanitize_key( Woodev_Helper::get_requested_value( 'action' ) );

			if( ! $current_action ) {

				if( $this->has_step( $current_step ) ) {
					$this->current_step = $current_step;
				} elseif( $first_step_url = $this->get_step_url( key( $this->steps ) ) ) {
					wp_safe_redirect( $first_step_url );
					exit;
				} else {
					wp_safe_redirect( $this->get_dashboard_url() );
					exit;
				}
			}

			// add the page to WP core
			add_action( 'admin_menu', array( $this, 'add_page' ) );

			// renders the entire setup page markup
			add_action( 'admin_init', array( $this, 'render_page' ) );
		}


		/**
		 * Adds the page to WordPress core.
		 *
		 * While this doesn't output any markup/menu items, it is essential to officially register the page to avoid permissions issues.
		 *
		 * @internal
		 */
		public function add_page() {

			add_dashboard_page( '', '', $this->required_capability, $this->get_slug(), '' );
		}


		/**
		 * Renders the entire setup page markup.
		 *
		 * @internal
		 */
		public function render_page() {

			// maybe save and move onto the next step
			$error_message = Woodev_Helper::get_posted_value( 'save_step' ) ? $this->save_step( $this->current_step ) : '';

			$page_title = sprintf(
			/* translators: Placeholders: %s - plugin name */
				__( '%s &rsaquo; Setup', 'woodev-plugin-framework' ),
				$this->get_plugin()->get_plugin_name()
			);

			// add the step name to the page title
			if( ! empty( $this->steps[ $this->current_step ]['name'] ) ) {
				$page_title .= " &rsaquo; {$this->steps[ $this->current_step ]['name']}";
			}

			$this->load_scripts_styles();

			ob_start();

			?>
            <!DOCTYPE html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta name="viewport" content="width=device-width"/>
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
                <title><?php echo esc_html( $page_title ); ?></title>
				<?php wp_print_scripts( 'wc-setup' ); ?>
				<?php do_action( 'admin_print_scripts' ); ?>
				<?php do_action( 'admin_print_styles' ); ?>
            </head>
            <body class="wc-setup wp-core-ui <?php echo esc_attr( $this->get_slug() ); ?>">
			<?php $this->render_header(); ?>
			<?php $this->render_steps(); ?>
			<?php $this->render_content( $error_message ); ?>
			<?php $this->render_footer(); ?>
            </body>
            </html>
			<?php

			exit;
		}


		/**
		 * Saves a step.
		 *
		 * @param string $step_id the step ID being saved
		 *
		 * @return void|string redirects upon success, returns an error message upon failure
		 *
		 */
		protected function save_step( $step_id ) {

			$error_message = __( 'Oops! An error occurred, please try again.', 'woodev-plugin-framework' );

			try {

				// bail early if the nonce is bad
				if( ! wp_verify_nonce( Woodev_Helper::get_posted_value( 'nonce' ), "wc_{$this->id}_setup_wizard_save" ) ) {
					throw new Woodev_Plugin_Exception( $error_message );
				}

				if( $this->has_step( $step_id ) ) {

					// if the step has a saving callback defined, save the form fields
					if( is_callable( $this->steps[ $step_id ]['save'] ) ) {
						call_user_func( $this->steps[ $step_id ]['save'], $this );
					}

					// move to the next step
					wp_safe_redirect( $this->get_next_step_url( $step_id ) );
					exit;
				}

			} catch ( Woodev_Plugin_Exception $exception ) {

				return $exception->getMessage() ?: $error_message;
			}
		}


		/**
		 * Registers and enqueues the wizard's scripts and styles.
		 */
		protected function load_scripts_styles() {

			// block UI
			wp_register_script( 'jquery-blockui', WC()->plugin_url() . '/assets/js/jquery-blockui/jquery.blockUI.min.js', array( 'jquery' ), '2.70', true );

			// enhanced dropdowns
			wp_register_script( 'selectWoo', WC()->plugin_url() . '/assets/js/selectWoo/selectWoo.full.min.js', array( 'jquery' ), '1.0.0' );
			wp_register_script( 'wc-enhanced-select', WC()->plugin_url() . '/assets/js/admin/wc-enhanced-select.min.js', array(
				'jquery',
				'selectWoo'
			), \Woodev_Helper::get_wc_version() );
			wp_localize_script(
				'wc-enhanced-select',
				'wc_enhanced_select_params',
				array(
					'i18n_no_matches'           => _x( 'No matches found', 'enhanced select', 'woodev-plugin-framework' ),
					'i18n_ajax_error'           => _x( 'Loading failed', 'enhanced select', 'woodev-plugin-framework' ),
					'i18n_input_too_short_1'    => _x( 'Please enter 1 or more characters', 'enhanced select', 'woodev-plugin-framework' ),
					'i18n_input_too_short_n'    => _x( 'Please enter %qty% or more characters', 'enhanced select', 'woodev-plugin-framework' ),
					'i18n_input_too_long_1'     => _x( 'Please delete 1 character', 'enhanced select', 'woodev-plugin-framework' ),
					'i18n_input_too_long_n'     => _x( 'Please delete %qty% characters', 'enhanced select', 'woodev-plugin-framework' ),
					'i18n_selection_too_long_1' => _x( 'You can only select 1 item', 'enhanced select', 'woodev-plugin-framework' ),
					'i18n_selection_too_long_n' => _x( 'You can only select %qty% items', 'enhanced select', 'woodev-plugin-framework' ),
					'i18n_load_more'            => _x( 'Loading more results&hellip;', 'enhanced select', 'woodev-plugin-framework' ),
					'i18n_searching'            => _x( 'Searching&hellip;', 'enhanced select', 'woodev-plugin-framework' ),
					'ajax_url'                  => admin_url( 'admin-ajax.php' ),
					'search_products_nonce'     => wp_create_nonce( 'search-products' ),
					'search_customers_nonce'    => wp_create_nonce( 'search-customers' ),
				)
			);

			// WooCommerce Setup core styles
			wp_enqueue_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), \Woodev_Helper::get_wc_version() );
			wp_enqueue_style( 'wc-setup', WC()->plugin_url() . '/assets/css/wc-setup.css', array(
				'dashicons',
				'install'
			), $this->get_plugin()->get_version() );

			// framework bundled styles
			wp_enqueue_style( 'woodev-admin-setup', $this->get_plugin()->get_framework_assets_url() . '/css/admin/woodev-plugin-admin-setup-wizard.min.css', array( 'wc-setup' ), $this->get_plugin()->get_version() );
			wp_enqueue_script( 'woodev-admin-setup', $this->get_plugin()->get_framework_assets_url() . '/js/admin/woodev-plugin-admin-setup-wizard.min.js', array(
				'jquery',
				'wc-enhanced-select',
				'jquery-blockui'
			), $this->get_plugin()->get_version() );
		}


		/** Header Methods ************************************************************************************************/


		/**
		 * Renders the header markup.
		 */
		protected function render_header() {

			$title     = $this->get_plugin()->get_plugin_name();
			$link_url  = $this->get_plugin()->get_sales_page_url();
			$image_url = $this->get_header_image_url();

			$header_content = $image_url ? '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $title ) . '" />' : $title;

			?>
            <h1 id="wc-logo"
                class="woodev-plugin-logo <?php echo esc_attr( 'woodev-' . $this->get_plugin()->get_id_dasherized() . '-logo' ); ?>">
				<?php if( $link_url ) : ?>
                    <a href="<?php echo esc_url( $link_url ); ?>" target="_blank"><?php echo $header_content; ?></a>
				<?php else : ?>
					<?php echo esc_html( $header_content ); ?>
				<?php endif; ?>
            </h1>
			<?php
		}


		/**
		 * Gets the header image URL.
		 *
		 * Plugins can override this to point to their own branding image URL.
		 *
		 * @return string
		 *
		 */
		protected function get_header_image_url() {

			return '';
		}


		/**
		 * Renders the step list.
		 *
		 * This displays a list of steps, marking them as complete or upcoming as sort of a progress bar.
		 *
		 */
		protected function render_steps() {

			?>
            <ol class="wc-setup-steps">

				<?php foreach ( $this->steps as $id => $step ) : ?>

					<?php if( $id === $this->current_step ) : ?>
                        <li class="active"><?php echo esc_html( $step['name'] ); ?></li>
					<?php elseif( $this->is_step_complete( $id ) ) : ?>
                        <li class="done"><a
                                    href="<?php echo esc_url( $this->get_step_url( $id ) ); ?>"><?php echo esc_html( $step['name'] ); ?></a>
                        </li>
					<?php else : ?>
                        <li><?php echo esc_html( $step['name'] ); ?></li>
					<?php endif; ?>

				<?php endforeach; ?>

                <li class="<?php echo $this->is_finished() ? 'done' : ''; ?>"><?php esc_html_e( 'Ready!', 'woodev-plugin-framework' ); ?></li>

            </ol>
			<?php
		}


		/** Content Methods ***********************************************************************************************/


		/**
		 * Renders the setup content.
		 *
		 * This will display the welcome screen, finished screen, or a specific step's markup.
		 *
		 * @param string $error_message custom error message
		 *
		 *
		 */
		protected function render_content( $error_message = '' ) {

			?>
            <div class="wc-setup-content woodev-plugin-admin-setup-content <?php echo esc_attr( $this->get_slug() ) . '-content'; ?>">

				<?php if( $this->is_finished() ) : ?>

					<?php $this->render_finished(); ?>

					<?php $this->complete_setup(); ?>

				<?php else : ?>

					<?php // render a welcome message if the current is the first step ?>
					<?php if( $this->is_started() ) : ?>
						<?php $this->render_welcome(); ?>
					<?php endif; ?>

					<?php // render any error message from a previous save ?>
					<?php if( ! empty( $error_message ) ) : ?>
						<?php $this->render_error( $error_message ); ?>
					<?php endif; ?>

                    <form method="post">
						<?php $this->render_step( $this->current_step ); ?>
						<?php wp_nonce_field( "wc_{$this->id}_setup_wizard_save", 'nonce' ); ?>
                    </form>

				<?php endif; ?>

            </div>
			<?php
		}


		/**
		 * Renders a save error.
		 *
		 * @param string $message error message to render
		 */
		protected function render_error( $message ) {

			if( ! empty( $message ) ) {

				printf( '<p class="error">%s</p>', esc_html( $message ) );
			}
		}


		/**
		 * Renders a default welcome note.
		 */
		protected function render_welcome() {

			?>
            <h1><?php $this->render_welcome_heading() ?></h1>
            <p class="welcome"><?php $this->render_welcome_text(); ?></p>
			<?php
		}


		/**
		 * Renders the default welcome note heading.
		 */
		protected function render_welcome_heading() {

			printf(
			/* translators: Placeholder: %s - plugin name */
				esc_html__( 'Welcome to %s!', 'woodev-plugin-framework' ),
				$this->get_plugin()->get_plugin_name()
			);
		}


		/**
		 * Renders the default welcome note text.
		 */
		protected function render_welcome_text() {

			esc_html_e( 'This quick setup wizard will help you configure the basic settings and get you started.', 'woodev-plugin-framework' );
		}


		/**
		 * Renders the finished screen markup.
		 *
		 * This is what gets displayed after all of the steps have been completed or skipped.
		 */
		protected function render_finished() {

			?>
            <h1><?php printf( esc_html__( '%s is ready!', 'woodev-plugin-framework' ), esc_html( $this->get_plugin()->get_plugin_name() ) ); ?></h1>
			<?php $this->render_before_next_steps(); ?>
			<?php $this->render_next_steps(); ?>
			<?php $this->render_after_next_steps(); ?>
			<?php
		}


		/**
		 * Renders HTML before the next steps in the finished step screen.
		 *
		 * Plugins can implement this method to output additional HTML before the next steps are printed.
		 */
		protected function render_before_next_steps() {
			// stub method
		}


		/**
		 * Renders HTML after the next steps in the finished step screen.
		 *
		 * Plugins can implement this method to output additional HTML after the next steps are printed.
		 */
		protected function render_after_next_steps() {
			// stub method
		}


		/**
		 * Renders the next steps.
		 */
		protected function render_next_steps() {

			$next_steps         = $this->get_next_steps();
			$additional_actions = $this->get_additional_actions();

			if( ! empty( $next_steps ) || ! empty( $additional_actions ) ) :

				?>
                <ul class="wc-wizard-next-steps">

					<?php foreach ( $next_steps as $step ) : ?>

                        <li class="wc-wizard-next-step-item">
                            <div class="wc-wizard-next-step-description">

                                <p class="next-step-heading"><?php esc_html_e( 'Next step', 'woodev-plugin-framework' ); ?></p>
                                <h3 class="next-step-description"><?php echo esc_html( $step['label'] ); ?></h3>

								<?php if( ! empty( $step['description'] ) ) : ?>
                                    <p class="next-step-extra-info"><?php echo esc_html( $step['description'] ); ?></p>
								<?php endif; ?>

                            </div>

                            <div class="wc-wizard-next-step-action">
                                <p class="wc-setup-actions step">
									<?php $button_class = isset( $step['button_class'] ) ? $step['button_class'] : 'button button-primary button-large'; ?>
									<?php $button_class = is_string( $button_class ) || is_array( $button_class ) ? array_map( 'sanitize_html_class', explode( ' ', implode( ' ', (array) $button_class ) ) ) : ''; ?>
                                    <a class="<?php echo implode( ' ', $button_class ); ?>"
                                       href="<?php echo esc_url( $step['url'] ); ?>">
										<?php echo esc_html( $step['name'] ); ?>
                                    </a>
                                </p>
                            </div>
                        </li>

					<?php endforeach; ?>

					<?php if( ! empty( $additional_actions ) ) : ?>

                        <li class="wc-wizard-additional-steps">
                            <div class="wc-wizard-next-step-description">
                                <p class="next-step-heading"><?php esc_html_e( 'You can also:', 'woodev-plugin-framework' ); ?></p>
                            </div>
                            <div class="wc-wizard-next-step-action">

                                <p class="wc-setup-actions step">

									<?php foreach ( $additional_actions as $name => $url ) : ?>

                                        <a class="button button-large" href="<?php echo esc_url( $url ); ?>">
											<?php echo esc_html( $name ); ?>
                                        </a>

									<?php endforeach; ?>

                                </p>
                            </div>
                        </li>

					<?php endif; ?>

                </ul>
			<?php

			endif;
		}


		/**
		 * Gets the next steps.
		 *
		 * These are major actions a user can take after finishing the setup wizard.
		 * For instance, things like "Create your first Add-On" could go here.
		 *
		 * @return array
		 *
		 */
		protected function get_next_steps() {

			$steps = array();

			if( $this->get_plugin()->get_documentation_url() ) {

				$steps['view-docs'] = array(
					'name'        => __( 'View the Docs', 'woodev-plugin-framework' ),
					'label'       => __( 'See more setup options', 'woodev-plugin-framework' ),
					'description' => __( 'Learn more about customizing the plugin', 'woodev-plugin-framework' ),
					'url'         => $this->get_plugin()->get_documentation_url(),
				);
			}

			return $steps;
		}


		/**
		 * Gets the additional steps.
		 *
		 * These are secondary actions.
		 *
		 * @return array
		 *
		 */
		protected function get_additional_actions() {

			$next_steps = $this->get_next_steps();
			$actions    = array();

			if( $this->get_plugin()->get_settings_url() ) {
				$actions[ __( 'Review Your Settings', 'woodev-plugin-framework' ) ] = $this->get_plugin()->get_settings_url();
			}

			if( empty( $next_steps['view-docs'] ) && $this->get_plugin()->get_documentation_url() ) {
				$actions[ __( 'View the Docs', 'woodev-plugin-framework' ) ] = $this->get_plugin()->get_documentation_url();
			}

			if( $this->get_plugin()->get_reviews_url() ) {
				$actions[ __( 'Leave a Review', 'woodev-plugin-framework' ) ] = $this->get_plugin()->get_reviews_url();
			}

			return $actions;
		}


		/**
		 * Renders a given step's markup.
		 *
		 * This will display a title, whatever get's rendered by the step's view
		 * callback, then the navigation buttons.
		 *
		 * @param string $step_id step ID to render
		 *
		 */
		protected function render_step( $step_id ) {

			call_user_func( $this->steps[ $step_id ]['view'], $this );

			if ( isset( $this->steps[ $step_id ]['button_label'] ) ) {
				$label = $this->steps[ $step_id ]['button_label'];
			} else {
				$label = __( 'Continue', 'woodev-plugin-framework' );
			}

			?>
            <p class="wc-setup-actions step">

				<?php if( is_callable( $this->steps[ $step_id ]['save'] ) ) : ?>

                    <button
                            type="submit"
                            name="save_step"
                            class="button-primary button button-large button-next"
                            value="<?php echo esc_attr( $label ); ?>">
						<?php echo esc_html( $label ); ?>
                    </button>

				<?php else : ?>

                    <a class="button-primary button button-large button-next"
                       href="<?php echo esc_url( $this->get_next_step_url( $step_id ) ); ?>"><?php echo esc_html( $label ); ?></a>

				<?php endif; ?>
            </p>
			<?php
		}


		/**
		 * Renders a form field.
		 *
		 * Call this in the same way as woocommerce_form_field().
		 *
		 * @param string $key field key
		 * @param array $args field args - @see woocommerce_form_field()
		 * @param string|null $value field value
		 *
		 *
		 */
		protected function render_form_field( $key, $args, $value = null ) {

			if( ! isset( $args['class'] ) ) {
				$args['class'] = array();
			} else {
				$args['class'] = (array) $args['class'];
			}

			// the base wrapper class for our styling
			$args['class'][] = 'woodev-plugin-admin-setup-control';

			// add the "required" HTML attribute for browser form validation
			if( ! empty( $args['required'] ) ) {
				$args['custom_attributes']['required'] = true;
			}

			// all dropdowns are treated as enhanced selects
			if( isset( $args['type'] ) && 'select' === $args['type'] ) {
				$args['input_class'][] = 'wc-enhanced-select';
			}

			// always echo the field
			$args['return'] = false;

			if( isset( $args['type'] ) && 'toggle' === $args['type'] ) {
				$this->render_toggle_form_field( $key, $args, $value );
			} elseif( isset( $args['type'] ) && 'multiselect' === $args['type'] ) {
				$this->render_multiselect_form_field( $key, $args, $value );
            } else {
				woocommerce_form_field( $key, $args, $value );
			}
		}

		/**
		 * Renders the multiselect form field.
		 *
		 * @param string $key field key
		 * @param array $args field args - @see woocommerce_form_field()
		 * @param string|null $value field value
		 */
		public function render_multiselect_form_field( $key, $args, $value ) {

            $args = wp_parse_args( $args, array(
				'label'             => '',
				'description'       => '',
				'required'          => false,
				'id'                => $key,
				'class'             => array(),
				'label_class'       => array(),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'default'           => false,
				'allow_html'        => false,
			) );

			if ( empty( $args['options'] ) ) return;

			if( $args['required'] ) {
				$args['class'][] = 'validate-required';
				$required        = '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'woodev-plugin-framework' ) . '">*</abbr>';
			} else {
				$required = '&nbsp;<span class="optional">(' . esc_html__( 'optional', 'woodev-plugin-framework' ) . ')</span>';
			}

			// Custom attribute handling.
			$custom_attributes         = array();
			$args['custom_attributes'] = array_filter( (array) $args['custom_attributes'], 'strlen' );

			if( ! empty( $args['required'] ) ) {
				$args['custom_attributes']['required'] = true;
			}

			if ( $args['description'] ) {
				$args['custom_attributes']['aria-describedby'] = $args['id'] . '-description';
			}

			if ( ! empty( $args['custom_attributes'] ) && is_array( $args['custom_attributes'] ) ) {
				foreach ( $args['custom_attributes'] as $attribute => $attribute_value ) {
					$custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
				}
			}

			$options = '';

			foreach ( $args['options'] as $option_key => $option_text ) {
				if ( '' === $option_key ) {
					if ( empty( $args['placeholder'] ) ) {
						$args['placeholder'] = $option_text ?: __( 'Choose an option', 'woodev-plugin-framework' );
					}
					$custom_attributes[] = 'data-allow_clear="true"';
				}

				$selected = is_array( $value ) && in_array( $option_key, $value, true ) ? 'selected="selected"' : '';

				$options .= '<option value="' . esc_attr( $option_key ) . '" ' . $selected . '>' . esc_html( $option_text ) . '</option>';
			}

            ?>

            <div class="form-row <?php echo esc_attr( implode( ' ', $args['class'] ) ); ?>">

                <?php if ( $args['label'] ) : ?>
                    <label for="<?php esc_attr_e( $args['id'] );?>" class="<?php esc_attr_e( implode( ' ', $args['label_class'] ) ); ?>"><?php echo wp_kses_post( $args['label'] );?> <?php echo $required;?></label>
                <?php endif; ?>

                <select multiple="multiple" name="<?php esc_attr_e( $key );?>[]" id="<?php esc_attr_e( $args['id'] );?>" class="select wc-enhanced-select <?php esc_attr_e( implode( ' ', $args['input_class'] ) );?>" <?php echo implode( ' ', $custom_attributes );?> data-placeholder="<?php esc_attr_e( $args['placeholder'] );?>">
                    <?php echo $options;?>
                </select>

                <?php if ( $args['description'] ) : ?>
                    <span class="description" id="<?php esc_attr_e( $args['id'] );?>-description" aria-hidden="true"><?php echo wp_kses_post( $args['description'] ); ?></span>
                <?php endif; ?>

            </div>

            <?php
        }


		/**
		 * Renders the toggle form field.
		 *
		 * This requires special markup for the toggle UI.
		 *
		 * @param string $key field key
		 * @param array $args field args - @see woocommerce_form_field()
		 * @param string|null $value field value
		 */
		public function render_toggle_form_field( $key, $args, $value ) {

			$args = wp_parse_args( $args, array(
				'type'              => 'text',
				'label'             => '',
				'description'       => '',
				'required'          => false,
				'id'                => $key,
				'class'             => array(),
				'label_class'       => array(),
				'input_class'       => array(),
				'custom_attributes' => array(),
				'default'           => false,
				'allow_html'        => false,
			) );

			$args['class'][] = 'toggle';

			if( $args['required'] ) {
				$args['class'][] = 'validate-required';
			}

			if( null === $value ) {
				$value = $args['default'];
			}

			$custom_attributes         = array();
			$args['custom_attributes'] = array_filter( (array) $args['custom_attributes'], 'strlen' );

			if( $args['description'] ) {
				$args['custom_attributes']['aria-describedby'] = $args['id'] . '-description';
			}

			if( ! empty( $args['custom_attributes'] ) && is_array( $args['custom_attributes'] ) ) {
				foreach ( $args['custom_attributes'] as $attribute => $attribute_value ) {
					$custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
				}
			}

			$enabled = $value || $args['default'];

			if( $enabled ) {
				$args['class'][] = 'enabled';
			}

			?>
            <div class="form-row <?php echo esc_attr( implode( ' ', $args['class'] ) ); ?>">

                <p class="name"><?php echo true === $args['allow_html'] ? $args['label'] : esc_html( $args['label'] ); ?></p>

				<?php if( true === $args['allow_html'] ) : ?>
                    <div class="content"><p class="description"><?php echo $args['description']; ?></p></div>
				<?php else : ?>
                    <p class="content description"><?php echo esc_html( $args['description'] ); ?></p>
				<?php endif; ?>

                <div class="enable">
				<span class="toggle <?php echo $enabled ? '' : 'disabled'; ?>">
					<input
                            id="<?php echo esc_attr( $args['id'] ); ?>"
                            type="checkbox"
                            class="input-checkbox <?php echo esc_attr( implode( ' ', $args['input_class'] ) ); ?>"
                            name="<?php echo esc_attr( $key ); ?>"
                            value="yes" <?php checked( true, $value ); ?>
						<?php echo implode( ' ', $custom_attributes ); ?>
					/>
					<label for="<?php echo esc_attr( $args['id'] ); ?>"
                           class="<?php implode( ' ', (array) $args['label_class'] ); ?>">
				</span>
                </div>

            </div>
			<?php
		}


		/**
		 * Renders the setup footer.
		 */
		protected function render_footer() {

			?>
			<?php if( $this->is_finished() ) : ?>
                <a class="wc-setup-footer-links"
                   href="<?php echo esc_url( $this->get_dashboard_url() ); ?>"><?php esc_html_e( 'Return to the WordPress Dashboard', 'woodev-plugin-framework' ); ?></a>
			<?php elseif( $this->is_started() ) : ?>
                <a class="wc-setup-footer-links"
                   href="<?php echo esc_url( $this->get_dashboard_url() ); ?>"><?php esc_html_e( 'Not right now', 'woodev-plugin-framework' ); ?></a>
			<?php else : ?>
                <a class="wc-setup-footer-links"
                   href="<?php echo esc_url( $this->get_next_step_url() ); ?>"><?php esc_html_e( 'Skip this step', 'woodev-plugin-framework' ); ?></a>
			<?php endif; ?>
			<?php

			do_action( 'wp_print_footer_scripts' );
		}


		/** Helper Methods ************************************************************************************************/


		/**
		 * Registers a step.
		 *
		 * @param string $id unique step ID
		 * @param string $name step name for display
		 * @param string|array $view_callback callback to render the step's content HTML
		 * @param string|array|null $save_callback callback to save the step's form values
		 *
		 * @return bool whether the step was successfully added
		 *
		 */
		public function register_step( $id, $name, $view_callback, $save_callback = null ) {

			try {

				// invalid ID
				if( ! is_string( $id ) || empty( $id ) || $this->has_step( $id ) ) {
					throw new Woodev_Plugin_Exception( 'Invalid step ID' );
				}

				// invalid name
				if( ! is_string( $name ) || empty( $name ) ) {
					throw new Woodev_Plugin_Exception( 'Invalid step name' );
				}

				// invalid view callback
				if( ! is_callable( $view_callback ) ) {
					throw new Woodev_Plugin_Exception( 'Invalid view callback' );
				}

				// invalid save callback
				if( null !== $save_callback && ! is_callable( $save_callback ) ) {
					throw new Woodev_Plugin_Exception( 'Invalid save callback' );
				}

				$this->steps[ $id ] = array(
					'name' => $name,
					'view' => $view_callback,
					'save' => $save_callback,
				);

				return true;

			} catch ( Woodev_Plugin_Exception $exception ) {

				wc_doing_it_wrong( __METHOD__, $exception->getMessage(), '1.8.0' );

				return false;
			}
		}


		/**
		 * Marks the setup as complete.
		 *
		 * @return bool
		 */
		public function complete_setup() {

			return update_option( "wc_{$this->id}_setup_wizard_complete", 'yes' );
		}


		/** Conditional Methods *******************************************************************************************/


		/**
		 * Determines if the current page is the setup wizard page.
		 *
		 * @return bool
		 */
		public function is_setup_page() {

			return is_admin() && $this->get_slug() === Woodev_Helper::get_requested_value( 'page' );
		}


		/**
		 * Determines if a step is the current one displayed.
		 *
		 * @param string $step_id step ID
		 *
		 * @return bool
		 */
		public function is_current_step( $step_id ) {

			return $this->current_step === $step_id;
		}


		/**
		 * Determines if setup has started.
		 *
		 * @return bool
		 */
		public function is_started() {

			$steps = array_keys( $this->steps );

			return $this->current_step && $this->current_step === reset( $steps );
		}


		/**
		 * Determines if setup has completed all of the steps.
		 *
		 * @return bool
		 */
		public function is_finished() {

			return self::ACTION_FINISH === Woodev_Helper::get_requested_value( 'action' );
		}


		/**
		 * Determines if the setup wizard has been completed.
		 *
		 * This will be true if any user has been redirected back to the regular
		 * WordPress dashboard, either manually or after finishing the steps.
		 *
		 * @return bool
		 */
		public function is_complete() {

			return 'yes' === get_option( "wc_{$this->id}_setup_wizard_complete", 'no' );
		}


		/**
		 * Determines if the given step has been completed.
		 *
		 * @param string $step_id step ID to check
		 *
		 * @return bool
		 */
		public function is_step_complete( $step_id ) {

			return array_search( $this->current_step, array_keys( $this->steps ), true ) > array_search( $step_id, array_keys( $this->steps ), true ) || $this->is_finished();
		}


		/**
		 * Determines if the wizard has steps to display.
		 *
		 * @return bool
		 */
		public function has_steps() {

			return is_array( $this->steps ) && ! empty( $this->steps );
		}


		/**
		 * Determines if this setup handler has a given step.
		 *
		 * @param string $step_id step ID to check
		 *
		 * @return bool
		 */
		public function has_step( $step_id ) {

			return ! empty( $this->steps[ $step_id ] );
		}


		/** Getter Methods ************************************************************************************************/


		/**
		 * Gets a given step's title.
		 *
		 * @param string $step_id step ID (optional: will assume the current step if unspecified)
		 *
		 * @return string
		 */
		public function get_step_title( $step_id = '' ) {

			$step_title = '';

			if( ! $step_id ) {
				$step_id = $this->current_step;
			}

			if( isset( $this->steps[ $step_id ]['name'] ) ) {
				$step_title = $this->steps[ $step_id ]['name'];
			}

			return $step_title;
		}


		/**
		 * Gets the Setup Wizard URL.
		 *
		 * @return string
		 */
		public function get_setup_url() {

			return add_query_arg( 'page', $this->get_slug(), admin_url( 'index.php' ) );
		}


		/**
		 * Gets the URL for the next step based on a current step.
		 *
		 * @param string $step_id step ID to base "next" off of - defaults to this class's internal pointer
		 *
		 * @return string
		 */
		public function get_next_step_url( $step_id = '' ) {

			if( ! $step_id ) {
				$step_id = $this->current_step;
			}

			$steps = array_keys( $this->steps );

			// if on the last step, next is the final finish step
			if( end( $steps ) === $step_id ) {

				$url = $this->get_finish_url();

			} else {

				$step_index = array_search( $step_id, $steps, true );

				// if the current step is found, use the next in the array. otherwise, the first
				$step = false !== $step_index ? $steps[ $step_index + 1 ] : reset( $steps );

				$url = add_query_arg( 'step', $step );
			}

			return $url;
		}


		/**
		 * Gets a given step's URL.
		 *
		 * @param string $step_id step ID
		 *
		 * @return string|false
		 */
		public function get_step_url( $step_id ) {

			$url = false;

			if( $this->has_step( $step_id ) ) {
				$url = add_query_arg( 'step', $step_id, remove_query_arg( 'action' ) );
			}

			return $url;
		}


		/**
		 * Gets the "finish" action URL.
		 *
		 * @return string
		 */
		protected function get_finish_url() {

			return add_query_arg( 'action', self::ACTION_FINISH, remove_query_arg( 'step' ) );
		}


		/**
		 * Gets the return URL.
		 *
		 * Can be used to return the user to the dashboard. The plugin's settings URL
		 * will be used if it exists, otherwise the general dashboard URL.
		 *
		 * @return string
		 */
		protected function get_dashboard_url() {

			$settings_url  = $this->get_plugin()->get_settings_url();
			$dashboard_url = ! empty( $settings_url ) ? $settings_url : admin_url();

			return add_query_arg( "wc_{$this->id}_setup_wizard_complete", true, $dashboard_url );
		}


		/**
		 * Gets the setup setup handler's slug.
		 *
		 * @return string
		 */
		protected function get_slug() {

			return 'woodev-' . $this->get_plugin()->get_id_dasherized() . '-setup';
		}


		/**
		 * Gets the plugin instance.
		 *
		 * @return Woodev_Plugin|Woodev_Payment_Gateway_Plugin
		 */
		protected function get_plugin() {
			return $this->plugin;
		}
	}

endif;
