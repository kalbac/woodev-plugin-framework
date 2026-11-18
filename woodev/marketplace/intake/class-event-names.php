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
 * the name of the switch the adapter declared for it ({@see Webhook_Type::label()}), in the lower case a sentence wants.
 *
 * The names are the adapter's — the framework knows no marketplace's codes. A type nobody declared stays as the decoder gave it,
 * so a notification is never shown under a made-up name.
 *
 * @since 2.1.13
 */
final class Event_Names {
	/**
	 * Declared types by lower-cased id.
	 *
	 * @var array<string,Webhook_Type>
	 */
	private $types = [];

	/**
	 * Creates the namer.
	 *
	 * @since 2.1.13
	 *
	 * @param Push_Protocol|null $protocol The marketplace's notification protocol; null when it pushes nothing.
	 */
	public function __construct( ?Push_Protocol $protocol ) {
		foreach ( null === $protocol ? [] : $protocol->webhook_types() as $type ) {
			$this->types[ strtolower( $type->id() ) ] = $type;
		}
	}

	/**
	 * Returns the merchant-facing name of an event type, first letter in lower case («отмена отправления»).
	 *
	 * @since 2.1.13
	 *
	 * @param string $type Event type the decoder gave the notification.
	 * @return string The adapter's name for it, or `$type` itself when the adapter declared none.
	 */
	public function name( string $type ): string {
		$key = strtolower( $type );

		if ( ! isset( $this->types[ $key ] ) || '' === trim( $this->types[ $key ]->label() ) ) {
			return $type;
		}

		$label = trim( $this->types[ $key ]->label() );
		$first = mb_substr( $label, 0, 2 );

		// An acronym at the start («FBS…») keeps its capitals.
		if ( mb_strtoupper( $first ) === $first && mb_strtolower( $first ) !== $first ) {
			return $label;
		}

		return mb_strtolower( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 );
	}
}
