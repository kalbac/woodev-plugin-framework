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
	 * Nothing the client says is trusted: every field is re-validated against a strict pattern, every
	 * frame URL is re-checked against the registered plugins' URL roots ({@see Plugin_Scope::locate_url()})
	 * and rewritten to a relative path, and the only free text that can reach the event is an identifier
	 * made of the characters the patterns allow. **No `error.message` is read or accepted** — a report
	 * carries the error type, the frames of OUR scripts, or the three tokens of a domain event.
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

		/** @var Plugin_Scope */
		private Plugin_Scope $scope;

		/** @var array<string,string> */
		private array $context;

		/**
		 * @param Plugin_Scope         $scope   Which plugins (and URLs) are ours.
		 * @param array<string,string> $context Emitted: site, framework_version, wp_version, wc_version, php_version, environment.
		 */
		public function __construct( Plugin_Scope $scope, array $context ) {
			$this->scope   = $scope;
			$this->context = $context;
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
			$type = is_string( $type ) && 1 === preg_match( '/^[A-Za-z_$][\w$]{0,63}$/D', $type ) ? $type : 'Error';

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
		 * `woodev_pickup_error`: the three tokens `pluginId`, `fieldId`, `code` — nothing else is read.
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

			if ( null === $owner ) {
				return null;
			}

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
		 * One reported frame: its owner and the anonymised entry — or null when it is not ours.
		 *
		 * @param array<string,mixed> $frame Client frame: url, line, col, fn.
		 * @return array{owner:array{id:string,version:string},frame:array<string,mixed>}|null
		 */
		private function locate( array $frame ): ?array {
			$url = $frame['url'] ?? '';

			if ( ! is_string( $url ) || strlen( $url ) > self::MAX_URL ) {
				return null;
			}

			$located = $this->scope->locate_url( $url );

			if ( null === $located ) {
				return null;
			}

			$entry = [
				'filename' => $located['path'],
				'lineno'   => self::position( $frame['line'] ?? 0 ),
				'colno'    => self::position( $frame['col'] ?? 0 ),
				'in_app'   => true,
			];

			$function = $frame['fn'] ?? '';

			if ( is_string( $function ) && 1 === preg_match( '/^[\w$.<>\[\]-]{1,100}$/D', $function ) ) {
				$entry['function'] = $function;
			}

			return [
				'owner' => [
					'id'      => $located['id'],
					'version' => $located['version'],
				],
				'frame' => $entry,
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
