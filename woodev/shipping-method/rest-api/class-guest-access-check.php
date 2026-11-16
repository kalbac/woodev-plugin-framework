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
	 * Health does. The probe sends every argument the route requires, so WordPress runs the route's
	 * `permission_callback` (a restriction placed there is only visible to such a request) and reaches OUR
	 * controller. The query is deliberately one character, too short to search on: the controller answers
	 * it itself with `woodev_location_invalid_query`, which proves the request got through, calls no
	 * provider and costs one tick of the route's own rate limit.
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

		/**
		 * The host answered the server's own request with an HTTP-level refusal (a Basic-auth challenge, or a
		 * firewall's 403 page) — which says nothing about what a buyer gets, so nothing is blamed.
		 */
		public const STATUS_UNVERIFIABLE = 'unverifiable';

		/** The kept answer. */
		public const TRANSIENT = 'woodev_rest_guest_access';

		/** The query argument of the «check again» link, and its nonce action. */
		public const RECHECK_ARG = 'woodev_recheck_rest_access';

		/** How long an answer is kept. */
		private const CACHE_TTL = DAY_IN_SECONDS;

		/** The route probed, under the REST root. */
		private const ROUTE = 'woodev/v1/location/suggest';

		/** One character: shorter than the route's minimum, so the controller refuses it before any provider is asked. */
		private const PROBE_QUERY = 'x';

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
		 * A 401/403 only counts as «closed for buyers» when WordPress itself said it (a JSON error body, e.g.
		 * `rest_not_logged_in`). A web-server Basic-auth challenge (`WWW-Authenticate`) or a 403 page that is not
		 * JSON is the host refusing its own server's request — the buyers may well be let in — so it is neutral.
		 *
		 * @since 2.1.9
		 *
		 * @param int    $code       HTTP status.
		 * @param string $body       Response body.
		 * @param bool   $challenged Whether the response carries a `WWW-Authenticate` header.
		 * @return string One of the STATUS_* constants (never STATUS_UNREACHABLE: that is a failed request, not an answer).
		 */
		public static function classify( int $code, string $body, bool $challenged = false ): string {

			if ( $code >= 300 && $code < 400 ) {
				return self::STATUS_REDIRECT;
			}

			$data = json_decode( $body, true );

			if ( 401 === $code || 403 === $code ) {
				return ! $challenged && is_array( $data ) ? self::STATUS_DENIED : self::STATUS_UNVERIFIABLE;
			}

			if ( ! is_array( $data ) ) {
				return self::STATUS_HTML;
			}

			return self::is_our_answer( $code, $data ) ? self::STATUS_OK : self::STATUS_FOREIGN;
		}

		/**
		 * Whether a decoded JSON body is what OUR controller says to the probe: the «query too short» error it
		 * returns itself, or its own rate-limit error (the controller was reached either way).
		 *
		 * @param int                 $code HTTP status.
		 * @param array<string,mixed> $data Decoded body.
		 */
		private static function is_our_answer( int $code, array $data ): bool {

			$error = (string) ( $data['code'] ?? '' );

			return ( 400 === $code && 'woodev_location_invalid_query' === $error )
				|| ( 429 === $code && 'woodev_location_rate_limited' === $error );
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

			$url = add_query_arg(
				[
					'q'       => self::PROBE_QUERY,
					'level'   => 'settlement',
					'country' => 'RU',
				],
				rest_url( self::ROUTE )
			);

			$response = wp_remote_get(
				$url,
				[
					'timeout'     => 5,
					'redirection' => 0,
					'cookies'     => [],
					'headers'     => [ 'Accept' => 'application/json' ],
					// WordPress core's own contract for loopback requests: the URL is the second argument, the result
					// (a bool, or the path of a certificate file) goes to the request as it is.
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false, $url ),
				]
			);

			if ( is_wp_error( $response ) ) {
				return self::STATUS_UNREACHABLE;
			}

			return self::classify(
				(int) wp_remote_retrieve_response_code( $response ),
				(string) wp_remote_retrieve_body( $response ),
				'' !== (string) wp_remote_retrieve_header( $response, 'www-authenticate' )
			);
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
				. ' ' . sprintf(
					/* translators: %s: the address the site really uses for its REST API, e.g. https://shop.example/wp-json/woodev/v1/ */
					__( 'Откройте для всех адреса, которые начинаются с %s — в настройках плагина безопасности или кеширования, либо в коде темы.', 'woodev-plugin-framework' ),
					rest_url( 'woodev/v1/' )
				)
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
			} elseif ( self::STATUS_UNREACHABLE === $status || self::STATUS_UNVERIFIABLE === $status ) {
				$result['status']      = 'recommended';
				$result['label']       = __( 'Не удалось проверить, открыт ли REST API для покупателей', 'woodev-plugin-framework' );
				$result['description'] = '<p>' . esc_html__( 'Сервер не смог проверить это сам: запрос сайта к самому себе не прошёл (сетевая ошибка, защита доступа или файрвол хостинга). Это не значит, что покупатели не могут искать город. Проверьте поиск на странице оформления заказа вручную, не входя в аккаунт.', 'woodev-plugin-framework' ) . '</p>';
			}

			return $result;
		}
	}

endif;
