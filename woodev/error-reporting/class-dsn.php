<?php
/**
 * Sentry-compatible DSN value object.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Dsn' ) ) :

	/**
	 * A parsed `scheme://public_key@host[:port][/prefix]/project_id` DSN.
	 *
	 * Self-hosted GlitchTip speaks the Sentry envelope protocol, so the same value object
	 * serves both. Only what the envelope transport needs is kept: the endpoint URL and the
	 * public key. A legacy `public:secret@` pair is accepted but the secret is dropped — the
	 * envelope endpoint does not need it and it must never travel from a merchant's site.
	 *
	 * @since 2.0.2
	 */
	final class Dsn {

		/** @var string */
		private string $scheme;

		/** @var string */
		private string $host;

		/** @var int|null */
		private ?int $port;

		/** @var string Path prefix before `/api/`, without a trailing slash. */
		private string $prefix;

		/** @var string */
		private string $project_id;

		/** @var string */
		private string $public_key;

		/**
		 * @param string   $scheme     `http` or `https`.
		 * @param string   $host       Receiver host.
		 * @param int|null $port       Explicit port, if any.
		 * @param string   $prefix     Path prefix (empty for a root install).
		 * @param string   $project_id Project id (last path segment).
		 * @param string   $public_key Public key (user part).
		 */
		private function __construct( string $scheme, string $host, ?int $port, string $prefix, string $project_id, string $public_key ) {
			$this->scheme     = $scheme;
			$this->host       = $host;
			$this->port       = $port;
			$this->prefix     = $prefix;
			$this->project_id = $project_id;
			$this->public_key = $public_key;
		}

		/**
		 * Parses a DSN string.
		 *
		 * @since 2.0.2
		 *
		 * @param string $dsn Raw DSN.
		 * @return self|null Null when the string is not a usable DSN.
		 */
		public static function parse( string $dsn ): ?self {
			$parts = parse_url( trim( $dsn ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WP-independent on purpose, runs at shutdown.

			if ( ! is_array( $parts ) ) {
				return null;
			}

			$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
			$host   = isset( $parts['host'] ) ? (string) $parts['host'] : '';
			$key    = isset( $parts['user'] ) ? (string) $parts['user'] : '';
			$path   = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';

			if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host || '' === $key || '' === $path ) {
				return null;
			}

			$segments   = explode( '/', $path );
			$project_id = (string) array_pop( $segments );

			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $project_id ) ) {
				return null;
			}

			$prefix = [] === $segments ? '' : '/' . implode( '/', $segments );
			$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;

			return new self( $scheme, $host, $port, $prefix, $project_id, $key );
		}

		/**
		 * The envelope ingest URL: `<origin><prefix>/api/<project_id>/envelope/`.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_envelope_url(): string {
			$origin = $this->scheme . '://' . $this->host . ( null !== $this->port ? ':' . $this->port : '' );

			return $origin . $this->prefix . '/api/' . $this->project_id . '/envelope/';
		}

		/**
		 * The public key.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_public_key(): string {
			return $this->public_key;
		}

		/**
		 * The `X-Sentry-Auth` header value (protocol version 7).
		 *
		 * @since 2.0.2
		 *
		 * @param string $client Client name/version, arbitrary.
		 * @return string
		 */
		public function get_auth_header( string $client ): string {
			return sprintf( 'Sentry sentry_version=7, sentry_client=%s, sentry_key=%s', $client, $this->public_key );
		}
	}

endif;
