<?php
/**
 * Rows of the «Карточки на маркетплейсе» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Admin\Product\Card_State_Badge;
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
	 * Creates the builder.
	 *
	 * @since 2.1.5
	 *
	 * @param callable $describe `function ( int $product_id ): array{title:string,url:string}`.
	 */
	public function __construct( callable $describe ) {
		$this->describe = $describe;
	}

	/**
	 * Builds a row.
	 *
	 * @since 2.1.5
	 *
	 * @param int                 $account_id Account id.
	 * @param array<string,mixed> $card       Snapshot card with `wc_product_id` (0 when no product carries it).
	 * @return array<string,mixed>
	 */
	public function row( int $account_id, array $card ): array {
		$offer_id = (string) ( $card['offer_id'] ?? '' );

		return [
			'id'           => Remote_Cards_Ids::row_id( $account_id, $offer_id ),
			'revision'     => '',
			'cells'        => [
				'offer_id' => $offer_id,
				'name'     => (string) ( $card['name'] ?? '' ),
				'ids'      => $this->ids( (array) ( $card['remote_ids'] ?? [] ) ),
				'state'    => $this->state( $card ),
				'on_site'  => $this->on_site( (int) ( $card['wc_product_id'] ?? 0 ) ),
			],
			'actions'      => [ $this->link_action( $card ) ],
			'selectable'   => false,
			'has_children' => false,
		];
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
