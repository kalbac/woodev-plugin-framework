<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_API_Base' ) ) :

	abstract class Woodev_API_Base {

		/** @var string request method, defaults to POST */
		protected $request_method = 'POST';

		/** @var string URI used for the request */
		protected $request_uri;

		/** @var array request headers */
		protected $request_headers = array();

		/** @var string request user-agent */
		protected $request_user_agent;

		/** @var string request HTTP version, defaults to 1.0 */
		protected $request_http_version = '1.0';

		/** @var float|null request duration in seconds */
		protected $request_duration;

		/** @var Woodev_API_Request|object request */
		protected $request;

		/** @var string|null response code */
		protected $response_code;

		/** @var string|null response message */
		protected $response_message;

		/** @var array|null response headers */
		protected $response_headers;

		/** @var string|null raw response body */
		protected $raw_response_body;

		/** @var string response handler class name */
		protected $response_handler;

		/** @var Woodev_API_Response|object|null response */
		protected $response;

		/**
		 * The fixed placeholder used everywhere a credential value is masked out
		 * of a log — headers and request params (both via
		 * {@see self::mask_secret_values()}, including through
		 * {@see Woodev_Licensing_API_Request} and {@see Woodev_API_JSON_Request},
		 * which reuse it outside this hierarchy) and free text
		 * ({@see self::redact_secret_query_params()}).
		 *
		 * Round 1 and round 2 of #395 masked with `*` repeated to the value's
		 * original length, matching the convention this class already used for
		 * headers. Two independent critic passes flagged the same problem with
		 * that convention: the mask's LENGTH still leaks information about the
		 * secret — enough, for a fixed-format credential (this framework's own
		 * license keys follow a known shape/length), to help fingerprint or
		 * narrow down what kind of secret was logged from the redacted line
		 * alone. A fixed placeholder leaks nothing about the original value at
		 * all, which is the stronger property a log-redaction routine should
		 * have — "consistency with the pre-existing header convention" doesn't
		 * outweigh that once the same finding has been raised twice.
		 *
		 * @since 2.0.2
		 */
		public const SECRET_VALUE_MASK = '[REDACTED]';

		/**
		 * Perform the request and return the parsed response
		 *
		 * @param Woodev_API_Request|object $request class instance which implements Woodev_API_Request
		 * @return Woodev_API_Response|object class instance which implements Woodev_API_Response
		 * @throws Woodev_API_Exception|Woodev_Plugin_Exception may be thrown in implementations
		 */
		protected function perform_request( $request ) {

			$this->reset_response();
			$this->request = $request;
			$start_time    = microtime( true );

			if ( $this->get_plugin()->require_tls_1_2() ) {
				add_action( 'http_api_curl', array( $this, 'set_tls_1_2_request' ), 10, 3 );
			}

			$response = $this->do_remote_request( $this->get_request_uri(), $this->get_request_args() );

			$this->request_duration = round( microtime( true ) - $start_time, 5 );

			try {

				$response = $this->handle_response( $response );

			} catch ( Woodev_Plugin_Exception $e ) {
				$this->broadcast_request();

				throw $e;
			}

			return $response;
		}

		/**
		 * Simple wrapper for wp_remote_request() so child classes can override this
		 * and provide their own transport mechanism if needed, e.g. a custom
		 * cURL implementation
		 *
		 * @param string $request_uri
		 * @param string $request_args
		 * @return array|WP_Error
		 */
		protected function do_remote_request( $request_uri, $request_args ) {
			return wp_safe_remote_request( $request_uri, $request_args );
		}

		/**
		 * Handle and parse the response
		 *
		 * @since 2.0.2 a WP_Error's message is redacted (see {@see self::redact_secret_query_params()})
		 *              before it is used to build the thrown exception — a transport
		 *              override or an `http_api_curl`-style filter is free to embed the
		 *              raw request URI it was given in the error message, and that
		 *              message is what {@see Woodev_Plugin_Updater::get_version_from_remote()}
		 *              (and any other caller) ends up logging — see #395 (Blocking 2).
		 *
		 * @param array|WP_Error $response response data
		 * @throws Woodev_API_Exception network issues, timeouts, API errors, etc
		 * @return Woodev_API_Request|object request class instance that implements Woodev_API_Request
		 */
		protected function handle_response( $response ) {

			if ( is_wp_error( $response ) ) {

				$message = self::redact_secret_query_params( $response->get_error_message(), $this->get_secret_param_names() );

				throw new Woodev_API_Exception( $message, (int) $response->get_error_code() );
			}

			$this->response_code     = wp_remote_retrieve_response_code( $response );
			$this->response_message  = wp_remote_retrieve_response_message( $response );
			$this->raw_response_body = wp_remote_retrieve_body( $response );

			$response_headers = wp_remote_retrieve_headers( $response );

			if ( is_object( $response_headers ) ) {
				$response_headers = $response_headers->getAll();
			}

			$this->response_headers = $response_headers;

			$this->do_pre_parse_response_validation();

			$this->response = $this->get_parsed_response( $this->raw_response_body );

			$this->do_post_parse_response_validation();

			$this->broadcast_request();

			return $this->response;
		}

		/**
		 * Allow child classes to validate a response prior to instantiating the
		 * response object. Useful for checking response codes or messages, e.g.
		 * throw an exception if the response code is not 200.
		 *
		 * A child class implementing this method should simply return true if the response
		 * processing should continue, or throw a Woodev_API_Exception with a
		 * relevant error message & code to stop processing.
		 *
		 * Note: Child classes *must* sanitize the raw response body before throwing
		 * an exception, as it will be included in the broadcast_request() method
		 * which is typically used to log requests.
		 */
		protected function do_pre_parse_response_validation() {}

		/**
		 * Allow child classes to validate a response after it has been parsed
		 * and instantiated. This is useful for check error codes or messages that
		 * exist in the parsed response.
		 *
		 * A child class implementing this method should simply return true if the response
		 * processing should continue, or throw a Woodev_API_Exception with a
		 * relevant error message & code to stop processing.
		 *
		 * Note: Response body sanitization is handled automatically
		 */
		protected function do_post_parse_response_validation() {}

		/**
		 * Return the parsed response object for the request
		 *
		 * @param string $raw_response_body
		 * @return object|Woodev_API_Request response class instance which implements Woodev_API_Request
		 */
		protected function get_parsed_response( $raw_response_body ) {

			$handler_class = $this->get_response_handler();

			return new $handler_class( $raw_response_body );
		}

		/**
		 * Alert other actors that a request has been performed. This is primarily used
		 * for request logging.
		 */
		protected function broadcast_request() {

			$request_data  = $this->get_request_data_for_broadcast();
			$response_data = $this->get_response_data_for_broadcast();

			/**
			 * API Base Request Performed Action.
			 *
			 * Fired when an API request is performed via this base class. Plugins can
			 * hook into this to log request/response data.
			 *
			 * @param array $request_data {
			 *     @type string $method request method, e.g. POST
			 *     @type string $uri request URI
			 *     @type string $user-agent
			 *     @type string $headers request headers
			 *     @type string $body request body
			 *     @type string $duration in seconds
			 * }
			 * @param array $response data {
			 *     @type string $code response HTTP code
			 *     @type string $message response message
			 *     @type string $headers response HTTP headers
			 *     @type string $body response body
			 * }
			 * @param Woodev_API_Base $instance API base instance
			 */

			do_action( 'woodev_' . $this->get_api_id() . '_api_request_performed', $request_data, $response_data, $this );
		}

		protected function reset_response() {
			$this->response_code     = null;
			$this->response_message  = null;
			$this->response_headers  = null;
			$this->raw_response_body = null;
			$this->response          = null;
			$this->request_duration  = null;
		}

		/**
		 * Get the request URI
		 *
		 * @return string
		 */
		protected function get_request_uri() {

			$uri = $this->request_uri . $this->get_request_path();

			return apply_filters( 'woodev_' . $this->get_api_id() . '_api_request_uri', $uri, $this );
		}

		/**
		 * Gets the request path.
		 *
		 * @return string
		 */
		protected function get_request_path() {

			return ( $this->get_request() ) ? $this->get_request()->get_path() : '';
		}


		/**
		 * Gets the sanitized request path, for logging.
		 *
		 * Delegates to the request object's `get_path_safe()` when it implements
		 * one — an opt-in method, not part of the {@see Woodev_API_Request}
		 * interface — for a request class that wants full control over its own
		 * masking. Falls back to the real {@see self::get_request_path()} when it
		 * doesn't. Either way, the result is then run through
		 * {@see self::redact_secret_query_params()} unconditionally: a request
		 * class implementing `get_path_safe()` gets a harmless second pass over
		 * an already-masked value, but a request class that implements NO masking
		 * of its own — the common case, and the one a future or third-party
		 * extension is most likely to be — is never logged with its raw path.
		 * Before this fix, that fallback path was the raw, unmasked one; a
		 * request carrying a credential under an unrecognised param name (e.g.
		 * `token`, `api_key`, `secret`, `instance_id`) still logged it in clear
		 * text by default — see #395 (Blocking 1).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 made fail-safe by default via {@see self::redact_secret_query_params()}
		 *              instead of falling back to the raw path unconditionally.
		 *
		 * @return string
		 */
		protected function get_sanitized_request_path(): string {

			$request = $this->get_request();

			$path = ( $request && is_callable( [ $request, 'get_path_safe' ] ) )
				? $request->get_path_safe()
				: $this->get_request_path();

			return self::redact_secret_query_params( $path, $this->get_secret_param_names() );
		}

		/**
		 * Gets the sanitized request URI, for logging.
		 *
		 * Same as {@see self::get_request_uri()} but built from
		 * {@see self::get_sanitized_request_path()}, and with
		 * {@see self::redact_secret_query_params()} run again on the FINAL
		 * string — AFTER the `woodev_{api_id}_api_request_uri` filter, not
		 * before. A filter attached to that hook can append its own query
		 * param (some third-party transports do, e.g. a signature or replay
		 * token); redacting before the filter ran would let such an addition
		 * reach the log unmasked. Redacting after covers both the path this
		 * class already sanitized AND anything the filter added — see #395
		 * (Blocking 1). The request actually sent to the server always goes
		 * through the unmodified {@see self::get_request_uri()}; only what
		 * gets logged changes here.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 redaction now runs AFTER the request-uri filter, not before.
		 *
		 * @return string
		 */
		protected function get_sanitized_request_uri(): string {

			$uri = $this->request_uri . $this->get_sanitized_request_path();
			$uri = apply_filters( 'woodev_' . $this->get_api_id() . '_api_request_uri', $uri, $this );

			return self::redact_secret_query_params( $uri, $this->get_secret_param_names() );
		}


		/**
		 * Names of query-string / body params — as opposed to headers, see
		 * {@see self::get_secret_header_names()} — that carry a credential and
		 * must be redacted from any request path, URI, exception message, or
		 * body before it reaches a log. This is the list
		 * {@see self::redact_secret_query_params()} applies unconditionally,
		 * regardless of whether the concrete request class implements its own
		 * `get_path_safe()`/`to_string_safe()` masking — the fail-safe backstop
		 * for #395 (Blocking 1 & 2): an unknown or future request class carrying
		 * a credential under one of these common names must never be logged raw
		 * by default.
		 *
		 * A request class with a credential under an uncommon name should still
		 * add its own masking (as {@see Woodev_Licensing_API_Request} does,
		 * defensively, for `license` even though it is already in this default
		 * list) — this list only covers the common cases so a class that forgets
		 * to isn't left fully exposed. Override to extend it, the same pattern as
		 * {@see self::get_secret_header_names()}.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, string>
		 */
		protected function get_secret_param_names(): array {
			return self::get_default_secret_param_names();
		}

		/**
		 * The default secret param name list, shared by
		 * {@see self::get_secret_param_names()} and, statically, by request
		 * classes outside this hierarchy that need the same fail-safe default
		 * for their own body serialization (e.g.
		 * {@see Woodev_API_JSON_Request::get_secret_param_names()}) — one list,
		 * never two that can drift apart.
		 *
		 * Matching against this list ({@see self::mask_secret_values()},
		 * {@see self::redact_secret_query_params()}) is case- AND
		 * separator-insensitive, so `api_key`, `api-key`, `apikey`, and `apiKey`
		 * are all one entry as far as matching is concerned — kept spelled out
		 * three ways below anyway, so the list stays self-documenting about the
		 * real-world spellings it is guarding against, not because matching
		 * needs it. `license_key` is included alongside `license` for the same
		 * reason: it is the literal REST arg name used by
		 * {@see Woodev_REST_API_License} and the `edd_license_key` checkout param
		 * built by {@see Woodev_License_Messages}, i.e. a real spelling in THIS
		 * codebase, not a hypothetical one.
		 *
		 * This list is defence in depth, NOT a guarantee that every credential a
		 * request carries is masked: it can only mask a value that appears next
		 * to one of these names in a `name=value` or `<name>value</name>` shape
		 * (see {@see self::redact_secret_query_params()}) or as a `name => value`
		 * array entry (see {@see self::mask_secret_values()}). A secret carried
		 * under an uncommon name not on this list, or with no name attached at
		 * all (a bare path segment), is not caught by this default — a request
		 * class that knows its own shape should still mask itself explicitly
		 * (as {@see Woodev_Licensing_API_Request} does for `license`) rather than
		 * relying on this fallback alone.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 added `license_key`; documented as defence in depth, not a
		 *              guarantee — #395 Round 3, Blocking 1 & 2.
		 *
		 * @return array<int, string>
		 */
		public static function get_default_secret_param_names(): array {
			return [
				'license',
				'license_key',
				'token',
				'access_token',
				'refresh_token',
				'api_key',
				'api-key',
				'apikey',
				'api_secret',
				'client_secret',
				'secret',
				'password',
				'instance_id',
			];
		}

		/**
		 * Redacts every occurrence of a known secret param name anywhere it
		 * appears in $text — a URI's query string, a request/response body of
		 * ANY format, or free text such as a transport-thrown WP_Error message
		 * that happens to embed the URI it was given (see #395). $text is
		 * scanned for two shapes, independent of whether it happens to be a
		 * well-formed `path?query` string, a form-encoded body, an XML body, or
		 * an arbitrary error message:
		 *
		 * - `name=value` (query string / form body), including the nested-array
		 *   shape `http_build_query()` emits for an array value — both the
		 *   percent-encoded wire form (`name%5Bsub%5D=value`) and the literal
		 *   form (`name[sub]=value`) — matched by the array's own top-level
		 *   name; the bracket suffix is preserved untouched in the output, only
		 *   the value is masked;
		 * - `<name>value</name>` (a single, non-nested XML element) — the
		 *   backstop {@see self::get_sanitized_request_body()} needs now that it
		 *   runs this same routine over whatever an XML (or other non-JSON)
		 *   request class's `to_string_safe()` returns, format be damned — see
		 *   #395 Round 3, Blocking 2. {@see Woodev_API_XML_Request::to_string_safe()}
		 *   is a concrete example already in this codebase of a `to_string_safe()`
		 *   that applies NO masking of its own.
		 *
		 * A candidate name is matched against $secret_names case- and
		 * separator-insensitively (`api_key`, `api-key`, `apikey`, and `apiKey`
		 * all canonicalize to the same comparison key — #395 Round 3, Blocking 1),
		 * never by substring: the FULL candidate name must canonicalize to a
		 * canonicalized entry in $secret_names, so a value that happens to
		 * CONTAIN a secret name (e.g. a param literally called `mylicense`, or a
		 * license key value containing the word "license") is never mistaken for
		 * the param itself.
		 *
		 * Uses {@see self::SECRET_VALUE_MASK} — the same fixed placeholder
		 * {@see self::mask_secret_values()} uses — so a log reader sees one
		 * consistent mask everywhere; kept as a separate routine rather than
		 * reused through it because this one operates on a flat string, not an
		 * associative array.
		 *
		 * This is defence in depth, NOT a guarantee: it can only mask a value
		 * that carries an explicit name (a `key=` or a `<tag>` wrapper) somewhere
		 * in the text. A secret carried as a bare PATH SEGMENT with no such
		 * wrapper — e.g. a REST-style `/license/{key}/status` URL — has no name
		 * to match against and is NOT caught here; a request class shaped that
		 * way must mask its own {@see Woodev_API_Request} path getter (there is
		 * no generic way to know which segment of an arbitrary path is the
		 * secret one).
		 *
		 * This is the fail-safe backstop for
		 * {@see self::get_sanitized_request_path()} (when a request class
		 * implements no `get_path_safe()` of its own),
		 * {@see self::get_sanitized_request_uri()} (run AFTER the
		 * `woodev_{api_id}_api_request_uri` filter, so a filter that itself
		 * appends a secret-bearing param cannot bypass it),
		 * {@see self::get_sanitized_request_body()} (when a request class's
		 * `to_string_safe()` masks nothing, or masks in a format this routine
		 * doesn't otherwise recognise), and {@see self::handle_response()} (a
		 * transport-thrown WP_Error message).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 also matches a name's nested/percent-encoded/casing
		 *              variants instead of only its exact literal spelling, also
		 *              masks a `<name>value</name>` XML element, and masks with
		 *              {@see self::SECRET_VALUE_MASK} instead of a
		 *              length-revealing run of `*` — #395 Round 3 (Blocking 1 & 2,
		 *              and the masking-shape SHOULD-FIX).
		 *
		 * @param string             $text
		 * @param array<int, string> $secret_names
		 * @return string
		 */
		protected static function redact_secret_query_params( string $text, array $secret_names ): string {

			if ( '' === $text || empty( $secret_names ) ) {
				return $text;
			}

			$canonical_secret_names = array_unique( array_map( [ self::class, 'canonicalize_secret_param_name' ], $secret_names ) );

			$text = (string) preg_replace_callback(
				'/([A-Za-z0-9_\-\[\]%]+)=([^&\s]*)/',
				static function ( array $matches ) use ( $canonical_secret_names ): string {

					$base_name = self::get_query_param_base_name( $matches[1] );

					if ( ! in_array( self::canonicalize_secret_param_name( $base_name ), $canonical_secret_names, true ) ) {
						return $matches[0];
					}

					return $matches[1] . '=' . self::SECRET_VALUE_MASK;
				},
				$text
			);

			return (string) preg_replace_callback(
				'/<([A-Za-z0-9_-]+)>([^<]*)<\/\1>/',
				static function ( array $matches ) use ( $canonical_secret_names ): string {

					if ( ! in_array( self::canonicalize_secret_param_name( $matches[1] ), $canonical_secret_names, true ) ) {
						return $matches[0];
					}

					return '<' . $matches[1] . '>' . self::SECRET_VALUE_MASK . '</' . $matches[1] . '>';
				},
				$text
			);
		}

		/**
		 * Canonicalizes a param/header/tag name for secret-name comparison:
		 * lowercased, with every non-alphanumeric separator stripped. Folds
		 * `api_key`, `api-key`, `apikey`, and `apiKey` (or `license_key` and
		 * `licenseKey`) down to the same comparison key, so
		 * {@see self::redact_secret_query_params()} recognises a casing or
		 * separator variant of a listed name without the list needing an entry
		 * for every spelling — #395 Round 3, Blocking 1.
		 *
		 * @since 2.0.2
		 *
		 * @param string $name
		 * @return string
		 */
		private static function canonicalize_secret_param_name( string $name ): string {
			return strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '', $name ) );
		}

		/**
		 * Extracts the top-level param name from a possibly nested/percent-encoded
		 * query key, e.g. `token%5Bprimary%5D` or `token[primary]` both yield
		 * `token` — the name `http_build_query()` derives it from, and the one a
		 * secret-param denylist entry is written against. A key with no bracket
		 * suffix is returned unchanged. Used only by
		 * {@see self::redact_secret_query_params()} — #395 Round 3, Blocking 1.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key Raw query key, as captured from the wire text.
		 * @return string
		 */
		private static function get_query_param_base_name( string $key ): string {

			$decoded_key      = str_ireplace( [ '%5b', '%5d' ], [ '[', ']' ], $key );
			$bracket_position = strpos( $decoded_key, '[' );

			return false === $bracket_position ? $decoded_key : substr( $decoded_key, 0, $bracket_position );
		}

		protected function get_request_args() {

			$args = array(
				'method'      => $this->get_request_method(),
				'timeout'     => MINUTE_IN_SECONDS,
				'redirection' => 0,
				'httpversion' => $this->get_request_http_version(),
				'sslverify'   => apply_filters( 'woodev_sl_api_request_verify_ssl', true, $this ),
				'blocking'    => true,
				'user-agent'  => $this->get_request_user_agent(),
				'headers'     => $this->get_request_headers(),
				'body'        => $this->get_request_body(),
				'cookies'     => array(),
			);

			return apply_filters( 'woodev_' . $this->get_api_id() . '_http_request_args', $args, $this );
		}

		protected function get_request_method() {
			return $this->get_request() && $this->get_request()->get_method() ? $this->get_request()->get_method() : $this->request_method;
		}

		/**
		 * Gets the request body.
		 *
		 * @return string
		 */
		protected function get_request_body() {

			if ( in_array( strtoupper( $this->get_request_method() ), array( 'GET', 'HEAD' ) ) ) {
				return '';
			}

			return ( $this->get_request() && $this->get_request()->to_string() ) ? $this->get_request()->to_string() : '';
		}


		/**
		 * Gets the sanitized request body, for logging.
		 *
		 * Same "harmless second pass" pattern as
		 * {@see self::get_sanitized_request_path()}: whatever the request's
		 * `to_string_safe()` returns is run through
		 * {@see self::redact_secret_query_params()} unconditionally, instead of
		 * being trusted outright. Before this, the base took the interface-required
		 * `to_string_safe()` result on faith — but that method is opaque to the
		 * base (it declares no format, and any concrete class is free to return
		 * whatever it wants), so a request class that implements it naively still
		 * logged a raw credential by default. {@see Woodev_API_XML_Request::to_string_safe()}
		 * is a concrete example already in this codebase: it prettifies the XML
		 * body but masks nothing in it. See #395 Round 3, Blocking 2.
		 *
		 * A `Woodev_API_JSON_Request`/`Woodev_Licensing_API_Request` body that
		 * already masked its own secret params (via {@see self::mask_secret_values()}
		 * before serializing) is unaffected: the second pass only rewrites text
		 * matching a `name=value` or `<name>value</name>` shape, and neither JSON
		 * (`"name":"value"`) nor a `print_r()` dump (`[name] => value`) matches
		 * that shape, so an already-safe body passes through unchanged.
		 *
		 * @since 2.0.2 runs {@see self::redact_secret_query_params()} over whatever
		 *              `to_string_safe()` returns, instead of trusting it outright.
		 *
		 * @return string
		 */
		protected function get_sanitized_request_body() {

			if ( in_array( strtoupper( $this->get_request_method() ), array( 'GET', 'HEAD' ) ) ) {
				return '';
			}

			$body = ( $this->get_request() && $this->get_request()->to_string_safe() ) ? $this->get_request()->to_string_safe() : '';

			return self::redact_secret_query_params( $body, $this->get_secret_param_names() );
		}

		protected function get_request_http_version() {
			return $this->request_http_version;
		}

		protected function get_request_headers() {
			return $this->request_headers;
		}

		/**
		 * Gets the sanitized request headers, for logging.
		 *
		 * Masks the VALUE of every header whose name (matched case-insensitively —
		 * HTTP header names are case-insensitive) appears in
		 * {@see self::get_secret_header_names()}, using
		 * {@see self::mask_secret_values()}'s fixed placeholder. The original key
		 * casing sent on the wire is preserved in the returned array; only the
		 * comparison is case-insensitive.
		 *
		 * {@see self::get_request_headers()} is not return-typed, so a subclass
		 * override is free to return something other than an array (e.g. `null`);
		 * that is passed through unchanged rather than handed to
		 * {@see self::mask_secret_values()}, mirroring the guard
		 * {@see self::get_sanitized_response_headers()} already applies for the same
		 * reason — a logging call must never fatal a request.
		 *
		 * @since 2.0.2 masks every header from {@see self::get_secret_header_names()},
		 *              not only `Authorization`; guards against a non-array
		 *              {@see self::get_request_headers()} override instead of letting
		 *              {@see self::mask_secret_values()}'s `array` type hint fatal.
		 *
		 * @return array<string, string>|null
		 */
		protected function get_sanitized_request_headers() {

			$headers = $this->get_request_headers();

			return is_array( $headers )
				? self::mask_secret_values( $headers, $this->get_secret_header_names() )
				: $headers;
		}

		/**
		 * Masks the VALUE of every entry whose name matches $secret_names, using
		 * {@see self::SECRET_VALUE_MASK}. The match is decided by
		 * {@see self::canonicalize_secret_param_name()} on both sides — lowercased,
		 * separators stripped — so it is both case-insensitive (HTTP header names
		 * are case-insensitive, and this is also used for request/query params —
		 * see below) AND tolerant of a `snake_case`/`kebab-case`/`camelCase`
		 * spelling difference (`api_key`, `api-key`, `apikey`, `apiKey` all match
		 * the same entry — #395 Round 3, Blocking 1). The original key casing is
		 * preserved in the returned array; only the comparison is normalized.
		 *
		 * A value can itself be an array: WordPress's HTTP transport
		 * (`WP_HTTP_Requests_Response::get_headers()`) folds a duplicated response
		 * header — e.g. multiple `Set-Cookie` lines, the normal shape of a
		 * session-establishing response — into an array of values rather than a
		 * string. Each element is masked individually so the array shape survives;
		 * casting the whole array with `(string)` would emit an
		 * `Array to string conversion` warning and collapse it into the literal
		 * string `"Array"`.
		 *
		 * Shared by {@see self::get_sanitized_request_headers()} and
		 * {@see self::get_sanitized_response_headers()} so both directions of the
		 * `woodev_{api_id}_api_request_performed` broadcast mask header names
		 * identically — one masking routine, never two that can drift apart.
		 * Also reused by {@see Woodev_Licensing_API_Request} and
		 * {@see Woodev_API_JSON_Request} to mask secret-carrying request params
		 * out of the logged query string and request body — see #395. Named
		 * generically (renamed from `mask_secret_headers()`, which had grown
		 * misleading once params joined headers as callers) rather than after
		 * either caller, since the routine itself is fully generic over any
		 * associative array. Marked `public` rather than `protected` so a request
		 * class outside this hierarchy can reuse it instead of re-implementing the
		 * masking convention.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 masks array-valued headers element-wise instead of casting the
		 *              whole array to a string.
		 * @since 2.0.2 widened from `protected` to `public` so request classes that
		 *              don't extend this hierarchy (e.g. {@see Woodev_Licensing_API_Request})
		 *              can reuse it for masking secret-carrying request params.
		 * @since 2.0.2 renamed from `mask_secret_headers()` to `mask_secret_values()`
		 *              — the name no longer matched what it masked once request
		 *              params joined headers as callers.
		 * @since 2.0.2 name matching is now separator-insensitive too (not only
		 *              case-insensitive), and masks with {@see self::SECRET_VALUE_MASK}
		 *              instead of a length-revealing run of `*` — #395 Round 3.
		 *
		 * @param array<string, mixed> $headers Name/value pairs (value: string, or array<int, string>
		 *                              for a duplicated header), casing as sent/received.
		 * @param array<int, string>   $secret_names Names to mask, matched case- and separator-insensitively.
		 * @return array<string, mixed>
		 */
		public static function mask_secret_values( array $headers, array $secret_names ): array {

			$canonical_secret_names = array_unique( array_map( [ self::class, 'canonicalize_secret_param_name' ], $secret_names ) );

			/*
			 * Membership is decided by header NAME alone, never by the value being
			 * truthy: `empty()` is true for the string `'0'`, so a credential whose
			 * value happens to be `0` would have reached the logger in clear text.
			 * Masking a genuinely empty value costs nothing — it still logs as
			 * SECRET_VALUE_MASK rather than being skipped and logged as `''`.
			 */
			foreach ( $headers as $name => $value ) {

				if ( ! in_array( self::canonicalize_secret_param_name( (string) $name ), $canonical_secret_names, true ) ) {
					continue;
				}

				$headers[ $name ] = is_array( $value )
					? array_map( static fn( $item ) => self::SECRET_VALUE_MASK, $value )
					: self::SECRET_VALUE_MASK;
			}

			return $headers;
		}

		/**
		 * Names of the request or response headers whose values carry a credential
		 * and must be masked before {@see self::get_sanitized_request_headers()} or
		 * {@see self::get_sanitized_response_headers()} hand the request/response off
		 * to `woodev_{api_id}_api_request_performed` — the documented action any
		 * attached request logger listens on.
		 *
		 * This is the extension point for an API client that authenticates with a
		 * header other than `Authorization`, or whose responses carry a credential
		 * under a vendor-specific name (e.g. a refreshed-token header): override this
		 * method in the subclass and return
		 * `array_merge( parent::get_secret_header_names(), [ 'X-My-Header' ] )`
		 * rather than overriding the sanitizers themselves — one extra name here is
		 * masked exactly like every other credential header, in both directions, with
		 * no risk of drifting from the shared masking logic.
		 *
		 * The default list covers `Authorization` (never regress this — the
		 * payment-gateway tree shares this base class and its production request
		 * logs depend on it staying masked) plus the header names real third-party
		 * APIs are commonly seen using for a second credential; `Cookie` and
		 * `Set-Cookie`/`Set-Cookie2` (the request and response sides of the same
		 * session token); a handful of vendor token-bearing names seen in the wild
		 * (`X-Subject-Token` — OpenStack Identity, `Refresh-Token`/`X-Refresh-Token`,
		 * `X-Amz-Security-Token`, `Authentication-Info`, `X-CSRF-Token`).
		 *
		 * `Location` is deliberately NOT in this list: it only carries a credential
		 * under an OAuth2 implicit-grant redirect, a flow this framework does not
		 * implement, and masking it on every gateway/shipping redirect would destroy
		 * ordinary debugging of where a request was sent.
		 *
		 * @since 2.0.2 renamed from `get_secret_request_header_names()` and now also
		 *              consulted by {@see self::get_sanitized_response_headers()};
		 *              added `Set-Cookie` to the default list.
		 * @since 2.0.2 added `Cookie`, `Set-Cookie2`, `X-Subject-Token`,
		 *              `Refresh-Token`, `X-Refresh-Token`, `X-Amz-Security-Token`,
		 *              `Authentication-Info`, `X-CSRF-Token`.
		 *
		 * @return array<int, string>
		 */
		protected function get_secret_header_names(): array {
			return [
				'Authorization',
				'Proxy-Authorization',
				'Api-Key',
				'X-Api-Key',
				'X-Api-Secret',
				'X-Auth-Token',
				'X-Access-Token',
				'X-Secret',
				'Cookie',
				'Set-Cookie',
				'Set-Cookie2',
				'X-Subject-Token',
				'Refresh-Token',
				'X-Refresh-Token',
				'X-Amz-Security-Token',
				'Authentication-Info',
				'X-CSRF-Token',
			];
		}

		protected function get_request_user_agent() {

			$plugin_name    = $this->get_plugin()->get_plugin_name();
			$plugin_version = $this->get_plugin()->get_version();
			$wc_version     = Woodev_Helper::get_wc_version();
			$wp_version     = $GLOBALS['wp_version'];

			if ( ! is_null( $wc_version ) ) {
				return sprintf( '%s/%s (WooCommerce/%s; WordPress/%s)', str_replace( ' ', '-', $plugin_name ), $plugin_version, $wc_version, $wp_version );
			}

			return sprintf( '%s/%s (WordPress/%s)', str_replace( ' ', '-', $plugin_name ), $plugin_version, $wp_version );
		}

		protected function get_request_duration() {
			return $this->request_duration;
		}

		/**
		 * Gets the request data for broadcasting the request.
		 * Overriding this method allows child classes to customize the request data when broadcasting the request.
		 *
		 * @since 2.0.2 `uri` is now {@see self::get_sanitized_request_uri()}
		 *              instead of the raw {@see self::get_request_uri()} — see #395.
		 *
		 * @return array
		 */
		protected function get_request_data_for_broadcast() {
			return array(
				'method'     => $this->get_request_method(),
				'uri'        => $this->get_sanitized_request_uri(),
				'user-agent' => $this->get_request_user_agent(),
				'headers'    => $this->get_sanitized_request_headers(),
				'body'       => $this->get_sanitized_request_body(),
				'duration'   => $this->get_request_duration() . 's', // seconds
			);
		}

		protected function get_response_handler() {
			return $this->response_handler;
		}

		protected function get_response_code() {
			return $this->response_code;
		}

		protected function get_response_message() {
			return $this->response_message;
		}

		protected function get_response_headers() {
			return $this->response_headers;
		}

		/**
		 * Gets the sanitized response headers, for logging.
		 *
		 * Masks the VALUE of every header whose name (matched case-insensitively)
		 * appears in {@see self::get_secret_header_names()} — the same list, and the
		 * same masking convention, used for the outgoing request headers by
		 * {@see self::get_sanitized_request_headers()}. Response headers are the
		 * other half of the same log broadcast and can carry a credential the
		 * request never had, e.g. `Set-Cookie` or a refreshed-token header a carrier
		 * returns on a token-refresh call.
		 *
		 * {@see self::get_response_headers()} returns `null` before a response has
		 * been received (e.g. when the transport itself failed); that is passed
		 * through unchanged rather than coerced to an array, so the broadcast payload
		 * shape is unaffected by this method's addition.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 declared `?array` — expressible on the platform's PHP 7.4
		 *              floor and matches the documented `array<string, string>|null`.
		 *
		 * @return array<string, string>|null
		 */
		protected function get_sanitized_response_headers(): ?array {

			$headers = $this->get_response_headers();

			return is_array( $headers )
				? self::mask_secret_values( $headers, $this->get_secret_header_names() )
				: $headers;
		}

		protected function get_raw_response_body() {
			return $this->raw_response_body;
		}

		protected function get_sanitized_response_body() {
			return is_callable( array( $this->get_response(), 'to_string_safe' ) ) ? $this->get_response()->to_string_safe() : null;
		}

		/**
		 * Gets the response data for broadcasting the request.
		 * Overriding this method allows child classes to customize the response data when broadcasting the request.
		 *
		 * @since 2.0.2 `headers` is now {@see self::get_sanitized_response_headers()}
		 *              instead of the raw {@see self::get_response_headers()} — see #300.
		 *              The `body` policy is unchanged.
		 *
		 * @return array
		 */
		protected function get_response_data_for_broadcast() {
			return array(
				'code'    => $this->get_response_code(),
				'message' => $this->get_response_message(),
				'headers' => $this->get_sanitized_response_headers(),
				'body'    => $this->get_sanitized_response_body() ? $this->get_sanitized_response_body() : $this->get_raw_response_body(),
			);
		}

		public function get_request() {
			return $this->request;
		}

		public function get_response() {
			return $this->response;
		}

		protected function get_api_id() {

			return $this->get_plugin()->get_id();
		}

		abstract protected function get_new_request( $args = array() );

		abstract protected function get_plugin();

		protected function set_request_header( $name, $value ) {
			$this->request_headers[ $name ] = $value;
		}

		protected function set_request_headers( array $headers ) {

			foreach ( $headers as $name => $value ) {

				$this->request_headers[ $name ] = $value;
			}
		}

		protected function set_http_basic_auth( $username, $password ) {
			$this->request_headers['Authorization'] = sprintf( 'Basic %s', base64_encode( "{$username}:{$password}" ) );
		}

		protected function set_request_content_type_header( $content_type ) {
			$this->request_headers['content-type'] = $content_type;
		}

		protected function set_request_accept_header( $type ) {
			$this->request_headers['accept'] = $type;
		}

		protected function set_response_handler( $handler ) {
			$this->response_handler = $handler;
		}

		public function set_tls_1_2_request( $handle, $r, $url ) {

			if ( ! Woodev_Helper::str_starts_with( $url, 'https://' ) ) {
				return;
			}

			curl_setopt( $handle, CURLOPT_SSLVERSION, 6 );
		}

		public function require_tls_1_2() {
			_deprecated_function( __METHOD__, '1.1.6', 'Woodev_Plugin::require_tls_1_2()' );
			return $this->get_plugin()->require_tls_1_2();
		}

		public function is_tls_1_2_available() {
			return (bool) apply_filters( 'woodev_' . $this->get_plugin()->get_id() . '_api_is_tls_1_2_available', $this->get_plugin()->is_tls_1_2_available(), $this );
		}
	}

endif;
