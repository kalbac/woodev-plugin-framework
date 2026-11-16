<?php
/**
 * The bounded report of a bulk run.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Report;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Bulk_Run_Report
 */
final class BulkRunReportTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string   $reason   Reason.
	 * @param int      $count    Count.
	 * @param string   $kind     Kind.
	 * @param string[] $examples Examples.
	 * @return array<string,mixed>
	 */
	private function group( string $reason, int $count = 1, string $kind = 'skipped', array $examples = [ 'Товар' ] ): array {
		return [
			'kind'      => $kind,
			'reason'    => $reason,
			'count'     => $count,
			'fix_label' => '',
			'fix_url'   => '',
			'examples'  => $examples,
		];
	}

	public function test_the_same_reason_in_two_parts_is_one_group_with_the_counts_added(): void {
		$report = Bulk_Run_Report::merge( [], [ $this->group( 'нет цены', 3, 'skipped', [ 'A', 'B' ] ) ] );
		$report = Bulk_Run_Report::merge( $report, [ $this->group( 'нет цены', 4, 'skipped', [ 'C', 'D', 'E', 'F' ] ) ] );

		$this->assertCount( 1, $report );
		$this->assertSame( 7, $report[0]['count'] );
		$this->assertSame( [ 'A', 'B', 'C', 'D', 'E' ], $report[0]['examples'], 'At most five examples.' );
	}

	public function test_a_skipped_and_a_failed_group_with_one_reason_stay_apart(): void {
		$report = Bulk_Run_Report::merge( [], [ $this->group( 'занято', 2, 'skipped' ), $this->group( 'занято', 5, 'failed' ) ] );

		$this->assertCount( 2, $report );
	}

	public function test_sixty_distinct_reasons_make_49_groups_and_one_other_group_and_the_counts_are_conserved(): void {
		$report = [];
		$sum    = 0;

		for ( $part = 0; $part < 3; $part++ ) {
			$groups = [];

			for ( $i = 0; $i < 20; $i++ ) {
				$count    = $part + $i + 1;
				$sum     += $count;
				$groups[] = $this->group( 'причина ' . ( $part * 20 + $i ), $count );
			}

			$report = Bulk_Run_Report::merge( $report, $groups );
		}

		$this->assertCount( 50, $report, '49 reasons and «Другие причины».' );
		$this->assertSame( Bulk_Run_Report::overflow_reason(), $report[49]['reason'] );
		$this->assertSame( $sum, Bulk_Run_Report::total( $report ), 'Nothing is lost in the merge.' );

		$merged = 0;

		for ( $i = 49; $i < 60; $i++ ) {
			$merged += (int) floor( $i / 20 ) + ( $i % 20 ) + 1;
		}

		$this->assertSame( $merged, $report[49]['count'] );
	}

	public function test_a_reason_that_is_already_kept_still_adds_up_after_the_overflow_began(): void {
		$groups = [];

		for ( $i = 0; $i < 60; $i++ ) {
			$groups[] = $this->group( 'причина ' . $i );
		}

		$report = Bulk_Run_Report::merge( [], $groups );
		$report = Bulk_Run_Report::merge( $report, [ $this->group( 'причина 0', 9 ), $this->group( 'причина 59', 4 ) ] );

		$this->assertSame( 10, $report[0]['count'], 'A kept reason is found again.' );
		$this->assertSame( 11 + 4, $report[49]['count'], 'A late one joins the overflow.' );
		$this->assertSame( 60 + 13, Bulk_Run_Report::total( $report ) );
	}

	public function test_the_overflow_is_a_failure_when_any_merged_reason_was_one(): void {
		$groups = [];

		for ( $i = 0; $i < 49; $i++ ) {
			$groups[] = $this->group( 'причина ' . $i );
		}

		$report = Bulk_Run_Report::merge( [], array_merge( $groups, [ $this->group( 'последняя', 2, 'failed' ) ] ) );

		$this->assertSame( 'failed', $report[49]['kind'] );
	}

	public function test_a_fifty_thousand_offer_failing_run_keeps_the_stored_report_under_64_kb(): void {
		$long    = str_repeat( 'я', 400 );
		$example = str_repeat( 'ю', 200 );
		$report  = [];

		// Fifty thousand failing offers, every reason as long as it can be and every group full of long examples.
		for ( $offer = 0; $offer < 50000; $offer += 500 ) {
			$groups = [];

			for ( $i = 0; $i < 50; $i++ ) {
				$groups[] = [
					'kind'      => 0 === $i % 2 ? 'failed' : 'skipped',
					'reason'    => $long . ( ( $offer / 500 + $i ) % 80 ),
					'count'     => 10,
					'fix_label' => 'Исправить в «Сопоставлении»',
					'fix_url'   => 'https://example.test/wp-admin/admin.php?page=ozon-mappings',
					'examples'  => [ $example, $example, $example, $example, $example ],
				];
			}

			$report = Bulk_Run_Report::merge( $report, $groups );
		}

		$this->assertLessThanOrEqual( 50, count( $report ), 'It fits what the engine\'s answer carries.' );
		$this->assertSame( 50000 / 500 * 50 * 10, Bulk_Run_Report::total( $report ), 'The counts of the report add up to the offers that were left out.' );
		$this->assertLessThan( Bulk_Run_Report::MAX_BYTES, strlen( (string) json_encode( $report, JSON_UNESCAPED_UNICODE ) ) );
	}
}
