<?php
/**
 * How one marketplace's notification endpoint talks.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * The marketplace's half of the notification endpoint, next to its decoder
 * ({@see \Woodev\Framework\Marketplace\Ledger\Inbound_Decoder}): which address a request came from, whether that address is the
 * marketplace's, and the body the marketplace expects with an error status. The intake decides *when* to refuse; this decides
 * what a refusal looks like, because every marketplace documents its own error format and reads it.
 *
 * @since 2.1.0
 */
interface Push_Protocol {
	/**
	 * Returns the address the request came from, read from the server variables the marketplace's network is known to set
	 * (the peer address, or a forwarded one behind the shop's trusted proxies).
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $server Request server variables.
	 * @return string An IP address, or '' when none can be trusted.
	 */
	public function client_ip( array $server ): string;

	/**
	 * Tells whether an address belongs to the marketplace. Asked only for a request that carries no token: with a token the
	 * address is recorded on the event and never refuses it.
	 *
	 * @since 2.1.0
	 *
	 * @param string $ip Sender address.
	 * @return bool
	 */
	public function is_allowed( string $ip ): bool;

	/**
	 * Returns the newline-separated IP/CIDR list the settings screen offers as the allowlist until the merchant edits it, or ''
	 * when the marketplace publishes no addresses. The saved list is read back under {@see \Woodev\Framework\Marketplace\Settings\Notifications_Section::option_names()}.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function default_ip_allowlist(): string;

	/**
	 * Returns the body of an error answer in the marketplace's documented format.
	 *
	 * @since 2.1.0
	 *
	 * @param string $reason  One of {@see Intake_Response}'s `REASON_*` constants.
	 * @param string $message Short English text of what went wrong.
	 * @return array<string,mixed>
	 */
	public function error_body( string $reason, string $message ): array;
}
