<?php
/**
 * A marketplace that wants some decoded notifications answered with an error.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

defined( 'ABSPATH' ) || exit;

/**
 * Optional half of {@see Push_Protocol}: lets the adapter refuse a notification the decoder has already read. Some marketplaces
 * check a URL by sending deliberately wrong pushes (a type that does not exist, a body that is not JSON) and expect an error
 * status back; the intake answers 200 for everything it decoded, which such a check reads as «the endpoint accepts anything».
 *
 * The event is still recorded (the merchant's diagnostics keep the row) and never worked; only the answer changes, to the
 * protocol's own error body ({@see Push_Protocol::error_body()}). A protocol that does not implement this keeps today's
 * behaviour: every decoded event is answered 200. Refuse only what the marketplace documents as wrong: a 4xx counts against the
 * URL in the marketplace's health check, so a real notification of a type the plugin merely does not handle stays a 200.
 *
 * @since 2.1.18
 */
interface Rejects_Inbound_Events {
	/**
	 * Tells whether a decoded notification is answered with an error, and which.
	 *
	 * @since 2.1.18
	 *
	 * @param Inbound_Event $event The decoded event, with the decoder's `ignore_reason()` when it set one.
	 * @return array{status:int,reason:string}|null Null to answer as usual. Otherwise the HTTP status (4xx or 5xx; anything else
	 *                                              is treated as 400) and one of {@see Intake_Response}'s `REASON_*` constants,
	 *                                              which the intake passes to {@see Push_Protocol::error_body()}.
	 */
	public function rejection( Inbound_Event $event ): ?array;
}
