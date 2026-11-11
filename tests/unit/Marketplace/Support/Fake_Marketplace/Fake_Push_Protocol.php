<?php
/**
 * Notification endpoint rules of the fake marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Intake\Push_Protocol;

/**
 * The sender is the peer address; the marketplace's network is `203.0.113.0/24`; an error is `{"fault": reason, "text": …}` —
 * a format no real marketplace uses, so a test proves the intake prints what the protocol gave it.
 */
final class Fake_Push_Protocol implements Push_Protocol {
	public const TRUSTED_IP = '203.0.113.7';
	public const FOREIGN_IP = '198.51.100.9';

	/**
	 * Returns the sender's address.
	 *
	 * @param array<string,mixed> $server Server variables.
	 * @return string
	 */
	public function client_ip( array $server ): string {
		return (string) ( $server['REMOTE_ADDR'] ?? '' );
	}

	/**
	 * Tells whether an address is the marketplace's.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public function is_allowed( string $ip ): bool {
		return 0 === strpos( $ip, '203.0.113.' );
	}

	/**
	 * Returns the error body.
	 *
	 * @param string $reason  Reason.
	 * @param string $message Text.
	 * @return array<string,mixed>
	 */
	public function error_body( string $reason, string $message ): array {
		return [
			'fault' => $reason,
			'text'  => $message,
		];
	}
}
