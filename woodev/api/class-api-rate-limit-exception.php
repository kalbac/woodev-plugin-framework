<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_API_Rate_Limit_Exception' ) ) :

	/**
	 * The remote service answered HTTP 429: it is throttling us and did NOT act on the request.
	 *
	 * Thrown by {@see Woodev_API_Base::handle_response()} in place of the plain
	 * {@see Woodev_API_Exception} a carrier's own validation threw for a 429. It is deliberately
	 * NOT a {@see Woodev_API_Transport_Exception}: a transport failure means «the server may have
	 * acted», while a 429 is a definite «not now» — nothing was created, so the call is safe to
	 * repeat without asking the carrier first, and it is not a refusal either, because a later
	 * attempt can succeed. It carries the wait the server asked for in `Retry-After` (#954).
	 *
	 * @since 2.0.2
	 */
	class Woodev_API_Rate_Limit_Exception extends Woodev_API_Exception {

		/** @var int the longest wait honoured, in seconds — a server asking for more is clamped to a day */
		public const MAX_RETRY_AFTER = 86400;

		/** @var int|null seconds the server asked us to wait, or null when it did not say */
		private ?int $retry_after;

		/**
		 * @param string          $message     the message
		 * @param int             $code        the HTTP status, 429
		 * @param \Throwable|null $previous    the exception this one re-types
		 * @param int|null        $retry_after seconds to wait, or null when the response had no usable `Retry-After`
		 */
		public function __construct( string $message = '', int $code = 429, ?\Throwable $previous = null, ?int $retry_after = null ) {
			parent::__construct( $message, $code, $previous );

			$this->retry_after = $retry_after;
		}

		/**
		 * The wait the server asked for.
		 *
		 * @since 2.0.2
		 *
		 * @return int|null seconds (0 to {@see self::MAX_RETRY_AFTER}), or null when the response carried no usable `Retry-After`
		 */
		public function get_retry_after(): ?int {
			return $this->retry_after;
		}

		/**
		 * Reads a `Retry-After` header value: a number of seconds or an HTTP date (RFC 9110 §10.2.3).
		 *
		 * @since 2.0.2
		 *
		 * @param string|null $value the raw header value
		 * @param int|null    $now   the current unix time — a seam for tests; defaults to `time()`
		 * @return int|null seconds to wait, clamped to 0…{@see self::MAX_RETRY_AFTER}; null when the header is absent or unreadable
		 */
		public static function parse_retry_after( ?string $value, ?int $now = null ): ?int {

			$value = trim( (string) $value );

			if ( '' === $value ) {
				return null;
			}

			if ( ctype_digit( $value ) ) {
				// A run of digits too long for an int would overflow in the cast: it is «a very long time» anyway.
				$seconds = strlen( $value ) > 9 ? self::MAX_RETRY_AFTER : (int) $value;
			} else {
				// An HTTP date names its day and month; strtotime() alone would read «-5» or «1.5» as some relative time.
				$timestamp = 1 === preg_match( '/[a-z]{3}/i', $value ) ? strtotime( $value ) : false;

				if ( false === $timestamp ) {
					return null;
				}

				$seconds = $timestamp - ( $now ?? time() );
			}

			return max( 0, min( self::MAX_RETRY_AFTER, $seconds ) );
		}
	}

endif;
