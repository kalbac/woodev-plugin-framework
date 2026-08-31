<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Payment_Gateway_Admin_Payment_Token_Editor' ) ) :


	/**
	 * The token editor.
	 */
	class Woodev_Payment_Gateway_Admin_Payment_Token_Editor {

		/** @var Woodev_Payment_Gateway the gateway object * */
		protected $gateway;


		/**
		 * Constructs the editor.
		 *
		 * @param Woodev_Payment_Gateway $gateway the gateway object
		 */
		public function __construct( Woodev_Payment_Gateway $gateway ) {

			$this->gateway = $gateway;

			// Load the editor scripts and styles
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts_styles' ] );

			// Display the tokens markup inside the editor
			add_action(
				'wc_payment_gateway_' . $this->get_gateway()->get_id() . '_token_editor_tokens',
				[
					$this,
					'display_tokens',
				]
			);

			/** AJAX actions */

			// Get the blank token markup via AJAX
			add_action(
				'wp_ajax_wc_payment_gateway_' . $this->get_gateway()->get_id() . '_admin_get_blank_payment_token',
				[
					$this,
					'ajax_get_blank_token',
				]
			);

			// Remove a token via AJAX
			add_action(
				'wp_ajax_wc_payment_gateway_' . $this->get_gateway()->get_id() . '_admin_remove_payment_token',
				[
					$this,
					'ajax_remove_token',
				]
			);

			// Refresh the tokens via AJAX
			add_action(
				'wp_ajax_wc_payment_gateway_' . $this->get_gateway()->get_id() . '_admin_refresh_payment_tokens',
				[
					$this,
					'ajax_refresh_tokens',
				]
			);
		}


		/**
		 * Load the editor scripts and styles.
		 *
		 * Only enqueued for a capable user (`manage_woocommerce`) viewing a user
		 * profile screen, so the editor's AJAX nonces are never localized for an
		 * unauthorized visitor or on unrelated admin screens.
		 *
		 * @since 2.0.2
		 */
		public function enqueue_scripts_styles() {

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			if ( ! Woodev_Helper::is_current_screen( 'profile' ) && ! Woodev_Helper::is_current_screen( 'user-edit' ) ) {
				return;
			}

			// Stylesheet
			wp_enqueue_style( 'woodev-payment-gateway-token-editor', $this->get_gateway()->get_plugin()->get_payment_gateway_framework_assets_url() . '/css/admin/woodev-payment-gateway-token-editor.css', [], Woodev_Plugin::VERSION );

			// Main editor script
			wp_enqueue_script( 'woodev-payment-gateway-token-editor', $this->get_gateway()->get_plugin()->get_payment_gateway_framework_assets_url() . '/js/admin/woodev-payment-gateway-token-editor.js', [ 'jquery' ], Woodev_Plugin::VERSION, true );

			wp_localize_script(
				'woodev-payment-gateway-token-editor',
				'wc_payment_gateway_token_editor',
				[
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'actions'  => [
						'remove_token' => [
							'ays'   => __( 'Are you sure you want to remove this token?', 'woodev-plugin-framework' ),
							'nonce' => wp_create_nonce( 'wc_payment_gateway_admin_remove_payment_token' ),
						],
						'add_token'    => [
							'nonce' => wp_create_nonce( 'wc_payment_gateway_admin_get_blank_payment_token' ),
						],
						'refresh'      => [
							'nonce' => wp_create_nonce( 'wc_payment_gateway_admin_refresh_payment_tokens' ),
						],
						'save'         => [
							'error' => __( 'Invalid token data', 'woodev-plugin-framework' ),
						],
					],
					'i18n'     => [
						'general_error' => __( 'An error occurred. Please try again.', 'woodev-plugin-framework' ),
					],
				]
			);
		}


		/**
		 * Display the token editor.
		 *
		 * @param int $user_id the user ID
		 */
		public function display( $user_id ) {

			$id      = $this->get_gateway()->get_id();
			$title   = $this->get_title();
			$columns = $this->get_columns();
			$actions = $this->get_actions();

			// Issue #393: the view prints this as a hidden input, so a POST can tell "this
			// editor was on the form" from "this editor was never rendered".
			$rendered_marker_name = $this->get_rendered_marker_name();

			include $this->get_gateway()->get_plugin()->get_payment_gateway_framework_path() . '/admin/views/html-user-payment-token-editor.php';
		}


		/**
		 * Display the tokens.
		 *
		 * @param int $user_id the user ID
		 */
		public function display_tokens( $user_id ) {

			$tokens = $this->get_tokens( $user_id );

			$fields     = $this->get_fields();
			$input_name = $this->get_input_name();
			$actions    = $this->get_token_actions();
			$type       = $this->get_payment_type();

			$index = 0;

			foreach ( $tokens as $token ) {

				include $this->get_gateway()->get_plugin()->get_payment_gateway_framework_path() . '/admin/views/html-user-payment-token-editor-token.php';

				++$index;
			}
		}


		/**
		 * The hidden input name that marks this editor as having been RENDERED (issue #393).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function get_rendered_marker_name() {
			return $this->get_input_name() . '_rendered';
		}

		/**
		 * Save the token editor.
		 *
		 * DOES NOTHING WHEN THIS EDITOR WAS NOT ON THE SUBMITTED FORM (issue #393).
		 *
		 * The data loss this prevents: `Admin_User_Handler::display_token_editors()` SKIPS an
		 * editor whose gateway supports a customer id when the customer has none saved yet
		 * (`continue`), while `save_tokens()` calls this method on EVERY registered editor
		 * unconditionally. This method then read a missing `$_POST` section as `array()` and
		 * handed it to `update_tokens()` — which reads an empty array as "the admin removed
		 * every token". So opening such a customer's profile and pressing «Обновить
		 * пользователя» for any unrelated reason, a display-name typo included, deleted every
		 * saved card they had with that gateway. The same happened on any
		 * `edit_user_profile_update` / `personal_options_update` fired without token fields.
		 *
		 * A MARKER IS REQUIRED BECAUSE ABSENCE IS AMBIGUOUS, and this is the whole reason the
		 * fix is not a one-line `isset()` guard on the tokens key. An editor rendered with
		 * ZERO rows posts no token inputs either — identical to one never rendered — so
		 * "no tokens key" cannot distinguish "the admin deleted the last card" (which must
		 * persist) from "this table was skipped" (which must not touch anything). The hidden
		 * marker the view prints answers exactly that question and nothing else.
		 *
		 * Permissions are not the issue here and never were: `save_profile_fields()` is
		 * correctly gated on `manage_woocommerce`. This is data loss, not an access hole.
		 *
		 * @param int $user_id the user ID
		 */
		public function save( $user_id ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see the note below.
			//
			// A previous version of this comment said the nonce is verified by
			// `save_profile_fields()`. It is NOT — that method checks only `is_supported()`
			// and `current_user_can( 'manage_woocommerce' )`
			// (`class-payment-gateway-admin-user-handler.php:140-150`). A critic pass caught
			// the false claim; a wrong security note is worse than none, because the next
			// reader stops looking.
			//
			// The real gate is WordPress core: `wp-admin/user-edit.php` and `profile.php` run
			// `check_admin_referer( 'update-user_' . $user_id )` BEFORE firing
			// `personal_options_update` / `edit_user_profile_update`, which are the only two
			// hooks that reach `save_profile_fields()` and therefore this method. The marker
			// read below is attacker-controllable POST data, so what it can do matters: its
			// only effect is to make this method DO NOTHING, never to write anything.
			if ( ! isset( $_POST[ $this->get_rendered_marker_name() ] ) ) {
				return;
			}

			$tokens = ( isset( $_POST[ $this->get_input_name() ] ) ) ? $_POST[ $this->get_input_name() ] : [];

			$built_tokens = [];

			foreach ( $tokens as $data ) {

				$token_id = $data['id'];

				unset( $data['id'] );

				if ( ! $token_id ) {
					continue;
				}

				if ( 'credit_card' === $data['type'] ) {
					$data = $this->prepare_expiry_date( $data );
				}

				// Set the default method
				$data['default'] = $token_id === Woodev_Helper::get_posted_value( $this->get_input_name() . '_default' );

				if ( $data = $this->validate_token_data( $token_id, $data ) ) {
					$built_tokens[ $token_id ] = $this->build_token( $user_id, $token_id, $data );
				}
			}

			$this->update_tokens( $user_id, $built_tokens );
		}


		/**
		 * Add a token via AJAX.
		 *
		 * @since 2.0.2 gated behind `manage_woocommerce`, in addition to the nonce
		 * @since 2.0.2 capability check now runs before the nonce check, consistent with the other handlers
		 */
		public function ajax_get_blank_token() {

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error();
				return;
			}

			check_ajax_referer( 'wc_payment_gateway_admin_get_blank_payment_token', 'security' );

			$index = Woodev_Helper::get_requested_value( 'index' );

			if ( $index ) {

				$fields     = $this->get_fields();
				$input_name = $this->get_input_name();
				$actions    = $this->get_token_actions();
				$type       = $this->get_payment_type();
				$user_id    = 0;

				$token            = array_fill_keys( array_keys( $fields ), '' );
				$token['id']      = '';
				$token['expiry']  = '';
				$token['default'] = false;

				ob_start();

				include $this->get_gateway()->get_plugin()->get_payment_gateway_framework_path() . '/admin/views/html-user-payment-token-editor-token.php';

				$html = ob_get_clean();

				wp_send_json_success( $html );

			} else {

				wp_send_json_error();
			}
		}


		/**
		 * Remove a token via AJAX.
		 *
		 * @since 2.0.2 gated behind `manage_woocommerce`, in addition to the nonce
		 * @since 2.0.2 also requires `edit_user` on the target user, since `manage_woocommerce`
		 *              alone does not authorize acting on an arbitrary user object
		 */
		public function ajax_remove_token() {

			try {

				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					throw new Woodev_Payment_Gateway_Exception( 'You do not have permission to do this' );
				}

				if ( ! check_ajax_referer( 'wc_payment_gateway_admin_remove_payment_token', 'security' ) ) {
					throw new Woodev_Payment_Gateway_Exception( 'Invalid nonce' );
				}

				$user_id  = $this->get_authorized_target_user_id();
				$token_id = Woodev_Helper::get_requested_value( 'token_id' );

				if ( ! $token_id ) {
					throw new Woodev_Payment_Gateway_Exception( 'Token ID is missing' );
				}

				if ( $this->remove_token( $user_id, $token_id ) ) {
					wp_send_json_success();
				} else {
					throw new Woodev_Payment_Gateway_Exception( 'Could not remove token' );
				}
			} catch ( Woodev_Plugin_Exception $e ) {

				wp_send_json_error( $e->getMessage() );
			}
		}


		/**
		 * Refresh the tokens list via AJAX.
		 *
		 * @since 2.0.2 gated behind `manage_woocommerce`, in addition to the nonce
		 * @since 2.0.2 also requires `edit_user` on the target user, since `manage_woocommerce`
		 *              alone does not authorize acting on an arbitrary user object
		 */
		public function ajax_refresh_tokens() {

			try {

				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					throw new Woodev_Payment_Gateway_Exception( 'You do not have permission to do this' );
				}

				if ( ! check_ajax_referer( 'wc_payment_gateway_admin_refresh_payment_tokens', 'security', false ) ) {
					throw new Woodev_Payment_Gateway_Exception( 'Invalid nonce' );
				}

				$user_id = $this->get_authorized_target_user_id();

				ob_start();

				$this->display_tokens( $user_id );

				$html = ob_get_clean();

				wp_send_json_success( trim( $html ) );

			} catch ( Woodev_Payment_Gateway_Exception $e ) {

				wp_send_json_error( $e->getMessage() );
			}
		}


		/**
		 * Resolve and authorize the user ID targeted by an AJAX request.
		 *
		 * Normalizes the requested value to a positive integer, then requires
		 * `edit_user` on it, and only then confirms the ID resolves to an
		 * existing user. `manage_woocommerce` alone does not authorize acting
		 * on an arbitrary target: WordPress checks object-level capabilities
		 * like `edit_user` per target, so a shop manager holding
		 * `manage_woocommerce` is not necessarily allowed to edit an
		 * administrator (or, on multisite, a user outside the current site).
		 *
		 * The capability check runs before the existence lookup deliberately:
		 * `current_user_can( 'edit_user', $id )` resolves to WordPress core's
		 * generic `edit_users` capability for any non-self, non-multisite-superadmin
		 * target — it does not query whether `$id` is a real user — so a caller
		 * without `edit_user` on the target is denied identically whether that
		 * target exists or not. Running `get_userdata()` first would instead let
		 * such a caller distinguish an existing-but-forbidden user ID from a
		 * nonexistent one by which error comes back.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 capability check now runs before the existence lookup, so
		 *              a caller cannot use the error to enumerate real user IDs
		 *
		 * @param string $key the request key holding the target user ID
		 *
		 * @return int the authorized target user ID
		 *
		 * @throws Woodev_Payment_Gateway_Exception if the user ID is missing, invalid, not editable by the current user, or does not resolve to an existing user
		 */
		protected function get_authorized_target_user_id( string $key = 'user_id' ): int {

			$raw_user_id = Woodev_Helper::get_requested_value( $key );

			if ( ! is_scalar( $raw_user_id ) ) {
				throw new Woodev_Payment_Gateway_Exception( 'User ID is missing' );
			}

			$user_id = filter_var( $raw_user_id, FILTER_VALIDATE_INT );

			if ( false === $user_id || $user_id <= 0 ) {
				throw new Woodev_Payment_Gateway_Exception( 'User ID is missing' );
			}

			if ( ! current_user_can( 'edit_user', $user_id ) ) {
				throw new Woodev_Payment_Gateway_Exception( 'You do not have permission to do this' );
			}

			if ( ! get_userdata( $user_id ) ) {
				throw new Woodev_Payment_Gateway_Exception( 'User ID is missing' );
			}

			return $user_id;
		}


		/**
		 * Build a token object from data saved in the admin.
		 *
		 * This method allows concrete gateways to add special token data.
		 * See Authorize.net CIM for an example.
		 *
		 * @param int    $user_id the user ID
		 * @param string $token_id the token ID
		 * @param array  $data the token data
		 *
		 * @return Woodev_Payment_Gateway_Payment_Token the payment token object
		 */
		protected function build_token( $user_id, $token_id, $data ) {

			return $this->get_gateway()->get_payment_tokens_handler()->build_token( $token_id, $data );
		}


		/**
		 * Update the user's token data.
		 *
		 * @param int   $user_id the user ID
		 * @param array $tokens the token objects
		 */
		protected function update_tokens( $user_id, $tokens ) {

			$this->get_gateway()->get_payment_tokens_handler()->update_tokens( $user_id, $tokens, $this->get_gateway()->get_environment() );
		}


		/**
		 * Remove a specific token.
		 *
		 * @param int    $user_id the user ID
		 * @param string $token_id the token ID
		 *
		 * @return bool whether the token was successfully removed
		 */
		protected function remove_token( $user_id, $token_id ) {

			return $this->get_gateway()->get_payment_tokens_handler()->remove_token( $user_id, $token_id, $this->get_gateway()->get_environment() );
		}


		/**
		 * Validate a token's data before saving.
		 *
		 * Concrete gateways can override this to provide their own validation.
		 *
		 * @since 2.0.2 the filtered value is now actually validated to be an array or false,
		 *              matching this method's own documented contract; any other type is
		 *              discarded and degrades to the pre-filter $data, since `save()` passes a
		 *              truthy return straight into `build_token()`'s untyped `$data` parameter
		 *
		 * @param int   $token_id the token ID
		 * @param array $data the token data
		 *
		 * @return array|bool the validated token data or false if the token should not be saved
		 */
		protected function validate_token_data( $token_id, $data ) {

			/**
			 * Filter the validated token data.
			 *
			 * @since 2.0.2 only an array or `false` is honoured; any other return is discarded
			 *              and the pre-filter $data is used instead
			 *
			 * @param array $data the validated token data
			 * @param int   $token_id the token ID
			 * @param Woodev_Payment_Gateway_Admin_Payment_Token_Editor $instance the token editor instance
			 *
			 * @return array|bool the validated token data, or false to skip saving
			 */
			$filtered = apply_filters( 'wc_payment_gateway_' . $this->get_gateway()->get_id() . '_token_editor_validate_token_data', $data, $token_id, $this );

			return is_array( $filtered ) || false === $filtered ? $filtered : $data;
		}


		/**
		 * Correctly format a credit card expiration date for storage.
		 *
		 * @param array $data
		 *
		 * @return array
		 */
		protected function prepare_expiry_date( $data ) {

			// expiry date must be present, include a forward slash and be 5 characters (MM/YY)
			if ( ! $data['expiry'] || ! Woodev_Helper::str_exists( $data['expiry'], '/' ) || 5 !== strlen( $data['expiry'] ) ) {
				unset( $data['expiry'] );

				return $data;
			}

			list( $data['exp_month'], $data['exp_year'] ) = explode( '/', $data['expiry'] );

			unset( $data['expiry'] );

			return $data;
		}


		/**
		 * Get the stored tokens for a user.
		 *
		 * @param int $user_id the user ID
		 *
		 * @return array the tokens in db format
		 */
		protected function get_tokens( $user_id ) {

			// Clear any cached tokens
			$this->get_gateway()->get_payment_tokens_handler()->clear_transient( $user_id );

			// get the customer ID separately so it's never auto-created from the admin
			$customer_id = $this->get_gateway()->get_customer_id(
				$user_id,
				[
					'autocreate' => false,
				]
			);

			$stored_tokens = $this->get_gateway()->get_payment_tokens_handler()->get_tokens(
				$user_id,
				[
					'customer_id' => $customer_id,
				]
			);

			$tokens = [];

			foreach ( $stored_tokens as $token ) {

				$token_id = $token->get_id();

				// Set the token data
				$tokens[ $token_id ] = $token->to_datastore_format();

				$tokens[ $token_id ]['id'] = $token_id;

				// Set the credit card expiration date
				if ( $token->is_credit_card() ) {
					$tokens[ $token_id ]['expiry'] = $token->get_exp_month() && $token->get_exp_year() ? $token->get_exp_date() : '';
				}

				$tokens[ $token_id ]['default'] = $token->is_default();

				// Parse against the editor field IDs so we don't have to isset throughout the HTML
				$tokens[ $token_id ] = wp_parse_args( $tokens[ $token_id ], array_fill_keys( array_keys( $this->get_fields() ), '' ) );
			}

			return $tokens;
		}


		/**
		 * Get the editor title.
		 *
		 * @return string
		 */
		protected function get_title() {

			$title = $this->get_gateway()->get_title();

			// Append the environment name if there are multiple
			if ( $this->get_gateway()->get_plugin()->get_admin_user_handler()->has_multiple_environments() ) {
				$title .= ' ' . sprintf( __( '(%s)', 'woodev-plugin-framework' ), $this->get_gateway()->get_environment_name() );
			}

			/**
			 * Filters the token editor name.
			 *
			 * @param string $title the editor title
			 * @param Woodev_Payment_Gateway_Admin_Payment_Token_Editor $instance the editor object
			 */
			return apply_filters( 'wc_payment_gateway_' . $this->get_gateway()->get_id() . '_token_editor_title', $title, $this );
		}


		/**
		 * Get the editor columns.
		 *
		 * @since 2.0.2 the filtered value is validated as an array; a non-array return
		 *              discards the filter and degrades to the pre-filter columns, since the
		 *              caller does `count( $columns )` for a table `colspan`
		 *
		 * @return array
		 */
		protected function get_columns() {

			$fields  = $this->get_fields();
			$columns = [];

			foreach ( $fields as $field_id => $field ) {
				$columns[ $field_id ] = isset( $field['label'] ) ? $field['label'] : '';
			}

			$columns['default'] = __( 'Default', 'woodev-plugin-framework' );
			$columns['actions'] = '';

			/**
			 * Filters the admin token editor columns.
			 *
			 * @since 2.0.2 a non-array return is discarded; the pre-filter columns are used instead
			 *
			 * @param array $columns
			 * @param Woodev_Payment_Gateway_Admin_Payment_Token_Editor $instance the editor object
			 */
			$filtered = apply_filters( 'wc_payment_gateway_' . $this->get_gateway()->get_id() . '_token_editor_columns', $columns, $this );

			return is_array( $filtered ) ? $filtered : $columns;
		}


		/**
		 * Get the editor fields.
		 *
		 * @since 2.0.2 the filtered value is validated as an array; a non-array return
		 *              discards the filter and degrades to the pre-filter fields, since callers
		 *              do `array_keys( $fields )` (`ajax_get_blank_token()`) and iterate the result
		 *
		 * @return array
		 */
		protected function get_fields( $type = '' ) {

			if ( ! $type ) {
				$type = $this->get_gateway()->get_payment_type();
			}

			switch ( $type ) {

				case 'credit-card':
					// Define the credit card fields
					$fields = [
						'id'        => [
							'label'    => __( 'Token ID', 'woodev-plugin-framework' ),
							'editable' => ! $this->get_gateway()->get_api()->supports_get_tokenized_payment_methods(),
							'required' => true,
						],
						'card_type' => [
							'label'   => __( 'Card Type', 'woodev-plugin-framework' ),
							'type'    => 'select',
							'options' => $this->get_card_type_options(),
						],
						'last_four' => [
							'label'      => __( 'Last Four', 'woodev-plugin-framework' ),
							'attributes' => [
								'pattern'   => '[0-9]{4}',
								'maxlength' => 4,
							],
						],
						'expiry'    => [
							'label'      => __( 'Expiration (MM/YY)', 'woodev-plugin-framework' ),
							'attributes' => [
								'placeholder' => 'MM/YY',
								'pattern'     => '(0[1-9]|1[012])[- /.]\d\d',
								'maxlength'   => 5,
							],
						],
					];

					break;

				default:
					$fields = [];
			}

			// Parse each field against the defaults
			foreach ( $fields as $field_id => $field ) {

				$fields[ $field_id ] = wp_parse_args(
					$field,
					[
						'label'      => '',
						'type'       => 'text',
						'attributes' => [],
						'editable'   => true,
						'required'   => false,
					]
				);
			}

			/**
			 * Filters the admin token editor fields.
			 *
			 * @since 2.0.2 a non-array return is discarded; the pre-filter fields are used instead
			 *
			 * @param array $fields
			 * @param Woodev_Payment_Gateway_Admin_Payment_Token_Editor $instance the editor object
			 */
			$filtered = apply_filters( 'wc_payment_gateway_' . $this->get_gateway()->get_id() . '_token_editor_fields', $fields, $this );

			return is_array( $filtered ) ? $filtered : $fields;
		}


		/**
		 * Get the token payment type.
		 *
		 * @return string
		 */
		protected function get_payment_type() {
			return str_replace( '-', '_', $this->get_gateway()->get_payment_type() );
		}


		/**
		 * Get the credit card type field options.
		 *
		 * @return array
		 */
		protected function get_card_type_options() {

			$card_types = $this->get_gateway()->get_card_types();
			$options    = [];

			foreach ( $card_types as $card_type ) {

				$card_type = Woodev_Payment_Gateway_Helper::normalize_card_type( $card_type );

				$options[ $card_type ] = Woodev_Payment_Gateway_Helper::payment_type_to_name( $card_type );
			}

			return $options;
		}


		/**
		 * Get the HTML name for the token fields.
		 *
		 * @return string
		 */
		protected function get_input_name() {
			return 'wc_payment_gateway_' . $this->get_gateway()->get_id() . '_tokens';
		}


		/**
		 * Get the available editor actions.
		 *
		 * @return array
		 */
		protected function get_actions() {

			$actions = [];

			if ( $this->get_gateway()->get_api()->supports_get_tokenized_payment_methods() ) {
				$actions['refresh'] = __( 'Refresh', 'woodev-plugin-framework' );
			} else {
				$actions['add-new'] = __( 'Add New', 'woodev-plugin-framework' );
			}

			$actions['save'] = __( 'Save', 'woodev-plugin-framework' );

			/**
			 * Filters the payment token editor actions.
			 *
			 * @param array $actions the actions
			 * @param Woodev_Payment_Gateway_Admin_Payment_Token_Editor $instance the editor object
			 */
			return apply_filters( 'wc_payment_gateway_' . $this->get_gateway()->get_id() . '_token_editor_actions', $actions, $this );
		}


		/**
		 * Get the available token actions.
		 *
		 * @return array
		 */
		protected function get_token_actions() {

			$actions = [
				'remove' => __( 'Remove', 'woodev-plugin-framework' ),
			];

			/**
			 * Filters the token actions.
			 *
			 * @param array $actions the token actions
			 * @param Woodev_Payment_Gateway_Admin_Payment_Token_Editor $instance the editor object
			 */
			return apply_filters( 'wc_payment_gateway_' . $this->get_gateway()->get_id() . '_token_editor_token_actions', $actions, $this );
		}


		/**
		 * Gets the gateway object.
		 *
		 * @return Woodev_Payment_Gateway the gateway object
		 */
		protected function get_gateway() {
			return $this->gateway;
		}
	}


endif;
