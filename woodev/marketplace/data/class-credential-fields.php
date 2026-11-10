<?php
/**
 * Which credential fields an account stores.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

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
	 * Creates the declaration.
	 *
	 * @since 2.0.29
	 *
	 * @param string   $identifier Name of the non-secret, unique account identifier.
	 * @param string[] $secrets    Names of the secret fields; at least one.
	 * @throws \InvalidArgumentException When a name is empty or repeated, or there is no secret.
	 */
	public function __construct( string $identifier, array $secrets ) {
		$secrets = array_values( array_map( 'strval', $secrets ) );
		$names   = array_merge( [ $identifier ], $secrets );

		if ( [] === $secrets || in_array( '', $names, true ) || count( array_unique( $names ) ) !== count( $names ) ) {
			throw new \InvalidArgumentException( 'Credential fields need one identifier and at least one secret, all with distinct names.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$this->identifier = $identifier;
		$this->secrets    = $secrets;
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
