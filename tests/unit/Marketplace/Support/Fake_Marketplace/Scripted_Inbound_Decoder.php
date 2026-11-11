<?php
/**
 * Notification decoder of a fake marketplace that has push.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Ledger\Inbound_Decoder;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

/**
 * Decodes `{"kind": …, "id": …, "cabinet": …}`, the body of a marketplace invented for the intake tests:
 *
 * - `kind = ping` is noise (null);
 * - a body that is not JSON, or a kind outside {@see self::KINDS}, is stored as ignored with the reason;
 * - an event no account was resolved for is stored as ignored «unknown account»;
 * - the dedup key is the kind and the id, so the same notification sent twice is one event whatever its `time` says;
 * - the address is judged by the intake, so the decoder reports it as not trusted.
 */
final class Scripted_Inbound_Decoder implements Inbound_Decoder {
	public const KINDS = [ 'order_created', 'order_changed', 'stock_changed' ];

	public const SUCCESS = [
		'accepted' => true,
		'by'       => 'fake marketplace intake',
	];

	/**
	 * Decodes a body.
	 *
	 * @param string   $raw_body   Body.
	 * @param int|null $account_id Account id.
	 * @param string   $ip         Sender.
	 * @return Inbound_Event|null
	 */
	public function decode( string $raw_body, ?int $account_id, string $ip ): ?Inbound_Event {
		$data    = json_decode( $raw_body, true );
		$trusted = false; // The intake decides that, not the decoder.
		$at      = new \DateTimeImmutable( '2026-11-08T12:00:00Z' );

		if ( ! is_array( $data ) ) {
			return ( new Inbound_Event( $account_id, 'unreadable', sha1( $raw_body ), [ 'raw' => $raw_body ], $at, $ip, $trusted ) )->with_ignore_reason( 'invalid json' );
		}

		$kind = (string) ( $data['kind'] ?? '' );

		if ( 'ping' === $kind ) {
			return null;
		}

		$event = new Inbound_Event( $account_id, $kind, sha1( $kind . '|' . (string) ( $data['id'] ?? '' ) ), $data, $at, $ip, $trusted );

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return $event->with_ignore_reason( 'unsupported type' );
		}

		return null === $account_id ? $event->with_ignore_reason( 'unknown account' ) : $event;
	}

	/**
	 * Returns the success answer.
	 *
	 * @return array<string,mixed>
	 */
	public function success_body(): array {
		return self::SUCCESS;
	}
}
