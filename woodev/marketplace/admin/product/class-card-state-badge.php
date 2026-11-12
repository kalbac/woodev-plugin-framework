<?php
/**
 * The merchant's name for the state of a card.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Data\Link_Record;

defined( 'ABSPATH' ) || exit;

/**
 * The eleven states of the card lifecycle ({@see Link_Record}) collapse into the seven a merchant sees:
 * Не связан / В очереди / Отправлен / Создаётся / Создан / Ошибка / В архиве. The tone is the colour of the badge.
 *
 * @since 2.1.2
 */
final class Card_State_Badge {
	public const TONE_NEUTRAL = 'neutral';
	public const TONE_WAIT    = 'wait';
	public const TONE_OK      = 'ok';
	public const TONE_ERROR   = 'error';

	/**
	 * Names a state.
	 *
	 * @since 2.1.2
	 *
	 * @param string $state `Link_Record::STATE_*`.
	 * @return array{label:string,tone:string}
	 */
	public static function of( string $state ): array {
		switch ( $state ) {
			case Link_Record::STATE_QUEUED:
				return self::badge( __( 'В очереди', 'woodev-plugin-framework' ), self::TONE_WAIT );
			case Link_Record::STATE_SUBMITTED:
				return self::badge( __( 'Отправлен', 'woodev-plugin-framework' ), self::TONE_WAIT );
			case Link_Record::STATE_OFFER_VALIDATED:
			case Link_Record::STATE_PICS_DELIVERED:
			case Link_Record::STATE_SKU_CREATED:
			case Link_Record::STATE_PRICE_SENT:
				return self::badge( __( 'Создаётся', 'woodev-plugin-framework' ), self::TONE_WAIT );
			case Link_Record::STATE_CREATED:
				return self::badge( __( 'Создан', 'woodev-plugin-framework' ), self::TONE_OK );
			case Link_Record::STATE_IMPORTED_WITH_ERRORS:
			case Link_Record::STATE_FAILED:
				return self::badge( __( 'Ошибка', 'woodev-plugin-framework' ), self::TONE_ERROR );
			case Link_Record::STATE_ARCHIVED:
				return self::badge( __( 'В архиве', 'woodev-plugin-framework' ), self::TONE_NEUTRAL );
			default:
				return self::badge( __( 'Не связан', 'woodev-plugin-framework' ), self::TONE_NEUTRAL );
		}
	}

	/**
	 * Tells whether no card is on the marketplace for the state yet, so the button says «Экспортировать», not «Обновить карточку».
	 *
	 * @since 2.1.2
	 *
	 * @param string $state `Link_Record::STATE_*`.
	 * @return bool
	 */
	public static function is_unsent( string $state ): bool {
		return in_array( $state, [ Link_Record::STATE_UNLINKED, Link_Record::STATE_FAILED ], true );
	}

	/**
	 * Builds the result.
	 *
	 * @param string $label Label.
	 * @param string $tone  Tone.
	 * @return array{label:string,tone:string}
	 */
	private static function badge( string $label, string $tone ): array {
		return [
			'label' => $label,
			'tone'  => $tone,
		];
	}
}
