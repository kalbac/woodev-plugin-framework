<?php
/**
 * Unit tests for Pickup_Schedule — the structured weekly opening hours of a pickup point
 * (issue #152): validation of carrier data, the closed/unknown distinction, and the single
 * flat rendering.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Pickup\Pickup_Schedule;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-schedule.php';

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Schedule
 */
final class PickupScheduleTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wp_locale'] );

		parent::tearDown();
	}

	public function test_a_well_formed_schedule_is_returned_unchanged(): void {
		$raw = [
			'mon' => [ [ '09:00', '13:00' ], [ '14:00', '18:00' ] ],
			'sat' => [ [ '10:00', '14:00' ] ],
			'sun' => [],
		];

		$this->assertSame( $raw, Pickup_Schedule::normalize( $raw ) );
	}

	public function test_non_arrays_and_schedules_without_a_usable_day_are_unknown(): void {
		foreach ( [ null, '', 'Пн-Пт 9-18', 5, true, [], [ 'monday' => [ [ '09:00', '18:00' ] ] ], [ 'mon' => 'open' ] ] as $raw ) {
			$this->assertNull( Pickup_Schedule::normalize( $raw ), var_export( $raw, true ) );
		}
	}

	public function test_days_come_out_in_week_order_whatever_order_the_carrier_used(): void {
		$schedule = Pickup_Schedule::normalize(
			[
				'sun' => [],
				'wed' => [ [ '09:00', '18:00' ] ],
				'mon' => [ [ '09:00', '18:00' ] ],
			]
		);

		$this->assertSame( [ 'mon', 'wed', 'sun' ], array_keys( $schedule ) );
	}

	/**
	 * @dataProvider malformed_intervals
	 *
	 * @param mixed $interval One bad interval.
	 */
	public function test_a_malformed_interval_is_dropped_and_the_good_one_beside_it_survives( $interval ): void {
		$schedule = Pickup_Schedule::normalize( [ 'mon' => [ $interval, [ '09:00', '18:00' ] ] ] );

		$this->assertSame( [ 'mon' => [ [ '09:00', '18:00' ] ] ], $schedule, var_export( $interval, true ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function malformed_intervals(): array {
		return [
			'not an array'          => [ '09:00-18:00' ],
			'one element'           => [ [ '09:00' ] ],
			'three elements'        => [ [ '09:00', '12:00', '18:00' ] ],
			'integers'              => [ [ 9, 18 ] ],
			'null end'              => [ [ '09:00', null ] ],
			'hour out of range'     => [ [ '09:00', '25:00' ] ],
			'minute out of range'   => [ [ '09:00', '18:60' ] ],
			'no leading zero'       => [ [ '9:00', '18:00' ] ],
			'seconds'               => [ [ '09:00:00', '18:00' ] ],
			'trailing newline'      => [ [ '09:00', "18:00\n" ] ],
			'24:00 as a start'      => [ [ '24:00', '23:00' ] ],
			'empty interval'        => [ [ '12:00', '12:00' ] ],
			'free text'             => [ [ 'открыто', 'закрыто' ] ],
		];
	}

	public function test_24_00_is_a_valid_end(): void {
		$this->assertSame(
			[ 'mon' => [ [ '00:00', '24:00' ] ] ],
			Pickup_Schedule::normalize( [ 'mon' => [ [ '00:00', '24:00' ] ] ] )
		);
	}

	public function test_an_overnight_interval_is_kept_as_given(): void {
		// «Until that time on the next day»: a late locker, not a malformed pair.
		$this->assertSame(
			[ 'fri' => [ [ '22:00', '02:00' ] ] ],
			Pickup_Schedule::normalize( [ 'fri' => [ [ '22:00', '02:00' ] ] ] )
		);
	}

	public function test_an_interval_with_equal_start_and_end_is_dropped_not_read_as_overnight(): void {
		$this->assertNull( Pickup_Schedule::normalize( [ 'mon' => [ [ '22:00', '22:00' ] ] ] ) );
		$this->assertSame(
			[ 'mon' => [ [ '22:00', '02:00' ] ] ],
			Pickup_Schedule::normalize( [ 'mon' => [ [ '22:00', '22:00' ], [ '22:00', '02:00' ] ] ] )
		);
	}

	public function test_overnight_intervals_sort_by_start_and_deduplicate_without_breaking(): void {
		$schedule = Pickup_Schedule::normalize(
			[
				'mon' => [
					[ '22:00', '02:00' ],
					[ '09:00', '13:00' ],
					[ '22:00', '02:00' ],
					[ '13:00', '09:00' ],
				],
			]
		);

		$this->assertSame(
			[ 'mon' => [ [ '09:00', '13:00' ], [ '13:00', '09:00' ], [ '22:00', '02:00' ] ] ],
			$schedule
		);
	}

	public function test_a_day_whose_only_intervals_are_malformed_is_unknown_not_closed(): void {
		$schedule = Pickup_Schedule::normalize(
			[
				'mon' => [ [ 'bad', 'worse' ] ],
				'tue' => [],
			]
		);

		// `mon` had something we could not read: reporting it as `[]` would claim "closed".
		$this->assertSame( [ 'tue' => [] ], $schedule );
	}

	public function test_intervals_are_sorted_and_deduplicated(): void {
		$schedule = Pickup_Schedule::normalize(
			[ 'mon' => [ [ '14:00', '18:00' ], [ '09:00', '13:00' ], [ '09:00', '13:00' ] ] ]
		);

		$this->assertSame( [ 'mon' => [ [ '09:00', '13:00' ], [ '14:00', '18:00' ] ] ], $schedule );
	}

	public function test_an_associative_pair_is_accepted_by_value_only(): void {
		$schedule = Pickup_Schedule::normalize( [ 'mon' => [ [ 'from' => '09:00', 'to' => '18:00' ] ] ] );

		// Only the two VALUES are kept, re-listed — the keys carry no meaning.
		$this->assertSame( [ 'mon' => [ [ '09:00', '18:00' ] ] ], $schedule );
	}

	public function test_time_zone_accepts_identifiers_offsets_and_a_yandex_hour_offset(): void {
		$this->assertSame( 'Europe/Moscow', Pickup_Schedule::normalize_time_zone( 'Europe/Moscow' ) );
		$this->assertSame( '+03:00', Pickup_Schedule::normalize_time_zone( '+03:00' ) );
		$this->assertSame( 'UTC+3', Pickup_Schedule::normalize_time_zone( 3 ) );
		$this->assertSame( 'UTC-5', Pickup_Schedule::normalize_time_zone( -5 ) );
	}

	public function test_time_zone_rejects_everything_else(): void {
		foreach ( [ null, '', '  ', [ 'Europe/Moscow' ], 3.5, true, 99, 'Moscow time (MSK)', "UTC\n", str_repeat( 'a', 65 ) ] as $raw ) {
			$this->assertNull( Pickup_Schedule::normalize_time_zone( $raw ), var_export( $raw, true ) );
		}
	}

	public function test_consecutive_days_with_identical_hours_share_a_row(): void {
		$schedule = Pickup_Schedule::normalize(
			[
				'mon' => [ [ '09:00', '18:00' ] ],
				'tue' => [ [ '09:00', '18:00' ] ],
				'wed' => [ [ '09:00', '18:00' ] ],
				'thu' => [ [ '09:00', '18:00' ] ],
				'fri' => [ [ '09:00', '18:00' ] ],
				'sat' => [ [ '10:00', '14:00' ] ],
				'sun' => [],
			]
		);

		$this->assertSame(
			[
				[ 'days' => 'Mon–Fri', 'hours' => '09:00–18:00' ],
				[ 'days' => 'Sat', 'hours' => '10:00–14:00' ],
			],
			Pickup_Schedule::rows( $schedule )
		);
		$this->assertSame( 'Mon–Fri 09:00–18:00; Sat 10:00–14:00', Pickup_Schedule::format( $schedule ) );
	}

	public function test_an_overnight_interval_is_rendered_verbatim_and_groups_like_any_other(): void {
		$schedule = Pickup_Schedule::normalize(
			[
				'fri' => [ [ '22:00', '02:00' ] ],
				'sat' => [ [ '22:00', '02:00' ] ],
				'sun' => [ [ '10:00', '14:00' ], [ '22:00', '24:00' ] ],
			]
		);

		$this->assertSame(
			[
				[ 'days' => 'Fri–Sat', 'hours' => '22:00–02:00' ],
				[ 'days' => 'Sun', 'hours' => '10:00–14:00, 22:00–24:00' ],
			],
			Pickup_Schedule::rows( $schedule )
		);
		$this->assertSame( 'Fri–Sat 22:00–02:00; Sun 10:00–14:00, 22:00–24:00', Pickup_Schedule::format( $schedule ) );
	}

	public function test_a_closed_or_unknown_day_breaks_a_run_and_gets_no_row(): void {
		$schedule = Pickup_Schedule::normalize(
			[
				'mon' => [ [ '09:00', '18:00' ] ],
				'tue' => [],                          // closed
				'wed' => [ [ '09:00', '18:00' ] ],
				// thu unknown
				'fri' => [ [ '09:00', '18:00' ] ],
			]
		);

		$this->assertSame( 'Mon 09:00–18:00; Wed 09:00–18:00; Fri 09:00–18:00', Pickup_Schedule::format( $schedule ) );
	}

	public function test_several_intervals_in_a_day_are_comma_joined_and_only_group_when_all_match(): void {
		$schedule = Pickup_Schedule::normalize(
			[
				'mon' => [ [ '09:00', '13:00' ], [ '14:00', '18:00' ] ],
				'tue' => [ [ '09:00', '13:00' ], [ '14:00', '18:00' ] ],
				'wed' => [ [ '09:00', '13:00' ] ],
			]
		);

		$this->assertSame( 'Mon–Tue 09:00–13:00, 14:00–18:00; Wed 09:00–13:00', Pickup_Schedule::format( $schedule ) );
	}

	public function test_a_schedule_with_every_day_closed_renders_nothing(): void {
		$schedule = Pickup_Schedule::normalize( [ 'mon' => [], 'sun' => [] ] );

		$this->assertSame( [ 'mon' => [], 'sun' => [] ], $schedule );
		$this->assertSame( [], Pickup_Schedule::rows( $schedule ) );
		$this->assertSame( '', Pickup_Schedule::format( $schedule ) );
	}

	public function test_day_labels_come_from_the_site_locale_when_it_is_available(): void {
		// A stand-in for WP_Locale: weekday index 0 is SUNDAY there.
		$GLOBALS['wp_locale'] = new class() {
			public function get_weekday( int $index ): string {
				return [ 'Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота' ][ $index ];
			}

			public function get_weekday_abbrev( string $name ): string {
				return mb_substr( $name, 0, 2 );
			}
		};

		$schedule = Pickup_Schedule::normalize(
			[
				'mon' => [ [ '09:00', '18:00' ] ],
				'tue' => [ [ '09:00', '18:00' ] ],
				'sun' => [ [ '10:00', '14:00' ] ],
			]
		);

		$this->assertSame( 'По–Вт 09:00–18:00; Во 10:00–14:00', Pickup_Schedule::format( $schedule ) );
	}
}
