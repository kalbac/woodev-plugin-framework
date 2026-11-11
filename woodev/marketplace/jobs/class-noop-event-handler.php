<?php
/**
 * Placeholder event handler.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts an event and does nothing: the answer for a type no handler was registered for, so an event the marketplace starts
 * to send before the plugin knows it is stored and closed instead of retried forever.
 *
 * @since 2.1.0
 */
final class Noop_Event_Handler implements Event_Handler {
	/**
	 * Processes one event.
	 *
	 * @since 2.1.0
	 *
	 * @param Inbound_Event $event Claimed event.
	 * @return string
	 */
	public function handle( Inbound_Event $event ): string {
		return 'no handler registered yet for ' . $event->type();
	}
}
