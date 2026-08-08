<?php
/**
 * Unit tests for issue #226: `Woodev_Test_Live_Pochta_Point_Source`, the rig's OPT-IN
 * STRATEGY_VIEWPORT Point_Source that calls the real Russian Post (Почта РФ) widget API
 * instead of the three static points in `Woodev_Test_Viewport_Point_Source`.
 *
 * HARD REQUIREMENT this file exists to prove: the unit suite must never make a network call.
 * Every transport function (`wp_safe_remote_post`, `wp_safe_remote_get`, `is_wp_error`,
 * `wp_remote_retrieve_response_code`, `wp_remote_retrieve_body`, `get_transient`,
 * `set_transient`) is stubbed via Brain Monkey below.
 *
 * `WOODEV_TEST_POCHTA_SETTINGS_ID` is defined once for the whole process (see
 * `setUpBeforeClass()`), so every test here exercises the FAST settings-resolution path
 * (skips `POST /api/sites/public_show` entirely). The `ACCOUNT_ID`-resolution path and the
 * "no constants at all" guard both need a constant to be ABSENT, which is impossible once
 * defined in this process — those live in the isolated sibling file
 * `TestLivePochtaPointSourceAccountGuardTest.php`, each under `@runInSeparateProcess`, the
 * same split `TestLiveYandexPointSourceTest`/`TestLiveYandexPointSourceTokenGuardTest` use.
 *
 * The bbox and coordinate values below are the operator's own measured example from issue
 * #226's body (central Moscow: `min_lat 55.72, min_lng 37.55, max_lat 55.80, max_lng 37.70`),
 * not invented — TRAP 1's exact expected wire shape is pinned against that same example.
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
require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-live-pochta-point-source.php';

/**
 * @covers \Woodev_Test_Live_Pochta_Point_Source
 */
final class TestLivePochtaPointSourceTest extends TestCase {

	/**
	 * The measured example bbox from issue #226's body.
	 */
	private const BBOX = '55.72,37.55,55.80,37.70';

	/**
	 * Defines the fast-path settings constant once for the whole process — see the file
	 * docblock. Deliberately not the operator's real value: nothing here reaches the network
	 * (transport is stubbed in every test), so the value only has to be numeric.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'WOODEV_TEST_POCHTA_SETTINGS_ID' ) ) {
			define( 'WOODEV_TEST_POCHTA_SETTINGS_ID', 41353 );
		}
	}

	/**
	 * A real `russian_post` sparse listing record, matching the measured shape (`id`, `type`,
	 * `geo`, `address`, `deliveryPointIndex` — and nothing else).
	 *
	 * @return array<string, mixed>
	 */
	private function sparse_russian_post_record(): array {
		// VERBATIM from a live capture, 08.08.2026 (`POST /api/pvz`, central-Moscow bbox). Kept
		// byte-faithful on purpose, including the parts a hand-written fixture gets wrong and
		// therefore stops testing: `place` carries its own "г. " prefix, `street` already carries
		// its type ("ш.", "пл.", "ул."), `deliveryPointIndex` is a STRING not an int, the null
		// slots are present rather than absent, and `address.id` (62170) DIFFERS from the point
		// `id` (62257) — a mapper reading the wrong one looks correct on other records.
		// A fixture poorer than production is how s49 hid two of its four map defects.
		return [
			'address'            => [
				'addressType'    => 'DEFAULT',
				'area'           => null,
				'building'       => null,
				'corpus'         => '2',
				'hotel'          => null,
				'house'          => '3',
				'id'             => 62170,
				'index'          => '111543',
				'insertedAt'     => '2024-01-25T03:02:48',
				'letter'         => null,
				'location'       => null,
				'manualInput'    => false,
				'numAddressType' => null,
				'office'         => null,
				'place'          => 'г. Москва',
				'region'         => 'г. Москва',
				'room'           => null,
				'slash'          => null,
				'street'         => 'ш. Энтузиастов',
				'updatedAt'      => '2026-06-20T03:02:01',
				'vladenie'       => null,
			],
			'addressString'      => null,
			'deletedAt'          => null,
			'deliveryPointIndex' => '111543',
			'geo'                => [
				'coordinates' => [ 37.692995, 55.748245 ], // [lng, lat] — TRAP 1, confirmed live.
				'crs'         => [ 'properties' => [ 'name' => 'EPSG:4326' ], 'type' => 'name' ],
				'type'        => 'Point',
			],
			'id'                 => 62257,
			'type'               => 'russian_post',
		];
	}

	/**
	 * A real `postamat` sparse listing record.
	 *
	 * @return array<string, mixed>
	 */
	private function sparse_postamat_record(): array {
		// VERBATIM from a live capture, 08.08.2026 (`POST /api/pvz` with `pvzType: ["postamat"]`,
		// 34 entries over the same central-Moscow bbox). Note `deliveryPointIndex` is "990537" —
		// postamats carry a 990xxx PSEUDO-index, not a real postal code, so anything that treats
		// `deliveryPointIndex` as a postcode is wrong for a third of the points on the map.
		// `address.id` (66698) again differs from the point `id` (66790).
		return [
			'address'            => [
				'addressType'    => 'DEFAULT',
				'area'           => null,
				'building'       => null,
				'corpus'         => null,
				'hotel'          => null,
				'house'          => '6',
				'id'             => 66698,
				'index'          => '990537',
				'insertedAt'     => '2025-11-28T03:02:53',
				'letter'         => null,
				'location'       => null,
				'manualInput'    => false,
				'numAddressType' => null,
				'office'         => null,
				'place'          => 'г. Москва',
				'region'         => 'г. Москва',
				'room'           => null,
				'slash'          => null,
				'street'         => 'ул. 1-я Тверская-Ямская',
				'updatedAt'      => '2026-06-20T03:02:39',
				'vladenie'       => null,
			],
			'addressString'      => null,
			'deletedAt'          => null,
			'deliveryPointIndex' => '990537',
			'geo'                => [
				'coordinates' => [ 37.593852, 55.771582 ], // [lng, lat]
				'crs'         => [ 'properties' => [ 'name' => 'EPSG:4326' ], 'type' => 'name' ],
				'type'        => 'Point',
			],
			'id'                 => 66790,
			'type'               => 'postamat',
		];
	}

	/**
	 * The full detail record for the russian_post point above.
	 *
	 * @return array<string, mixed>
	 */
	private function full_russian_post_record(): array {
		// VERBATIM from the live capture of `GET /api/pvz/62257` (HTTP 200, 1987 bytes), the
		// detail record for the very point `sparse_russian_post_record()` returns. `workTime` is
		// the real shape and it is NOT what a hand-written fixture guesses: Pochta returns
		// already-human-readable Russian strings, one per weekday, including days off — nothing
		// to parse, unlike Yandex's structured `schedule.restrictions`.
		//
		// `cashPayment: false` here IS the live proof the lazy-detail path (#219/#223) exists
		// for: the sparse listing above says nothing about cash on delivery.
		return array_merge(
			$this->sparse_russian_post_record(),
			[
				'acceptEcom'            => true,
				'boxSize'               => null,
				'brandId'               => null,
				'brandName'             => 'Почта России',
				'cardPayment'           => false,
				'cashPayment'           => false,
				'closed'                => false,
				'contentsChecking'      => false,
				'deliveryPointType'     => 'ГОПС',
				'functionalityChecking' => false,
				'getto'                 => null,
				'holidays'              => [
					[ 'df' => '2026-05-09', 'ds' => '2026-05-09', 'work' => [ [ 'dt' => '2026-05-09', 'nm' => 6 ] ] ],
				],
				'insertedAt'            => '2026-08-08T03:00:15',
				'legalName'             => null,
				'legalShortName'        => null,
				'partialRedemption'     => false,
				'pochtaId'              => null,
				'returnAvailable'       => false,
				'temporaryClosed'       => false,
				'typesizeId'            => null,
				'typesizeVal'           => null,
				'updatedAt'             => '2026-08-08T03:01:51',
				'withFitting'           => false,
				'workTime'              => [
					'пн, выходной',
					'вт, открыто: 10:00 - 19:00',
					'ср, выходной',
					'чт, выходной',
					'пт, выходной',
					'сб, выходной',
					'вс, выходной',
				],
			]
		);
	}

	/**
	 * Stubs a successful single-page listing transport.
	 *
	 * @param array<int, mixed> $data       Raw `data` records.
	 * @param int                $total_pages `totalPages` to report.
	 *
	 * @return void
	 */
	private function stub_successful_listing_transport( array $data, int $total_pages = 1 ): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				[
					'data'         => $data,
					'pageNumber'   => 1,
					'totalEntries' => count( $data ),
					'totalPages'   => $total_pages,
				]
			)
		);
		Functions\when( 'set_transient' )->justReturn( true );
	}

	public function test_strategy_is_viewport(): void {
		$source = new \Woodev_Test_Live_Pochta_Point_Source();

		$this->assertSame( 'viewport', $source->get_strategy() );
	}

	/**
	 * TRAP 1, pinned exactly against the operator's own measured example: the bbox corners
	 * sent to Pochta are `[lng, lat]`, and TopRight must carry the MAXIMA, BottomLeft the
	 * MINIMA — reversing either silently returns 0 points in production.
	 */
	public function test_bbox_corners_are_sent_as_lng_lat_not_lat_lng(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'data' => [], 'totalPages' => 1 ] )
		);
		Functions\when( 'set_transient' )->justReturn( true );

		Functions\expect( 'wp_safe_remote_post' )->once()->with(
			'https://widget.pochta.ru/api/pvz',
			\Mockery::on(
				static function ( array $args ): bool {
					$body = json_decode( $args['body'], true );

					return [ 37.70, 55.80 ] === $body['currentTopRightPoint']
						&& [ 37.55, 55.72 ] === $body['currentBottomLeftPoint'];
				}
			)
		)->andReturn( [ 'fake' => 'response' ] );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );
	}

	public function test_maps_both_russian_post_and_postamat_onto_fixture_type_codes(): void {
		$this->stub_successful_listing_transport( [ $this->sparse_russian_post_record(), $this->sparse_postamat_record() ] );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );

		$this->assertCount( 2, $points );

		$by_id = [];
		foreach ( $points as $point ) {
			$by_id[ $point->get_id() ] = $point;
		}

		// Keyed on the TOP-LEVEL `id` (62257), not `address.id` (62170) — the live capture has
		// them differing, which is what makes reading the wrong one a silent mis-key.
		$office = $by_id['62257'];
		$this->assertSame( [ 'code' => 'PVZ', 'label' => 'Почтовое отделение' ], $office->to_array()['type'] );

		// TRAP 1, pinned against the live record: `geo.coordinates` is [lng, lat], so the
		// LATITUDE is the SECOND element. Swapping these puts the point in the Indian Ocean.
		$this->assertSame( 55.748245, $office->get_lat() );
		$this->assertSame( 37.692995, $office->get_lng() );

		$postamat = $by_id['66790'];
		$this->assertSame( [ 'code' => 'POSTAMAT', 'label' => 'Почтомат' ], $postamat->to_array()['type'] );
	}

	/**
	 * Issue #226 point 1 — the sparse listing carries no name at all; this fixture
	 * synthesizes one from the type label, the postal index, and the city.
	 */
	public function test_sparse_listing_synthesizes_a_name(): void {
		$this->stub_successful_listing_transport( [ $this->sparse_russian_post_record() ] );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );

		// The carrier's own `place` carries its "г. " prefix; we pass it through rather than
		// second-guessing it. Live values.
		$this->assertSame( 'Почтовое отделение №111543, г. Москва', $points[0]->to_array()['name'] );
	}

	/**
	 * Address composed from the structural fields, matching the real widget's own
	 * street/house-part composition (verified in the vendored bundle — see the fixture class's
	 * own ADDRESS COMPOSITION docblock section).
	 */
	public function test_sparse_listing_composes_address_from_structural_fields(): void {
		$this->stub_successful_listing_transport( [ $this->sparse_russian_post_record() ] );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );

		// Live values. Note `street` ALREADY carries its type ("ш."), so the composer must not
		// prepend one of its own — a hand-written fixture using a bare street name never tests
		// that, and would let "ул. ш. Энтузиастов" through.
		$this->assertSame( 'г. Москва, ш. Энтузиастов 3 к. 2', $points[0]->get_address() );
	}

	public function test_each_type_carries_its_own_short_name_for_the_tab_label(): void {
		$this->stub_successful_listing_transport( [ $this->sparse_russian_post_record(), $this->sparse_postamat_record() ] );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );

		$short_by_code = [];
		foreach ( $points as $point ) {
			$short_by_code[ $point->to_array()['type']['code'] ] = $point->to_array()['point_short_name'];
		}

		$this->assertSame( 'Почта', $short_by_code['PVZ'] );
		$this->assertSame( 'Почтомат', $short_by_code['POSTAMAT'] );
	}

	/**
	 * D-10: an empty `get_types()` means "all types" — this fixture sends every known
	 * `pvzType` value explicitly rather than omitting the parameter (see class docblock).
	 */
	public function test_empty_type_filter_requests_every_known_pvz_type(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'data' => [], 'totalPages' => 1 ] ) );
		Functions\when( 'set_transient' )->justReturn( true );

		Functions\expect( 'wp_safe_remote_post' )->once()->with(
			\Mockery::any(),
			\Mockery::on(
				static function ( array $args ): bool {
					$body = json_decode( $args['body'], true );

					return [ 'russian_post', 'postamat' ] === $body['pvzType'];
				}
			)
		)->andReturn( [ 'fake' => 'response' ] );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );
	}

	/**
	 * A single-type filter maps back to exactly one `pvzType` value.
	 */
	public function test_single_type_filter_maps_to_one_pvz_type(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'data' => [], 'totalPages' => 1 ] ) );
		Functions\when( 'set_transient' )->justReturn( true );

		Functions\expect( 'wp_safe_remote_post' )->once()->with(
			\Mockery::any(),
			\Mockery::on(
				static function ( array $args ): bool {
					$body = json_decode( $args['body'], true );

					return [ 'postamat' ] === $body['pvzType'];
				}
			)
		)->andReturn( [ 'fake' => 'response' ] );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX, 'types' => 'POSTAMAT' ] ) );
	}

	/**
	 * A type filter naming ONLY codes this source cannot translate must short-circuit to an
	 * empty result WITHOUT ever touching the network — no carrier type can match, so a request
	 * would only spend a round trip to learn nothing.
	 */
	public function test_unrecognised_type_filter_never_touches_the_network(): void {
		Functions\expect( 'wp_safe_remote_post' )->never();
		Functions\expect( 'get_transient' )->never();

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX, 'types' => 'FUTURE_TYPE' ] ) );

		$this->assertSame( [], $points );
	}

	/**
	 * Pagination is real — a `totalPages: 2` first response must trigger a second request,
	 * and the results must merge, bounded per the class's own `MAX_PAGES`.
	 */
	public function test_pagination_follows_totalPages_and_merges_results(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'set_transient' )->justReturn( true );

		$page_one   = json_encode(
			[ 'data' => [ $this->sparse_russian_post_record() ], 'pageNumber' => 1, 'totalPages' => 2 ]
		);
		$page_two   = json_encode(
			[ 'data' => [ $this->sparse_postamat_record() ], 'pageNumber' => 2, 'totalPages' => 2 ]
		);
		$call_count = 0;

		Functions\expect( 'wp_safe_remote_post' )->twice()->andReturnUsing(
			static function () use ( &$call_count ) {
				++$call_count;
				return [ 'fake' => 'response', 'page' => $call_count ];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) use ( $page_one, $page_two ) {
				return 1 === $response['page'] ? $page_one : $page_two;
			}
		);

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );

		$this->assertCount( 2, $points );
	}

	/**
	 * A malformed record (unrecognised type) must be SKIPPED, not abort the whole fetch.
	 */
	public function test_malformed_record_is_skipped_not_fatal(): void {
		$foreign_record         = $this->sparse_russian_post_record();
		$foreign_record['type'] = 'additional_pvz';

		$this->stub_successful_listing_transport( [ $foreign_record, $this->sparse_postamat_record() ] );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );

		$this->assertCount( 1, $points );
		$this->assertSame( 'POSTAMAT', $points[0]->to_array()['type']['code'] );
	}

	public function test_transport_failure_throws_api_exception(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'Connection timed out' ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\expect( 'set_transient' )->never();

		$this->expectException( \Woodev_API_Exception::class );

		( new \Woodev_Test_Live_Pochta_Point_Source() )->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );
	}

	public function test_non_200_response_throws_api_exception(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"internal"}' );
		Functions\expect( 'set_transient' )->never();

		$this->expectException( \Woodev_API_Exception::class );

		( new \Woodev_Test_Live_Pochta_Point_Source() )->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );
	}

	public function test_unexpected_body_shape_throws_api_exception(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"unexpected":true}' );
		Functions\expect( 'set_transient' )->never();

		$this->expectException( \Woodev_API_Exception::class );

		( new \Woodev_Test_Live_Pochta_Point_Source() )->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );
	}

	public function test_cached_response_never_hits_the_transport(): void {
		Functions\when( 'get_transient' )->justReturn( [ $this->sparse_russian_post_record() ] );
		Functions\expect( 'wp_safe_remote_post' )->never();
		Functions\expect( 'set_transient' )->never();

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$points = $source->fetch_points( Point_Query::from_request( [ 'bbox' => self::BBOX ] ) );

		$this->assertCount( 1, $points );
	}

	// -----------------------------------------------------------------------------
	// fetch_details() — full record merge, TRAP 2, and the deliberate no-account-gate design.
	// -----------------------------------------------------------------------------

	private function stub_successful_details_transport( array $body ): void {
		Functions\when( 'wp_safe_remote_get' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $body ) );
	}

	/**
	 * `cashPayment: false` in the full record must reach `accepts_cod` as PHP `false`, and the
	 * full record's payment methods / work_time / services must all merge onto the point.
	 */
	public function test_fetch_details_merges_the_full_record(): void {
		$this->stub_successful_details_transport( $this->full_russian_post_record() );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();
		$point  = $source->fetch_details( '62257' );

		$this->assertNotNull( $point );

		// THE POINT OF THIS WHOLE CARD: the sparse listing said nothing about cash on delivery,
		// and the live detail record says `cashPayment: false`. This is #219/#223's lazy-detail
		// path proven against a real carrier instead of a fixture that carried it up front.
		$this->assertFalse( $point->get_accepts_cod() );

		// Live record has `cardPayment: false` and `acceptEcom: true` — so no card-on-receipt
		// label. An invented fixture had `cardPayment: true` here and was asserting a label the
		// real point does not have.
		$this->assertSame( [], $point->to_array()['payment_methods'] );

		// Pochta returns already-readable Russian strings, one per weekday, days off included —
		// there is nothing to parse, so the mapper joins them and stops.
		$this->assertStringContainsString( 'вт, открыто: 10:00 - 19:00', $point->to_array()['work_time'] );
		$this->assertStringContainsString( 'пн, выходной', $point->to_array()['work_time'] );

		// Every service flag is false on this real record.
		$this->assertSame( [], $point->to_array()['services'] );
	}

	/**
	 * TRAP 2 — a wrong key (the postal index instead of the numeric id) returns HTTP 200 with
	 * a shapeless 4-byte body (`null`). This must resolve to `null`, NOT a thrown exception —
	 * nothing failed upstream, this key simply does not resolve to a record.
	 */
	public function test_trap_two_empty_success_body_returns_null_not_an_exception(): void {
		Functions\when( 'wp_safe_remote_get' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'null' );

		$source = new \Woodev_Test_Live_Pochta_Point_Source();

		$this->assertNull( $source->fetch_details( '111673' ) );
	}

	public function test_fetch_details_transport_failure_throws_api_exception(): void {
		Functions\when( 'wp_safe_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'Connection timed out' ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );

		$this->expectException( \Woodev_API_Exception::class );

		( new \Woodev_Test_Live_Pochta_Point_Source() )->fetch_details( '26600' );
	}

	public function test_fetch_details_non_200_throws_api_exception(): void {
		Functions\when( 'wp_safe_remote_get' )->justReturn( [ 'fake' => 'response' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->expectException( \Woodev_API_Exception::class );

		( new \Woodev_Test_Live_Pochta_Point_Source() )->fetch_details( '26600' );
	}
}
