<?php
/**
 * Woodev Shipping Orders — rates REST controller
 *
 * `POST woodev/v1/shipping/orders/rates`: the carriers' rates for a package the admin order
 * wizard built by hand (#710 spec D2, card #965). Registered through
 * {@see \Woodev_REST_V1_Registrar} by
 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::register_rest()}, next to
 * {@see Orders_Controller}, and gated exactly like that controller's write routes.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Admin\Orders\Admin_Rate_Calculator;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Rates_Controller' ) ) :

	/**
	 * `POST woodev/v1/shipping/orders/rates` dispatch controller.
	 *
	 * @since 2.0.2
	 */
	class Rates_Controller extends \WP_REST_Controller {

		/**
		 * Most lines one request may price — a wizard order is hand-built, so this is a
		 * safety floor against a runaway client, not a business limit.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		private const MAX_LINES = 200;

		/**
		 * Rate calculator.
		 *
		 * @since 2.0.2
		 *
		 * @var Admin_Rate_Calculator
		 */
		private $calculator;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry            $registry   orders registry, the providers' source.
		 * @param Admin_Rate_Calculator|null $calculator calculator; defaults to one built from `$registry`.
		 */
		public function __construct( Orders_Registry $registry, ?Admin_Rate_Calculator $calculator = null ) {
			$this->calculator = $calculator ?? new Admin_Rate_Calculator( $registry );
		}

		/**
		 * Registers `POST /shipping/orders/rates`.
		 *
		 * The body is the wizard's state after the Items step: the lines at their edited prices,
		 * the destination (typed fields and/or a `Location_Record::to_array()` shape) and the
		 * chosen customer. Nothing is read from a cart or a session.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {

			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/rates',
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'get_rates' ],
					'permission_callback' => [ $this, 'get_rates_permissions_check' ],
					'args'                => [
						'items'       => [
							'type'              => 'array',
							'required'          => true,
							'minItems'          => 1,
							'maxItems'          => self::MAX_LINES,
							'items'             => [
								'type'       => 'object',
								'required'   => [ 'product_id', 'quantity' ],
								'properties' => [
									'product_id'   => [
										'type'    => 'integer',
										'minimum' => 1,
									],
									'variation_id' => [
										'type'    => 'integer',
										'minimum' => 0,
									],
									'quantity'     => [
										'type'    => 'integer',
										'minimum' => 1,
									],
									// The per-unit price the manager set (spec O8); absent keeps the product's own.
									'price'        => [
										'type'    => 'number',
										'minimum' => 0,
									],
								],
							],
							'validate_callback' => 'rest_validate_request_arg',
						],
						'destination' => [
							'type'              => 'object',
							'default'           => [],
							'validate_callback' => 'rest_validate_request_arg',
						],
						// A `Location_Record::to_array()` shape. No nested schema, for the reason
						// `/location/select` gives: the contract lives in Location_Record::from_array().
						'location'    => [
							'type'              => 'object',
							'validate_callback' => 'rest_validate_request_arg',
						],
						'customer_id' => [
							'type'              => 'integer',
							'minimum'           => 0,
							'default'           => 0,
							'validate_callback' => 'rest_validate_request_arg',
						],
					],
				]
			);
		}

		/**
		 * Permission gate: `edit_shop_orders`, the WooCommerce order-edit capability every
		 * shipping-orders write route requires ({@see Orders_Controller::perform_action_permissions_check()}).
		 * A missing login is answered 401 and a lacking capability 403 by WordPress itself.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return bool
		 */
		public function get_rates_permissions_check( $request ): bool {
			return current_user_can( 'edit_shop_orders' );
		}

		/**
		 * Answers the rates, grouped by carrier.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_rates( $request ) {

			$record = $this->parse_location( $request->get_param( 'location' ) );

			if ( $record instanceof \WP_Error ) {
				return $record;
			}

			$customer_id = absint( $request->get_param( 'customer_id' ) );

			if ( $customer_id > 0 && false === get_userdata( $customer_id ) ) {
				return new \WP_Error(
					'woodev_rates_unknown_customer',
					__( 'Покупатель не найден.', 'woodev-plugin-framework' ),
					[ 'status' => 422 ]
				);
			}

			$lines = $this->resolve_lines( (array) $request->get_param( 'items' ) );

			if ( $lines instanceof \WP_Error ) {
				return $lines;
			}

			$destination = $this->calculator->normalize_destination(
				(array) $request->get_param( 'destination' ),
				$record
			);

			if ( '' === $destination['country'] ) {
				return new \WP_Error(
					'woodev_rates_no_country',
					__( 'Укажите страну доставки.', 'woodev-plugin-framework' ),
					[ 'status' => 422 ]
				);
			}

			return rest_ensure_response( $this->calculator->calculate( $lines, $destination, $record, $customer_id ) );
		}

		/**
		 * Turns the request lines into products; an unknown product is a 422, never a skipped line
		 * (a silently smaller package would price the wrong parcel).
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, mixed> $items request `items`.
		 * @return array<int, array{product: \WC_Product, quantity: int, price: float|null}>|\WP_Error
		 */
		private function resolve_lines( array $items ) {

			$lines = [];

			foreach ( $items as $item ) {

				$item         = (array) $item;
				$product_id   = absint( $item['product_id'] ?? 0 );
				$variation_id = absint( $item['variation_id'] ?? 0 );
				$product      = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );

				if ( ! $product instanceof \WC_Product ) {
					return new \WP_Error(
						'woodev_rates_unknown_product',
						sprintf(
							/* translators: %d: product id. */
							__( 'Товар №%d не найден.', 'woodev-plugin-framework' ),
							$variation_id > 0 ? $variation_id : $product_id
						),
						[ 'status' => 422 ]
					);
				}

				$lines[] = [
					'product'  => $product,
					'quantity' => max( 1, absint( $item['quantity'] ?? 1 ) ),
					'price'    => isset( $item['price'] ) && is_numeric( $item['price'] ) ? max( 0.0, (float) $item['price'] ) : null,
				];
			}

			return $lines;
		}

		/**
		 * Parses the optional `location` param into a record.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $raw the raw param.
		 * @return Location_Record|\WP_Error|null `null` when none was sent.
		 */
		private function parse_location( $raw ) {

			if ( null === $raw || [] === $raw ) {
				return null;
			}

			try {
				if ( ! is_array( $raw ) ) {
					throw new \InvalidArgumentException( 'location must be an object' );
				}

				return Location_Record::from_array( $raw );
			} catch ( \InvalidArgumentException $exception ) {
				return new \WP_Error(
					'woodev_location_invalid_record',
					__( 'Некорректные данные о местоположении.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}
		}
	}

endif;
