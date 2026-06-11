<?php
/**
 * License-authority shared functions.
 *
 * Hosts woodev_normalize_site() — the single site-normalization primitive used,
 * byte-identically, both when a signed claim is issued (server-side) and when it
 * is verified (client-side). Exceptionally for this framework, it is a guarded
 * global function rather than an OOP method: the function NAME is fixed across
 * repositories by the cross-implementation test vector, and the if-not-defined
 * guard keeps it multi-version safe (gotcha bootstrap/multiversion-early-class-guards).
 *
 * @package Woodev\Framework
 * @since 2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'woodev_normalize_site' ) ) {

	/**
	 * Normalizes a site URL for cryptographic claim binding.
	 *
	 * Implements need-license-spec §4.2 steps 0-6. A pure, deterministic function:
	 * the same input always yields the same output, and the output is idempotent
	 * (normalizing a normalized value is a no-op). Any deviation from an absolute
	 * http/https URL with a usable host yields null — never an exception. A null
	 * result is the caller's signal to treat the claim as invalid (safe / locked).
	 *
	 * Rules:
	 *   0. input MUST be an absolute http/https URL with a non-empty host;
	 *   1. scheme -> strtolower;
	 *   2. host -> strtolower; a host with bytes > 0x7F (raw IDN) FAILs — punycode
	 *      is the deterministic form; IPv6 literals keep their brackets, hex lowered;
	 *   3. drop default ports (:80 http / :443 https); keep non-default ports;
	 *   4. path -> untrailingslashit(), case preserved; absent path -> '';
	 *   5. drop query + fragment; any userinfo (user/pass) FAILs;
	 *   6. return scheme . '://' . host [ . ':' . port ] . path.
	 *
	 * @since 2.0.0
	 *
	 * @param string $url Raw site URL.
	 * @return string|null Normalized URL, or null on any failure.
	 */
	function woodev_normalize_site( string $url ): ?string {

		$parts = wp_parse_url( $url );

		if ( false === $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		$host = strtolower( (string) $parts['host'] );

		if ( preg_match( '/[^\x00-\x7F]/', $host ) ) {
			return null;
		}

		// IPv6 literals: normalize to a single-bracket form, but ONLY for hosts that
		// actually are IPv6 addresses. Parser behavior differs (some wp_parse_url
		// builds strip the brackets, native parse_url keeps them), so accept either
		// shape — and FAIL on anything bracket-like that is not a valid IPv6 literal
		// (empty brackets, bracketed hostnames, stray brackets) instead of mutating it.
		if ( '' !== $host && '[' === $host[0] ) {
			if ( ']' !== substr( $host, -1 ) ) {
				return null;
			}

			$ipv6 = substr( $host, 1, -1 );

			if ( false === filter_var( $ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return null;
			}

			$host = '[' . $ipv6 . ']';
		} elseif ( false !== strpos( $host, ':' ) ) {
			// Unbracketed host containing ':' — a parser that strips IPv6 brackets.
			if ( false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return null;
			}

			$host = '[' . $host . ']';
		} elseif ( false !== strpos( $host, '[' ) || false !== strpos( $host, ']' ) ) {
			return null;
		}

		$port = '';

		if ( isset( $parts['port'] ) ) {
			$is_default = ( 'http' === $scheme && 80 === $parts['port'] ) || ( 'https' === $scheme && 443 === $parts['port'] );

			if ( ! $is_default ) {
				$port = ':' . $parts['port'];
			}
		}

		$path = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';

		return $scheme . '://' . $host . $port . $path;
	}
}
