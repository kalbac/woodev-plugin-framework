<?php
/**
 * Records engine — one registered table.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * A provider together with the owner it was registered under and the capability that
 * gates every request to it.
 *
 * @since 2.0.29
 */
final class Records_Registration {
	/**
	 * @var string
	 */
	private $owner;

	/**
	 * @var Records_Provider
	 */
	private $provider;

	/**
	 * @var string
	 */
	private $capability;

	/**
	 * @param string           $owner      Owner (plugin id).
	 * @param Records_Provider $provider   Provider.
	 * @param string           $capability Capability required for any request to the table.
	 */
	public function __construct( string $owner, Records_Provider $provider, string $capability ) {
		$this->owner      = $owner;
		$this->provider   = $provider;
		$this->capability = $capability;
	}

	/**
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function get_owner(): string {
		return $this->owner;
	}

	/**
	 * @since 2.0.29
	 *
	 * @return Records_Provider
	 */
	public function get_provider(): Records_Provider {
		return $this->provider;
	}

	/**
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function get_capability(): string {
		return $this->capability;
	}
}
