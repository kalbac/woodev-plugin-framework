<?php
/**
 * Guest access check for the public `woodev/v1` REST routes (#1631).
 *
 * @package Woodev\Framework\Shipping\Rest_Api
 */

namespace Woodev\Framework\Shipping\Rest_Api;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Guest_Access_Check' ) ) :

	/**
	 * Tells the merchant when the REST API is closed to guests.
	 *
	 * The city search and the pickup points at the order form read the public `woodev/v1/location/suggest`
	 * route; a customer is usually not logged in. Some security or caching plugins (or a theme) close the
	 * REST API to guests, and then the buyer sees an empty search and cannot pick a delivery method, while
	 * the administrator — logged in — sees nothing wrong.
	 *
	 * So the server asks the route the way a guest would: a loopback request without cookies, like Site
	 * Health does. The probe sends NO query parameters, on purpose: our route answers that with a 400
	 * `rest_missing_callback_param` that names `q` and `level`, which proves the request reached OUR
	 * route, costs no provider call and spends none of the route's rate-limit budget.
	 *
	 * The answer is kept for a day (a transient). It is read on every admin page that shows it, but the
	 * request itself is made only when there is no kept answer — on the settings pages and in Site
	 * Health, never on the storefront and never on a random admin page — or on the «Проверить снова» link.
	 *
	 * @since 2.1.9
	 */
	final class Guest_Access_Check {

		/** The guest reached our route. */
		public const STATUS_OK = 'ok';

		/** The request was redirected — the REST API is closed with a redirect (e.g. to the home page). */
		public const STATUS_REDIRECT = 'redirect';

		/** A page came back instead of JSON — a rewrite rule or the theme took the address over. */
		public const STATUS_HTML = 'html';

		/** 401 or 403 — a plugin demands a login. */
		public const STATUS_DENIED = 'denied';

		/** JSON came back, but not from our route (a «no route» error, another plugin's answer). */
		public const STATUS_FOREIGN = 'foreign';

		/** The server could not make the request — nothing is known, so nothing is warned about. */
		public const STATUS_UNREACHABLE = 'unreachable';

		/** The kept answer. */
		public const TRANSIENT = 'woodev_rest_guest_access';

		/** The query argument of the «check again» link, and its nonce action. */
		public const RECHECK_ARG = 'woodev_recheck_rest_access';

		/** How long an answer is kept. */
		private const CACHE_TTL = DAY_IN_SECONDS;

		/** The route probed, under the REST root. */
		private const ROUTE = 'woodev/v1/location/suggest';

		/** One wiring for the whole process, whatever the number of carrier plugins. */
		private static bool $registered = false;

		/**
		 * Wires the notice and the Site Health test, once.
		 *
		 * @since 2.1.9
		 */
		public static function register(): void {
			if ( self::$registered ) {
				return;
			}
			self::$registered = true;

			add_action( 'admin_init', [ __CLASS__, 'handle_recheck' ] );
			add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );
			add_filter( 'site_status_tests', [ __CLASS__, 'add_site_health_test' ] );
		}

		/**
		 * Sorts a loopback answer.
		 *
		 * @since 2.1.9
		 *
		 * @param int    $code HTTP status.
		 * @param string $body Response body.
		 * @return string One of the STATUS_* constants (never STATUS_UNREACHABLE: that is a failed request, not an answer).
		 */
		public static function classify( int $code, string $body ): string {

			if ( $code >= 300 && $code < 400 ) {
				return self::STATUS_REDIRECT;
			}

			if ( 401 === $code || 403 === $code ) {
				return self::STATUS_DENIED;
			}

			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return self::STATUS_HTML;
			}

			return self::is_our_answer( $code, $data ) ? self::STATUS_OK : self::STATUS_FOREIGN;
		}

		/**
		 * Whether a decoded JSON body is what OUR route says to a request without arguments.
		 *
		 * @param int                 $code HTTP status.
		 * @param array<string,mixed> $data Decoded body.
		 */
		private static function is_our_answer( int $code, array $data ): bool {

			if ( 400 !== $code || 'rest_missing_callback_param' !== ( $data['code'] ?? '' ) ) {
				return false;
			}

			$params = $data['data']['params'] ?? [];

			return is_array( $params ) && in_array( 'q', $params, true );
		}

		/**
		 * Whether a status is a problem the merchant has to act on.
		 *
		 * @since 2.1.9
		 *
		 * @param string $status A STATUS_* constant.
		 */
		public static function is_problem( string $status ): bool {
			return in_array( $status, [ self::STATUS_REDIRECT, self::STATUS_HTML, self::STATUS_DENIED, self::STATUS_FOREIGN ], true );
		}

		/**
		 * Makes the loopback request as a guest.
		 *
		 * @since 2.1.9
		 *
		 * @return string A STATUS_* constant.
		 */
		public static function probe(): string {

			$response = wp_remote_get(
				rest_url( self::ROUTE ),
				[
					'timeout'     => 5,
					'redirection' => 0,
					'cookies'     => [],
					'headers'     => [ 'Accept' => 'application/json' ],
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				]
			);

			if ( is_wp_error( $response ) ) {
				return self::STATUS_UNREACHABLE;
			}

			return self::classify( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
		}

		/**
		 * The kept answer, if there is one.
		 *
		 * @since 2.1.9
		 *
		 * @return string|null A STATUS_* constant, or null when nothing is kept.
		 */
		public static function get_cached_status(): ?string {

			$kept = get_transient( self::TRANSIENT );

			return is_string( $kept ) && '' !== $kept ? $kept : null;
		}

		/**
		 * The answer — kept, or fresh when nothing is kept.
		 *
		 * @since 2.1.9
		 *
		 * @return string A STATUS_* constant.
		 */
		public static function get_status(): string {

			$status = self::get_cached_status();

			if ( null === $status ) {
				$status = self::probe();
				set_transient( self::TRANSIENT, $status, self::CACHE_TTL );
			}

			return $status;
		}

		/**
		 * Forgets the kept answer.
		 *
		 * @since 2.1.9
		 */
		public static function forget(): void {
			delete_transient( self::TRANSIENT );
		}

		/**
		 * The merchant-facing text of a problem status.
		 *
		 * @since 2.1.9
		 *
		 * @param string $status A STATUS_* constant.
		 */
		public static function get_message( string $status ): string {

			$details = [
				self::STATUS_REDIRECT => __( 'Запрос без входа в аккаунт перенаправляется на другую страницу.', 'woodev-plugin-framework' ),
				self::STATUS_HTML     => __( 'Вместо ответа API приходит обычная страница сайта.', 'woodev-plugin-framework' ),
				self::STATUS_DENIED   => __( 'Запрос без входа в аккаунт отклоняется: требуется авторизация.', 'woodev-plugin-framework' ),
				self::STATUS_FOREIGN  => __( 'Адрес отвечает не нашим плагином.', 'woodev-plugin-framework' ),
			];

			return trim(
				__( 'REST API закрыт для покупателей: поиск города и пункты выдачи при оформлении заказа не работают.', 'woodev-plugin-framework' )
				. ' ' . ( $details[ $status ] ?? '' )
				. ' ' . __( 'Откройте для всех адреса, которые начинаются с /wp-json/woodev/v1/ — в настройках плагина безопасности или кеширования, либо в коде темы.', 'woodev-plugin-framework' )
			);
		}

		/**
		 * Whether this admin request is a page where the answer is shown (and may be asked for).
		 */
		private static function is_settings_page(): bool {

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			// the Woodev settings page, or the WooCommerce «Integration» tab a carrier still lives on
			return 'woodev-settings' === $page || ( 'wc-settings' === $page && 'integration' === $tab );
		}

		/**
		 * The «check again» URL.
		 */
		private static function get_recheck_url(): string {
			return wp_nonce_url( add_query_arg( self::RECHECK_ARG, '1' ), self::RECHECK_ARG );
		}

		/**
		 * Drops the kept answer on the «check again» link, then returns to the page without the argument.
		 *
		 * @internal
		 *
		 * @since 2.1.9
		 */
		public static function handle_recheck(): void {

			if ( empty( $_GET[ self::RECHECK_ARG ] ) || ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			check_admin_referer( self::RECHECK_ARG );

			self::forget();

			wp_safe_redirect( remove_query_arg( [ self::RECHECK_ARG, '_wpnonce' ] ) );
			exit;
		}

		/**
		 * Prints the warning on the plugin settings pages.
		 *
		 * @internal
		 *
		 * @since 2.1.9
		 */
		public static function render_notice(): void {

			if ( ! self::is_settings_page() || ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$status = self::get_status();

			if ( ! self::is_problem( $status ) ) {
				return;
			}

			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( self::get_message( $status ) ),
				esc_url( self::get_recheck_url() ),
				esc_html__( 'Проверить снова', 'woodev-plugin-framework' )
			);
		}

		/**
		 * Adds the Site Health test.
		 *
		 * @internal
		 *
		 * @since 2.1.9
		 *
		 * @param array<string,mixed> $tests The tests.
		 * @return array<string,mixed>
		 */
		public static function add_site_health_test( $tests ) {

			$tests['direct']['woodev_rest_guest_access'] = [
				'label' => __( 'REST API открыт для покупателей', 'woodev-plugin-framework' ),
				'test'  => [ __CLASS__, 'run_site_health_test' ],
			];

			return $tests;
		}

		/**
		 * The Site Health test itself.
		 *
		 * @internal
		 *
		 * @since 2.1.9
		 *
		 * @return array<string,mixed>
		 */
		public static function run_site_health_test(): array {

			$status = self::get_status();
			$result = [
				'label'       => __( 'REST API открыт для покупателей', 'woodev-plugin-framework' ),
				'status'      => 'good',
				'badge'       => [
					'label' => __( 'Доставка', 'woodev-plugin-framework' ),
					'color' => 'blue',
				],
				'description' => '<p>' . esc_html__( 'Поиск города и пункты выдачи при оформлении заказа доступны покупателям.', 'woodev-plugin-framework' ) . '</p>',
				'actions'     => '',
				'test'        => 'woodev_rest_guest_access',
			];

			if ( self::is_problem( $status ) ) {
				$result['status']      = 'critical';
				$result['label']       = __( 'REST API закрыт для покупателей', 'woodev-plugin-framework' );
				$result['description'] = '<p>' . esc_html( self::get_message( $status ) ) . '</p>';
				$result['actions']     = '<p><a href="' . esc_url( self::get_recheck_url() ) . '">' . esc_html__( 'Проверить снова', 'woodev-plugin-framework' ) . '</a></p>';
			} elseif ( self::STATUS_UNREACHABLE === $status ) {
				$result['status']      = 'recommended';
				$result['label']       = __( 'Не удалось проверить, открыт ли REST API для покупателей', 'woodev-plugin-framework' );
				$result['description'] = '<p>' . esc_html__( 'Сервер не смог отправить запрос сам себе. Проверьте поиск города на странице оформления заказа вручную, не входя в аккаунт.', 'woodev-plugin-framework' ) . '</p>';
			}

			return $result;
		}
	}

endif;
