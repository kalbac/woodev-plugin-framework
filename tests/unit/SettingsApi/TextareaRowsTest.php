<?php
/** Optional textarea height survives control registration, schema and classic rendering. */
namespace Woodev\Tests\Unit\SettingsApi;

use Brain\Monkey\Functions;
use Woodev\Framework\Settings\Field_Schema;
use Woodev\Tests\Unit\TestCase;

final class TextareaRowsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
	}

	private function handler(): \Woodev_Abstract_Settings {
		return new class( 'textarea_rows' ) extends \Woodev_Abstract_Settings {
			protected function register_settings(): void {
				foreach ( [ 'address', 'plain', 'text' ] as $id ) {
					$this->register_setting( $id, \Woodev_Setting::TYPE_STRING, [ 'default' => 'Saved text' ] );
				}
				$this->register_control( 'address', \Woodev_Control::TYPE_TEXTAREA, [ 'rows' => 3 ] );
				$this->register_control( 'plain', \Woodev_Control::TYPE_TEXTAREA );
				$this->register_control( 'text', \Woodev_Control::TYPE_TEXT, [ 'rows' => 3 ] );
			}
		};
	}

	public function test_rows_reach_the_textarea_schema_without_changing_other_defaults(): void {
		$handler = $this->handler();
		$schema = Field_Schema::from_handler( $handler );
		$this->assertSame( 3, $handler->get_setting( 'address' )->get_control()->get_rows() );
		$this->assertSame( 3, $schema['address']['rows'] );
		$this->assertSame( 'Saved text', $schema['address']['value'] );
		$this->assertNull( $handler->get_setting( 'plain' )->get_control()->get_rows() );
		$this->assertArrayNotHasKey( 'rows', $schema['plain'] );
		$this->assertArrayNotHasKey( 'rows', $schema['text'] );
	}

	public function test_nonpositive_rows_keep_the_default(): void {
		$handler = $this->handler();
		foreach ( [ 0, -3 ] as $rows ) {
			$this->assertTrue( $handler->register_control( 'address', \Woodev_Control::TYPE_TEXTAREA, [ 'rows' => $rows ] ) );
			$this->assertNull( $handler->get_setting( 'address' )->get_control()->get_rows() );
			$this->assertArrayNotHasKey( 'rows', Field_Schema::from_handler( $handler )['address'] );
		}
	}

	public function test_classic_textarea_accepts_rows_and_keeps_its_existing_default(): void {
		Functions\when( 'get_option' )->justReturn( [ 'address' => 'Saved text' ] );
		Functions\when( 'esc_textarea' )->returnArg( 1 );
		$renderer = new \Woodev_Register_Settings_Fields( 'classic_rows' );
		foreach ( [ 3, 10, 0 ] as $rows ) {
			ob_start();
			$renderer->textarea( 'address', null, $rows );
			$html = ob_get_clean();
			$this->assertStringContainsString( 'rows="' . ( $rows > 0 ? $rows : 10 ) . '"', $html );
			$this->assertStringContainsString( '>Saved text</textarea>', $html );
		}
		ob_start();
		$renderer->textarea( 'address' );
		$this->assertStringContainsString( 'rows="10"', ob_get_clean() );
	}
}
