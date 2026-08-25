<?php
/**
 * Unit tests for Shipping_Tools_Registry — the «Инструменты» registry (issue
 * #505, D6): filter collection, `_doing_it_wrong()` on a non-conforming entry,
 * duplicate id rejection, run()'s selector-arg allow-list, disabled tools, and
 * the singleton reset.
 *
 * @package Woodev\Tests\Unit\Shipping\Settings
 */

namespace Woodev\Tests\Unit\Shipping\Settings;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Settings\Shipping_Tool;
use Woodev\Framework\Shipping\Settings\Shipping_Tools_Registry;
use Woodev\Framework\Shipping\Settings\Tool_Result;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-tool.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-tool-result.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-tools-registry.php';

class ShippingToolsRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Shipping_Tools_Registry::reset_for_tests();
	}

	protected function tearDown(): void {
		Shipping_Tools_Registry::reset_for_tests();
		parent::tearDown();
	}

	private function stub_filter_tools( array $tools ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) use ( $tools ) {
				if ( Shipping_Tools_Registry::FILTER_TOOLS === $tag ) {
					return $tools;
				}

				return $default;
			}
		);
	}

	private function tool( string $id, ?callable $callback = null, bool $disabled = false, ?array $selector = null ): Shipping_Tool {
		return Shipping_Tool::create(
			$id,
			$id,
			'',
			'Btn',
			$callback ?? static fn( array $args ): Tool_Result => Tool_Result::success(),
			$disabled,
			'',
			$selector
		);
	}

	public function test_no_tools_without_a_filter_contribution(): void {
		$this->stub_filter_tools( [] );

		$this->assertFalse( Shipping_Tools_Registry::instance()->has_tools() );
		$this->assertSame( [], Shipping_Tools_Registry::instance()->get_tools() );
	}

	public function test_get_tools_returns_filter_contributed_tools(): void {
		$a = $this->tool( 'a' );
		$b = $this->tool( 'b' );
		$this->stub_filter_tools( [ $a, $b ] );

		$tools = Shipping_Tools_Registry::instance()->get_tools();

		$this->assertTrue( Shipping_Tools_Registry::instance()->has_tools() );
		$this->assertSame( [ $a, $b ], $tools );
	}

	public function test_non_conforming_entry_is_rejected_and_reported(): void {
		$a = $this->tool( 'a' );
		$this->stub_filter_tools( [ $a, 'not-a-tool', 42 ] );

		Functions\expect( '_doing_it_wrong' )->twice();

		$tools = Shipping_Tools_Registry::instance()->get_tools();

		$this->assertSame( [ $a ], $tools );
	}

	public function test_duplicate_id_first_wins_and_is_reported(): void {
		$first  = $this->tool( 'dup' );
		$second = $this->tool( 'dup' );
		$this->stub_filter_tools( [ $first, $second ] );

		Functions\expect( '_doing_it_wrong' )->once();

		$tools = Shipping_Tools_Registry::instance()->get_tools();

		$this->assertSame( [ $first ], $tools );
	}

	public function test_collection_happens_once_per_instance(): void {
		$calls = 0;
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) use ( &$calls ) {
				if ( Shipping_Tools_Registry::FILTER_TOOLS === $tag ) {
					++$calls;

					return [];
				}

				return $default;
			}
		);

		$registry = Shipping_Tools_Registry::instance();
		$registry->get_tools();
		$registry->has_tools();
		$registry->run( 'anything', [] );

		$this->assertSame( 1, $calls );
	}

	public function test_run_scopes_args_to_the_tool_s_declared_selector_names(): void {
		$received = null;
		$tool     = $this->tool(
			'sweep',
			static function ( array $args ) use ( &$received ): Tool_Result {
				$received = $args;

				return Tool_Result::success( 'ok' );
			},
			false,
			[ 'name' => 'provider_id', 'options' => [] ]
		);
		$this->stub_filter_tools( [ $tool ] );

		$result = Shipping_Tools_Registry::instance()->run(
			'sweep',
			[ 'provider_id' => 'dadata', 'extra' => 'should-not-reach-the-callback' ]
		);

		$this->assertSame( [ 'provider_id' => 'dadata' ], $received );
		$this->assertTrue( $result->is_success() );
	}

	public function test_run_unknown_tool_fails_without_calling_anything(): void {
		$this->stub_filter_tools( [] );

		$result = Shipping_Tools_Registry::instance()->run( 'missing', [] );

		$this->assertFalse( $result->is_success() );
	}

	public function test_run_disabled_tool_fails_and_never_invokes_the_callback(): void {
		$invoked = false;
		$tool    = $this->tool(
			'blocked',
			static function ( array $args ) use ( &$invoked ): Tool_Result {
				$invoked = true;

				return Tool_Result::success();
			},
			true
		);
		$this->stub_filter_tools( [ $tool ] );

		$result = Shipping_Tools_Registry::instance()->run( 'blocked', [] );

		$this->assertFalse( $invoked );
		$this->assertFalse( $result->is_success() );
	}

	public function test_run_disabled_tool_uses_status_text_when_present(): void {
		$tool = Shipping_Tool::create( 'blocked', 'Blocked', '', 'Btn', static fn( array $args ): Tool_Result => Tool_Result::success(), true, 'Недоступно сейчас' );
		$this->stub_filter_tools( [ $tool ] );

		$result = Shipping_Tools_Registry::instance()->run( 'blocked', [] );

		$this->assertSame( 'Недоступно сейчас', $result->get_message() );
	}

	public function test_reset_for_tests_forces_a_fresh_collection(): void {
		$this->stub_filter_tools( [ $this->tool( 'a' ) ] );
		$this->assertTrue( Shipping_Tools_Registry::instance()->has_tools() );

		Shipping_Tools_Registry::reset_for_tests();
		$this->stub_filter_tools( [] );

		$this->assertFalse( Shipping_Tools_Registry::instance()->has_tools() );
	}
}
