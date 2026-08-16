<?php
/**
 * Unit tests for the rig's CDEK fixture provider (issue #343).
 *
 * Covers the two things about it that are decided in OUR code rather than by the carrier,
 * and are therefore ours to keep from regressing:
 *
 * 1. The SHAPE it declares — region and settlement, never `address`, and the `list`
 *    capability. That shape is the whole reason the fixture exists (it is what makes #337's
 *    "no address suggestions ⇒ no lock" rule observable against a live provider), and it is
 *    exactly the kind of thing a well-meaning later edit widens by accident.
 * 2. The ONE derived rule in it — splitting CDEK's `full_name` composite into display
 *    components. The strings below are verbatim captures from the live test contour
 *    (16.08.2026), all three shapes it actually produces.
 *
 * Nothing here touches the network: both are answerable without a token, which is why the
 * parse was extracted as a pure static in the first place.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 3 ) . '/_fixtures/woodev-test-shipping-method/class-test-cdek-location-provider.php';

/**
 * Class CdekFixtureProviderTest
 */
class CdekFixtureProviderTest extends TestCase {

	/**
	 * The declared levels are region and settlement — and `address` is NOT among them.
	 *
	 * Asserted as an explicit absence, not merely as an expected pair: this is the
	 * property #343's scenario A and #337's lock rule both hang off, and it deserves to
	 * fail with a message that says which one broke.
	 */
	public function test_it_declares_region_and_settlement_but_never_address(): void {
		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->assertSame(
			[ Location_Record::LEVEL_REGION, Location_Record::LEVEL_SETTLEMENT ],
			$provider->get_suggest_levels()
		);
		$this->assertNotContains(
			Location_Record::LEVEL_ADDRESS,
			$provider->get_suggest_levels(),
			'CDEK has no street data — a fixture claiming the address level would stop being the live case #343 needs.'
		);
	}

	/**
	 * The per-country answer does not widen the unnarrowed one — CDEK's dictionary has no
	 * street data anywhere, so no country may report an address level.
	 */
	public function test_no_country_reports_an_address_level(): void {
		$provider = new \Woodev_Test_Cdek_Location_Provider();

		foreach ( $provider->get_countries() as $country ) {
			$this->assertNotContains(
				Location_Record::LEVEL_ADDRESS,
				$provider->get_suggest_levels( $country ),
				sprintf( 'Country %s must not report an address level.', $country )
			);
		}
	}

	/**
	 * The `list` capability is declared (by overriding `list_localities()`), and the two
	 * capabilities this provider does NOT implement are not.
	 */
	public function test_it_declares_only_the_list_capability(): void {
		$provider = new \Woodev_Test_Cdek_Location_Provider();

		$this->assertSame( [ Location_Provider::CAPABILITY_LIST ], $provider->get_capabilities() );
	}

	/**
	 * A three-part `full_name`: settlement, region, country.
	 */
	public function test_it_splits_a_settlement_with_a_region(): void {
		$this->assertSame(
			[
				'settlement' => 'Московский',
				'district'   => '',
				'region'     => 'Московская область',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( 'Московский, Московская область, Россия' )
		);
	}

	/**
	 * A four-part `full_name`: settlement, district, region, country.
	 */
	public function test_it_splits_a_settlement_with_a_district(): void {
		$this->assertSame(
			[
				'settlement' => 'Московская',
				'district'   => 'Афанасьевский район',
				'region'     => 'Кировская область',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( 'Московская, Афанасьевский район, Кировская область, Россия' )
		);
	}

	/**
	 * A FEDERAL CITY carries no region part at all — the parse must leave the region empty
	 * rather than promoting the country into it. (Recovering the region for this case is a
	 * separate, dictionary-confirmed step in the provider itself, deliberately not part of
	 * the parse.)
	 */
	public function test_it_leaves_a_federal_city_without_a_region(): void {
		$this->assertSame(
			[
				'settlement' => 'Москва',
				'district'   => '',
				'region'     => '',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( 'Москва, Россия' )
		);
	}

	/**
	 * Degenerate input never throws and never invents a component: an empty string yields
	 * an empty settlement, and a single part is a settlement with nothing above it.
	 */
	public function test_it_survives_degenerate_input(): void {
		$this->assertSame(
			[
				'settlement' => '',
				'district'   => '',
				'region'     => '',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( '' )
		);

		$this->assertSame(
			[
				'settlement' => 'Одинокое',
				'district'   => '',
				'region'     => '',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( 'Одинокое' )
		);
	}

	/**
	 * Whitespace and empty segments are cleaned rather than carried into a component —
	 * CDEK's own strings are tidy, but a component with a stray leading space is exactly
	 * the kind of thing that later reads as a different locality name.
	 */
	public function test_it_trims_and_drops_empty_segments(): void {
		$this->assertSame(
			[
				'settlement' => 'Пушкино',
				'district'   => 'Пушкинский городской округ',
				'region'     => 'Московская область',
			],
			\Woodev_Test_Cdek_Location_Provider::split_full_name( ' Пушкино ,, Пушкинский городской округ ,  Московская область , Россия ' )
		);
	}
}
