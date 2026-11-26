<?php
/**
 * Which barcode may go into WooCommerce's GTIN field.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Gtin;

/**
 * @covers \Woodev\Framework\Marketplace\Catalog\Gtin
 */
final class GtinTest extends TestCase {
	/**
	 * Barcodes and whether each is a GTIN.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function barcodes(): array {
		return [
			'GTIN-8'                        => [ '96385074', true ],
			'GTIN-12'                       => [ '036000291452', true ],
			'GTIN-13'                       => [ '4006381333931', true ],
			'GTIN-14'                       => [ '10012345678902', true ],
			'wrong check digit'             => [ '4006381333932', false ],
			'letters'                       => [ '12345Ejf5', false ],
			'hyphenated'                    => [ '400-638133393-1', false ],
			'length 9'                      => [ '123456784', false ],
			'trailing newline'              => [ "4006381333931\n", false ],
			'spaces'                        => [ ' 4006381333931', false ],
			'empty'                         => [ '', false ],
			'a marketplace code, 206…'      => [ '2060000000002', false ],
			'a shop code, 20…'              => [ '2000000000008', false ],
			'restricted, 02…'               => [ '0200000000004', false ],
			'restricted, 04…'               => [ '0400000000008', false ],
			'restricted GTIN-12, 2…'        => [ '200000000004', false ],
			'restricted GTIN-12, 4…'        => [ '400000000008', false ],
			'restricted GTIN-14, 2… inside' => [ '12000000000005', false ],
			'restricted, 0000000…'          => [ '0000000123457', false ],
			'restricted GTIN-12, 000000…'   => [ '000000123457', false ],
			'restricted GTIN-14, 0000000…'  => [ '10000000123454', false ],
			'next to the seven zeros'       => [ '0000001123456', true ],
			'restricted GTIN-8, 0…'         => [ '01234565', false ],
			'restricted GTIN-8, 2…'         => [ '20000004', false ],
		];
	}

	/**
	 * Only digits of a GTIN length with a correct check digit.
	 *
	 * @dataProvider barcodes
	 * @param string $barcode Barcode.
	 * @param bool   $valid   Expected.
	 * @return void
	 */
	public function test_only_a_real_gtin_passes( string $barcode, bool $valid ): void {
		$this->assertSame( $valid, Gtin::is_valid( $barcode ) );
	}

	/**
	 * The first GTIN of a list wins; none gives ''.
	 *
	 * @return void
	 */
	public function test_the_first_gtin_of_a_list(): void {
		$this->assertSame( '96385074', Gtin::first( [ '12345Ejf5', 7, '96385074', '4006381333931' ] ) );
		$this->assertSame( '', Gtin::first( [ '12345Ejf5', 'A-1' ] ) );
		$this->assertSame( '', Gtin::first( [] ) );
	}
}
