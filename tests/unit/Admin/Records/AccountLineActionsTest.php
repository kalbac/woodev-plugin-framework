<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\{Action_Input, Activity_Normalizer, Records_Definition};
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Action_Test_Doubles.php';

/**
 * The pieces of the «every row under the filter» card that are not the request itself: the `hidden` field type,
 * the `all_matching` flag of an action, and the buttons of the account line.
 */
class AccountLineActionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );
	}

	/**
	 * The shared definition plus an account action that carries its run id in a hidden field.
	 *
	 * @param array<string,mixed> $stop Keys of the `bulk_stop` action to replace.
	 * @return array<string,mixed>
	 */
	private static function definition( array $stop = [] ): array {
		$definition                   = Action_Definitions::with_actions();
		$definition['bulk_actions'][] = array_merge(
			[
				'id'             => 'bulk_stop',
				'label'          => 'Остановить',
				'effect'         => 'mutate',
				'interaction'    => 'dialog',
				'scope'          => 'account',
				'scope_sentence' => 'Остановит выполняющееся действие.',
				'fields'         => [
					[ 'id' => 'run_id', 'label' => 'Запуск', 'type' => 'hidden', 'required' => true ],
				],
			],
			$stop
		);

		return $definition;
	}

	private function parsed( ?array $definition = null ): Records_Definition {
		return Records_Definition::from_array( $definition ?? self::definition() );
	}

	// -- all_matching ----------------------------------------------------------------------------

	public function test_a_selection_bulk_action_may_declare_all_matching_and_the_default_is_false(): void {
		$definition                          = Action_Definitions::with_actions();
		$definition['bulk_actions'][0]['all_matching'] = true;
		$parsed                              = $this->parsed( $definition );

		$this->assertTrue( $parsed->get_action( 'export' )['all_matching'] );
		$this->assertFalse( $parsed->get_action( 'link_all' )['all_matching'] );
		$this->assertFalse( $parsed->get_action( 'sync' )['all_matching'] );
	}

	public function test_an_account_action_may_not_declare_all_matching(): void {
		$definition                          = Action_Definitions::with_actions();
		$definition['bulk_actions'][1]['all_matching'] = true;

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'all_matching' );
		$this->parsed( $definition );
	}

	public function test_a_row_action_may_not_declare_all_matching(): void {
		$definition                          = Action_Definitions::with_actions();
		$definition['row_actions'][1]['all_matching'] = true;

		$this->expectException( \InvalidArgumentException::class );
		$this->parsed( $definition );
	}

	public function test_all_matching_must_be_a_boolean(): void {
		$definition                          = Action_Definitions::with_actions();
		$definition['bulk_actions'][0]['all_matching'] = 'yes';

		$this->expectException( \InvalidArgumentException::class );
		$this->parsed( $definition );
	}

	// -- hidden ----------------------------------------------------------------------------------

	public function test_a_hidden_field_is_a_declared_field_type(): void {
		$this->assertContains( 'hidden', Records_Definition::FIELD_TYPES );
		$this->assertSame( 'hidden', $this->parsed()->get_action_field( 'bulk_stop', 'run_id' )['type'] );
	}

	public function test_a_hidden_field_is_validated_like_any_other(): void {
		$action = $this->parsed()->get_action( 'bulk_stop' );

		$this->assertSame( [ 'run_id' => 'r9f2' ], Action_Input::validate( $action, [ 'run_id' => ' r9f2 ' ] ) );

		foreach ( [ [], [ 'run_id' => '' ], [ 'run_id' => 7 ], [ 'run_id' => [ 'a' ] ], [ 'run_id' => str_repeat( 'x', 501 ) ], [ 'run_id' => "a\nb" ] ] as $payload ) {
			$result = Action_Input::validate( $action, $payload );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 422, $result->get_error_data()['status'] );
			$this->assertArrayHasKey( 'run_id', $result->get_error_data()['errors'] );
		}
	}

	public function test_a_payload_key_the_action_does_not_declare_is_still_refused(): void {
		$result = Action_Input::validate( $this->parsed()->get_action( 'bulk_stop' ), [ 'run_id' => 'r1', 'other' => 'x' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// -- the account line ------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $extra Keys of the account activity to add.
	 * @return array<string,mixed>
	 */
	private function line( array $extra ): array {
		return Activity_Normalizer::account( $this->parsed(), array_merge( [ 'active' => true, 'text' => 'Идёт обработка.' ], $extra ) );
	}

	public function test_the_account_line_carries_its_buttons_with_their_payload(): void {
		$out = $this->line(
			[
				'actions' => [
					[ 'action' => 'bulk_stop', 'label' => 'Остановить', 'payload' => [ 'run_id' => 'r9f2' ], 'secret' => 'dropped' ],
					[ 'action' => 'link_all', 'label' => 'Подробнее' ],
				],
			]
		);

		$this->assertSame(
			[
				[ 'action' => 'bulk_stop', 'label' => 'Остановить', 'payload' => [ 'run_id' => 'r9f2' ] ],
				[ 'action' => 'link_all', 'label' => 'Подробнее', 'payload' => [] ],
			],
			$out['actions']
		);
	}

	public function test_a_line_without_buttons_has_no_actions_key(): void {
		$this->assertArrayNotHasKey( 'actions', $this->line( [] ) );
		$this->assertArrayNotHasKey( 'actions', $this->line( [ 'actions' => [] ] ) );
		$this->assertArrayNotHasKey( 'actions', Activity_Normalizer::account( $this->parsed(), null ) );
	}

	public function test_a_finished_line_with_buttons_may_be_idle_but_must_say_what_it_is(): void {
		$out = $this->line( [ 'active' => false, 'text' => 'Готово.', 'actions' => [ [ 'action' => 'link_all', 'label' => 'Подробнее' ] ] ] );

		$this->assertFalse( $out['active'] );
		$this->assertCount( 1, $out['actions'] );

		$this->expectException( \UnexpectedValueException::class );
		Activity_Normalizer::account( $this->parsed(), [ 'active' => false, 'actions' => [ [ 'action' => 'link_all', 'label' => 'Подробнее' ] ] ] );
	}

	/**
	 * @dataProvider broken_buttons
	 *
	 * @param mixed $actions Provider `actions`.
	 */
	public function test_a_button_that_breaks_the_contract_is_refused( $actions ): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->line( [ 'actions' => $actions ] );
	}

	public function broken_buttons(): array {
		$stop = [ 'action' => 'bulk_stop', 'label' => 'Остановить', 'payload' => [ 'run_id' => 'r1' ] ];

		return [
			'more than two'                   => [ [ $stop, [ 'action' => 'link_all', 'label' => 'A' ], [ 'action' => 'link_all', 'label' => 'B' ] ] ],
			'not a list'                      => [ [ 'stop' => $stop ] ],
			'an action that is not declared'  => [ [ [ 'action' => 'nope', 'label' => 'X' ] ] ],
			'a selection action'              => [ [ [ 'action' => 'export', 'label' => 'X' ] ] ],
			'a link action'                   => [ [ [ 'action' => 'open', 'label' => 'X' ] ] ],
			'no label'                        => [ [ [ 'action' => 'link_all' ] ] ],
			'a label of tags only'            => [ [ [ 'action' => 'link_all', 'label' => '<b></b>' ] ] ],
			'the same action twice'           => [ [ $stop, $stop ] ],
			'a payload key the action lacks'  => [ [ [ 'action' => 'bulk_stop', 'label' => 'X', 'payload' => [ 'other' => 'a' ] ] ] ],
			'a payload value that is no string' => [ [ [ 'action' => 'bulk_stop', 'label' => 'X', 'payload' => [ 'run_id' => 5 ] ] ] ],
			'a payload value that is long'    => [ [ [ 'action' => 'bulk_stop', 'label' => 'X', 'payload' => [ 'run_id' => str_repeat( 'x', 201 ) ] ] ] ],
			'a payload that is a list'        => [ [ [ 'action' => 'bulk_stop', 'label' => 'X', 'payload' => [ 'r1' ] ] ] ],
		];
	}
}
