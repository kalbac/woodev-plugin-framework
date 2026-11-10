<?php
/**
 * Encryption of the stored API key.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Account;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;

/**
 * The key is stored with `sodium_crypto_secretbox` under a key derived from the site's salt (spec 3.4).
 */
final class SecretCipherTest extends TestCase {
	private const KEY = 'k-0000-1111-2222-3333-secret';

	/**
	 * A secret survives a round trip, Cyrillic and long values included.
	 *
	 * @return void
	 */
	public function test_a_secret_survives_a_round_trip(): void {
		$cipher = new Secret_Cipher( 'salt-a' );

		foreach ( array( self::KEY, 'ключ-№1', str_repeat( 'x', 4000 ) ) as $secret ) {
			$this->assertSame( $secret, $cipher->decrypt( $cipher->encrypt( $secret ) ) );
		}
	}

	/**
	 * The stored text is not the key, carries the version marker, and differs every time (random nonce).
	 *
	 * @return void
	 */
	public function test_the_stored_text_is_not_the_key_and_is_new_every_time(): void {
		$cipher = new Secret_Cipher( 'salt-a' );

		$first  = $cipher->encrypt( self::KEY );
		$second = $cipher->encrypt( self::KEY );

		$this->assertStringStartsWith( Secret_Cipher::PREFIX, $first );
		$this->assertStringNotContainsString( self::KEY, $first );
		$this->assertNotSame( $first, $second );
		$this->assertTrue( Secret_Cipher::is_encrypted( $first ) );
		$this->assertFalse( Secret_Cipher::is_encrypted( self::KEY ) );
	}

	/**
	 * A changed salt cannot open the value: the caller gets null, not an exception.
	 *
	 * @return void
	 */
	public function test_other_key_material_cannot_open_the_value(): void {
		$stored = ( new Secret_Cipher( 'salt-a' ) )->encrypt( self::KEY );

		$this->assertNull( ( new Secret_Cipher( 'salt-b' ) )->decrypt( $stored ) );
	}

	/**
	 * Cut, edited, foreign and malformed values give null.
	 *
	 * @return void
	 */
	public function test_damaged_values_give_null(): void {
		$cipher = new Secret_Cipher( 'salt-a' );
		$stored = $cipher->encrypt( self::KEY );
		$raw    = base64_decode( substr( $stored, strlen( Secret_Cipher::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$flip   = $raw;

		// Change one whole byte of the ciphertext, never a base64 padding bit: the nonce is random, so a text edit could be a no-op.
		$flip[ strlen( $flip ) - 1 ] = chr( ord( $flip[ strlen( $flip ) - 1 ] ) ^ 0xFF );

		$cases = array(
			'plain key'          => self::KEY,
			'prefix only'        => Secret_Cipher::PREFIX,
			'not base64'         => Secret_Cipher::PREFIX . '%%%not base64%%%',
			'shorter than nonce' => Secret_Cipher::PREFIX . base64_encode( 'short' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'cut'                => Secret_Cipher::PREFIX . base64_encode( substr( $raw, 0, -3 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'edited'             => Secret_Cipher::PREFIX . base64_encode( $flip ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);

		foreach ( $cases as $label => $value ) {
			$this->assertNull( $cipher->decrypt( $value ), $label );
		}
	}

	/**
	 * An empty secret stays empty, so "no key yet" is visible in the table.
	 *
	 * @return void
	 */
	public function test_an_empty_secret_stays_empty(): void {
		$cipher = new Secret_Cipher( 'salt-a' );

		$this->assertSame( '', $cipher->encrypt( '' ) );
		$this->assertSame( '', $cipher->decrypt( '' ) );
	}

	/**
	 * A cipher without key material is refused: an unset salt would encrypt for nothing.
	 *
	 * @return void
	 */
	public function test_empty_key_material_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Secret_Cipher( '' );
	}

	/**
	 * The fingerprint is the first 16 hex characters of the SHA-256, and empty for no key.
	 *
	 * @return void
	 */
	public function test_the_fingerprint_is_sixteen_hex_characters_of_sha256(): void {
		$this->assertSame( substr( hash( 'sha256', self::KEY ), 0, 16 ), Secret_Cipher::fingerprint( self::KEY ) );
		$this->assertSame( 16, strlen( Secret_Cipher::fingerprint( self::KEY ) ) );
		$this->assertSame( '', Secret_Cipher::fingerprint( '' ) );
	}
}
