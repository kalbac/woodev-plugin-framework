<?php
/**
 * Whether a card's detail is still the card the list showed.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Link_Record;

defined( 'ABSPATH' ) || exit;

/**
 * The cabinet is read in two steps (the list, then the details), and a card can be replaced in between by another one that reuses
 * the same article. The article alone cannot tell them apart, so the framework — not each adapter — requires the stable remote
 * identifiers to agree: every identifier the summary and the confirmed card both carry (the adapter's
 * {@see Card_Lifecycle::card_id_name()} at least) must be equal, and there must be one. Identifiers that appear only in the detail
 * (those that arrive late) are not compared.
 *
 * @since 2.0.30
 */
final class Card_Identity {
	/**
	 * Tells whether the confirmed card is the card of the summary.
	 *
	 * @since 2.0.30
	 *
	 * @param array<string,mixed> $summary   Card of {@see Link_Resolver::catalogue()}.
	 * @param array<string,mixed> $confirmed Card {@see Link_Resolver::confirmed()} answered.
	 * @return bool
	 */
	public static function same( array $summary, array $confirmed ): bool {
		$known = Link_Record::clean_ids( (array) ( $summary['remote_ids'] ?? [] ) );
		$now   = Link_Record::clean_ids( (array) ( $confirmed['remote_ids'] ?? [] ) );
		$both  = array_intersect_key( $known, $now );

		if ( [] === $both ) {
			return false;
		}

		foreach ( $both as $name => $value ) {
			if ( (string) $value !== (string) $now[ $name ] ) {
				return false;
			}
		}

		return true;
	}
}
