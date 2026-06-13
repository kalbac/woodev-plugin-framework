<?php
/**
 * Platform-neutral lifecycle helper tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-lifecycle.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/commands/interface-license-command.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/commands/class-license-command-deactivate-plugin.php';

/**
 * Test wpdb double for lifecycle event persistence.
 */
class Testable_Platform_Neutral_Lifecycle_WPDB {

	/**
	 * Options table name.
	 *
	 * @var string
	 */
	public $options = 'wp_options';

	/**
	 * Last replace payload.
	 *
	 * @var array<string,mixed>
	 */
	public $last_replace = [];

	/**
	 * Mocks wpdb::get_var().
	 *
	 * @param string $query SQL query.
	 * @return null
	 */
	public function get_var( string $query ) {
		return null;
	}

	/**
	 * Mocks wpdb::prepare().
	 *
	 * @param string $query SQL query.
	 * @param mixed  ...$args Query arguments.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		return $query;
	}

	/**
	 * Mocks wpdb::replace().
	 *
	 * @param string              $table Table name.
	 * @param array<string,mixed> $data Data payload.
	 * @param array<int,string>   $format Value formats.
	 * @return int
	 */
	public function replace( string $table, array $data, array $format ): int {
		$this->last_replace = [
			'table'  => $table,
			'data'   => $data,
			'format' => $format,
		];

		return 1;
	}
}

/**
 * Test lifecycle wrapper exposing store_event() without WordPress hook registration.
 */
class Testable_Platform_Neutral_Lifecycle extends \Woodev_Lifecycle {

	/**
	 * Plugin test double.
	 *
	 * @var object
	 */
	private $plugin;

	/**
	 * Constructs the test lifecycle wrapper.
	 *
	 * @param object $plugin Plugin test double.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Avoid DB reads in focused unit tests.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_event_history(): array {
		return [];
	}

	/**
	 * Returns the plugin test double.
	 *
	 * @return object
	 */
	protected function get_plugin() {
		return $this->plugin;
	}
}

/**
 * Class PlatformNeutralLifecycleTest.
 */
class PlatformNeutralLifecycleTest extends TestCase {

	/**
	 * Lifecycle event storage should keep the recursive sanitize contract without WooCommerce helpers.
	 *
	 * @return void
	 */
	public function test_store_event_keeps_recursive_sanitize_contract_without_woocommerce_helpers(): void {
		global $wpdb;

		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_version' )->once()->andReturn( ' 2.0.0 ' );
		$plugin->shouldReceive( 'get_id' )->once()->andReturn( 'platform-neutral-lifecycle' );

		$wpdb = new Testable_Platform_Neutral_Lifecycle_WPDB();

		Functions\when( 'current_time' )->justReturn( 1_717_171_717 );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $value ): string {
				return (string) json_encode( $value );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ): string {
				return trim( strip_tags( (string) $value ) );
			}
		);

		$lifecycle = new Testable_Platform_Neutral_Lifecycle( $plugin );

		$result = $lifecycle->store_event(
			' upgrade <strong>done</strong> ',
			[
				'from_version' => ' <em>1.9.0</em> ',
				'nested'       => [
					'note' => ' <script>alert(1)</script>ready ',
				],
			]
		);

		$this->assertSame( 1, $result );
		$this->assertSame( 'wp_options', $wpdb->last_replace['table'] );
		$this->assertSame(
			'woodev_platform-neutral-lifecycle_lifecycle_events',
			$wpdb->last_replace['data']['option_name']
		);

		$stored_history = json_decode( $wpdb->last_replace['data']['option_value'], true );

		$this->assertIsArray( $stored_history );
		$this->assertSame( 'upgrade done', $stored_history[0]['name'] );
		$this->assertSame( 1717171717, $stored_history[0]['time'] );
		$this->assertSame( '2.0.0', $stored_history[0]['version'] );
		$this->assertSame( '1.9.0', $stored_history[0]['data']['from_version'] );
		$this->assertSame( 'alert(1)ready', $stored_history[0]['data']['nested']['note'] );
	}

	/**
	 * Finding A (s12): a genuine (re)activation transition clears this plugin's own
	 * stale entry from the remote-deactivation notices option (other plugins kept)
	 * so a reactivated plugin never shows a "you were disabled" banner.
	 *
	 * @return void
	 */
	public function test_handle_activation_clears_stale_remote_deactivation_notice(): void {

		$id = 'platform-neutral-lifecycle';

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_id' )->andReturn( $id );
		$plugin->shouldReceive( 'get_id_dasherized' )->andReturn( $id );

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $id ) {
				if ( 'woodev_' . $id . '_is_active' === $name ) {
					return false; // not yet active → genuine activation transition.
				}
				if ( 'woodev_license_remote_deactivation_notices' === $name ) {
					return array(
						$id      => array( 'message' => 'stale', 'ts' => 1 ),
						'other'  => array( 'message' => 'keep', 'ts' => 2 ),
					);
				}
				return $default;
			}
		);
		Functions\when( 'wp_reschedule_event' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );

		$writes = array();
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $autoload = '' ) use ( &$writes ) {
				$writes[ $name ] = $value;
				return true;
			}
		);

		Actions\expectDone( 'woodev_' . $id . '_activated' )->once();

		$lifecycle = new Testable_Platform_Neutral_Lifecycle( $plugin );
		$lifecycle->handle_activation();

		$this->assertArrayHasKey( 'woodev_license_remote_deactivation_notices', $writes );
		$notices = $writes[ 'woodev_license_remote_deactivation_notices' ];
		$this->assertArrayNotHasKey( $id, $notices, 'The reactivated plugin\'s own stale notice must be removed.' );
		$this->assertArrayHasKey( 'other', $notices, 'Other plugins\' notices must be preserved.' );
		$this->assertSame( 'yes', $writes[ 'woodev_' . $id . '_is_active' ] );
	}

	/**
	 * Base-owned lifecycle should not call wc_clean() directly anymore.
	 *
	 * @return void
	 */
	public function test_lifecycle_file_does_not_call_wc_clean_directly(): void {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-lifecycle.php' );

		$this->assertIsString( $contents );
		$this->assertStringNotContainsString( 'wc_clean(', $contents );
	}
}
