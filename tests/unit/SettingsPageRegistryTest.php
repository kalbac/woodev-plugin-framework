<?php
namespace Woodev\Tests\Unit;

use Mockery;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-provider.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-page-registry.php';

class SettingsPageRegistryTest extends TestCase {

	// ----- capability resolution (4 rules) -----

	public function test_capability_defaults_to_manage_options(): void {
		$this->assertSame( 'manage_options', Settings_Page_Registry::resolve_capability( null, false ) );
	}

	public function test_capability_flips_to_manage_woocommerce_for_wc_plugin(): void {
		$this->assertSame( 'manage_woocommerce', Settings_Page_Registry::resolve_capability( null, true ) );
	}

	public function test_explicit_capability_overrides_both(): void {
		$this->assertSame( 'edit_shop_orders', Settings_Page_Registry::resolve_capability( 'edit_shop_orders', true ) );
		$this->assertSame( 'edit_shop_orders', Settings_Page_Registry::resolve_capability( 'edit_shop_orders', false ) );
	}

	// ----- page capability = broadest reach -----

	public function test_page_capability_prefers_manage_woocommerce_reach(): void {
		$this->assertSame( 'manage_woocommerce', Settings_Page_Registry::resolve_page_capability( [ 'manage_options', 'manage_woocommerce' ] ) );
	}

	public function test_page_capability_all_neutral_is_manage_options(): void {
		$this->assertSame( 'manage_options', Settings_Page_Registry::resolve_page_capability( [ 'manage_options', 'manage_options' ] ) );
	}

	public function test_page_capability_empty_defaults_to_manage_options(): void {
		$this->assertSame( 'manage_options', Settings_Page_Registry::resolve_page_capability( [] ) );
	}

	// ----- tab aggregation -----

	private function provider( string $id, string $label, ?string $cap = null ): Settings_Provider {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( 'api_key' );
		$setting->shouldReceive( 'get_type' )->andReturn( 'string' );
		$setting->shouldReceive( 'get_name' )->andReturn( 'Ключ' );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_description' )->andReturn( '' );
		$setting->shouldReceive( 'get_control' )->andReturn( null );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_id' )->andReturn( $id );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ 'api_key' => $setting ] );
		$handler->shouldReceive( 'get_value' )->andReturn( 'v' );

		return Settings_Provider::create(
			$id,
			$label,
			$handler,
			[ Settings_Section::create( 'general', 'Общие', [ 'api_key' ] ) ],
			null === $cap ? [] : [ 'capability' => $cap ]
		);
	}

	public function test_build_tabs_dedupes_by_id_keeping_first_and_preserves_order(): void {
		$registry = Settings_Page_Registry::instance();

		$tabs = $registry->build_tabs(
			[
				[ 'provider' => $this->provider( 'b', 'B' ), 'is_woocommerce' => false ],
				[ 'provider' => $this->provider( 'a', 'A' ), 'is_woocommerce' => true ],
				[ 'provider' => $this->provider( 'b', 'B-dup' ), 'is_woocommerce' => false ],
			],
			static function (): bool {
				return true; // current_user_can stub: sees everything.
			}
		);

		$ids = array_column( $tabs, 'id' );
		$this->assertSame( [ 'b', 'a' ], $ids );
		$this->assertSame( 'B', $tabs[0]['label'] );
		$this->assertSame( 'manage_woocommerce', $tabs[1]['capability'] );
	}

	public function test_build_tabs_omits_tabs_the_user_cannot_access(): void {
		$registry = Settings_Page_Registry::instance();

		$tabs = $registry->build_tabs(
			[
				[ 'provider' => $this->provider( 'wc', 'WC', 'manage_woocommerce' ), 'is_woocommerce' => true ],
				[ 'provider' => $this->provider( 'admin', 'Admin', 'manage_options' ), 'is_woocommerce' => false ],
			],
			static function ( string $cap ): bool {
				return 'manage_woocommerce' === $cap; // shop manager.
			}
		);

		$this->assertSame( [ 'wc' ], array_column( $tabs, 'id' ) );
		$this->assertArrayHasKey( 'sections', $tabs[0] );
		$this->assertSame( 'general', $tabs[0]['sections'][0]['id'] );
		$this->assertArrayHasKey( 'api_key', $tabs[0]['sections'][0]['fields'] );
	}
}
