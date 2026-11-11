<?php
/**
 * Names of a marketplace plugin's background jobs.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Every marketplace plugin runs the same jobs, each under its own names, so two plugins on one site never share an Action
 * Scheduler hook, a filter or a transient: `woodev_{plugin id}_{job}` in the group `woodev-{plugin id}`.
 *
 *     ( new Job_Names( 'ozon-seller' ) )->hook( 'flush_sync' ); // woodev_ozon_seller_flush_sync
 *     ( new Job_Names( 'ozon-seller' ) )->group();              // woodev-ozon-seller
 *
 * @since 2.1.0
 */
final class Job_Names {
	/**
	 * Plugin id in lower case with underscores.
	 *
	 * @var string
	 */
	private $plugin_id;

	/**
	 * Creates the names of a plugin.
	 *
	 * @since 2.1.0
	 *
	 * @param string $plugin_id Plugin id, as `Woodev_Plugin::get_id()` gives it (dashes or underscores).
	 * @throws \InvalidArgumentException When the id is not lower-case words joined by dashes or underscores.
	 */
	public function __construct( string $plugin_id ) {
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $plugin_id ) ) {
			throw new \InvalidArgumentException( 'A plugin id must be lower-case words joined by dashes or underscores.' );
		}

		$this->plugin_id = str_replace( '-', '_', $plugin_id );
	}

	/**
	 * Returns the name of a job's hook; filters and transients of a job are named the same way.
	 *
	 * @since 2.1.0
	 *
	 * @param string $job Job name in snake case (`flush_sync`, `drain_events`).
	 * @return string
	 */
	public function hook( string $job ): string {
		return 'woodev_' . $this->plugin_id . '_' . $job;
	}

	/**
	 * Returns the Action Scheduler group of the plugin's jobs.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function group(): string {
		return 'woodev-' . str_replace( '_', '-', $this->plugin_id );
	}
}
