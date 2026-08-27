<?php
namespace Woodev\Tests\Unit;

use Mockery;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;


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
	// -----------------------------------------------------------------------
	// #514 m6, critic round 2. `create()` takes an untyped array and stores it verbatim,
	// while `get_sections()` has always DOCUMENTED `Settings_Section[]` — a promise nothing
	// kept. That became load-bearing once the tools guarantee moved onto
	// `Settings_Section::get_tools()`: an object that merely LOOKS like a section never
	// passes through that accessor at all.
	//
	// Reproduced through the PUBLIC `create()`, no reflection needed — `build_sections()`
	// fatalled with `Call to a member function to_array() on string`, and `run_tool()` with
	// `Call to a member function get_id() on string`.
	// -----------------------------------------------------------------------

	public function test_get_sections_drops_an_object_that_is_not_a_section(): void {
		$real = Settings_Section::create( 'general', 'Общие', [ 'api_key' ] );

		$provider = Settings_Provider::create(
			'cdek',
			'СДЭК',
			$this->make_handler( 'cdek' ),
			[ $real, $this->duck_typed_section(), 'not-a-section-at-all' ]
		);

		$this->assertSame( [ $real ], $provider->get_sections() );
	}

	/**
	 * The control: a wholly conforming list survives intact, in order and by identity.
	 * Without it, the assertion above would also pass for a `get_sections()` that returned
	 * only its first element, or an empty array.
	 */
	public function test_control_get_sections_returns_a_conforming_list_intact(): void {
		$first  = Settings_Section::create( 'general', 'Общие', [ 'api_key' ] );
		$second = Settings_Section::create( 'advanced', 'Дополнительно', [ 'mode' ] );

		$provider = Settings_Provider::create( 'cdek', 'СДЭК', $this->make_handler( 'cdek' ), [ $first, $second ] );

		$this->assertSame( [ $first, $second ], $provider->get_sections() );
	}

	/**
	 * An object shaped exactly like a tools section, and not one. Every method
	 * `build_sections()` and `run_tool()` call on a section is present, so it gets all the
	 * way to dereferencing the junk in `get_tools()`.
	 */
	private function duck_typed_section() {
		return new class() {
			public function is_tools(): bool {
				return true;
			}

			public function is_connection(): bool {
				return false;
			}

			public function get_tools(): array {
				return [ 'not-a-tool' ];
			}

			public function get_id(): string {
				return 'tools';
			}

			public function get_label(): string {
				return 'Инструменты';
			}

			public function get_description(): string {
				return '';
			}

			public function get_setting_ids(): array {
				return [];
			}
		};
	}
}
