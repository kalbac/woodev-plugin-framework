<?php
/**
 * Records engine — the sentences every table shares.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * The words of the controls the shell draws the same way on every table: the bulk-action select, the counted view row and the
 * period picker. A definition merges the groups it uses into its own `messages`, so the shell keeps no copy of any of them
 * (the PHP-driven rule of the marketplace admin).
 *
 * @since 2.1.6
 */
final class Standard_Messages {
	/**
	 * The bulk-action select: its empty entry, its button and its accessible name.
	 *
	 * @since 2.1.6
	 *
	 * @return array<string,string>
	 */
	public static function bulk(): array {
		return [
			'bulk_placeholder' => __( 'Выберите действие', 'woodev-plugin-framework' ),
			'bulk_apply'       => __( 'Применить', 'woodev-plugin-framework' ),
			'bulk_label'       => __( 'Массовые действия', 'woodev-plugin-framework' ),
			// `{count}` is the number of ticked rows; the shell writes it beside the button.
			'selected_count'   => __( 'Выбрано: {count}', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * The «select every row under the filter» bar above a ticked page: the offer, the confirmation and the way back.
	 *
	 * `{count}` is the number of rows and `{filters}` the active filters in words (the shell writes both). `$records` names the
	 * rows in the sentences: «записи» by default, a definition passes its own («товары», «карточки»).
	 *
	 * @since 2.1.8
	 *
	 * @param string $records The noun for the table's rows, already translated.
	 * @return array<string,string>
	 */
	public static function matching( string $records = 'записи' ): array {
		return [
			'selected_page'         => sprintf( __( 'Выбраны все %s на этой странице: {count}.', 'woodev-plugin-framework' ), $records ),
			'select_all_matching'   => sprintf( __( 'Выбрать все %s под текущим отбором: {count}', 'woodev-plugin-framework' ), $records ),
			'selected_all_matching' => sprintf( __( 'Выбраны все %s под текущим отбором: {count}. {filters}', 'woodev-plugin-framework' ), $records ),
			'clear_selection'       => __( 'Снять выбор', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * The counted view row (the entry that clears the filter) and the name of the table's column menu.
	 *
	 * @since 2.1.6
	 *
	 * @return array<string,string>
	 */
	public static function views(): array {
		return [
			'views_all'    => __( 'Все', 'woodev-plugin-framework' ),
			'columns_menu' => __( 'Столбцы таблицы', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * The card footer's pager: the page count, the two arrows and the jump-to-page field. The footer is always drawn, a one-page
	 * table included (arrows off), like the footer of «Заказы доставки». The size of a page is the definition's own `per_page`
	 * sentence («Товаров на странице»), which names the rows.
	 *
	 * `{page}` is the page on screen and `{pages}` the number of pages (the shell writes both).
	 *
	 * @since 2.1.13
	 *
	 * @return array<string,string>
	 */
	public static function pager(): array {
		return [
			'page_status' => __( 'Страница {page} из {pages}', 'woodev-plugin-framework' ),
			'page_prev'   => __( 'Предыдущая страница', 'woodev-plugin-framework' ),
			'page_next'   => __( 'Следующая страница', 'woodev-plugin-framework' ),
			'page_goto'   => __( 'Перейти на страницу', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * The period picker: its label, «all time», the ten presets, the custom range and its two buttons.
	 *
	 * The apply button is «Применить период», not the bare «Применить»: the bulk-action button carries that name, and two controls
	 * on one screen must not share an accessible name.
	 *
	 * @since 2.1.6
	 *
	 * @return array<string,string>
	 */
	public static function period(): array {
		return [
			'period_label'        => __( 'Период', 'woodev-plugin-framework' ),
			'period_legend'       => __( 'Выберите период', 'woodev-plugin-framework' ),
			'period_all'          => __( 'Всё время', 'woodev-plugin-framework' ),
			'period_today'        => __( 'Сегодня', 'woodev-plugin-framework' ),
			'period_yesterday'    => __( 'Вчера', 'woodev-plugin-framework' ),
			'period_week'         => __( 'С начала недели', 'woodev-plugin-framework' ),
			'period_last_week'    => __( 'Прошлая неделя', 'woodev-plugin-framework' ),
			'period_month'        => __( 'С начала месяца', 'woodev-plugin-framework' ),
			'period_last_month'   => __( 'Прошлый месяц', 'woodev-plugin-framework' ),
			'period_quarter'      => __( 'С начала квартала', 'woodev-plugin-framework' ),
			'period_last_quarter' => __( 'Прошлый квартал', 'woodev-plugin-framework' ),
			'period_year'         => __( 'С начала года', 'woodev-plugin-framework' ),
			'period_last_year'    => __( 'Прошлый год', 'woodev-plugin-framework' ),
			'period_custom'       => __( 'Произвольный период', 'woodev-plugin-framework' ),
			'period_back'         => __( 'Назад', 'woodev-plugin-framework' ),
			'period_apply'        => __( 'Применить период', 'woodev-plugin-framework' ),
		];
	}
}
