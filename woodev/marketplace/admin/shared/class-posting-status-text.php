<?php
/**
 * Merchant-facing names of a marketplace's posting statuses.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the status a marketplace gave a posting («awaiting_packaging», «delivered») into the Russian word and badge tone the admin
 * screens show, so no raw code ever reaches a merchant. One map serves every screen that shows a posting status.
 *
 * The codes are the posting vocabulary the marketplaces share (Ozon's FBS statuses, and the neutral names the order layer uses
 * for a posting that is new, shipped or delivered). A code the map does not know gets one generic word, never the code itself.
 *
 * @since 2.1.13
 */
final class Posting_Status_Text {
	/**
	 * Badge tone of a status: finished well, finished badly, a dispute, a step the shop must act on, or a step the marketplace is on.
	 */
	private const TONES = [
		'delivered'              => 'success',
		'cancelled'              => 'danger',
		'not_accepted'           => 'danger',
		'arbitration'            => 'warning',
		'client_arbitration'     => 'warning',
		'awaiting_registration'  => 'warning',
		'awaiting_approve'       => 'warning',
		'awaiting_packaging'     => 'warning',
		'awaiting_deliver'       => 'warning',
		'new'                    => 'warning',
		'awaiting_shipment'      => 'warning',
		'acceptance_in_progress' => 'info',
		'delivering'             => 'info',
		'driver_pickup'          => 'info',
		'sent_by_seller'         => 'info',
		'shipped'                => 'info',
	];

	/**
	 * Names a posting status.
	 *
	 * @since 2.1.13
	 *
	 * @param string $status The marketplace's status, as stored.
	 * @return string Translated word; a generic one for a code the map does not know.
	 */
	public static function label( string $status ): string {
		switch ( self::normalise( $status ) ) {
			case 'awaiting_registration':
				return __( 'Ожидает регистрации', 'woodev-plugin-framework' );
			case 'acceptance_in_progress':
				return __( 'Идёт приёмка', 'woodev-plugin-framework' );
			case 'awaiting_approve':
				return __( 'Ожидает подтверждения', 'woodev-plugin-framework' );
			case 'awaiting_packaging':
				return __( 'Ожидает упаковки', 'woodev-plugin-framework' );
			case 'awaiting_deliver':
			case 'awaiting_shipment':
				return __( 'Ожидает отгрузки', 'woodev-plugin-framework' );
			case 'new':
				return __( 'Новое', 'woodev-plugin-framework' );
			case 'arbitration':
				return __( 'Арбитраж', 'woodev-plugin-framework' );
			case 'client_arbitration':
				return __( 'Спор с покупателем', 'woodev-plugin-framework' );
			case 'delivering':
				return __( 'Доставляется', 'woodev-plugin-framework' );
			case 'driver_pickup':
				return __( 'Передано водителю', 'woodev-plugin-framework' );
			case 'sent_by_seller':
			case 'shipped':
				return __( 'Отправлено', 'woodev-plugin-framework' );
			case 'delivered':
				return __( 'Доставлено', 'woodev-plugin-framework' );
			case 'cancelled':
				return __( 'Отменено', 'woodev-plugin-framework' );
			case 'not_accepted':
				return __( 'Не принято', 'woodev-plugin-framework' );
			default:
				return __( 'Другой статус', 'woodev-plugin-framework' );
		}
	}

	/**
	 * Gives the badge tone of a posting status.
	 *
	 * @since 2.1.13
	 *
	 * @param string $status The marketplace's status, as stored.
	 * @return string `success`, `danger`, `warning`, `info` or `neutral`.
	 */
	public static function tone( string $status ): string {
		return self::TONES[ self::normalise( $status ) ] ?? 'neutral';
	}

	/**
	 * @param string $status Status as stored.
	 * @return string Lower-case code with spaces and dashes as underscores.
	 */
	private static function normalise( string $status ): string {
		return str_replace( [ ' ', '-' ], '_', strtolower( trim( $status ) ) );
	}
}
