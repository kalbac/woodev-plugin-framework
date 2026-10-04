<?php
/**
 * Plugin_Scope — the «only OUR errors» stack filter (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Woodev\Framework\Error_Reporting\Plugin_Scope;
use Woodev\Framework\Framework_Plugin_Loader_Definition;

/**
 * @covers \Woodev\Framework\Error_Reporting\Plugin_Scope
 */
final class PluginScopeTest extends ErrorReportingTestCase {

	public function test_a_file_in_a_registered_plugin_is_ours_and_attributed(): void {
		$owner = $this->make_scope()->match( [ self::OURS . '/includes/class-export.php' ] );

		$this->assertSame(
			[
				'id'      => 'acme-delivery',
				'version' => '1.4.0',
			],
			$owner
		);
	}

	public function test_a_foreign_plugin_theme_or_core_is_dropped(): void {
		$scope = $this->make_scope();

		$this->assertNull( $scope->match( [ self::OTHER . '/main.php', '/srv/wp/wp-includes/class-wp.php', '/srv/wp/wp-content/themes/t/functions.php' ] ) );
	}

	public function test_a_registered_plugin_is_matched_by_registry_not_by_a_woodev_name_pattern(): void {
		$scope = new Plugin_Scope(
			[
				[
					'id'      => 'woocommerce-edostavka',
					'version' => '3.0.0',
					'dir'     => '/srv/wp/wp-content/plugins/woocommerce-edostavka',
				],
			]
		);

		$this->assertSame( 'woocommerce-edostavka', $scope->match( [ '/srv/wp/wp-content/plugins/woocommerce-edostavka/a.php' ] )['id'] );
		// A look-alike directory that is NOT registered is foreign.
		$this->assertNull( $scope->match( [ '/srv/wp/wp-content/plugins/woodev-not-registered/a.php' ] ) );
	}

	public function test_the_framework_vendored_inside_our_plugin_counts_as_that_plugin(): void {
		$owner = $this->make_scope()->match( [ self::OURS . '/vendor/woodev/woodev/class-helper.php' ] );

		$this->assertSame( 'acme-delivery', $owner['id'] );
	}

	public function test_a_sibling_directory_sharing_a_name_prefix_is_not_ours(): void {
		$this->assertNull( $this->make_scope()->match( [ self::OURS . '-pro/main.php' ] ) );
	}

	public function test_a_foreign_throw_site_with_our_code_further_up_the_stack_is_ours(): void {
		$owner = $this->make_scope()->match( [ self::OTHER . '/lib.php', self::OURS . '/hooks.php' ] );

		$this->assertSame( 'acme-delivery', $owner['id'] );
	}

	public function test_the_innermost_owner_wins_when_two_plugins_are_on_the_stack(): void {
		$scope = new Plugin_Scope(
			[
				[
					'id'      => 'first',
					'version' => '1',
					'dir'     => '/p/first',
				],
				[
					'id'      => 'second',
					'version' => '2',
					'dir'     => '/p/second',
				],
			]
		);

		$this->assertSame( 'second', $scope->match( [ '/p/second/a.php', '/p/first/b.php' ] )['id'] );
	}

	public function test_windows_separators_are_normalised(): void {
		$scope = new Plugin_Scope(
			[
				[
					'id'      => 'win',
					'version' => '1',
					'dir'     => 'C:\\wp\\plugins\\win',
				],
			]
		);

		$this->assertSame( 'win', $scope->match( [ 'C:\\wp\\plugins\\win\\src\\a.php' ] )['id'] );
	}

	public function test_a_single_file_plugin_cannot_claim_the_whole_plugins_directory(): void {
		$scope = new Plugin_Scope(
			[
				[
					'id'      => 'single-file',
					'version' => '1',
					'dir'     => WP_PLUGIN_DIR,
				],
			]
		);

		$this->assertNull( $scope->match( [ self::OTHER . '/main.php' ] ) );
	}

	public function test_get_returns_a_registered_plugin_by_id_only(): void {
		$scope = $this->make_scope();

		$this->assertSame( '1.4.0', $scope->get( 'acme-delivery' )['version'] );
		$this->assertNull( $scope->get( 'nope' ) );
	}

	public function test_the_scope_is_built_from_the_resolver_registry_and_skips_legacy_entries(): void {
		$errors     = [];
		$definition = Framework_Plugin_Loader_Definition::from_array(
			[
				'plugin_id'         => 'acme-delivery',
				'plugin_name'       => 'Acme',
				'plugin_version'    => '1.4.0',
				'framework_version' => '2.0.1',
				'plugin_file'       => self::OURS . '/acme-delivery.php',
				'platform'          => Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
				'download_id'       => 77,
				'main_class'        => 'Acme_Plugin',
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
			],
			$errors
		);

		$this->assertNotNull( $definition, implode( '; ', $errors ) );

		$scope = Plugin_Scope::from_registered_plugins(
			[
				$definition->to_legacy_plugin(),
				[
					'plugin_name' => 'legacy',
					'path'        => '/x/legacy.php',
				],
			]
		);

		$this->assertSame( 'acme-delivery', $scope->match( [ self::OURS . '/src/a.php' ] )['id'] );
		$this->assertNull( $scope->match( [ '/x/other.php' ] ) );
	}

	public function test_a_script_url_is_located_under_its_plugin_and_anonymised(): void {
		$located = $this->make_browser_scope()->locate_url( 'http://Shop.Example.ru/wp-content/plugins/acme-delivery/assets/js/a.js?ver=1#x' );

		$this->assertSame(
			[
				'id'      => 'acme-delivery',
				'version' => '1.4.0',
				'path'    => 'plugins/acme-delivery/assets/js/a.js',
			],
			$located
		);
	}

	public function test_a_url_outside_every_plugin_root_is_not_located(): void {
		$scope = $this->make_browser_scope();

		$this->assertNull( $scope->locate_url( 'https://shop.example.ru/wp-content/plugins/other/a.js' ) );
		$this->assertNull( $scope->locate_url( self::OUR_URL . '-pro/a.js' ) );
		$this->assertNull( $scope->locate_url( self::OUR_URL ) );
		$this->assertNull( $scope->locate_url( self::OUR_URL . '/../x.js' ) );
		$this->assertNull( $scope->locate_url( '' ) );
	}

	public function test_a_plugin_without_a_url_has_no_url_claim_and_a_bare_host_is_refused(): void {
		$scope = new Plugin_Scope(
			[
				[
					'id'      => 'no-url',
					'version' => '1',
					'dir'     => '/srv/wp/wp-content/plugins/no-url',
				],
				[
					'id'      => 'bare-host',
					'version' => '1',
					'dir'     => '/srv/wp/wp-content/plugins/bare-host',
					'url'     => 'https://shop.example.ru',
				],
			]
		);

		$this->assertSame( [], $scope->url_bases() );
		$this->assertNull( $scope->locate_url( 'https://shop.example.ru/anything.js' ) );
	}

	public function test_a_single_file_plugin_is_refused_for_its_url_too(): void {
		$scope = new Plugin_Scope(
			[
				[
					'id'      => 'single-file',
					'version' => '1',
					'dir'     => WP_PLUGIN_DIR,
					'url'     => 'https://shop.example.ru/wp-content/plugins',
				],
			]
		);

		$this->assertSame( [], $scope->url_bases() );
		$this->assertNull( $scope->locate_url( 'https://shop.example.ru/wp-content/plugins/other/a.js' ) );
	}

	public function test_the_browser_is_told_the_base_urls_without_a_trailing_slash(): void {
		$scope = new Plugin_Scope(
			[
				[
					'id'      => 'a',
					'version' => '1',
					'dir'     => '/p/a',
					'url'     => 'https://shop.example.ru/wp-content/plugins/a/',
				],
			]
		);

		$this->assertSame( [ 'https://shop.example.ru/wp-content/plugins/a' ], $scope->url_bases() );
	}
}
