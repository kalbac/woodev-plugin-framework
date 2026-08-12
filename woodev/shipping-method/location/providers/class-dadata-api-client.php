<?php
/**
 * Woodev DaData API Client
 *
 * Server-side HTTP client for the DaData suggestions/cleaning APIs (Task 7; spec
 * D3, D4). Lives on the framework's own API layer ({@see \Woodev_API_Base},
 * {@see \Woodev_API_JSON_Request}, {@see \Woodev_API_JSON_Response}) exactly like
 * {@see \Woodev_Licensing_API} does — the one other concrete, in-repo (not
 * vendored-plugin-reference) implementation of that layer.
 *
 * **Reference basis (D4: tokens never reach the client).** The operator's own
 * production DaData integrations (`woocommerce-edostavka`, `woocommerce-yandex-delivery`,
 * `woodev-russian-post`, all under `plugins-reference/`) call `suggest/address`
 * DIRECTLY from the browser, with the token `wp_localize_script()`'d into every
 * page — see `plugins-reference/woocommerce-edostavka/assets/js/frontend/suggestions-plugin.js`
 * and `class-wc-edostavka-checkout.php`. That is the opposite of D4 and is
 * deliberately NOT replicated here: every DaData call this class makes happens
 * server-side, with the token/secret attached only to the outgoing request headers,
 * never serialized into any response this framework hands back to the browser.
 * `plugins-reference/woocommerce-edostavka/api/class-wc-edostavka-dadata-api.php`
 * DOES proxy two calls server-side already (`iplocate/address`, `findById/delivery`)
 * — that class is the shape this one follows (host selection via a `get_new_request()`
 * request-type switch, `Woodev_Cacheable_API_Base`'s non-cacheable sibling
 * `Woodev_API_Base` since suggest queries are live/uncached, `Authorization: Token`
 * + optional `X-Secret` headers) — but it has NO `suggest/address` or
 * `clean/address` proxy of its own; those two are designed fresh here, using field
 * names/bounds confirmed against DaData's own docs (dadata.ru/api/suggest/address/,
 * .../detect_address_by_ip/, .../clean/address/, .../find-address/ — fetched
 * 12.08.2026) rather than invented.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Providers\\Dadata_Api_Client' ) ) :

	/**
	 * Server-side DaData API client.
	 *
	 * Framework-owned, not plugin-owned (D3: the bundled provider is not any one
	 * plugin's dependency) — this client is constructed with no natural single
	 * `Woodev_Plugin` owner (the bundled {@see Dadata_Provider} itself is built
	 * with ZERO constructor arguments by
	 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::collect()}).
	 * `Woodev_API_Base::get_plugin()` is nonetheless abstract and its return value
	 * IS reached from `perform_request()` (`$this->get_plugin()->require_tls_1_2()`
	 * runs unconditionally, before the request-type check inside it is even
	 * evaluated). Rather than reaching for a fleet-wide "pick any active plugin"
	 * resolution (the one existing precedent,
	 * `Woodev_Plugin_Bootstrap::instance()->get_active_plugin_instances()`, used in
	 * `woodev/admin/class-admin-pages.php` for an analogous "framework code needs
	 * A representative plugin" case — which would tie this framework-owned client's
	 * behavior to whichever plugin happens to be first in an unrelated fleet, and
	 * would need Bootstrap mocking in every test), this class satisfies the narrow
	 * subset of the `Woodev_Plugin` surface `Woodev_API_Base` actually calls by
	 * returning itself: {@see self::get_api_id()} and
	 * {@see self::get_request_user_agent()} are both overridden below to avoid
	 * `get_plugin()` entirely, so the ONLY method a real caller ever reaches through
	 * `get_plugin()` is {@see self::require_tls_1_2()} — implemented here directly,
	 * matching `Woodev_Plugin`'s own unconditional default (`return false;`).
	 *
	 * @since 2.0.2
	 */
	class Dadata_Api_Client extends \Woodev_API_Base {

		/**
		 * Suggestions API base URL (suggest/address, iplocate/address,
		 * findById/address) — confirmed against dadata.ru/api/suggest/address/,
		 * .../detect_address_by_ip/, .../find-address/ (12.08.2026), and matches
		 * `plugins-reference/woocommerce-edostavka/api/class-wc-edostavka-dadata-api.php`'s
		 * own `'suggestions'` case byte-for-byte.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const SUGGESTIONS_BASE_URL = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs';

		/**
		 * Address cleaning ("Clean") API base URL — a DIFFERENT host, requires
		 * BOTH the token and the secret, and is a paid tier (spec Task 7). Matches
		 * the reference client's own (never-called) `'cleaner'` case and
		 * dadata.ru/api/clean/address/ (12.08.2026).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const CLEANER_BASE_URL = 'https://cleaner.dadata.ru/api/v1/clean';

		/**
		 * The framework-owned API id used for the `woodev_{id}_api_request_performed`
		 * logging action and the `woodev_{id}_http_request_args` /
		 * `woodev_{id}_api_request_uri` filters — stable regardless of which plugin
		 * (if any) ends up triggering a given request, since this client has no
		 * single plugin owner. See {@see self::get_api_id()}.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const API_ID = 'location_dadata';

		/**
		 * The DaData API token. Read once at construction from the store setting
		 * (D4: server-side only) by {@see Dadata_Provider} — never accepted from,
		 * or exposed to, the client/browser.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $token;

		/**
		 * The DaData "Clean" API secret, or `''` when not configured. Required,
		 * alongside the token, only for {@see self::clean_address()}.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $secret;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string $token  DaData API token.
		 * @param string $secret DaData "Clean" API secret, or `''` when not configured.
		 */
		public function __construct( string $token, string $secret = '' ) {
			$this->token  = $token;
			$this->secret = $secret;

			$this->set_request_content_type_header( 'application/json' );
			$this->set_request_accept_header( 'application/json' );
			$this->set_response_handler( Dadata_Api_Response::class );
			$this->set_request_header( 'Authorization', sprintf( 'Token %s', $this->token ) );

			// Matches the reference client's own conditional (class-wc-edostavka-dadata-api.php):
			// the secret header is only ever sent when a secret is actually configured.
			if ( '' !== $this->secret ) {
				$this->set_request_header( 'X-Secret', $this->secret );
			}
		}

		/**
		 * Address/locality suggestion lookup — `POST suggest/address`.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $query Free-text search term.
		 * @param array<string, mixed> $args  Extra body fields (`count`, `from_bound`,
		 *                                    `to_bound`, `locations`, `restrict_value`, …) —
		 *                                    merged verbatim; `Dadata_Provider` builds these
		 *                                    per the D15 level→bounds mapping.
		 *
		 * @return array<int, array<string, mixed>> Zero or more raw suggestion arrays,
		 *                                           each shaped `{ value, unrestricted_value, data }`.
		 *
		 * @throws \Woodev_API_Exception On a network failure or a non-2xx response.
		 */
		public function suggest_address( string $query, array $args = [] ): array {
			$request = $this->get_new_request( 'suggestions' );
			$request->suggest_address( $this->with_language( array_merge( [ 'query' => $query ], $args ) ) );

			/** @var Dadata_Api_Response $response */
			$response = $this->perform_request( $request );

			return $response->get_suggestions();
		}

		/**
		 * Geo-IP lookup — `GET iplocate/address`.
		 *
		 * @since 2.0.2
		 *
		 * @param string $ip IPv4 or IPv6 address.
		 *
		 * @return array<string, mixed>|null The raw `{ value, unrestricted_value, data }`
		 *                                   location object, or null when DaData resolved
		 *                                   nothing for this IP.
		 *
		 * @throws \Woodev_API_Exception On a network failure or a non-2xx response.
		 */
		public function iplocate_address( string $ip ): ?array {
			$request = $this->get_new_request( 'suggestions' );
			$request->iplocate_address( $this->with_language( [ 'ip' => $ip ] ) );

			/** @var Dadata_Api_Response $response */
			$response = $this->perform_request( $request );

			return $response->get_location();
		}

		/**
		 * Adds DaData's `language` body field to a suggestions-API request, unless the
		 * caller already set one.
		 *
		 * DaData answers in Russian by default and transliterates the whole payload when
		 * asked for English — MEASURED against the live API (s70), not assumed:
		 *
		 * | field     | `ru` (and no param)   | `en`                  |
		 * |-----------|-----------------------|-----------------------|
		 * | `value`   | `г Казань`            | `Russia, gorod Kazan` |
		 * | `city`    | `Казань`              | `Kazan`               |
		 * | `region`  | `Татарстан`           | `Tatarstan`           |
		 * | `fias_id` | `93b3df57-…`          | `93b3df57-…` (SAME)   |
		 *
		 * That last row is what makes this safe to switch at any time: identity is carried
		 * by `fias_id`, which does not move, so a locality stored while the customer was
		 * reading Russian still resolves after the site is switched to English — only the
		 * text a human reads changes. Without this, an English-locale checkout renders
		 * Cyrillic suggestions in an otherwise English form.
		 *
		 * Only `ru` and `en` exist; anything else is coerced to `en`, because DaData
		 * rejects unknown values and a failed suggest reads to the customer as a broken
		 * field. The locale itself is passed to the filter so a plugin can decide
		 * differently for a locale this two-way split gets wrong.
		 *
		 * When no locale can be resolved at all — neither `get_user_locale()` nor
		 * `get_locale()` exists, i.e. outside a WordPress runtime — the field is OMITTED
		 * rather than guessed. DaData then applies its own default, which the table above
		 * measured to be identical to `ru`; expressing no opinion is honest, and inventing
		 * one would silently pick a language for a customer nobody has identified.
		 *
		 * Applies to the three SUGGESTIONS endpoints only. The Clean API is a different
		 * host with its own contract and is deliberately left alone here.
		 *
		 * @since 2.1.0
		 *
		 * @param array<string, mixed> $body Request body built by the caller.
		 *
		 * @return array<string, mixed>
		 */
		private function with_language( array $body ): array {
			if ( isset( $body['language'] ) ) {
				return $body;
			}

			$locale = $this->current_locale();

			if ( '' === $locale ) {
				return $body;
			}

			/**
			 * Filters the language DaData is asked to answer in.
			 *
			 * @since 2.1.0
			 *
			 * @param string $language Either `ru` or `en`.
			 * @param string $locale   The WordPress locale the default was derived from.
			 */
			$language = (string) apply_filters(
				'woodev_location_dadata_language',
				0 === strpos( strtolower( $locale ), 'ru' ) ? 'ru' : 'en',
				$locale
			);

			$body['language'] = in_array( $language, [ 'ru', 'en' ], true ) ? $language : 'en';

			return $body;
		}

		/**
		 * The locale to derive DaData's response language from — a SEAM, not a direct
		 * `get_user_locale()` call at the use site.
		 *
		 * Overridable rather than mocked on purpose. Stubbing a WordPress function with
		 * Brain Monkey DEFINES it process-wide, so it stays defined for every test class
		 * that runs afterwards and silently changes the behaviour of code that branches on
		 * `function_exists()` — this repo has already paid for that once (gotcha
		 * `brain-monkey-wc-mock-defines-the-function-globally`, whose remedy is exactly
		 * this shape: a seam like `Checkout_Handler::wc_country_codes()`). Measured here
		 * too: stubbing `get_user_locale()` in this class's own tests turned 25 unrelated
		 * `Dadata_Provider` tests red as soon as the whole directory ran in one process,
		 * while every file passed alone.
		 *
		 * Returns `''` when neither locale function exists (outside a WordPress runtime),
		 * which {@see self::with_language()} reads as "express no opinion".
		 *
		 * @since 2.1.0
		 *
		 * @return string
		 */
		protected function current_locale(): string {
			if ( function_exists( 'get_user_locale' ) ) {
				return (string) get_user_locale();
			}

			if ( function_exists( 'get_locale' ) ) {
				return (string) get_locale();
			}

			return '';
		}

		/**
		 * Lookup by native FIAS id — `POST findById/address`.
		 *
		 * @since 2.0.2
		 *
		 * @param string $fias_id A DaData FIAS id (`data.fias_id` from an earlier suggestion).
		 *
		 * @return array<string, mixed>|null The raw `{ value, unrestricted_value, data }`
		 *                                   object for the first match, or null when
		 *                                   DaData returned no match.
		 *
		 * @throws \Woodev_API_Exception On a network failure or a non-2xx response.
		 */
		public function find_by_id_address( string $fias_id ): ?array {
			$request = $this->get_new_request( 'suggestions' );
			$request->find_by_id_address( $this->with_language( [ 'query' => $fias_id ] ) );

			/** @var Dadata_Api_Response $response */
			$response = $this->perform_request( $request );

			$suggestions = $response->get_suggestions();

			return $suggestions[0] ?? null;
		}

		/**
		 * Free-form address normalization ("Clean") — `POST address` on the
		 * cleaner host. Requires BOTH the token (always sent) and the secret
		 * (sent only when configured — an empty/missing secret makes DaData
		 * reject the request, which surfaces as a thrown {@see \Woodev_API_Exception},
		 * exactly the "degrade, but log" outcome {@see Dadata_Provider::normalize()}
		 * is written to handle).
		 *
		 * The DaData Clean API is a BATCH endpoint: the request body is a JSON
		 * array of query strings (`["addr"]`, per dadata.ru/api/clean/address/,
		 * 12.08.2026), and — per the same batch design — the response is expected
		 * to be an array of result objects, one per input; this was not verified
		 * against a live capture (no credentials available), so
		 * {@see Dadata_Api_Response::get_clean_result()} defensively accepts EITHER
		 * a top-level array or a single bare object.
		 *
		 * @since 2.0.2
		 *
		 * @param string $free_form Free-form address text.
		 *
		 * @return array<string, mixed>|null The raw clean-result object (`result`,
		 *                                   `postal_code`, `qc`, …), or null when
		 *                                   DaData returned nothing usable.
		 *
		 * @throws \Woodev_API_Exception On a network failure or a non-2xx response.
		 */
		public function clean_address( string $free_form ): ?array {
			$request = $this->get_new_request( 'cleaner' );
			$request->clean_address( [ $free_form ] );

			/** @var Dadata_Api_Response $response */
			$response = $this->perform_request( $request );

			return $response->get_clean_result();
		}

		/**
		 * {@inheritDoc}
		 *
		 * Selects the request host by request type — mirrors
		 * `class-wc-edostavka-dadata-api.php`'s own `get_new_request( $request_type )`
		 * switch exactly (its `'suggestions'` and `'cleaner'` cases; its `'core'`
		 * case, `https://dadata.ru/api/v2`, has no call site in this class — nothing
		 * in the Task 7 contract needs the account/balance API).
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $args Request type: `'suggestions'` (default) or `'cleaner'`.
		 *
		 * @return Dadata_Api_Request
		 */
		protected function get_new_request( $args = 'suggestions' ) {
			$request_type = is_string( $args ) && '' !== $args ? $args : 'suggestions';

			$this->request_uri = 'cleaner' === $request_type ? self::CLEANER_BASE_URL : self::SUGGESTIONS_BASE_URL;

			return new Dadata_Api_Request();
		}

		/**
		 * {@inheritDoc}
		 *
		 * Framework-owned identity — see the class docblock for why this client
		 * does not resolve a real {@see \Woodev_Plugin}. `require_tls_1_2()`
		 * matches `Woodev_Plugin`'s own unconditional default; DaData is reached
		 * exclusively over `https://` regardless, so no request this class makes
		 * is ever downgraded by this returning `false`.
		 *
		 * @since 2.0.2
		 *
		 * @return $this
		 */
		protected function get_plugin() {
			return $this;
		}

		/**
		 * Matches {@see \Woodev_Plugin::require_tls_1_2()}'s own default. Called
		 * from {@see \Woodev_API_Base::perform_request()} via {@see self::get_plugin()}
		 * (which returns `$this`).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function require_tls_1_2(): bool {
			return false;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Stable, framework-owned id — see the class docblock. Avoids
		 * `Woodev_API_Base::get_api_id()`'s default (`$this->get_plugin()->get_id()`),
		 * which would tie the logging action name to whichever plugin happened to
		 * be resolved, for a client that has no single plugin owner.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function get_api_id() {
			return self::API_ID;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Self-contained — avoids `Woodev_API_Base::get_request_user_agent()`'s
		 * default (`$this->get_plugin()->get_plugin_name()`/`get_version()`), for
		 * the same "no single plugin owner" reason as {@see self::get_api_id()}.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function get_request_user_agent() {
			$wp_version = $GLOBALS['wp_version'] ?? 'unknown';

			return sprintf( 'Woodev-Location-Dadata/%s (WordPress/%s)', \Woodev_Plugin::VERSION, $wp_version );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Throws on a non-2xx response so every call site
		 * ({@see Dadata_Provider}) can rely on `perform_request()` either
		 * returning a usable {@see Dadata_Api_Response} or throwing — never a
		 * response object silently carrying an error body. A 401/403 gets its own
		 * message (matches the reference client's own "неверно указаны данные
		 * авторизации" 401 special-case) since that specific failure means the
		 * store's token/secret setting is wrong, not a transient network issue.
		 *
		 * @since 2.0.2
		 *
		 * @throws \Woodev_API_Exception
		 */
		protected function do_post_parse_response_validation() {
			$code = (int) $this->get_response_code();

			if ( $code >= 200 && $code < 300 ) {
				return;
			}

			if ( 401 === $code || 403 === $code ) {
				throw new \Woodev_API_Exception(
					__( 'DaData API: неверный токен или секретный ключ.', 'woodev-plugin-framework' ),
					$code
				);
			}

			throw new \Woodev_API_Exception(
				sprintf(
					/* translators: 1: HTTP response code, 2: response message */
					__( 'Ошибка DaData API (%1$d): %2$s', 'woodev-plugin-framework' ),
					$code,
					(string) $this->get_response_message()
				),
				$code
			);
		}
	}

endif;
