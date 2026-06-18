<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_API_Extensions' ) ) :

	/**
	 * REST controller for the «Плагины» React page (`woodev/v1` namespace).
	 *
	 * Exposes one read route, `GET /woodev/v1/extensions`, which proxies the
	 * woodev.ru EDD storefront API (categories + products), normalizes each
	 * product to a lean UI shape, and caches the assembled payload in a
	 * transient. Network/secrets stay server-side; the React app makes a single
	 * apiFetch. Registered on core rest_api_init through Woodev_REST_V1_Registrar.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_REST_API_Extensions {

		/**
		 * Storefront category endpoint.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const CATEGORIES_URL = 'https://woodev.ru/edd-api/v2/categories';

		/**
		 * Storefront product endpoint.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const PRODUCTS_URL = 'https://woodev.ru/edd-api/v2/products/';

		/**
		 * Assembled-payload transient key.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const CACHE_KEY = 'woodev_extensions_catalog_v2';

		/**
		 * Whether boot() has already registered the controller (idempotency guard).
		 *
		 * @since 2.0.2
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Registers a single controller instance through the woodev/v1 registrar.
		 *
		 * Idempotent: a second call is a no-op. Called from Woodev_Plugin::add_hooks().
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function boot(): void {

			if ( self::$booted ) {
				return;
			}

			self::$booted = true;

			Woodev_REST_V1_Registrar::register_controller( new self() );
		}

		/**
		 * Registers the catalog route under the woodev/v1 namespace.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {

			register_rest_route(
				Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/extensions',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				)
			);
		}

		/**
		 * Capability gate — matches the «Плагины» admin page (manage_options).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return true|WP_Error True when allowed, a 401/403 WP_Error otherwise.
		 */
		public function check_permissions() {

			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			return new WP_Error(
				'woodev_extensions_forbidden',
				esc_html__( 'Недостаточно прав для просмотра каталога плагинов.', 'woodev-plugin-framework' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		/**
		 * GET handler: returns { categories, products, stale }.
		 *
		 * Serves a cached payload when present; otherwise fetches both endpoints,
		 * normalizes, and caches a successful (non-empty) result. An outage yields
		 * `stale: true` with empty products and is left uncached so it stays retryable.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return WP_REST_Response
		 */
		public function get_items() {

			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return rest_ensure_response( $cached );
			}

			$products = $this->fetch_products();

			$payload = array(
				'categories' => $this->fetch_categories(),
				'products'   => $products,
				'stale'      => ( array() === $products ),
			);

			if ( ! $payload['stale'] ) {
				set_transient( self::CACHE_KEY, $payload, WEEK_IN_SECONDS );
			}

			return rest_ensure_response( $payload );
		}

		/**
		 * Fetches + normalizes storefront categories.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,array<string,string>> List of { slug, label }.
		 */
		private function fetch_categories(): array {

			$body = $this->remote_json( self::CATEGORIES_URL );

			if ( ! $body || ! isset( $body->categories ) || ! is_array( $body->categories ) ) {
				return array();
			}

			$out = array();

			foreach ( $body->categories as $cat ) {
				if ( isset( $cat->slug, $cat->label ) ) {
					$out[] = array(
						'slug'  => (string) $cat->slug,
						'label' => (string) $cat->label,
					);
				}
			}

			return $out;
		}

		/**
		 * Fetches + normalizes storefront products into the lean UI shape.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,array<string,mixed>>
		 */
		private function fetch_products(): array {

			$url  = add_query_arg( array( 'number' => -1 ), self::PRODUCTS_URL );
			$body = $this->remote_json( $url );

			if ( ! $body || ! isset( $body->products ) || ! is_array( $body->products ) ) {
				return array();
			}

			$out = array();

			foreach ( $body->products as $raw ) {
				$product = self::normalize_product( $raw );
				if ( null !== $product ) {
					$out[] = $product;
				}
			}

			return $out;
		}

		/**
		 * GETs a URL and json-decodes the body, or null on any transport/HTTP failure.
		 *
		 * @since 2.0.2
		 *
		 * @param string $url The request URL.
		 *
		 * @return object|null Decoded JSON object, or null.
		 */
		private function remote_json( string $url ) {

			$response = wp_safe_remote_get( $url );

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			return is_object( $body ) ? $body : null;
		}

		/**
		 * Maps a raw EDD product object to the lean UI shape, or null when it has no id.
		 *
		 * PURE: depends only on its input and WP escaping helpers. The permalink is
		 * decorated with the extensions-screen UTM campaign tags (legacy parity).
		 *
		 * @since 2.0.2
		 *
		 * @param object $raw Raw product object from edd-api/v2.
		 *
		 * @return array<string,mixed>|null Lean product, or null when unusable.
		 */
		public static function normalize_product( $raw ) {

			$info = isset( $raw->info ) && is_object( $raw->info ) ? $raw->info : null;

			if ( null === $info || empty( $info->id ) ) {
				return null;
			}

			$price = isset( $raw->pricing->amount ) ? (int) $raw->pricing->amount : 0;

			$thumbnail = $info->thumbnails->medium
				?? $info->thumbnails->small
				?? $info->thumbnail
				?? '';

			$permalink = ! empty( $info->permalink ) ? $info->permalink : ( $info->link ?? '' );

			$slug = (string) ( $info->slug ?? '' );

			$categories = array();
			if ( isset( $info->category ) && is_array( $info->category ) ) {
				foreach ( $info->category as $cat ) {
					if ( isset( $cat->slug ) ) {
						$categories[] = (string) $cat->slug;
					}
				}
			}

			return array(
				'id'         => (int) $info->id,
				'slug'       => $slug,
				'title'      => (string) ( $info->title ?? '' ),
				'excerpt'    => wp_kses_post( (string) ( $info->excerpt ?? '' ) ),
				'thumbnail'  => esc_url_raw( (string) $thumbnail ),
				'permalink'  => esc_url_raw( self::utm_url( (string) $permalink, $slug ) ),
				'price'      => $price,
				'free'       => $price <= 0,
				'categories' => $categories,
			);
		}

		/**
		 * Decorates a storefront URL with the extensions-screen UTM campaign tags.
		 *
		 * @since 2.0.2
		 *
		 * @param string $url     The storefront URL.
		 * @param string $content The utm_content value (product slug).
		 *
		 * @return string The decorated URL, or '' when the input URL is empty.
		 */
		private static function utm_url( string $url, string $content ): string {

			if ( '' === $url ) {
				return '';
			}

			return add_query_arg(
				array(
					'utm_source'   => 'extensionsscreen',
					'utm_medium'   => 'product',
					'utm_campaign' => 'woodevplugin',
					'utm_content'  => $content,
				),
				$url
			);
		}
	}

endif;
