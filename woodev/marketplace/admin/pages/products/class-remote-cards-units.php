<?php
/**
 * The cards of the «Карточки на маркетплейсе» table that have several units.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Activity_Normalizer;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Admin\Shared\Plain_Price;
use Woodev\Framework\Marketplace\Flows\Card_Import;
use Woodev\Framework\Marketplace\Flows\Sellable_Units;
use Woodev\Framework\Marketplace\Flows\Unit_Matcher;

defined( 'ABSPATH' ) || exit;

/**
 * For a marketplace that sells a size inside a card ({@see Sellable_Units}) a card with several units is a **parent row** that sums
 * them and expands into them, the way a variable product does on «Товары сайта»; each unit is the ordinary row of
 * {@see Remote_Cards_Rows}. The parent row offers «Открыть на {marketplace}» and «Импортировать» — the whole card; linking and
 * unlinking are a unit's own.
 *
 * It also words what the last «link by article» run could not settle ({@see self::conflict_note()}).
 *
 * @since 2.1.23
 */
final class Remote_Cards_Units {
	/**
	 * The unit rows, and the cells a card row shares with them.
	 *
	 * @var Remote_Cards_Rows
	 */
	private $rows;

	/**
	 * The cells of a card's photo and name.
	 *
	 * @var Remote_Cards_Cells
	 */
	private $cells;

	/**
	 * The adapter's unit rules.
	 *
	 * @var Sellable_Units
	 */
	private $units;

	/**
	 * Whether the table offers «Импортировать».
	 *
	 * @var bool
	 */
	private $importable;

	/**
	 * Creates the builder.
	 *
	 * @since 2.1.23
	 *
	 * @param Sellable_Units     $units      The adapter's unit rules.
	 * @param Remote_Cards_Rows  $rows       Row builder.
	 * @param Remote_Cards_Cells $cells      Cell builder.
	 * @param bool               $importable Whether the table offers «Импортировать».
	 */
	public function __construct( Sellable_Units $units, Remote_Cards_Rows $rows, Remote_Cards_Cells $cells, bool $importable ) {
		$this->units      = $units;
		$this->rows       = $rows;
		$this->cells      = $cells;
		$this->importable = $importable;
	}

	/**
	 * Builds the parent row of a card.
	 *
	 * @since 2.1.23
	 *
	 * @param int                            $account_id Account id.
	 * @param string                         $ref        Card reference.
	 * @param array<int,array<string,mixed>> $units      Its units, each with `wc_product_id` and, when known, `currency`; at least one.
	 * @param string[]                       $importing  Articles being imported.
	 * @return array<string,mixed>
	 */
	public function parent_row( int $account_id, string $ref, array $units, array $importing ): array {
		$first   = $units[0];
		$article = $this->units->shown_article( (string) $first['offer_id'], $first )->article();
		$on_site = count( array_filter( array_column( $units, 'wc_product_id' ) ) );
		$busy    = [] !== array_intersect( array_column( $units, 'offer_id' ), $importing );

		return [
			'id'           => Remote_Cards_Ids::card_row_id( $account_id, $ref ),
			'revision'     => '',
			'cells'        => [
				'image'    => $this->cells->image( $first ),
				'name'     => $this->cells->name( $first ),
				'offer_id' => $article,
				'state'    => $this->states( $units ),
				'price'    => $this->price_range( $units ),
				'stock'    => $this->stock_sum( $units ),
				'on_site'  => [
					[
						'type'  => 'text',
						/* translators: 1: how many sizes of the card are on the site, 2: how many it has. */
						'value' => sprintf( __( '%1$d из %2$d', 'woodev-plugin-framework' ), $on_site, count( $units ) ),
					],
				],
			],
			'actions'      => $this->actions( $units, $busy ),
			'selectable'   => $this->importable,
			'has_children' => true,
			'activity'     => $busy ? Remote_Cards_Rows::importing_activity() : Activity_Normalizer::idle(),
		];
	}

	/**
	 * Words a conflict of the last «link by article» run as the note of the card's state badge.
	 *
	 * @since 2.1.23
	 *
	 * @param array<string,mixed> $conflict `offer_id`, `reason`, `product_ids`.
	 * @return array{offer_id:string,text:string,detail:string}
	 */
	public function conflict_note( array $conflict ): array {
		$product_ids = (array) $conflict['product_ids'];
		$text        = Unit_Matcher::CONTESTED === $conflict['reason']
			? sprintf(
				/* translators: %s: number of a product. */
				__( 'Подходящий товар №%s подходит и другой карточке. Свяжите нужный вручную: «Связать с товаром…».', 'woodev-plugin-framework' ),
				(string) ( $product_ids[0] ?? '' )
			)
			: Card_Import::ambiguous_text( $product_ids );

		return [
			'offer_id' => (string) $conflict['offer_id'],
			'text'     => $text,
			'detail'   => '',
		];
	}

	/**
	 * One badge per state among the units, with how many are in it, the biggest group first.
	 *
	 * @param array<int,array<string,mixed>> $units Units.
	 * @return array<int,array<string,mixed>>
	 */
	private function states( array $units ): array {
		$badges = [];

		foreach ( $units as $unit ) {
			$badge = $this->rows->state_badge( $unit );
			$label = $badge['label'];

			$badges[ $label ]          = $badges[ $label ] ?? $badge + [ 'count' => 0 ];
			$badges[ $label ]['count'] += 1;
		}

		uasort(
			$badges,
			static function ( array $one, array $other ): int {
				return $other['count'] <=> $one['count'];
			}
		);

		$cell = [];

		foreach ( array_slice( $badges, 0, Row_Normalizer::MAX_STACK_ITEMS ) as $badge ) {
			$cell[] = [
				'type'  => 'badge',
				'value' => [
					'label' => $badge['label'] . ' · ' . $badge['count'],
					'tone'  => $badge['tone'],
				],
			];
		}

		return $cell;
	}

	/**
	 * The lowest and the highest known price of the units, one number when they are the same.
	 *
	 * @param array<int,array<string,mixed>> $units Units.
	 * @return string
	 */
	private function price_range( array $units ): string {
		$prices = array_map( 'floatval', array_filter( array_column( $units, 'price' ), 'is_numeric' ) );

		if ( [] === $prices ) {
			return '—';
		}

		$currency = trim( (string) ( $units[0]['currency'] ?? '' ) );
		$lowest   = Plain_Price::format( (string) min( $prices ), $currency );

		return min( $prices ) === max( $prices ) ? $lowest : $lowest . ' – ' . Plain_Price::format( (string) max( $prices ), $currency );
	}

	/**
	 * The units' known stock summed.
	 *
	 * @param array<int,array<string,mixed>> $units Units.
	 * @return string
	 */
	private function stock_sum( array $units ): string {
		$stocks = array_filter( array_column( $units, 'stock' ), 'is_numeric' );

		return [] === $stocks ? '—' : (string) array_sum( $stocks );
	}

	/**
	 * «Открыть на {marketplace}» and, while a unit can be imported, «Импортировать».
	 *
	 * @param array<int,array<string,mixed>> $units Units.
	 * @param bool                           $busy  Whether a unit is being imported.
	 * @return array<int,array<string,mixed>>
	 */
	private function actions( array $units, bool $busy ): array {
		$actions = $this->rows->open_remote_actions( $units[0] );
		$import  = null;

		foreach ( $units as $unit ) {
			if ( ! $this->importable || ! empty( $unit['wc_product_id'] ) ) {
				continue;
			}

			$own    = $this->rows->import_action( $unit, $busy ? Remote_Cards_Rows::locked_reason() : '' );
			$import = null !== $import && $import['enabled'] ? $import : $own;
		}

		return null === $import ? $actions : array_merge( $actions, [ $import ] );
	}
}
