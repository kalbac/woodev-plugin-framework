<?php
/**
 * How alike two seller articles are.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 5.2: linking by article is an exact match, plus a **near-match report** for the pairs a merchant typed differently without
 * meaning to: a different case, or a letter from the other alphabet that looks the same (Cyrillic `О` for Latin `O`, `С` for `C`,
 * `Р` for `P`, `А` for `A`, `Е` for `E`, and a letter `O` for the digit zero `[#16257]`). Such a pair is never linked on its own;
 * the merchant confirms it.
 *
 * Folding is only a way to find candidates: two articles are alike when their folded forms are equal.
 *
 * @since 2.0.29
 */
final class Offer_Id_Likeness {
	/**
	 * The articles are the same text.
	 */
	public const SAME = 'same';

	/**
	 * The articles differ only in letter case.
	 */
	public const CASE = 'case';

	/**
	 * The articles differ in letters that look the same (alphabet look-alikes, or `O` and `0`), possibly also in case.
	 */
	public const LOOK_ALIKE = 'look_alike';

	/**
	 * Not alike.
	 */
	public const DIFFERENT = '';

	/**
	 * Letters of the other alphabet that look like a Latin one, in both cases, mapped to the lower-case Latin letter.
	 *
	 * @var array<string,string>
	 */
	private const CYRILLIC_LOOK_ALIKES = [
		'А' => 'a',
		'а' => 'a',
		'В' => 'b',
		'в' => 'b',
		'Е' => 'e',
		'е' => 'e',
		'К' => 'k',
		'к' => 'k',
		'М' => 'm',
		'м' => 'm',
		'Н' => 'h',
		'н' => 'h',
		'О' => 'o',
		'о' => 'o',
		'Р' => 'p',
		'р' => 'p',
		'С' => 'c',
		'с' => 'c',
		'Т' => 't',
		'т' => 't',
		'У' => 'y',
		'у' => 'y',
		'Х' => 'x',
		'х' => 'x',
	];

	/**
	 * Says how alike two articles are.
	 *
	 * @since 2.0.29
	 *
	 * @param string $first  Article.
	 * @param string $second Article.
	 * @return string One of the class constants.
	 */
	public static function relation( string $first, string $second ): string {
		if ( $first === $second ) {
			return self::SAME;
		}

		if ( self::lower( $first ) === self::lower( $second ) ) {
			return self::CASE;
		}

		return '' !== $first && self::fold( $first ) === self::fold( $second ) ? self::LOOK_ALIKE : self::DIFFERENT;
	}

	/**
	 * Reduces an article to the form in which look-alikes are equal: lower case, look-alike letters of the other alphabet written
	 * as Latin, the letter `o` written as the digit zero.
	 *
	 * @since 2.0.29
	 *
	 * @param string $article Article.
	 * @return string
	 */
	public static function fold( string $article ): string {
		return str_replace( 'o', '0', self::lower( strtr( $article, self::CYRILLIC_LOOK_ALIKES ) ) );
	}

	/**
	 * Lower-cases text, Cyrillic included when mbstring is there.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}
}
