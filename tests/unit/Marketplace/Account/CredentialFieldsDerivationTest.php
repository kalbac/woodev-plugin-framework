<?php
/**
 * Credential fields whose identifier is derived from the secrets.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Account;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Account\Credential_Fields;

/**
 * An adapter whose account has one secret and no identifier the merchant types (WF5).
 */
final class CredentialFieldsDerivationTest extends TestCase {
	/**
	 * Without a derivation the settings form asks for the identifier and nothing is derived.
	 *
	 * @return void
	 */
	public function test_without_a_derivation_the_identifier_is_typed(): void {
		$fields = new Credential_Fields( 'cabinet_id', [ 'api_token' ] );

		$this->assertFalse( $fields->derives_identifier() );
		$this->assertSame( '', $fields->derive_identifier( [ 'api_token' => 'secret' ] ) );
	}

	/**
	 * The identifier comes from the secrets, trimmed, and the callback receives the secrets by name only.
	 *
	 * @return void
	 */
	public function test_the_identifier_is_derived_from_the_secrets(): void {
		$received = null;
		$fields   = new Credential_Fields(
			'sid',
			[ 'token' ],
			static function ( array $secrets ) use ( &$received ): string {
				$received = $secrets;

				return ' seller-of-' . $secrets['token'] . ' ';
			}
		);

		$this->assertTrue( $fields->derives_identifier() );
		$this->assertSame( 'seller-of-abc', $fields->derive_identifier( [ 'token' => 'abc', 'sid' => 'ignored' ] ) );
		$this->assertSame( [ 'token' => 'abc' ], $received );
	}

	/**
	 * A missing or empty secret derives nothing, and the callback is never asked.
	 *
	 * @return void
	 */
	public function test_a_missing_secret_derives_nothing(): void {
		$calls  = 0;
		$fields = new Credential_Fields(
			'sid',
			[ 'token' ],
			static function () use ( &$calls ): string {
				++$calls;

				return 'seller';
			}
		);

		$this->assertSame( '', $fields->derive_identifier( [ 'token' => '' ] ) );
		$this->assertSame( '', $fields->derive_identifier( [] ) );
		$this->assertSame( 0, $calls );
	}
}
