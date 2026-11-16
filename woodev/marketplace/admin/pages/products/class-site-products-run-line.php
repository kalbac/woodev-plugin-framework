<?php
/**
 * What the account line says about a bulk run, and the sentences of its preview.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Catalog\Bulk_Validation_Report;
use Woodev\Framework\Marketplace\Data\Bulk_Run;

defined( 'ABSPATH' ) || exit;

/**
 * Pure wording (#1795, §2.5 and §2.6). The line speaks in two units and never mixes them: products (the table's own unit, the apply-time
 * estimate and the parents finished so far) and offers (simple products and variations). A run that only prepares remote work reports
 * what it queued, never a remote outcome: later failures of an export land on the link and the task, which carry no run id.
 *
 * @since 2.1.8
 */
final class Site_Products_Run_Line {
	/**
	 * How long a finished run stays on the line (a proposal): a day, as the finished row stays until the next run replaces it.
	 */
	public const SHOW_FINISHED_SECONDS = 86400;

	/**
	 * Actions that finish inside the run, and so report a result; the others only prepare and queue remote work.
	 */
	private const LOCAL = [
		Site_Products_Bulk::SYNC_STOCK_ON,
		Site_Products_Bulk::SYNC_STOCK_OFF,
		Site_Products_Bulk::SYNC_PRICE_ON,
		Site_Products_Bulk::SYNC_PRICE_OFF,
		Site_Products_Bulk::SET_WAREHOUSE,
		Site_Products_Bulk::RESET,
		Site_Products_Bulk::RESET_PRICE,
		Site_Products_Bulk::DETACH,
	];

	/**
	 * The account line of a run: `active`, `text` and the buttons, or null when the run has nothing to show (none, or it finished
	 * long ago).
	 *
	 * @since 2.1.8
	 *
	 * @param Bulk_Run|null $run Run of the account.
	 * @param int           $now Unix time.
	 * @return array{active:bool,text:string,actions:array<int,array{action:string,label:string,payload:array<string,string>}>}|null
	 */
	public static function line( ?Bulk_Run $run, int $now ): ?array {
		if ( null === $run || ( ! $run->is_running() && $run->finished_at() + self::SHOW_FINISHED_SECONDS < $now ) ) {
			return null;
		}

		$payload = [ Site_Products_Bulk::RUN_FIELD => $run->run_id() ];
		$report  = [
			'action'  => Site_Products_Bulk::RUN_REPORT,
			'label'   => __( 'Подробнее', 'woodev-plugin-framework' ),
			'payload' => $payload,
		];

		if ( ! $run->is_running() ) {
			return [
				'active'  => false,
				'text'    => self::finished( $run ),
				'actions' => [ $report ],
			];
		}

		$stop = [
			'action'  => Site_Products_Bulk::RUN_STOP,
			'label'   => __( 'Остановить', 'woodev-plugin-framework' ),
			'payload' => $payload,
		];

		return [
			'active'  => true,
			'text'    => self::running( $run ),
			'actions' => $run->cancel_requested() ? [ $report ] : [ $stop, $report ],
		];
	}

	/**
	 * «Выполняется «Отправить остатки» по фильтру: товаров 3 400 из ~12 345 (обработано вариантов и простых товаров: 7 900)».
	 *
	 * @since 2.1.8
	 *
	 * @param Bulk_Run $run A running run.
	 * @return string
	 */
	public static function running( Bulk_Run $run ): string {
		$counts = $run->counts();

		$text = sprintf(
			/* translators: 1: the action, 2: products done, 3: products expected, 4: offers handled. */
			__( 'Выполняется «%1$s» по фильтру: товаров %2$s из ~%3$s (обработано вариантов и простых товаров: %4$s).', 'woodev-plugin-framework' ),
			Site_Products_Definition::label_of( $run->action() ),
			self::number( $counts['products_done'] ),
			self::number( $counts['products_total'] ),
			self::number( self::handled( $counts ) )
		);

		return $run->cancel_requested() ? $text . ' ' . __( 'Останавливаем: обработка прекратится после текущей части.', 'woodev-plugin-framework' ) : $text;
	}

	/**
	 * The result of a run that is over.
	 *
	 * @since 2.1.8
	 *
	 * @param Bulk_Run $run A finished run.
	 * @return string
	 */
	public static function finished( Bulk_Run $run ): string {
		$counts = $run->counts();
		$label  = Site_Products_Definition::label_of( $run->action() );

		if ( Bulk_Run::STOPPED === $run->state() ) {
			return sprintf(
				/* translators: 1: the action, 2: why it stopped. */
				__( '«%1$s» по фильтру остановлено: %2$s', 'woodev-plugin-framework' ),
				$label,
				(string) ( $run->cursor()['stopped'] ?? __( 'причина не записана.', 'woodev-plugin-framework' ) )
			);
		}

		$prefix = Bulk_Run::CANCELLED === $run->state()
			? sprintf(
				/* translators: 1: the action, 2: products done, 3: products expected. */
				__( '«%1$s» по фильтру остановлено: обработано товаров %2$s из ~%3$s. ', 'woodev-plugin-framework' ),
				$label,
				self::number( $counts['products_done'] ),
				self::number( $counts['products_total'] )
			)
			: '';

		return $prefix . self::result( $run->action(), $counts );
	}

	/**
	 * What the preview of an action over every product under the filter says first.
	 *
	 * @since 2.1.8
	 *
	 * @param int $total Products under the filter.
	 * @return string
	 */
	public static function reach( int $total ): string {
		/* translators: %s: «12 345 товаров». */
		return sprintf( __( 'Будет затронуто %s по фильтру.', 'woodev-plugin-framework' ), self::number( $total ) . ' ' . Bulk_Validation_Report::products( $total ) );
	}

	/**
	 * What every preview of the matching mode ends with.
	 *
	 * @since 2.1.8
	 *
	 * @return string
	 */
	public static function background(): string {
		return __( 'Действие выполняется в фоне частями; товары, изменившиеся за это время, обрабатываются такими, какими они будут в момент обработки. Ход работы — в строке аккаунта над таблицей.', 'woodev-plugin-framework' );
	}

	/**
	 * The sentence of one action's preview in the matching mode, after {@see self::reach()}.
	 *
	 * @since 2.1.8
	 *
	 * @param string $action      Action id.
	 * @param string $marketplace Merchant-facing marketplace name.
	 * @return string
	 */
	public static function effect( string $action, string $marketplace ): string {
		$sentences = [
			Site_Products_Bulk::EXPORT         => __( 'Каждый товар будет проверен перед отправкой; прошедшие проверку уйдут в очередь частями по 100. Товары с ошибками останутся как есть — список причин будет в итоге.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SEND_STOCK     => __( 'Текущие остатки с сайта будут поставлены в очередь на отправку на %s для всех связанных товаров по фильтру. Несвязанные и выключенные пропустим.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SEND_PRICE     => __( 'Текущие цены с сайта будут поставлены в очередь на отправку на %s для всех связанных товаров по фильтру. Несвязанные и выключенные пропустим.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SYNC_STOCK_ON  => __( 'Переключатель изменится у всех связанных товаров по фильтру. Включение сразу поставит в очередь отправку текущих остатков с сайта.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SYNC_STOCK_OFF => __( 'Переключатель изменится у всех связанных товаров по фильтру.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SYNC_PRICE_ON  => __( 'Переключатель изменится у всех связанных товаров по фильтру. Включение сразу поставит в очередь отправку текущих цен с сайта.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SYNC_PRICE_OFF => __( 'Переключатель изменится у всех связанных товаров по фильтру.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SET_WAREHOUSE  => __( 'Остатки всех связанных товаров по фильтру будут отправляться на выбранный склад.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::ARCHIVE        => __( 'Остаток на %s станет нулевым, затем карточки уйдут в архив. Товары на сайте не меняются.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::DETACH         => __( 'Сайт забудет карточки всех товаров по фильтру. Карточки на %s не меняются.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::RESET          => __( 'Товары снова станут «Не связан». На %s ничего не меняется.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::RESET_PRICE    => __( 'У всех связанных товаров по фильтру своя цена будет удалена: цена снова считается по правилу аккаунта.', 'woodev-plugin-framework' ),
			Site_Products_Bulk::PULL_PRICES    => __( 'Цены созданных карточек будут прочитаны с %s и записаны в товары сайта.', 'woodev-plugin-framework' ),
		];

		return sprintf( $sentences[ $action ] ?? '', $marketplace );
	}

	/**
	 * The result of a run, in the words of what its action does.
	 *
	 * @param string            $action Action id.
	 * @param array<string,int> $counts Counters.
	 * @return string
	 */
	private static function result( string $action, array $counts ): string {
		if ( in_array( $action, self::LOCAL, true ) ) {
			return sprintf(
				/* translators: 1: products, 2: offers changed, 3: offers skipped, 4: offers failed. */
				__( 'Готово: товаров %1$s; изменено %2$s, пропущено %3$s, не удалось %4$s.', 'woodev-plugin-framework' ),
				self::number( $counts['products_done'] ),
				self::number( $counts['offers_accepted'] ),
				self::number( $counts['offers_skipped'] ),
				self::number( $counts['offers_failed'] )
			);
		}

		if ( Site_Products_Bulk::PULL_PRICES === $action ) {
			return sprintf(
				/* translators: 1: offers queued, 2: offers skipped, 3: offers failed. */
				__( 'Поставлено в очередь на загрузку цен: %1$s; пропущено %2$s, не удалось %3$s. Цены на сайте обновятся в течение нескольких минут; то, что не удалось пересчитать, записано в журнал ошибок.', 'woodev-plugin-framework' ),
				self::number( $counts['offers_accepted'] ),
				self::number( $counts['offers_skipped'] ),
				self::number( $counts['offers_failed'] )
			);
		}

		return sprintf(
			/* translators: 1: offers queued, 2: offers skipped, 3: offers failed. */
			__( 'Подготовлено и поставлено в очередь: %1$s; пропущено %2$s, не удалось %3$s. Отправка идёт в фоне — её ход виден в столбце «Состояние», ошибки — в фильтре «только с ошибками».', 'woodev-plugin-framework' ),
			self::number( $counts['offers_accepted'] ),
			self::number( $counts['offers_skipped'] ),
			self::number( $counts['offers_failed'] )
		);
	}

	/**
	 * @param array<string,int> $counts Counters.
	 * @return int Offers the run has gone through.
	 */
	private static function handled( array $counts ): int {
		return $counts['offers_accepted'] + $counts['offers_skipped'] + $counts['offers_failed'];
	}

	/**
	 * @param int $number Number.
	 * @return string With a space between thousands.
	 */
	private static function number( int $number ): string {
		return number_format( $number, 0, '', "\u{00A0}" );
	}
}
