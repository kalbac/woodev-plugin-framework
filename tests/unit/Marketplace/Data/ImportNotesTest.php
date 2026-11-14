<?php
/**
 * The import notes of the link report store.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Tests\Unit\TestCase;

/**
 * Card I1 acceptance: a note keeps its text and its technical detail as two fields, the store never reads the logging switch,
 * the notes are bounded and go with their account.
 */
final class ImportNotesTest extends TestCase {
	/**
	 * Options, name => value.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * Backs the option functions with an array.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);
	}

	/**
	 * Restores Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Text and detail are two fields: the detail is kept apart, so the caller can show or hide it later.
	 *
	 * @return void
	 */
	public function test_a_note_keeps_text_and_detail_as_two_fields(): void {
		$store = new Link_Report_Store( 'woodev_one_' );

		$store->set_import_note( 3, 'SKU-1', 'Не удалось загрузить фото.', 'RuntimeException, HTTP 503' );

		$this->assertSame(
			[
				[
					'offer_id' => 'SKU-1',
					'text'     => 'Не удалось загрузить фото.',
					'detail'   => 'RuntimeException, HTTP 503',
				],
			],
			$store->import_notes( 3 )
		);
	}

	/**
	 * A later note for the same article replaces the earlier one and moves to the newest end.
	 *
	 * @return void
	 */
	public function test_a_later_note_for_an_article_replaces_it_and_moves_it_last(): void {
		$store = new Link_Report_Store( 'woodev_one_' );

		$store->set_import_note( 3, 'SKU-1', 'first' );
		$store->set_import_note( 3, 'SKU-2', 'second' );
		$store->set_import_note( 3, 'SKU-1', 'again' );

		$notes = $store->import_notes( 3 );
		$this->assertSame( [ 'SKU-2', 'SKU-1' ], array_column( $notes, 'offer_id' ) );
		$this->assertSame( 'again', $notes[1]['text'] );
		$this->assertSame( '', $notes[1]['detail'] );
	}

	/**
	 * Notes are bounded: past the limit the oldest is dropped.
	 *
	 * @return void
	 */
	public function test_notes_are_bounded_and_the_oldest_is_dropped(): void {
		$store = new Link_Report_Store( 'woodev_one_' );

		for ( $i = 1; $i <= Link_Report_Store::MAX_ENTRIES + 3; $i++ ) {
			$store->set_import_note( 3, 'SKU-' . $i, 'note' );
		}

		$notes = $store->import_notes( 3 );
		$this->assertCount( Link_Report_Store::MAX_ENTRIES, $notes );
		$this->assertSame( 'SKU-4', $notes[0]['offer_id'] );
		$this->assertSame( 'SKU-' . ( Link_Report_Store::MAX_ENTRIES + 3 ), $notes[ count( $notes ) - 1 ]['offer_id'] );
	}

	/**
	 * Clearing an article removes only its note, and the last one removes the option.
	 *
	 * @return void
	 */
	public function test_clearing_removes_only_that_article(): void {
		$store = new Link_Report_Store( 'woodev_one_' );
		$store->set_import_note( 3, 'SKU-1', 'a' );
		$store->set_import_note( 3, 'SKU-2', 'b' );

		$store->clear_import_note( 3, 'SKU-1' );
		$this->assertSame( [ 'SKU-2' ], array_column( $store->import_notes( 3 ), 'offer_id' ) );

		$store->clear_import_note( 3, 'SKU-2' );
		$this->assertSame( [], $store->import_notes( 3 ) );
		$this->assertArrayNotHasKey( 'woodev_one_import_notes_3', $this->options );
	}

	/**
	 * Notes belong to the account: another account reads none, and forgetting an account removes its notes.
	 *
	 * @return void
	 */
	public function test_notes_are_per_account_and_go_with_it(): void {
		$store = new Link_Report_Store( 'woodev_one_' );
		$store->set_import_note( 3, 'SKU-1', 'a' );

		$this->assertSame( [], $store->import_notes( 4 ) );

		$store->forget_account( 3 );
		$this->assertSame( [], $store->import_notes( 3 ) );
	}

	/**
	 * The store never reads the logging switch: the detail is shown by the caller at render time, so a tail glued on here would
	 * be stale after the merchant turns the switch on or off.
	 *
	 * @return void
	 */
	public function test_the_store_never_reads_the_logging_switch(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/woodev/marketplace/data/class-link-report-store.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test read.

		$this->assertStringNotContainsString( 'Logging', $source );
		$this->assertStringNotContainsString( 'hint(', $source );
	}
}
