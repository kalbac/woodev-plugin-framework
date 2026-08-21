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
		public $removed_tokens = array();

		/** @var bool */
		public $remove_token_return = true;

		/** @var array<int,mixed> */
		public $refreshed_user_ids = array();

		protected function remove_token( $user_id, $token_id ) {
			$this->removed_tokens[] = array( $user_id, $token_id );

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

			$GLOBALS['current_screen'] = null;
		}

		protected function tearDown(): void {
			unset( $GLOBALS['current_screen'] );
			unset( $_REQUEST['user_id'], $_REQUEST['token_id'] );

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

			$this->assertSame( array(), $editor->removed_tokens, 'remove_token() must never run for an unauthorized caller — this is the #383 IDOR' );
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

			$this->assertSame( array( array( '7', 'tok_1' ) ), $editor->removed_tokens, 'A capable, correctly-nonced caller must still be able to remove a token' );
		}

		public function test_ajax_remove_token_rejects_an_invalid_nonce_even_for_a_capable_user(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id']  = '7';
			$_REQUEST['token_id'] = 'tok_1';

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_ajax_referer' )->justReturn( false );
			Functions\expect( 'wp_send_json_error' )->once()->with( Mockery::pattern( '/nonce/i' ) );

			$editor->ajax_remove_token();

			$this->assertSame( array(), $editor->removed_tokens, 'The nonce stays a required CSRF check on top of the capability check' );
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

			$this->assertSame( array(), $editor->refreshed_user_ids, 'display_tokens() must never run for an unauthorized caller — this is the #383 IDOR' );
		}

		public function test_ajax_refresh_tokens_succeeds_for_an_authorized_caller(): void {
			$editor = $this->make_editor();

			$_REQUEST['user_id'] = '99';

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\expect( 'wp_send_json_success' )->once()->with( 'tokens-markup-for-99' );

			$editor->ajax_refresh_tokens();

			$this->assertSame( array( '99' ), $editor->refreshed_user_ids, 'A capable, correctly-nonced caller must still be able to refresh the tokens list' );
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
