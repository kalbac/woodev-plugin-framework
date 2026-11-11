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
 * ({@see \Woodev\Framework\Marketplace\Ledger\Inbound_Decoder}): the addresses the marketplace sends from, the kinds of
 * notification it pushes, and the body it expects with an error status. The intake decides *when* to refuse; this decides what a
 * refusal looks like, because every marketplace documents its own error format and reads it. Where a request came from and
 * whether that address is the marketplace's is {@see Sender_Network}'s job: the merchant decides none of it.
 *
 * @since 2.1.0
 */
interface Push_Protocol {
	/**
	 * Returns the marketplace's published sender addresses, a constant of the adapter. A developer can extend the list with the
	 * `woodev_{marketplace id}_push_ip_allowlist` filter ({@see Sender_Network}); the merchant has no setting for it.
	 *
	 * @since 2.1.2
	 *
	 * @return string Newline-separated IP/CIDR list, or '' when the marketplace publishes no addresses.
	 */
	public function default_ip_allowlist(): string;

	/**
	 * Returns the kinds of notification the marketplace pushes. The settings screen shows a switch with an explanation for each, and
	 * the intake drops a notification whose switch is off.
	 *
	 * @since 2.1.2
	 *
	 * @return Webhook_Type[] Empty when the merchant has nothing to choose.
	 */
	public function webhook_types(): array;

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
