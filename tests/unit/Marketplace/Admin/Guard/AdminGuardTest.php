<?php
/**
 * Unit tests for transport-neutral Admin_Guard.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Guard;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Guard\Admin_Guard;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Account_Store;
use Woodev\Tests\Unit\TestCase;

final class AdminGuardTest extends TestCase {

	protected function tearDown(): void {
		unset( $_REQUEST['_wpnonce'], $_REQUEST['custom_nonce'] );
		parent::tearDown();
	}

	public function test_check_rejects_empty_or_invalid_nonce_before_capability_lookup(): void {
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'bad-token', 'mp_action' )->andReturn( false );
		Functions\expect( 'current_user_can' )->never();

		$guard = new Admin_Guard();

		$this->assertFalse( $guard->check( 'manage_woocommerce', 'mp_action', '' ) );
		$this->assertFalse( $guard->check( 'manage_woocommerce', 'mp_action', 'bad-token' ) );
	}

	public function test_check_verifies_general_and_meta_capabilities(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_post', 77 )->andReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_post', 88 )->andReturn( false );

		$guard = new Admin_Guard();

		$this->assertTrue( $guard->check( 'edit_post', 'save_tab', 'valid-nonce', 77 ) );
		$this->assertFalse( $guard->check( 'edit_post', 'save_tab', 'valid-nonce', 88 ) );
	}

	public function test_for_store_enforces_account_existence_and_adapter_ownership(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$store = new Fake_Account_Store();
		$own   = $store->save( new Account( 0, 'alpha', 'Alpha Shop', true, [ 'id' => '1', 'secret' => 's' ] ) );
		$other = $store->save( new Account( 0, 'beta', 'Beta Shop', true, [ 'id' => '2', 'secret' => 's' ] ) );

		$guard = Admin_Guard::for_store( $store, 'alpha' );

		$this->assertTrue( $guard->check( 'manage_woocommerce', 'act', 'tok', null, $own ) );
		$this->assertFalse( $guard->check( 'manage_woocommerce', 'act', 'tok', null, $other ) );
		$this->assertFalse( $guard->check( 'manage_woocommerce', 'act', 'tok', null, 999 ) );
		$this->assertFalse( $guard->check( 'manage_woocommerce', 'act', 'tok', null, 0 ) );
	}

	public function test_check_fails_when_account_id_is_passed_without_resolver(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$guard = new Admin_Guard();

		$this->assertFalse( $guard->check( 'manage_woocommerce', 'act', 'tok', null, 1 ) );
	}

	public function test_check_request_reads_and_sanitizes_nonce_from_request_superglobal(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'req-token', 'ajax_act' )->andReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$_REQUEST['custom_nonce'] = '  req-token  ';

		$guard = new Admin_Guard(
			static function ( int $id ): bool {
				return 5 === $id;
			}
		);

		$this->assertTrue( $guard->check_request( 'manage_woocommerce', 'ajax_act', null, 5, 'custom_nonce' ) );
	}
}
