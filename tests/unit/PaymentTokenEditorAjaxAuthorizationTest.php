<?php
/**
 * Regression tests for GH-383: the payment-token editor AJAX endpoints
 * (Woodev_Payment_Gateway_Admin_Payment_Token_Editor::ajax_remove_token(),
 * ::ajax_refresh_tokens()) had no capability check at all — any logged-in user
 * could read and delete another user's saved payment tokens by supplying an
 * arbitrary `user_id`, using a nonce that enqueue_scripts_styles() localized
 * for every logged-in user on every admin screen.
 *
 * The fix adds a `current_user_can( 'manage_woocommerce' )` gate on every AJAX
 * handler (matching the capability already enforced by the outer
 * Woodev_Payment_Gateway_Admin_User_Handler::add_profile_section() /
 * save_profile_fields()), and gates enqueue_scripts_styles() on that same
 * capability plus the profile/user-edit admin screens, so the nonces are never
 * localized for an unauthorized visitor.
 *
 * Follow-up: `manage_woocommerce` alone does not authorize acting on an
 * arbitrary target user — WordPress checks object-level capabilities like
 * `edit_user` per target, so a shop manager holding `manage_woocommerce` is
 * not necessarily allowed to edit an administrator. ajax_remove_token() and
 * ajax_refresh_tokens() now also normalize the requested `user_id` to a
 * positive integer referencing an existing user and require
 * `current_user_can( 'edit_user', $user_id )` before touching anything.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/exceptions/class-payment-gateway-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/admin/class-payment-gateway-admin-payment-token-editor.php';

	if ( ! class_exists( 'WP_Screen', false ) ) {
		/**
		 * Minimal WP_Screen stub — Woodev_Helper::get_current_screen() declares a
		 * `?WP_Screen` return type, so the global must be an instance of it.
		 */
		class Token_Editor_IDOR_Test_WP_Screen_Stub {
			/** @var string */
			public $id;
		}

		class_alias( Token_Editor_IDOR_Test_WP_Screen_Stub::class, 'WP_Screen' );
	}

	/**
	 * Stub plugin backing the stub gateway — only the accessors
	 * enqueue_scripts_styles() actually calls.
	 */
	class Token_Editor_IDOR_Test_Plugin_Stub {

		public function get_payment_gateway_framework_assets_url() {
			return 'https://example.test/assets/payment-gateway';
		}
	}

	/**
	 * Stub gateway — avoids constructing a real Woodev_Payment_Gateway (a heavy
	 * WC_Payment_Gateway subclass) just to exercise the editor's AJAX handlers.
	 */
	class Token_Editor_IDOR_Test_Gateway_Stub {

		public function get_plugin() {
			return new Token_Editor_IDOR_Test_Plugin_Stub();
		}
	}

	/**
	 * Testable token editor: overrides the two protected/public methods that
	 * would otherwise reach into a real payment-tokens handler, and records
	 * whether — and with what arguments — they were invoked.
	 */
	class Token_Editor_IDOR_Test_Editor extends \Woodev_Payment_Gateway_Admin_Payment_Token_Editor {

		/** @var array<int,array{0:mixed,1:mixed}> */
		public $removed_tokens = [];

		/** @var bool */
		public $remove_token_return = true;

		/** @var array<int,mixed> */
		public $refreshed_user_ids = [];

		protected function remove_token( $user_id, $token_id ) {
			$this->removed_tokens[] = [ $user_id, $token_id ];

			return $this->remove_token_return;
		}

		public function display_tokens( $user_id ) {
			$this->refreshed_user_ids[] = $user_id;

			echo 'tokens-markup-for-' . $user_id;
		}
	}

}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;
	use Mockery;

	/**
	 * @covers \Woodev_Payment_Gateway_Admin_Payment_Token_Editor
	 */
	class PaymentTokenEditorAjaxAuthorizationTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'sanitize_text_field' )->returnArg();
			Functions\when( 'wp_unslash' )->returnArg();

			// Default: the requested target user exists. Tests covering an
			// absent/nonexistent user_id override this per-test.
			Functions\when( 'get_userdata' )->justReturn( true );

			$GLOBALS['current_screen'] = null;
		}

		protected function tearDown(): void {
			unset( $GLOBALS['current_screen'] );
			unset( $_REQUEST['user_id'], $_REQUEST['token_id'], $_REQUEST['index'] );

			parent::tearDown();
		}

		/**
		 * Builds a Token_Editor_IDOR_Test_Editor without running the real
		 * constructor (which registers WP hooks against a real gateway), and
		 * injects the stub gateway directly via the inherited `$gateway` property.
		 */
		private function make_editor(): \Token_Editor_IDOR_Test_Editor {

			$reflection = new \ReflectionClass( \Token_Editor_IDOR_Test_Editor::class );
			$editor     = $reflection->newInstanceWithoutConstructor();

			$gateway_property = new \ReflectionProperty( \Woodev_Payment_Gateway_Admin_Payment_Token_Editor::class, 'gateway' );
			if ( PHP_VERSION_ID < 80100 ) {
				$gateway_property->setAccessible( true );
			}
			$gateway_property->setValue( $editor, new \Token_Editor_IDOR_Test_Gateway_Stub() );

			return $editor;
		}

		/**
		 * Builds a WP_Screen stub with the given screen id, for the global
		 * $current_screen that Woodev_Helper::is_current_screen() reads.
		 */
		private function make_screen( string $id ): \WP_Screen {
			$screen     = new \WP_Screen();
			$screen->id = $id;

			return $screen;
		}

		/**
		 * Stubs current_user_can() so `manage_woocommerce` is always granted
		 * (the feature capability the shop manager legitimately holds) while
		 * `edit_user` is granted only for the given allowed target user ID(s)
		 * — modelling a shop manager who is NOT permitted to edit every user
		 * on the site (e.g. an administrator, or another site's user).
		 *
		 * @param int[] $editable_user_ids user IDs `edit_user` should allow
		 */
		private function stub_capabilities_for_shop_manager( array $editable_user_ids ): void {

			Functions\when( 'current_user_can' )->alias(
				static function ( $capability, ...$args ) use ( $editable_user_ids ) {

					if ( 'manage_woocommerce' === $capability ) {
						return true;
					}

					if ( 'edit_user' === $capability ) {
						return in_array( $args[0], $editable_user_ids, true );
					}

					return false;
				}
			);
		}

		// -------------------------------------------------------------------
		// ajax_remove_token() — GH-383: deletes another user's saved card
		// -------------------------------------------------------------------

		public function test_ajax_remove_token_rejects_a_caller_without_manage_woocommerce(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id']  = '7';
			$_REQUEST['token_id'] = 'tok_1';

			Functions\when( 'current_user_can' )->justReturn( false );
			Functions\expect( 'check_ajax_referer' )->never();
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/permission/i' ) );
			Functions\expect( 'wp_send_json_success' )->never();

			$editor->ajax_remove_token();

			$this->assertSame( [], $editor->removed_tokens, 'remove_token() must never run for an unauthorized caller — this is the #383 IDOR' );
		}

		public function test_ajax_remove_token_succeeds_for_an_authorized_caller(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id']  = '7';
			$_REQUEST['token_id'] = 'tok_1';

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\expect( 'wp_send_json_success' )->once()->withNoArgs();
			Functions\expect( 'wp_send_json_error' )->never();

			$editor->ajax_remove_token();

			$this->assertSame( [ [ 7, 'tok_1' ] ], $editor->removed_tokens, 'A capable, correctly-nonced caller must still be able to remove a token' );
		}

		public function test_ajax_remove_token_rejects_an_invalid_nonce_even_for_a_capable_user(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id']  = '7';
			$_REQUEST['token_id'] = 'tok_1';

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_ajax_referer' )->justReturn( false );
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/nonce/i' ) );

			$editor->ajax_remove_token();

			$this->assertSame( [], $editor->removed_tokens, 'The nonce stays a required CSRF check on top of the capability check' );
		}

		public function test_ajax_remove_token_rejects_a_shop_manager_targeting_a_user_they_may_not_edit(): void {
			$editor = $this->make_editor();

			// A shop manager holds manage_woocommerce, but user 1 (e.g. an
			// administrator) is outside the set of users they may edit.
			$_REQUEST['user_id']  = '1';
			$_REQUEST['token_id'] = 'tok_1';

			$this->stub_capabilities_for_shop_manager( [] );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/permission/i' ) );
			Functions\expect( 'wp_send_json_success' )->never();

			$editor->ajax_remove_token();

			$this->assertSame( [], $editor->removed_tokens, 'manage_woocommerce alone must not authorize removing an arbitrary target user\'s token — GH-383 follow-up' );
		}

		public function test_ajax_remove_token_rejects_a_non_numeric_user_id(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id']  = 'not-a-number';
			$_REQUEST['token_id'] = 'tok_1';

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/user id/i' ) );
			Functions\expect( 'wp_send_json_success' )->never();

			$editor->ajax_remove_token();

			$this->assertSame( [], $editor->removed_tokens, 'A non-numeric user_id must be rejected before it reaches remove_token()' );
		}

		public function test_ajax_remove_token_rejects_a_negative_user_id(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id']  = '-5';
			$_REQUEST['token_id'] = 'tok_1';

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/user id/i' ) );
			Functions\expect( 'wp_send_json_success' )->never();

			$editor->ajax_remove_token();

			$this->assertSame( [], $editor->removed_tokens, 'A negative user_id must be rejected before it reaches remove_token()' );
		}

		public function test_ajax_remove_token_rejects_an_array_user_id(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id']  = [ '1', '2' ];
			$_REQUEST['token_id'] = 'tok_1';

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/user id/i' ) );
			Functions\expect( 'wp_send_json_success' )->never();

			$editor->ajax_remove_token();

			$this->assertSame( [], $editor->removed_tokens, 'An array user_id must be rejected — it must never reach a user-ID array key downstream' );
		}

		public function test_ajax_remove_token_rejects_a_nonexistent_user_id(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id']  = '999999';
			$_REQUEST['token_id'] = 'tok_1';

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\when( 'get_userdata' )->justReturn( false );
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/user id/i' ) );
			Functions\expect( 'wp_send_json_success' )->never();

			$editor->ajax_remove_token();

			$this->assertSame( [], $editor->removed_tokens, 'A user_id that does not resolve to an existing user must be rejected' );
		}

		// -------------------------------------------------------------------
		// ajax_refresh_tokens() — GH-383: lists another user's saved cards
		// -------------------------------------------------------------------

		public function test_ajax_refresh_tokens_rejects_a_caller_without_manage_woocommerce(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id'] = '99';

			Functions\when( 'current_user_can' )->justReturn( false );
			Functions\expect( 'check_ajax_referer' )->never();
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/permission/i' ) );

			$editor->ajax_refresh_tokens();

			$this->assertSame( [], $editor->refreshed_user_ids, 'display_tokens() must never run for an unauthorized caller — this is the #383 IDOR' );
		}

		public function test_ajax_refresh_tokens_succeeds_for_an_authorized_caller(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id'] = '99';

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\expect( 'wp_send_json_success' )->once()->with( 'tokens-markup-for-99' );

			$editor->ajax_refresh_tokens();

			$this->assertSame( [ 99 ], $editor->refreshed_user_ids, 'A capable, correctly-nonced caller must still be able to refresh the tokens list' );
		}

		public function test_ajax_refresh_tokens_rejects_a_shop_manager_targeting_a_user_they_may_not_edit(): void {
			$editor = $this->make_editor();

			// A shop manager holds manage_woocommerce, but user 1 (e.g. an
			// administrator) is outside the set of users they may edit.
			$_REQUEST['user_id'] = '1';

			$this->stub_capabilities_for_shop_manager( [] );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/permission/i' ) );
			Functions\expect( 'wp_send_json_success' )->never();

			$editor->ajax_refresh_tokens();

			$this->assertSame( [], $editor->refreshed_user_ids, 'manage_woocommerce alone must not authorize listing an arbitrary target user\'s tokens — GH-383 follow-up' );
		}

		public function test_ajax_refresh_tokens_allows_a_shop_manager_targeting_a_user_they_may_edit(): void {
			$editor = $this->make_editor();

			// The shop manager IS allowed to edit this customer (e.g. a
			// subscriber they created / manage), so the refresh must succeed.
			$_REQUEST['user_id'] = '42';

			$this->stub_capabilities_for_shop_manager( [ 42 ] );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\expect( 'wp_send_json_success' )->once()->with( 'tokens-markup-for-42' );
			Functions\expect( 'wp_send_json_error' )->never();

			$editor->ajax_refresh_tokens();

			$this->assertSame( [ 42 ], $editor->refreshed_user_ids, 'A shop manager must still be able to refresh tokens for a customer they are allowed to edit' );
		}

		public function test_ajax_refresh_tokens_rejects_a_nonexistent_user_id(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id'] = '999999';

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\when( 'get_userdata' )->justReturn( false );
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/user id/i' ) );
			Functions\expect( 'wp_send_json_success' )->never();

			$editor->ajax_refresh_tokens();

			$this->assertSame( [], $editor->refreshed_user_ids, 'A user_id that does not resolve to an existing user must be rejected' );
		}

		// -------------------------------------------------------------------
		// ajax_get_blank_token() — no target user_id, but must still gate on
		// the feature capability, and do so before the CSRF check
		// -------------------------------------------------------------------

		public function test_ajax_get_blank_token_rejects_an_uncapable_caller_with_a_valid_nonce(): void {
			$editor = $this->make_editor();

			$_REQUEST['index'] = '1';

			Functions\when( 'current_user_can' )->justReturn( false );
			// Authorization must precede the CSRF check consistently across
			// all three handlers, so check_ajax_referer() must never run here.
			Functions\expect( 'check_ajax_referer' )->never();
			Functions\expect( 'wp_send_json_error' )->once()->withNoArgs();
			Functions\expect( 'wp_send_json_success' )->never();

			$editor->ajax_get_blank_token();
		}

		// -------------------------------------------------------------------
		// enqueue_scripts_styles() — GH-383: both AJAX nonces were localized
		// for every logged-in user on every admin screen
		// -------------------------------------------------------------------

		public function test_enqueue_does_not_localize_nonces_for_a_user_without_the_capability(): void {
			$editor = $this->make_editor();

			$GLOBALS['current_screen'] = $this->make_screen( 'profile' );

			Functions\when( 'current_user_can' )->justReturn( false );
			Functions\expect( 'wp_create_nonce' )->never();
			Functions\expect( 'wp_localize_script' )->never();
			Functions\expect( 'wp_enqueue_script' )->never();
			Functions\expect( 'wp_enqueue_style' )->never();

			$editor->enqueue_scripts_styles();
		}

		public function test_enqueue_does_not_localize_nonces_on_an_unrelated_admin_screen(): void {
			$editor = $this->make_editor();

			// Capable user, but on e.g. the Posts list screen — not a user-profile screen.
			$GLOBALS['current_screen'] = $this->make_screen( 'edit-post' );

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\expect( 'wp_create_nonce' )->never();
			Functions\expect( 'wp_localize_script' )->never();

			$editor->enqueue_scripts_styles();
		}

		public function test_enqueue_still_localizes_nonces_for_a_capable_user_on_the_profile_screen(): void {
			$editor = $this->make_editor();

			$GLOBALS['current_screen'] = $this->make_screen( 'profile' );

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin-ajax.php' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'a-nonce' );
			Functions\expect( 'wp_enqueue_style' )->once();
			Functions\expect( 'wp_enqueue_script' )->once();
			Functions\expect( 'wp_localize_script' )->once();

			$editor->enqueue_scripts_styles();
		}

		public function test_enqueue_still_localizes_nonces_for_a_capable_user_editing_another_users_profile(): void {
			$editor = $this->make_editor();

			// user-edit.php — the screen used when an admin/shop_manager edits someone else's profile.
			$GLOBALS['current_screen'] = $this->make_screen( 'user-edit' );

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin-ajax.php' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'a-nonce' );
			Functions\when( 'wp_enqueue_style' )->justReturn( null );
			Functions\when( 'wp_enqueue_script' )->justReturn( null );
			Functions\expect( 'wp_localize_script' )->once();

			$editor->enqueue_scripts_styles();
		}
	}

}
