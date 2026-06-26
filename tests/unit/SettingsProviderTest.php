<?php
namespace Woodev\Tests\Unit;

use Mockery;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-provider.php';

class SettingsProviderTest extends TestCase {

	private function make_handler( string $id ) {
		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_id' )->andReturn( $id );

		return $handler;
	}

	public function test_exposes_core_descriptor_fields(): void {
		$handler  = $this->make_handler( 'cdek' );
		$sections = [ Settings_Section::create( 'general', 'Общие', [ 'api_key' ] ) ];

		$provider = Settings_Provider::create(
			'cdek',
			'СДЭК',
			$handler,
			$sections,
			[
				'capability'        => 'manage_woocommerce',
				'legacy_option_key' => 'woocommerce_cdek_settings',
				'legacy_page'       => 'wc-settings&tab=shipping&section=cdek',
				'supports'          => [ 'fields' => true ],
			]
		);

		$this->assertSame( 'cdek', $provider->get_id() );
		$this->assertSame( 'СДЭК', $provider->get_label() );
		$this->assertSame( $handler, $provider->get_handler() );
		$this->assertSame( $sections, $provider->get_sections() );
		$this->assertSame( 'manage_woocommerce', $provider->get_declared_capability() );
		$this->assertSame( 'woocommerce_cdek_settings', $provider->get_legacy_option_key() );
		$this->assertSame( 'wc-settings&tab=shipping&section=cdek', $provider->get_legacy_page() );
		$this->assertTrue( $provider->supports( 'fields' ) );
		$this->assertFalse( $provider->supports( 'export' ) );
	}

	public function test_optional_fields_default_to_null_or_empty(): void {
		$provider = Settings_Provider::create( 'svc', 'Сервис', $this->make_handler( 'svc' ), [] );

		$this->assertNull( $provider->get_declared_capability() );
		$this->assertNull( $provider->get_legacy_option_key() );
		$this->assertNull( $provider->get_legacy_page() );
		$this->assertSame( [], $provider->get_sections() );
		$this->assertFalse( $provider->supports( 'anything' ) );
	}

	public function test_id_falls_back_to_handler_id_when_blank(): void {
		$provider = Settings_Provider::create( '', 'X', $this->make_handler( 'handler-id' ), [] );

		$this->assertSame( 'handler-id', $provider->get_id() );
	}
}
