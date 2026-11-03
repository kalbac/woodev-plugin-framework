<?php
/**
 * Woodev Shipping Orders — delivery analytics REST controller
 *
 * @since 2.0.24
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Admin\Orders\{Analytics_Aggregator, Analytics_Cache, Analytics_Query, Analytics_Report, Orders_Provider, Orders_Registry};

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Analytics_Controller' ) ) :

	/**
	 * `GET /shipping/orders/analytics` — the aggregates behind the «Аналитика» tab of the shipping orders page (#711).
	 *
	 * A read of financial totals, so it takes the page capability like the orders list does (a 403, never a silently empty
	 * answer). It validates the request, answers from {@see Analytics_Cache} when it can and otherwise lets
	 * {@see Analytics_Report} build the answer; the SQL, the arithmetic and the cache live in their own classes.
	 *
	 * | param            | rule                                                                          |
	 * |------------------|-------------------------------------------------------------------------------|
	 * | `after`/`before` | required `Y-m-d`, `after <= before`, at most {@see self::MAX_RANGE_DAYS} days |
	 * | `compare_after`/`compare_before` | both or neither, same rule                            |
	 * | `interval`       | `day`, `week`, `month`, `auto` (default)                                      |
	 * | `carrier`        | a registered provider id; absent = all                                        |
	 * | `refresh`        | true => skip the cache                                                        |
	 *
	 * @since 2.0.24
	 */
	class Analytics_Controller extends \WP_REST_Controller {

		/**
		 * The longest range a request may ask for, in days.
		 *
		 * @var int
		 */
		public const MAX_RANGE_DAYS = 800;

		/**
		 * WooCommerce order statuses that are not part of the population (spec §2.1): not revenue and not a delivery outcome.
		 * Filter `woodev_shipping_analytics_excluded_statuses`.
		 *
		 * @var string[]
		 */
		public const DEFAULT_EXCLUDED_STATUSES = [ 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'wc-checkout-draft', 'auto-draft', 'trash' ];

		/**
		 * Registry to read the page capability and the providers from.
		 *
		 * @var Orders_Registry
		 */
		private $registry;

		/**
		 * Constructor.
		 *
		 * @since 2.0.24
		 *
		 * @param Orders_Registry $registry orders registry.
		 */
		public function __construct( Orders_Registry $registry ) {
			$this->registry = $registry;
		}

		/**
		 * Registers the route.
		 *
		 * @since 2.0.24
		 *
		 * @return void
		 */
		public function register_routes(): void {
			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/analytics',
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_report' ],
					'permission_callback' => [ $this, 'get_permissions_check' ],
					'args'                => $this->route_args(),
				]
			);
		}

		/**
		 * The page capability.
		 *
		 * @since 2.0.24
		 *
		 * @return bool
		 */
		public function get_permissions_check(): bool {
			return current_user_can( $this->registry->get_page_capability() );
		}

		/**
		 * Validates a `Y-m-d` argument; an empty optional value passes.
		 *
		 * @since 2.0.24
		 *
		 * @param mixed $value the value.
		 * @return bool
		 */
		public static function validate_date( $value ): bool {
			if ( '' === $value || null === $value ) {
				return true;
			}

			return is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
		}

		/**
		 * Answers the request.
		 *
		 * @since 2.0.24
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_report( $request ) {
			$params = $this->read_params( $request );

			if ( $params instanceof \WP_Error ) {
				return $params;
			}

			$providers = $this->registry->get_providers();

			if ( null !== $params['carrier'] && ! in_array( $params['carrier'], $this->provider_ids( $providers ), true ) ) {
				return $this->bad_request( 'woodev_shipping_analytics_unknown_carrier', __( 'Такого перевозчика нет.', 'woodev-plugin-framework' ) );
			}

			$hpos = \Woodev_Plugin_Compatibility::is_hpos_enabled();

			try {
				$answer = Analytics_Cache::fetch(
					$this->cache_key( $params, $providers, $hpos ),
					Analytics_Cache::ttl_for( $params['before'], $params['timezone'] ),
					function () use ( $params, $providers, $hpos ): array {
						return $this->build( $params, $providers, $hpos );
					},
					$params['refresh']
				);
			} catch ( \RuntimeException $e ) {
				// Nothing was stored: a failed read must not be cached as an answer. The database's text goes to the log, not to the client.
				error_log( '[woodev] Delivery analytics query failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for the server log; the client gets a generic message.

				return new \WP_Error( 'woodev_shipping_analytics_failed', __( 'Не удалось собрать аналитику. Попробуйте позже.', 'woodev-plugin-framework' ), [ 'status' => 500 ] );
			}

			return rest_ensure_response( $answer['data'] + [ 'cached' => $answer['cached'] ] );
		}

		/**
		 * The argument schema of the route.
		 *
		 * @return array<string,array<string,mixed>>
		 */
		private function route_args(): array {
			$date = [
				'type'              => 'string',
				'validate_callback' => [ __CLASS__, 'validate_date' ],
			];

			return [
				'after'          => $date + [ 'required' => true ],
				'before'         => $date + [ 'required' => true ],
				'compare_after'  => $date,
				'compare_before' => $date,
				'interval'       => [
					'type'    => 'string',
					'enum'    => [ 'day', 'week', 'month', 'auto' ],
					'default' => 'auto',
				],
				'carrier'        => [ 'type' => 'string' ],
				'refresh'        => [
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				],
			];
		}

		/**
		 * Reads the request into the shape {@see Analytics_Report::build()} takes, or answers a 400.
		 *
		 * @param \WP_REST_Request $request request.
		 * @return array<string,mixed>|\WP_Error
		 */
		private function read_params( $request ) {
			$params = [
				'after'          => (string) $request->get_param( 'after' ),
				'before'         => (string) $request->get_param( 'before' ),
				'compare_after'  => (string) $request->get_param( 'compare_after' ),
				'compare_before' => (string) $request->get_param( 'compare_before' ),
				'interval'       => (string) ( $request->get_param( 'interval' ) ?: 'auto' ),
				'carrier'        => $this->carrier_param( (string) $request->get_param( 'carrier' ) ),
				'refresh'        => (bool) $request->get_param( 'refresh' ),
				'week_start'     => (int) get_option( 'start_of_week', 1 ),
				'timezone'       => wp_timezone(),
				'excluded'       => $this->excluded_statuses(),
			];

			$error = $this->range_error( $params['after'], $params['before'] );

			if ( null === $error && ( '' !== $params['compare_after'] || '' !== $params['compare_before'] ) ) {
				$error = $this->range_error( $params['compare_after'], $params['compare_before'] );
			}

			return $error ?? $params;
		}

		/**
		 * The provider id a request restricts to; `all` and nothing both mean «every carrier».
		 *
		 * @param string $carrier the `carrier` argument.
		 * @return string|null
		 */
		private function carrier_param( string $carrier ): ?string {
			return '' === $carrier || 'all' === $carrier ? null : $carrier;
		}

		/**
		 * A 400 for a missing, reversed or too wide range; null when the range is usable.
		 *
		 * @param string $after  first day.
		 * @param string $before last day.
		 * @return \WP_Error|null
		 */
		private function range_error( string $after, string $before ): ?\WP_Error {
			if ( '' === $after || '' === $before || $after > $before ) {
				return $this->bad_request( 'woodev_shipping_analytics_bad_range', __( 'Укажите период: начало не может быть позже конца.', 'woodev-plugin-framework' ) );
			}

			if ( Analytics_Aggregator::days_in_range( $after, $before ) > self::MAX_RANGE_DAYS ) {
				return $this->bad_request( 'woodev_shipping_analytics_range_too_wide', __( 'Период слишком длинный — выберите не больше 800 дней.', 'woodev-plugin-framework' ) );
			}

			return null;
		}

		/**
		 * A 400 error.
		 *
		 * @param string $code    error code.
		 * @param string $message merchant message.
		 * @return \WP_Error
		 */
		private function bad_request( string $code, string $message ): \WP_Error {
			return new \WP_Error( $code, $message, [ 'status' => 400 ] );
		}

		/**
		 * The statuses outside the population.
		 *
		 * @return string[]
		 */
		private function excluded_statuses(): array {
			/**
			 * Filters the WooCommerce order statuses the delivery analytics leaves out of its population.
			 *
			 * @since 2.0.24
			 *
			 * @param string[] $statuses statuses with their `wc-` prefix, as stored.
			 */
			$statuses = apply_filters( 'woodev_shipping_analytics_excluded_statuses', self::DEFAULT_EXCLUDED_STATUSES );

			return is_array( $statuses ) ? array_values( array_filter( $statuses, 'is_string' ) ) : self::DEFAULT_EXCLUDED_STATUSES;
		}

		/**
		 * Builds the answer on a cache miss.
		 *
		 * @param array<string,mixed> $params    validated request.
		 * @param Orders_Provider[]   $providers registered providers.
		 * @param bool                $hpos      whether HPOS is the datastore.
		 * @return array<string,mixed>
		 */
		private function build( array $params, array $providers, bool $hpos ): array {
			global $wpdb;

			$report = new Analytics_Report( new Analytics_Query( $wpdb, $hpos ), $providers, [ $this, 'method_label' ] );

			return $report->build( $params ) + [
				'currency'     => get_woocommerce_currency(),
				'generated_at' => gmdate( 'c' ),
			];
		}

		/**
		 * The label the merchant reads for a shipping method id; the id itself when WooCommerce does not know it.
		 *
		 * @since 2.0.24
		 *
		 * @param string $method_id shipping method id.
		 * @return string
		 */
		public function method_label( string $method_id ): string {
			$methods = function_exists( 'WC' ) ? WC()->shipping()->get_shipping_methods() : [];
			$title   = isset( $methods[ $method_id ] ) ? (string) $methods[ $method_id ]->get_method_title() : '';

			return '' !== $title ? $title : $method_id;
		}

		/**
		 * Everything the answer depends on — the cache key.
		 *
		 * @param array<string,mixed> $params    validated request.
		 * @param Orders_Provider[]   $providers registered providers.
		 * @param bool                $hpos      whether HPOS is the datastore.
		 * @return array<string,mixed>
		 */
		private function cache_key( array $params, array $providers, bool $hpos ): array {
			$key = $params;

			unset( $key['refresh'], $key['timezone'] );

			$key['timezone']  = $params['timezone']->getName();
			$key['providers'] = $this->provider_signature( $providers );
			$key['hpos']      = $hpos;

			return $key;
		}

		/**
		 * A fingerprint of what the providers say about statuses and markers, so a plugin update that changes a
		 * `status_map` does not serve buckets built with the old one.
		 *
		 * @param Orders_Provider[] $providers registered providers.
		 * @return string
		 */
		private function provider_signature( array $providers ): string {
			$signature = [];

			foreach ( $providers as $provider ) {
				$signature[] = [
					$provider->get_id(),
					$provider->get_marker_meta_key(),
					$provider->get_status_meta_key(),
					$provider->get_status_map(),
				];
			}

			return md5( (string) wp_json_encode( $signature ) );
		}

		/**
		 * The ids of the providers.
		 *
		 * @param Orders_Provider[] $providers registered providers.
		 * @return string[]
		 */
		private function provider_ids( array $providers ): array {
			return array_map(
				static function ( Orders_Provider $provider ): string {
					return $provider->get_id();
				},
				$providers
			);
		}
	}

endif;
