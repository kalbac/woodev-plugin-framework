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
	}

	/**
	 * Очистка после каждого теста.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
