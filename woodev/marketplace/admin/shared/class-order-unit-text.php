<?php
/**
 * The words a screen uses for one unit of a grouped order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Framework\Marketplace\Flows\Article_Display;
use Woodev\Framework\Marketplace\Flows\Order_Unit;
use Woodev\Framework\Marketplace\Flows\Sellable_Units;

defined( 'ABSPATH' ) || exit;

/**
 * The one reading of an {@see Order_Unit} the order screen and the «Заказы» table share: its goods, article, status and amount.
 * The marketplace's unit keys and status codes never reach the merchant: the article comes through {@see Article_Display}, the
 * status through the adapter's names.
 *
 * @since 2.1.28
 */
final class Order_Unit_Text {
	/**
	 * @var Posting_Status_Names|null
	 */
	private $status_names;

	/**
	 * @var Article_Display
	 */
	private $articles;

	/**
	 * @since 2.1.28
	 *
	 * @param Posting_Status_Names|null $status_names The adapter's names of statuses, when it has them.
	 * @param Article_Display           $articles     How an article reads.
	 */
	public function __construct( ?Posting_Status_Names $status_names, Article_Display $articles ) {
		$this->status_names = $status_names;
		$this->articles     = $articles;
	}

	/**
	 * Builds the reading of a plugin's units over its adapter and stored cards list.
	 *
	 * @since 2.1.28
	 *
	 * @param Marketplace_Adapter $adapter   The plugin's adapter.
	 * @param \wpdb               $db        Database.
	 * @param string              $plugin_id Plugin id.
	 * @return self
	 */
	public static function for_adapter( Marketplace_Adapter $adapter, \wpdb $db, string $plugin_id ): self {
		$articles = new Article_Display();

		if ( $adapter instanceof Sellable_Units ) {
			$schema   = new Schema( $adapter->id() );
			$cards    = new Wpdb_Card_Store( $db, $schema->table_name( 'cards', $db->prefix ), $schema->table_name( 'links', $db->prefix ), Uninstaller::option_prefix( $plugin_id ) );
			$articles = new Article_Display( $adapter, $cards );
		}

		return new self( $adapter instanceof Posting_Status_Names ? $adapter : null, $articles );
	}

	/**
	 * Returns the goods of a unit.
	 *
	 * @since 2.1.28
	 *
	 * @param Order_Unit $unit Unit.
	 * @return string
	 */
	public function name( Order_Unit $unit ): string {
		$names = [];

		foreach ( $unit->lines() as $line ) {
			if ( '' !== $line['name'] ) {
				$names[] = $line['quantity'] > 1 ? $line['name'] . ' × ' . $line['quantity'] : $line['name'];
			}
		}

		return [] === $names ? __( 'Позиция', 'woodev-plugin-framework' ) : implode( ', ', $names );
	}

	/**
	 * Returns the article of a unit, with what tells it apart (a size) beside it.
	 *
	 * @since 2.1.28
	 *
	 * @param int        $account_id Account of the order.
	 * @param Order_Unit $unit       Unit.
	 * @return string '' for a unit without an article.
	 */
	public function article( int $account_id, Order_Unit $unit ): string {
		$articles = [];

		foreach ( $unit->lines() as $line ) {
			$articles[] = $this->articles->of_link( $account_id, $line['offer_id'] );
		}

		return implode( ', ', array_filter( $articles ) );
	}

	/**
	 * Returns the status badge of a unit: «Отменено» for a cancelled one, else the marketplace's status.
	 *
	 * @since 2.1.28
	 *
	 * @param Order_Unit $unit Unit.
	 * @return array{label:string,tone:string}
	 */
	public function status( Order_Unit $unit ): array {
		if ( $unit->is_cancelled() ) {
			return [
				'label' => self::cancelled(),
				'tone'  => 'danger',
			];
		}

		return [
			'label' => Posting_Status_Text::label( $unit->status_raw(), $this->status_names ),
			'tone'  => Posting_Status_Text::tone( $unit->status_raw() ),
		];
	}

	/**
	 * Returns what the marketplace calls the status of a cancelled unit, when it says more than «Отменено» (who cancelled it).
	 *
	 * @since 2.1.28
	 *
	 * @param Order_Unit $unit Unit.
	 * @return string '' for a live unit or a status with nothing to add.
	 */
	public function cancellation( Order_Unit $unit ): string {
		if ( ! $unit->is_cancelled() || null === $this->status_names ) {
			return '';
		}

		$name = trim( $this->status_names->posting_status_name( $unit->status_raw() ) );

		return $name === $unit->status_raw() || self::cancelled() === $name ? '' : $name;
	}

	/**
	 * Returns the price of a unit as the shop writes a price.
	 *
	 * @since 2.1.28
	 *
	 * @param Order_Unit $unit Unit.
	 * @return string '' for a unit without a price.
	 */
	public function amount( Order_Unit $unit ): string {
		$total = $unit->total();

		return null === $total ? '' : Plain_Price::format( $total->to_string(), $total->currency() );
	}

	/**
	 * @return string The word a cancelled unit carries.
	 */
	private static function cancelled(): string {
		return __( 'Отменено', 'woodev-plugin-framework' );
	}
}
