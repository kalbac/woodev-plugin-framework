<?php
/**
 * The sentence that says how an account's notifications are doing.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Admin\Diagnostics\Diagnostics_Reader;
use Woodev\Framework\Marketplace\Intake\Event_Names;
use Woodev\Framework\Marketplace\Settings\Push_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * «Связь не проверялась. Последнее уведомление: отмена отправления, 3 дня назад.» — what the marketplace last reported about the
 * address, then the latest notification received, as two sentences. The settings screen and the diagnostics page both say it, so
 * they say it the same way.
 *
 * @since 2.1.13
 */
final class Notification_State_Text {
	/**
	 * Names of the notification types.
	 *
	 * @var Event_Names
	 */
	private $events;

	/**
	 * Creates the mapper.
	 *
	 * @since 2.1.13
	 *
	 * @param Event_Names $events Names of the notification types.
	 */
	public function __construct( Event_Names $events ) {
		$this->events = $events;
	}

	/**
	 * Builds the two sentences.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed>                                   $state  The registration state stored for the account; empty when it was never checked.
	 * @param Push_Admin|null                                       $admin  The adapter's registration helper, if it has one.
	 * @param array{type:string,received_at:int,status:string}|null $latest The latest notification received, if any.
	 * @param int                                                   $now    Current timestamp.
	 * @return string
	 */
	public function describe( array $state, ?Push_Admin $admin, ?array $latest, int $now ): string {
		if ( [] === $state ) {
			$registration = __( 'Связь не проверялась.', 'woodev-plugin-framework' );
		} elseif ( null !== $admin ) {
			$registration = self::sentence( $admin->describe_state( $state ) );
		} else {
			$registration = ! empty( $state['enabled'] )
				? __( 'Уведомления включены.', 'woodev-plugin-framework' )
				: __( 'Уведомления отключены.', 'woodev-plugin-framework' );
		}

		if ( null === $latest ) {
			return $registration . ' ' . __( 'Уведомления пока не поступали.', 'woodev-plugin-framework' );
		}

		return $registration . ' ' . sprintf(
			/* translators: 1: name of the notification type, 2: relative time, «3 дня назад». */
			__( 'Последнее уведомление: %1$s, %2$s.', 'woodev-plugin-framework' ),
			$this->events->name( $latest['type'] ),
			Diagnostics_Reader::age_text( $latest['received_at'], $now )
		);
	}

	/**
	 * Capitalises the first letter of a line an adapter or a list supplied in lower case, and ends it with a full stop.
	 *
	 * @since 2.1.13
	 *
	 * @param string $text Line.
	 * @return string
	 */
	public static function sentence( string $text ): string {
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		$text = mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );

		return preg_match( '/[.!?…]$/u', $text ) ? $text : $text . '.';
	}
}
