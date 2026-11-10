<?php
/**
 * The marketplace base plugin installs the persistence lifecycle itself.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Tests\Unit\Marketplace\Admin\Test_Marketplace_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/../Admin/Test_Marketplace_Plugin.php';

/**
 * A plugin that extends `Marketplace_Plugin` gets `Data\Lifecycle` without writing anything, like carriers get their
 * checkout: the card's «a `Woodev_Lifecycle` the base class installs».
 */
final class MarketplacePluginLifecycleTest extends TestCase {
	/**
	 * Statements handed to `dbDelta`.
	 *
	 * @var array<int,string[]>
	 */
	private $batches = [];

	/**
	 * Boots the stubs and a recording database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'plugin_basename' )->returnArg();
		Functions\when( 'dbDelta' )->alias(
			function ( $statements ) {
				$this->batches[] = $statements;

				foreach ( $statements as $statement ) {
					preg_match( '/^CREATE TABLE (\S+) /', $statement, $matches );
					$GLOBALS['wpdb']->tables[] = $matches[1];
				}

				return [];
			}
		);

		$GLOBALS['wpdb'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test database double.
			/** @var string */
			public $prefix = 'wp_';
			/** @var string[] */
			public $tables = [];

			/** @return string */
			public function get_charset_collate(): string {
				return '';
			}

			/**
			 * @param string $query SQL.
			 * @param string ...$args Values.
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				return str_replace( '%s', "'" . (string) ( $args[0] ?? '' ) . "'", $query );
			}

			/**
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( string $text ): string {
				return $text;
			}

			/** @return string[] */
			public function get_col(): array {
				return $this->tables;
			}
		};
	}

	/**
	 * Restores the globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * The framework builds the lifecycle through `init_lifecycle_handler()`; the marketplace base answers with its own.
	 *
	 * @return void
	 */
	public function test_the_base_class_builds_the_marketplace_lifecycle(): void {
		$plugin = new Test_Marketplace_Plugin( 'fake-seller', [ 'id' => 'fake-seller' ] );

		$plugin->build_lifecycle_handler();

		$this->assertInstanceOf( Lifecycle::class, $plugin->get_lifecycle_handler() );
	}

	/**
	 * The handler it installs creates the tables of the plugin's own adapter id, nothing to configure.
	 *
	 * @return void
	 */
	public function test_the_installed_lifecycle_creates_the_adapters_tables(): void {
		$plugin = new Test_Marketplace_Plugin( 'fake-seller', [ 'id' => 'fake-seller' ] );
		$plugin->build_lifecycle_handler();

		$plugin->get_lifecycle_handler()->create_tables();

		$this->assertCount( 1, $this->batches );
		$this->assertCount( 15, $this->batches[0] );
		$this->assertStringStartsWith( 'CREATE TABLE wp_woodev_fake_wildberries_accounts ', $this->batches[0][0] );
	}
}
