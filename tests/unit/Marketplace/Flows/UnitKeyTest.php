<?php
/**
 * The key of a sellable unit.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Links\Unit_Key;

/**
 * @covers \Woodev\Framework\Marketplace\Links\Unit_Key
 */
final class UnitKeyTest extends TestCase {
	/**
	 * The pair that a naive `article|size` would merge: the one-size article `coat|M` and size M of the article `coat`.
	 *
	 * @return void
	 */
	public function test_an_article_holding_the_separator_never_meets_a_size(): void {
		$this->assertSame( 'coat\\|M|', Unit_Key::encode( 'coat|M' ) );
		$this->assertSame( 'coat|M', Unit_Key::encode( 'coat', 'M' ) );
		$this->assertNotSame( Unit_Key::encode( 'coat|M' ), Unit_Key::encode( 'coat', 'M' ) );
	}

	/**
	 * A size without a name is an empty second component.
	 *
	 * @return void
	 */
	public function test_an_unnamed_size_keeps_the_separator(): void {
		$this->assertSame( 'coat|', Unit_Key::encode( 'coat' ) );
	}

	/**
	 * Pairs that hold the two special characters, spaces and Cyrillic.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function pairs(): array {
		return [
			'separator in the article'    => [ 'a|b', 'M' ],
			'separator in the size'       => [ 'a', 'M|L' ],
			'backslash in the article'    => [ 'a\\b', 'M' ],
			'backslash in the size'       => [ 'a', 'M\\' ],
			'backslash before separator'  => [ 'a\\', '|M' ],
			'both everywhere'             => [ '\\|\\', '|\\|' ],
			'unnamed size'                => [ '12345Ejf5', '' ],
			'empty article'               => [ '', 'M' ],
			'cyrillic and outer spaces'   => [ ' Пальто ', 'сорок два' ],
			'second size of a single one' => [ 'coat', 'L' ],
		];
	}

	/**
	 * A key reads back into exactly the pair it was built from, and no two pairs share a key.
	 *
	 * @dataProvider pairs
	 * @param string $article Article.
	 * @param string $size    Size.
	 * @return void
	 */
	public function test_a_key_reads_back_into_its_pair( string $article, string $size ): void {
		$this->assertSame(
			[
				'article'   => $article,
				'unit'      => $size,
				'qualifier' => '',
			],
			Unit_Key::decode( Unit_Key::encode( $article, $size ) )
		);
	}

	/**
	 * A new card that takes a freed article and size gets a third component, so it never passes for the old link.
	 *
	 * @return void
	 */
	public function test_a_reused_article_gets_a_third_component(): void {
		$key = Unit_Key::encode( 'coat', 'M', '9912' );

		$this->assertSame( 'coat|M|9912', $key );
		$this->assertNotSame( Unit_Key::encode( 'coat', 'M' ), $key );
		$this->assertNotSame( Unit_Key::encode( 'coat', 'M|9912' ), $key );
		$this->assertSame(
			[
				'article'   => 'coat',
				'unit'      => 'M',
				'qualifier' => '9912',
			],
			Unit_Key::decode( $key )
		);
		$this->assertSame( 'a|b', Unit_Key::decode( Unit_Key::encode( 'x', '', 'a|b' ) )['qualifier'] );
	}

	/**
	 * Distinct pairs give distinct keys.
	 *
	 * @return void
	 */
	public function test_distinct_pairs_give_distinct_keys(): void {
		$keys = [];

		foreach ( $this->pairs() as list( $article, $size ) ) {
			$keys[] = Unit_Key::encode( $article, $size );
		}

		$this->assertSame( $keys, array_values( array_unique( $keys ) ) );
	}

	/**
	 * A text that is not a key reads as nothing.
	 *
	 * @return void
	 */
	public function test_a_text_that_is_no_key_reads_as_null(): void {
		foreach ( [ '', 'coat', 'a|b|', 'a|b|c|d', 'coat\\x|M', 'coat|M\\' ] as $text ) {
			$this->assertNull( Unit_Key::decode( $text ), $text );
		}
	}

	/**
	 * The link table holds 191 characters, not bytes; escaping counts.
	 *
	 * @return void
	 */
	public function test_a_key_longer_than_the_column_does_not_fit(): void {
		$this->assertTrue( Unit_Key::fits( Unit_Key::encode( str_repeat( 'я', 190 ) ) ) );
		$this->assertFalse( Unit_Key::fits( Unit_Key::encode( str_repeat( 'я', 191 ) ) ) );
		$this->assertFalse( Unit_Key::fits( Unit_Key::encode( str_repeat( '|', 96 ) ) ) );
	}
}
