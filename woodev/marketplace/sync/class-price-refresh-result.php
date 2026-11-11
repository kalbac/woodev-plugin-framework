<?php
/**
 * What one `from_marketplace` price refresh did.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * The tally of a refresh, with the reason of every skipped article, and the merchant's one-line message for it.
 *
 * @since 2.0.30
 */
final class Price_Refresh_Result {
	/**
	 * Articles whose shop prices were changed.
	 *
	 * @var string[]
	 */
	private $updated = [];

	/**
	 * Articles whose shop prices already matched.
	 *
	 * @var string[]
	 */
	private $unchanged = [];

	/**
	 * Skipped articles: article => reason.
	 *
	 * @var array<string,string>
	 */
	private $skipped = [];

	/**
	 * Whether the account does not take prices from the marketplace.
	 *
	 * @var bool
	 */
	private $disabled = false;

	/**
	 * Records an updated product.
	 *
	 * @since 2.0.30
	 *
	 * @param string $offer_id Article.
	 * @return void
	 */
	public function add_updated( string $offer_id ): void {
		$this->updated[] = $offer_id;
	}

	/**
	 * Records a product that already matched.
	 *
	 * @since 2.0.30
	 *
	 * @param string $offer_id Article.
	 * @return void
	 */
	public function add_unchanged( string $offer_id ): void {
		$this->unchanged[] = $offer_id;
	}

	/**
	 * Records a skipped product and why.
	 *
	 * @since 2.0.30
	 *
	 * @param string $offer_id Article.
	 * @param string $reason   The sentence the merchant reads.
	 * @return void
	 */
	public function add_skipped( string $offer_id, string $reason ): void {
		$this->skipped[ $offer_id ] = $reason;
	}

	/**
	 * Marks the refresh as refused because the account does not take prices from the marketplace.
	 *
	 * @since 2.0.30
	 *
	 * @return void
	 */
	public function mark_disabled(): void {
		$this->disabled = true;
	}

	/**
	 * Returns the updated articles.
	 *
	 * @since 2.0.30
	 *
	 * @return string[]
	 */
	public function updated(): array {
		return $this->updated;
	}

	/**
	 * Returns the articles that already matched.
	 *
	 * @since 2.0.30
	 *
	 * @return string[]
	 */
	public function unchanged(): array {
		return $this->unchanged;
	}

	/**
	 * Returns the skipped articles with their reasons.
	 *
	 * @since 2.0.30
	 *
	 * @return array<string,string>
	 */
	public function skipped(): array {
		return $this->skipped;
	}

	/**
	 * Returns the one-line message for the merchant.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function message(): string {
		if ( $this->disabled ) {
			return __( 'В настройках аккаунта цены не берутся с маркетплейса.', 'woodev-plugin-framework' );
		}

		$line = [] === $this->updated
			? __( 'Нечего обновлять (цены совпадают)', 'woodev-plugin-framework' )
			/* translators: %d: number of products whose shop prices were updated */
			: sprintf( __( 'Цены на сайте обновлены: %d', 'woodev-plugin-framework' ), count( $this->updated ) );

		if ( [] === $this->skipped ) {
			return $line;
		}

		/* translators: 1: the summary so far, 2: number of skipped products, 3: the reason of the first skipped one */
		return sprintf( __( '%1$s; пропущено: %2$d (%3$s)', 'woodev-plugin-framework' ), $line, count( $this->skipped ), reset( $this->skipped ) );
	}
}
