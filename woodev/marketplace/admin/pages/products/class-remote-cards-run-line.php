<?php
/**
 * What the account line says about the import of every card under a filter.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Data\Bulk_Run;

defined( 'ABSPATH' ) || exit;

/**
 * Pure wording of the remote-cards run (#1796). The unit is the **card**, not the product, and the run never reports what the import of
 * a card came to: it only feeds the import job, and the result of each card is in its own row. {@see Site_Products_Run_Line} hands a
 * run of this action to this class, so the line reads right on both tables of the page.
 *
 * @since 2.1.10
 */
final class Remote_Cards_Run_Line {
	/**
	 * Whether a run is the import of cards by filter.
	 *
	 * @since 2.1.10
	 *
	 * @param Bulk_Run $run A run.
	 * @return bool
	 */
	public static function is_import( Bulk_Run $run ): bool {
		return Remote_Cards_Definition::IMPORT_SELECTED === $run->action();
	}

	/**
	 * The name the merchant knows an action by: the import's own, or the products table's.
	 *
	 * @since 2.1.10
	 *
	 * @param string $action Action id.
	 * @return string
	 */
	public static function label_of( string $action ): string {
		return Remote_Cards_Definition::IMPORT_SELECTED === $action ? __( 'Импортировать', 'woodev-plugin-framework' ) : Site_Products_Definition::label_of( $action );
	}

	/**
	 * «Импорт по фильтру: в очередь поставлено 3 400 карточек из ~12 345.»
	 *
	 * @since 2.1.10
	 *
	 * @param Bulk_Run $run A running import.
	 * @return string
	 */
	public static function running( Bulk_Run $run ): string {
		$counts = $run->counts();

		if ( empty( $run->cursor()['frozen'] ) ) {
			$text = __( 'Импорт по фильтру: готовим список карточек.', 'woodev-plugin-framework' );
		} else {
			$text = sprintf(
				/* translators: 1: cards queued so far, 2: cards the filter reached when the run started. */
				__( 'Импорт по фильтру: в очередь на импорт поставлено %1$s из ~%2$s.', 'woodev-plugin-framework' ),
				self::cards( $counts['offers_accepted'] ),
				self::number( $counts['products_total'] )
			);
		}

		return $run->cancel_requested() ? $text . ' ' . __( 'Останавливаем: обработка прекратится после текущей части.', 'woodev-plugin-framework' ) : $text;
	}

	/**
	 * The result of an import run that is over: what was queued, and where the rest is found.
	 *
	 * @since 2.1.10
	 *
	 * @param Bulk_Run $run A finished import.
	 * @return string
	 */
	public static function finished( Bulk_Run $run ): string {
		$counts = $run->counts();

		if ( Bulk_Run::STOPPED === $run->state() ) {
			return sprintf(
				/* translators: %s: why the import stopped. */
				__( 'Импорт по фильтру остановлен: %s', 'woodev-plugin-framework' ),
				(string) ( $run->cursor()['stopped'] ?? __( 'причина не записана.', 'woodev-plugin-framework' ) )
			);
		}

		$prefix = Bulk_Run::CANCELLED === $run->state()
			? sprintf(
				/* translators: 1: cards queued, 2: cards the filter reached. */
				__( 'Импорт по фильтру остановлен: поставлено в очередь %1$s из ~%2$s. ', 'woodev-plugin-framework' ),
				self::cards( $counts['offers_accepted'] ),
				self::number( $counts['products_total'] )
			)
			: '';

		return $prefix . sprintf(
			/* translators: 1: cards queued for import, 2: cards left out. */
			__( 'Поставлено в очередь на импорт: %1$s; пропущено %2$s. Результат каждой карточки — в её строке.', 'woodev-plugin-framework' ),
			self::cards( $counts['offers_accepted'] ),
			self::number( $counts['offers_skipped'] )
		);
	}

	/**
	 * What the preview of the import by filter says: how many cards, what happens to them, and how long it takes.
	 *
	 * @since 2.1.10
	 *
	 * @param int $total Cards under the filter.
	 * @return string
	 */
	public static function preview( int $total ): string {
		$reach = sprintf(
			/* translators: %s: «12 345 карточек». */
			__( 'Будет затронуто %s по фильтру.', 'woodev-plugin-framework' ),
			self::cards( $total )
		);
		$what  = sprintf(
			/* translators: %s: «12 345 карточек». */
			__( 'Будет импортировано до %s: часть из них свяжется с уже существующими товарами. Картинки загружаются по одной — импорт тысяч карточек займёт несколько часов.', 'woodev-plugin-framework' ),
			self::cards_up_to( $total )
		);

		return $reach . ' ' . $what . ' ' . __( 'Действие выполняется в фоне частями. Ход работы — в строке аккаунта над таблицей.', 'woodev-plugin-framework' );
	}

	/**
	 * «12 345 карточек».
	 *
	 * @since 2.1.10
	 *
	 * @param int $count Number.
	 * @return string
	 */
	public static function cards( int $count ): string {
		$last_two = $count % 100;
		$last     = $count % 10;

		if ( $last_two >= 11 && $last_two <= 14 ) {
			$word = 'карточек';
		} elseif ( 1 === $last ) {
			$word = 'карточка';
		} else {
			$word = $last >= 2 && $last <= 4 ? 'карточки' : 'карточек';
		}

		return self::number( $count ) . ' ' . $word;
	}

	/**
	 * «до 1 карточки», «до 3 карточек», «до 12 345 карточек»: after «до» the noun takes the genitive, which for 1, 21, 31… is the
	 * singular and for every other count the plural.
	 *
	 * @since 2.1.10
	 *
	 * @param int $count Number.
	 * @return string
	 */
	public static function cards_up_to( int $count ): string {
		$word = 1 === $count % 10 && 11 !== $count % 100 ? 'карточки' : 'карточек';

		return self::number( $count ) . ' ' . $word;
	}

	/**
	 * @param int $number Number.
	 * @return string With a space between thousands.
	 */
	private static function number( int $number ): string {
		return number_format( $number, 0, '', "\u{00A0}" );
	}
}
