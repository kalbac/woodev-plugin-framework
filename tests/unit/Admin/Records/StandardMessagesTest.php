<?php
/**
 * The sentences every records table shares.
 *
 * @package Woodev\Tests\Unit\Admin\Records
 */

namespace Woodev\Tests\Unit\Admin\Records;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Standard_Messages;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Definition;
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
		$groups = [ Standard_Messages::bulk(), Standard_Messages::matching(), Standard_Messages::views(), Standard_Messages::pager(), Standard_Messages::period() ];
		$merged = array_merge( ...$groups );

		$this->assertCount( array_sum( array_map( 'count', $groups ) ), $merged, 'No key is sent by two groups.' );

		$definition = Records_Definition::from_array( Records_Definitions::standard( [ 'messages' => $merged ] ) );

		$this->assertSame( $merged, $definition->to_array()['messages'] );
	}

	public function test_the_pager_has_the_keys_the_footer_reads_and_the_placeholders_it_fills(): void {
		$messages = Standard_Messages::pager();

		$this->assertSame( [ 'page_status', 'page_prev', 'page_next', 'page_goto' ], array_keys( $messages ) );
		$this->assertStringContainsString( '{page}', $messages['page_status'] );
		$this->assertStringContainsString( '{pages}', $messages['page_status'] );
	}

	public function test_the_matching_bar_has_every_key_the_shell_reads_and_the_placeholders_it_fills(): void {
		$messages = Standard_Messages::matching();

		$this->assertSame( [ 'selected_page', 'select_all_matching', 'selected_all_matching', 'clear_selection' ], array_keys( $messages ) );
		$this->assertStringContainsString( '{count}', $messages['select_all_matching'] );
		$this->assertStringContainsString( '{count}', $messages['selected_all_matching'] );
		$this->assertStringContainsString( '{filters}', $messages['selected_all_matching'] );
	}

	public function test_every_definition_with_an_all_matching_action_carries_the_matching_sentences(): void {
		$definitions = [
			Site_Products_Definition::build( [], 'Ozon', [] ),
			Remote_Cards_Definition::build( [], 'Ozon' ),
		];
		$declaring   = 0;

		foreach ( $definitions as $definition ) {
			if ( ! $this->declares_all_matching( $definition ) ) {
				continue;
			}

			++$declaring;

			foreach ( array_keys( Standard_Messages::matching() ) as $key ) {
				$this->assertNotSame( '', $definition['messages'][ $key ] ?? '', "A definition with all_matching lacks «{$key}»." );
			}
		}

		$this->assertGreaterThan( 0, $declaring, 'The check must cover at least one definition that declares all_matching.' );
	}

	/**
	 * @param array<string,mixed> $definition A definition as `to_array()` sends it.
	 */
	private function declares_all_matching( array $definition ): bool {
		foreach ( $definition['bulk_actions'] as $action ) {
			if ( true === ( $action['all_matching'] ?? false ) ) {
				return true;
			}
		}

		return false;
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
