<?php
/**
 * UI-kit gallery page integration tests — dev gate + submenu registration.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

use Woodev\Framework\Admin\Ui_Kit_Gallery_Page;

class UiKitGalleryPageTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'woodev_ui_kit_gallery' );
		parent::tear_down();
	}

	public function test_gallery_disabled_by_default(): void {
		$page = new Ui_Kit_Gallery_Page();

		$this->assertFalse( $page->is_enabled() );
	}

	public function test_gallery_enabled_via_filter(): void {
		add_filter( 'woodev_ui_kit_gallery', '__return_true' );

		$page = new Ui_Kit_Gallery_Page();

		$this->assertTrue( $page->is_enabled() );
	}

	public function test_gallery_registers_submenu_when_registered(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		global $submenu;
		unset( $submenu['woodev'] );

		$page = new Ui_Kit_Gallery_Page( woodev_test_plugin() );
		$page->register_page();

		$slugs = array_column( $submenu['woodev'] ?? [], 2 );

		$this->assertContains( Ui_Kit_Gallery_Page::PAGE_SLUG, $slugs );
	}
}
