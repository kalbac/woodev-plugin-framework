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

use Woodev\Framework\Shipping\Admin\Orders\{Admin_Rate_Calculator, Orders_Registry};
use Woodev\Framework\Shipping\Fee_Payments;
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
		 * The longest pickup-point id the route reads — the pickup routes' own cap.
		 *
		 * @since 2.0.7
		 *
		 * @var int
		 */
		private const MAX_POINT_ID_LENGTH = 128;

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
		 * chosen customer, and — once the manager has chosen one — the payment method. Nothing is
		 * read from a cart or a session.
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
						// The gateway the order will be paid with. The wizard sends it once the manager has
						// chosen one (step ⑤), so the quote carries what depends on it; absent = no method yet.
						'payment_method' => [
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => 'rest_validate_request_arg',
						],
						// The pickup tariff the manager chose and the point under it (step ⑤): a pickup carrier's price
						// depends on the point, so the re-quote is made for it. Both or neither.
						'method_id'      => [
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => 'rest_validate_request_arg',
						],
						'pickup_point'   => [
							'type'              => 'string',
							'default'           => '',
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

			$payment_method = $this->resolve_payment_method( $request->get_param( 'payment_method' ) );

			if ( $payment_method instanceof \WP_Error ) {
				return $payment_method;
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

			$pickup = $this->resolve_pickup( $request, $lines, $destination, $customer_id, $payment_method );

			if ( $pickup instanceof \WP_Error ) {
				return $pickup;
			}

			return rest_ensure_response( $this->calculator->calculate( $lines, $destination, $record, $customer_id, $payment_method, $pickup ) );
		}

		/**
		 * Validates the optional `method_id` + `pickup_point` pair: the point must belong to the chosen
		 * method and pass the acceptance check step ⑤ already runs (see
		 * {@see Admin_Rate_Calculator::refuse_pickup()}). It is installed into the carrier's own selection
		 * accessor for the one calculation, so an id the carrier would refuse is a 422, never priced.
		 *
		 * @since 2.0.7
		 *
		 * @param \WP_REST_Request                                                          $request        the request.
		 * @param array<int, array{product: \WC_Product, quantity: int, price: float|null}> $lines          resolved lines.
		 * @param array<string, string>                                                     $destination    normalized destination.
		 * @param int                                                                       $customer_id    customer id.
		 * @param string                                                                    $payment_method validated payment method.
		 * @return array{method_id: string, point_id: string}|null|\WP_Error `null` when no point was sent.
		 */
		private function resolve_pickup( $request, array $lines, array $destination, int $customer_id, string $payment_method ) {

			$point_id  = $this->clean_param( $request->get_param( 'pickup_point' ), self::MAX_POINT_ID_LENGTH );
			$method_id = $this->clean_param( $request->get_param( 'method_id' ), self::MAX_POINT_ID_LENGTH );

			if ( '' === $point_id ) {
				return null;
			}

			$pickup  = [
				'method_id' => $method_id,
				'point_id'  => $point_id,
			];
			$refusal = '' === $method_id
				? __( 'Укажите способ доставки, к которому относится пункт выдачи.', 'woodev-plugin-framework' )
				: $this->calculator->refuse_pickup( $lines, $destination, $customer_id, $payment_method, $pickup );

			if ( null !== $refusal ) {
				return new \WP_Error(
					'woodev_rates_pickup_point_refused',
					$refusal,
					[ 'status' => 422 ]
				);
			}

			return $pickup;
		}

		/**
		 * A string param, unslashed, cleaned and capped; `''` for anything that is not a string.
		 *
		 * @since 2.0.7
		 *
		 * @param mixed $raw    the raw param.
		 * @param int   $length the cap.
		 * @return string
		 */
		private function clean_param( $raw, int $length ): string {

			return is_string( $raw ) ? substr( trim( (string) wc_clean( wp_unslash( $raw ) ) ), 0, $length ) : '';
		}

		/**
		 * Validates the optional `payment_method` param against the shop's enabled gateways: it rides
		 * into the carriers' rate code as the chosen method, so an id the shop does not offer is
		 * refused rather than priced.
		 *
		 * @since 2.0.7
		 *
		 * @param mixed $raw the raw param.
		 * @return string|\WP_Error `''` when none was sent.
		 */
		private function resolve_payment_method( $raw ) {

			$method = is_string( $raw ) ? trim( $raw ) : '';

			if ( '' === $method ) {
				return '';
			}

			if ( ! array_key_exists( $method, Fee_Payments::gateway_options() ) ) {
				return new \WP_Error(
					'woodev_rates_unknown_payment_method',
					__( 'Такого способа оплаты нет в магазине.', 'woodev-plugin-framework' ),
					[ 'status' => 422 ]
				);
			}

			return $method;
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
