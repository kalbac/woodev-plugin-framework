<?php
/**
 * Unit tests for Woodev_Installed_Plugins::download_ids — the pure collector that
 * maps active plugin instances to their deduped, positive integer download ids.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/account/class-installed-plugins.php';

/**
 * @covers \Woodev_Installed_Plugins
 */
final class InstalledPluginsTest extends TestCase {

	/** Builds a stub plugin returning a given download id. */
	private function plugin( $download_id ): object {
		return new class( $download_id ) {
			private $id;
			public function __construct( $id ) {
				$this->id = $id;
			}
			public function get_download_id() {
				return $this->id;
			}
		};
	}

	public function test_collects_positive_int_ids_deduped(): void {
		$plugins = array(
			$this->plugin( 127940 ),
			$this->plugin( '21' ),     // numeric string → int.
			$this->plugin( 127940 ),   // duplicate → collapsed.
		);

		$this->assertSame(
			array( 127940, 21 ),
			\Woodev_Installed_Plugins::download_ids( $plugins )
		);
	}

	public function test_skips_zero_negative_and_non_plugin_entries(): void {
		$plugins = array(
			$this->plugin( 0 ),
			$this->plugin( -5 ),
			$this->plugin( '' ),
			'not-an-object',
			new \stdClass(), // no get_download_id().
			$this->plugin( 99 ),
		);

		$this->assertSame( array( 99 ), \Woodev_Installed_Plugins::download_ids( $plugins ) );
	}

	public function test_empty_input_yields_empty_array(): void {
		$this->assertSame( array(), \Woodev_Installed_Plugins::download_ids( array() ) );
	}

	public function test_collects_ids_from_framework_refusal_records(): void {
		$definition = static function ( int $id ): object {
			return new class( $id ) {
				private int $id;
				public function __construct( int $id ) { $this->id = $id; }
				public function get_download_id(): int { return $this->id; }
			};
		};

		$records = [
			[ 'definition' => $definition( 216 ) ],
			[ 'definition' => $definition( 216 ) ],
			[ 'definition' => $definition( 0 ) ],
			[ 'definition' => new \stdClass() ],
			'not a resolver record',
		];

		$this->assertSame( [ 216 ], \Woodev_Installed_Plugins::download_ids_from_records( $records ) );
	}

	public function test_unmatched_legacy_plugin_does_not_look_like_a_store_product(): void {
		$this->assertSame(
			[],
			\Woodev_Installed_Plugins::download_ids_from_mixed_fleet_records(
				[
					[
						'plugin_name' => 'Third-party plugin',
						'path'        => '/var/www/wp-content/plugins/third-party/third-party.php',
					],
				]
			)
		);
	}
}
