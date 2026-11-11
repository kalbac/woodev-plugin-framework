<?php
/**
 * Event handlers by type.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Maps an event type to its handler; a type nobody registered gets the no-op handler. The event types are the marketplace's
 * own names (what its {@see \Woodev\Framework\Marketplace\Ledger\Inbound_Decoder} puts into the event), so the registry is
 * filled by whoever knows them: the flows of the framework for the types they serve, the plugin for the rest.
 *
 * @since 2.0.30
 */
final class Event_Handler_Registry {
	/**
	 * Handlers by type.
	 *
	 * @var array<string,Event_Handler>
	 */
	private $handlers = [];

	/**
	 * Fallback.
	 *
	 * @var Event_Handler
	 */
	private $fallback;

	/**
	 * Creates the registry.
	 *
	 * @since 2.0.30
	 *
	 * @param Event_Handler|null $fallback Handler for an unregistered type; the no-op handler when null.
	 */
	public function __construct( ?Event_Handler $fallback = null ) {
		$this->fallback = $fallback ?? new Noop_Event_Handler();
	}

	/**
	 * Registers the handler of a type, replacing an earlier one.
	 *
	 * @since 2.0.30
	 *
	 * @param string        $type    Event type, as the decoder names it.
	 * @param Event_Handler $handler Handler.
	 * @return void
	 */
	public function register( string $type, Event_Handler $handler ): void {
		$this->handlers[ $type ] = $handler;
	}

	/**
	 * Returns the handler of a type.
	 *
	 * @since 2.0.30
	 *
	 * @param string $type Event type.
	 * @return Event_Handler
	 */
	public function for_type( string $type ): Event_Handler {
		return $this->handlers[ $type ] ?? $this->fallback;
	}
}
