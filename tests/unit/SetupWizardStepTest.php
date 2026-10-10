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

	public function test_short_label_is_null_until_set_and_the_setter_is_fluent(): void {
		$step = Step::settings( 'connection', 'Подключение к сервису', [ 'api_key' ] );

		$this->assertNull( $step->get_short_label() );
		$this->assertSame( $step, $step->set_short_label( 'Связь' ) );
		$this->assertSame( 'Связь', $step->get_short_label() );
		$this->assertSame( 'Подключение к сервису', $step->get_label() );
	}

	/**
	 * @dataProvider provide_blank_short_labels
	 */
	public function test_a_blank_short_label_means_not_set( string $blank ): void {
		$step = Step::content( 'info', 'Информация', '' )->set_short_label( 'Инфо' )->set_short_label( $blank );

		$this->assertNull( $step->get_short_label() );
	}

	/** @return array<string,array{string}> */
	public function provide_blank_short_labels(): array {
		return [
			'empty'      => [ '' ],
			'spaces'     => [ '   ' ],
			'whitespace' => [ " \t\n" ],
		];
	}

	public function test_the_short_label_is_trimmed(): void {
		$this->assertSame( 'Связь', Step::content( 'info', 'Информация', '' )->set_short_label( "  Связь \n" )->get_short_label() );
	}
}
