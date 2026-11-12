<?php
/**
 * The state groups of the «Товары сайта» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Admin\Product\Card_State_Badge;
use Woodev\Framework\Marketplace\Data\Link_Record;

defined( 'ABSPATH' ) || exit;

/**
 * The eleven card states ({@see Link_Record}) in the seven groups the filter and the badges show. The labels and tones are
 * {@see Card_State_Badge}'s, so the table and the product tab name a state the same way.
 *
 * @since 2.1.3
 */
final class State_Groups {
	public const UNLINKED = 'unlinked';
	public const QUEUED   = 'queued';
	public const SENT     = 'submitted';
	public const CREATING = 'creating';
	public const CREATED  = 'created';
	public const ERROR    = 'error';
	public const ARCHIVED = 'archived';

	/**
	 * Group => card states.
	 *
	 * @var array<string,string[]>
	 */
	private const STATES = [
		self::UNLINKED => [ Link_Record::STATE_UNLINKED ],
		self::QUEUED   => [ Link_Record::STATE_QUEUED ],
		self::SENT     => [ Link_Record::STATE_SUBMITTED ],
		self::CREATING => Link_Record::LIFECYCLE_STATES,
		self::CREATED  => [ Link_Record::STATE_CREATED ],
		self::ERROR    => [ Link_Record::STATE_IMPORTED_WITH_ERRORS, Link_Record::STATE_FAILED ],
		self::ARCHIVED => [ Link_Record::STATE_ARCHIVED ],
	];

	/**
	 * Card tone => the tone of the records engine.
	 *
	 * @var array<string,string>
	 */
	private const TONES = [
		Card_State_Badge::TONE_NEUTRAL => 'neutral',
		Card_State_Badge::TONE_WAIT    => 'info',
		Card_State_Badge::TONE_OK      => 'success',
		Card_State_Badge::TONE_ERROR   => 'danger',
	];

	/**
	 * Group of a card state; a product without a link row is `unlinked`.
	 *
	 * @since 2.1.3
	 *
	 * @param string $card_state `Link_Record::STATE_*`, or '' for no link row.
	 * @return string
	 */
	public static function group_of( string $card_state ): string {
		foreach ( self::STATES as $group => $states ) {
			if ( in_array( $card_state, $states, true ) ) {
				return $group;
			}
		}

		return self::UNLINKED;
	}

	/**
	 * Card states of a group.
	 *
	 * @since 2.1.3
	 *
	 * @param string $group Group.
	 * @return string[] Empty for an unknown group.
	 */
	public static function states_of( string $group ): array {
		return self::STATES[ $group ] ?? [];
	}

	/**
	 * Whether the string names a group.
	 *
	 * @since 2.1.3
	 *
	 * @param string $group Candidate.
	 * @return bool
	 */
	public static function is_group( string $group ): bool {
		return isset( self::STATES[ $group ] );
	}

	/**
	 * The options of the «Состояние» filter: group => label.
	 *
	 * @since 2.1.3
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$labels = [];

		foreach ( self::STATES as $group => $states ) {
			$labels[ $group ] = Card_State_Badge::of( $states[0] )['label'];
		}

		return $labels;
	}

	/**
	 * A badge cell for a group.
	 *
	 * @since 2.1.3
	 *
	 * @param string $group Group.
	 * @param int    $count How many offers are in it; 0 or 1 adds nothing to the label.
	 * @return array{label:string,tone:string}
	 */
	public static function badge( string $group, int $count = 1 ): array {
		$badge = Card_State_Badge::of( ( self::STATES[ $group ] ?? self::STATES[ self::UNLINKED ] )[0] );

		return [
			'label' => $count > 1 ? $badge['label'] . ' × ' . $count : $badge['label'],
			'tone'  => self::TONES[ $badge['tone'] ] ?? 'neutral',
		];
	}
}
