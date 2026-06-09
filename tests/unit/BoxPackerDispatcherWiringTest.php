<?php
/**
 * Guards the production load path for the box-packer dispatcher.
 *
 * Woodev_Plugin::includes() must require the dispatcher, its input/output
 * contracts, and the packable-item interface unconditionally, and must require
 * the WooCommerce-aware dispatcher only behind an is_woocommerce_active() gate.
 * Without these requires a real plugin calling the dispatcher in production
 * fatals (the test autoloader masks the gap). This source-assertion test reads
 * woodev/class-plugin.php as a string and locks the wiring in place.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit {

	class BoxPackerDispatcherWiringTest extends TestCase {

		/**
		 * Reads the production plugin class source.
		 *
		 * @return string
		 */
		private function plugin_source(): string {
			return (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-plugin.php' );
		}

		/**
		 * The platform-neutral dispatcher and its contracts must be required
		 * unconditionally inside includes().
		 *
		 * @return void
		 */
		public function test_includes_requires_dispatcher_and_contracts(): void {
			$source = $this->plugin_source();

			foreach (
				[
					'/box-packer/interfaces/interface-packer-packable-item.php',
					'/box-packer/class-packer-input-item.php',
					'/box-packer/class-packer-package-result.php',
					'/box-packer/class-packer-result.php',
					'/box-packer/class-packer-dispatcher.php',
				] as $relative_path
			) {
				$this->assertStringContainsString(
					"require_once \$framework_path . '" . $relative_path . "';",
					$source,
					"class-plugin.php must require_once {$relative_path}."
				);
			}
		}

		/**
		 * The WooCommerce-aware dispatcher require must live behind an
		 * is_woocommerce_active() gate, never required unconditionally.
		 *
		 * @return void
		 */
		public function test_wc_dispatcher_require_is_woocommerce_gated(): void {
			$source = $this->plugin_source();

			$wc_require = "require_once \$framework_path . '/box-packer/class-wc-packer-dispatcher.php';";

			$this->assertStringContainsString(
				$wc_require,
				$source,
				'class-plugin.php must require_once the WooCommerce-aware dispatcher.'
			);

			// The require must appear inside the literal gated block.
			$gated_block = "if ( Woodev_Helper::is_woocommerce_active() ) {\n\t\t\t\t" . $wc_require;

			$this->assertStringContainsString(
				$gated_block,
				$source,
				'The WooCommerce-aware dispatcher require must be guarded by Woodev_Helper::is_woocommerce_active().'
			);

			// And it must not be required unconditionally (only the single gated occurrence).
			$this->assertSame(
				1,
				substr_count( $source, $wc_require ),
				'The WooCommerce-aware dispatcher must be required exactly once, inside the is_woocommerce_active() gate.'
			);
		}
	}

}
