<?php
/**
 * Plugin action links handler tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Handlers\Plugin_Action_Links_Handler;

require_once dirname( __DIR__, 3 ) . '/woodev/handlers/class-plugin-action-links-handler.php';

/**
 * Class PluginActionLinksHandlerTest.
 */
class PluginActionLinksHandlerTest extends TestCase {

	/**
	 * Builds a plugin test double exposing the accessors the handler relies on.
	 *
	 * @param array $overrides Accessor return-value overrides, keyed by method name.
	 * @return \Woodev_Plugin&\Mockery\MockInterface
	 */
	private function make_plugin( array $overrides = [] ) {
		$plugin = Mockery::mock( \Woodev_Plugin::class );

		$defaults = [
			'get_plugin_file'      => 'acme/acme.php',
			'get_id'                => 'acme',
			'get_settings_link'     => '',
			'get_documentation_url' => '',
			'get_support_url'       => '',
			'get_reviews_url'       => '',
			'is_need_license'       => false,
		];

		foreach ( array_merge( $defaults, $overrides ) as $method => $value ) {
			$plugin->shouldReceive( $method )->andReturn( $value );
		}

		return $plugin;
	}

	/**
	 * Constructing the handler registers the plugin_action_links_{basename} filter,
	 * bound to the plugin instance (not the handler) so an override on the concrete
	 * plugin class keeps firing via parent::plugin_action_links().
	 *
	 * @return void
	 */
	public function test_constructor_registers_filter_bound_to_plugin(): void {
		$plugin = $this->make_plugin();

		Functions\when( 'plugin_basename' )->returnArg( 1 );

		Functions\expect( 'add_filter' )
			->once()
			->with(
				'plugin_action_links_acme/acme.php',
				[ $plugin, 'plugin_action_links' ]
			);

		new Plugin_Action_Links_Handler( $plugin );
	}

	/**
	 * build_links() adds the Configure/Docs/Support/Review entries when the
	 * corresponding plugin accessors return a value, and merges them in front
	 * of the incoming actions.
	 *
	 * @return void
	 */
	public function test_build_links_adds_available_entries_in_front_of_actions(): void {
		$plugin = $this->make_plugin(
			[
				'get_settings_link'     => '<a href="settings">Настройки</a>',
				'get_documentation_url' => 'https://example.com/docs',
				'get_support_url'       => 'https://example.com/support',
				'get_reviews_url'       => 'https://example.com/reviews',
			]
		);

		Functions\when( 'plugin_basename' )->returnArg( 1 );
		Functions\when( 'add_filter' )->justReturn( true );

		$handler = new Plugin_Action_Links_Handler( $plugin );

		$links = $handler->build_links( [ 'deactivate' => '<a href="#">Deactivate</a>' ] );

		$this->assertSame(
			[ 'configure', 'docs', 'support', 'review', 'deactivate' ],
			array_keys( $links )
		);
		$this->assertSame( '<a href="settings">Настройки</a>', $links['configure'] );
		$this->assertStringContainsString( 'https://example.com/docs', $links['docs'] );
		$this->assertStringContainsString( 'https://example.com/support', $links['support'] );
		$this->assertStringContainsString( 'https://example.com/reviews', $links['review'] );
	}

	/**
	 * build_links() omits entries whose plugin accessor returns an empty value,
	 * and never adds a license link when the plugin does not require one.
	 *
	 * @return void
	 */
	public function test_build_links_omits_missing_entries(): void {
		$plugin = $this->make_plugin();

		Functions\when( 'plugin_basename' )->returnArg( 1 );
		Functions\when( 'add_filter' )->justReturn( true );

		$handler = new Plugin_Action_Links_Handler( $plugin );

		$links = $handler->build_links( [] );

		$this->assertSame( [], $links );
	}

	/**
	 * build_links() adds a license link, labelled by validity, when the plugin
	 * needs a license and a license settings URL is available.
	 *
	 * @return void
	 */
	public function test_build_links_adds_license_entry_when_license_required(): void {
		$license = Mockery::mock();
		$license->shouldReceive( 'get_license_settings_url' )->andReturn( 'https://example.com/license' );
		$license->shouldReceive( 'is_license_valid' )->andReturn( false );

		$plugin = $this->make_plugin( [ 'is_need_license' => true ] );
		$plugin->shouldReceive( 'get_license_instance' )->andReturn( $license );

		Functions\when( 'plugin_basename' )->returnArg( 1 );
		Functions\when( 'add_filter' )->justReturn( true );

		$handler = new Plugin_Action_Links_Handler( $plugin );

		$links = $handler->build_links( [] );

		$this->assertArrayHasKey( 'license', $links );
		$this->assertStringContainsString( 'https://example.com/license', $links['license'] );
		$this->assertStringContainsString( 'Указать лицензию', $links['license'] );
	}
}
