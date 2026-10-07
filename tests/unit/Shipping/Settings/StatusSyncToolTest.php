<?php
/**
 * Unit: the «Обновить статусы сейчас» button (s158) — the carrier's own cron hook, fired by hand.
 *
 * @package Woodev\Tests\Unit\Shipping\Settings
 */

namespace Woodev\Tests\Unit\Shipping\Settings;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Settings\Status_Sync_Tool;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-tool-result.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-tool.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivery-sync-status.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-status-sync-tool.php';

/**
 * @covers \Woodev\Framework\Shipping\Settings\Status_Sync_Tool
 */
final class StatusSyncToolTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_key' )->alias( static fn( string $key ) => strtolower( $key ) );
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ) => 'DATE ' . $ts );
	}

	private function provider( string $id, ?string $hook ): Orders_Provider {
		return Orders_Provider::create( $id, 'Carrier', '_marker_' . $id, [ $id ], null === $hook ? [] : [ 'cron_hook' => $hook ] );
	}

	public function test_no_cron_hook_means_no_button(): void {
		$this->assertNull( Status_Sync_Tool::create( [ $this->provider( 'cdek', null ) ] ) );
		$this->assertNull( Status_Sync_Tool::create( [] ) );
	}

	public function test_a_hook_with_no_callback_gives_a_disabled_button(): void {
		Functions\when( 'has_action' )->justReturn( false );

		$tool = Status_Sync_Tool::create( [ $this->provider( 'cdek', 'cdek_update' ) ] );

		$this->assertTrue( $tool->is_disabled() );
		$this->assertNotSame( '', $tool->get_status_text() );
	}

	public function test_pressing_it_fires_the_carriers_cron_hook(): void {
		Functions\when( 'has_action' )->justReturn( 10 );
		Actions\expectDone( 'cdek_update' )->once();

		$tool   = Status_Sync_Tool::create( [ $this->provider( 'cdek', 'cdek_update' ) ] );
		$result = call_user_func( $tool->get_callback(), [] );

		$this->assertFalse( $tool->is_disabled() );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'Статусы обновлены.', $result->get_message() );
	}

	public function test_it_quotes_the_refresh_time_only_when_the_run_moved_it(): void {
		Functions\when( 'has_action' )->justReturn( 10 );
		$this->options['woodev_shipping_delivery_synced_cdek'] = 100;
		$tool = Status_Sync_Tool::create( [ $this->provider( 'cdek', 'cdek_update' ) ] );

		$this->assertSame( 'Статусы обновлены.', call_user_func( $tool->get_callback(), [] )->get_message(), 'a stale time is not a claim about this run' );

		Actions\expectDone( 'cdek_update' )->once()->whenHappen(
			function (): void {
				$this->options['woodev_shipping_delivery_synced_cdek'] = 200;
			}
		);

		$this->assertStringContainsString( 'DATE 200', call_user_func( $tool->get_callback(), [] )->get_message() );
	}

	public function test_a_hook_that_throws_is_a_failure_with_a_log_line_and_the_other_carrier_still_runs(): void {
		Functions\when( 'has_action' )->justReturn( 10 );

		$fired = [];
		Actions\expectDone( 'broken_update' )->once()->whenHappen(
			static function (): void {
				throw new \RuntimeException( 'carrier down' );
			}
		);
		Actions\expectDone( 'fine_update' )->once()->whenHappen(
			static function () use ( &$fired ): void {
				$fired[] = 'fine';
			}
		);

		$logged = [];
		$tool   = Status_Sync_Tool::create(
			[ $this->provider( 'a', 'broken_update' ), $this->provider( 'b', 'fine_update' ) ],
			static function ( string $message ) use ( &$logged ): void {
				$logged[] = $message;
			}
		);

		$result = call_user_func( $tool->get_callback(), [] );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( [ 'fine' ], $fired );
		$this->assertCount( 1, $logged );
		$this->assertStringContainsString( 'carrier down', $logged[0] );
	}
}
