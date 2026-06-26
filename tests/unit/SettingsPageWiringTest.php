<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-provider.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-page-registry.php';

class SettingsPageWiringTest extends TestCase {

	private function neutral_provider( string $id ): Settings_Provider {
		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_id' )->andReturn( $id );
		$handler->shouldReceive( 'get_settings' )->andReturn( [] );

		return Settings_Provider::create( $id, strtoupper( $id ), $handler, [] );
	}

	public function test_collect_entries_pulls_providers_from_plugins_and_services(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$wc_plugin = Mockery::mock( '\Woodev\Framework\Woocommerce_Plugin' );
		$wc_plugin->shouldReceive( 'get_id' )->andReturn( 'wc' );
		$wc_plugin->shouldReceive( 'get_settings_providers' )->andReturn( [ $this->neutral_provider( 'cdek' ) ] );

		$neutral_plugin = Mockery::mock( '\Woodev_Plugin' );
		$neutral_plugin->shouldReceive( 'get_id' )->andReturn( 'neutral' );
		$neutral_plugin->shouldReceive( 'get_settings_providers' )->andReturn( [ $this->neutral_provider( 'tool' ) ] );

		$registry = Settings_Page_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_plugin( $wc_plugin );
		$registry->register_plugin( $neutral_plugin );
		$registry->register_service( $this->neutral_provider( 'dadata' ) );

		$entries = $registry->collect_entries();
		$ids     = array_map( static fn( $e ) => $e['provider']->get_id(), $entries );

		$this->assertContains( 'cdek', $ids );
		$this->assertContains( 'tool', $ids );
		$this->assertContains( 'dadata', $ids );

		foreach ( $entries as $entry ) {
			if ( 'cdek' === $entry['provider']->get_id() ) {
				$this->assertTrue( $entry['is_woocommerce'] );
			}
			if ( 'tool' === $entry['provider']->get_id() ) {
				$this->assertFalse( $entry['is_woocommerce'] );
			}
			if ( 'dadata' === $entry['provider']->get_id() ) {
				$this->assertFalse( $entry['is_woocommerce'] );
			}
		}
	}

	public function test_get_page_capability_uses_broadest_reach(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$wc_plugin = Mockery::mock( '\Woodev\Framework\Woocommerce_Plugin' );
		$wc_plugin->shouldReceive( 'get_id' )->andReturn( 'wc' );
		$wc_plugin->shouldReceive( 'get_settings_providers' )->andReturn( [ $this->neutral_provider( 'cdek' ) ] );

		$registry = Settings_Page_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_plugin( $wc_plugin );

		$this->assertSame( 'manage_woocommerce', $registry->get_page_capability() );
	}
}
