<?php
/**
 * Link notes on a state badge of a table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

use Woodev\Framework\Admin\Records\Row_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Puts {@see Link_Note}s on a badge cell of the records engine: their sentences open the tooltip, and a warning or an error takes
 * over the badge's colour, so a card that waits for the merchant does not look settled. The label stays the card's state.
 *
 * @since 2.1.24
 */
final class Link_Note_Badge {
	/**
	 * Adds notes to a badge.
	 *
	 * @since 2.1.24
	 *
	 * @param array{label:string,tone:string,tooltip?:string} $badge Badge with a tone of the records engine.
	 * @param Link_Note[]                                     $notes Notes of the cards the badge stands for; none leaves it as it is.
	 * @return array{label:string,tone:string,tooltip?:string}
	 */
	public static function add( array $badge, array $notes ): array {
		if ( [] === $notes ) {
			return $badge;
		}

		$lines = [];

		foreach ( $notes as $note ) {
			// The sizes of one card often carry the same sentence: it is said once.
			$lines[ $note->text() ] = $note->text();
			$badge['tone']          = self::tone( $badge['tone'], $note );
		}

		if ( '' !== (string) ( $badge['tooltip'] ?? '' ) ) {
			$lines[] = $badge['tooltip'];
		}

		$badge['tooltip'] = mb_substr( implode( "\n", $lines ), 0, Row_Normalizer::MAX_TOOLTIP_LENGTH );

		return $badge;
	}

	/**
	 * Names the tone a badge takes with a note: the more serious of the two wins.
	 *
	 * @param string    $tone Tone of the badge.
	 * @param Link_Note $note Note.
	 * @return string
	 */
	private static function tone( string $tone, Link_Note $note ): string {
		if ( Link_Note::TONE_ERROR === $note->tone() ) {
			return 'danger';
		}

		return Link_Note::TONE_WARNING === $note->tone() && 'danger' !== $tone ? 'warning' : $tone;
	}
}
