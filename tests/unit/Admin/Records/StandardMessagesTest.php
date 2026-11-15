<?php
/**
 * The sentences every records table shares.
 *
 * @package Woodev\Tests\Unit\Admin\Records
 */

namespace Woodev\Tests\Unit\Admin\Records;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Standard_Messages;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Records_Fixtures.php';

/**
 * @covers \Woodev\Framework\Admin\Records\Standard_Messages
 */
final class StandardMessagesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );
	}

	public function test_the_groups_are_accepted_as_definition_messages_and_do_not_overlap(): void {
		$groups = [ Standard_Messages::bulk(), Standard_Messages::views(), Standard_Messages::period() ];
		$merged = array_merge( ...$groups );

		$this->assertCount( array_sum( array_map( 'count', $groups ) ), $merged, 'No key is sent by two groups.' );

		$definition = Records_Definition::from_array( Records_Definitions::standard( [ 'messages' => $merged ] ) );

		$this->assertSame( $merged, $definition->to_array()['messages'] );
	}

	public function test_the_period_picker_has_a_label_for_every_preset_the_shell_draws(): void {
		$keys = array_keys( Standard_Messages::period() );

		foreach ( [ 'today', 'yesterday', 'week', 'last_week', 'month', 'last_month', 'quarter', 'last_quarter', 'year', 'last_year' ] as $preset ) {
			$this->assertContains( 'period_' . $preset, $keys );
		}
	}

	public function test_the_apply_button_of_the_period_is_not_the_bare_apply_of_the_bulk_action(): void {
		$this->assertNotSame( Standard_Messages::bulk()['bulk_apply'], Standard_Messages::period()['period_apply'] );
	}
}
