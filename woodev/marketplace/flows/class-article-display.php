<?php
/**
 * The article as a merchant reads it.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that turns a link's `offer_id` into the text of a screen or a message: as it is for a marketplace whose article is
 * the `offer_id`, and the adapter's own words for one that sells units ({@see Sellable_Units}) — which follow the card as the
 * stored list holds it now, so an article renamed on the marketplace is shown renamed although the key is the old one.
 *
 * @since 2.1.23
 */
final class Article_Display {
	/**
	 * The adapter's unit rules, or null.
	 *
	 * @var Sellable_Units|null
	 */
	private $units;

	/**
	 * The stored cards list, or null.
	 *
	 * @var Wpdb_Card_Store|null
	 */
	private $cards;

	/**
	 * Texts already worked out from the stored list, by `{account id}:{offer id}`.
	 *
	 * @var array<string,string>
	 */
	private $looked_up = [];

	/**
	 * Creates the display.
	 *
	 * @since 2.1.23
	 *
	 * @param Sellable_Units|null  $units The adapter, when it sells units.
	 * @param Wpdb_Card_Store|null $cards The stored cards list {@see self::of_link()} reads.
	 */
	public function __construct( ?Sellable_Units $units = null, ?Wpdb_Card_Store $cards = null ) {
		$this->units = $units;
		$this->cards = $cards;
	}

	/**
	 * Tells whether the marketplace sells units.
	 *
	 * @since 2.1.23
	 *
	 * @return bool
	 */
	public function sells_units(): bool {
		return null !== $this->units;
	}

	/**
	 * Returns the text of an article whose card the caller holds, or of the key alone.
	 *
	 * @since 2.1.23
	 *
	 * @param string              $offer_id The link's article.
	 * @param array<string,mixed> $card     The card, [] when there is none at hand.
	 * @return string '' for no article.
	 */
	public function text( string $offer_id, array $card = [] ): string {
		if ( null === $this->units || '' === $offer_id ) {
			return $offer_id;
		}

		return $this->units->shown_article( $offer_id, $card )->text();
	}

	/**
	 * Returns the text of a link's article, looking its card up in the stored list. A local read, one per article.
	 *
	 * @since 2.1.23
	 *
	 * @param int    $account_id Account id.
	 * @param string $offer_id   The link's article.
	 * @return string
	 */
	public function of_link( int $account_id, string $offer_id ): string {
		if ( null === $this->units || null === $this->cards || '' === $offer_id ) {
			return $this->text( $offer_id );
		}

		$name = $account_id . ':' . $offer_id;

		if ( ! isset( $this->looked_up[ $name ] ) ) {
			$this->looked_up[ $name ] = $this->text( $offer_id, (array) $this->cards->find( $account_id, Wpdb_Card_Store::key( $offer_id ) ) );
		}

		return $this->looked_up[ $name ];
	}
}
