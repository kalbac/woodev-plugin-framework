<?php
/**
 * Base Unit Test Case
 *
 * Все юнит тесты наследуются от этого класса.
 * Автоматически инициализирует и сбрасывает Brain Monkey.
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	// Интеграция Mockery с PHPUnit (автоматическая проверка expectations)
	use MockeryPHPUnitIntegration;

	/**
	 * Инициализация перед каждым тестом.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Мокаем самые частые WP функции чтобы они не падали
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
		// Settings schema descriptions are sanitized before being sent to React.
		Monkey\Functions\when( 'wp_kses_post' )->returnArg();
		// Woodev_Order_Compatibility slashes non-HPOS meta values before update_post_meta() (#1362).
		Monkey\Functions\when( 'wp_slash' )->returnArg();
		// Once any test stubs it the function exists for the rest of the run, and `Order_Actions::get_exportable_statuses()`
		// (#1458) calls it whenever it is there: a stock shop unless the test says otherwise.
		Monkey\Functions\when( 'wc_get_order_statuses' )->justReturn(
			[
				'wc-pending'    => 'Pending payment',
				'wc-processing' => 'Processing',
				'wc-on-hold'    => 'On hold',
				'wc-completed'  => 'Completed',
				'wc-cancelled'  => 'Cancelled',
				'wc-refunded'   => 'Refunded',
				'wc-failed'     => 'Failed',
			]
		);
	}

	/**
	 * Очистка после каждого теста.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
