<?php
/**
 * Sentry envelope transport.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Transport' ) ) :

	/**
	 * POSTs one event as a Sentry envelope. Called only from {@see Dispatcher} in a cron
	 * request, never from the request that failed — so it waits for the answer (short timeout)
	 * instead of pretending to be asynchronous. Never throws; a failure is a silent `false`.
	 *
	 * @since 2.0.2
	 */
	final class Transport {

		const CLIENT = 'woodev-error-reporter/' . Event_Builder::SDK_VERSION;

		/**
		 * Sends an event.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $event Event payload.
		 * @param Dsn                 $dsn   Receiver.
		 * @return bool True when the HTTP layer delivered the request without an error.
		 */
		public function send( array $event, Dsn $dsn ): bool {
			$body = $this->build_envelope( $event );

			if ( '' === $body ) {
				return false;
			}

			$response = wp_remote_post(
				$dsn->get_envelope_url(),
				[
					'timeout'     => 3,
					'blocking'    => true,
					'redirection' => 0,
					'user-agent'  => self::CLIENT,
					'headers'     => [
						'Content-Type'  => 'application/x-sentry-envelope',
						'X-Sentry-Auth' => $dsn->get_auth_header( self::CLIENT ),
					],
					'body'        => $body,
				]
			);

			return ! is_wp_error( $response );
		}

		/**
		 * The newline-delimited envelope: envelope header, item header (with byte length), payload.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $event Event payload.
		 * @return string Empty when the event cannot be encoded.
		 */
		public function build_envelope( array $event ): string {
			$flags   = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
			$payload = wp_json_encode( $event, $flags );
			$header  = wp_json_encode(
				[
					'event_id' => $event['event_id'] ?? '',
					'sent_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
				],
				$flags
			);

			if ( false === $payload || false === $header ) {
				return '';
			}

			$item = wp_json_encode(
				[
					'type'   => 'event',
					'length' => strlen( $payload ),
				],
				$flags
			);

			return $header . "\n" . $item . "\n" . $payload . "\n";
		}
	}

endif;
