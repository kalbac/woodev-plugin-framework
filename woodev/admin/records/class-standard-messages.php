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
		];
	}

	/**
	 * The counted view row: the entry that clears the filter.
	 *
	 * @since 2.1.6
	 *
	 * @return array<string,string>
	 */
	public static function views(): array {
		return [
			'views_all' => __( 'Все', 'woodev-plugin-framework' ),
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
