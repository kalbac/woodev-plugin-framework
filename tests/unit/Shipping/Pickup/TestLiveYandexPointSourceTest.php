<?php
/**
 * Unit tests for issue #185: `Woodev_Test_Live_Yandex_Point_Source`, the rig's OPT-IN
 * Point_Source that calls the real Yandex.Delivery sandbox instead of a static array.
 *
 * HARD REQUIREMENT this file exists to prove: the unit suite must never make a network
 * call. Every transport function (`wp_safe_remote_post`, `is_wp_error`,
 * `wp_remote_retrieve_response_code`, `wp_remote_retrieve_body`, `get_transient`,
 * `set_transient`) is stubbed via Brain Monkey below — `wp_safe_remote_post` is never the
 * real WordPress HTTP client here, and {@see self::test_foreign_locality_never_touches_the_network()}
 * additionally asserts it is not even CALLED for the (very common) case where the requested
 * locality is not this fixture's own city.
 *
 * The canned payloads below are not invented — they are trimmed, byte-preserved copies of
 * TWO records from a real 07.08.2026 `curl` against
 * `POST https://b2b.taxi.tst.yandex.net/api/b2b/platform/pickup-points/list` (`geo_id: 213`):
 * one `pickup_point` ("ГиперПВЗ-2") and one `terminal` ("Постамат Яндекс.Маркет"), so the
 * mapping assertions below are checked against the REAL observed shape, not a guess at the
 * documented one.
 *
 * `Woodev_Test_Live_Yandex_Point_Source` lives in its own file for the same reason as
 * `Woodev_Test_Bulk_Point_Source` (see that class's own docblock and
 * {@see \Woodev\Tests\Unit\Shipping\Pickup\TestShippingMethodFixtureLocalityTest}, whose
 * `require_once` list this test mirrors): it can be pulled in directly, bypassing
 * `Woodev_Plugin_Bootstrap`'s process-wide, load-once singleton.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-live-yandex-point-source.php';

/**
 * @covers \Woodev_Test_Live_Yandex_Point_Source
 */
final class TestLiveYandexPointSourceTest extends TestCase {

	/**
	 * The source reads its bearer token from a constant rather than a literal, because this
	 * repository is public and the real value is a third party's credential (see the source's
	 * own TOKEN docblock). Every test that exercises a SUCCESSFUL fetch therefore has to get
	 * past that guard first, so a dummy is defined once for the whole process.
	 *
	 * Deliberately not the real token: nothing here reaches the network — the transport is
	 * stubbed in every test — so the value only has to be non-empty.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'WOODEV_TEST_YANDEX_SANDBOX_TOKEN' ) ) {
			define( 'WOODEV_TEST_YANDEX_SANDBOX_TOKEN', 'dummy-token-for-tests' );
		}
	}

	/**
	 * A real `pickup_point` record, trimmed to the fields this class reads, captured live
	 * 07.08.2026 (id/name/coordinates/address/schedule/payment_methods are byte-preserved).
	 *
	 * @return array<string, mixed>
	 */
	private function real_pickup_point_record(): array {
		return [
			'id'               => '019373a0ecd97151922149c5094feaf6',
			'operator_station_id' => '10001218535',
			'name'             => 'ГиперПВЗ-2',
			'type'             => 'pickup_point',
			'position'         => [ 'latitude' => 55.740522, 'longitude' => 37.855554 ],
			'address'          => [
				'locality'     => 'Москва',
				'postal_code'  => '111673',
				'full_address' => 'Москва Новокосинская 17 к6',
			],
			'instruction'      => '',
			'payment_methods'  => [ 'already_paid', 'card_on_receipt' ],
			'contact'          => [ 'phone' => '+74951570020' ],
			'schedule'         => [
				'time_zone'    => 3,
				'restrictions' => [
					[ 'days' => [ 1 ], 'time_from' => [ 'hours' => 0, 'minutes' => 0 ], 'time_to' => [ 'hours' => 23, 'minutes' => 59 ] ],
					[ 'days' => [ 2 ], 'time_from' => [ 'hours' => 0, 'minutes' => 0 ], 'time_to' => [ 'hours' => 23, 'minutes' => 59 ] ],
					[ 'days' => [ 3 ], 'time_from' => [ 'hours' => 0, 'minutes' => 0 ], 'time_to' => [ 'hours' => 23, 'minutes' => 59 ] ],
					[ 'days' => [ 4 ], 'time_from' => [ 'hours' => 0, 'minutes' => 0 ], 'time_to' => [ 'hours' => 23, 'minutes' => 59 ] ],
					[ 'days' => [ 5 ], 'time_from' => [ 'hours' => 0, 'minutes' => 0 ], 'time_to' => [ 'hours' => 23, 'minutes' => 59 ] ],
					[ 'days' => [ 6 ], 'time_from' => [ 'hours' => 0, 'minutes' => 0 ], 'time_to' => [ 'hours' => 23, 'minutes' => 59 ] ],
					[ 'days' => [ 7 ], 'time_from' => [ 'hours' => 0, 'minutes' => 0 ], 'time_to' => [ 'hours' => 23, 'minutes' => 59 ] ],
				],
			],
			'is_yandex_branded' => true,
			'pickup_services'   => [
				'is_fitting_allowed'          => true,
				'is_partial_refuse_allowed'   => true,
				'is_paperless_pickup_allowed' => false,
				'is_unboxing_allowed'         => true,
			],
		];
	}

	/**
	 * A real `terminal` record, trimmed the same way, captured live 07.08.2026.
	 *
	 * @return array<string, mixed>
	 */
	private function real_terminal_record(): array {
		return [
			'id'              => '5a6879c2-7910-44c1-a134-3de32d35e10c',
			'name'            => 'Постамат Яндекс.Маркет',
			'type'            => 'terminal',
			'position'        => [ 'latitude' => 55.75130844116211, 'longitude' => 37.58462142944336 ],
			'address'         => [
				'locality'     => 'Москва',
				'postal_code'  => '121099',
				'full_address' => 'Москва Новинский бульвар 8',
			],
			'instruction'     => '',
			'payment_methods' => [ 'already_paid', 'card_on_receipt' ],
			'contact'         => [ 'phone' => '+74951570020' ],
			'schedule'        => [
				'time_zone'    => 3,
				'restrictions' => [
					[ 'days' => [ 1 ], 'time_from' => [ 'hours' => 8, 'minutes' => 0 ], 'time_to' => [ 'hours' => 22, 'minutes' => 0 ] ],
					[ 'days' => [ 2 ], 'time_from' => [ 'hours' => 8, 'minutes' => 0 ], 'time_to' => [ 'hours' => 22, 'minutes' => 0 ] ],
					[ 'days' => [ 3 ], 'time_from' => [ 'hours' => 8, 'minutes' => 0 ], 'time_to' => [ 'hours' => 22, 'minutes' => 0 ] ],
					[ 'days' => [ 4 ], 'time_from' => [ 'hours' => 8, 'minutes' => 0 ], 'time_to' => [ 'hours' => 22, 'minutes' => 0 ] ],
					[ 'days' => [ 5 ], 'time_from' => [ 'hours' => 8, 'minutes' => 0 ], 'time_to' => [ 'hours' => 22, 'minutes' => 0 ] ],
					[ 'days' => [ 6 ], 'time_from' => [ 'hours' => 8, 'minutes' => 0 ], 'time_to' => [ 'hours' => 22, 'minutes' => 0 ] ],
					[ 'days' => [ 7 ], 'time_from' => [ 'hours' => 8, 'minutes' => 0 ], 'time_to' => [ 'hours' => 22, 'minutes' => 0 ] ],
				],
			],
			'is_yandex_branded' => false,
			'pickup_services'   => [
				'is_fitting_allowed'          => false,
				'is_partial_refuse_allowed'   => false,
				'is_paperless_pickup_allowed' => false,
				'is_unboxing_allowed'         => false,
			],
		];
	}

	/**
	 * Stubs a successful transport returning the given raw `points` array.
	 *
	 * @param array<int, mixed> $points Raw points array to embed as the JSON body.
	 *
	 * @return void
	 */
	private function stub_successful_transport( array $points ): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'points' => $points ] ) );
	}

	public function test_strategy_is_bulk(): void {
		$source = new \Woodev_Test_Live_Yandex_Point_Source();

		$this->assertSame( 'bulk', $source->get_strategy() );
	}

	/**
	 * The most common miss case (a locality that is not Moscow) must resolve without
	 * ever touching the transport — proves the network gate sits BEFORE the HTTP call,
	 * not just around the class as a whole.
	 */
	public function test_foreign_locality_never_touches_the_network(): void {
		Functions\expect( 'wp_safe_remote_post' )->never();
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$query  = Point_Query::from_request( [ 'locality' => 'Новосибирск' ] );

		$this->assertSame( [], $source->fetch_points( $query ) );
	}

	public function test_locality_matching_ignores_case_and_surrounding_whitespace(): void {
		$this->stub_successful_transport( [ $this->real_pickup_point_record() ] );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$query  = Point_Query::from_request( [ 'locality' => '  МОСКВА  ' ] );

		$this->assertNotEmpty( $source->fetch_points( $query ) );
	}

	public function test_maps_both_pickup_point_and_terminal_onto_fixture_type_codes(): void {
		$this->stub_successful_transport( [ $this->real_pickup_point_record(), $this->real_terminal_record() ] );
		Functions\expect( 'set_transient' )->once()->with(
			'woodev_test_live_yandex_points_213',
			$this->anything(),
			DAY_IN_SECONDS
		)->andReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$query  = Point_Query::from_request( [ 'locality' => 'Москва' ] );
		$points = $source->fetch_points( $query );

		$this->assertCount( 2, $points );

		$by_id = [];
		foreach ( $points as $point ) {
			$by_id[ $point->get_id() ] = $point;
		}

		$pvz = $by_id['019373a0ecd97151922149c5094feaf6'];
		$this->assertSame( [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ], $pvz->to_array()['type'] );
		$this->assertSame( 55.740522, $pvz->get_lat() );
		$this->assertSame( 37.855554, $pvz->get_lng() );

		$postamat = $by_id['5a6879c2-7910-44c1-a134-3de32d35e10c'];
		$this->assertSame( [ 'code' => 'POSTAMAT', 'label' => 'Постамат' ], $postamat->to_array()['type'] );
	}

	/**
	 * Issue #207: Yandex's payload carries no short name, so co-located tabs fell back to
	 * `type.label` and read "Пункт выдачи заказов 1/2" — too long for a tab. The fixture now
	 * supplies a per-type short name; the framework still does the numbering.
	 *
	 * The assertion on `type` above is what pins the other half of this: `TYPE_MAP`'s extra
	 * `short` key must NOT leak into the point's `type` array (`from_array()` whitelists
	 * `code`/`label`), or the browser would receive a shape the contract never declared.
	 */
	public function test_each_type_carries_its_own_short_name_for_the_tab_label(): void {
		$this->stub_successful_transport( [ $this->real_pickup_point_record(), $this->real_terminal_record() ] );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );

		$short_by_code = [];
		foreach ( $points as $point ) {
			$short_by_code[ $point->to_array()['type']['code'] ] = $point->to_array()['point_short_name'];
		}

		$this->assertSame( 'ПВЗ', $short_by_code['PVZ'] );
		$this->assertSame( 'Постамат', $short_by_code['POSTAMAT'] );
	}

	public function test_unrecognised_type_is_skipped_not_fatal(): void {
		$foreign_type_record         = $this->real_pickup_point_record();
		$foreign_type_record['type'] = 'a_future_yandex_type_this_fixture_does_not_know';

		$this->stub_successful_transport( [ $foreign_type_record, $this->real_terminal_record() ] );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );

		// The unknown-typed record is dropped; the terminal survives — one bad record must
		// not empty the whole map.
		$this->assertCount( 1, $points );
		$this->assertSame( 'POSTAMAT', $points[0]->to_array()['type']['code'] );
	}

	public function test_payment_methods_map_to_russian_labels_and_drop_unknown_codes(): void {
		$record                     = $this->real_pickup_point_record();
		$record['payment_methods'][] = 'a_future_code_this_fixture_does_not_know';

		$this->stub_successful_transport( [ $record ] );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );

		// Issue #200 wording pass: 'already_paid' -> 'Предоплата' (the old "Товар уже
		// оплачен" read as "the item is already paid for", not "pay in advance"),
		// 'card_on_receipt' -> 'Картой при получении' (dropped the "Оплата" that the chip's
		// own section title, "Способы оплаты", already says).
		$this->assertSame(
			[ 'Предоплата', 'Картой при получении' ],
			$points[0]->to_array()['payment_methods']
		);
	}

	public function test_schedule_flattens_seven_identical_days_into_one_span(): void {
		$this->stub_successful_transport( [ $this->real_pickup_point_record() ] );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );

		$this->assertSame( 'Пн–Вс 00:00–23:59', $points[0]->to_array()['work_time'] );
	}

	public function test_schedule_flattens_differing_span_from_the_terminal_record(): void {
		$this->stub_successful_transport( [ $this->real_terminal_record() ] );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );

		$this->assertSame( 'Пн–Вс 08:00–22:00', $points[0]->to_array()['work_time'] );
	}

	public function test_pickup_services_flags_map_to_russian_service_labels(): void {
		$this->stub_successful_transport( [ $this->real_pickup_point_record() ] );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );

		$this->assertSame(
			[ 'Примерка', 'Частичный отказ от заказа', 'Вскрытие упаковки' ],
			$points[0]->to_array()['services']
		);
	}

	public function test_cached_response_never_hits_the_transport(): void {
		Functions\when( 'get_transient' )->justReturn( [ $this->real_pickup_point_record() ] );
		Functions\expect( 'wp_safe_remote_post' )->never();
		Functions\expect( 'set_transient' )->never();

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );

		$this->assertCount( 1, $points );
	}

	public function test_fetch_details_finds_a_point_by_id_from_the_cached_list(): void {
		Functions\when( 'get_transient' )->justReturn( [ $this->real_pickup_point_record(), $this->real_terminal_record() ] );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$point  = $source->fetch_details( '5a6879c2-7910-44c1-a134-3de32d35e10c' );

		$this->assertNotNull( $point );
		$this->assertSame( 'Постамат Яндекс.Маркет', $point->to_array()['name'] );
	}

	// -----------------------------------------------------------------------------
	// Per-point icons by operator_id (issue #193): 5post and Yandex-branded/market
	// points share the SAME type code (`pickup_point`) in a live response — a live
	// 812-point Moscow sample measured 679 `5post` + 129 `market_l4g` points, both
	// reporting `type: "pickup_point"` — so only a per-point field, `operator_id`,
	// can tell them apart. This wires the fixture's own consumer for the framework's
	// new cascade tier 1 (`Pickup_Point`'s `icons` field).
	// -----------------------------------------------------------------------------

	/**
	 * A `5post` point gets its own icon override, reusing the fixture's EXISTING terminal
	 * SVGs (already shipped for the `POSTAMAT` type-level icon) rather than new artwork —
	 * visually distinguishable from the office pin the `PVZ` type tier already draws for
	 * every OTHER `pickup_point`, which is exactly what a 5post point needs: it is a
	 * DIFFERENT branded network sharing the same type code.
	 */
	public function test_5post_points_get_their_own_icon_by_operator_id(): void {
		Functions\when( 'plugins_url' )->alias(
			static fn( $path, $file ) => 'https://example.test/wp-content/plugins/woodev-test-shipping-method/' . $path
		);

		$record                 = $this->real_pickup_point_record();
		$record['operator_id']  = '5post';

		$this->stub_successful_transport( [ $record ] );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );

		$this->assertSame(
			[
				'default' => 'https://example.test/wp-content/plugins/woodev-test-shipping-method/'
					. 'assets/images/yandex-delivery-map-pin-terminal.svg',
				'active'  => 'https://example.test/wp-content/plugins/woodev-test-shipping-method/'
					. 'assets/images/yandex-delivery-map-pin-terminal-active.svg',
			],
			$points[0]->to_array()['icons']
		);
	}

	/**
	 * A `market_l4g` (or any other) operator gets no icon override of its own — it falls
	 * through to the domain's existing type-keyed tier (`PVZ`'s office icon), unchanged.
	 */
	public function test_non_5post_operators_get_no_icon_of_their_own(): void {
		$record                = $this->real_pickup_point_record();
		$record['operator_id'] = 'market_l4g';

		$this->stub_successful_transport( [ $record ] );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );

		$this->assertNull( $points[0]->to_array()['icons'] );
	}

	/**
	 * The captured live records above carry no `operator_id` at all — a record with the
	 * field simply absent must not fatal, and must resolve to "no icon of its own" exactly
	 * like an explicit non-5post value.
	 */
	public function test_a_record_with_no_operator_id_at_all_gets_no_icon_of_its_own(): void {
		$this->stub_successful_transport( [ $this->real_terminal_record() ] );
		Functions\when( 'set_transient' )->justReturn( true );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );

		$this->assertNull( $points[0]->to_array()['icons'] );
	}

	public function test_fetch_details_returns_null_for_an_unknown_id(): void {
		Functions\when( 'get_transient' )->justReturn( [ $this->real_pickup_point_record() ] );

		$source = new \Woodev_Test_Live_Yandex_Point_Source();

		$this->assertNull( $source->fetch_details( 'no-such-id' ) );
	}

	/**
	 * `wp_safe_remote_post()` returning a `WP_Error` (a real transport failure — blocked
	 * host, DNS failure, timeout) must surface as `\Woodev_API_Exception`, matching the
	 * `Point_Source` interface's own `@throws` contract, never as a silently empty list.
	 */
	public function test_transport_failure_throws_api_exception(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'Connection timed out' ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\expect( 'set_transient' )->never();

		$this->expectException( \Woodev_API_Exception::class );

		( new \Woodev_Test_Live_Yandex_Point_Source() )->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );
	}

	/**
	 * A non-200 HTTP response (rate limit, auth failure, sandbox 5xx) must throw, not
	 * report zero points for a city that in fact has points.
	 */
	public function test_non_200_response_throws_api_exception(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"code":"429","message":"rate limited"}' );
		Functions\expect( 'set_transient' )->never();

		$this->expectException( \Woodev_API_Exception::class );

		( new \Woodev_Test_Live_Yandex_Point_Source() )->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );
	}

	/**
	 * A 200 response whose body is not `{ "points": [...] }` (HTML error page, truncated
	 * JSON, a shape the sandbox changed underneath us) must throw rather than being
	 * silently treated as zero points.
	 */
	public function test_unexpected_body_shape_throws_api_exception(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"unexpected":true}' );
		Functions\expect( 'set_transient' )->never();

		$this->expectException( \Woodev_API_Exception::class );

		( new \Woodev_Test_Live_Yandex_Point_Source() )->fetch_points( Point_Query::from_request( [ 'locality' => 'Москва' ] ) );
	}
}
