<?php
/**
 * Marketplace prices into a WooCommerce product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Pair;

defined( 'ABSPATH' ) || exit;

/**
 * The shop side of the `from_marketplace` price direction, shared by everything that turns a marketplace price into a shop price:
 * {@see Price_Refresher} (a linked card whose price changed) and the card importer (a new product).
 *
 * {@see self::restore()} undoes the price rule in force for the product ({@see Price_Context::invert()} — division for a percent
 * markup, never subtraction, then the currency rate divides), or says why it cannot. {@see self::write()} saves the result with
 * `set_regular_price()` / `set_sale_price()` (sale cleared when the marketplace's old price is 0 or not above the price) inside
 * {@see Price_Refresh_Guard}, so that save does not echo back to the marketplace.
 *
 * The rule is resolved from the product's **stored** categories (through {@see Price_Context}), so a product whose categories are
 * not saved yet gets the account's rule, not its profile's.
 *
 * @since 2.1.6
 */
final class Shop_Price_Writer {
	/**
	 * The export side's price answers (mapping, profile, rule, rate).
	 *
	 * @var Price_Context
	 */
	private $contexts;

	/**
	 * Creates the writer.
	 *
	 * @since 2.1.6
	 *
	 * @param Price_Context $contexts The export side's price answers.
	 */
	public function __construct( Price_Context $contexts ) {
		$this->contexts = $contexts;
	}

	/**
	 * Works the shop prices out of the marketplace's, or says why it cannot.
	 *
	 * @since 2.1.6
	 *
	 * @param Account                  $account  Account.
	 * @param \WC_Product              $product  Product or variation.
	 * @param Price_Pair               $remote   The marketplace's prices.
	 * @param array<string,mixed>|null $override The link's price override, null for a product with no link.
	 * @param string|null              $refusal  Receives the sentence the merchant reads when null is returned.
	 * @return array{regular:Money|null,sale:Money|null}|null
	 */
	public function restore( Account $account, \WC_Product $product, Price_Pair $remote, ?array $override, ?string &$refusal ): ?array {
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

			$restored = $this->contexts->invert( $remote->price(), $remote->old_price(), $terms['rule'], $override, $terms['currency'] );
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
	 * @since 2.1.6
	 *
	 * @param string                                    $marketplace_id The adapter's id of the account the prices came from.
	 * @param \WC_Product                               $product        Product or variation.
	 * @param array{regular:Money|null,sale:Money|null} $restored       Restored prices; a null regular price is left alone.
	 * @return bool True when the product was saved.
	 */
	public function write( string $marketplace_id, \WC_Product $product, array $restored ): bool {
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
	 * Reads the price fields a product holds as the merchant saved them: the regular and sale price and the sale schedule. This is what a
	 * price intent is worked out from, kept beside the intent ({@see Price_Intents}) so that {@see self::put_back()} can restore it
	 * exactly; the marketplace price cannot be turned back into it when the rule rounds.
	 *
	 * @since 2.1.10
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array{regular:string,sale:string,from:int|null,to:int|null}
	 */
	public static function snapshot( \WC_Product $product ): array {
		return [
			'regular' => (string) $product->get_regular_price(),
			'sale'    => (string) $product->get_sale_price(),
			'from'    => self::moment( $product->get_date_on_sale_from() ),
			'to'      => self::moment( $product->get_date_on_sale_to() ),
		];
	}

	/**
	 * Writes a {@see self::snapshot()} back into the product, under {@see Price_Refresh_Guard} of the marketplace the intent belongs to
	 * (the save must not record an intent of its own). A product that already holds those fields is not saved.
	 *
	 * @since 2.1.10
	 *
	 * @param string              $marketplace_id The adapter's id.
	 * @param \WC_Product         $product        Product or variation.
	 * @param array<string,mixed> $source         The snapshot, as stored with the intent.
	 * @return bool True when the product was saved.
	 */
	public function put_back( string $marketplace_id, \WC_Product $product, array $source ): bool {
		$wanted = [
			'regular' => (string) ( $source['regular'] ?? '' ),
			'sale'    => (string) ( $source['sale'] ?? '' ),
			'from'    => isset( $source['from'] ) ? (int) $source['from'] : null,
			'to'      => isset( $source['to'] ) ? (int) $source['to'] : null,
		];

		if ( self::snapshot( $product ) === $wanted ) {
			return false;
		}

		Price_Refresh_Guard::run(
			$marketplace_id,
			static function () use ( $product, $wanted ): void {
				$product->set_regular_price( $wanted['regular'] );
				$product->set_sale_price( $wanted['sale'] );
				$product->set_date_on_sale_from( null === $wanted['from'] ? '' : $wanted['from'] );
				$product->set_date_on_sale_to( null === $wanted['to'] ? '' : $wanted['to'] );
				$product->save();
			}
		);

		return true;
	}

	/**
	 * A date WooCommerce hands out (a `WC_DateTime`, or nothing) as a Unix time.
	 *
	 * @param mixed $date Date.
	 * @return int|null
	 */
	private static function moment( $date ): ?int {
		if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
			return (int) $date->getTimestamp();
		}

		$time = is_string( $date ) && '' !== $date ? strtotime( $date ) : false;

		return false === $time ? null : $time;
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
