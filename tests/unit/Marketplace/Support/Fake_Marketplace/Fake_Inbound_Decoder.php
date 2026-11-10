<?php
/**
 * Generic push decoder.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Ledger\Inbound_Decoder;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

/**
 * Decodes `{"type":"...","id":"..."}`. Wildberries has no push, so its adapter never hands this out; the class exists to
 * show the decoder contract is implementable (null for noise, a stable dedup key, the success body).
 */
final class Fake_Inbound_Decoder implements Inbound_Decoder {
	/**
	 * Clock.
	 *
	 * @var Fake_Clock
	 */
	private $clock;

	/**
	 * Creates the decoder.
	 *
	 * @param Fake_Clock $clock Clock.
	 */
	public function __construct( Fake_Clock $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Decodes a body.
	 *
	 * @param string   $raw_body   Body.
	 * @param int|null $account_id Account id.
	 * @param string   $ip         Sender.
	 * @return Inbound_Event|null
	 */
	public function decode( string $raw_body, ?int $account_id, string $ip ): ?Inbound_Event {
		$data = json_decode( $raw_body, true );
		if ( ! is_array( $data ) || empty( $data['type'] ) || ! isset( $data['id'] ) ) {
			return null;
		}

		return new Inbound_Event( $account_id, (string) $data['type'], sha1( $data['type'] . '|' . $data['id'] ), $data, $this->clock->now(), $ip, false );
	}

	/**
	 * Returns the success answer.
	 *
	 * @return array<string,mixed>
	 */
	public function success_body(): array {
		return array( 'result' => true );
	}
}
