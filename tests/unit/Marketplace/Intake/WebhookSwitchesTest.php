<?php
/**
 * Unit tests for Webhook_Switches.
 *
 * @package Woodev\Tests\Unit\Marketplace\Intake
 */

namespace Woodev\Tests\Unit\Marketplace\Intake;

use Woodev\Framework\Marketplace\Intake\Webhook_Switches;
use Woodev\Framework\Marketplace\Intake\Webhook_Type;
use Woodev\Tests\Unit\TestCase;

/**
 * The saved switch wins; an unsaved one is the type's own default; a type nobody declared is never reported as off.
 */
final class WebhookSwitchesTest extends TestCase {
	/**
	 * Saved values, defaults and unknown types.
	 *
	 * @return void
	 */
	public function test_saved_value_then_the_types_default_then_unknown_types_are_on(): void {
		$options  = [
			'woodev_x_webhook_a' => 'no',
			'woodev_x_webhook_b' => 'yes',
		];
		$switches = new Webhook_Switches(
			[
				new Webhook_Type( 'a', 'A', 'a', '' ),
				new Webhook_Type( 'b', 'B', 'b', '', false ),
				new Webhook_Type( 'c', 'C', 'c', '' ),
				new Webhook_Type( 'd', 'D', 'd', '', false ),
			],
			'woodev_x_',
			static function ( string $name, $default = null ) use ( $options ) {
				return $options[ $name ] ?? $default;
			}
		);

		$this->assertFalse( $switches->is_enabled( 'a' ), 'saved off' );
		$this->assertTrue( $switches->is_enabled( 'b' ), 'saved on beats a default of off' );
		$this->assertTrue( $switches->is_enabled( 'c' ), 'unsaved: the default' );
		$this->assertFalse( $switches->is_enabled( 'd' ), 'unsaved: the default' );
		$this->assertTrue( $switches->is_enabled( 'undeclared' ) );
		$this->assertSame( 'webhook_a', Webhook_Switches::setting_id( 'a' ) );
	}
}
