<?php
/**
 * IPv4/IPv6 address and CIDR list reader for the push-notification sender rules.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * Checks an address against a newline- or comma-separated list of IPv4/IPv6 addresses and CIDR prefixes (`198.51.100.0/24`, `10.0.0.1`,
 * `2001:db8::/32`). Blank lines and `#` comments are ignored.
 *
 * @since 2.1.2
 */
final class Ip_Ranges {
	/**
	 * Tells whether `$ip` falls inside any entry of `$list`.
	 *
	 * @since 2.1.2
	 *
	 * @param string $ip   Candidate client IP.
	 * @param string $list Newline- or comma-separated IP/CIDR list.
	 * @return bool
	 */
	public static function contains( string $ip, string $list ): bool {
		$packed_ip = @inet_pton( trim( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on malformed input.
		if ( false === $packed_ip ) {
			return false;
		}

		foreach ( self::tokens( $list ) as $entry ) {
			$cidr = self::parse_cidr( $entry );
			if ( null !== $cidr && strlen( $cidr['packed'] ) === strlen( $packed_ip ) && self::matches_prefix( $packed_ip, $cidr['packed'], $cidr['prefix'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Splits raw text into non-empty tokens, stripping `#` comments.
	 *
	 * @param string $list Raw input.
	 * @return string[]
	 */
	private static function tokens( string $list ): array {
		$tokens = [];

		foreach ( preg_split( '/[\r\n]+/', $list ) ?: [] as $line ) {
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}

			foreach ( explode( ',', $line ) as $piece ) {
				$trimmed = trim( $piece );
				if ( '' !== $trimmed ) {
					$tokens[] = $trimmed;
				}
			}
		}

		return $tokens;
	}

	/**
	 * Parses one IP or `IP/prefix` token.
	 *
	 * @param string $entry Single token.
	 * @return array{ip:string,packed:string,prefix:int}|null
	 */
	private static function parse_cidr( string $entry ): ?array {
		$parts = explode( '/', $entry, 2 );
		$ip    = trim( $parts[0] );

		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return null;
		}

		$max_bits = 4 === strlen( $packed ) ? 32 : 128;

		if ( 1 === count( $parts ) ) {
			return [
				'ip'     => $ip,
				'packed' => $packed,
				'prefix' => $max_bits,
			];
		}

		$prefix_raw = trim( $parts[1] );
		if ( '' === $prefix_raw || ! ctype_digit( $prefix_raw ) ) {
			return null;
		}

		// Disallow leading zeros like `/08` except `/0`.
		if ( strlen( $prefix_raw ) > 1 && '0' === $prefix_raw[0] ) {
			return null;
		}

		$prefix = (int) $prefix_raw;
		if ( $prefix < 0 || $prefix > $max_bits ) {
			return null;
		}

		return [
			'ip'     => $ip,
			'packed' => $packed,
			'prefix' => $prefix,
		];
	}

	/**
	 * Compares the top `$prefix` bits of two packed addresses of equal byte length.
	 *
	 * @param string $ip      Packed candidate IP.
	 * @param string $network Packed network IP.
	 * @param int    $prefix  Prefix length in bits.
	 * @return bool
	 */
	private static function matches_prefix( string $ip, string $network, int $prefix ): bool {
		$full_bytes = intdiv( $prefix, 8 );
		$rem_bits   = $prefix % 8;

		if ( $full_bytes > 0 && substr( $ip, 0, $full_bytes ) !== substr( $network, 0, $full_bytes ) ) {
			return false;
		}

		if ( 0 === $rem_bits ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rem_bits ) ) & 0xFF;

		return ( ord( $ip[ $full_bytes ] ) & $mask ) === ( ord( $network[ $full_bytes ] ) & $mask );
	}
}
