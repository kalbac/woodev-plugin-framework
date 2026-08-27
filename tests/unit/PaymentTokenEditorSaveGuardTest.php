<?php
/**
 * Regression tests for #393: saving a user profile that did NOT carry a gateway's token
 * editor deleted every saved token for that gateway.
 *
 * `Admin_User_Handler::display_token_editors()` SKIPS an editor whose gateway supports a
 * customer id when the customer has none saved yet (`continue`), while `save_tokens()` calls
 * `save()` on EVERY registered editor unconditionally. `save()` read a missing `$_POST`
 * section as `array()` and handed it to `update_tokens()`, which reads an empty array as "the
 * admin removed every token".
 *
 * So opening such a customer's profile and pressing «Обновить пользователя» for any unrelated
 * reason — a display-name typo — deleted every card they had with that gateway. The same on
 * any `edit_user_profile_update` / `personal_options_update` fired without token fields.
 *
 * ABSENCE IS AMBIGUOUS, which is why the fix is a rendered-marker input rather than an
 * `isset()` on the tokens key: an editor rendered with ZERO rows posts no token inputs either.
 * These tests pin both readings apart.
 *
 * Permissions are not at issue and never were — `save_profile_fields()` is gated on
 * `manage_woocommerce`. This is data loss, not an access hole.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/exceptions/class-payment-gateway-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/admin/class-payment-gateway-admin-payment-token-editor.php';

	/**
	 * Records what `save()` ultimately asked the tokens handler to store.
	 */
	class Token_Editor_Save_Guard_Test_Editor extends \Woodev_Payment_Gateway_Admin_Payment_Token_Editor {

		/** @var array<int,array{0:mixed,1:mixed}> every update_tokens() call, in order. */
		public $updates = [];

		public function get_input_name_for_test(): string {
			return $this->get_input_name();
		}

		public function get_rendered_marker_name_for_test(): string {
			return $this->get_rendered_marker_name();
		}

		protected function get_input_name() {
			return 'wc_payment_gateway_stub_tokens';
		}

		protected function update_tokens( $user_id, $tokens ) {
			$this->updates[] = [ $user_id, $tokens ];
		}

		protected function validate_token_data( $token_id, $data ) {
			return $data;
		}

		protected function build_token( $user_id, $token_id, $data ) {
			return $token_id;
		}
	}

}

namespace Woodev\Tests\Unit {

	/**
	 * @covers \Woodev_Payment_Gateway_Admin_Payment_Token_Editor::save
	 */
	class PaymentTokenEditorSaveGuardTest extends TestCase {

		/** @var \Token_Editor_Save_Guard_Test_Editor|null */
		private $editor;

		protected function setUp(): void {
			parent::setUp();

			$reflection   = new \ReflectionClass( \Token_Editor_Save_Guard_Test_Editor::class );
			$this->editor = $reflection->newInstanceWithoutConstructor();

			$_POST = [];
		}

		protected function tearDown(): void {
			$_POST = [];

			parent::tearDown();
		}

		/**
		 * THE DEFECT. No marker in the POST means this editor was never on the form, so
		 * nothing may be written — least of all an empty token set.
		 */
		public function test_an_editor_that_was_not_on_the_form_writes_nothing(): void {
			$this->editor->save( 7 );

			$this->assertSame( [], $this->editor->updates, 'update_tokens() must not be reached at all' );
		}

		/**
		 * The half that makes the marker necessary rather than an `isset()` on the tokens key:
		 * an editor that WAS rendered and came back with no rows means the admin removed the
		 * last card, and that must still persist.
		 */
		public function test_a_rendered_editor_with_no_rows_still_clears_the_tokens(): void {
			$_POST[ $this->editor->get_rendered_marker_name_for_test() ] = '1';

			$this->editor->save( 7 );

			$this->assertSame( [ [ 7, [] ] ], $this->editor->updates );
		}

		/**
		 * The control: a rendered editor carrying rows still stores them. Without it, the two
		 * assertions above would both pass for a `save()` that had stopped working entirely.
		 */
		public function test_control_a_rendered_editor_with_rows_stores_them(): void {
			$_POST[ $this->editor->get_rendered_marker_name_for_test() ] = '1';
			$_POST[ $this->editor->get_input_name_for_test() ]           = [
				[
					'id'   => 'tok_123',
					'type' => 'echeck',
				],
			];

			$this->editor->save( 7 );

			$this->assertCount( 1, $this->editor->updates );
			$this->assertSame( 7, $this->editor->updates[0][0] );
			$this->assertArrayHasKey( 'tok_123', $this->editor->updates[0][1] );
		}

		/**
		 * The marker name must be DERIVED from the input name, not a second literal: two
		 * gateways' editors post into the same request, and a shared marker would let one
		 * gateway's presence authorise wiping another's tokens.
		 */
		public function test_the_marker_name_is_derived_from_the_input_name(): void {
			$this->assertSame(
				$this->editor->get_input_name_for_test() . '_rendered',
				$this->editor->get_rendered_marker_name_for_test()
			);
		}

		/**
		 * THE RENDER SIDE OF THE CONTRACT, and the gap a critic pass named: every test above
		 * manufactures the marker in `$_POST` by hand, so deleting or renaming the hidden
		 * input in the VIEW leaves all of them green while every ordinary admin token edit
		 * silently does nothing — `save()` returns on an absent marker, which is the whole
		 * point of it.
		 *
		 * The view is asserted directly rather than through `display()`, which reaches for a
		 * real gateway and plugin to resolve its own path. What matters is that the file the
		 * editor includes emits the SAME name `save()` looks for, so the two are compared.
		 */
		public function test_the_view_emits_exactly_the_marker_save_looks_for(): void {
			$view = file_get_contents(
				dirname( __DIR__, 2 ) . '/woodev/payment-gateway/admin/views/html-user-payment-token-editor.php'
			);

			$this->assertStringContainsString(
				'name="<?php echo esc_attr( $rendered_marker_name ); ?>"',
				$view,
				'the view must print the marker input'
			);

			// And the variable it prints is the one display() sets from the accessor.
			$editor = file_get_contents(
				dirname( __DIR__, 2 ) . '/woodev/payment-gateway/admin/class-payment-gateway-admin-payment-token-editor.php'
			);

			$this->assertStringContainsString(
				'$rendered_marker_name = $this->get_rendered_marker_name();',
				$editor,
				'display() must hand the view the name save() reads'
			);
		}
	}

}
