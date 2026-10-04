<?php
/**
 * Turns a browser report into a Sentry `event` payload.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Browser_Event_Builder' ) ) :

	/**
	 * Builds the event for a report the BROWSER sent (#1081, D7) — or null when it is not ours.
	 *
	 * **Nothing the client says is exported as text.** Syntax validation does not prove anonymity (a name
	 * or a phone number is a valid «token»), so every exported value is one of: a number; a constant of
	 * this class; a value the SERVER knows — the filesystem-verified path of an existing script of a
	 * registered plugin ({@see Plugin_Scope::locate_url()}), a registered plugin id, a pickup field id the
	 * pickup handlers declared. A value outside its closed set is replaced by a fixed constant or the
	 * whole report is dropped; it never passes through. **No `error.message`, no function name** is read.
	 *
	 * @since 2.0.2
	 */
	final class Browser_Event_Builder {

		const SOURCE_ERROR     = 'error';
		const SOURCE_REJECTION = 'unhandledrejection';
		const SOURCE_PICKUP    = 'pickup';

		/** Frames kept, innermost first. */
		const MAX_FRAMES = 30;

		/** Longest frame URL read. */
		const MAX_URL = 500;

		/** Largest line / column number kept (a minified bundle is a long single line). */
		const MAX_POSITION = 10000000;

		/** The standard JS error names; any other `error.name` is exported as {@see self::DEFAULT_ERROR_TYPE}. */
		const ERROR_TYPES = [ 'Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'EvalError', 'URIError', 'AggregateError' ];

		/** Exported for an error name outside {@see self::ERROR_TYPES}. */
		const DEFAULT_ERROR_TYPE = 'Error';

		/**
		 * The `code`s a `woodev_pickup_error` carries — the ones the pickup map's providers emit
		 * (`map-provider-yandex.js`, `map-provider-embedded.js`; D-14). The ONE definition: the browser
		 * sends whatever it holds, a code outside this list is exported as {@see self::UNKNOWN_CODE}.
		 */
		const PICKUP_CODES = [
			'map_script',
			'woodev_pickup_embed_invalid_payload',
			'woodev_pickup_embed_adapter_error',
			'woodev_pickup_embed_invalid_url',
			'woodev_pickup_embed_load_failed',
		];

		/** Exported for a pickup `code` outside {@see self::PICKUP_CODES}. */
		const UNKNOWN_CODE = 'unknown';

		/** @var Plugin_Scope */
		private Plugin_Scope $scope;

		/** @var array<string,string> */
		private array $context;

		/** @var array<string,string[]> Pickup field ids the handlers declared, by plugin id. */
		private array $pickup_fields = [];

		/**
		 * @param Plugin_Scope               $scope         Which plugins (and URLs) are ours.
		 * @param array<string,string>       $context       Emitted: site, framework_version, wp_version, wc_version, php_version, environment.
		 * @param array<string,array<mixed>> $pickup_fields Field ids the server knows, by plugin id (the `woodev_error_reporting_pickup_fields` filter's answer); anything not a list of strings is ignored.
		 */
		public function __construct( Plugin_Scope $scope, array $context, array $pickup_fields = [] ) {
			$this->scope   = $scope;
			$this->context = $context;

			foreach ( $pickup_fields as $plugin_id => $fields ) {
				if ( ! is_string( $plugin_id ) || ! is_array( $fields ) ) {
					continue;
				}

				$this->pickup_fields[ $plugin_id ] = array_values( array_filter( $fields, 'is_string' ) );
			}
		}

		/**
		 * Builds an event from a decoded browser payload.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $payload The decoded JSON body.
		 * @return array<string,mixed>|null Null when the report is malformed or not ours.
		 */
		public function from_payload( array $payload ): ?array {
			$source = $payload['source'] ?? '';

			if ( self::SOURCE_PICKUP === $source ) {
				return $this->from_pickup( $payload );
			}

			if ( self::SOURCE_ERROR === $source || self::SOURCE_REJECTION === $source ) {
				return $this->from_error( $payload, 'error' === $source ? 'onerror' : 'onunhandledrejection' );
			}

			return null;
		}

		/**
		 * A token: letters, digits, `_`, `.`, `-`, 1–64 characters — or null.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value Candidate.
		 * @return string|null
		 */
		public static function token( $value ): ?string {
			return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,64}$/D', $value ) ? $value : null;
		}

		/**
		 * @param array<string,mixed> $payload   Payload.
		 * @param string              $mechanism Sentry mechanism type.
		 * @return array<string,mixed>|null
		 */
		private function from_error( array $payload, string $mechanism ): ?array {
			$type = $payload['type'] ?? '';
			$type = is_string( $type ) && in_array( $type, self::ERROR_TYPES, true ) ? $type : self::DEFAULT_ERROR_TYPE;

			$frames = [];
			$owner  = null;

			$reported = is_array( $payload['frames'] ?? null ) ? $payload['frames'] : [];

			foreach ( $reported as $frame ) {
				$located = is_array( $frame ) ? $this->locate( $frame ) : null;

				if ( null === $located ) {
					continue;
				}

				$owner    = $owner ?? $located['owner']; // Innermost owned frame owns the event.
				$frames[] = $located['frame'];

				if ( count( $frames ) >= self::MAX_FRAMES ) {
					break;
				}
			}

			if ( null === $owner ) {
				return null;
			}

			return $this->event(
				$owner,
				[
					'type'       => $type,
					'mechanism'  => [
						'type'    => $mechanism,
						'handled' => false,
					],
					'stacktrace' => [ 'frames' => array_reverse( $frames ) ], // Sentry wants the oldest frame first.
				],
				[]
			);
		}

		/**
		 * `woodev_pickup_error`: `pluginId`, `fieldId`, `code` — nothing else is read, and each is checked
		 * against a set the server holds (registered plugins, declared fields, {@see self::PICKUP_CODES}).
		 *
		 * @param array<string,mixed> $payload Payload.
		 * @return array<string,mixed>|null
		 */
		private function from_pickup( array $payload ): ?array {
			$plugin_id = self::token( $payload['pluginId'] ?? null );
			$field_id  = self::token( $payload['fieldId'] ?? null );
			$code      = self::token( $payload['code'] ?? null );

			if ( null === $plugin_id || null === $field_id || null === $code ) {
				return null;
			}

			$owner = $this->scope->get( $plugin_id );

			// A field id is only as good as the server's own knowledge of it: a syntactically valid
			// «John.Smith» is a name, not a field. Unknown for this plugin → the report is dropped.
			if ( null === $owner || ! in_array( $field_id, $this->pickup_fields[ $plugin_id ] ?? [], true ) ) {
				return null;
			}

			// The code comes from a closed set; anything else (a phone number, a sentence) becomes a constant.
			$code = in_array( $code, self::PICKUP_CODES, true ) ? $code : self::UNKNOWN_CODE;

			return $this->event(
				$owner,
				[
					'type'      => 'woodev_pickup_error',
					'value'     => $plugin_id . ':' . $field_id . ':' . $code,
					'mechanism' => [
						'type'    => 'woodev_pickup_error',
						'handled' => true,
					],
				],
				[ 'field_id' => $field_id ]
			);
		}

		/**
		 * One reported frame: its owner and the anonymised entry — or null when it is not a real script of ours.
		 *
		 * @param array<string,mixed> $frame Client frame: url, line, col.
		 * @return array{owner:array{id:string,version:string},frame:array<string,mixed>}|null
		 */
		private function locate( array $frame ): ?array {
			$url = $frame['url'] ?? '';

			if ( ! is_string( $url ) || strlen( $url ) > self::MAX_URL ) {
				return null;
			}

			// The path is the one the filesystem reports for a script that exists; the position is a
			// number. Nothing else of the frame is read — in particular no function name.
			$located = $this->scope->locate_url( $url );

			if ( null === $located ) {
				return null;
			}

			return [
				'owner' => [
					'id'      => $located['id'],
					'version' => $located['version'],
				],
				'frame' => [
					'filename' => $located['path'],
					'lineno'   => self::position( $frame['line'] ?? 0 ),
					'colno'    => self::position( $frame['col'] ?? 0 ),
					'in_app'   => true,
				],
			];
		}

		/**
		 * @param mixed $value Candidate line or column.
		 * @return int
		 */
		private static function position( $value ): int {
			return is_int( $value ) ? max( 0, min( self::MAX_POSITION, $value ) ) : 0;
		}

		/**
		 * @param array{id:string,version:string} $owner      Owning plugin.
		 * @param array<string,mixed>             $exception  The exception value.
		 * @param array<string,string>            $extra_tags Extra tags.
		 * @return array<string,mixed>
		 */
		private function event( array $owner, array $exception, array $extra_tags ): array {
			$tags = array_filter(
				array_merge(
					[
						'plugin'            => $owner['id'],
						'plugin_version'    => $owner['version'],
						'framework_version' => $this->context['framework_version'] ?? '',
						'wp_version'        => $this->context['wp_version'] ?? '',
						'wc_version'        => $this->context['wc_version'] ?? '',
						'php_version'       => $this->context['php_version'] ?? '',
						'site'              => $this->context['site'] ?? '',
						'source'            => 'browser',
					],
					$extra_tags
				),
				static function ( string $tag ): bool {
					return '' !== $tag;
				}
			);

			return [
				'event_id'    => bin2hex( random_bytes( 16 ) ),
				'timestamp'   => time(),
				'platform'    => 'javascript',
				'level'       => 'error',
				'logger'      => 'woodev',
				'release'     => $owner['id'] . '@' . $owner['version'],
				'environment' => $this->context['environment'] ?? 'production',
				'server_name' => $this->context['site'] ?? '',
				'sdk'         => [
					'name'    => Event_Builder::SDK_NAME,
					'version' => Event_Builder::SDK_VERSION,
				],
				'tags'        => $tags,
				'exception'   => [ 'values' => [ $exception ] ],
			];
		}
	}

endif;
