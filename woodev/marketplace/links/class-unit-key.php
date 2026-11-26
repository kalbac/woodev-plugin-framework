<?php
/**
 * The key of a sellable unit that is a part of a card.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Links;

defined( 'ABSPATH' ) || exit;

/**
 * A marketplace may sell one size of a card as the unit: the card has one seller article, the unit is that article **and** the size.
 * A link is one unit, and its `offer_id` is the unit key an adapter builds here:
 *
 * `escaped article` `|` `escaped unit name` — **always both components and always the separator**.
 *
 * `\` is written `\\` and `|` is written `\|` in every component, so an unescaped `|` is always a separator and a key
 * decodes back to exactly the components it was built from. A unit without a name is the empty second component (`coat|`), so the article `coat|M` of a
 * one-unit card (`coat\|M|`) and size `M` of the article `coat` (`coat|M`) never meet.
 *
 * The key is built when the link is made and is **frozen** from then on: it never depends on how many units the card has, and a
 * size or an article renamed on the marketplace keeps its link and its old key — the adapter finds such a card by its remote
 * identifiers and answers with the stored key. When a **new** card then takes a freed article and size, its key would be the old
 * link's: the adapter appends a third component that tells them apart (the unit's own identifier), `article|size|qualifier`.
 *
 * @since 2.1.22
 */
final class Unit_Key {
	/**
	 * Longest key the link table holds, in characters (`links.offer_id`). A longer one is refused before any write.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 191;

	private const SEPARATOR = '|';
	private const ESCAPE    = '\\';

	/**
	 * Builds the key of a unit.
	 *
	 * @since 2.1.22
	 *
	 * @param string $article   The card's seller article, byte for byte.
	 * @param string $unit      What names the unit inside the card (a size); '' for a unit without a name.
	 * @param string $qualifier What tells the unit from another that holds the same pair (a reused article); '' for none.
	 * @return string The key; it may be longer than {@see self::MAX_LENGTH}, which {@see self::fits()} tells.
	 */
	public static function encode( string $article, string $unit = '', string $qualifier = '' ): string {
		$key = self::escape( $article ) . self::SEPARATOR . self::escape( $unit );

		return '' === $qualifier ? $key : $key . self::SEPARATOR . self::escape( $qualifier );
	}

	/**
	 * Reads a key back into its components.
	 *
	 * @since 2.1.22
	 *
	 * @param string $key Unit key.
	 * @return array{article:string,unit:string,qualifier:string}|null Null for a text that is not a key: no separator, more than two
	 *                                                                of them, an empty third component, or a stray `\`.
	 */
	public static function decode( string $key ): ?array {
		$parts  = [ '' ];
		$length = strlen( $key );

		// Both special characters are single bytes and never occur inside a multi-byte UTF-8 character, so a byte walk is safe.
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $key[ $index ];

			if ( self::SEPARATOR === $char ) {
				$parts[] = '';
				continue;
			}

			if ( self::ESCAPE === $char ) {
				$char = $key[ $index + 1 ] ?? '';

				if ( self::ESCAPE !== $char && self::SEPARATOR !== $char ) {
					return null;
				}

				++$index;
			}

			$parts[ count( $parts ) - 1 ] .= $char;
		}

		if ( count( $parts ) < 2 || count( $parts ) > 3 || ( isset( $parts[2] ) && '' === $parts[2] ) ) {
			return null;
		}

		return [
			'article'   => $parts[0],
			'unit'      => $parts[1],
			'qualifier' => $parts[2] ?? '',
		];
	}

	/**
	 * Tells whether a key (or any article) fits the link table.
	 *
	 * @since 2.1.22
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function fits( string $key ): bool {
		return mb_strlen( $key, 'UTF-8' ) <= self::MAX_LENGTH;
	}

	/**
	 * Escapes one component.
	 *
	 * @param string $text Component.
	 * @return string
	 */
	private static function escape( string $text ): string {
		return str_replace(
			[ self::ESCAPE, self::SEPARATOR ],
			[ self::ESCAPE . self::ESCAPE, self::ESCAPE . self::SEPARATOR ],
			$text
		);
	}
}
