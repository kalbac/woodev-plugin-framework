<?php
/**
 * Tests for Woodev_Notes_Helper::add_note() (s12 — Finding B breadcrumb creator).
 *
 * The unit suite has no WooCommerce, so the WC Admin Notes API is absent. add_note()
 * must self-guard and return false WITHOUT side effects in that case — this is what
 * lets the heavily mocked remote-deactivation command call it unconditionally on the
 * executed path without tripping any of its persistence-seam assertions.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/admin/class-notes-helper.php';

/**
 * Class NotesHelperTest.
 */
class NotesHelperTest extends TestCase {

	/**
	 * Without the WooCommerce Admin Notes API, add_note() is a no-op returning false.
	 *
	 * @return void
	 */
	public function test_add_note_returns_false_without_wc_admin(): void {

		$this->assertFalse(
			class_exists( '\Automattic\WooCommerce\Admin\Notes\Note' ),
			'Guard precondition: the unit suite must NOT have the WC Admin Notes API.'
		);

		$this->assertFalse(
			\Woodev_Notes_Helper::add_note(
				'woodev-test-remote-deactivated',
				'woodev-test',
				'Плагин Test отключён',
				'Лицензия недействительна для этого сайта.',
				array(
					array(
						'name'  => 'support',
						'label' => 'Связаться с нами',
						'url'   => 'https://woodev.ru/support/',
					),
				)
			),
			'add_note() must return false (no-op) when WooCommerce Admin is unavailable.'
		);
	}
}
