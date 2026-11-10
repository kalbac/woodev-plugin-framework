<?php
/**
 * Inbound notification decoder contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the raw body of a marketplace push into an `Inbound_Event`. Marketplaces without push (Wildberries) return null
 * from `Marketplace_Adapter::inbound_decoder()` and rely on polling.
 *
 * @since 2.0.29
 */
interface Inbound_Decoder {
	/**
	 * Decodes a request body.
	 *
	 * @since 2.0.29
	 *
	 * @param string   $raw_body   Raw request body.
	 * @param int|null $account_id Account matched by the endpoint URL token, or null when none matched.
	 * @param string   $ip         Sender address.
	 * @return Inbound_Event|null Null means «ignore this request» (a ping, an unknown type, malformed JSON).
	 */
	public function decode( string $raw_body, ?int $account_id, string $ip ): ?Inbound_Event;

	/**
	 * Returns the body the marketplace expects in the HTTP 200 answer.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function success_body(): array;
}
