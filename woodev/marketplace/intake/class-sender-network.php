<?php
/**
 * Where a marketplace's notifications may come from.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * The sender-address rules of a notification endpoint. The merchant decides none of it: the marketplace's addresses are the
 * adapter's constant ({@see Push_Protocol::default_ip_allowlist()}), a developer can extend them, and the reverse proxies a shop
 * stands behind are a developer's knowledge too.
 *
 * Both lists are filterable, as newline- or comma-separated IP/CIDR text:
 * - `woodev_{marketplace id}_push_ip_allowlist` — the marketplace's addresses (default: the adapter's constant);
 * - `woodev_{marketplace id}_push_trusted_proxies` — the reverse proxies whose `X-Forwarded-For` the shop believes (default: none).
 *
 * The address matters on one route only: the legacy URL without a token. On the token route the token is the credential and the
 * address is only recorded.
 *
 * @since 2.1.2
 */
final class Sender_Network {
	/**
	 * Adapter id, in the hook names.
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * The adapter's endpoint rules.
	 *
	 * @var Push_Protocol
	 */
	private $protocol;

	/**
	 * Creates the rules.
	 *
	 * @since 2.1.2
	 *
	 * @param string        $marketplace_id Adapter id.
	 * @param Push_Protocol $protocol       The adapter's endpoint rules.
	 */
	public function __construct( string $marketplace_id, Push_Protocol $protocol ) {
		$this->marketplace_id = str_replace( '-', '_', $marketplace_id );
		$this->protocol       = $protocol;
	}

	/**
	 * Returns the marketplace's addresses: the adapter's constant, as a developer's filter left it.
	 *
	 * @since 2.1.2
	 *
	 * @return string Newline- or comma-separated IP/CIDR list; '' when the marketplace publishes none.
	 */
	public function allowlist(): string {
		return $this->filtered_list( 'push_ip_allowlist', $this->protocol->default_ip_allowlist() );
	}

	/**
	 * Returns the reverse proxies the shop stands behind, as a developer's filter declared them.
	 *
	 * @since 2.1.2
	 *
	 * @return string Newline- or comma-separated IP/CIDR list; '' for none.
	 */
	public function trusted_proxies(): string {
		return $this->filtered_list( 'push_trusted_proxies', '' );
	}

	/**
	 * Tells whether an address belongs to the marketplace. A marketplace that publishes no addresses has nothing to check.
	 *
	 * @since 2.1.2
	 *
	 * @param string $ip Sender address.
	 * @return bool
	 */
	public function is_allowed( string $ip ): bool {
		$list = $this->allowlist();

		// A list with entries that are all malformed refuses everyone: a typo must not open the route.
		return '' === trim( $list ) || Ip_Ranges::contains( $ip, $list );
	}

	/**
	 * Returns the address a request came from: the peer, or behind a trusted proxy the nearest address in `X-Forwarded-For`
	 * that is not itself a trusted proxy.
	 *
	 * @since 2.1.2
	 *
	 * @param array<string,mixed> $server Request server variables.
	 * @return string An IP address, or '' when none can be trusted.
	 */
	public function client_ip( array $server ): string {
		$peer = isset( $server['REMOTE_ADDR'] ) ? trim( (string) $server['REMOTE_ADDR'] ) : '';

		if ( false === filter_var( $peer, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$proxies = $this->trusted_proxies();

		if ( '' === $proxies || ! Ip_Ranges::contains( $peer, $proxies ) || empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
			return $peer;
		}

		$chain = array_reverse( array_map( 'trim', explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) ) );

		foreach ( $chain as $hop ) {
			if ( false === filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				return $peer;
			}

			if ( ! Ip_Ranges::contains( $hop, $proxies ) ) {
				return $hop;
			}
		}

		return $peer;
	}

	/**
	 * Tells whether the request came through a reverse proxy, the way WooCommerce reads it: its geolocation helper answers with a
	 * forwarded address when a forwarding header is set, so an answer that differs from the peer means a proxy forwarded the request.
	 *
	 * @since 2.1.2
	 *
	 * @param array<string,mixed> $server Request server variables.
	 * @return bool
	 */
	public static function behind_proxy( array $server ): bool {
		$peer = isset( $server['REMOTE_ADDR'] ) ? trim( (string) $server['REMOTE_ADDR'] ) : '';

		if ( false === filter_var( $peer, FILTER_VALIDATE_IP ) || ! class_exists( '\\WC_Geolocation' ) ) {
			return false;
		}

		$forwarded = trim( (string) \WC_Geolocation::get_ip_address() );

		return false !== filter_var( $forwarded, FILTER_VALIDATE_IP ) && $forwarded !== $peer;
	}

	/**
	 * Runs a list through its developer filter.
	 *
	 * @param string $name    Hook suffix.
	 * @param string $default Adapter's value.
	 * @return string The filtered list; the adapter's when the filter returned something that is not text.
	 */
	private function filtered_list( string $name, string $default ): string {
		/**
		 * Filters one of the sender-address lists of a marketplace's notification endpoint.
		 *
		 * The dynamic part is the adapter id in snake case; the suffix is `push_ip_allowlist` (the marketplace's addresses) or
		 * `push_trusted_proxies` (reverse proxies whose `X-Forwarded-For` the shop believes).
		 *
		 * @since 2.1.2
		 *
		 * @param string $list Newline- or comma-separated IP/CIDR list.
		 */
		$list = apply_filters( 'woodev_' . $this->marketplace_id . '_' . $name, $default ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Built from the adapter id.

		return is_string( $list ) ? $list : $default;
	}
}
