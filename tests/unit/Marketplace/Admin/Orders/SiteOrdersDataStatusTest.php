<?php
/**
 * The «Статус данных» block of the orders table.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Data_Status;
use Woodev\Framework\Marketplace\Jobs\Posting_Poll_Times;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Data_Status
 * @covers \Woodev\Framework\Marketplace\Jobs\Posting_Poll_Times
 */
final class SiteOrdersDataStatusTest extends TestCase {

	/** @var array<string,mixed> */
	private $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];
		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, int $time ): string {
				return gmdate( $format, $time );
			}
		);
	}

	/**
	 * @param int|bool $next What Action Scheduler answers for the next run.
	 * @return array<int,string> The two values.
	 */
	private function values( $next ): array {
		$status = new Site_Orders_Data_Status(
			new Posting_Poll_Times( 'ozon-seller' ),
			static function () use ( $next ) {
				return $next;
			}
		);

		return array_column( $status->for_account( 7 )['items'], 'value' );
	}

	public function test_an_account_never_polled_since_the_record_began_says_so_and_the_next_run_is_its_time(): void {
		$this->assertSame( [ 'Ни разу', '20.11.2026 10:00' ], $this->values( 1795168800 ) );
	}

	public function test_the_last_poll_is_the_accounts_own_and_a_poll_with_no_schedule_says_so(): void {
		$this->options['woodev_ozon-seller_postings_polled'] = [
			7 => 1795165200,
			8 => 1795000000,
		];

		$this->assertSame( [ '20.11.2026 09:00', 'Не по расписанию' ], $this->values( false ) );
		$this->assertSame( [ '20.11.2026 09:00', 'Сейчас' ], $this->values( true ), 'Action Scheduler answers true while the poll runs.' );
	}

	public function test_the_block_names_poll_attempts_and_never_promises_that_every_change_was_read(): void {
		$block = ( new Site_Orders_Data_Status( new Posting_Poll_Times( 'ozon-seller' ), '__return_false' ) )->for_account( 7 );

		$this->assertSame( [ 'Последний опрос', 'Следующий опрос' ], array_column( $block['items'], 'label' ), 'A void catch_up() cannot say the read finished, so the label says «опрос», not «Обновлено».' );
		$this->assertStringContainsString( 'может прочитать не все изменения', $block['title'], 'The tooltip says a poll may leave the rest for the next one.' );
	}

	public function test_a_broken_option_reads_as_never(): void {
		$this->options['woodev_ozon-seller_postings_polled'] = 'garbage';

		$this->assertNull( ( new Posting_Poll_Times( 'ozon-seller' ) )->last( 7 ) );
	}
}
