<?php
/**
 * Prices from the marketplace into WooCommerce.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Pair;

defined( 'ABSPATH' ) || exit;

/**
 * The `from_marketplace` direction: read the prices the marketplace holds for some articles ({@see Price_Reader}), undo the
 * rule on them ({@see Price_Context::invert()} — division for a percent markup, never subtraction, then the currency rate
 * divides), and write the result to the linked product with `set_regular_price()` / `set_sale_price()` (sale cleared when the
 * marketplace's old price is 0 or not above the price) and `save()`, inside {@see Price_Refresh_Guard} so that save does not
 * echo back.
 *
 * Only `created` cards with price sync on, of an account whose direction is `from_marketplace`, are touched. A link whose
 * selling price is an absolute override, a marketplace currency that differs from the cabinet's, a missing price, an unreadable
 * rule: skipped, with the reason in the result. A product whose prices already equal the inverted ones is not saved.
 *
 * Who calls it: the handler of the marketplace's «card changed» notification and the daily reconcile, through the
 * `refresh_prices` job.
 *
 * @since 2.1.0
 */
final class Price_Refresher {
	/**
	 * Reader.
	 *
	 * @var Price_Reader
	 */
	private $reader;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * The export side's price answers (mapping, profile, rule, rate).
	 *
	 * @var Price_Context
	 */
	private $contexts;

	/**
	 * Creates the refresher.
	 *
	 * @since 2.1.0
	 *
	 * @param Price_Reader    $reader   Reader.
	 * @param Wpdb_Link_Store $links    Links.
	 * @param Price_Context   $contexts The export side's price answers.
	 */
	public function __construct( Price_Reader $reader, Wpdb_Link_Store $links, Price_Context $contexts ) {
		$this->reader   = $reader;
		$this->links    = $links;
		$this->contexts = $contexts;
	}

	/**
	 * Refreshes the shop prices of articles from the marketplace.
	 *
	 * @since 2.1.0
	 *
	 * @param Account  $account   Account.
	 * @param string[] $offer_ids Articles.
	 * @return Price_Refresh_Result
	 * @throws \UnexpectedValueException When the marketplace answers with an article that was not asked for.
	 * @throws \Throwable When the read fails; the job classifies it.
	 */
	public function refresh( Account $account, array $offer_ids ): Price_Refresh_Result {
		$result = new Price_Refresh_Result();

		if ( Wpdb_Account_Store::PRICE_FROM_MARKETPLACE !== (string) $account->setting( 'price_direction', Wpdb_Account_Store::PRICE_TO_MARKETPLACE ) ) {
			$result->mark_disabled();

			return $result;
		}

		$links = $this->eligible( $account, $offer_ids, $result );
		$read  = $this->reader->read( $account, array_map( 'strval', array_keys( $links ) ) );

		foreach ( $links as $offer_id => $link ) {
			if ( ! isset( $read[ $offer_id ] ) ) {
				$result->add_skipped( (string) $offer_id, __( 'Маркетплейс не вернул цену этого товара.', 'woodev-plugin-framework' ) );
				continue;
			}

			$this->apply( $account, $link, $read[ $offer_id ], $result );
		}

		return $result;
	}

	/**
	 * Finds the links that may be refreshed, recording why the others cannot.
	 *
	 * @param Account              $account   Account.
	 * @param string[]             $offer_ids Articles.
	 * @param Price_Refresh_Result $result    Result.
	 * @return array<string,Link_Record> Link by article.
	 */
	private function eligible( Account $account, array $offer_ids, Price_Refresh_Result $result ): array {
		$links = [];

		foreach ( array_unique( array_map( 'strval', $offer_ids ) ) as $offer_id ) {
			$link = $this->links->find_by_offer_id( $account->id(), $offer_id );

			if ( null === $link || ! $link->is_offer() ) {
				$result->add_skipped( $offer_id, __( 'Товар не связан с карточкой маркетплейса.', 'woodev-plugin-framework' ) );
			} elseif ( ! $link->sync_price() ) {
				$result->add_skipped( $offer_id, __( 'Для товара выключена синхронизация цен.', 'woodev-plugin-framework' ) );
			} elseif ( Link_Record::STATE_CREATED !== $link->card_state() ) {
				$result->add_skipped( $offer_id, __( 'Карточка на маркетплейсе ещё не создана.', 'woodev-plugin-framework' ) );
			} else {
				$links[ $offer_id ] = $link;
			}
		}

		return $links;
	}

	/**
	 * Writes one article's marketplace price into its product.
	 *
	 * @param Account              $account Account.
	 * @param Link_Record          $link    Link.
	 * @param Price_Pair           $remote  The marketplace's prices.
	 * @param Price_Refresh_Result $result  Result.
	 * @return void
	 */
	private function apply( Account $account, Link_Record $link, Price_Pair $remote, Price_Refresh_Result $result ): void {
		$offer_id = (string) $link->offer_id();
		$product  = $this->contexts->product( $link->wc_product_id() );

		if ( null === $product ) {
			$result->add_skipped( $offer_id, __( 'Товар удалён с сайта.', 'woodev-plugin-framework' ) );

			return;
		}

		$refusal  = null;
		$restored = $this->restored( $account, $product, $link, $remote, $refusal );

		if ( null === $restored ) {
			$result->add_skipped( $offer_id, (string) $refusal );

			return;
		}

		if ( $this->write( $account->adapter_id(), $product, $restored ) ) {
			$result->add_updated( $offer_id );
		} else {
			$result->add_unchanged( $offer_id );
		}
	}

	/**
	 * Works the shop prices out of the marketplace's, or says why it cannot.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product or variation.
	 * @param Link_Record $link    Link.
	 * @param Price_Pair  $remote  The marketplace's prices.
	 * @param string|null $refusal Receives the sentence the merchant reads when null is returned.
	 * @return array{regular:Money|null,sale:Money|null}|null
	 */
	private function restored( Account $account, \WC_Product $product, Link_Record $link, Price_Pair $remote, ?string &$refusal ): ?array {
		try {
			$terms = $this->contexts->price_terms( $account, $product );

			if ( $terms['currency']->is_unresolved() ) {
				$refusal = __( 'Валюта магазина и валюта кабинета разные, а правила валюты в профиле нет.', 'woodev-plugin-framework' );

				return null;
			}

			if ( $remote->price()->currency() !== $terms['currency']->target() ) {
				/* translators: 1: currency the marketplace keeps the price in, 2: currency of the cabinet */
				$refusal = sprintf( __( 'Маркетплейс хранит цену в другой валюте (%1$s), чем кабинет (%2$s).', 'woodev-plugin-framework' ), $remote->price()->currency(), $terms['currency']->target() );

				return null;
			}

			$restored = $this->contexts->invert( $remote->price(), $remote->old_price(), $terms['rule'], $link->price_rule_override(), $terms['currency'] );
		} catch ( \InvalidArgumentException $e ) {
			/* translators: %s: technical reason */
			$refusal = sprintf( __( 'Правило цены, курс или своя цена не читаются: %s', 'woodev-plugin-framework' ), $e->getMessage() );

			return null;
		}

		if ( null === $restored ) {
			$refusal = __( 'Цена для маркетплейса у товара задана отдельно (своя цена или поле товара) — обратный расчёт невозможен.', 'woodev-plugin-framework' );

			return null;
		}

		foreach ( array_filter( [ $restored['regular'], $restored['sale'] ] ) as $price ) {
			if ( ! $price->is_positive() ) {
				$refusal = __( 'Цена маркетплейса меньше наценки — цена сайта получилась бы нулевой.', 'woodev-plugin-framework' );

				return null;
			}
		}

		return $restored;
	}

	/**
	 * Saves the prices into the product when they differ, under the loop guard of the marketplace the prices came from: its
	 * own price recorder ignores the save, another marketplace's recorder sees it and pushes the new price on.
	 *
	 * The marketplace is the master: its sale replaces the shop's. A sale on the marketplace is only mirrored when the product
	 * also has no sale schedule — a future start date would keep selling at the regular price, an end date would later end the
	 * mirrored sale — so a schedule counts as a difference even when the amounts match, and is cleared with the write.
	 *
	 * @param string                                    $marketplace_id The adapter's id of the account the prices came from.
	 * @param \WC_Product                               $product        Product or variation.
	 * @param array{regular:Money|null,sale:Money|null} $restored       Inverted prices; a null regular price is left alone.
	 * @return bool True when the product was saved.
	 */
	private function write( string $marketplace_id, \WC_Product $product, array $restored ): bool {
		$known = $restored['regular'] ?? $restored['sale'];

		if ( null === $known ) {
			return false; // Nothing was restored: nothing to compare and nothing to write.
		}

		$currency = $known->currency();
		$regular  = null === $restored['regular'] ? null : $restored['regular']->to_string();
		$sale     = null === $restored['sale'] ? '' : $restored['sale']->to_string();
		$same     = ( null === $regular || self::same( (string) $product->get_regular_price(), $regular, $currency ) )
			&& self::same( (string) $product->get_sale_price(), $sale, $currency )
			&& ( '' === $sale || ( empty( $product->get_date_on_sale_from() ) && empty( $product->get_date_on_sale_to() ) ) );

		if ( $same ) {
			return false;
		}

		Price_Refresh_Guard::run(
			$marketplace_id,
			static function () use ( $product, $regular, $sale ): void {
				if ( null !== $regular ) {
					$product->set_regular_price( $regular );
				}

				$product->set_sale_price( $sale );
				$product->set_date_on_sale_from( '' );
				$product->set_date_on_sale_to( '' );
				$product->save();
			}
		);

		return true;
	}

	/**
	 * Compares a stored shop price with a computed one as amounts; an empty price equals only an empty price.
	 *
	 * @param string $stored   Price as WooCommerce holds it.
	 * @param string $computed Price as computed.
	 * @param string $currency Currency.
	 * @return bool
	 */
	private static function same( string $stored, string $computed, string $currency ): bool {
		if ( '' === $stored || '' === $computed ) {
			return $stored === $computed;
		}

		try {
			return Money::from_string( $stored, $currency )->equals( Money::from_string( $computed, $currency ) );
		} catch ( \InvalidArgumentException $e ) {
			return false;
		}
	}
}
