<?php
/**
 * Notification endpoint rules of the fake marketplace that also refuses some decoded notifications.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Intake\Intake_Response;
use Woodev\Framework\Marketplace\Intake\Rejects_Inbound_Events;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

/**
 * The same protocol as {@see Fake_Push_Protocol}, plus a rejection: an unreadable body is refused as invalid JSON and a type the
 * fake marketplace does not document as an unknown type (both 400); a type of `order_changed` is refused with the scripted
 * status and reason, so a test can hand the intake an out-of-range status or a reason it has no text for.
 */
final class Fake_Rejecting_Push_Protocol implements \Woodev\Framework\Marketplace\Intake\Push_Protocol, Rejects_Inbound_Events {
	/**
	 * The protocol the calls are delegated to.
	 *
	 * @var Fake_Push_Protocol
	 */
	private $inner;

	/**
	 * What an `order_changed` event is refused with, or null to accept it.
	 *
	 * @var array{status:int,reason:string}|null
	 */
	public $order_changed = null;

	/**
	 * Creates the protocol.
	 */
	public function __construct() {
		$this->inner = new Fake_Push_Protocol();
	}

	/**
	 * Returns the published addresses.
	 *
	 * @return string
	 */
	public function default_ip_allowlist(): string {
		return $this->inner->default_ip_allowlist();
	}

	/**
	 * Returns the kinds of notification.
	 *
	 * @return \Woodev\Framework\Marketplace\Intake\Webhook_Type[]
	 */
	public function webhook_types(): array {
		return $this->inner->webhook_types();
	}

	/**
	 * Returns the error body.
	 *
	 * @param string $reason  Reason.
	 * @param string $message Text.
	 * @return array<string,mixed>
	 */
	public function error_body( string $reason, string $message ): array {
		return $this->inner->error_body( $reason, $message );
	}

	/**
	 * Refuses what the fake marketplace documents as wrong.
	 *
	 * @param Inbound_Event $event Decoded event.
	 * @return array{status:int,reason:string}|null
	 */
	public function rejection( Inbound_Event $event ): ?array {
		if ( 'invalid json' === $event->ignore_reason() ) {
			return [
				'status' => 400,
				'reason' => Intake_Response::REASON_INVALID_JSON,
			];
		}

		if ( 'unsupported type' === $event->ignore_reason() ) {
			return [
				'status' => 400,
				'reason' => Intake_Response::REASON_UNKNOWN_TYPE,
			];
		}

		return 'order_changed' === $event->type() ? $this->order_changed : null;
	}
}
