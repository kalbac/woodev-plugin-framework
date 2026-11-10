<?php
/**
 * Uninstall drops the plugin's tables, product meta, options and transients, and only uninstall does.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Uninstaller;

/**
 * Runs the uninstaller against a recording `wpdb` that evaluates the `LIKE` patterns it is given, the way WordPress runs
 * `uninstall.php` on plugin deletion.
 */
final class UninstallerTest extends TestCase {
	/**
	 * Option rows in the database, name => value.
	 *
	 * @var array<string,string>
	 */
	public $option_rows = [];

	/**
	 * The persistent object cache: a copy of option values that outlives the row until it is invalidated.
	 *
	 * @var array<string,string>
	 */
	public $option_cache = [];

	/**
	 * The recording `wpdb`.
	 *
	 * @var object
	 */
	private $db;

	/**
	 * Installs the recording `wpdb` and the option functions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// `delete_option()` removes the row and the cached copy, like WordPress; `get_option()` reads the cache first.
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->option_rows[ $name ], $this->option_cache[ $name ] );

				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return $this->option_cache[ $name ] ?? $fallback;
			}
		);

		$this->db = $this->recording_db();
	}

	/**
	 * Restores Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Deleting the plugin drops the 14 tables of its marketplace and the tables the plugin names itself, and no other table.
	 *
	 * @return void
	 */
	public function test_uninstall_drops_the_marketplace_tables_and_the_extra_ones(): void {
		Uninstaller::run( $this->db, new Schema( 'fake' ), 'fake-seller', [ 'wp_legacy_stores', 'wp_legacy_profiles' ] );

		$drops = array_values( array_filter( $this->db->queries, static fn( string $sql ): bool => 0 === strpos( $sql, 'DROP TABLE' ) ) );

		$this->assertCount( 16, $drops );
		$this->assertContains( 'DROP TABLE IF EXISTS wp_woodev_fake_accounts', $drops );
		$this->assertContains( 'DROP TABLE IF EXISTS wp_woodev_fake_catalog_cache', $drops );
		$this->assertContains( 'DROP TABLE IF EXISTS wp_legacy_stores', $drops );
		$this->assertContains( 'DROP TABLE IF EXISTS wp_legacy_profiles', $drops );
	}

	/**
	 * Per-plugin prefix: uninstalling one marketplace leaves the tables of another one on the same site alone.
	 *
	 * @return void
	 */
	public function test_uninstall_leaves_another_marketplaces_tables_alone(): void {
		Uninstaller::run( $this->db, new Schema( 'fake' ), 'fake-seller' );

		foreach ( $this->db->queries as $sql ) {
			$this->assertStringNotContainsString( 'woodev_other_', $sql );
		}
	}

	/**
	 * The articles chosen on the product tab are product meta of this marketplace; deleting the plugin deletes them, and only them.
	 *
	 * @return void
	 */
	public function test_uninstall_deletes_the_chosen_articles_from_the_products(): void {
		Uninstaller::run( $this->db, new Schema( 'fake' ), 'fake-seller' );

		$deletes = array_values( array_filter( $this->db->queries, static fn( string $sql ): bool => 0 === strpos( $sql, 'DELETE FROM wp_postmeta' ) ) );

		$this->assertCount( 1, $deletes );
		$this->assertStringContainsString( '\\_woodev\\_fake\\_offer\\_id\\_%', $deletes[0] );
	}

	/**
	 * The option family and its transients go with the tables, and only that family.
	 *
	 * @return void
	 */
	public function test_uninstall_deletes_the_option_family_and_nothing_else(): void {
		$this->option_rows  = [
			'woodev_fake-seller_version'                        => '2.0.0',
			'woodev_fake-seller_run_progress_link_3'            => '{}',
			'_transient_woodev_fake-seller_cards_3'             => 'a:0:{}',
			'_transient_timeout_woodev_fake-seller_cards_3'     => '1900000000',
			'woodev_other-seller_version'                       => '1.0.0',
			'_transient_woodev_other-seller_cards_3'            => 'a:0:{}',
			'siteurl'                                           => 'https://example.test',
		];
		$this->option_cache = $this->option_rows;

		Uninstaller::run( $this->db, new Schema( 'fake' ), 'fake-seller' );

		$this->assertSame(
			[ 'woodev_other-seller_version', '_transient_woodev_other-seller_cards_3', 'siteurl' ],
			array_keys( $this->option_rows )
		);
	}

	/**
	 * The family is built from the plugin id, exactly as `Woodev_Plugin` builds its option names.
	 *
	 * @return void
	 */
	public function test_the_option_family_is_woodev_plus_the_plugin_id(): void {
		$this->assertSame( 'woodev_ozon-seller_', Uninstaller::option_prefix( 'ozon-seller' ) );
	}

	/**
	 * With a persistent object cache primed with the stored version, uninstall must not leave the cached copy behind, or a
	 * reinstall reads "installed" and never creates the tables.
	 *
	 * @return void
	 */
	public function test_uninstall_clears_the_cached_version_so_a_reinstall_takes_the_install_path(): void {
		$this->option_rows  = [ 'woodev_fake-seller_version' => '2.0.0' ];
		$this->option_cache = $this->option_rows;

		Uninstaller::run( $this->db, new Schema( 'fake' ), 'fake-seller' );

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( 'fake-seller/fake-seller.php' );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'fake-seller' );
		$plugin->shouldReceive( 'get_plugin_version_name' )->andReturn( 'woodev_fake-seller_version' );
		Functions\when( 'is_admin' )->justReturn( false );

		$lifecycle = new Lifecycle( $plugin, new Schema( 'fake' ) );
		$installed = new ReflectionMethod( $lifecycle, 'get_installed_version' );
		if ( PHP_VERSION_ID < 80100 ) {
			$installed->setAccessible( true );
		}

		// The framework installs when this is empty (`! $installed_version`), and only upgrades or skips otherwise.
		$this->assertEmpty( $installed->invoke( $lifecycle ) );
	}

	/**
	 * Builds a `wpdb` double that records every query it is asked to run and answers option lookups from the owner's rows.
	 *
	 * @return object
	 */
	private function recording_db(): object {
		return new class( $this ) {
			/**
			 * Test owner.
			 *
			 * @var UninstallerTest
			 */
			private $test;

			/**
			 * Queries run, with their placeholders already filled.
			 *
			 * @var string[]
			 */
			public $queries = [];

			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Options table.
			 *
			 * @var string
			 */
			public $options = 'wp_options';

			/**
			 * Post meta table.
			 *
			 * @var string
			 */
			public $postmeta = 'wp_postmeta';

			/**
			 * Creates the double.
			 *
			 * @param UninstallerTest $test Test owner.
			 */
			public function __construct( UninstallerTest $test ) {
				$this->test = $test;
			}

			/**
			 * Fills `%i` (bare) and `%s` (quoted) placeholders in order.
			 *
			 * @param string $query   SQL.
			 * @param mixed  ...$args Values.
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				foreach ( $args as $arg ) {
					$arg   = (string) $arg;
					$query = (string) preg_replace_callback(
						'/%([is])/',
						static function ( array $m ) use ( $arg ): string {
							return 'i' === $m[1] ? $arg : "'" . $arg . "'";
						},
						$query,
						1
					);
				}

				return $query;
			}

			/**
			 * Escapes LIKE wildcards the way `wpdb::esc_like()` does.
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			/**
			 * Returns the option names the owner holds that match any `LIKE` pattern of the query.
			 *
			 * @param string $query SQL with the patterns already filled in.
			 * @return string[]
			 */
			public function get_col( string $query ): array {
				preg_match_all( "/LIKE '((?:[^'])*)'/", $query, $matches );

				$regexes = array_map( [ $this, 'like_to_regex' ], $matches[1] );

				return array_values(
					array_filter(
						array_keys( $this->test->option_rows ),
						static function ( string $name ) use ( $regexes ): bool {
							foreach ( $regexes as $regex ) {
								if ( 1 === preg_match( $regex, $name ) ) {
									return true;
								}
							}

							return false;
						}
					)
				);
			}

			/**
			 * Records a query.
			 *
			 * @param string $query SQL.
			 * @return int
			 */
			public function query( string $query ): int {
				$this->queries[] = $query;

				return 1;
			}

			/**
			 * Evaluates a `LIKE` pattern: `%` is any text, `_` one character, `\` escapes the next one.
			 *
			 * @param string $pattern Pattern.
			 * @return string Regular expression.
			 */
			private function like_to_regex( string $pattern ): string {
				$regex = '';
				$chars = str_split( $pattern );

				while ( [] !== $chars ) {
					$char = array_shift( $chars );

					if ( '\\' === $char && [] !== $chars ) {
						$regex .= preg_quote( (string) array_shift( $chars ), '/' );
					} elseif ( '%' === $char ) {
						$regex .= '.*';
					} elseif ( '_' === $char ) {
						$regex .= '.';
					} else {
						$regex .= preg_quote( $char, '/' );
					}
				}

				return '/^' . $regex . '$/s';
			}
		};
	}
}
