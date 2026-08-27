<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Framework\Shipping\Settings\Shipping_Tool;
use Woodev\Framework\Shipping\Settings\Tool_Result;

require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/settings/class-shipping-tool.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/settings/class-tool-result.php';


class SettingsSectionTest extends TestCase {

	public function test_create_exposes_id_label_and_setting_ids(): void {
		$section = Settings_Section::create( 'general', 'Общие', [ 'api_key', 'mode' ] );

		$this->assertSame( 'general', $section->get_id() );
		$this->assertSame( 'Общие', $section->get_label() );
		$this->assertSame( [ 'api_key', 'mode' ], $section->get_setting_ids() );
	}

	public function test_setting_ids_are_reindexed(): void {
		$section = Settings_Section::create( 'x', 'X', [ 2 => 'a', 5 => 'b' ] );

		$this->assertSame( [ 'a', 'b' ], $section->get_setting_ids() );
	}

	public function test_section_defaults_to_non_connection(): void {
		$section = Settings_Section::create( 'general', 'Общие', [ 'a' ] );

		$this->assertFalse( $section->is_connection() );
		$this->assertSame( '', $section->get_action_label() );
	}

	public function test_connection_section_carries_action_label(): void {
		$section = Settings_Section::create_connection( 'api', 'Подключение', [ 'token' ], 'Проверить', 'Креды API.' );

		$this->assertTrue( $section->is_connection() );
		$this->assertSame( 'Проверить', $section->get_action_label() );
	}

	public function test_section_defaults_to_non_tools(): void {
		$section = Settings_Section::create( 'general', 'Общие', [ 'a' ] );

		$this->assertFalse( $section->is_tools() );
		$this->assertSame( [], $section->get_tools() );
	}

	public function test_tools_section_carries_tool_descriptors(): void {
		$tool    = $this->make_tool( 'noop' );
		$section = Settings_Section::create_tools( 'tools', 'Инструменты', [ $tool ], 'Действия.' );

		$this->assertTrue( $section->is_tools() );
		$this->assertSame( [ $tool ], $section->get_tools() );
		$this->assertSame( 'Действия.', $section->get_description() );
	}

	/**
	 * A tools section is fields-less BY CONSTRUCTION (#514 m6) — create_tools() takes no
	 * setting ids at all, so there is no call site that can give one some.
	 */
	public function test_tools_section_declares_no_setting_ids(): void {
		$section = Settings_Section::create_tools( 'tools', 'Инструменты', [ $this->make_tool( 'noop' ) ] );

		$this->assertSame( [], $section->get_setting_ids() );
	}

	public function test_tools_are_reindexed(): void {
		$first  = $this->make_tool( 'a' );
		$second = $this->make_tool( 'b' );

		$section = Settings_Section::create_tools( 'tools', 'Инструменты', [ 5 => $first, 9 => $second ] );

		$this->assertSame( [ $first, $second ], $section->get_tools() );
	}

	/**
	 * #514 m6 / critic N3: create_tools() is the LOUD half of tool validation — it names the
	 * offending call site, which the read-side filter in get_tools() cannot. It is not the
	 * only door (see that method's own test below), and the guarantee both readers of
	 * get_tools() rely on is the accessor's, not this one's.
	 */
	public function test_create_tools_drops_a_non_conforming_entry_and_keeps_the_rest(): void {
		$conforming = $this->make_tool( 'sweep' );

		Functions\expect( '_doing_it_wrong' )->once();

		$section = Settings_Section::create_tools( 'tools', 'Инструменты', [ $conforming, 'not-a-tool' ] );

		$this->assertSame( [ $conforming ], $section->get_tools() );
	}

	/**
	 * The control for the test above: a conforming-only list raises nothing. Without it,
	 * `->once()` would pass for an implementation that warned on every entry.
	 */
	public function test_create_tools_is_silent_when_every_entry_conforms(): void {
		Functions\expect( '_doing_it_wrong' )->never();

		$section = Settings_Section::create_tools( 'tools', 'Инструменты', [ $this->make_tool( 'sweep' ) ] );

		$this->assertCount( 1, $section->get_tools() );
	}

	/**
	 * The critic's BLOCKER on #514 m6, promoted to a permanent test.
	 *
	 * Constructor privacy is NOT what keeps `$tools` clean.
	 * `ReflectionClass::newInstanceWithoutConstructor()` builds this `final` class without
	 * running the constructor, and `ReflectionProperty` then writes the array directly.
	 * With the validation living only in `create_tools()`, that object reached both readers
	 * of `get_tools()` and fatalled `Settings_Page_Registry::build_sections()` with
	 * `Call to a member function to_array() on string` — a whole-settings-page fatal, for
	 * every tab, from one plugin's descriptor.
	 *
	 * Reproduced before the fix; this pins the answer. `get_tools()` filters on READ, so the
	 * hydration route no longer matters to any reader.
	 */
	public function test_get_tools_filters_a_descriptor_hydrated_past_the_constructor(): void {
		$conforming = $this->make_tool( 'sweep' );
		$reflection = new \ReflectionClass( Settings_Section::class );
		$section    = $reflection->newInstanceWithoutConstructor();

		foreach ( [ 'id' => 'tools', 'label' => 'Инструменты', 'setting_ids' => [], 'description' => '', 'is_connection' => false, 'action_label' => '', 'is_tools' => true, 'tools' => [ $conforming, 'not-a-tool' ] ] as $property => $value ) {
			$reflection->getProperty( $property )->setValue( $section, $value );
		}

		$this->assertTrue( $section->is_tools() );
		$this->assertSame( [ $conforming ], $section->get_tools() );
	}

	/**
	 * The control: the same reflection route with a wholly conforming list returns it intact.
	 * Without it, the assertion above would also pass for a `get_tools()` that returned `[]`.
	 */
	public function test_control_get_tools_returns_a_conforming_hydrated_list_intact(): void {
		$first      = $this->make_tool( 'a' );
		$second     = $this->make_tool( 'b' );
		$reflection = new \ReflectionClass( Settings_Section::class );
		$section    = $reflection->newInstanceWithoutConstructor();

		foreach ( [ 'id' => 'tools', 'label' => 'Инструменты', 'setting_ids' => [], 'description' => '', 'is_connection' => false, 'action_label' => '', 'is_tools' => true, 'tools' => [ $first, $second ] ] as $property => $value ) {
			$reflection->getProperty( $property )->setValue( $section, $value );
		}

		$this->assertSame( [ $first, $second ], $section->get_tools() );
	}

	/**
	 * Builds a minimal conforming tool descriptor.
	 */
	private function make_tool( string $id ): Shipping_Tool {
		return Shipping_Tool::create(
			$id,
			'Проверить',
			'',
			'Проверить',
			static fn( array $args ): Tool_Result => Tool_Result::success()
		);
	}
}
