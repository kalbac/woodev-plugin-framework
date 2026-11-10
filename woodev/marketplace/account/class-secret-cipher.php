<?php
/**
 * Encryption of a stored secret.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts an API key for the database with `sodium_crypto_secretbox` (PHP core since 7.2).
 *
 * The real case it covers: a database dump handed to a hoster, a freelancer or support without `wp-config.php`. The box key is
 * derived from key material the caller supplies (the plugin passes `wp_salt( 'secure_auth' )`), so the dump alone does not open it.
 * The stored form is `sb1:` and base64 of the 24-byte nonce followed by the ciphertext; the nonce is random per call, so the same
 * secret never encrypts to the same text twice.
 *
 * Decryption never throws: a value that cannot be opened (the salts changed, the text was cut or edited) comes back as null, and the
 * caller decides what the merchant sees.
 *
 * @since 2.0.29
 */
final class Secret_Cipher {

	/**
	 * Marker and format version at the start of every stored value.
	 *
	 * @var string
	 */
	public const PREFIX = 'sb1:';

	/**
	 * Secret key of the box, derived from the key material.
	 *
	 * @var string
	 */
	private $box_key;

	/**
	 * Creates a cipher.
	 *
	 * @since 2.0.29
	 *
	 * @param string $key_material Secret site-specific text, e.g. the `secure_auth` salt. Must not be empty.
	 * @throws \InvalidArgumentException When the key material is empty (a salt that is not set protects nothing).
	 */
	public function __construct( string $key_material ) {
		if ( '' === $key_material ) {
			throw new \InvalidArgumentException( 'The key material of the cipher is empty.' );
		}

		$this->box_key = hash( 'sha256', $key_material, true );
	}

	/**
	 * Encrypts a secret. An empty secret is stored as an empty string: nothing to protect, and "no key yet" stays visible.
	 *
	 * @since 2.0.29
	 *
	 * @param string $plain Secret.
	 * @return string Value for the database.
	 */
	public function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->box_key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text for storage, not obfuscation.
	}

	/**
	 * Decrypts a stored value.
	 *
	 * @since 2.0.29
	 *
	 * @param string $stored Value read from the database.
	 * @return string|null The secret ('' for an empty value), or null when it cannot be opened with this key material.
	 */
	public function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary for storage, not obfuscation.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$opened = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $this->box_key );

		return false === $opened ? null : $opened;
	}

	/**
	 * Tells whether a value is in the encrypted form this class writes.
	 *
	 * @since 2.0.29
	 *
	 * @param string $stored Value read from the database.
	 * @return bool
	 */
	public static function is_encrypted( string $stored ): bool {
		return 0 === strpos( $stored, self::PREFIX );
	}

	/**
	 * Returns the fingerprint of a secret: the first 16 hex characters of its SHA-256. It lets diagnostics say that a key changed
	 * without the key.
	 *
	 * @since 2.0.29
	 *
	 * @param string $plain Secret.
	 * @return string 16 hex characters, '' for an empty secret.
	 */
	public static function fingerprint( string $plain ): string {
		return '' === $plain ? '' : substr( hash( 'sha256', $plain ), 0, 16 );
	}
}
