<?php
/**
 * Unit tests for the `Logging` members restored in F15: the refusal sentence, the hint and the switch writer.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Diagnostics;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Tests\Unit\TestCase;

final class LoggingSwitchTest extends TestCase {
	private const OPTION = 'woodev_fake_seller_advanced_logging';

	public function test_refusal_names_the_switch_and_where_it_lives(): void {
		$this->assertStringContainsString( 'при включённом «Логировании»', Logging::REFUSAL );
		$this->assertStringContainsString( 'раздел «Дополнительно»', Logging::REFUSAL );
	}

	public function test_hint_is_empty_while_the_switch_is_off(): void {
		$logging = new Logging( self::OPTION, static fn (): string => 'no' );

		$this->assertSame( '', $logging->hint( 'Проверьте ключ API.' ) );
	}

	public function test_hint_carries_the_text_while_the_switch_is_on(): void {
		$logging = new Logging( self::OPTION, static fn (): string => 'yes' );

		$this->assertSame( 'подсказка: Проверьте ключ API.', $logging->hint( 'Проверьте ключ API.' ) );
	}

	public function test_set_enabled_stores_yes_or_no_the_way_the_settings_api_does(): void {
		$logging = new Logging( self::OPTION, static fn (): string => 'no' );

		Functions\expect( 'update_option' )->once()->with( self::OPTION, 'yes' );
		$logging->set_enabled( true );

		Functions\expect( 'update_option' )->once()->with( self::OPTION, 'no' );
		$logging->set_enabled( false );
	}
}
