<?php
/**
 * Which credential fields an account stores.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Account;

defined( 'ABSPATH' ) || exit;

/**
 * What a marketplace's credentials are made of, as the account store needs it: one **identifier** (the non-secret id that names
 * the cabinet and is unique among accounts, e.g. a client id) and one or more **secrets** (keys and tokens, stored encrypted and
 * never in `wp_options`).
 *
 * The adapter supplies it; the store never names a field itself. Labels and validation of the fields belong to the settings
 * section that renders them, not to persistence.
 *
 * @since 2.0.29
 */
final class Credential_Fields {
	/**
	 * Name of the identifier field.
	 *
	 * @var string
	 */
	private $identifier;

	/**
	 * Names of the secret fields, in a fixed order.
	 *
	 * @var string[]
	 */
	private $secrets;

	/**
	 * Derives the identifier from the secrets, null when the form asks for it.
	 *
	 * @var callable|null
	 */
	private $derive;

	/**
	 * Creates the declaration.
	 *
	 * @since 2.0.29
	 *
	 * @param string        $identifier Name of the non-secret, unique account identifier.
	 * @param string[]      $secrets    Names of the secret fields; at least one.
	 * @param callable|null $derive_identifier Receives the secrets by name and returns the identifier, '' when it cannot be
	 *                                         derived. Null when the settings form asks for the identifier.
	 * @throws \InvalidArgumentException When a name is empty or repeated, or there is no secret.
	 */
	public function __construct( string $identifier, array $secrets, ?callable $derive_identifier = null ) {
		$secrets = array_values( array_map( 'strval', $secrets ) );
		$names   = array_merge( [ $identifier ], $secrets );

		if ( [] === $secrets || in_array( '', $names, true ) || count( array_unique( $names ) ) !== count( $names ) ) {
			throw new \InvalidArgumentException( 'Credential fields need one identifier and at least one secret, all with distinct names.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$this->identifier = $identifier;
		$this->secrets    = $secrets;
		$this->derive     = $derive_identifier;
	}

	/**
	 * Tells whether the identifier is derived from the secrets, so the settings form does not ask for it.
	 *
	 * @since 2.1.21
	 *
	 * @return bool
	 */
	public function derives_identifier(): bool {
		return null !== $this->derive;
	}

	/**
	 * Derives the identifier from the secrets.
	 *
	 * Returns '' when the identifier is not derivable here, or when a secret is missing: the caller then has no identifier and
	 * reports the account as incomplete.
	 *
	 * @since 2.1.21
	 *
	 * @param array<string,mixed> $credentials Credentials by field name; only the secrets are read.
	 * @return string
	 */
	public function derive_identifier( array $credentials ): string {
		$secrets = $this->secrets_of( $credentials );

		if ( null === $this->derive || in_array( '', $secrets, true ) ) {
			return '';
		}

		return trim( (string) call_user_func( $this->derive, $secrets ) );
	}

	/**
	 * Name of the identifier field.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function identifier(): string {
		return $this->identifier;
	}

	/**
	 * Names of the secret fields.
	 *
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public function secrets(): array {
		return $this->secrets;
	}

	/**
	 * Keeps the secret fields of a credentials array, in the declared order.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $credentials Credentials by field name.
	 * @return array<string,string> Secret name => value; a field the array lacks is ''.
	 */
	public function secrets_of( array $credentials ): array {
		$secrets = [];

		foreach ( $this->secrets as $name ) {
			$secrets[ $name ] = (string) ( $credentials[ $name ] ?? '' );
		}

		return $secrets;
	}
}
