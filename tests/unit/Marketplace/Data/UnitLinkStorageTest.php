<?php
/**
 * The barcodes of a link and the conflicts of a link run.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Links\Unit_Key;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Sellable_Units;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * @covers \Woodev\Framework\Marketplace\Data\Wpdb_Link_Store
 * @covers \Woodev\Framework\Marketplace\Data\Link_Report_Store
 * @covers \Woodev\Framework\Marketplace\Flows\Card_Linker
 */
final class UnitLinkStorageTest extends Sync_Test_Case {
	/**
	 * Links a unit card to a product.
	 *
	 * @param int      $product_id Product.
	 * @param string   $size       Size of the article `coat`.
	 * @param string[] $barcodes   Barcodes.
	 * @return string Outcome.
	 */
	private function link( int $product_id, string $size, array $barcodes ): string {
		return ( new Card_Linker( $this->links() ) )->link( $this->account_id, $product_id, 0, Fake_Sellable_Units::card( 'coat', $size, $barcodes ) );
	}

	/**
	 * The barcodes of the link of a product.
	 *
	 * @param int $product_id Product.
	 * @return string[]
	 */
	private function barcodes( int $product_id ): array {
		return $this->links()->barcodes_of( (int) $this->links()->find( $this->account_id, $product_id )->link_id() );
	}

	/**
	 * Stored barcode rows of every link.
	 *
	 * @return int
	 */
	private function stored(): int {
		return (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'link_barcodes' ) );
	}

	public function test_a_link_keeps_every_barcode_byte_for_byte_in_order(): void {
		$this->assertSame( Card_Linker::LINKED, $this->link( 10, 'M', [ '12345Ejf5', '12345EJF5', ' 00 ', '12345Ejf5', '', str_repeat( '9', 192 ) ] ) );

		$this->assertSame( [ '12345Ejf5', '12345EJF5', ' 00 ' ], $this->barcodes( 10 ), 'nothing cut, a repeat and an over-long one left out' );
	}

	public function test_a_row_without_a_card_takes_the_barcodes_with_the_card(): void {
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 10, 0, '' ) );

		$this->assertSame( Card_Linker::LINKED, $this->link( 10, 'M', [ '111', '222' ] ) );
		$this->assertSame( [ '111', '222' ], $this->barcodes( 10 ) );
	}

	public function test_a_barcode_names_one_unit_of_the_account(): void {
		$this->link( 10, 'M', [ '111' ] );
		$this->link( 11, 'L', [ '111', '333' ] );

		$this->assertSame( [ '111' ], $this->barcodes( 10 ) );
		$this->assertSame( [ '333' ], $this->barcodes( 11 ) );
	}

	public function test_the_barcodes_go_with_the_link(): void {
		$this->link( 10, 'M', [ '111' ] );
		$this->link( 11, 'L', [ '222' ] );
		$this->link( 12, 'S', [ '333' ] );

		$this->links()->detach( $this->account_id, (int) $this->links()->find( $this->account_id, 10 )->link_id() );
		$this->assertSame( 2, $this->stored() );

		$this->links()->delete_link( (int) $this->links()->find( $this->account_id, 11 )->link_id() );
		$this->assertSame( 1, $this->stored() );

		while ( $this->links()->delete_account( $this->account_id, 100 ) > 0 ) {
			continue;
		}

		$this->assertSame( 0, $this->stored() );
	}

	public function test_a_key_longer_than_the_column_is_refused_before_any_write(): void {
		$card = Fake_Sellable_Units::card( str_repeat( 'a', 191 ), '', [ '111' ] );

		$this->assertGreaterThan( Unit_Key::MAX_LENGTH, mb_strlen( $card['offer_id'] ) );
		$this->assertSame( Card_Linker::KEY_TOO_LONG, ( new Card_Linker( $this->links() ) )->link( $this->account_id, 10, 0, $card ) );
		$this->assertNull( $this->links()->find( $this->account_id, 10 ) );
		$this->assertSame( 0, $this->stored() );
	}

	public function test_a_run_over_part_of_the_shop_keeps_the_conflicts_it_did_not_look_at(): void {
		$options = [];

		Functions\when( 'get_option' )->alias(
			static function ( string $name, $fallback = false ) use ( &$options ) {
				return $options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$options ): bool {
				$options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( &$options ): bool {
				unset( $options[ $name ] );

				return true;
			}
		);

		$entry   = static function ( string $offer_id, int $product_id ): array {
			return [
				'offer_id'    => $offer_id,
				'reason'      => 'ambiguous',
				'product_ids' => [ $product_id ],
			];
		};
		$reports = new Link_Report_Store( 'woodev_test_' );

		$reports->replace_conflicts( 1, [ $entry( 'a|', 1 ), $entry( 'b|', 2 ), $entry( 'c|', 3 ) ] );
		$reports->replace_conflicts( 1, [ $entry( 'a|', 9 ) ], [ 2, 9 ] );

		$this->assertSame( [ 'a|', 'c|' ], array_column( $reports->conflicts( 1 ), 'offer_id' ) );

		$reports->replace_conflicts( 1, [] );
		$this->assertSame( [], $reports->conflicts( 1 ) );

		$reports->replace_conflicts( 1, [ $entry( 'a|', 1 ) ] );
		$reports->forget_account( 1 );
		$this->assertSame( [], $reports->conflicts( 1 ) );
	}
}
