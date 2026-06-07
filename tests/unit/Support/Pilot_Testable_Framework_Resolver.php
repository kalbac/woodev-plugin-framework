<?php
/**
 * Shared testable framework resolver for the pilot-fixture unit tests.
 *
 * @package Woodev\Tests\Unit\Support
 */

namespace Woodev\Tests\Unit\Support;

require_once dirname( __DIR__, 3 ) . '/woodev/class-framework-resolver.php';

/**
 * Test resolver exposing a controlled WooCommerce version and framework path.
 *
 * Extracted from the pilot-fixture tests, which each previously declared an
 * identical resolver subclass. Each fixture supplies its own framework path
 * base via the constructor (defaulting to the repository root).
 */
class Pilot_Testable_Framework_Resolver extends \Woodev\Framework\Framework_Resolver {

	/** @var string|null WooCommerce version used for resolver assertions. */
	public ?string $wc_version = null;

	/** @var string Framework path base returned to the loader. */
	private string $plugin_path;

	/**
	 * Constructor.
	 *
	 * @param string|null $plugin_path Framework path base; defaults to the repository root.
	 */
	public function __construct( ?string $plugin_path = null ) {
		parent::__construct();

		$this->plugin_path = $plugin_path ?? dirname( __DIR__, 3 );
	}

	/**
	 * Returns the configured framework path base.
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	public function get_plugin_path( string $file ): string {
		return $this->plugin_path;
	}

	/**
	 * Gets the test WooCommerce version.
	 *
	 * @return string|null
	 */
	protected function get_wc_version(): ?string {
		return $this->wc_version;
	}
}
