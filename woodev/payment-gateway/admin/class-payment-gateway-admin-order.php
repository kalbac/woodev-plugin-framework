<?php


defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Payment_Gateway_Admin_Order' ) ) :


	/**
	 * Handle the admin order screens.
	 */
	class Woodev_Payment_Gateway_Admin_Order {

		/** @var Woodev_Payment_Gateway_Plugin the plugin instance * */
		protected $plugin;


		/**
		 * Constructs the class.
		 *
		 * @param Woodev_Payment_Gateway_Plugin $plugin The plugin instance
		 */
		public function __construct( Woodev_Payment_Gateway_Plugin $plugin ) {

			$this->plugin = $plugin;

			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

			// capture feature
			if ( $this->get_plugin()->supports_capture_charge() ) {

				add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'add_capture_button' ) );

				add_action( 'wp_ajax_wc_' . $this->get_plugin()->get_id() . '_capture_charge', array(
					$this,
					'ajax_process_capture'
				) );

				// bulk capture order action
				add_action( 'admin_footer-edit.php', array( $this, 'maybe_add_capture_charge_bulk_order_action' ) );
				add_action( 'load-edit.php', array( $this, 'process_capture_charge_bulk_order_action' ) );
			}
		}


		/**
		 * Enqueues the scripts and styles.
		 *
		 * @param string $hook_suffix page hook suffix
		 *
		 * @internal
		 *
		 */
		public function enqueue_scripts( $hook_suffix ) {

			// Order screen assets
			if ( 'shop_order' === get_post_type() ) {

				// Edit Order screen assets
				if ( 'post.php' === $hook_suffix ) {

					$order = wc_get_order( Woodev_Helper::get_requested_value( 'post' ) );

					if ( ! $order ) {
						return;
					}

					// bail if the order payment method doesn't belong to this plugin
					if ( ! $this->get_order_gateway( $order ) ) {
						return;
					}

					$this->enqueue_edit_order_assets( $order );
				}
			}
		}


		/**
		 * Enqueues the assets for the Edit Order screen.
		 *
		 * @param WC_Order $order order object
		 */
		protected function enqueue_edit_order_assets( WC_Order $order ) {

			wp_enqueue_script( 'woodev-payment-gateway-admin-order', $this->get_plugin()->get_payment_gateway_framework_assets_url() . '/js/admin/woodev-payment-gateway-admin-order.js', array( 'jquery' ), Woodev_Plugin::VERSION, true );

			wp_localize_script( 'woodev-payment-gateway-admin-order', 'woodev_payment_gateway_admin_order', array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'gateway_id'     => $order->get_payment_method( 'edit' ),
				'order_id'       => $order->get_id(),
				'capture_ays'    => __( 'Are you sure you wish to process this capture? The action cannot be undone.', 'woodev-plugin-framework' ),
				'capture_action' => 'wc_' . $this->get_plugin()->get_id() . '_capture_charge',
				'capture_nonce'  => wp_create_nonce( 'wc_' . $this->get_plugin()->get_id() . '_capture_charge' ),
				'capture_error'  => __( 'Something went wrong, and the capture could no be completed. Please try again.', 'woodev-plugin-framework' ),
			) );

			wp_enqueue_style( 'woodev-payment-gateway-admin-order', $this->get_plugin()->get_payment_gateway_framework_assets_url() . '/css/admin/woodev-payment-gateway-admin-order.css', Woodev_Plugin::VERSION );
		}

		/**
		 * Adds 'Capture charge' to the Orders screen bulk action select.
		 */
		public function maybe_add_capture_charge_bulk_order_action() {
			global $post_type, $post_status;

			if ( ! current_user_can( 'edit_shop_orders' ) ) {
				return;
			}

			if ( $post_type === 'shop_order' && $post_status !== 'trash' ) {

				$can_capture_charge = false;

				// ensure at least one gateway supports capturing charge
				foreach ( $this->get_plugin()->get_gateways() as $gateway ) {

					// ensure that it supports captures
					if ( $gateway->supports_credit_card_capture() ) {

						$can_capture_charge = true;
						break;
					}
				}

				if ( $can_capture_charge ) {

					?>
                    <script type="text/javascript">
                        jQuery(document).ready(function ($) {
                            if (0 == $('select[name^=action] option[value=wc_capture_charge]').size()) {
                                $('select[name^=action]').append(
                                    $('<option>').val('<?php echo esc_js( 'wc_capture_charge' ); ?>').text('<?php _e( 'Capture Charge', 'woodev-plugin-framework' ); ?>')
                                );
                            }
                        });
                    </script>
					<?php
				}
			}
		}


		/**
		 * Processes the 'Capture Charge' custom bulk action.
		 */
		public function process_capture_charge_bulk_order_action() {
			global $typenow;

			if ( 'shop_order' === $typenow ) {

				// get the action
				$wp_list_table = _get_list_table( 'WP_Posts_List_Table' );
				$action        = $wp_list_table->current_action();

				// bail if not processing a capture
				if ( 'wc_capture_charge' !== $action ) {
					return;
				}

				if ( ! current_user_can( 'edit_shop_orders' ) ) {
					return;
				}

				// security check
				check_admin_referer( 'bulk-posts' );

				// make sure order IDs are submitted
				if ( isset( $_REQUEST['post'] ) ) {
					$order_ids = array_map( 'absint', $_REQUEST['post'] );
				}

				// return if there are no orders to export
				if ( empty( $order_ids ) ) {
					return;
				}

				// give ourselves an unlimited timeout if possible
				@set_time_limit( 0 );

				foreach ( $order_ids as $order_id ) {

					$order = wc_get_order( $order_id );

					if ( $order && ( $gateway = $this->get_order_gateway( $order ) ) ) {
						$gateway->get_capture_handler()->maybe_perform_capture( $order );
					}
				}
			}
		}


		/**
		 * Adds a "Capture Charge" action to the admin Order Edit screen
		 *
		 * @param array $actions available order actions
		 *
		 * @return array
		 */
		public function add_order_action_charge_action( $actions ) {

			/* translators: verb, as in "Capture credit card charge".
			 Used when an amount has been pre-authorized before, but funds have not yet been captured (taken) from the card.
			 Capturing the charge will take the money from the credit card and put it in the merchant's pockets. */
			$actions[ 'wc_' . $this->get_plugin()->get_id() . '_capture_charge' ] = esc_html__( 'Capture Charge', 'woodev-plugin-framework' );

			return $actions;
		}


		/**
		 * Adds the capture charge button to the order UI.
		 *
		 * @param WC_Order $order order object
		 *
		 * @internal
		 *
		 */
		public function add_capture_button( $order ) {

			// only display the button for core orders
			if ( ! $order instanceof WC_Order || 'shop_order' !== get_post_type( $order->get_id() ) ) {
				return;
			}

			$gateway = $this->get_order_gateway( $order );

			if ( ! $gateway ) {
				return;
			}

			if ( ! $gateway->get_capture_handler()->is_order_ready_for_capture( $order ) ) {
				return;
			}

			$tooltip = '';
			$classes = array(
				'button',
				'woodev-payment-gateway-capture',
				'wc-' . $gateway->get_id_dasherized() . '-capture',
			);

			// indicate if the partial-capture UI can be shown
			if ( $gateway->supports_credit_card_partial_capture() && $gateway->is_partial_capture_enabled() ) {
				$classes[] = 'partial-capture';
			} elseif ( $gateway->get_capture_handler()->order_can_be_captured( $order ) ) {
				$classes[] = 'button-primary';
			}

			// ensure that the authorization is still valid for capture
			if ( ! $gateway->get_capture_handler()->order_can_be_captured( $order ) ) {

				$classes[] = 'tips disabled';

				// add some tooltip wording explaining why this cannot be captured
				if ( $gateway->get_capture_handler()->is_order_fully_captured( $order ) ) {
					$tooltip = __( 'This charge has been fully captured.', 'woodev-plugin-framework' );
				} elseif ( $gateway->get_order_meta( $order, 'trans_date' ) && $gateway->get_capture_handler()->has_order_authorization_expired( $order ) ) {
					$tooltip = __( 'This charge can no longer be captured.', 'woodev-plugin-framework' );
				} else {
					$tooltip = __( 'This charge cannot be captured.', 'woodev-plugin-framework' );
				}
			}

			?>

            <button type="button"
                    class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" <?php echo ( $tooltip ) ? 'data-tip="' . esc_html( $tooltip ) . '"' : ''; ?>><?php _e( 'Capture Charge', 'woodev-plugin-framework' ); ?></button>

			<?php

			// add the partial capture UI HTML
			if ( $gateway->supports_credit_card_partial_capture() && $gateway->is_partial_capture_enabled() ) {
				$this->output_partial_capture_html( $order, $gateway );
			}
		}


		/**
		 * Outputs the partial capture UI HTML.
		 *
		 * @param WC_Order $order order object
		 * @param Woodev_Payment_Gateway $gateway gateway instance
		 */
		protected function output_partial_capture_html( WC_Order $order, Woodev_Payment_Gateway $gateway ) {

			$authorization_total = $gateway->get_capture_handler()->get_order_authorization_amount( $order );
			$total_captured      = $gateway->get_order_meta( $order, 'capture_total' );
			$remaining_total     = Woodev_Helper::number_format( $order->get_total() - (float) $total_captured );

			include( $this->get_plugin()->get_payment_gateway_framework_path() . '/admin/views/html-order-partial-capture.php' );
		}


		/**
		 * Processes a capture via AJAX.
		 *
		 * @internal
		 *
		 */
		public function ajax_process_capture() {

			check_ajax_referer( 'wc_' . $this->get_plugin()->get_id() . '_capture_charge', 'nonce' );

			$gateway_id = Woodev_Helper::get_requested_value( 'gateway_id' );

			if ( ! $this->get_plugin()->has_gateway( $gateway_id ) ) {
				die();
			}

			$gateway = $this->get_plugin()->get_gateway( $gateway_id );

			try {

				$order_id = Woodev_Helper::get_requested_value( 'order_id' );
				$order    = wc_get_order( $order_id );

				if ( ! $order ) {
					throw new Woodev_Payment_Gateway_Exception( 'Invalid order ID' );
				}

				if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
					throw new Woodev_Payment_Gateway_Exception( 'Invalid permissions' );
				}

				if ( $order->get_payment_method( 'edit' ) !== $gateway->get_id() ) {
					throw new Woodev_Payment_Gateway_Exception( 'Invalid payment method' );
				}

				if ( $request_amount = Woodev_Helper::get_requested_value( 'amount' ) ) {
					$amount = (float) $request_amount;
				} else {
					$amount = $order->get_total();
				}

				$result = $gateway->get_capture_handler()->perform_capture( $order, $amount );

				if ( empty( $result['success'] ) ) {
					throw new Woodev_Payment_Gateway_Exception( $result['message'] );
				}

				wp_send_json_success( [
					'message' => html_entity_decode( wp_strip_all_tags( $result['message'] ) ),
					// ensure any HTML tags are removed and the currency symbol entity is decoded
				] );

			} catch ( Woodev_Payment_Gateway_Exception $e ) {

				wp_send_json_error( [
					'message' => $e->getMessage(),
				] );
			}
		}


		/**
		 * Gets the gateway object from an order.
		 *
		 * @param WC_Order $order order object
		 *
		 * @return Woodev_Payment_Gateway
		 */
		protected function get_order_gateway( WC_Order $order ) {

			$capture_gateway = null;
			$payment_method  = $order->get_payment_method( 'edit' );

			if ( $this->get_plugin()->has_gateway( $payment_method ) ) {

				$gateway = $this->get_plugin()->get_gateway( $payment_method );

				// ensure that it supports captures
				if ( $gateway->supports_credit_card_capture() ) {
					$capture_gateway = $gateway;
				}
			}

			return $capture_gateway;
		}


		/**
		 * Gets the plugin instance.
		 *
		 * @return Woodev_Payment_Gateway_Plugin the plugin instance
		 */
		protected function get_plugin() {
			return $this->plugin;
		}


		/**
		 * Capture a credit card charge for a prior authorization if this payment
		 * method was used for the given order, the charge hasn't already been
		 * captured, and the gateway supports issuing a capture request
		 *
		 * @param WC_Order|int $order the order identifier or order object
		 * @param float|null $amount capture amount
		 *
		 * @deprecated 1.1.8
		 *
		 */
		protected function maybe_capture_charge( $order, $amount = null ) {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			if ( ! is_object( $order ) ) {
				$order = wc_get_order( $order );
			}

			$gateway = $this->get_order_gateway( $order );

			if ( ! $gateway ) {
				return;
			}

			// don't try to capture cancelled/fully refunded transactions
			if ( ! $gateway->get_capture_handler()->is_order_ready_for_capture( $order ) ) {
				return;
			}

			// since a capture results in an update to the post object (by updating
			// the paid date) we need to unhook the meta box save action, otherwise we
			// can get boomeranged and change the status back to on-hold
			remove_action( 'woocommerce_process_shop_order_meta', 'WC_Meta_Box_Order_Data::save', 40 );

			// perform the capture
			$gateway->get_capture_handler()->maybe_perform_capture( $order, $amount );
		}


		/**
		 * Captures an order on status change to a "paid" status.
		 *
		 * @param int $order_id order ID
		 * @param string $old_status status being changed
		 * @param string $new_status new order status
		 *
		 * @deprecated 1.1.8
		 *
		 * @internal
		 *
		 */
		public function maybe_capture_paid_order( $order_id, $old_status, $new_status ) {
			wc_deprecated_function( __METHOD__, '1.1.8' );
		}

		/**
		 * Determines if an order is ready for capture.
		 *
		 * @param WC_Order $order order object
		 *
		 * @return bool
		 * @deprecated 1.1.8
		 *
		 */
		protected function is_order_ready_for_capture( WC_Order $order ) {

			wc_deprecated_function( __METHOD__, '1.1.8' );

			$gateway = $this->get_order_gateway( $order );

			return $gateway && $gateway->get_capture_handler()->is_order_ready_for_capture( $order );
		}


	}


endif;
