<?php
/**
 * The lifecycle creates the schema at the right moments and tells the merchant when it cannot.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Schema_Exception;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;

/**
 * Drives the real `Woodev_Lifecycle` machinery with a mocked plugin and records `dbDelta` calls.
 */
final class LifecycleTest extends TestCase {
	/**
	 * Statements handed to `dbDelta`, one entry per call.
	 *
	 * @var array<int,array<int,string>>
	 */
	private $delta_batches = [];

	/**
	 * The handler under test.
	 *
	 * @var Lifecycle
	 */
	private $lifecycle;

	/**
	 * Whether `dbDelta` really creates the tables; a test sets it to false to play a database user without CREATE.
	 *
	 * @var bool
	 */
	private $creation_works = true;

	/**
	 * The admin notice handler the plugin mock hands out.
	 *
	 * @var \Mockery\MockInterface
	 */
	private $notices;

	/**
	 * The mocked plugin.
	 *
	 * @var \Mockery\MockInterface
	 */
	private $plugin;

	/**
	 * Option store behind the `get_option()` / `update_option()` doubles.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * Transient store behind the `get_transient()` / `set_transient()` doubles.
	 *
	 * @var array<string,mixed>
	 */
	private $transients = [];

	/**
	 * Builds the handler over a mocked plugin and a recording `wpdb`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once __DIR__ . '/../Support/wpdb-stub.php';

		$GLOBALS['wpdb'] = $this->database_double(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test database double.

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'dbDelta' )->alias(
			function ( $statements ) {
				$this->delta_batches[] = $statements;

				if ( $this->creation_works ) {
					foreach ( $statements as $statement ) {
						preg_match( '/^CREATE TABLE (\S+) /', $statement, $matches );
						$GLOBALS['wpdb']->tables[] = $matches[1];
					}
				}

				return [];
			}
		);

		$this->plugin = Mockery::mock( \Woodev_Plugin::class );
		$this->plugin->shouldReceive( 'get_plugin_file' )->andReturn( 'fake-seller/fake-seller.php' );
		$this->plugin->shouldReceive( 'get_id' )->andReturn( 'fake-seller' );
		$this->plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Fake Seller' );
		$this->plugin->shouldReceive( 'get_plugin_version_name' )->andReturn( 'woodev_fake-seller_version' );
		$this->plugin->shouldReceive( 'get_version' )->andReturn( '2.0.0' );
		$this->plugin->shouldReceive( 'log_debug' );
		$this->plugin->shouldReceive( 'log' )->byDefault();
		$this->notices = Mockery::mock( \Woodev_Admin_Notice_Handler::class );
		$this->plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn( $this->notices );

		$this->lifecycle = new Lifecycle( $this->plugin, new Schema( 'fake' ) );
	}

	/**
	 * Restores the globals and Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A first install creates every table of the marketplace.
	 *
	 * @return void
	 */
	public function test_install_creates_every_table(): void {
		$this->call( 'install' );

		$this->assertCount( 1, $this->delta_batches );
		$this->assertSame( array_values( ( new Schema( 'fake' ) )->statements( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' ) ), $this->delta_batches[0] );
		$this->assertCount( 15, $this->delta_batches[0] );
	}

	/**
	 * Every upgrade brings the tables up to date: a later framework release adds a column by changing a statement, and `dbDelta`
	 * applies only the difference.
	 *
	 * @return void
	 */
	public function test_every_upgrade_synchronises_the_tables(): void {
		$this->call( 'upgrade', '1.1.7' );
		$this->call( 'upgrade', '2.0.5' );

		$this->assertCount( 2, $this->delta_batches );
		$this->assertCount( 15, $this->delta_batches[1] );
	}

	/**
	 * Creating the tables twice sends the same statements both times and nothing destructive: `dbDelta` makes the second run a
	 * no-op, and the lifecycle adds no `DROP`, `TRUNCATE`, `DELETE` or `ALTER` of its own.
	 *
	 * @return void
	 */
	public function test_creating_the_tables_again_resends_identical_non_destructive_statements(): void {
		$this->lifecycle->create_tables();
		$this->lifecycle->create_tables();

		$this->assertCount( 2, $this->delta_batches );
		$this->assertSame( $this->delta_batches[0], $this->delta_batches[1] );
		foreach ( $this->delta_batches[0] as $statement ) {
			$this->assertStringStartsWith( 'CREATE TABLE ', $statement );
			$this->assertDoesNotMatchRegularExpression( '/\b(DROP|TRUNCATE|DELETE|ALTER)\b/i', $statement );
		}
	}

	/**
	 * On an up-to-date site `init()` leaves the schema alone.
	 *
	 * @return void
	 */
	public function test_init_on_an_up_to_date_site_does_nothing(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $name ) {
				return 'woodev_fake-seller_is_active' === $name ? 'yes' : '2.0.0';
			}
		);

		$this->lifecycle->init();

		$this->assertSame( [], $this->delta_batches );
	}

	/**
	 * A request that is not an admin page load (REST, WP-CLI, cron) installs the tables on an early `init` when no version is
	 * stored, and a second such request does nothing (#1727).
	 *
	 * @return void
	 */
	public function test_a_request_outside_admin_installs_once_when_no_version_is_stored(): void {
		$this->use_option_store( [ 'woodev_fake-seller_is_active' => 'yes' ] );
		$this->use_transient_store();
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Actions\expectDone( 'woodev_fake-seller_installed' )->once();

		$this->assertSame( 1, has_action( 'init', [ $this->lifecycle, 'install_outside_admin' ] ), 'hooked early on init' );

		$this->lifecycle->install_outside_admin();
		$this->lifecycle->install_outside_admin();

		$this->assertCount( 1, $this->delta_batches, 'the tables are created once' );
		$this->assertSame( '2.0.0', $this->options['woodev_fake-seller_version'] );
		$this->assertSame( [], $this->transients, 'a successful install leaves no retry mark' );
	}

	/**
	 * With a stored version the early hook is one option read: nothing is created and nothing is marked.
	 *
	 * @return void
	 */
	public function test_a_request_outside_admin_does_nothing_once_a_version_is_stored(): void {
		$this->use_option_store(
			[
				'woodev_fake-seller_is_active' => 'yes',
				'woodev_fake-seller_version'   => '1.1.7',
			]
		);
		$this->use_transient_store();
		Functions\when( 'wp_doing_ajax' )->justReturn( false );

		$this->lifecycle->install_outside_admin();

		$this->assertSame( [], $this->delta_batches );
		$this->assertSame( '1.1.7', $this->options['woodev_fake-seller_version'], 'an upgrade stays the admin lifecycle\'s job' );
		$this->assertSame( [], $this->transients );
	}

	/**
	 * An admin page load is the parent's: the early hook leaves it alone, so the install does not run twice there.
	 *
	 * @return void
	 */
	public function test_an_admin_page_load_is_left_to_the_parent_hook(): void {
		$this->use_option_store( [ 'woodev_fake-seller_is_active' => 'yes' ] );
		$this->use_transient_store();
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );

		$this->lifecycle->install_outside_admin();

		$this->assertSame( [], $this->delta_batches );
		$this->assertArrayNotHasKey( 'woodev_fake-seller_version', $this->options );
	}

	/**
	 * A refused CREATE stores no version, so the early hook would try again on every request: it marks the failure and waits.
	 *
	 * @return void
	 */
	public function test_a_failed_install_outside_admin_is_not_retried_on_every_request(): void {
		$this->use_option_store( [ 'woodev_fake-seller_is_active' => 'yes' ] );
		$this->use_transient_store();
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		$this->creation_works = false;
		$this->expect_failure_notice();

		$this->lifecycle->install_outside_admin();
		$this->lifecycle->install_outside_admin();

		$this->assertCount( 1, $this->delta_batches, 'the second request waits for the mark to expire' );
		$this->assertArrayNotHasKey( 'woodev_fake-seller_version', $this->options );
		$this->assertArrayHasKey( 'woodev_fake-seller_schema_retry', $this->transients );
	}

	/**
	 * Deactivation keeps the data: the handler overrides neither `deactivate()` nor the deactivation handler.
	 *
	 * @return void
	 */
	public function test_deactivation_removes_nothing(): void {
		foreach ( [ 'deactivate', 'handle_deactivation' ] as $method ) {
			$declaring = ( new ReflectionMethod( Lifecycle::class, $method ) )->getDeclaringClass()->getName();

			$this->assertSame( \Woodev_Lifecycle::class, $declaring, "{$method} is the framework's" );
		}
	}

	/**
	 * `dbDelta` returns normally when every CREATE is refused, so the lifecycle looks the tables up and throws, naming what is
	 * missing.
	 *
	 * @return void
	 */
	public function test_create_tables_throws_when_a_table_is_missing_afterwards(): void {
		$this->creation_works = false;

		try {
			$this->lifecycle->create_tables();
			$this->fail( 'A missing table must raise Schema_Exception.' );
		} catch ( Schema_Exception $exception ) {
			$this->assertStringContainsString( 'wp_woodev_fake_accounts', $exception->getMessage() );
			$this->assertStringContainsString( 'wp_woodev_fake_catalog_cache', $exception->getMessage() );
		}
	}

	/**
	 * The lookup asks for the marketplace's own prefix, so another marketplace's tables never make this one look complete.
	 *
	 * @return void
	 */
	public function test_another_marketplaces_tables_do_not_count(): void {
		$GLOBALS['wpdb']->tables = ( new Schema( 'other' ) )->table_names( 'wp_' );
		$this->creation_works    = false;

		$this->expectException( Schema_Exception::class );

		$this->lifecycle->create_tables();
	}

	/**
	 * Table names come back in the case the server stores them: a capitalised site prefix must not look like missing tables.
	 *
	 * @return void
	 */
	public function test_table_lookup_ignores_case(): void {
		$GLOBALS['wpdb']->prefix = 'WP_';
		$GLOBALS['wpdb']->tables = array_map( 'strtolower', ( new Schema( 'fake' ) )->table_names( 'WP_' ) );
		$this->creation_works    = false;

		$this->lifecycle->create_tables();

		$this->assertCount( 1, $this->delta_batches );
	}

	/**
	 * Install path: a refused CREATE leaves no stored version and fires no `_installed` action, tells the merchant, and the next
	 * page load installs once the database allows it.
	 *
	 * @return void
	 */
	public function test_failed_install_stores_no_version_and_a_retry_installs(): void {
		$this->use_option_store( [ 'woodev_fake-seller_is_active' => 'yes' ] );
		$this->creation_works = false;
		$this->expect_failure_notice();
		Actions\expectDone( 'woodev_fake-seller_installed' )->once();

		$this->lifecycle->init();

		$this->assertArrayNotHasKey( 'woodev_fake-seller_version', $this->options, 'the failed install must not count as installed' );

		$this->creation_works = true;
		$this->lifecycle->init();

		$this->assertSame( '2.0.0', $this->options['woodev_fake-seller_version'] );
		$this->assertCount( 2, $this->delta_batches, 'one failed run, one that created the tables' );
	}

	/**
	 * Upgrade path: a shop on an older version stays on it while CREATE is refused, and upgrades on the retry.
	 *
	 * @return void
	 */
	public function test_failed_upgrade_keeps_the_old_version_and_a_retry_upgrades(): void {
		$this->use_option_store(
			[
				'woodev_fake-seller_is_active' => 'yes',
				'woodev_fake-seller_version'   => '1.1.7',
			]
		);
		$this->creation_works = false;
		$this->expect_failure_notice();
		Actions\expectDone( 'woodev_fake-seller_updated' )->once()->with( '1.1.7' );

		$this->lifecycle->init();

		$this->assertSame( '1.1.7', $this->options['woodev_fake-seller_version'] );

		$this->creation_works = true;
		$this->lifecycle->init();

		$this->assertSame( '2.0.0', $this->options['woodev_fake-seller_version'] );
	}

	/**
	 * Without a given schema the handler takes the id of the plugin's adapter, so the base class can install it with no argument.
	 *
	 * @return void
	 */
	public function test_the_schema_comes_from_the_adapter_of_a_marketplace_plugin(): void {
		$plugin = Mockery::mock( Marketplace_Plugin::class );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( 'fake-seller/fake-seller.php' );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'fake-seller' );
		$plugin->shouldReceive( 'get_marketplace_adapter' )->andReturn( new Fake_Marketplace_Adapter() );

		$lifecycle = new Lifecycle( $plugin );
		$lifecycle->create_tables();

		$this->assertSame( array_values( ( new Schema( ( new Fake_Marketplace_Adapter() )->id() ) )->statements( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' ) ), $this->delta_batches[0] );
	}

	/**
	 * A plugin that is not a marketplace plugin must say which schema it wants.
	 *
	 * @return void
	 */
	public function test_a_plain_plugin_needs_a_schema(): void {
		$lifecycle = new Lifecycle( $this->plugin );

		$this->expectException( \LogicException::class );

		$lifecycle->create_tables();
	}

	/**
	 * A `wpdb` that lists the tables it was told exist and ignores the rest.
	 *
	 * @return object
	 */
	private function database_double(): object {
		return new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Options table name.
			 *
			 * @var string
			 */
			public $options = 'wp_options';

			/**
			 * Tables that exist, as the database would list them.
			 *
			 * @var string[]
			 */
			public $tables = [];

			/**
			 * Charset clause.
			 *
			 * @return string
			 */
			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4';
			}

			/**
			 * Fills `%s` with the quoted value.
			 *
			 * @param string $query SQL.
			 * @param string ...$args Values.
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				return str_replace( '%s', "'" . (string) ( $args[0] ?? '' ) . "'", $query );
			}

			/**
			 * Returns the text unchanged.
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( string $text ): string {
				return $text;
			}

			/**
			 * Answers `SHOW TABLES LIKE` with the existing tables that start with the asked prefix.
			 *
			 * @param string $query SQL with the pattern filled in.
			 * @return string[]
			 */
			public function get_col( string $query = '' ): array {
				preg_match( "/LIKE '(.*)%'/", $query, $matches );
				$prefix = strtolower( $matches[1] ?? '' );

				return array_values(
					array_filter(
						$this->tables,
						static function ( string $table ) use ( $prefix ): bool {
							return 0 === strpos( strtolower( $table ), $prefix );
						}
					)
				);
			}

			/**
			 * Event history lookup of the framework: none stored.
			 *
			 * @return null
			 */
			public function get_var() {
				return null;
			}

			/**
			 * Event history write of the framework.
			 *
			 * @return int
			 */
			public function replace(): int {
				return 1;
			}
		};
	}

	/**
	 * Backs `get_option()` / `update_option()` with an array and stubs the helpers the framework's `init()` uses.
	 *
	 * @param array<string,mixed> $initial Options present at the start.
	 * @return void
	 */
	private function use_option_store( array $initial ): void {
		$this->options = $initial;

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'current_time' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * Backs `get_transient()` / `set_transient()` with {@see self::$transients}.
	 *
	 * @return void
	 */
	private function use_transient_store(): void {
		$this->transients = [];

		Functions\when( 'get_transient' )->alias(
			function ( string $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->transients[ $name ] = $value;

				return true;
			}
		);
	}

	/**
	 * Expects the failure to be logged and shown once, as an error notice that cannot be dismissed.
	 *
	 * @return void
	 */
	private function expect_failure_notice(): void {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		$this->plugin->shouldReceive( 'log' )->once()->with( Mockery::pattern( '/^Schema creation failed/' ) );
		$this->notices->shouldReceive( 'add_admin_notice' )
			->once()
			->with(
				Mockery::type( 'string' ),
				'woodev_fake-seller_schema_failed',
				Mockery::on(
					static function ( array $params ): bool {
						return 'notice-error' === $params['notice_class'] && false === $params['dismissible'];
					}
				)
			);
	}

	/**
	 * Calls a protected lifecycle method.
	 *
	 * @param string $method  Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function call( string $method, ...$args ) {
		$reflection = new ReflectionMethod( $this->lifecycle, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->invoke( $this->lifecycle, ...$args );
	}
}
