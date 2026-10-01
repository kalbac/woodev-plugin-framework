<?php
namespace Woodev\Tests\Unit;

use Mockery;
use Woodev\Framework\Settings\Composite_Settings_Handler;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-connection-result.php';

/**
 * A `create_connection()` section inside a composite tab (#1014, #1028): the connection test and the
 * status badge are forwarded to the child that owns the section's settings, and the button shows only
 * when THAT child can test.
 */
final class CompositeConnectionForwardingTest extends TestCase {

	/** A mocked setting with everything `Field_Schema` reads. */
	private function setting( string $id ) {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( $id );
		$setting->shouldReceive( 'get_type' )->andReturn( 'string' );
		$setting->shouldReceive( 'get_name' )->andReturn( $id );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_description' )->andReturn( '' );
		$setting->shouldReceive( 'get_control' )->andReturn( null );
		$setting->shouldReceive( 'is_sensitive' )->andReturn( false );
		$setting->shouldReceive( 'get_constant_name' )->andReturn( null );
		$setting->shouldReceive( 'is_required' )->andReturn( false );
		$setting->shouldReceive( 'get_validate' )->andReturn( null );
		$setting->shouldReceive( 'get_show_if_conditions' )->andReturn( [] );

		return $setting;
	}

	/**
	 * A child handler owning one setting.
	 *
	 * @param string $interfaces extra interfaces the child implements, as Mockery spells them.
	 */
	private function child( string $setting_id, string $interfaces = '' ) {
		$child = Mockery::mock( '\Woodev_Abstract_Settings' . ( '' === $interfaces ? '' : ', ' . $interfaces ) );
		$child->shouldReceive( 'get_settings' )->andReturn( [ $setting_id => $this->setting( $setting_id ) ] );
		$child->shouldReceive( 'get_value' )->andReturn( '' );

		return $child;
	}

	public function test_the_test_is_forwarded_to_the_child_that_owns_the_connection_settings(): void {
		$other = $this->child( 'currency' );
		$owner = $this->child( 'token', '\Woodev_Settings_Connection_Test' );
		$owner->shouldReceive( 'test_connection' )->once()->with( 'api', [ 'token' => 'T' ] )->andReturn( \Woodev_Connection_Result::success( 'ok' ) );

		$composite = new Composite_Settings_Handler( 'cdek', [ $other, $owner ], [ 'api' => $owner ] );

		$this->assertTrue( $composite->supports_connection_test( 'api' ) );
		$this->assertTrue( $composite->test_connection( 'api', [ 'token' => 'T' ] )->is_success() );
	}

	public function test_the_status_is_forwarded_and_a_child_without_status_gives_null(): void {
		$with = $this->child( 'token', '\Woodev_Settings_Connection_Test, \Woodev_Settings_Connection_Status' );
		$with->shouldReceive( 'get_connection_status' )->once()->with( 'api' )->andReturn( \Woodev_Connection_Result::success( 'Подключено' ) );
		$without = $this->child( 'other_token', '\Woodev_Settings_Connection_Test' );

		$composite = new Composite_Settings_Handler( 'cdek', [ $with, $without ], [ 'api' => $with, 'second' => $without ] );

		$this->assertSame( 'Подключено', $composite->get_connection_status( 'api' )->get_message() );
		$this->assertNull( $composite->get_connection_status( 'second' ) );
	}

	public function test_a_child_without_the_interface_is_not_asked_and_cannot_be_tested(): void {
		$plain = $this->child( 'token' );

		$composite = new Composite_Settings_Handler( 'cdek', [ $plain ], [ 'api' => $plain ] );

		$this->assertFalse( $composite->supports_connection_test( 'api' ) );
		$this->assertNull( $composite->get_connection_status( 'api' ) );

		$this->expectException( \Woodev_Plugin_Exception::class );
		$composite->test_connection( 'api', [] );
	}

	public function test_a_handshake_block_with_no_setting_ids_is_forwarded_to_its_declared_handler(): void {
		$other = $this->child( 'currency' );
		$owner = $this->child( 'token', '\Woodev_Settings_Connection_Test, \Woodev_Settings_Connection_Status' );
		$owner->shouldReceive( 'test_connection' )->once()->with( 'widget', [] )->andReturn( \Woodev_Connection_Result::success( 'ok' ) );
		$owner->shouldReceive( 'get_connection_status' )->once()->with( 'widget' )->andReturn( \Woodev_Connection_Result::success( 'Подключено' ) );

		$composite = new Composite_Settings_Handler( 'cdek', [ $other, $owner ], [ 'widget' => $owner ] );

		$this->assertTrue( $composite->supports_connection_test( 'widget' ) );
		$this->assertTrue( $composite->test_connection( 'widget', [] )->is_success() );
		$this->assertSame( 'Подключено', $composite->get_connection_status( 'widget' )->get_message() );
	}

	public function test_a_connection_id_outside_the_map_has_no_owner(): void {
		$composite = new Composite_Settings_Handler( 'cdek', [ $this->child( 'token', '\Woodev_Settings_Connection_Test' ) ] );

		$this->assertFalse( $composite->supports_connection_test( 'api' ) );
		$this->assertNull( $composite->get_connection_status( 'api' ) );
	}

	public function test_the_schema_shows_the_button_only_for_a_block_whose_owner_can_test(): void {
		$tester = $this->child( 'token', '\Woodev_Settings_Connection_Test, \Woodev_Settings_Connection_Status' );
		$tester->shouldReceive( 'get_connection_status' )->andReturn( \Woodev_Connection_Result::success( 'Подключено' ) );
		$plain = $this->child( 'login' );

		$composite = new Composite_Settings_Handler( 'cdek', [ $tester, $plain ], [ 'tested' => $tester, 'untested' => $plain, 'handshake' => $tester ] );
		$provider  = Settings_Provider::create_with_sections(
			'cdek',
			'СДЭК',
			$composite,
			[],
			Settings_Section::create_connection( 'tested', 'Подключение', [ 'token' ], 'Проверить' ),
			Settings_Section::create_connection( 'untested', 'Вход', [ 'login' ], 'Проверить' ),
			Settings_Section::create_connection( 'handshake', 'Виджет', [], 'Подключить' )
		);

		$ref = new \ReflectionMethod( Settings_Page_Registry::instance(), 'build_sections' );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		$sections = $ref->invoke( Settings_Page_Registry::instance(), $provider );

		$this->assertTrue( $sections[0]['supports_test'] );
		$this->assertArrayHasKey( 'status', $sections[0] );
		$this->assertFalse( $sections[1]['supports_test'] );
		$this->assertArrayNotHasKey( 'status', $sections[1] );
		$this->assertTrue( $sections[2]['supports_test'], 'a handshake block (no setting ids) still has its owner' );
	}
}
