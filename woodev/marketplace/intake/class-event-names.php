<?php
/**
 * Merchant-facing names of the notification types a marketplace pushes.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the event type a notification carries (`TYPE_POSTING_CANCELLED`, the decoder's own word) into what the merchant reads:
 * the name the adapter gave it ({@see Names_Event_Types}, for every type it handles) or, failing that, the name of the switch it
 * declared for it ({@see Webhook_Type::label()}), in the lower case a sentence wants.
 *
 * The names are the adapter's — the framework knows no marketplace's codes. A type nobody named stays as the decoder gave it,
 * so a notification is never shown under a made-up name.
 *
 * @since 2.1.13
 */
final class Event_Names {
	/**
	 * Names by lower-cased event type.
	 *
	 * @var array<string,string>
	 */
	private $names = [];

	/**
	 * Creates the namer.
	 *
	 * @since 2.1.13
	 *
	 * @param Push_Protocol|null $protocol The marketplace's notification protocol; null when it pushes nothing.
	 */
	public function __construct( ?Push_Protocol $protocol ) {
		if ( null === $protocol ) {
			return;
		}

		foreach ( $protocol->webhook_types() as $type ) {
			$this->names[ strtolower( $type->id() ) ] = $type->label();
		}

		// The adapter's own names win: they cover the types that have no switch.
		if ( $protocol instanceof Names_Event_Types ) {
			foreach ( $protocol->event_names() as $id => $name ) {
				$this->names[ strtolower( (string) $id ) ] = (string) $name;
			}
		}
	}

	/**
	 * Returns the merchant-facing name of an event type, first letter in lower case («отмена отправления»).
	 *
	 * @since 2.1.13
	 *
	 * @param string $type Event type the decoder gave the notification.
	 * @return string The adapter's name for it, or `$type` itself when the adapter named none.
	 */
	public function name( string $type ): string {
		$label = trim( $this->names[ strtolower( $type ) ] ?? '' );

		if ( '' === $label ) {
			return $type;
		}

		$first = mb_substr( $label, 0, 2 );

		// An acronym at the start («FBS…») keeps its capitals.
		if ( mb_strtoupper( $first ) === $first && mb_strtolower( $first ) !== $first ) {
			return $label;
		}

		return mb_strtolower( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 );
	}
}
