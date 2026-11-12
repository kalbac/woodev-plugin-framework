<?php
/**
 * The sentences about the last «Связать по артикулу» run and its near-match report.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Data\Link_Report_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Plain sentences for the line above the «Карточки на маркетплейсе» table. Pure: the stored run summary and a count in, text out.
 *
 * @since 2.1.5
 */
final class Remote_Cards_Summary {
	/**
	 * What the last run did, or why it failed.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,mixed>|null $run The stored summary of the last run ({@see \Woodev\Framework\Marketplace\Data\Link_Report_Store::last_run()}).
	 * @return string '' when no run has finished.
	 */
	public static function last_run( ?array $run ): string {
		if ( null === $run ) {
			return '';
		}

		$time = wp_date( 'd.m.Y H:i', (int) ( $run['finished_at'] ?? 0 ) );

		if ( '' !== (string) ( $run['error'] ?? '' ) ) {
			/* translators: 1: time of the run, 2: the reason it failed. */
			return sprintf( __( 'Последний поиск по артикулу (%1$s) не удался: %2$s', 'woodev-plugin-framework' ), $time, (string) $run['error'] );
		}

		$sentence = sprintf(
			/* translators: 1: time of the run, 2: cards checked, 3: linked now, 4: already linked, 5: left without a pair for several candidates. */
			__( 'Последний поиск по артикулу (%1$s): карточек просмотрено — %2$d, связано — %3$d, уже были связаны — %4$d, неоднозначных — %5$d.', 'woodev-plugin-framework' ),
			$time,
			(int) ( $run['cards'] ?? 0 ),
			(int) ( $run['linked'] ?? 0 ),
			(int) ( $run['already_linked'] ?? 0 ),
			(int) ( $run['ambiguous'] ?? 0 )
		);

		$dropped = (int) ( $run['near_dropped'] ?? 0 );

		if ( $dropped > 0 ) {
			$sentence .= ' ' . sprintf(
				/* translators: 1: pairs the list keeps, 2: pairs that did not fit. */
				__( 'В списке похожих артикулов показаны первые %1$d пар, ещё %2$d не поместились.', 'woodev-plugin-framework' ),
				Link_Report_Store::MAX_ENTRIES,
				$dropped
			);
		}

		return $sentence;
	}

	/**
	 * How many look-alike pairs wait for the merchant, and where they are.
	 *
	 * @since 2.1.5
	 *
	 * @param int $waiting Pairs in the report now.
	 * @return string '' when none waits.
	 */
	public static function waiting( int $waiting ): string {
		if ( $waiting <= 0 ) {
			return '';
		}

		return sprintf(
			/* translators: %d: number of pairs in the near-match report. */
			__( 'Пар с похожими артикулами, которые ждут вашего решения: %d. Выберите «Показать» → «Похожие артикулы».', 'woodev-plugin-framework' ),
			$waiting
		);
	}

	/**
	 * The explanation shown above the report.
	 *
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public static function near_explained(): string {
		return __( 'Артикулы в этих парах отличаются только регистром или буквами, которые выглядят одинаково (например, русская «С» и латинская «C», буква «O» и ноль). Автоматически такие пары не связываются: подтвердите, что это один и тот же товар, или скажите, что товары разные.', 'woodev-plugin-framework' );
	}
}
