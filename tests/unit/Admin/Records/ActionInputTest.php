<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\{Action_Input, Records_Definition};
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Action_Test_Doubles.php';

/**
 * The payload of an action against its declared fields.
 */
class ActionInputTest extends TestCase {

	/** @var array<string,mixed> */
	private $action;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );

		$this->action = Records_Definition::from_array( Action_Definitions::with_actions() )->get_action( 'export' );
	}

	/**
	 * @param array<string,mixed> $payload Payload; the required warehouse is added unless given.
	 * @return mixed
	 */
	private function validate( array $payload ) {
		return Action_Input::validate( $this->action, $payload + [ 'warehouse' => 'w1' ] );
	}

	/**
	 * @param mixed  $result Result of validate().
	 * @param string $field  Field id expected in the 422.
	 */
	private function assertFieldError( $result, string $field ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( $field, $result->get_error_data()['errors'] );
	}

	public function test_a_complete_payload_is_typed(): void {
		$typed = $this->validate( [ 'mode' => 'full', 'qty' => 3, 'comment' => ' hi ', 'notify' => true, 'tags' => [ 'b' ] ] );

		$this->assertSame( [ 'warehouse' => 'w1', 'mode' => 'full', 'qty' => 3, 'comment' => 'hi', 'notify' => true, 'tags' => [ 'b' ] ], $typed );
	}

	public function test_a_field_that_was_not_sent_is_not_in_the_result(): void {
		$this->assertSame( [ 'warehouse' => 'w1' ], $this->validate( [] ), 'an omitted boolean is not false' );
	}

	public function test_a_required_field_must_be_sent_and_not_empty(): void {
		$this->assertFieldError( Action_Input::validate( $this->action, [] ), 'warehouse' );
		$this->assertFieldError( Action_Input::validate( $this->action, [ 'warehouse' => '' ] ), 'warehouse' );
		$this->assertFieldError( Action_Input::validate( $this->action, [ 'warehouse' => null ] ), 'warehouse' );
	}

	/**
	 * @dataProvider bad_values
	 * @param mixed $value Value that must be refused.
	 */
	public function test_a_wrong_value_is_a_field_error( string $field, $value ): void {
		$this->assertFieldError( $this->validate( [ $field => $value ] ), $field );
	}

	/** @return array<string,array{0:string,1:mixed}> */
	public function bad_values(): array {
		return [
			'select outside its options' => [ 'mode', 'slow' ],
			'select as a list'           => [ 'mode', [ 'full' ] ],
			'select with a bad token'    => [ 'warehouse', 'w 1' ],
			'multi_select as a string'   => [ 'tags', 'a' ],
			'multi_select unknown'       => [ 'tags', [ 'a', 'z' ] ],
			'multi_select duplicate'     => [ 'tags', [ 'a', 'a' ] ],
			'number as text'             => [ 'qty', 'many' ],
			'number with exponent'       => [ 'qty', '1e3' ],
			'number NaN'                 => [ 'qty', NAN ],
			'number as a list'           => [ 'qty', [ 1 ] ],
			'boolean as a string'        => [ 'notify', 'true' ],
			'boolean as an int'          => [ 'notify', 1 ],
			'textarea as a number'       => [ 'comment', 5 ],
			'textarea too long'          => [ 'comment', str_repeat( 'я', 2001 ) ],
			'textarea with NUL'          => [ 'comment', "a\0b" ],
		];
	}

	public function test_single_line_text_refuses_line_breaks_and_length(): void {
		$action = Records_Definition::from_array( Action_Definitions::with_actions() )->get_action( 'sync' );

		$this->assertSame( [ 'note' => 'ok' ], Action_Input::validate( $action, [ 'note' => 'ok' ] ) );
		$this->assertFieldError( Action_Input::validate( $action, [ 'note' => "a\nb" ] ), 'note' );
		$this->assertFieldError( Action_Input::validate( $action, [ 'note' => str_repeat( 'x', 501 ) ] ), 'note' );
	}

	public function test_a_source_backed_select_is_checked_by_shape_only(): void {
		$this->assertSame( [ 'warehouse' => 'any-warehouse:1' ], Action_Input::validate( $this->action, [ 'warehouse' => 'any-warehouse:1' ] ) );
	}

	public function test_an_empty_optional_choice_is_accepted_as_empty(): void {
		$this->assertSame( [ 'warehouse' => 'w1', 'mode' => '', 'tags' => [] ], $this->validate( [ 'mode' => '', 'tags' => [] ] ) );
	}

	public function test_decimal_strings_become_numbers(): void {
		$this->assertSame( 12, $this->validate( [ 'qty' => '12' ] )['qty'] );
		$this->assertSame( -0.5, $this->validate( [ 'qty' => '-0.5' ] )['qty'] );
	}

	public function test_an_unknown_key_or_a_list_is_a_400(): void {
		$unknown = $this->validate( [ 'price' => '1' ] );
		$list    = Action_Input::validate( $this->action, [ 'w1' ] );

		foreach ( [ $unknown, $list ] as $result ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}

	public function test_an_action_without_fields_takes_an_empty_payload_only(): void {
		$action = Records_Definition::from_array( Action_Definitions::with_actions() )->get_action( 'link_all' );

		$this->assertSame( [], Action_Input::validate( $action, [] ) );
		$this->assertSame( 400, Action_Input::validate( $action, [ 'x' => 1 ] )->get_error_data()['status'] );
	}
}
