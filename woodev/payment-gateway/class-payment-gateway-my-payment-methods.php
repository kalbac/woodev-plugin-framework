<?php


if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'Woodev_Payment_Gateway_My_Payment_Methods' ) ) :

	/**
	 * My Payment Methods Class
	 *
	 * Renders the My Payment Methods table on the My Account page and handles
	 * any associated actions (deleting a payment method, etc)
	 */
	class Woodev_Payment_Gateway_My_Payment_Methods extends Woodev_Script_Handler {

		/** @var Woodev_Payment_Gateway_Plugin */
		protected $plugin;

		/** @var Woodev_Payment_Gateway_Payment_Token[] array of token objects */
		protected $tokens;

		/** @var Woodev_Payment_Gateway_Payment_Token[] array of token objects */
		protected $credit_card_tokens;

		/** @var Woodev_Payment_Gateway_Payment_Token[] array of token objects */
		protected $echeck_tokens;

		/** @var bool true if there are tokens */
		protected $has_tokens;

		/** @var string JS handler base class name, without the FW version */
		protected $js_handler_base_class_name = 'Woodev_Payment_Methods_Handler';


		/**
		 * Setup Class
		 *
		 * @param Woodev_Payment_Gateway_Plugin $plugin gateway plugin
		 */
		public function __construct( $plugin ) {

			$this->plugin = $plugin;

			parent::__construct();
		}


		/**
		 * Adds the action and filter hooks.
		 */
		protected function add_hooks() {

			parent::add_hooks();

			add_action( 'wp', array( $this, 'init' ) );

			// save a payment method via AJAX
			add_action( 'wp_ajax_wc_' . $this->get_plugin()->get_id() . '_save_payment_method', array(
				$this,
				'ajax_save_payment_method'
			) );
		}


		/**
		 * Gets the script ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return $this->get_plugin()->get_id() . '_payment_methods';
		}


		/**
		 * Gets the script ID, dasherized.
		 *
		 * @return string
		 */
		public function get_id_dasherized() {
			return $this->get_plugin()->get_id_dasherized() . '-payment-methods';
		}


		/**
		 * Initializes the My Payment Methods table
		 */
		public function init() {

			if ( ! $this->is_payment_methods_page() ) {
				return;
			}

			// initializes tokens as WooCommerce core tokens
			$this->load_tokens();

			// styles/scripts
			add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_styles_scripts' ) );

			add_filter( 'woocommerce_payment_methods_list_item', array(
				$this,
				'add_payment_methods_list_item_id'
			), 10, 2 );
			add_filter( 'woocommerce_payment_methods_list_item', array(
				$this,
				'add_payment_methods_list_item_edit_action'
			), 10, 2 );

			add_filter( 'woocommerce_account_payment_methods_columns', array( $this, 'add_payment_methods_columns' ) );

			add_action( 'woocommerce_account_payment_methods_column_title', array(
				$this,
				'add_payment_method_title'
			) );
			add_action( 'woocommerce_account_payment_methods_column_details', array(
				$this,
				'add_payment_method_details'
			) );
			add_action( 'woocommerce_account_payment_methods_column_default', array(
				$this,
				'add_payment_method_default'
			) );

			// map Framework payment methods actions to WooCommerce actions for backwards compatibility
			add_action( 'woocommerce_before_account_payment_methods', array( $this, 'before_payment_methods_table' ) );
			add_action( 'woocommerce_after_account_payment_methods', array( $this, 'after_payment_methods_table' ) );

			// handle custom payment method actions
			$this->handle_payment_method_actions();

			// render JavaScript used in the My Payment Methods section
			add_action( 'woocommerce_after_account_payment_methods', [ $this, 'render_js' ] );
		}


		/**
		 * Enqueue frontend CSS/JS
		 */
		public function maybe_enqueue_styles_scripts() {

			$handle = 'woodev-payment-gateway-my-payment-methods';

			wp_register_script( 'jquery-tiptip', WC()->plugin_url() . '/assets/js/jquery-tiptip/jquery.tipTip.min.js', array( 'jquery' ), Woodev_Helper::get_wc_version(), true );

			wp_enqueue_style( $handle, $this->get_plugin()->get_payment_gateway_framework_assets_url() . '/css/frontend/' . $handle . '.css', array( 'dashicons' ), Woodev_Plugin::VERSION );

			wp_enqueue_script( $handle, $this->get_plugin()->get_payment_gateway_framework_assets_url() . '/dist/frontend/' . $handle . '.js', array(
				'jquery-tiptip',
				'jquery'
			), Woodev_Plugin::VERSION );
		}


		/**
		 * Gets the the available tokens for each plugin gateway and combine them.
		 *
		 * Tokens are also separated into Credit Card and eCheck-specific class members for convenience.
		 */
		protected function load_tokens() {

			if ( ! empty( $this->tokens ) ) {
				return $this->tokens;
			}

			$this->credit_card_tokens = $this->echeck_tokens = array();

			foreach ( $this->get_plugin()->get_gateways() as $gateway ) {

				if ( ! $gateway->is_available() || ! ( $gateway->supports_tokenization() && $gateway->tokenization_enabled() ) ) {
					continue;
				}

				foreach ( $gateway->get_payment_tokens_handler()->get_tokens( get_current_user_id() ) as $token ) {

					// prevent duplicates, as some gateways will return all tokens in each gateway
					if ( isset( $this->credit_card_tokens[ $token->get_id() ] ) || isset( $this->echeck_tokens[ $token->get_id() ] ) ) {
						continue;
					}

					if ( $token->is_credit_card() ) {

						$this->credit_card_tokens[ $token->get_id() ] = $token;

					} elseif ( $token->is_echeck() ) {

						$this->echeck_tokens[ $token->get_id() ] = $token;
					}
				}
			}

			// we don't use array_merge here since the indexes could be numeric
			// and cause the indexes to be reset
			$this->tokens = $this->credit_card_tokens + $this->echeck_tokens;

			$this->has_tokens = ! empty( $this->tokens );

			return $this->tokens;
		}


		/**
		 * Clear the tokens transients after making a method the default, so that the correct payment method shows as default.
		 *
		 * @param int $token_id token ID
		 * @param WC_Payment_Token $token core token object
		 *
		 * @internal
		 *
		 */
		public function clear_payment_methods_transients( $token_id, $token ) {

			foreach ( $this->get_plugin()->get_gateways() as $gateway ) {

				if ( ! $gateway->is_available() || ! ( $gateway->supports_tokenization() && $gateway->tokenization_enabled() ) ) {
					continue;
				}

				$gateway->get_payment_tokens_handler()->clear_transient( get_current_user_id() );
			}
		}


		/**
		 * Adds the token ID to the token data array.
		 *
		 * @param array $item individual list item from woocommerce_saved_payment_methods_list
		 * @param WC_Payment_Token $token payment token associated with this method entry
		 *
		 * @return array
		 * @internal
		 *
		 * @see wc_get_account_saved_payment_methods_list
		 *
		 */
		public function add_payment_methods_list_item_id( $item, $token ) {

			$item['token'] = $token->get_token();

			return $item;
		}


		/**
		 * Adds the Edit and Save buttons to the Actions column.
		 *
		 * @param array $item individual list item from woocommerce_saved_payment_methods_list
		 * @param WC_Payment_Token $core_token payment token associated with this method entry
		 *
		 * @return array
		 * @internal
		 *
		 * @see wc_get_account_saved_payment_methods_list
		 *
		 */
		public function add_payment_methods_list_item_edit_action( $item, $core_token ) {

			// add new actions for FW tokens belonging to this gateway
			if ( $token = $this->get_token_by_id( $core_token->get_token() ) ) {

				$new_actions = array(
					'edit' => array(
						'url'  => '#',
						'name' => esc_html__( 'Edit', 'woodev-plugin-framework' ),
					),
					'save' => array(
						'url'  => '#',
						'name' => esc_html__( 'Save', 'woodev-plugin-framework' ),
					)
				);

				/**
				 * My Payment Methods Table Method Actions Filter.
				 *
				 * Allows actors to modify the table method actions.
				 *
				 * @param $actions array {
				 *
				 * @type string $url action URL
				 * @type string $name action button name
				 * }
				 *
				 * @param Woodev_Payment_Gateway_Payment_Token $token
				 * @param Woodev_Payment_Gateway_My_Payment_Methods $this instance
				 */
				$custom_actions = apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_my_payment_methods_table_method_actions', [], $token, $this );

				$item['actions'] = array_merge( $new_actions, $item['actions'], $custom_actions );
			}

			return $item;
		}


		/**
		 * Adds columns to the payment methods table.
		 *
		 * @param array $columns of table columns in key => Title format
		 *
		 * @return array of table columns in key => Title format
		 * @internal
		 *
		 */
		public function add_payment_methods_columns( $columns = [] ) {

			$title_column = array( 'title' => __( 'Title', 'woocommerce-plugin-framework' ) );
			$columns      = Woodev_Helper::array_insert_after( $columns, 'method', $title_column );

			$details_column = array( 'details' => __( 'Details', 'woocommerce-plugin-framework' ) );
			$columns        = Woodev_Helper::array_insert_after( $columns, 'title', $details_column );

			$default_column = array( 'default' => __( 'Default?', 'woocommerce-plugin-framework' ) );
			$columns        = Woodev_Helper::array_insert_after( $columns, 'expires', $default_column );

			/**
			 * My Payment Methods Table Headers Filter.
			 *
			 * Allow actors to modify the table headers.
			 *
			 * @param array $headers table headers {
			 *
			 * @type string $method
			 * @type string $title
			 * @type string $details
			 * @type string $expires
			 * @type string $default
			 * @type string $actions
			 * }
			 *
			 * @param Woodev_Payment_Gateway_My_Payment_Methods $this instance
			 */
			$columns = apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_my_payment_methods_table_headers', $columns, $this );

			// backwards compatibility for 3rd parties using the filter with the old column keys
			if ( array_key_exists( 'expiry', $columns ) ) {

				$columns['expires'] = $columns['expiry'];
				unset( $columns['expiry'] );
			}

			return $columns;
		}


		/**
		 * Gets FW token object from payment method token ID.
		 *
		 * @param string $token_id token string
		 *
		 * @return Woodev_Payment_Gateway_Payment_Token|null
		 */
		private function get_token_by_id( $token_id ) {

			$token = null;

			foreach ( $this->get_plugin()->get_gateways() as $gateway ) {

				$token = $gateway->get_payment_tokens_handler()->get_token( get_current_user_id(), $token_id );

				if ( ! empty( $token ) ) {
					break;
				}
			}

			return $token;
		}


		/**
		 * Gets FW token object from payment method data array.
		 *
		 * @param array $method payment method data array
		 *
		 * @return Woodev_Payment_Gateway_Payment_Token|null
		 */
		private function get_token_from_method_data_array( $method ) {

			if ( ! empty( $method['token'] ) ) {
				return $this->get_token_by_id( $method['token'] );
			}

			return null;
		}

		/**
		 * Adds the Title column content.
		 *
		 * @param array $method payment method
		 *
		 * @internal
		 *
		 */
		public function add_payment_method_title( $method ) {

			if ( $token = $this->get_token_from_method_data_array( $method ) ) {

				echo $this->get_payment_method_title_html( $token );
			}
		}


		/**
		 * Adds the Details column content.
		 *
		 * @param array $method payment method
		 *
		 * @internal
		 *
		 */
		public function add_payment_method_details( $method ) {

			if ( $token = $this->get_token_from_method_data_array( $method ) ) {

				echo $this->get_payment_method_details_html( $token );
			}
		}


		/**
		 * Adds the Default column content.
		 *
		 * @param array $method payment method
		 *
		 * @internal
		 *
		 */
		public function add_payment_method_default( $method ) {

			echo $this->get_payment_method_default_html( ! empty( $method['is_default'] ), $this->get_token_from_method_data_array( $method ) );
		}


		/**
		 * Triggers the wc_{id}_before_my_payment_method_table action.
		 *
		 * @param bool $has_methods whether there any saved payment methods in the table
		 *
		 * @internal
		 *
		 */
		public function before_payment_methods_table( $has_methods ) {

			if ( $has_methods ) {

				/**
				 * Before My Payment Methods Table Action.
				 *
				 * Fired before WooCommerce's My Payment Methods table HTML is rendered.
				 *
				 * @param Woodev_Payment_Gateway_My_Payment_Methods $this instance
				 */
				do_action( 'wc_' . $this->get_plugin()->get_id() . '_before_my_payment_method_table', $this );
			}
		}


		/**
		 * Triggers the wc_{id}_after_my_payment_method_table action.
		 *
		 * @param bool $has_methods whether there any saved payment methods in the table
		 *
		 * @internal
		 *
		 */
		public function after_payment_methods_table( $has_methods ) {

			if ( $has_methods ) {

				/**
				 * After My Payment Methods Table Action.
				 *
				 * Fired after WooCommerce's My Payment Methods table HTML is rendered.
				 *
				 * @param Woodev_Payment_Gateway_My_Payment_Methods $this instance
				 */
				do_action( 'wc_' . $this->get_plugin()->get_id() . '_after_my_payment_method_table', $this );
			}
		}


		/**
		 * Triggers action wc_payment_gateway_{id}_payment_method_deleted when a framework token is deleted.
		 *
		 * @param int $core_token_id the ID of a core token
		 * @param WC_Payment_Token $core_token the core token object
		 *
		 * @internal
		 *
		 */
		public function payment_token_deleted( $core_token_id, $core_token ) {

			$token_id = null;

			// find out if the core token belongs to one of the gateways from this plugin
			// we can't use get_token_by_id() here because the FW token and associated core token were already deleted
			foreach ( $this->get_plugin()->get_gateways() as $gateway ) {

				if ( $gateway->get_id() === $core_token->get_gateway_id() ) {

					$token_id = $core_token->get_token();
					break;
				}
			}

			// confirm this is one of the plugin's tokens and that the token was deleted from the Payment Methods screen
			if ( $token_id && (int) $core_token_id === (int) get_query_var( 'delete-payment-method' ) ) {

				$user_id = get_current_user_id();

				/**
				 * Fires after a new payment method is deleted by a customer.
				 *
				 * @param string $token_id ID of the deleted token
				 * @param int $user_id user ID
				 *
				 */
				do_action( 'wc_payment_gateway_' . $core_token->get_gateway_id() . '_payment_method_deleted', $token_id, $user_id );
			}
		}


		/**
		 * Renders the payment methods table.
		 *
		 * @internal
		 *
		 * @deprecated 1.1.8
		 */
		public function render() {
			wc_deprecated_function( __METHOD__, '1.1.8' );
		}


		/**
		 * Gets the JS args for the payment methods handler.
		 *
		 * Payment gateways can overwrite this method to define specific args.
		 * render_js() will apply filters to the returned array of args.
		 *
		 * @return array
		 */
		protected function get_js_handler_args() {

			return array(
				'id'              => $this->get_plugin()->get_id(),
				'slug'            => $this->get_plugin()->get_id_dasherized(),
				'has_core_tokens' => (bool) wc_get_customer_saved_methods_list( get_current_user_id() ),
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'ajax_nonce'      => wp_create_nonce( 'wc_' . $this->get_plugin()->get_id() . '_save_payment_method' ),
				'i18n'            => array(
					'edit_button'   => esc_html__( 'Edit', 'woodev-plugin-framework' ),
					'cancel_button' => esc_html__( 'Cancel', 'woodev-plugin-framework' ),
					'save_error'    => esc_html__( 'Oops, there was an error updating your payment method. Please try again.', 'woodev-plugin-framework' ),
					'delete_ays'    => esc_html__( 'Are you sure you want to delete this payment method?', 'woodev-plugin-framework' ),
				),
			);
		}


		/**
		 * Gets the JS handler class name.
		 *
		 * Plugins can override this for their own JS implementations.
		 *
		 * @return string
		 * @deprecated 1.1.8
		 *
		 */
		protected function get_js_handler_class() {

			wc_deprecated_function( __METHOD__, '1.1.8', __CLASS__ . '::get_js_handler_class_name()' );

			return parent::get_js_handler_class_name();
		}


		/**
		 * Adds a log entry.
		 *
		 * @param string $message message to log
		 */
		protected function log_event( $message ) {
			$this->get_plugin()->log( $message );
		}


		/**
		 * Determines whether logging is enabled.
		 *
		 * Considers logging enabled at the plugin level if at least one gateway has logging enabled.
		 *
		 * @return bool
		 */
		protected function is_logging_enabled() {

			$is_logging_enabled = parent::is_logging_enabled();

			if ( ! $is_logging_enabled ) {

				foreach ( $this->get_plugin()->get_gateways() as $gateway ) {

					if ( $gateway->debug_log() ) {
						$is_logging_enabled = true;
						break;
					}
				}
			}

			return $is_logging_enabled;
		}


		/**
		 * Return the no payment methods section HTML
		 *
		 * @return string no payment methods HTML
		 */
		protected function get_no_payment_methods_html() {

			/**
			 * My Payment Methods Table No Methods Text Filter.
			 *
			 * Allow actors to modify the text shown when no saved payment methods are present.
			 *
			 * @param string $message no methods text
			 * @param Woodev_Payment_Gateway_My_Payment_Methods $this instance
			 */
			/* translators: Payment method as in a specific credit card, eCheck or bank account */
			$html = '<p>' . apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_no_payment_methods_text', esc_html__( 'You do not have any saved payment methods.', 'woodev-plugin-framework' ), $this ) . '</p>';

			/**
			 * My Payment Methods Table No Methods HTML Filter.
			 *
			 * Allow actors to modify the HTML used when no saved payment methods are present.
			 *
			 * @param string $html no methods HTML
			 * @param Woodev_Payment_Gateway_My_Payment_Methods $this instance
			 */
			return apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_my_payment_methods_no_payment_methods_html', $html, $this );
		}

		/**
		 * Return the table title HTML, text defaults to "My Payment Methods"
		 *
		 * @return string table title HTML
		 * @deprecated 1.1.8
		 *
		 */
		protected function get_table_title_html() {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			return '';
		}


		/**
		 * Returns the table HTML
		 *
		 * @return string table HTML
		 * @deprecated 1.1.8
		 *
		 */
		public function get_table_html() {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			return '';
		}


		/**
		 * Returns the table head HTML
		 *
		 * @return string table thead HTML
		 * @deprecated 1.1.8
		 *
		 */
		protected function get_table_head_html() {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			return '';
		}


		/**
		 * Returns the table headers.
		 *
		 * @return array of table headers in key => Title format
		 * @deprecated 1.1.8
		 *
		 */
		protected function get_table_headers() {

			wc_deprecated_function( __METHOD__, '1.1.8', 'Woodev_Payment_Gateway_My_Payment_Methods::add_payment_methods_columns' );

			return $this->add_payment_methods_columns();
		}


		/**
		 * Returns the table body HTML
		 *
		 * @return string table tbody HTML
		 * @deprecated 1.1.8
		 *
		 */
		protected function get_table_body_html() {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			return '';
		}


		/**
		 * Returns the table body row HTML, each row represents a single payment method.
		 *
		 * @param Woodev_Payment_Gateway_Payment_Token[] $tokens token objects
		 *
		 * @return string table tbody > tr HTML
		 * @deprecated 1.1.8
		 *
		 */
		protected function get_table_body_row_html( $tokens ) {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			return '';
		}


		/**
		 * Gets the payment method data for a given token.
		 *
		 * @param Woodev_Payment_Gateway_Payment_Token $token the token object
		 *
		 * @return array payment method data suitable for HTML output
		 * @deprecated 1.1.8
		 *
		 */
		protected function get_table_body_row_data( $token ) {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			return array();
		}


		/**
		 * Get a token's payment method title HTML.
		 *
		 * @param Woodev_Payment_Gateway_Payment_Token $token token object
		 *
		 * @return string
		 */
		protected function get_payment_method_title_html( Woodev_Payment_Gateway_Payment_Token $token ) {

			$nickname = $token->get_nickname();
			$title    = $nickname ?: $token->get_type_full();

			/**
			 * Filter a token's payment method title.
			 *
			 * @param string $title payment method title
			 * @param Woodev_Payment_Gateway_Payment_Token $token token object
			 */
			$title = apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_my_payment_methods_table_method_title', $title, $token, $this );

			$html = '<div class="view">' . esc_html( $title ) . '</div>';

			// add the edit context input
			$html .= '<div class="edit" style="display:none;">';
			$html .= '<input type="text" class="nickname" name="nickname" value="' . esc_html( $token->get_nickname() ) . '" placeholder="' . esc_attr( __( 'Nickname', 'woodev-plugin-framework' ) ) . '" />';
			$html .= '<input type="hidden" name="token-id" value="' . esc_attr( $token->get_id() ) . '" />';
			$html .= '<input type="hidden" name="plugin-id" value="' . esc_attr( $this->get_plugin()->get_id_dasherized() ) . '" />';
			$html .= '</div>';

			/**
			 * Filter a token's payment method title HTML.
			 *
			 * @param string $html title HTML
			 * @param Woodev_Payment_Gateway_Payment_Token $token token object
			 */
			return apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_my_payment_methods_table_method_title_html', $html, $token );
		}


		/**
		 * Get a token's payment method "default" flag HTML.
		 *
		 * @param boolean $is_default true if the token is the default token
		 * @param Woodev_Payment_Gateway_Payment_Token|null $token FW token object, only set if the token is a FW token
		 *
		 * @return string
		 */
		protected function get_payment_method_default_html( $is_default = false, Woodev_Payment_Gateway_Payment_Token $token = null ) {

			$html = $is_default ? '<mark class="default">' . esc_html__( 'Default', 'woodev-plugin-framework' ) . '</mark>' : '';

			if ( $token instanceof Woodev_Payment_Gateway_Payment_Token ) {

				/**
				 * Filter a FW token's payment method "default" flag HTML.
				 *
				 * @param string $html "default" flag HTML
				 * @param Woodev_Payment_Gateway_Payment_Token $token token object
				 */
				$html = apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_my_payment_methods_table_method_default_html', $html, $token );
			}

			return $html;
		}


		/**
		 * Gets a token's payment method details HTML.
		 *
		 * This includes the method type icon, last four digits, and "default" badge if applicable.
		 * Example:
		 * [icon] * * * 1234 [default]
		 *
		 * @param Woodev_Payment_Gateway_Payment_Token $token token object
		 *
		 * @return string HTML Markdown
		 */
		protected function get_payment_method_details_html( Woodev_Payment_Gateway_Payment_Token $token ) {

			$html = '';

			if ( $image_url = $token->get_image_url() ) {
				$html .= sprintf( '<img src="%1$s" alt="%2$s" title="%2$s" width="40" height="25" />', esc_url( $image_url ), esc_attr( $token->get_type_full() ) );
			}

			if ( $last_four = $token->get_last_four() ) {
				$html .= "&bull; &bull; &bull; {$last_four}";
			}

			/**
			 * Filters a token's payment method details HTML.
			 *
			 * @param string $html details HTML
			 * @param Woodev_Payment_Gateway_Payment_Token $token token object
			 */
			return apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_my_payment_methods_table_details_html', $html, $token );
		}


		/**
		 * Get a token's payment method expiration date HTML.
		 *
		 * @param Woodev_Payment_Gateway_Payment_Token $token token object
		 *
		 * @return string
		 * @deprecated 1.1.8
		 *
		 */
		protected function get_payment_method_expiry_html( Woodev_Payment_Gateway_Payment_Token $token ) {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			return '';
		}


		/**
		 * Get a token's payment method actions HTML.
		 *
		 * @param Woodev_Payment_Gateway_Payment_Token $token token object
		 *
		 * @return string
		 * @deprecated 1.1.8
		 *
		 */
		protected function get_payment_method_actions_html( Woodev_Payment_Gateway_Payment_Token $token ) {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			return '';
		}


		/**
		 * Gets the actions for the given payment method token.
		 *
		 * @param Woodev_Payment_Gateway_Payment_Token $token token object
		 *
		 * @return array
		 * @deprecated 1.1.8
		 *
		 */
		protected function get_payment_method_actions( $token ) {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			return array();
		}

		/**
		 * Saves a payment method via AJAX.
		 *
		 * @internal
		 */
		public function ajax_save_payment_method() {

			check_ajax_referer( 'wc_' . $this->get_plugin()->get_id() . '_save_payment_method', 'nonce' );

			try {

				$this->load_tokens();

				$token_id = Woodev_Helper::get_posted_value( 'token_id' );

				if ( empty( $this->tokens[ $token_id ] ) || ! $this->tokens[ $token_id ] instanceof Woodev_Payment_Gateway_Payment_Token ) {
					throw new Woodev_Payment_Gateway_Exception( 'Invalid token ID' );
				}

				$user_id = get_current_user_id();
				$token   = $this->tokens[ $token_id ];
				$gateway = $this->get_plugin()->get_gateway_from_token( $user_id, $token );

				// bail if the gateway or token couldn't be found for this user
				if ( ! $gateway || ! $gateway->get_payment_tokens_handler()->user_has_token( $user_id, $token ) ) {
					throw new Woodev_Payment_Gateway_Exception( 'Invalid token' );
				}

				$data = array();

				parse_str( Woodev_Helper::get_posted_value( 'data' ), $data );

				// set the data
				$token = $this->save_token_data( $token, $data );

				// persist the data
				$gateway->get_payment_tokens_handler()->update_token( $user_id, $token );

				wp_send_json_success( [
					'title' => $this->get_payment_method_title_html( $token ),
					'nonce' => wp_create_nonce( 'wc_' . $this->get_plugin()->get_id() . '_save_payment_method' ),
				] );

			} catch ( Woodev_Payment_Gateway_Exception $e ) {

				wp_send_json_error( $e->getMessage() );
			}
		}


		/**
		 * Saves data to a token.
		 *
		 * Gateways can override this to set their own data if they add custom Edit
		 * fields. Note that this does not persist the data to the db, but only sets
		 * it for the object.
		 *
		 * @param Woodev_Payment_Gateway_Payment_Token $token token object
		 * @param array $data {
		 *    new data to store for the token
		 *
		 * @type string $nickname method nickname
		 * @type string $default whether the method should be set as default
		 * }
		 * @return Woodev_Payment_Gateway_Payment_Token
		 */
		protected function save_token_data( Woodev_Payment_Gateway_Payment_Token $token, array $data ) {

			$raw_nickname   = ! empty( $data['nickname'] ) ? $data['nickname'] : '';
			$clean_nickname = wc_clean( $raw_nickname );

			// only set the nickname if there is a clean value, or it was deliberately cleared
			if ( $clean_nickname || ! $raw_nickname ) {
				$token->set_nickname( $clean_nickname );
			}

			return $token;
		}


		/**
		 * Handles custom payment methods actions.
		 *
		 * @internal
		 */
		public function handle_payment_method_actions() {

			$token  = trim( Woodev_Helper::get_requested_value( 'wc-' . $this->get_plugin()->get_id_dasherized() . '-token' ) );
			$action = Woodev_Helper::get_requested_value( 'wc-' . $this->get_plugin()->get_id_dasherized() . '-action' );

			// process payment method actions
			if ( $token && $action && ! empty( $_GET['_wpnonce'] ) && is_user_logged_in() ) {

				// security check
				if ( false === wp_verify_nonce( $_GET['_wpnonce'], 'wc-' . $this->get_plugin()->get_id_dasherized() . '-token-action' ) ) {

					Woodev_Helper::wc_add_notice( esc_html__( 'Oops, you took too long, please try again.', 'woodev-plugin-framework' ), 'error' );

					$this->redirect_to_my_account();
				}

				$user_id = get_current_user_id();
				$gateway = $this->get_plugin()->get_gateway_from_token( $user_id, $token );

				// couldn't find an associated gateway for that token
				if ( ! is_object( $gateway ) ) {

					Woodev_Helper::wc_add_notice( esc_html__( 'There was an error with your request, please try again.', 'woodev-plugin-framework' ), 'error' );

					$this->redirect_to_my_account();
				}

				/**
				 * My Payment Methods Custom Action.
				 *
				 * Fired when a custom action is requested for a payment method (e.g. other than delete/make default)
				 *
				 * @param Woodev_Payment_Gateway_My_Payment_Methods $this instance
				 */
				do_action( 'wc_' . $this->get_plugin()->get_id() . '_my_payment_methods_action_' . sanitize_title( $action ), $this );

				$this->redirect_to_my_account();
			}
		}


		/**
		 * Renders the JavaScript.
		 */
		public function render_js() {
			wc_enqueue_js( $this->get_safe_handler_js() );
		}


		/**
		 * Redirect back to the Payment Methods (WC 2.6+) or My Account page
		 */
		protected function redirect_to_my_account() {

			wp_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit;
		}


		/**
		 * Return the gateway plugin, primarily a convenience method to other actors using filters
		 *
		 * @return Woodev_Payment_Gateway_Plugin
		 */
		public function get_plugin() {
			return $this->plugin;
		}


		/**
		 * Returns true if at least one of the plugin's gateways supports the add new payment method feature
		 *
		 * @return bool
		 */
		protected function supports_add_payment_method() {

			foreach ( $this->get_plugin()->get_gateways() as $gateway ) {

				if ( $gateway->is_direct_gateway() && $gateway->supports_add_payment_method() ) {
					return true;
				}
			}

			return false;
		}


		/**
		 * Determines if we're viewing the My Account -> Payment Methods page.
		 *
		 * @return bool
		 */
		protected function is_payment_methods_page() {
			global $wp;

			return is_user_logged_in() && is_account_page() && isset( $wp->query_vars['payment-methods'] );
		}

	}

endif;