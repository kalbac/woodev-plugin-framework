<?php
/**
 * What «Артикул» shows for a sellable unit.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * The key of a unit ({@see \Woodev\Framework\Marketplace\Links\Unit_Key}) is never shown to the merchant: the adapter says which
 * article the card has and what tells the unit apart inside it ({@see Sellable_Units::shown_article()}).
 *
 * @since 2.1.23
 */
final class Shown_Article {
	/**
	 * The article of the card.
	 *
	 * @var string
	 */
	private $article;

	/**
	 * What tells the unit apart (a size), '' for a unit without a name.
	 *
	 * @var string
	 */
	private $unit;

	/**
	 * Creates the value.
	 *
	 * @since 2.1.23
	 *
	 * @param string $article The article of the card.
	 * @param string $unit    What tells the unit apart inside it, '' for none.
	 */
	public function __construct( string $article, string $unit = '' ) {
		$this->article = $article;
		$this->unit    = $unit;
	}

	/**
	 * Returns the article of the card.
	 *
	 * @since 2.1.23
	 *
	 * @return string
	 */
	public function article(): string {
		return $this->article;
	}

	/**
	 * Returns what tells the unit apart.
	 *
	 * @since 2.1.23
	 *
	 * @return string '' for a unit without a name.
	 */
	public function unit(): string {
		return $this->unit;
	}

	/**
	 * Returns the article with the unit beside it.
	 *
	 * @since 2.1.23
	 *
	 * @return string e.g. `coat · M`.
	 */
	public function text(): string {
		return '' === $this->unit ? $this->article : $this->article . ' · ' . $this->unit;
	}
}
