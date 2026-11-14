<?php
/**
 * Rows of the «Карточки на маркетплейсе» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Activity_Normalizer;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Product\Card_State_Badge;
use Woodev\Framework\Marketplace\Catalog\Offer_Id_Likeness;
use Woodev\Framework\Marketplace\Data\Link_Record;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a snapshot card (with the product that carries its article, if any) into a row of the records engine. Plain values only: the
 * engine escapes and types every cell.
 *
 * @since 2.1.5
 */
final class Remote_Cards_Rows {
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
	 * Names a product for the «На сайте» cell: `function ( int $product_id ): array{title:string,url:string}`; the address is empty when
	 * the product is gone or the user may not edit it.
	 *
	 * @var callable
	 */
	private $describe;

	/**
	 * Decides whether an import note's technical tail is shown. Null: the tail is never shown.
	 *
	 * @var Logging|null
	 */
	private $logging;

	/**
	 * Whether the table offers «Импортировать» (the adapter can import cards): rows are then selectable and carry the action.
	 *
	 * @var bool
	 */
	private $importable;

	/**
	 * Creates the builder.
	 *
	 * @since 2.1.5
	 *
	 * @param callable     $describe   `function ( int $product_id ): array{title:string,url:string}`.
	 * @param Logging|null $logging    The «Логирование» switch, read when a row is built, so the technical tail of an import note follows it as it is now.
	 * @param bool         $importable Whether the table offers «Импортировать».
	 */
	public function __construct( callable $describe, ?Logging $logging = null, bool $importable = false ) {
		$this->describe   = $describe;
		$this->logging    = $logging;
		$this->importable = $importable;
	}

	/**
	 * Builds a row.
	 *
	 * @since 2.1.5
	 *
	 * @param int                                                   $account_id Account id.
	 * @param array<string,mixed>                                   $card       Snapshot card with `wc_product_id` (0 when no product carries it).
	 * @param array{offer_id:string,text:string,detail:string}|null $note       The card's import note, if it has one.
	 * @param bool                                                  $importing  Whether an import of the card is pending or running: the row is then locked.
	 * @return array<string,mixed>
	 */
	public function row( int $account_id, array $card, ?array $note = null, bool $importing = false ): array {
		$offer_id = (string) ( $card['offer_id'] ?? '' );

		return [
			'id'           => Remote_Cards_Ids::row_id( $account_id, $offer_id ),
			'revision'     => '',
			'cells'        => [
				'offer_id' => $offer_id,
				'name'     => (string) ( $card['name'] ?? '' ),
				'ids'      => $this->ids( (array) ( $card['remote_ids'] ?? [] ) ),
				'state'    => array_merge( $this->state( $card ), $this->note( $note ) ),
				'on_site'  => $this->on_site( (int) ( $card['wc_product_id'] ?? 0 ) ),
			],
			'actions'      => $this->actions( $card, $importing ),
			'selectable'   => $this->importable,
			'has_children' => false,
			'activity'     => $importing ? self::importing_activity() : Activity_Normalizer::idle(),
		];
	}

	/**
	 * The activity of a row whose card is being imported: it is locked, and neither the row's «Импортировать» nor «Связать с…» may run. The bulk
	 * action is not blocked: it would go grey for a whole selection because of one locked row, and the plan already skips such a card with its own reason.
	 *
	 * @return array<string,mixed> Shape of {@see Activity_Normalizer::row()}.
	 */
	public static function importing_activity(): array {
		return [
			'locked'          => true,
			'reason'          => self::locked_reason(),
			'blocked_actions' => [ Remote_Cards_Definition::IMPORT, Remote_Cards_Definition::LINK_TO ],
			'processes'       => [
				[
					'id'    => 'import',
					'state' => 'running',
					'label' => __( 'Импортируется', 'woodev-plugin-framework' ),
				],
			],
			'observed_at'     => Activity_Normalizer::now(),
		];
	}

	/**
	 * Builds a row of the near-match report: the card, the product it looks like, and the two answers.
	 *
	 * @since 2.1.5
	 *
	 * @param int                 $account_id Account id.
	 * @param array<string,mixed> $entry      Report entry: `wc_product_id`, `wc_sku`, `relation`, `card`.
	 * @return array<string,mixed>
	 */
	public function near_row( int $account_id, array $entry ): array {
		$card       = (array) ( $entry['card'] ?? [] );
		$offer_id   = (string) ( $card['offer_id'] ?? '' );
		$product_id = (int) ( $entry['wc_product_id'] ?? 0 );

		return [
			'id'           => Remote_Cards_Ids::near_row_id( $account_id, $offer_id, $product_id ),
			'revision'     => '',
			'cells'        => [
				'offer_id' => $offer_id,
				'name'     => (string) ( $card['name'] ?? '' ),
				'ids'      => $this->ids( (array) ( $card['remote_ids'] ?? [] ) ),
				'state'    => $this->state( array_merge( $card, [ 'details_ok' => true ] ) ),
				'on_site'  => array_merge(
					$this->on_site( $product_id ),
					[
						[
							'type'  => 'text',
							/* translators: %s: the article of the shop product. */
							'value' => sprintf( __( 'Артикул на сайте: %s', 'woodev-plugin-framework' ), (string) ( $entry['wc_sku'] ?? '' ) ),
						],
						[
							'type'  => 'text',
							'value' => self::relation_label( (string) ( $entry['relation'] ?? '' ) ),
						],
					]
				),
			],
			'actions'      => [
				[
					'id'      => Remote_Cards_Definition::NEAR_CONFIRM,
					'enabled' => true,
					'reason'  => '',
				],
				[
					'id'      => Remote_Cards_Definition::NEAR_DISMISS,
					'enabled' => true,
					'reason'  => '',
				],
			],
			'selectable'   => false,
			'has_children' => false,
		];
	}

	/**
	 * @return string Why a card that is being imported takes no action.
	 */
	private static function locked_reason(): string {
		return __( 'Карточка сейчас импортируется — дождитесь результата.', 'woodev-plugin-framework' );
	}

	/**
	 * The card's last import note, under its state. The sentence is stored whole; the technical tail is stored apart and asked from the
	 * «Логирование» switch only now, so turning the switch on or off changes this same note both ways.
	 *
	 * @param array{offer_id:string,text:string,detail:string}|null $note The note, if the card has one.
	 * @return array<int,array{type:string,value:string}>
	 */
	private function note( ?array $note ): array {
		if ( null === $note || '' === $note['text'] ) {
			return [];
		}

		$lines = [
			[
				'type'  => 'text',
				'value' => $note['text'],
			],
		];

		$detail = null === $this->logging || '' === $note['detail'] ? '' : $this->logging->hint( $note['detail'] );

		if ( '' !== $detail ) {
			$lines[] = [
				'type'  => 'text',
				'value' => $detail,
			];
		}

		return $lines;
	}

	/**
	 * Says how the two articles of a near match differ.
	 *
	 * @param string $relation {@see Offer_Id_Likeness::CASE} or {@see Offer_Id_Likeness::LOOK_ALIKE}.
	 * @return string
	 */
	private static function relation_label( string $relation ): string {
		return Offer_Id_Likeness::CASE === $relation
			? __( 'Артикулы отличаются регистром букв', 'woodev-plugin-framework' )
			: __( 'Артикулы отличаются похожими буквами или цифрами', 'woodev-plugin-framework' );
	}

	/**
	 * The marketplace's numbers of the card, as «name: value» lines.
	 *
	 * @param array<string,mixed> $remote_ids Identifier name => value.
	 * @return array<int,array{type:string,value:string}>|null Null when the card has none.
	 */
	private function ids( array $remote_ids ): ?array {
		$lines = [];

		foreach ( $remote_ids as $name => $value ) {
			if ( ! is_scalar( $value ) || '' === (string) $value || '0' === (string) $value ) {
				continue;
			}

			$lines[] = [
				'type'  => 'text',
				'value' => $name . ': ' . $value,
			];
		}

		return [] === $lines ? null : array_slice( $lines, 0, 5 );
	}

	/**
	 * The state of the card on the marketplace and, for a declined card, the marketplace's reason. A card whose details did not come
	 * with the list has no state to show.
	 *
	 * @param array<string,mixed> $card Card.
	 * @return array<int,array{type:string,value:mixed}>
	 */
	private function state( array $card ): array {
		if ( empty( $card['details_ok'] ) ) {
			return [
				[
					'type'  => 'badge',
					'value' => [
						'label' => __( 'Неизвестно', 'woodev-plugin-framework' ),
						'tone'  => 'neutral',
					],
				],
			];
		}

		$state = ! empty( $card['archived'] ) ? Link_Record::STATE_ARCHIVED : (string) ( $card['state'] ?? '' );
		$badge = Card_State_Badge::of( $state );
		$items = [
			[
				'type'  => 'badge',
				'value' => [
					'label' => $badge['label'],
					'tone'  => self::TONES[ $badge['tone'] ] ?? 'neutral',
				],
			],
		];

		$reason = trim( (string) ( $card['status_text'] ?? '' ) );

		if ( '' !== $reason ) {
			$items[] = [
				'type'  => 'text',
				'value' => $reason,
			];
		}

		return $items;
	}

	/**
	 * The product that carries the article: a link to its edit screen, its name when the user may not open it, or «—».
	 *
	 * @param int $product_id Product or variation id, 0 for none.
	 * @return array<int,array{type:string,value:mixed}>
	 */
	private function on_site( int $product_id ): array {
		if ( $product_id <= 0 ) {
			return [
				[
					'type'  => 'text',
					'value' => '—',
				],
			];
		}

		$product = call_user_func( $this->describe, $product_id );

		return [
			'' === $product['url']
				? [
					'type'  => 'text',
					'value' => $product['title'],
				]
				: [
					'type'  => 'link',
					'value' => [
						'label' => $product['title'],
						'url'   => $product['url'],
					],
				],
		];
	}

	/**
	 * The actions of a card's row: «Импортировать» (when the table offers it) and «Связать с…».
	 *
	 * @param array<string,mixed> $card      Card.
	 * @param bool                $importing Whether the card is being imported.
	 * @return array<int,array{id:string,enabled:bool,reason:string}>
	 */
	private function actions( array $card, bool $importing ): array {
		$locked = $importing ? self::locked_reason() : '';
		$link   = $this->link_action( $card );

		if ( $importing ) {
			$link['enabled'] = false;
			$link['reason']  = $locked;
		}

		return $this->importable ? [ $this->import_action( $card, $locked ), $link ] : [ $link ];
	}

	/**
	 * «Импортировать» is open for a card no product carries whose details are known and that is not archived; the other cards say why it is not.
	 *
	 * @param array<string,mixed> $card   Card.
	 * @param string              $locked Why the card is locked, or '' when it is not.
	 * @return array{id:string,enabled:bool,reason:string}
	 */
	private function import_action( array $card, string $locked ): array {
		$action = [
			'id'      => Remote_Cards_Definition::IMPORT,
			'enabled' => false,
			'reason'  => $locked,
		];

		if ( '' !== $locked ) {
			return $action;
		}

		if ( (int) ( $card['wc_product_id'] ?? 0 ) > 0 ) {
			$action['reason'] = __( 'Карточка уже на сайте.', 'woodev-plugin-framework' );
		} elseif ( empty( $card['details_ok'] ) ) {
			$action['reason'] = __( 'О карточке нет полных данных. Обновите список.', 'woodev-plugin-framework' );
		} elseif ( ! empty( $card['archived'] ) || Link_Record::STATE_ARCHIVED === (string) ( $card['state'] ?? '' ) ) {
			$action['reason'] = __( 'Карточка в архиве — не импортируем.', 'woodev-plugin-framework' );
		} else {
			$action['enabled'] = true;
		}

		return $action;
	}

	/**
	 * «Связать с…» is open for a card no product carries whose details are known; the other cards say why it is not.
	 *
	 * @param array<string,mixed> $card Card.
	 * @return array{id:string,enabled:bool,reason:string}
	 */
	private function link_action( array $card ): array {
		$action = [
			'id'      => Remote_Cards_Definition::LINK_TO,
			'enabled' => false,
			'reason'  => '',
		];

		if ( (int) ( $card['wc_product_id'] ?? 0 ) > 0 ) {
			$action['reason'] = __( 'Карточка уже связана с товаром сайта.', 'woodev-plugin-framework' );
		} elseif ( empty( $card['details_ok'] ) ) {
			$action['reason'] = __( 'О карточке нет полных данных. Обновите список.', 'woodev-plugin-framework' );
		} else {
			$action['enabled'] = true;
		}

		return $action;
	}
}
