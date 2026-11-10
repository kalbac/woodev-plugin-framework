<?php
/**
 * How alike two seller articles are.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Offer_Id_Likeness;

/**
 * Spec 5.2: case and the look-alikes `О/0`, `С/C`, `Р/P`, `А/A`, `Е/E` make a near match; anything else does not.
 */
final class OfferIdLikenessTest extends TestCase {
	/**
	 * The same text is `same`, not a near match.
	 *
	 * @return void
	 */
	public function test_identical_articles_are_the_same(): void {
		self::assertSame( Offer_Id_Likeness::SAME, Offer_Id_Likeness::relation( 'AB-12', 'AB-12' ) );
	}

	/**
	 * A different case is reported as `case`.
	 *
	 * @return void
	 */
	public function test_a_different_case_is_a_case_difference(): void {
		self::assertSame( Offer_Id_Likeness::CASE, Offer_Id_Likeness::relation( 'Abc-1', 'ABC-1' ) );
		self::assertSame( Offer_Id_Likeness::CASE, Offer_Id_Likeness::relation( 'Блокнот-1', 'БЛОКНОТ-1' ) );
	}

	/**
	 * Each pair the spec names is found, in both directions.
	 *
	 * @dataProvider look_alikes
	 *
	 * @param string $latin Article with Latin letters.
	 * @param string $other The same article with a look-alike from the other alphabet.
	 * @return void
	 */
	public function test_look_alike_letters_are_found( string $latin, string $other ): void {
		self::assertSame( Offer_Id_Likeness::LOOK_ALIKE, Offer_Id_Likeness::relation( $latin, $other ) );
		self::assertSame( Offer_Id_Likeness::LOOK_ALIKE, Offer_Id_Likeness::relation( $other, $latin ) );
	}

	/**
	 * The look-alike pairs of spec 5.2 and the `O` / `0` of ticket 16257.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function look_alikes(): array {
		return array(
			'cyrillic O'       => array( 'NOTE-1', 'NОTE-1' ),
			'cyrillic C'       => array( 'CAP-7', 'САP-7' ),
			'cyrillic P'       => array( 'PEN-2', 'РEN-2' ),
			'cyrillic A'       => array( 'AX-3', 'АX-3' ),
			'cyrillic E'       => array( 'EL-4', 'ЕL-4' ),
			'Latin O vs zero'  => array( 'WOODEV-100', 'W00DEV-1OO' ),
			'case and letters' => array( 'Cap-7', 'САР-7' ),
		);
	}

	/**
	 * Unrelated articles, and an empty one, are not alike.
	 *
	 * @return void
	 */
	public function test_other_differences_are_not_alike(): void {
		self::assertSame( Offer_Id_Likeness::DIFFERENT, Offer_Id_Likeness::relation( 'AB-12', 'AB-13' ) );
		self::assertSame( Offer_Id_Likeness::DIFFERENT, Offer_Id_Likeness::relation( 'AB-12', 'AB-12-X' ) );
		self::assertSame( Offer_Id_Likeness::DIFFERENT, Offer_Id_Likeness::relation( '', 'AB' ) );
	}
}
