<?php
/**
 * Unit tests for Sender_Network and Ip_Ranges.
 *
 * @package Woodev\Tests\Unit\Marketplace\Intake
 */

namespace Woodev\Tests\Unit\Marketplace\Intake;

use Brain\Monkey\Filters;
use Woodev\Framework\Marketplace\Intake\Ip_Ranges;
use Woodev\Framework\Marketplace\Intake\Sender_Network;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Push_Protocol;
use Woodev\Tests\Unit\TestCase;

/**
 * The marketplace's addresses are the adapter's constant and a developer's filter; the proxies are a developer's filter. The
 * merchant decides neither.
 */
final class SenderNetworkTest extends TestCase {
	/**
	 * The adapter's constant decides, and the hook is named after the adapter id in snake case.
	 *
	 * @return void
	 */
	public function test_the_adapters_constant_is_the_allowlist_until_a_filter_extends_it(): void {
		$network = new Sender_Network( 'fake-mp', new Fake_Push_Protocol( '203.0.113.0/24' ) );

		$this->assertTrue( $network->is_allowed( '203.0.113.9' ) );
		$this->assertFalse( $network->is_allowed( '198.51.100.9' ) );

		Filters\expectApplied( 'woodev_fake_mp_push_ip_allowlist' )->once()->with( '203.0.113.0/24' )->andReturn( "203.0.113.0/24\n198.51.100.0/24" );

		$this->assertTrue( $network->is_allowed( '198.51.100.9' ), 'a developer extended the list' );
	}

	/**
	 * A marketplace that publishes no addresses has nothing to check; a list that is only malformed refuses everyone.
	 *
	 * @return void
	 */
	public function test_no_published_addresses_means_no_check_and_a_malformed_list_opens_nothing(): void {
		$this->assertTrue( ( new Sender_Network( 'fake', new Fake_Push_Protocol( '' ) ) )->is_allowed( '198.51.100.9' ) );
		$this->assertFalse( ( new Sender_Network( 'fake', new Fake_Push_Protocol( '999.1.1.1/24' ) ) )->is_allowed( '198.51.100.9' ) );
	}

	/**
	 * A filter that returns something that is not text cannot clear the adapter's list.
	 *
	 * @return void
	 */
	public function test_a_filter_answering_with_a_non_string_is_ignored(): void {
		Filters\expectApplied( 'woodev_fake_push_ip_allowlist' )->andReturn( null );

		$this->assertFalse( ( new Sender_Network( 'fake', new Fake_Push_Protocol( '203.0.113.0/24' ) ) )->is_allowed( '198.51.100.9' ) );
	}

	/**
	 * Without trusted proxies the sender is the peer, whatever a forwarded header claims; a header cannot make a foreign address
	 * look like the marketplace.
	 *
	 * @return void
	 */
	public function test_the_sender_is_the_peer_unless_the_peer_is_a_trusted_proxy(): void {
		$network = new Sender_Network( 'fake', new Fake_Push_Protocol() );
		$server  = [
			'REMOTE_ADDR'          => '198.51.100.9',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
		];

		$this->assertSame( '198.51.100.9', $network->client_ip( $server ) );
		$this->assertSame( '', $network->client_ip( [ 'REMOTE_ADDR' => 'nonsense' ] ) );
		$this->assertSame( '', $network->client_ip( [] ) );
	}

	/**
	 * Behind a proxy a developer declared, the sender is the nearest forwarded address that is not itself a trusted proxy.
	 *
	 * @return void
	 */
	public function test_behind_a_declared_proxy_the_sender_is_the_nearest_untrusted_forwarded_address(): void {
		Filters\expectApplied( 'woodev_fake_push_trusted_proxies' )->andReturn( "10.0.0.0/8\n192.0.2.1" );

		$network = new Sender_Network( 'fake', new Fake_Push_Protocol() );

		$this->assertSame(
			'203.0.113.7',
			$network->client_ip(
				[
					'REMOTE_ADDR'          => '10.0.0.2',
					'HTTP_X_FORWARDED_FOR' => '198.51.100.99, 203.0.113.7, 192.0.2.1',
				]
			),
			'the left entries are the sender\'s to write, so the chain is read from the right'
		);
		$this->assertSame( '10.0.0.2', $network->client_ip( [ 'REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'junk' ] ) );
		$this->assertSame( '198.51.100.9', $network->client_ip( [ 'REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7' ] ), 'a peer that is no declared proxy is believed over its header' );
	}

	/**
	 * Behind a proxy is read the way WooCommerce reads it: a forwarded address that differs from the peer.
	 *
	 * @return void
	 */
	public function test_behind_a_proxy_means_the_forwarded_address_differs_from_the_peer(): void {
		if ( ! class_exists( '\\WC_Geolocation' ) ) {
			require_once dirname( __DIR__, 2 ) . '/Shipping/Rest_Api/wc-geolocation-stub.php';
		}

		try {
			\WC_Geolocation::$address = '198.51.100.20';
			$this->assertTrue( Sender_Network::behind_proxy( [ 'REMOTE_ADDR' => '10.0.0.2' ] ) );

			\WC_Geolocation::$address = '10.0.0.2';
			$this->assertFalse( Sender_Network::behind_proxy( [ 'REMOTE_ADDR' => '10.0.0.2' ] ) );

			\WC_Geolocation::$address = 'not-an-ip';
			$this->assertFalse( Sender_Network::behind_proxy( [ 'REMOTE_ADDR' => '10.0.0.2' ] ) );
			$this->assertFalse( Sender_Network::behind_proxy( [] ) );
		} finally {
			\WC_Geolocation::$address = null;
		}
	}

	/**
	 * Address matching on IPv4 and IPv6 prefixes, comments and commas.
	 *
	 * @return void
	 */
	public function test_ip_ranges_match_prefixes_comments_and_both_families(): void {
		$list = "198.51.100.0/24 # edge\n2001:db8::/32, 10.0.0.5";

		$this->assertTrue( Ip_Ranges::contains( '198.51.100.42', $list ) );
		$this->assertTrue( Ip_Ranges::contains( '2001:db8::1', $list ) );
		$this->assertTrue( Ip_Ranges::contains( '10.0.0.5', $list ) );
		$this->assertFalse( Ip_Ranges::contains( '198.51.101.42', $list ) );
		$this->assertFalse( Ip_Ranges::contains( '10.0.0.6', $list ) );
		$this->assertFalse( Ip_Ranges::contains( 'nonsense', $list ) );
	}

	/**
	 * An IPv4-mapped IPv6 sender (`::ffff:a.b.c.d`, any case) is matched as the IPv4 address it is, inside and outside a range.
	 *
	 * @return void
	 */
	public function test_ip_ranges_unwrap_an_ipv4_mapped_sender(): void {
		$list = '198.51.100.0/24';

		$this->assertTrue( Ip_Ranges::contains( '::ffff:198.51.100.42', $list ) );
		$this->assertTrue( Ip_Ranges::contains( '::FFFF:198.51.100.42', $list ) );
		$this->assertFalse( Ip_Ranges::contains( '::ffff:198.51.101.42', $list ) );
	}

	/**
	 * A mapped entry in the list matches a plain IPv4 sender, so a list written in either form keeps working.
	 *
	 * @return void
	 */
	public function test_ip_ranges_unwrap_mapped_entries_for_plain_ipv4_senders(): void {
		$this->assertTrue( Ip_Ranges::contains( '10.0.0.5', '::ffff:10.0.0.5' ) );
		$this->assertTrue( Ip_Ranges::contains( '198.51.100.42', '::ffff:198.51.100.0/120' ) );
		$this->assertFalse( Ip_Ranges::contains( '198.51.101.42', '::ffff:198.51.100.0/120' ) );
		$this->assertTrue( Ip_Ranges::contains( '198.51.100.42', '::ffff:0:0/96' ), 'the /96 mapped block is all of IPv4' );
	}

	/**
	 * Plain IPv6 senders and IPv6 ranges that merely contain an IPv4-looking tail are not unwrapped.
	 *
	 * @return void
	 */
	public function test_ip_ranges_leave_plain_ipv6_alone(): void {
		$this->assertTrue( Ip_Ranges::contains( '2001:db8::1', '2001:db8::/32' ) );
		$this->assertFalse( Ip_Ranges::contains( '2001:db8::1', '198.51.100.0/24' ) );
		$this->assertFalse( Ip_Ranges::contains( '64:ff9b::198.51.100.42', '198.51.100.0/24' ) );
	}

	/**
	 * Malformed mapped addresses are refused, and a malformed entry matches nothing.
	 *
	 * @return void
	 */
	public function test_ip_ranges_refuse_invalid_mapped_input(): void {
		$this->assertFalse( Ip_Ranges::contains( '::ffff:999.1.1.1', '198.51.100.0/24' ) );
		$this->assertFalse( Ip_Ranges::contains( '::ffff:198.51.100', '198.51.100.0/24' ) );
		$this->assertFalse( Ip_Ranges::contains( '::ffff:198.51.100.42', '::ffff:bogus' ) );
	}
}
