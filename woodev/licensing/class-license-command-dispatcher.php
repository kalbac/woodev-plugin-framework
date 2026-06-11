<?php
/**
 * Signed license-command dispatcher (§9.4 pipeline, forward tolerance).
 *
 * Transport-neutral: the REST endpoint (s8-p3) and the pull-fallback (s8-p5) both
 * feed it. handle_raw_body() adds the public-endpoint abuse gates (rate-limit, body
 * size, JSON decode + strict schema) on top of handle_envelope(), which runs the
 * cryptographic + protocol pipeline in the FROZEN §9.4 order:
 *
 *   1  rate-limit gate (transient, 30 rejections / 60s)        → rate_limited 429
 *   2  raw body <= MAX_BODY_BYTES                              → malformed     400
 *   3  json_decode (depth 8) + strict schema / scalar caps     → malformed     400
 *   4  strict base64 signature, 64 bytes                       → bad_signature 401
 *   5  kid rule (absent or matching the embedded key)          → bad_signature 401
 *   6  Ed25519 verify (BEFORE any site/plugin lookup)          → bad_signature 401
 *   7  protocol === 1                                          → unsupported_protocol 400
 *   8  normalized site === normalized home_url()               → site_mismatch  401
 *   9  registry lookup (absent OR ambiguous)                   → unknown_plugin 404
 *  10  time window (skew / TTL / ordering / expiry)            → invalid_window 400 / expired 410
 *  11  atomic nonce claim                                      → replayed 410 / rate_limited 429
 *      then vocabulary lookup (post-claim) + dispatch
 *
 * ZERO side effects on every rejection path: no add_option/update_option (except the
 * rate transient on steps 2–6), no command handler call, no do_action. The atomic
 * claim is the FIRST persistent write; the nonce is consumed for every terminal
 * outcome reached AFTER it (executed / already / unsupported_command /
 * network_active_unsupported). Steps 4–6 verify the signature BEFORE any site/plugin
 * lookup so an attacker learns nothing about the installed fleet.
 *
 * @package Woodev\Framework\Licensing
 * @since 2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_License_Command_Dispatcher' ) ) :

	/**
	 * Validates and dispatches signed server→client license commands.
	 *
	 * Not final: the unit tests subclass it to override the now() time seam so the
	 * window assertions are deterministic without sleeping.
	 *
	 * @since 2.0.0
	 */
	class Woodev_License_Command_Dispatcher {

		/**
		 * Supported protocol version (frozen §9.8).
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const PROTOCOL_VERSION = 1;

		/**
		 * Maximum accepted raw body length in bytes.
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const MAX_BODY_BYTES = 8192;

		/**
		 * json_decode max depth for the envelope.
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const JSON_DEPTH = 8;

		/**
		 * Clock-skew allowance for issued_at (seconds in the future).
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const CLOCK_SKEW = 300;

		/**
		 * Rate-limit transient key (counts pre-auth rejections in a window).
		 *
		 * @since 2.0.0
		 *
		 * @var string
		 */
		const RATE_LIMIT_TRANSIENT = 'woodev_license_cmd_rl';

		/**
		 * Rate-limit window length in seconds.
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const RATE_LIMIT_WINDOW = 60;

		/**
		 * Rejections allowed in the window before short-circuiting.
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const RATE_LIMIT_MAX = 30;

		// Scalar caps (frozen §9.4).
		const MAX_COMMAND_LENGTH    = 64;
		const MAX_SITE_LENGTH       = 255;
		const MAX_PLUGIN_ID_LENGTH  = 20;
		const MAX_KID_LENGTH        = 64;
		const MAX_ARGS_ENTRIES      = 16;
		const MAX_ARG_SCALAR_LENGTH = 255;

		/**
		 * The deterministic status → HTTP-code map (frozen §9.8).
		 *
		 * @since 2.0.0
		 *
		 * @var array<string, int>
		 */
		const HTTP_MAP = array(
			'executed'                    => 200,
			'already'                     => 200,
			'malformed'                   => 400,
			'unsupported_protocol'        => 400,
			'unsupported_command'         => 400,
			'invalid_window'              => 400,
			'bad_signature'               => 401,
			'site_mismatch'               => 401,
			'unknown_plugin'              => 404,
			'network_active_unsupported'  => 409,
			'expired'                     => 410,
			'replayed'                    => 410,
			'rate_limited'                => 429,
			'failed'                      => 500,
		);

		/**
		 * Registered command vocabulary, keyed by command name.
		 *
		 * Values are handlers. The typed Woodev_License_Command interface lands in
		 * s8-p4; until then the registry accepts callables.
		 *
		 * @since 2.0.0
		 *
		 * @var array<string, callable|object>
		 */
		private static $commands = array();

		/**
		 * Registers a command handler under its vocabulary name.
		 *
		 * BINDING registry contract (plan §9.2, amended 2026-06-11): registered
		 * commands MUST be idempotent — re-executing the same payload must be safe.
		 * The crash-recovery TAKEOVER of a stuck nonce (and its accepted non-atomic
		 * race: two stale-takeover requests can both win and both re-execute) relies
		 * on this. A future NON-idempotent command must first replace the takeover
		 * rule in Woodev_License_Command_Nonce_Store before it may be registered.
		 *
		 * @since 2.0.0
		 *
		 * @param string          $name    The command vocabulary name.
		 * @param callable|object $handler The handler (callable now; interface in s8-p4).
		 * @return void
		 */
		public static function register_command( string $name, $handler ): void {
			self::$commands[ $name ] = $handler;
		}

		/**
		 * Clears the command registry. Test seam only.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public static function reset_commands_for_tests(): void {
			self::$commands = array();
		}

		/**
		 * Entry point for the public REST endpoint: gates 1–3 then handle_envelope().
		 *
		 * @since 2.0.0
		 *
		 * @param string $body The raw request body.
		 * @return array{status: string, reason?: string, http: int}
		 */
		public static function handle_raw_body( string $body ): array {

			// Gate 1: rate-limit — a flooded window short-circuits with no further work.
			if ( self::is_rate_limited() ) {
				return self::reject( 'rate_limited' );
			}

			// Gate 2: body size (cheap, before any JSON work).
			if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
				return self::reject_and_count( 'malformed', 'inbound' );
			}

			// Gate 3: strict JSON decode + strict schema.
			$envelope = json_decode( $body, true, self::JSON_DEPTH );

			if ( ! is_array( $envelope ) || ! self::is_schema_valid( $envelope ) ) {
				return self::reject_and_count( 'malformed', 'inbound' );
			}

			return self::handle_envelope( $envelope, 'inbound' );
		}

		/**
		 * Runs steps 3 (schema, when reached directly) – 11 of the §9.4 pipeline.
		 *
		 * Usable without any HTTP context: the pull transport (s8-p5) feeds verified
		 * envelopes here. The schema gate runs here too so a pull-delivered envelope is
		 * held to the same strictness as an inbound one (steps 1–2 are HTTP-only).
		 *
		 * The dispatcher writes an ack record for EVERY terminal outcome reached AFTER
		 * the atomic claim (§9.5). When no $ack_store is injected it constructs the
		 * real default store itself (critic ruling s8-p5 #2) — the parameter is an
		 * injection seam for tests. Frozen lifecycle order: action → ack record →
		 * mark nonce consumed. For 'failed' (handler threw) the ack is non-terminal
		 * (retryable) and the nonce stays 'processing' so §9.1 takeover can retry.
		 * Both transports write acks; the duplicate is harmless — the server ignores
		 * nonces it has already cleared (§9.5).
		 *
		 * §9.7 client-side note: the ack carries no extra authenticity material —
		 * the request already carries `url` (raw home_url()) and the license key when
		 * present. The server binds acks to the request's url; that rule lives in the
		 * mirror spec, not here.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed>             $envelope  The decoded envelope.
		 * @param string                           $transport The delivery transport ('inbound'|'pull').
		 * @param Woodev_License_Command_Acks|null $ack_store Optional ack store for structured ack writes (§9.5).
		 * @return array{status: string, reason?: string, http: int}
		 */
		public static function handle_envelope( array $envelope, string $transport, $ack_store = null ): array {

			// §9.5: BOTH transports write acks. The production inbound path
			// (handle_raw_body via the REST controller) injects no store, so default
			// to the real one — critic ruling s8-p5 #2. The parameter stays an
			// injection seam for tests.
			if ( null === $ack_store ) {
				$ack_store = self::get_default_ack_store();
			}

			// Schema (idempotent with handle_raw_body's gate 3; the pull path enters here).
			if ( ! self::is_schema_valid( $envelope ) ) {
				return self::reject_and_count( 'malformed', $transport );
			}

			// Step 4: strict base64 signature, 64 bytes.
			$raw_signature = base64_decode( (string) $envelope['signature'], true );

			if ( false === $raw_signature || Woodev_License_Envelope_Verifier::SIGNATURE_BYTES !== strlen( $raw_signature ) ) {
				return self::reject_and_count( 'bad_signature', $transport );
			}

			// Steps 5–6: kid rule + Ed25519 verify (BEFORE any site/plugin lookup).
			$payload = Woodev_License_Envelope_Verifier::verify( $envelope, self::get_public_key() );

			if ( null === $payload ) {
				return self::reject_and_count( 'bad_signature', $transport );
			}

			// From here the signature is authentic — pre-auth rate counting stops.

			// Step 7: protocol — reject NOW (claim has not run → no consumption, no ack).
			if ( self::PROTOCOL_VERSION !== ( $payload['protocol'] ?? null ) ) {
				return self::reject( 'unsupported_protocol' );
			}

			// Step 8: site binding.
			$payload_site = woodev_normalize_site( (string) ( $payload['site'] ?? '' ) );
			$home_site    = woodev_normalize_site( (string) home_url() );

			if ( null === $payload_site || null === $home_site || $payload_site !== $home_site ) {
				return self::reject( 'site_mismatch' );
			}

			// Step 9: plugin lookup (absent OR ambiguous → unknown_plugin, no info leak).
			$plugin_id = (string) ( $payload['plugin_id'] ?? '' );

			if ( Woodev_Plugins_License::is_download_id_ambiguous( $plugin_id ) ) {
				return self::reject( 'unknown_plugin' );
			}

			$engine = Woodev_Plugins_License::get_registered_instance( $plugin_id );

			if ( null === $engine ) {
				return self::reject( 'unknown_plugin' );
			}

			// Step 10: time window.
			$issued_at  = $payload['issued_at'] ?? null;
			$expires_at = $payload['expires_at'] ?? null;

			if ( ! is_int( $issued_at ) || ! is_int( $expires_at ) ) {
				return self::reject( 'invalid_window' );
			}

			$now = static::now();

			if ( $expires_at <= $issued_at
				|| $issued_at > ( $now + self::CLOCK_SKEW )
				|| ( $expires_at - $issued_at ) > Woodev_License_Command_Nonce_Store::MAX_TTL
			) {
				return self::reject( 'invalid_window' );
			}

			if ( $now > $expires_at ) {
				return self::reject( 'expired' );
			}

			// Step 11: atomic nonce claim — the FIRST persistent write.
			$store  = self::get_nonce_store();
			$nonce  = (string) $payload['nonce'];
			$claim  = $store->claim( $nonce, $expires_at );

			if ( 'replayed' === $claim ) {
				return self::reject( 'replayed' );
			}

			if ( 'store_full' === $claim ) {
				// Retryable: the server redelivers later (mirror spec).
				return self::reject( 'rate_limited' );
			}

			// Vocabulary lookup AFTER the claim. An unknown command consumes the nonce
			// and takes NO action (forward tolerance B-2: never hard-fail, never
			// ack-as-done).
			$command = (string) $payload['command'];

			if ( ! isset( self::$commands[ $command ] ) ) {
				// Frozen lifecycle order (§9.1): action (none) → ack record → mark consumed.
				if ( null !== $ack_store ) {
					$ack_store->record( $nonce, 'unsupported_command' );
				}
				$store->mark_consumed( $nonce, 'unsupported_command', $expires_at );

				return self::terminal( 'unsupported_command' );
			}

			// Dispatch. A handler Throwable is a retryable 'failed' (nonce stays
			// processing → §9.1 takeover retry); a clean return is the terminal status.
			try {
				$status = (string) self::invoke( self::$commands[ $command ], $engine, $payload );
			} catch ( \Throwable $throwable ) {
				// Frozen lifecycle order: action (threw) → ack record (retryable) →
				// nonce stays processing (mark_consumed NOT called, §9.1 takeover retry).
				if ( null !== $ack_store ) {
					$ack_store->record( $nonce, 'failed' );
				}
				return self::terminal( 'failed' );
			}

			// Frozen lifecycle order (§9.1): action → ack record → mark consumed.
			if ( null !== $ack_store ) {
				$ack_store->record( $nonce, $status );
			}
			$store->mark_consumed( $nonce, $status, $expires_at );

			return self::terminal( $status );
		}

		/**
		 * Processes a pull-fallback license_commands array from a server response.
		 *
		 * Extracts the top-level `license_commands` field (array of envelopes; an
		 * object-shaped response is normalised via json_decode(wp_json_encode())),
		 * then feeds each envelope through the FULL §9.4 verification pipeline
		 * (minus HTTP gates 1–2: rate-limit and body-size checks are HTTP-only).
		 * Schema gate 3 onward applies identically to the inbound path.
		 *
		 * A malformed or rejected entry is SKIPPED with zero side effects; remaining
		 * entries still process. The same nonce delivered via both inbound and pull
		 * executes ONCE (the nonce store's replay protection handles deduplication).
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed>|object      $response_data The server response (array or object).
		 * @param string                           $transport     The transport label, default 'pull'.
		 * @param Woodev_License_Command_Acks|null $ack_store     Optional ack store for structured ack writes (§9.5).
		 * @return void
		 */
		public static function consume_pull_commands( $response_data, string $transport = 'pull', $ack_store = null ): void {

			// Normalise an object-shaped response to an associative array.
			if ( is_object( $response_data ) ) {
				$response_data = json_decode( (string) wp_json_encode( $response_data ), true );
			}

			if ( ! is_array( $response_data ) ) {
				return;
			}

			$commands = $response_data['license_commands'] ?? null;

			if ( ! is_array( $commands ) ) {
				return;
			}

			foreach ( $commands as $envelope ) {

				// Silently skip non-array entries (§9.9: malformed entries skipped,
				// zero side effects, remaining entries still processed).
				if ( ! is_array( $envelope ) ) {
					continue;
				}

				// Normalise object-valued payload (e.g. json_decode without assoc).
				if ( isset( $envelope['payload'] ) && is_object( $envelope['payload'] ) ) {
					$envelope['payload'] = json_decode( (string) wp_json_encode( $envelope['payload'] ), true );
				}

				// Run the full pipeline from schema gate (step 3) onward.
				// Rejected or replayed envelopes produce a result but no side effects;
				// we discard the result — the pull path has no HTTP response.
				self::handle_envelope( $envelope, $transport, $ack_store );
			}
		}

		/**
		 * Invokes a registered handler (callable now; Woodev_License_Command in s8-p4).
		 *
		 * @since 2.0.0
		 *
		 * @param callable|object        $handler The registered handler.
		 * @param Woodev_Plugins_License $engine  The resolved target license engine.
		 * @param array<string, mixed>   $payload The verified payload.
		 * @return string The terminal ack status.
		 */
		private static function invoke( $handler, $engine, array $payload ): string {

			if ( is_object( $handler ) && method_exists( $handler, 'execute' ) ) {
				return (string) $handler->execute( $engine, $payload );
			}

			return (string) call_user_func( $handler, $engine, $payload );
		}

		/**
		 * Builds a terminal (executed/already/...) result with its mapped HTTP code.
		 *
		 * @since 2.0.0
		 *
		 * @param string $status The terminal status.
		 * @return array{status: string, http: int}
		 */
		private static function terminal( string $status ): array {
			return array(
				'status' => $status,
				'http'   => self::HTTP_MAP[ $status ] ?? 500,
			);
		}

		/**
		 * Builds a rejection result carrying ONLY status/reason/http (no internals).
		 *
		 * @since 2.0.0
		 *
		 * @param string $reason The rejection reason code.
		 * @return array{status: string, reason: string, http: int}
		 */
		private static function reject( string $reason ): array {
			return array(
				'status' => 'rejected',
				'reason' => $reason,
				'http'   => self::HTTP_MAP[ $reason ] ?? 400,
			);
		}

		/**
		 * Whether the current rejection window is over the rate limit.
		 *
		 * The window is anchored at t0 (stored in the transient value), NEVER at the
		 * transient TTL — a TTL-anchored window would be extended by every write and
		 * a continuous low-rate drip would accumulate forever (plan §9.2 amended).
		 *
		 * @since 2.0.0
		 *
		 * @return bool
		 */
		private static function is_rate_limited(): bool {

			$window = get_transient( self::RATE_LIMIT_TRANSIENT );

			if ( ! is_array( $window ) || ! isset( $window['n'], $window['t0'] ) ) {
				return false;
			}

			if ( ( static::now() - (int) $window['t0'] ) > self::RATE_LIMIT_WINDOW ) {
				// Window expired — t0 is authoritative, regardless of transient TTL.
				return false;
			}

			return (int) $window['n'] > self::RATE_LIMIT_MAX;
		}

		/**
		 * Rejects AND increments the pre-authentication rate counter (steps 2–6 only).
		 *
		 * The transient stores { n, t0 }: an expired window (now - t0 > 60) resets to
		 * { 1, now }; otherwise n increments WITHOUT moving t0, so the window is never
		 * extended by traffic. The transient TTL is a 2x-window storage-cleanup bound
		 * only — the gate reads t0, never the TTL. The read-modify-write increment is
		 * lossy under concurrency — accepted, best-effort by design (plan §9.2).
		 *
		 * Ruled (s8-p5 critic #3): ONLY inbound (public REST) rejections count toward
		 * the HTTP rate limit. Pull-path rejections must NOT touch the transient — the
		 * limit guards the public endpoint against floods, and a server-delivered pull
		 * batch with malformed entries must never lock out the legitimate inbound path.
		 *
		 * @since 2.0.0
		 *
		 * @param string $reason    The rejection reason code.
		 * @param string $transport The delivery transport ('inbound'|'pull').
		 * @return array{status: string, reason: string, http: int}
		 */
		private static function reject_and_count( string $reason, string $transport = 'inbound' ): array {

			if ( 'inbound' !== $transport ) {
				return self::reject( $reason );
			}

			$now    = static::now();
			$window = get_transient( self::RATE_LIMIT_TRANSIENT );

			if ( ! is_array( $window )
				|| ! isset( $window['n'], $window['t0'] )
				|| ( $now - (int) $window['t0'] ) > self::RATE_LIMIT_WINDOW
			) {
				$window = array(
					'n'  => 1,
					't0' => $now,
				);
			} else {
				$window['n'] = (int) $window['n'] + 1;
			}

			set_transient( self::RATE_LIMIT_TRANSIENT, $window, 2 * self::RATE_LIMIT_WINDOW );

			return self::reject( $reason );
		}

		/**
		 * Strict envelope-schema validation (top keys, payload keys, scalar caps).
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed> $envelope The decoded envelope.
		 * @return bool
		 */
		private static function is_schema_valid( array $envelope ): bool {

			// Top-level keys ⊆ {payload, signature, kid}; payload + signature required.
			foreach ( array_keys( $envelope ) as $key ) {
				if ( ! in_array( $key, array( 'payload', 'signature', 'kid' ), true ) ) {
					return false;
				}
			}

			if ( ! isset( $envelope['payload'] ) || ! is_array( $envelope['payload'] ) ) {
				return false;
			}

			if ( ! isset( $envelope['signature'] ) || ! is_string( $envelope['signature'] ) ) {
				return false;
			}

			if ( array_key_exists( 'kid', $envelope )
				&& ( ! is_string( $envelope['kid'] ) || strlen( $envelope['kid'] ) > self::MAX_KID_LENGTH )
			) {
				return false;
			}

			return self::is_payload_schema_valid( $envelope['payload'] );
		}

		/**
		 * Strict payload-schema validation.
		 *
		 * Payload keys must be exactly {protocol, command, site, plugin_id, nonce,
		 * issued_at, expires_at} plus an optional `args`. Enforces the §9.4 scalar caps.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed> $payload The payload.
		 * @return bool
		 */
		private static function is_payload_schema_valid( array $payload ): bool {

			$required = array( 'protocol', 'command', 'site', 'plugin_id', 'nonce', 'issued_at', 'expires_at' );
			$allowed  = array_merge( $required, array( 'args' ) );

			foreach ( $required as $key ) {
				if ( ! array_key_exists( $key, $payload ) ) {
					return false;
				}
			}

			foreach ( array_keys( $payload ) as $key ) {
				if ( ! in_array( $key, $allowed, true ) ) {
					return false;
				}
			}

			if ( ! is_int( $payload['protocol'] ) ) {
				return false;
			}

			if ( ! is_string( $payload['command'] ) || strlen( $payload['command'] ) > self::MAX_COMMAND_LENGTH ) {
				return false;
			}

			if ( ! is_string( $payload['site'] ) || strlen( $payload['site'] ) > self::MAX_SITE_LENGTH ) {
				return false;
			}

			if ( ! is_string( $payload['plugin_id'] ) || strlen( $payload['plugin_id'] ) > self::MAX_PLUGIN_ID_LENGTH ) {
				return false;
			}

			if ( ! is_string( $payload['nonce'] ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $payload['nonce'] ) ) {
				return false;
			}

			if ( ! is_int( $payload['issued_at'] ) || ! is_int( $payload['expires_at'] ) ) {
				return false;
			}

			if ( array_key_exists( 'args', $payload ) && ! self::is_args_valid( $payload['args'] ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Validates the optional `args` container: <= 16 scalar entries, each <= 255.
		 *
		 * @since 2.0.0
		 *
		 * @param mixed $args The args value.
		 * @return bool
		 */
		private static function is_args_valid( $args ): bool {

			if ( ! is_array( $args ) ) {
				return false;
			}

			if ( count( $args ) > self::MAX_ARGS_ENTRIES ) {
				return false;
			}

			foreach ( $args as $value ) {
				if ( ! is_scalar( $value ) ) {
					return false;
				}

				if ( strlen( (string) $value ) > self::MAX_ARG_SCALAR_LENGTH ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Resolves the base64 public key, overridable via the shared filter for tests.
		 *
		 * @since 2.0.0
		 *
		 * @return string The base64 public key.
		 */
		private static function get_public_key(): string {

			$default = defined( 'WOODEV_LICENSE_AUTHORITY_PUBKEY' ) ? (string) WOODEV_LICENSE_AUTHORITY_PUBKEY : '';

			return (string) apply_filters( 'woodev_license_authority_pubkey', $default );
		}

		/**
		 * Builds the DEFAULT ack store used when no store is injected (§9.5).
		 *
		 * Critic ruling s8-p5 #2: the production inbound path (REST controller →
		 * handle_raw_body) passes no store, so the dispatcher must construct the real
		 * one itself or inbound acks would never be written. Shares the dispatcher's
		 * now() seam so the retention math is deterministic under test.
		 *
		 * @since 2.0.0
		 *
		 * @return Woodev_License_Command_Acks|null Null when the acks class is not loaded.
		 */
		private static function get_default_ack_store() {

			if ( ! class_exists( 'Woodev_License_Command_Acks' ) ) {
				return null;
			}

			return new Woodev_License_Command_Acks(
				static function (): int {
					return static::now();
				}
			);
		}

		/**
		 * Builds the nonce store. Overridable indirectly through the now() seam.
		 *
		 * @since 2.0.0
		 *
		 * @return Woodev_License_Command_Nonce_Store
		 */
		private static function get_nonce_store(): Woodev_License_Command_Nonce_Store {
			// Share the dispatcher's single now() seam with the store so the window /
			// takeover / retention math is consistent (deterministic under test).
			return new Woodev_License_Command_Nonce_Store(
				static function (): int {
					return static::now();
				}
			);
		}

		/**
		 * Returns the current Unix time. Overridable for deterministic tests.
		 *
		 * @since 2.0.0
		 *
		 * @return int
		 */
		protected static function now(): int {
			return time();
		}
	}

endif;
