<?php
namespace Woodev\Tests\Unit;

use Woodev\Framework\Setup\Step;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';

class SetupWizardStepTest extends TestCase {

	public function test_settings_step_exposes_setting_ids(): void {
		$step = Step::settings( 'connection', 'Подключение', [ 'api_key', 'api_secret' ] );

		$this->assertSame( 'connection', $step->get_id() );
		$this->assertSame( 'Подключение', $step->get_label() );
		$this->assertSame( Step::TYPE_SETTINGS, $step->get_type() );
		$this->assertSame( [ 'api_key', 'api_secret' ], $step->get_setting_ids() );
		$this->assertNull( $step->get_on_save() );
		$this->assertTrue( $step->is_visible() );
	}

	public function test_content_step_holds_a_callable_and_no_setting_ids(): void {
		$cb   = static function (): string { return '<p>hi</p>'; };
		$step = Step::content( 'welcome', 'Добро пожаловать', $cb );

		$this->assertSame( Step::TYPE_CONTENT, $step->get_type() );
		$this->assertSame( [], $step->get_setting_ids() );
		$this->assertSame( $cb, $step->get_content() );
	}

	public function test_optional_on_save_and_visibility_callback(): void {
		$save = static function (): void {};
		$step = Step::settings( 'delivery', 'Доставка', [ 'tariff' ], $save )
			->set_visibility_callback( static function (): bool { return false; } );

		$this->assertSame( $save, $step->get_on_save() );
		$this->assertFalse( $step->is_visible() );
	}

	public function test_content_step_accepts_plain_string_markup(): void {
		$step = Step::content( 'info', 'Инфо', '<p>Привет</p>' );

		$this->assertSame( Step::TYPE_CONTENT, $step->get_type() );
		$this->assertSame( '<p>Привет</p>', $step->get_content() );
	}
}
