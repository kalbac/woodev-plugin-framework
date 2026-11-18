<?php
/**
 * Notification endpoint rules of the fake marketplace that also names the types it accepts without a switch.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Intake\Names_Event_Types;

/**
 * The same protocol as {@see Fake_Push_Protocol}, plus the names of the event types the decoder handles.
 */
final class Fake_Named_Push_Protocol implements \Woodev\Framework\Marketplace\Intake\Push_Protocol, Names_Event_Types {
	/**
	 * The protocol the calls are delegated to.
	 *
	 * @var Fake_Push_Protocol
	 */
	private $inner;

	/**
	 * Names by event type.
	 *
	 * @var array<string,string>
	 */
	private $names;

	/**
	 * Creates the protocol.
	 *
	 * @param Fake_Push_Protocol  $inner The protocol to delegate to.
	 * @param array<string,string> $names Names by event type.
	 */
	public function __construct( Fake_Push_Protocol $inner, array $names ) {
		$this->inner = $inner;
		$this->names = $names;
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
	 * Returns the names of the handled event types.
	 *
	 * @return array<string,string>
	 */
	public function event_names(): array {
		return $this->names;
	}
}
