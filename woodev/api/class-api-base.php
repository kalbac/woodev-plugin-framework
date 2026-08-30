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
		 * Cookies received while passing an opt-in bot-protection challenge.
		 *
		 * @var array<string, string>
		 */
		private array $challenge_redirect_cookies = [];

		/**
		 * Maximum number of same-origin challenge redirects followed per request.
		 *
		 * A testcookie challenge needs one repeat. Keeping this explicit prevents a
		 * malformed or hostile endpoint from turning a request into an unbounded loop.
		 *
		 * @since 2.0.2
		 */
		private const CHALLENGE_REDIRECT_HOP_LIMIT = 1;

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
		 * The placeholder logged in place of an ENTIRE request/response body
		 * whose format {@see self::redact_secret_request_body()} cannot parse
		 * and walk structurally — #395 Round 5, Blocking.
		 *
		 * A body in an unrecognised shape (XML, a `print_r()` dump, or
		 * anything else that is neither valid JSON nor a genuine query
		 * string) has no reliable way to tell a secret-named field apart from
		 * a safe sibling one: the round-4 regex backstop only matched a
		 * `name=value` or `<name>value</name>` shape, so a `print_r()` dump
		 * (`[name] => value`) — the exact shape
		 * {@see Woodev_Licensing_API_Request::to_string_safe()} produces —
		 * matched neither and passed through with any unmasked secret intact.
		 * Masking the whole body is the fail-SAFE choice once the format
		 * itself cannot be trusted: it is deliberately coarser than the
		 * per-field masking a parseable body gets, and it can hide a
		 * non-secret sibling param too — that loss of debugging convenience
		 * is the acceptable side to err on, not a regression.
		 *
		 * @since 2.0.2
		 */
		public const UNPARSEABLE_BODY_MASK = '[REDACTED: body format could not be parsed for logging]';

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

			$response = $this->do_remote_request_with_challenge_redirects( $this->get_request_uri(), $this->get_request_args() );

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
		 * Performs a request and, when explicitly enabled by a subclass, repeats a
		 * same-origin 302 or 307 challenge redirect without changing its method or body.
		 *
		 * Cookies are collected from every non-error response, not just redirects, so
		 * a provider may rotate a challenge cookie between ordinary API calls. The
		 * `woodev_{api_id}_api_challenge_redirect_cookies` filter can supply a
		 * persisted jar, and `woodev_{api_id}_api_challenge_redirect_cookies_updated`
		 * lets a plugin persist a refreshed jar if it chooses to do so.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $request_uri Request URI.
		 * @param array<string, mixed> $request_args WordPress HTTP request arguments.
		 * @return array|WP_Error Response from the final request.
		 */
		protected function do_remote_request_with_challenge_redirects( string $request_uri, array $request_args ) {

			$response = $this->do_remote_request( $request_uri, $this->add_challenge_redirect_cookies_to_request_args( $request_args ) );

			if ( ! $this->follow_challenge_redirects() ) {
				return $response;
			}

			$this->remember_challenge_redirect_cookies( $response );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			for ( $hop = 0; $hop < self::CHALLENGE_REDIRECT_HOP_LIMIT; $hop++ ) {

				$redirect_uri = $this->get_same_origin_challenge_redirect_uri( $request_uri, $response );

				if ( is_wp_error( $redirect_uri ) || null === $redirect_uri ) {
					return $redirect_uri ?: $response;
				}

				$response = $this->do_remote_request( $redirect_uri, $this->add_challenge_redirect_cookies_to_request_args( $request_args ) );
				$this->remember_challenge_redirect_cookies( $response );
			}

			return $response;
		}

		/**
		 * Whether this API client may pass a bot-protection challenge redirect.
		 *
		 * Disabled by default so existing API clients retain their historical
		 * `redirection => 0` behavior exactly. A client that needs this mechanism
		 * overrides the method and returns true.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		protected function follow_challenge_redirects(): bool {
			return false;
		}

		/**
		 * Returns a safe challenge redirect URI, or a WP_Error for a cross-origin one.
		 *
		 * @since 2.0.2
		 *
		 * @param string         $request_uri Original request URI.
		 * @param array|WP_Error $response Response which may carry Location.
		 * @return string|WP_Error|null
		 */
		private function get_same_origin_challenge_redirect_uri( string $request_uri, $response ) {

			$status_code = (int) wp_remote_retrieve_response_code( $response );

			if ( ! in_array( $status_code, [ 302, 307 ], true ) ) {
				return null;
			}

			$location = $this->get_response_header_value( $response, 'location' );

			if ( null === $location ) {
				return null;
			}

			$redirect_uri = self::resolve_challenge_redirect_uri( $request_uri, $location );

			if ( ! self::is_same_origin_uri( $request_uri, $redirect_uri ) ) {
				return new WP_Error( 'woodev_api_challenge_redirect_cross_origin', 'Refusing to follow a cross-origin bot-protection challenge redirect.' );
			}

			return $redirect_uri;
		}

		/**
		 * Resolves a redirect location against the original request URI.
		 *
		 * @since 2.0.2
		 *
		 * @param string $request_uri Original request URI.
		 * @param string $location Redirect Location header.
		 * @return string
		 */
		private static function resolve_challenge_redirect_uri( string $request_uri, string $location ): string {

			if ( false !== strpos( $location, '://' ) ) {
				return $location;
			}

			$original = parse_url( $request_uri );

			if ( ! is_array( $original ) || empty( $original['scheme'] ) || empty( $original['host'] ) ) {
				return $location;
			}

			$origin = $original['scheme'] . '://' . $original['host'];

			if ( isset( $original['port'] ) ) {
				$origin .= ':' . $original['port'];
			}

			if ( 0 === strpos( $location, '//' ) ) {
				return $original['scheme'] . ':' . $location;
			}

			if ( 0 === strpos( $location, '/' ) ) {
				return $origin . $location;
			}

			$path = isset( $original['path'] ) ? $original['path'] : '/';

			return $origin . substr( $path, 0, strrpos( $path, '/' ) + 1 ) . $location;
		}

		/**
		 * Checks whether two URIs use the same scheme, host, and effective port.
		 *
		 * @since 2.0.2
		 *
		 * @param string $first_uri First URI.
		 * @param string $second_uri Second URI.
		 * @return bool
		 */
		private static function is_same_origin_uri( string $first_uri, string $second_uri ): bool {

			$first  = parse_url( $first_uri );
			$second = parse_url( $second_uri );

			if ( ! is_array( $first ) || ! is_array( $second ) ) {
				return false;
			}

			return isset( $first['scheme'], $first['host'], $second['scheme'], $second['host'] )
				&& strtolower( $first['scheme'] ) === strtolower( $second['scheme'] )
				&& strtolower( $first['host'] ) === strtolower( $second['host'] )
				&& self::get_uri_port( $first ) === self::get_uri_port( $second );
		}

		/**
		 * Gets a URI's explicit or scheme-default port.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $uri Parsed URI.
		 * @return int
		 */
		private static function get_uri_port( array $uri ): int {
			return isset( $uri['port'] ) ? (int) $uri['port'] : ( 'https' === strtolower( $uri['scheme'] ) ? 443 : 80 );
		}

		/**
		 * Gets a response header value case-insensitively.
		 *
		 * @since 2.0.2
		 *
		 * @param array|WP_Error $response Response data.
		 * @param string         $header_name Header name.
		 * @return string|null
		 */
		private function get_response_header_value( $response, string $header_name ): ?string {

			$headers = wp_remote_retrieve_headers( $response );
			$headers = is_object( $headers ) && is_callable( [ $headers, 'getAll' ] ) ? $headers->getAll() : $headers;

			if ( ! is_array( $headers ) ) {
				return null;
			}

			foreach ( $headers as $name => $value ) {
				if ( strtolower( (string) $name ) === strtolower( $header_name ) ) {
					return is_array( $value ) ? (string) reset( $value ) : (string) $value;
				}
			}

			return null;
		}

		/**
		 * Adds the current challenge-cookie jar to request headers.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $request_args Request arguments.
		 * @return array<string, mixed>
		 */
		private function add_challenge_redirect_cookies_to_request_args( array $request_args ): array {

			$cookies = $this->get_challenge_redirect_cookies();

			if ( [] === $cookies || ! isset( $request_args['headers'] ) || ! is_array( $request_args['headers'] ) ) {
				return $request_args;
			}

			$cookie_header_name = 'Cookie';
			$existing_cookies   = [];

			foreach ( $request_args['headers'] as $name => $value ) {
				if ( 'cookie' === strtolower( (string) $name ) ) {
					$cookie_header_name = $name;
					$existing_cookies   = self::parse_challenge_redirect_cookies( (string) $value );
					break;
				}
			}

			$request_args['headers'][ $cookie_header_name ] = self::build_challenge_redirect_cookie_header( array_merge( $existing_cookies, $cookies ) );

			return $request_args;
		}

		/**
		 * Reads and remembers every Set-Cookie value on a response.
		 *
		 * @since 2.0.2
		 *
		 * @param array|WP_Error $response Response data.
		 * @return void
		 */
		private function remember_challenge_redirect_cookies( $response ): void {

			if ( is_wp_error( $response ) ) {
				return;
			}

			$headers = wp_remote_retrieve_headers( $response );
			$headers = is_object( $headers ) && is_callable( [ $headers, 'getAll' ] ) ? $headers->getAll() : $headers;

			if ( ! is_array( $headers ) ) {
				return;
			}

			foreach ( $headers as $name => $value ) {
				if ( 'set-cookie' !== strtolower( (string) $name ) ) {
					continue;
				}

				$set_cookie_values = is_array( $value ) ? $value : [ $value ];

				foreach ( $set_cookie_values as $set_cookie_value ) {
					$this->challenge_redirect_cookies = array_merge(
						$this->challenge_redirect_cookies,
						self::parse_challenge_redirect_set_cookie( (string) $set_cookie_value )
					);
				}
			}

			if ( [] !== $this->challenge_redirect_cookies ) {
				do_action( 'woodev_' . $this->get_api_id() . '_api_challenge_redirect_cookies_updated', $this->challenge_redirect_cookies, $this );
			}
		}

		/**
		 * Gets the challenge-cookie jar, allowing a client to restore persisted cookies.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, string>
		 */
		private function get_challenge_redirect_cookies(): array {

			$cookies = apply_filters( 'woodev_' . $this->get_api_id() . '_api_challenge_redirect_cookies', $this->challenge_redirect_cookies, $this );

			return is_array( $cookies ) ? self::parse_challenge_redirect_cookies( self::build_challenge_redirect_cookie_header( $cookies ) ) : $this->challenge_redirect_cookies;
		}

		/**
		 * Parses a Set-Cookie or Cookie header into name/value pairs.
		 *
		 * @since 2.0.2
		 *
		 * @param string $header Cookie header text.
		 * @return array<string, string>
		 */
		private static function parse_challenge_redirect_cookies( string $header ): array {

			$cookies = [];

			foreach ( preg_split( '/[;\n]/', $header ) as $cookie ) {
				$pair = explode( '=', trim( $cookie ), 2 );

				if ( 2 === count( $pair ) && '' !== $pair[0] ) {
					$cookies[ $pair[0] ] = $pair[1];
				}
			}

			return $cookies;
		}

		/**
		 * Extracts only the name/value pair from one Set-Cookie header value.
		 *
		 * Set-Cookie attributes such as Path and HttpOnly describe storage policy;
		 * forwarding them in a Cookie request header would be invalid.
		 *
		 * @since 2.0.2
		 *
		 * @param string $header Set-Cookie header value.
		 * @return array<string, string>
		 */
		private static function parse_challenge_redirect_set_cookie( string $header ): array {

			$pair = explode( '=', trim( strtok( $header, ';' ) ), 2 );

			return 2 === count( $pair ) && '' !== $pair[0] ? [ $pair[0] => $pair[1] ] : [];
		}

		/**
		 * Builds a Cookie request header from a name/value jar.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, string> $cookies Cookie jar.
		 * @return string
		 */
		private static function build_challenge_redirect_cookie_header( array $cookies ): string {

			$parts = [];

			foreach ( $cookies as $name => $value ) {
				$parts[] = $name . '=' . $value;
			}

			return implode( '; ', $parts );
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
		 * @since 2.0.2 the HTTP reason phrase is redacted through the same routine, AT
		 *              ASSIGNMENT rather than at each place it is logged — see #451.
		 *              The phrase is attacker-influenced free text off the wire, and it
		 *              leaves this class by several unrelated routes: the broadcast
		 *              (`get_response_data_for_broadcast()`'s `message`), the text of a
		 *              thrown {@see Woodev_API_Exception} in the licensing API and in
		 *              the DaData client, and — through any subclass — wherever those
		 *              exceptions are caught. Those boundaries are numerous and, via the
		 *              plugin extension seams, EXTENSIBLE, so redacting per boundary
		 *              cannot be made complete for this field. Assignment is the one
		 *              place a later boundary cannot bypass.
		 *
		 *              Two consequences, deliberately taken (both raised by the #451
		 *              critic pass, neither one hypothetical):
		 *
		 *              1. The phrase DOES have a behavioural reader. `plugins-reference/`
		 *                 ships one: woocommerce-edostavka's DaData client branches on
		 *                 `str_starts_with( strtolower( $message ), 'unauthorized' )` to
		 *                 pick which message the merchant sees. That branch is safe here
		 *                 and provably so, not incidentally: redaction only ever replaces
		 *                 the VALUE after a secret `name=`, or between `<name></name>`,
		 *                 and leaves every other byte — the name included — in place. For
		 *                 the prefix test to flip, the phrase would have to BEGIN with a
		 *                 secret `name=`, and such a phrase does not begin with
		 *                 `unauthorized` either before or after redaction. Pinned by
		 *                 ApiBaseResponseMessageRedactionTest.
		 *              2. The phrase is not log-only: {@see Woodev_REST_API_License}'s
		 *                 `respond()` returns a caught Throwable's message to the admin
		 *                 as a WP_Error, so a phrase like `Policy requires password=8`
		 *                 reaches an administrator as `password=` plus the mask, losing a
		 *                 legitimate detail that merely looks like a credential. That is
		 *                 the same trade this class already accepts for request bodies
		 *                 (#395) and response bodies (#427), and an admin-visible string
		 *                 is if anything MORE likely to be pasted into a support ticket.
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
			$this->response_message  = self::redact_secret_query_params(
				(string) wp_remote_retrieve_response_message( $response ),
				$this->get_secret_param_names()
			);
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
		 * The `woodev_{api_id}_api_request_uri` filter's return is validated: this
		 * is the URI actually sent to the server, so a non-string return degrades
		 * to the pre-filter $uri rather than reaching the live outbound request
		 * malformed.
		 *
		 * @since 2.0.2 the filter return is validated with is_string(); a
		 *              non-string return no longer reaches the request unmodified.
		 *
		 * @return string
		 */
		protected function get_request_uri() {

			$uri = $this->request_uri . $this->get_request_path();

			$filtered_uri = apply_filters( 'woodev_' . $this->get_api_id() . '_api_request_uri', $uri, $this );

			return is_string( $filtered_uri ) ? $filtered_uri : $uri;
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
		 * {@see self::redact_secret_query_string()} unconditionally: a request
		 * class implementing `get_path_safe()` gets a harmless second pass over
		 * an already-masked value, but a request class that implements NO masking
		 * of its own — the common case, and the one a future or third-party
		 * extension is most likely to be — is never logged with its raw path.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 made fail-safe by default via redaction instead of falling
		 *              back to the raw path unconditionally — #395 Blocking 1.
		 * @since 2.0.2 redaction now parses the query string structurally via
		 *              {@see self::redact_secret_query_string()} instead of
		 *              scanning it with a `name=value` regex, so a secret nested
		 *              under a NON-secret param name at any depth (e.g.
		 *              `a[token]=…`) is still caught — #395 Round 4, Blocking.
		 *
		 * @return string
		 */
		protected function get_sanitized_request_path(): string {

			$request = $this->get_request();

			$path = ( $request && is_callable( [ $request, 'get_path_safe' ] ) )
				? $request->get_path_safe()
				: $this->get_request_path();

			return self::redact_secret_query_string( $path, $this->get_secret_param_names() );
		}

		/**
		 * Gets the sanitized request URI, for logging.
		 *
		 * Same as {@see self::get_request_uri()} but built from
		 * {@see self::get_sanitized_request_path()}, and with
		 * {@see self::redact_secret_query_string()} run again on the FINAL
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
		 * The filter's return is validated the same way {@see self::get_request_uri()}
		 * validates it: a non-string return degrades to the pre-filter $uri
		 * rather than being redacted and logged malformed.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 redaction now runs AFTER the request-uri filter, not before.
		 * @since 2.0.2 redaction now parses the query string structurally via
		 *              {@see self::redact_secret_query_string()} — #395 Round 4, Blocking.
		 * @since 2.0.2 the filter return is validated with is_string(); a
		 *              non-string return no longer reaches redaction unmodified.
		 *
		 * @return string
		 */
		protected function get_sanitized_request_uri(): string {

			$uri          = $this->request_uri . $this->get_sanitized_request_path();
			$filtered_uri = apply_filters( 'woodev_' . $this->get_api_id() . '_api_request_uri', $uri, $this );
			$uri          = is_string( $filtered_uri ) ? $filtered_uri : $uri;

			return self::redact_secret_query_string( $uri, $this->get_secret_param_names() );
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
		 * appears in $text, by SCANNING the raw string for two shapes — this is
		 * the regex-based backstop kept for {@see self::handle_response()}'s free
		 * text (a transport-thrown WP_Error message that happens to embed the
		 * URI it was given, see #395). A well-formed `path?query` string is no
		 * longer scanned by this routine — see {@see self::redact_secret_query_string()},
		 * which parses it structurally instead, so it cannot corrupt anything it
		 * rebuilds — #395 Round 4. Nor is a request/response BODY any more — see
		 * {@see self::redact_secret_request_body()}, which either parses it
		 * structurally (JSON, or a body genuinely shaped like a query string) or,
		 * since #395 Round 5, masks the WHOLE body rather than trust this scan to
		 * find every secret in a format it was never built to understand (it
		 * cannot match a `print_r()` dump's `[name] => value` shape at all).
		 *
		 * $text is scanned for two shapes:
		 *
		 * - `name=value` (free text), including the
		 *   nested-array shape `http_build_query()` emits for an array value —
		 *   both the percent-encoded wire form (`name%5Bsub%5D=value`) and the
		 *   literal form (`name[sub]=value`) — matched against EVERY bracket
		 *   segment of the key, not only the first, so a secret nested under a
		 *   NON-secret outer name (`a[token]=…`) is still caught — #395 Round 4,
		 *   Blocking. The key is URL-decoded (`+` included, so `api+key=…`
		 *   canonicalizes the same as `api_key=…`) before any segment is
		 *   compared — #395 Round 4, SHOULD-FIX. The bracket suffix is preserved
		 *   untouched in the output, only the value is masked;
		 * - `<name>value</name>` (a single, non-nested XML element) — kept for
		 *   the same free-text WP_Error message case; an XML-shaped BODY is now
		 *   handled by {@see self::redact_secret_request_body()}'s whole-body
		 *   mask instead, since #395 Round 5.
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
		 * @since 2.0.2
		 * @since 2.0.2 also matches a name's nested/percent-encoded/casing
		 *              variants instead of only its exact literal spelling, also
		 *              masks a `<name>value</name>` XML element, and masks with
		 *              {@see self::SECRET_VALUE_MASK} instead of a
		 *              length-revealing run of `*` — #395 Round 3 (Blocking 1 & 2,
		 *              and the masking-shape SHOULD-FIX).
		 * @since 2.0.2 checks EVERY bracket segment of a key, not only the first,
		 *              and URL-decodes the key (so `+` is treated as a space)
		 *              before canonicalizing; no longer scans a `path?query`
		 *              string (see {@see self::redact_secret_query_string()}) or a
		 *              successfully-decoded JSON body (see
		 *              {@see self::redact_secret_request_body()}) — #395 Round 4
		 *              (Blocking, both SHOULD-FIXes).
		 * @since 2.0.2 no longer used as the BODY fallback at all — a non-JSON
		 *              body is either genuinely query-string-shaped (parsed
		 *              structurally) or masked in full — #395 Round 5, Blocking.
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
				'/([A-Za-z0-9_\-\[\]%+]+)=([^&\s]*)/',
				static function ( array $matches ) use ( $canonical_secret_names ): string {

					foreach ( self::get_query_param_name_segments( $matches[1] ) as $segment ) {

						if ( in_array( self::canonicalize_secret_param_name( $segment ), $canonical_secret_names, true ) ) {
							return $matches[1] . '=' . self::SECRET_VALUE_MASK;
						}
					}

					return $matches[0];
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
		 * Redacts a known secret param name out of arbitrary FREE-FORM log
		 * text — an exception message, a diagnostic context string — for a
		 * caller that has no {@see Woodev_API_Base} instance to reach
		 * {@see self::redact_secret_query_params()} through, and may never
		 * have one at all.
		 *
		 * {@see self::handle_response()} redacts everything this class itself
		 * derives from a response, AT THE POINT it is assigned (#451), but
		 * that only covers text `Woodev_API_Base` builds. A `Point_Source` /
		 * carrier client is a plugin EXTENSION SEAM: it is free to throw any
		 * `\Throwable` of its own, built from a live third-party SDK this
		 * class never sees, and that message can reach a log boundary
		 * without ever passing through this class at all (#585). This is
		 * the seam those boundaries SHOULD route through, instead of each
		 * hand-redacting the same free text independently. As of #594 every
		 * APPLICATION-LOG sink in `woodev/` that writes a caught
		 * exception's message routes through it. Getting to that took
		 * three sweeps, each finding what the previous one's grep could
		 * not see:
		 *
		 * - #585 found four, all spelled `error_log(`.
		 * - #594's card listed eleven more, also `error_log(` — the
		 *   setup-wizard and settings-page REST controllers, the location
		 *   resolution cache, the shipment handler, the licensing/updater
		 *   command transport.
		 * - #594's own re-sweep found three in `woodev/payment-gateway/`
		 *   spelled `$plugin->log(`, invisible to a grep for `error_log`.
		 * - #594's CRITIC found a fourth spelling the re-sweep still
		 *   missed: {@see Woodev_Payment_Gateway::add_debug_message()},
		 *   which reaches the same WooCommerce logger INDIRECTLY. Two
		 *   catches hand it a foreign message. It is redacted AT THE SINK
		 *   rather than at those call sites, so a later caller cannot
		 *   forget.
		 *
		 * Read that list as a warning, not a certificate: each sweep was
		 * keyed on one spelling and each was wrong about being finished.
		 * Grep for the SINK, not for one spelling of it.
		 *
		 * DELIBERATELY OUT OF SCOPE, so their absence is not mistaken for
		 * an oversight:
		 *
		 * - `_doing_it_wrong()` in `woodev/settings-api/` (two sites)
		 *   carries a caught exception's message and WordPress may route
		 *   it to the PHP error log. It is a DEVELOPER-misuse marker: the
		 *   exception is the framework's own validation complaining about
		 *   the plugin author's arguments, and masking it would blunt the
		 *   one message whose whole job is to tell that author what they
		 *   got wrong.
		 * - `WC_Order` notes. Two payment-gateway sites also put the raw
		 *   message into one, and that stays. **Operator decision,
		 *   27.08.2026 (#608), closed as `not planned`:** the provider's
		 *   answer has to reach the merchant, because the less detail an
		 *   admin-side record carries, the more support requests it
		 *   generates. This method over-redacts on purpose, which is right
		 *   for a diagnostic line in a file and wrong for the note a human
		 *   reads to understand a failed payment.
		 * - BROWSER RESPONSES, with ONE exception. Roughly a dozen sites
		 *   hand a caught exception's message straight to
		 *   `wp_send_json_error()` or into a `WP_Error` returned from
		 *   REST, and they keep it. Settled on #610, 27.08.2026, by
		 *   splitting them on WHO READS THEM rather than on how dangerous
		 *   the text is:
		 *   • nine have the shop admin as their reader, so #608's
		 *     reasoning closes them unchanged;
		 *   • {@see Woodev_Script_Handler::ajax_log_event()} is `nopriv`,
		 *     but what it catches is this framework's own validation, and
		 *     stripping the text outright is not available either — the
		 *     response is a public seam a plugin's front end may act on.
		 *     Left alone deliberately;
		 *   • {@see Woodev_Payment_Gateway_My_Payment_Methods::ajax_save_payment_method()}
		 *     is the ONE that does route through this method. Its reader
		 *     is the CUSTOMER on their account page, and the call it wraps
		 *     goes into the gateway's API. The merchant loses nothing:
		 *     #594 already writes the full text to the log.
		 *
		 * Nothing here ENFORCES any of this. A new log boundary that skips
		 * this method fails nothing and says nothing, which is exactly how
		 * the later fourteen survived #585. Ask it of every new `catch`
		 * that logs: can this `\Throwable` have been thrown by somebody
		 * else's code?
		 *
		 * Reuses {@see self::redact_secret_query_params()} — the same
		 * `name=value` / `<name>value</name>` free-text scan
		 * {@see self::handle_response()} already runs over a
		 * transport-thrown `WP_Error` message — so there is exactly ONE
		 * implementation of "scan free text for a secret name" in this
		 * codebase, never a second one duplicated for exception text. Two
		 * consequences worth knowing before reading a redacted line:
		 *
		 * - It is a NAME-based scan, not a shape-based one: `api_key=VALUE`
		 *   and `<api_key>VALUE</api_key>` are caught; `Authorization: Bearer
		 *   VALUE`, a JSON `"api_key":"VALUE"` pair (colon, not `=`), a
		 *   sentence like "the key VALUE was rejected", and a bare token with
		 *   no name attached at all are NOT — there is no name for the scan
		 *   to match against. Pinned both directions by
		 *   ApiBaseLogTextRedactionTest.
		 * - It runs over the WHOLE message, not just an isolated parameter:
		 *   harmless prose that happens to contain `name=value` shape is
		 *   masked too (`the retry token=next is enabled`), and the value
		 *   match is greedy up to the next `&`/whitespace, so trailing
		 *   punctuation attached to the value is consumed with it
		 *   (`api_key=abc,` becomes `api_key=[REDACTED]`, comma included).
		 *   For a diagnostic log line this over-redaction is the right
		 *   trade against under-redaction, but it is a real, visible
		 *   behaviour, not a hypothetical one.
		 *
		 * $extra_secret_names is merged with
		 * {@see self::get_default_secret_param_names()} before redaction, and
		 * the merged list can be extended further through the
		 * {@see 'woodev_api_log_text_secret_param_names'} filter — a plugin
		 * author whose own carrier uses an uncommon credential name can add
		 * it there once, rather than passing $extra_secret_names at every
		 * call site.
		 *
		 * Both $extra_secret_names and the filter's return value are
		 * caller-supplied and are NOT trusted blindly: every member that is
		 * not actually a `string` (an object, an array, `null`, a resource —
		 * a #585 critic round 2 finding: `[ new stdClass() ]` from the
		 * filter reached {@see self::canonicalize_secret_param_name()} and
		 * threw an uncaught `\TypeError`) is dropped rather than passed
		 * through, and if the filter hands back nothing usable at all — the
		 * wrong type, or every member malformed — this falls back to
		 * {@see self::get_default_secret_param_names()} rather than
		 * redacting against an empty list. A misbehaving filter can degrade
		 * this to the default list; it must never be able to fatal a log
		 * call (a call already on an error path) or silently disable
		 * redaction entirely.
		 *
		 * This is DEFENCE IN DEPTH over an OPEN list of boundaries — a new
		 * call site can always be added later and forget to route through
		 * this — and an OPEN set of secret names (see
		 * {@see self::get_default_secret_param_names()}'s own docblock for
		 * the same caveat), NOT a guarantee that every credential a foreign
		 * exception carries is masked.
		 *
		 * @since 2.0.2
		 *
		 * @param string             $text               free-form text to redact — typically a caught `\Throwable`'s `getMessage()`.
		 * @param array<int, string> $extra_secret_names additional secret param names, beyond the default list, to also redact. Non-string members are dropped.
		 * @return string
		 */
		public static function redact_secret_log_text( string $text, array $extra_secret_names = [] ): string {

			$secret_names = array_merge( self::get_default_secret_param_names(), self::sanitize_secret_names( $extra_secret_names ) );

			/**
			 * Filters the secret param names {@see Woodev_API_Base::redact_secret_log_text()}
			 * checks for, on top of the default list and any names the
			 * caller passed directly via $extra_secret_names.
			 *
			 * The return value is validated, not trusted: a non-array return
			 * is dropped entirely, and within an array each member survives
			 * only if it is a `string` AND its canonicalized form (see
			 * {@see self::canonicalize_secret_param_name()}) is non-empty —
			 * a non-string member, `''`, whitespace-only, or a name made
			 * entirely of characters canonicalization strips (e.g. `'---'`)
			 * is just as unusable as a non-string and is dropped the same
			 * way. If nothing usable survives, the whole return degrades to
			 * the default secret-name list rather than throwing or disabling
			 * redaction — see {@see Woodev_API_Base::redact_secret_log_text()}'s
			 * own docblock.
			 *
			 * A plugin author whose carrier client uses an uncommon
			 * credential name can add it here so every call site benefits,
			 * instead of passing $extra_secret_names at each one.
			 *
			 * @since 2.0.2
			 *
			 * @param array<int, string> $secret_names the names to redact; defaults plus $extra_secret_names.
			 * @param string             $text         the text about to be redacted.
			 */
			$filtered_names = apply_filters( 'woodev_api_log_text_secret_param_names', $secret_names, $text );

			$secret_names = is_array( $filtered_names ) ? self::sanitize_secret_names( $filtered_names ) : [];

			if ( [] === $secret_names ) {
				// The filter returned nothing usable at all (wrong type, empty,
				// every member malformed) — fall back to the default list rather
				// than redacting against an empty one. Degrading to the default
				// is right; degrading to no redaction at all is not.
				$secret_names = self::get_default_secret_param_names();
			}

			return self::redact_secret_query_params( $text, array_unique( $secret_names ) );
		}

		/**
		 * Filters $names down to entries safely usable as a secret param
		 * name. Drops anything that is not actually a `string` — an array,
		 * an object, `null`, a resource — rather than letting it reach
		 * {@see self::canonicalize_secret_param_name()} and throw a
		 * `\TypeError`, AND drops any string whose canonicalized form is
		 * empty (`''`, whitespace-only, or made entirely of characters
		 * canonicalization strips, e.g. `'---'`) — such a name can never
		 * match a real query param/header/tag, so keeping it around is
		 * indistinguishable from silently discarding the whole list: it
		 * masks the `[] === $secret_names` check in
		 * {@see self::redact_secret_log_text()} that exists specifically to
		 * fall back to the default denylist, so redaction runs against a
		 * list that matches nothing and the secret goes to the log raw
		 * (#585 critic round 3). Usability is decided AFTER
		 * canonicalization, not before.
		 *
		 * Both {@see self::redact_secret_log_text()}'s $extra_secret_names
		 * parameter and the `woodev_api_log_text_secret_param_names` filter
		 * it runs are caller-supplied (one from PHP code, one from a plugin
		 * hook) and either can hand back a malformed or unusable member.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int|string, mixed> $names candidate secret param names, possibly malformed or unusable.
		 * @return array<int, string>
		 */
		private static function sanitize_secret_names( array $names ): array {
			return array_values(
				array_filter(
					array_filter( $names, 'is_string' ),
					static function ( string $name ): bool {
						return '' !== self::canonicalize_secret_param_name( $name );
					}
				)
			);
		}

		/**
		 * Redacts a `path?query` or full URI string by PARSING its query string
		 * structurally, instead of scanning the text with a regex — the fix for
		 * #395 Round 4, Blocking: {@see self::redact_secret_query_params()} only
		 * ever compared the FIRST bracket segment of a key against the denylist,
		 * so a secret nested under a non-secret outer name (`a[token]=SECRET`,
		 * literal or percent-encoded) sailed through untouched.
		 *
		 * `parse_str()` decodes the query string into a real, arbitrarily-nested
		 * PHP array — handling percent-encoding, `+`-as-space, and bracket
		 * nesting the same way `http_build_query()` (which built the query in
		 * the first place) emits them, for free. {@see self::redact_secret_values_recursively()}
		 * then walks that array and redacts by canonicalized key at ANY depth,
		 * and the result is rebuilt with `http_build_query()` — never by editing
		 * the original text, so this cannot corrupt anything the way a text-scan
		 * over an unrelated format can (see {@see self::redact_secret_request_body()}
		 * for the JSON-body version of the same problem).
		 *
		 * Only the LOG string is rebuilt here; the request actually sent to the
		 * server is always built independently from the real, unredacted params
		 * (see {@see self::get_request_uri()}), so a rebuild being acceptable for
		 * a log line has no bearing on the wire request.
		 *
		 * @since 2.0.2
		 *
		 * @param string             $text A path (`?query` included), or a full URI.
		 * @param array<int, string> $secret_names
		 * @return string
		 */
		protected static function redact_secret_query_string( string $text, array $secret_names ): string {

			if ( '' === $text || empty( $secret_names ) ) {
				return $text;
			}

			$query_start = strpos( $text, '?' );

			if ( false === $query_start ) {
				return $text;
			}

			$query_string = substr( $text, $query_start + 1 );

			if ( '' === $query_string ) {
				return $text;
			}

			parse_str( $query_string, $params );

			if ( empty( $params ) ) {
				return $text;
			}

			$canonical_secret_names = array_unique( array_map( [ self::class, 'canonicalize_secret_param_name' ], $secret_names ) );
			$redacted_params        = self::redact_secret_values_recursively( $params, $canonical_secret_names );

			return substr( $text, 0, $query_start + 1 ) . http_build_query( $redacted_params, '', '&' );
		}

		/**
		 * Redacts a request/response BODY, for logging — dispatches on format
		 * instead of running one text-scanning regex over every shape, per #395
		 * Round 4 (SHOULD-FIX 2): a JSON body containing the literal text
		 * `token=value` inside a legitimate string field used to be truncated by
		 * {@see self::redact_secret_query_params()}'s `name=value` scan, corrupting
		 * a body the redactor was never meant to touch.
		 *
		 * A body that decodes as a JSON ARRAY/OBJECT is walked and redacted
		 * STRUCTURALLY — `json_decode()`, {@see self::redact_secret_values_recursively()}
		 * by key at any depth, `json_encode()` back — so a secret-shaped substring
		 * embedded in an unrelated string VALUE is never touched, and the result
		 * is always valid JSON because it was rebuilt from a decoded structure,
		 * never edited as text.
		 *
		 * Anything else — a body that decodes as a JSON SCALAR (a bare string,
		 * number, bool, or `null`), or a body that is not valid JSON at all — is
		 * replaced with {@see self::UNPARSEABLE_BODY_MASK} IN FULL. This covers
		 * three cases that used to be handled separately, and unsafely:
		 *
		 * - Round 4 fell back to {@see self::redact_secret_query_params()} for a
		 *   non-JSON body, on the theory that its `<name>value</name>` pass
		 *   covers XML and a `print_r()` dump (`[name] => value`) never needed
		 *   covering because {@see Woodev_Licensing_API_Request::to_string_safe()}
		 *   already masks its own `license` entry before handing the dump to
		 *   this method. An independent critic review proved that reasoning
		 *   false by invoking this method directly with a `print_r()`-shaped
		 *   body carrying an UNMASKED secret: the regex cannot match
		 *   `[name] => value`, so the secret came back verbatim — #395 Round 5,
		 *   Blocking.
		 * - Round 5 tried to parse a non-JSON body as a query string
		 *   (`name=value&name=value…`) whenever it merely LOOKED shaped that
		 *   way, via a syntax check ({@see self::is_form_encoded_body()},
		 *   removed in Round 6). A critic review broke that with two shapes
		 *   that both pass a syntax check without being a form body at all: a
		 *   URL a caller pasted as free text (`https://x?token=SECRET`) parses
		 *   as `https://x?token` `=` `SECRET`, and free text containing a
		 *   newline still matches per-line. You cannot infer the body's actual
		 *   format from what its bytes merely look like — #395 Round 6,
		 *   Blocking 1. No concrete request class in this codebase ever
		 *   produces a form-encoded body (every {@see self::set_request_content_type_header()}
		 *   call in this codebase passes `application/json`; there is no
		 *   `Woodev_API_Form_Request` counterpart to
		 *   {@see Woodev_API_JSON_Request}/{@see Woodev_API_XML_Request}, and
		 *   the `Woodev_API_Request` interface declares no content-type/format
		 *   accessor a body could be checked against independently), so there
		 *   is no trustworthy signal to parse the body against in the first
		 *   place — whole-masking is not a lesser default, it is the only
		 *   correct one until a request class can declare its own format
		 *   through a channel the body's bytes cannot forge.
		 * - Round 5 also returned a JSON SCALAR body unchanged, reasoning that
		 *   there is no key to redact by. That is true, but incomplete: with no
		 *   key, the scalar VALUE itself is the only thing there, and nothing
		 *   rules out that value being the secret itself (a body that is
		 *   `"super-secret-token-value"` and nothing else) — #395 Round 6,
		 *   Blocking 2. A scalar has exactly the same "no key to redact by"
		 *   problem a non-JSON body does, so it now gets the same answer:
		 *   `null` is masked too, for the same reason — not because it can
		 *   carry a secret (it cannot: it carries no value at all), but because
		 *   a reader of the log should not have to learn that JSON scalars
		 *   split into "one kind that's shown" and "three kinds that are
		 *   masked" to understand a log line; one rule for every scalar is
		 *   simpler and costs nothing a null body's absence of information
		 *   didn't already cost.
		 *
		 * A parsed rendering of the surviving case (a JSON array/object) is a
		 * LOG rendering, not the wire bytes — over-redaction is the acceptable
		 * side to err on here, but a reader relying on a log line to
		 * reconstruct the original request should know this is lossy, by
		 * design:
		 *
		 * - re-encoding through `json_decode()`/`json_encode()` changes a
		 *   numeric literal's shape (`1.0` becomes `1`), the slash- and
		 *   Unicode-escaping (this method requests `JSON_UNESCAPED_SLASHES |
		 *   JSON_UNESCAPED_UNICODE`, which the original body may not have
		 *   used), and can turn an empty JSON object (`{}`) into an empty PHP
		 *   array that re-encodes as `[]`, or renumber a numeric-string JSON
		 *   key;
		 * - {@see self::canonicalize_secret_param_name()} folds separators away
		 *   before comparing, so distinct keys like `api.key` and `api_key`
		 *   canonicalize to the SAME comparison key and collide — a field
		 *   that merely LOOKS like a secret name once its separators are
		 *   stripped is over-redacted by design, per the "defence in depth,
		 *   not a guarantee" note on {@see self::get_default_secret_param_names()}.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 no longer trusts an unparseable body to the `name=value`/
		 *              `<name>value</name>` regex backstop — replaces it with
		 *              {@see self::UNPARSEABLE_BODY_MASK} in full instead, verifies
		 *              a non-JSON body is genuinely query-string-shaped before
		 *              parsing it as one, and returns a scalar JSON body unchanged
		 *              instead of regex-scanning it — #395 Round 5.
		 * @since 2.0.2 drops the query-string-shaped exception entirely — no
		 *              request class in this codebase can prove a body is
		 *              actually form-encoded rather than merely shaped like it,
		 *              so every non-JSON-array/object body is now whole-masked
		 *              — and masks a JSON scalar (including `null`) the same
		 *              way, instead of returning it unchanged, because a bare
		 *              scalar has no key to redact by and may itself BE the
		 *              secret — #395 Round 6, Blocking 1 & 2.
		 * @since 2.0.2 also called by {@see self::get_sanitized_response_body()}
		 *              to redact response bodies — #427. Every rule above is
		 *              about the body's FORMAT, not which direction it
		 *              travelled, so no response-specific branch was needed;
		 *              only the caller changed, not this method.
		 *
		 * @param string             $body
		 * @param array<int, string> $secret_names
		 * @return string
		 */
		protected static function redact_secret_request_body( string $body, array $secret_names ): string {

			if ( '' === $body || empty( $secret_names ) ) {
				return $body;
			}

			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {

				$canonical_secret_names = array_unique( array_map( [ self::class, 'canonicalize_secret_param_name' ], $secret_names ) );
				$redacted               = self::redact_secret_values_recursively( $decoded, $canonical_secret_names );
				$encoded                = json_encode( $redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

				return false !== $encoded ? $encoded : $body;
			}

			return self::UNPARSEABLE_BODY_MASK;
		}

		/**
		 * Walks a decoded query-string or JSON array and redacts the value of
		 * every entry whose KEY canonicalizes to a denylisted name, AT ANY DEPTH
		 * — not only the top level. A matching key's entire value is replaced
		 * with {@see self::SECRET_VALUE_MASK} in one shot, even when that value
		 * is itself an array (e.g. `token[primary]=…`): there is no safe way to
		 * partially mask a subtree whose very key is the secret's name. A
		 * NON-matching key whose value is an array is recursed into, so a secret
		 * nested under a non-secret outer name (`a[token]=…`) is still caught —
		 * #395 Round 4, Blocking.
		 *
		 * Shared by {@see self::redact_secret_query_string()} (query strings) and
		 * {@see self::redact_secret_request_body()} (JSON bodies) so both use the
		 * exact same nesting rule.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int|string, mixed> $values
		 * @param array<int, string>       $canonical_secret_names Already-canonicalized names.
		 * @return array<int|string, mixed>
		 */
		private static function redact_secret_values_recursively( array $values, array $canonical_secret_names ): array {

			foreach ( $values as $key => $value ) {

				if ( in_array( self::canonicalize_secret_param_name( (string) $key ), $canonical_secret_names, true ) ) {
					$values[ $key ] = self::SECRET_VALUE_MASK;
					continue;
				}

				if ( is_array( $value ) ) {
					$values[ $key ] = self::redact_secret_values_recursively( $value, $canonical_secret_names );
				}
			}

			return $values;
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
		 * Splits a possibly nested/percent-encoded query key into ALL of its
		 * name segments, e.g. `token%5Bprimary%5D` and `token[primary]` both
		 * yield `[ 'token', 'primary' ]` — not only the top-level `token` a
		 * previous round of this fix stopped at, which is how `a[token]=SECRET`
		 * (a secret nested under a NON-secret outer name) sailed through: only
		 * `a` was ever compared against the denylist. Every segment is now
		 * returned so the caller can check ALL of them — #395 Round 4, Blocking.
		 *
		 * The key is fully URL-decoded (`urldecode()`, so `+` becomes a literal
		 * space the same as any other percent-encoded byte) before splitting, so
		 * `api+key` decodes to `api key`, which {@see self::canonicalize_secret_param_name()}
		 * then folds down to `apikey` same as `api_key`/`api-key`/`apiKey` — #395
		 * Round 4, SHOULD-FIX 1. A key with no bracket suffix yields a single
		 * segment: itself, decoded.
		 *
		 * Used only by {@see self::redact_secret_query_params()}.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 renamed from `get_query_param_base_name()` and returns
		 *              EVERY bracket segment instead of only the first; fully
		 *              URL-decodes the key instead of unescaping only brackets —
		 *              #395 Round 4 (Blocking, SHOULD-FIX 1).
		 *
		 * @param string $key Raw query key, as captured from the wire text.
		 * @return array<int, string>
		 */
		private static function get_query_param_name_segments( string $key ): array {

			$decoded_key = urldecode( $key );

			preg_match_all( '/[^\[\]]+/', $decoded_key, $matches );

			return $matches[0];
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
		 * {@see self::redact_secret_request_body()} unconditionally, instead of
		 * being trusted outright. Before this, the base took the interface-required
		 * `to_string_safe()` result on faith — but that method is opaque to the
		 * base (it declares no format, and any concrete class is free to return
		 * whatever it wants), so a request class that implements it naively still
		 * logged a raw credential by default. {@see Woodev_API_XML_Request::to_string_safe()}
		 * is a concrete example already in this codebase: it prettifies the XML
		 * body but masks nothing in it. See #395 Round 3, Blocking 2.
		 *
		 * A `Woodev_API_JSON_Request` body that already masked its own secret
		 * params (via {@see self::mask_secret_values()} before serializing) is
		 * unaffected: a JSON body is walked and redacted by key, so an
		 * already-masked value stays masked. A `Woodev_Licensing_API_Request`
		 * body, however, is a `print_r()` dump — a format
		 * {@see self::redact_secret_request_body()} cannot parse and walk, so it
		 * is now replaced with {@see self::UNPARSEABLE_BODY_MASK} in full, same
		 * as any other unstructured body reaching this second pass (an
		 * independent critic review proved, in #395 Round 5, that the previous
		 * regex-based fallback here could not actually see a secret in that
		 * shape — see {@see self::redact_secret_request_body()} for the full
		 * account). This is a deliberate loss of debugging detail for an
		 * already-self-masked body, not a regression: the base has no way to
		 * distinguish "already masked, unstructured" from "never masked,
		 * unstructured" without parsing the format, and only the latter is the
		 * case this second pass exists to catch.
		 *
		 * @since 2.0.2 runs {@see self::redact_secret_query_params()} over whatever
		 *              `to_string_safe()` returns, instead of trusting it outright.
		 * @since 2.0.2 dispatches on body format via
		 *              {@see self::redact_secret_request_body()} instead of running
		 *              the same `name=value` text-scan over every body, so a JSON
		 *              body is no longer at risk of the scan mistaking text inside
		 *              a legitimate string value for a `name=value` pair and
		 *              truncating it — #395 Round 4, SHOULD-FIX 2.
		 * @since 2.0.2 a body {@see self::redact_secret_request_body()} cannot
		 *              parse and walk (e.g. a `print_r()` dump) is now masked in
		 *              full via {@see self::UNPARSEABLE_BODY_MASK}, instead of
		 *              being handed to a regex backstop that could not see a
		 *              secret in that shape at all — #395 Round 5, Blocking.
		 *
		 * @return string
		 */
		protected function get_sanitized_request_body() {

			if ( in_array( strtoupper( $this->get_request_method() ), array( 'GET', 'HEAD' ) ) ) {
				return '';
			}

			$body = ( $this->get_request() && $this->get_request()->to_string_safe() ) ? $this->get_request()->to_string_safe() : '';

			return self::redact_secret_request_body( $body, $this->get_secret_param_names() );
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

		/**
		 * Gets the sanitized response body, for logging.
		 *
		 * Symmetric with {@see self::get_sanitized_request_body()} — #427. Before
		 * this, the response side had no fail-safe pass at all: every concrete
		 * response class in this codebase ({@see Woodev_API_JSON_Response},
		 * {@see Woodev_API_XML_Response}) aliases `to_string_safe()` straight onto
		 * the raw `to_string()`, so whatever came back over the wire reached the
		 * log unredacted by default — the exact gap {@see self::redact_secret_request_body()}
		 * already closed on the request side.
		 *
		 * Whatever `to_string_safe()` returns — or `''` when there is no response
		 * yet, or its class does not implement the method at all — is run through
		 * {@see self::redact_secret_request_body()} unconditionally, exactly like
		 * the request side: a JSON object/array body is walked and redacted by
		 * key at any depth, anything else (XML, a `print_r()` dump, free text, a
		 * bare JSON scalar) is replaced with {@see self::UNPARSEABLE_BODY_MASK} in
		 * full.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function get_sanitized_response_body(): string {

			$body = is_callable( [ $this->get_response(), 'to_string_safe' ] ) ? (string) $this->get_response()->to_string_safe() : '';

			return self::redact_secret_request_body( $body, $this->get_secret_param_names() );
		}

		/**
		 * Gets the response data for broadcasting the request.
		 * Overriding this method allows child classes to customize the response data when broadcasting the request.
		 *
		 * @since 2.0.2 `headers` is now {@see self::get_sanitized_response_headers()}
		 *              instead of the raw {@see self::get_response_headers()} — see #300.
		 * @since 2.0.2 `body` is now {@see self::get_sanitized_response_body()} taken
		 *              unconditionally, instead of falling back to the raw
		 *              {@see self::get_raw_response_body()} whenever the sanitized
		 *              value was falsy (`''`, `'0'`, ...) — #427. That fallback was
		 *              exactly the case redaction exists to cover: a response class
		 *              with no `to_string_safe()`, or one that returns a falsy but
		 *              real value, fell straight through to the never-redacted
		 *              bytes off the wire — the second, undocumented leak behind
		 *              #427. A genuinely empty sanitized body already logs as `''`
		 *              (see {@see self::get_sanitized_response_body()}, same "empty
		 *              stays empty" convention {@see self::redact_secret_request_body()}
		 *              uses for an empty request body), so there is no longer a
		 *              case where the raw body carries information the sanitized
		 *              one does not — falling back to raw bytes is never correct.
		 *
		 * @return array<string, mixed>
		 */
		protected function get_response_data_for_broadcast() {
			return [
				'code'    => $this->get_response_code(),
				'message' => $this->get_response_message(),
				'headers' => $this->get_sanitized_response_headers(),
				'body'    => $this->get_sanitized_response_body(),
			];
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
