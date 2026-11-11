<?php
/**
 * Request-time side of the card export.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Bulk_Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Jobs\Export_Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * What the products screen, the product tab and the auto-export rule call. It never calls the marketplace: it writes link rows
 * and queues `export_batch` actions.
 *
 * - {@see self::queue()}: expands variable products into their variations (those the marketplace does not sell, as
 *   {@see Export_Facts::is_exportable()} says, are skipped), finds or creates each offer row, validates it with the same inputs
 *   the job will build from, stores the report on a blocked link and queues the valid ones (`unlinked` → `queued`) in batches.
 * - {@see self::reset()}: «Сбросить состояние», the link back to `unlinked` without touching the marketplace.
 *
 * @since 2.0.30
 */
final class Export_Service {
	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Export inputs.
	 *
	 * @var Export_Context_Builder
	 */
	private $contexts;

	/**
	 * Scheduler.
	 *
	 * @var Export_Scheduler
	 */
	private $scheduler;

	/**
	 * The marketplace's facts, for the question which products become cards.
	 *
	 * @var Export_Facts
	 */
	private $facts;

	/**
	 * Validator with the marketplace's rules.
	 *
	 * @var Export_Validator
	 */
	private $validator;

	/**
	 * Creates the service.
	 *
	 * @since 2.0.30
	 *
	 * @param Wpdb_Link_Store        $links     Links.
	 * @param Export_Context_Builder $contexts  Export inputs.
	 * @param Export_Scheduler       $scheduler Scheduler.
	 * @param Export_Facts           $facts     The marketplace's facts.
	 * @param Export_Validator       $validator Validator with the marketplace's rules.
	 */
	public function __construct( Wpdb_Link_Store $links, Export_Context_Builder $contexts, Export_Scheduler $scheduler, Export_Facts $facts, Export_Validator $validator ) {
		$this->links     = $links;
		$this->contexts  = $contexts;
		$this->scheduler = $scheduler;
		$this->facts     = $facts;
		$this->validator = $validator;
	}

	/**
	 * Validates products and queues the valid ones for export.
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account     Account the cards go to.
	 * @param int[]   $product_ids Products or variations; a variable product stands for its variations.
	 * @return Bulk_Validation_Report Keyed by the product or variation id. A valid product is not always queued: the store refuses a
	 *                                card that is being submitted or is archived; {@see self::enqueue()} says which ones were.
	 * @throws \RuntimeException When a link cannot be stored or Action Scheduler refuses a batch.
	 */
	public function queue( Account $account, array $product_ids ): Bulk_Validation_Report {
		return $this->enqueue( $account, $product_ids )['report'];
	}

	/**
	 * Validates products, queues the valid ones and reports what the queue really accepted.
	 *
	 * The link store decides (`queue()` refuses a card that is `submitted` or `archived`), so «valid» and «queued» are different
	 * sets: the products screen must count the second.
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account     Account the cards go to.
	 * @param int[]   $product_ids Products or variations; a variable product stands for its variations.
	 * @return array{report:Bulk_Validation_Report,queued:int[],refused:array<int,string>} `queued`: product ids whose link is now
	 *         `queued`; `refused`: valid product id => the card state that kept it out.
	 * @throws \RuntimeException When a link cannot be stored or Action Scheduler refuses a batch.
	 */
	public function enqueue( Account $account, array $product_ids ): array {
		$bulk     = new Bulk_Validation_Report();
		$link_ids = [];
		$queued   = [];
		$refused  = [];

		foreach ( $this->offers( $product_ids ) as $product ) {
			$link    = $this->links->find( $account->id(), (int) $product->get_id() );
			$context = $this->contexts->build( $account, $product, $link );
			$report  = $this->validator->validate( $product, $context->mapping(), $context->profile(), $context->raw_context() );
			$article = (string) $context->raw_context()['frozen_offer_id'];
			$link    = $link ?? $this->create_link( $account, $product, $article );

			$bulk->add( (int) $product->get_id(), $report );

			if ( null === $link ) {
				continue;
			}

			if ( ! $report->is_valid() ) {
				$first = (array) $report->first_error();
				$this->links->record_blocked( (int) $link->link_id(), (string) ( $first['code'] ?? '' ), implode( "\n", array_column( $report->errors(), 'message' ) ) );
				continue;
			}

			if ( $this->links->queue( (int) $link->link_id(), $article ) ) {
				$link_ids[] = (int) $link->link_id();
				$queued[]   = (int) $product->get_id();
			} else {
				$refused[ (int) $product->get_id() ] = $link->card_state();
			}
		}

		if ( [] !== $link_ids ) {
			$this->scheduler->queue_export( $account->id(), $link_ids );
		}

		return [
			'report'  => $bulk,
			'queued'  => $queued,
			'refused' => $refused,
		];
	}

	/**
	 * Validates products exactly as {@see self::queue()} would and writes nothing: the report of the confirmation window on the
	 * products screen (the report comes first, the queue after the merchant agrees).
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account     Account the cards would go to.
	 * @param int[]   $product_ids Products or variations; a variable product stands for its variations.
	 * @return Bulk_Validation_Report Keyed by the product or variation id.
	 */
	public function preview( Account $account, array $product_ids ): Bulk_Validation_Report {
		$bulk = new Bulk_Validation_Report();

		foreach ( $this->offers( $product_ids ) as $product ) {
			$context = $this->contexts->build( $account, $product, $this->links->find( $account->id(), (int) $product->get_id() ) );

			$bulk->add( (int) $product->get_id(), $this->validator->validate( $product, $context->mapping(), $context->profile(), $context->raw_context() ) );
		}

		return $bulk;
	}

	/**
	 * «Сбросить состояние»: puts the products' links back to `unlinked`; nothing is sent to the marketplace.
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account     Account.
	 * @param int[]   $product_ids Products or variations; a variable product stands for its variations.
	 * @return int Links reset.
	 */
	public function reset( Account $account, array $product_ids ): int {
		$link_ids = [];

		foreach ( $this->offers( $product_ids, true ) as $product ) {
			$link = $this->links->find( $account->id(), (int) $product->get_id() );

			if ( null !== $link && $link->is_offer() ) {
				$link_ids[] = (int) $link->link_id();
			}
		}

		return $this->links->reset_state( $account->id(), $link_ids );
	}

	/**
	 * Expands the selection into sellable products.
	 *
	 * @param int[] $product_ids Selected ids.
	 * @param bool  $keep_all    Keep the products the marketplace does not sell too (a reset must reach every link).
	 * @return \WC_Product[]
	 */
	private function offers( array $product_ids, bool $keep_all = false ): array {
		$offers = [];

		foreach ( array_unique( array_map( 'intval', $product_ids ) ) as $product_id ) {
			$product = $this->contexts->product( $product_id );

			if ( null === $product ) {
				continue;
			}

			$children = $product->is_type( 'variable' ) ? array_map( 'intval', (array) $product->get_children() ) : [];
			$members  = [] === $children ? [ $product ] : array_filter( array_map( [ $this->contexts, 'product' ], $children ) );

			foreach ( $members as $member ) {
				if ( $keep_all || $this->facts->is_exportable( $member ) ) {
					$offers[ (int) $member->get_id() ] = $member;
				}
			}
		}

		return array_values( $offers );
	}

	/**
	 * Creates the offer row of a product seen for the first time, with stock and price sync on (the merchant exports to sell). When
	 * the article is taken by another product, no row can exist (the article is unique per account); the report already says so
	 * through `offer_id_taken`.
	 *
	 * @param Account     $account  Account.
	 * @param \WC_Product $product  Product.
	 * @param string      $offer_id Article it will be sent with.
	 * @return Link_Record|null
	 * @throws \RuntimeException When the database refuses the insert.
	 */
	private function create_link( Account $account, \WC_Product $product, string $offer_id ): ?Link_Record {
		$record = Link_Record::offer(
			$account->id(),
			(int) $product->get_id(),
			(int) $product->get_parent_id(),
			$offer_id,
			[
				'card_state' => Link_Record::STATE_UNLINKED,
				'sync_stock' => 1,
				'sync_price' => 1,
			]
		);

		$this->links->insert_if_absent( $record );

		return $this->links->find( $account->id(), (int) $product->get_id() );
	}
}
