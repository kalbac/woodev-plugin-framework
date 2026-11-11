<?php
/**
 * Handler of one event type.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

defined( 'ABSPATH' ) || exit;

/**
 * Does the work an inbound event asks for. The body of an event is never trusted for business data beyond identifiers: a
 * handler re-reads the state from the marketplace.
 *
 * A handler runs while the runner holds the event's claim. A side effect outside our tables happens only right after the claim
 * was checked, which the handler that has the side effect owns.
 *
 * @since 2.1.0
 */
interface Event_Handler {
	/**
	 * Processes one event.
	 *
	 * @since 2.1.0
	 *
	 * @param Inbound_Event $event Claimed event.
	 * @return string Short result text, stored on the row.
	 * @throws \Throwable On any failure; the runner schedules a retry.
	 */
	public function handle( Inbound_Event $event ): string;
}
