<?php
/**
 * The actions of the «Карточки на маркетплейсе» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Jobs\Import_Scheduler;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Plans and does the actions of the table. **None of them calls the marketplace**: «Обновить список», «Связать по артикулу» and
 * «Импортировать» start a background job, and the other three write our own link table or the near-match report.
 *
 * | Action | What it does | Who may |
 * |---|---|---|
 * | `import`, `import_selected` | «Импортировать»: sorts the selected cards ({@see Remote_Cards_Import_Plan}) and queues the eligible ones on {@see Import_Scheduler}; the job itself reads the marketplace | `manage_woocommerce` |
 * | `import_selected` over every card under the filter | «Импортировать все по фильтру»: stores a bulk run and queues its first tick; the cards are frozen and fed to the import job in the background ({@see Remote_Cards_Run_Actions}) | `manage_woocommerce` |
 * | `bulk_stop`, `bulk_report` | the buttons of the account line of a bulk run | `manage_woocommerce` |
 * | `refresh_list` | marks the list as being refreshed and queues the job that refreshes it | `manage_woocommerce` |
 * | `link_by_article` | the account-wide job of {@see Site_Products_Bulk}, the same as on the products table | `manage_woocommerce` |
 * | `link_to` | links the one card to the product the merchant picked ({@see Card_Linker}); a link settles the pair in the near-match report | `edit_products`, and `edit_post` on the product |
 * | `near_confirm` | «Это тот же товар»: links a pair of the near-match report from the report's own copy of the card | `edit_products`, and `edit_post` on the product |
 * | `near_dismiss` | «Нет, это разные»: takes the pair out of the report and keeps the answer ({@see Link_Report_Store::dismiss_near_match()}) | `edit_products`, and `edit_post` on the product |
 *
 * The card for `link_to` is read from **this account's** stored list, never from the request: a row id names a card by the key of its
 * article, and a card that is not in the list any more (a refresh found it deleted) is refused as a stale list.
 *
 * `link_to`, `near_confirm` and `near_dismiss` act on one card: the table lets the merchant select many rows for «Импортировать», so each of
 * them refuses more than one id, at preview and at apply, instead of acting on the first.
 *
 * Both of the linking actions are previewed and then replanned at apply, as every action of the records engine is.
 *
 * @since 2.1.5
 */
final class Remote_Cards_Actions {
	/**
	 * @var Wpdb_Card_Store
	 */
	private $cards;

	/**
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * @var Link_Report_Store
	 */
	private $reports;

	/**
	 * @var Card_Linker
	 */
	private $linker;

	/**
	 * @var Link_Scheduler
	 */
	private $scheduler;

	/**
	 * @var Site_Products_Bulk
	 */
	private $bulk;

	/**
	 * @var License_Gate
	 */
	private $license;

	/**
	 * @var Remote_Cards_Products
	 */
	private $products;

	/**
	 * @var Remote_Cards_Rows
	 */
	private $rows;

	/**
	 * Queues the import job; null when the adapter cannot import cards.
	 *
	 * @var Import_Scheduler|null
	 */
	private $importer;

	/**
	 * Decides what the import does with each selected card; null when the adapter cannot import cards.
	 *
	 * @var Remote_Cards_Import_Plan|null
	 */
	private $plan;

	/**
	 * The import of every card under a filter and the buttons of its account line; null when the adapter cannot import cards.
	 *
	 * @var Remote_Cards_Run_Actions|null
	 */
	private $runs;

	/**
	 * Creates the actions.
	 *
	 * @since 2.1.5
	 *
	 * @param Wpdb_Card_Store               $cards     The stored list of the cabinet's cards.
	 * @param Wpdb_Link_Store               $links     Links.
	 * @param Link_Report_Store             $reports   The near-match report, which a link settles.
	 * @param Card_Linker                   $linker    The one place that writes a card's link.
	 * @param Link_Scheduler                $scheduler Queues the jobs.
	 * @param Site_Products_Bulk            $bulk      The products table's actions, for «Связать по артикулу».
	 * @param License_Gate                  $license   Licence gate.
	 * @param Remote_Cards_Products         $products  The shop's products.
	 * @param Remote_Cards_Rows             $rows      Row builder, for the row an action changed.
	 * @param Import_Scheduler|null         $importer  Queues the import job, null when the adapter cannot import cards.
	 * @param Remote_Cards_Import_Plan|null $plan Sorts the cards of an import, null when the adapter cannot import cards.
	 * @param Remote_Cards_Run_Actions|null $runs The import by filter and its account line, null when the adapter cannot import cards.
	 */
	public function __construct( Wpdb_Card_Store $cards, Wpdb_Link_Store $links, Link_Report_Store $reports, Card_Linker $linker, Link_Scheduler $scheduler, Site_Products_Bulk $bulk, License_Gate $license, Remote_Cards_Products $products, Remote_Cards_Rows $rows, ?Import_Scheduler $importer = null, ?Remote_Cards_Import_Plan $plan = null, ?Remote_Cards_Run_Actions $runs = null ) {
		$this->cards     = $cards;
		$this->links     = $links;
		$this->reports   = $reports;
		$this->linker    = $linker;
		$this->scheduler = $scheduler;
		$this->bulk      = $bulk;
		$this->license   = $license;
		$this->products  = $products;
		$this->rows      = $rows;
		$this->importer  = $importer;
		$this->plan      = $plan;
		$this->runs      = $runs;
	}

	/**
	 * The plan of an action. Writes nothing.
	 *
	 * @since 2.1.5
	 *
	 * @param Account             $account Account.
	 * @param string              $action  Action id.
	 * @param string[]            $ids     Row ids (one for `link_to`, many for «Импортировать», none for an account action).
	 * @param array<string,mixed> $payload Validated fields.
	 * @return array<string,mixed> The preview answer of the records engine.
	 * @throws Action_State_Exception When the licence is not in force, or a one-card action got several rows.
	 * @throws Action_Input_Exception When the picked product cannot take a card, or the category of the linking is not a product category.
	 */
	public function preview( Account $account, string $action, array $ids, array $payload ): array {
		$this->require_one_card( $action, $ids );
		$this->require_licence( $action );

		switch ( $action ) {
			case Remote_Cards_Definition::IMPORT:
			case Remote_Cards_Definition::IMPORT_SELECTED:
				return $this->preview_import( $account, $ids );
			case Remote_Cards_Definition::LINK_TO:
				return $this->preview_link( $account, (string) ( $ids[0] ?? '' ), $payload );
			case Remote_Cards_Definition::NEAR_CONFIRM:
			case Remote_Cards_Definition::NEAR_DISMISS:
				return $this->preview_near( $account, $action, (string) ( $ids[0] ?? '' ) );
			case Remote_Cards_Definition::REFRESH:
				return $this->preview_refresh( $account );
			case Site_Products_Bulk::RUN_STOP:
			case Site_Products_Bulk::RUN_REPORT:
				return $this->run_actions()->preview_line_action( $account, $action, $payload );
			default:
				return $this->bulk->preview( $account, $action, [], [], null, $this->bulk->link_category( $payload ) );
		}
	}

	/**
	 * Replans from the current state and does the action.
	 *
	 * @since 2.1.5
	 *
	 * @param Account             $account Account.
	 * @param string              $action  Action id.
	 * @param string[]            $ids     Row ids.
	 * @param array<string,mixed> $payload Validated fields.
	 * @return array<string,mixed> The apply answer of the records engine.
	 * @throws Action_State_Exception When the licence is not in force, a one-card action got several rows, or the queue refuses the job.
	 * @throws Action_Input_Exception When the picked product cannot take a card, or the category of the linking is not a product category.
	 */
	public function apply( Account $account, string $action, array $ids, array $payload ): array {
		$this->require_one_card( $action, $ids );
		$this->require_licence( $action );

		switch ( $action ) {
			case Remote_Cards_Definition::IMPORT:
			case Remote_Cards_Definition::IMPORT_SELECTED:
				return $this->apply_import( $account, $ids );
			case Remote_Cards_Definition::LINK_TO:
				return $this->apply_link( $account, (string) ( $ids[0] ?? '' ), $payload );
			case Remote_Cards_Definition::NEAR_CONFIRM:
				return $this->apply_near_confirm( $account, (string) ( $ids[0] ?? '' ) );
			case Remote_Cards_Definition::NEAR_DISMISS:
				return $this->apply_near_dismiss( $account, (string) ( $ids[0] ?? '' ) );
			case Remote_Cards_Definition::REFRESH:
				return $this->apply_refresh( $account );
			case Site_Products_Bulk::RUN_STOP:
				return $this->run_actions()->apply_stop( $account, $payload );
			default:
				return $this->bulk->apply( $account, $action, [], [], null, $this->bulk->link_category( $payload ) );
		}
	}

	/**
	 * The plan of an action over every card under the filter. Writes nothing.
	 *
	 * @since 2.1.10
	 *
	 * @param Account                                          $account Account.
	 * @param string                                           $action  Action id: the import.
	 * @param array{search:string,filters:array<string,mixed>} $query   Validated filter and search.
	 * @return array<string,mixed> The preview answer of the records engine.
	 * @throws Action_State_Exception When the import may not start.
	 */
	public function preview_matching( Account $account, string $action, array $query ): array {
		$this->require_import( $action );

		return $this->run_actions()->preview_matching( $account, $query );
	}

	/**
	 * Starts the action over every card under the filter. Nothing is imported in the request.
	 *
	 * @since 2.1.10
	 *
	 * @param Account                                          $account Account.
	 * @param string                                           $action  Action id: the import.
	 * @param array{search:string,filters:array<string,mixed>} $query   Validated filter and search.
	 * @return array<string,mixed> The apply answer of the records engine.
	 * @throws Action_State_Exception When the import may not start, or the queue refuses the run.
	 */
	public function apply_matching( Account $account, string $action, array $query ): array {
		$this->require_import( $action );

		return $this->run_actions()->apply_matching( $account, $query );
	}

	/**
	 * The bulk run's part of the account line, or null when the table has none.
	 *
	 * @since 2.1.10
	 *
	 * @param int $account_id Account.
	 * @param int $now        Unix time.
	 * @return array<string,mixed>|null
	 */
	public function run_line( int $account_id, int $now ): ?array {
		return null === $this->runs ? null : $this->runs->line( $account_id, $now );
	}

	/**
	 * Whether the account's bulk run is the import of cards by filter, still going.
	 *
	 * @since 2.1.10
	 *
	 * @param int $account_id Account.
	 * @return bool
	 */
	public function is_feeding_import( int $account_id ): bool {
		return null !== $this->runs && $this->runs->is_feeding( $account_id );
	}

	/**
	 * @return Remote_Cards_Run_Actions
	 * @throws Action_State_Exception When the adapter cannot import cards, so the table has no bulk run.
	 */
	private function run_actions(): Remote_Cards_Run_Actions {
		if ( null === $this->runs ) {
			throw new Action_State_Exception( __( 'Этот маркетплейс не умеет импортировать карточки.', 'woodev-plugin-framework' ) );
		}

		return $this->runs;
	}

	/**
	 * Only «Импортировать» runs over every card under a filter on this table.
	 *
	 * @param string $action Action id.
	 * @return void
	 * @throws Action_State_Exception When the action is another one.
	 */
	private function require_import( string $action ): void {
		if ( Remote_Cards_Definition::IMPORT_SELECTED !== $action ) {
			throw new Action_State_Exception( __( 'Это действие нельзя выполнить для всех карточек по фильтру.', 'woodev-plugin-framework' ) );
		}
	}

	/**
	 * What «Импортировать» will do with the selected cards. Reads the stored list, the links, the shop's products and the import queue:
	 * nothing is written and nothing is sent to the marketplace.
	 *
	 * @param Account  $account Account.
	 * @param string[] $ids     Row ids.
	 * @return array<string,mixed>
	 */
	private function preview_import( Account $account, array $ids ): array {
		$plan     = $this->import_plan( $account, $ids );
		$eligible = count( $plan[ Remote_Cards_Import_Plan::CREATE ] ) + count( $plan[ Remote_Cards_Import_Plan::LINK ] );
		$skipped  = $this->skipped_count( $plan );

		return [
			'requested'      => $eligible + $skipped,
			'eligible'       => $eligible,
			'skipped'        => $skipped,
			'scope_sentence' => $this->import_sentence( $account, $plan ),
			'groups'         => $this->import_groups( $plan ),
		];
	}

	/**
	 * Replans from the current state, then queues the eligible cards on the import job. The job reads the marketplace, not this request.
	 *
	 * @param Account  $account Account.
	 * @param string[] $ids     Row ids.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When Action Scheduler refuses the job.
	 */
	private function apply_import( Account $account, array $ids ): array {
		$plan     = $this->import_plan( $account, $ids );
		$offers   = array_merge( $plan[ Remote_Cards_Import_Plan::CREATE ], $plan[ Remote_Cards_Import_Plan::LINK ] );
		$skipped  = $this->skipped_count( $plan );
		$groups   = $this->import_groups( $plan );
		$result   = [
			'requested' => count( $offers ) + $skipped,
			'accepted'  => 0,
			'skipped'   => $skipped,
			'failed'    => 0,
			'groups'    => $groups,
		];

		if ( [] === $offers ) {
			return $result;
		}

		try {
			$this->importer->import( $account->id(), $offers );
		} catch ( Persistence_Exception $e ) {
			throw new Action_State_Exception( __( 'Не удалось поставить импорт в очередь. Проверьте, что в WooCommerce работает планировщик задач (Action Scheduler), и повторите.', 'woodev-plugin-framework' ) );
		}

		return array_merge(
			$result,
			[
				'accepted' => count( $offers ),
				'queued'   => true,
				'rows'     => $this->locked_rows( $account, $offers ),
				'messages' => [ 'action_status_queued' => __( 'Импорт поставлен в очередь. Товары появятся на сайте в течение нескольких минут — обновите страницу чуть позже. Ход работы виден в строках: пока карточка импортируется, она заблокирована.', 'woodev-plugin-framework' ) ],
			]
		);
	}

	/**
	 * Sorts the selected cards from local facts.
	 *
	 * @param Account  $account Account.
	 * @param string[] $ids     Row ids.
	 * @return array{create:string[],link:string[],skipped:array<string,string[]>,products:int,joined:int}
	 */
	private function import_plan( Account $account, array $ids ): array {
		$keys = [];

		foreach ( array_values( array_unique( $ids ) ) as $row_id ) {
			$keys[] = Remote_Cards_Ids::key_of( (string) $row_id, $account->id() );
		}

		$found = $this->cards->find_many( $account->id(), array_filter( $keys ) );
		$cards = [];

		foreach ( $keys as $key ) {
			$cards[] = null === $key ? null : $found[ $key ] ?? null;
		}

		return $this->plan->build( $cards, Remote_Cards_Query::linked_offers( $this->links->identity_map( $account->id() ) ), $this->importer->pending_offer_ids( $account->id() ), $account->id() );
	}

	/**
	 * @param array{create:string[],link:string[],skipped:array<string,string[]>,products:int,joined:int} $plan Plan.
	 * @return int
	 */
	private function skipped_count( array $plan ): int {
		return array_sum( array_map( 'count', $plan['skipped'] ) );
	}

	/**
	 * «Будет создано товаров: N. Товар с таким артикулом уже есть, свяжем: M.» The cards of one group are counted as the one
	 * variable product they become, by the plan's own count.
	 *
	 * @param Account                                                                                     $account Account.
	 * @param array{create:string[],link:string[],skipped:array<string,string[]>,products:int,joined:int} $plan    Plan.
	 * @return string
	 */
	private function import_sentence( Account $account, array $plan ): string {
		$parts = [];

		if ( $plan['products'] > 0 ) {
			/* translators: %d: number of products that will be created. */
			$parts[] = sprintf( __( 'Будет создано товаров: %d.', 'woodev-plugin-framework' ), $plan['products'] );
		}

		if ( count( $plan[ Remote_Cards_Import_Plan::CREATE ] ) > $plan['products'] + $plan['joined'] ) {
			$parts[] = __( 'Карточки одной группы станут вариантами одного товара.', 'woodev-plugin-framework' );
		}

		if ( $plan['joined'] > 0 ) {
			/* translators: %d: number of cards that will become variations of products that are on the site already. */
			$parts[] = sprintf( __( 'Станут вариантами уже импортированных товаров: %d.', 'woodev-plugin-framework' ), $plan['joined'] );
		}

		if ( [] !== $plan[ Remote_Cards_Import_Plan::LINK ] ) {
			/* translators: %d: number of cards that will be linked to existing products. */
			$parts[] = sprintf( __( 'Товар с таким артикулом уже есть, свяжем: %d.', 'woodev-plugin-framework' ), count( $plan[ Remote_Cards_Import_Plan::LINK ] ) );
		}

		$parts[] = __( 'Импорт идёт в фоне.', 'woodev-plugin-framework' );

		// A product the import creates holds no managed stock and gets its price from the card, so only a card linked to a product that is already there can send anything.
		$parts[] = [] === $plan[ Remote_Cards_Import_Plan::LINK ] ? $this->unchanged() : $this->linker->remote_effect( $account, $this->unchanged() );

		return implode( ' ', $parts );
	}

	/**
	 * What a confirmation says when linking sends nothing to the marketplace.
	 *
	 * @return string
	 */
	private function unchanged(): string {
		return __( 'На маркетплейсе ничего не меняется.', 'woodev-plugin-framework' );
	}

	/**
	 * The cards the import leaves out, one group per reason.
	 *
	 * @param array{create:string[],link:string[],skipped:array<string,string[]>,products:int,joined:int} $plan Plan.
	 * @return array<int,array<string,mixed>>
	 */
	private function import_groups( array $plan ): array {
		$reasons = [
			Remote_Cards_Import_Plan::ON_SITE         => __( 'Уже на сайте', 'woodev-plugin-framework' ),
			Remote_Cards_Import_Plan::IMPORTING       => __( 'Уже импортируется — дождитесь результата', 'woodev-plugin-framework' ),
			Remote_Cards_Import_Plan::GROUP_BUILDING  => __( 'Импорт другой карточки этой группы не завершён — сначала импортируйте её', 'woodev-plugin-framework' ),
			Remote_Cards_Import_Plan::VARIABLE_PARENT => __( 'Товар с таким артикулом — вариативный: выберите вариант через «Связать с…»', 'woodev-plugin-framework' ),
			Remote_Cards_Import_Plan::ARCHIVED        => __( 'Карточка в архиве — не импортируем', 'woodev-plugin-framework' ),
			Remote_Cards_Import_Plan::INCOMPLETE      => __( 'Нет полных данных — обновите список', 'woodev-plugin-framework' ),
			Remote_Cards_Import_Plan::NOT_IN_LIST     => __( 'Нет в загруженном списке — обновите список', 'woodev-plugin-framework' ),
		];
		$groups  = [];

		foreach ( $reasons as $outcome => $reason ) {
			$offers = $plan['skipped'][ $outcome ] ?? [];

			if ( [] !== $offers ) {
				$groups[] = [
					'kind'     => 'skipped',
					'reason'   => $reason,
					'count'    => count( $offers ),
					'examples' => array_slice( array_values( array_filter( $offers, 'strlen' ) ), 0, 3 ),
				];
			}
		}

		return $groups;
	}

	/**
	 * The rows of the queued cards, locked, so the table shows the import at once.
	 *
	 * @param Account  $account Account.
	 * @param string[] $offers  Articles that were queued.
	 * @return array<int,array<string,mixed>>
	 */
	private function locked_rows( Account $account, array $offers ): array {
		$found = $this->cards->find_many( $account->id(), array_map( [ Remote_Cards_Ids::class, 'key' ], $offers ) );
		$rows  = [];

		foreach ( $offers as $offer_id ) {
			$card = $found[ Remote_Cards_Ids::key( $offer_id ) ] ?? null;

			if ( null !== $card ) {
				$rows[] = $this->rows->row( $account->id(), array_merge( $card, [ 'wc_product_id' => 0 ] ), null, true );
			}
		}

		return $rows;
	}

	/**
	 * `link_to`, `near_confirm` and `near_dismiss` act on one card. The table selects many rows for «Импортировать», so a request that
	 * names several is refused rather than acting on the first.
	 *
	 * @param string   $action Action id.
	 * @param string[] $ids    Row ids.
	 * @return void
	 * @throws Action_State_Exception When the action is a one-card action and the request names more than one row.
	 */
	private function require_one_card( string $action, array $ids ): void {
		$one_card = [ Remote_Cards_Definition::LINK_TO, Remote_Cards_Definition::NEAR_CONFIRM, Remote_Cards_Definition::NEAR_DISMISS ];

		if ( in_array( $action, $one_card, true ) && count( array_unique( $ids ) ) > 1 ) {
			throw new Action_State_Exception( __( 'Это действие выполняется для одной карточки. Выберите одну.', 'woodev-plugin-framework' ) );
		}
	}

	/**
	 * @param Account             $account Account.
	 * @param string              $row_id  Row id.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed>
	 */
	private function preview_link( Account $account, string $row_id, array $payload ): array {
		$product = $this->products->pick( $payload[ Remote_Cards_Definition::PRODUCT_FIELD ] ?? null, Remote_Cards_Definition::PRODUCT_FIELD );
		$card    = $this->card( $account, $row_id );
		$reason  = $this->reason_not_to_link( $account, $card );

		if ( null !== $reason ) {
			return $this->refused_preview( $reason );
		}

		return [
			'requested'      => 1,
			'eligible'       => 1,
			'skipped'        => 0,
			'scope_sentence' => sprintf(
				/* translators: 1: the card's article, 2: the card's name, 3: the product's name, 4: what linking does on the marketplace. */
				__( 'Карточка %1$s «%2$s» будет связана с товаром «%3$s». %4$s', 'woodev-plugin-framework' ),
				(string) $card['offer_id'],
				(string) ( $card['name'] ?? '' ),
				$product['title'],
				$this->linker->remote_effect( $account, $this->unchanged() )
			),
		];
	}

	/**
	 * @param Account             $account Account.
	 * @param string              $row_id  Row id.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed>
	 */
	private function apply_link( Account $account, string $row_id, array $payload ): array {
		$product = $this->products->pick( $payload[ Remote_Cards_Definition::PRODUCT_FIELD ] ?? null, Remote_Cards_Definition::PRODUCT_FIELD );
		$card    = $this->card( $account, $row_id );
		$reason  = $this->reason_not_to_link( $account, $card );

		if ( null !== $reason ) {
			return $this->refused_apply( $reason, (string) ( $card['offer_id'] ?? '' ) );
		}

		try {
			$outcome = $this->linker->link( $account->id(), $product['id'], $product['parent_id'], $card );
		} catch ( Persistence_Exception $e ) {
			return $this->write_failed( $card );
		}

		if ( Card_Linker::LINKED !== $outcome ) {
			return $this->refused_apply( Card_Linker::message( $outcome ), (string) $card['offer_id'] );
		}

		$this->reports->settle_near_match( $account->id(), $product['id'], (string) $card['offer_id'], true );
		$this->reports->clear_import_note( $account->id(), (string) $card['offer_id'] );

		$text = Card_Linker::message( Card_Linker::LINKED );

		return [
			'requested' => 1,
			'accepted'  => 1,
			'skipped'   => 0,
			'failed'    => 0,
			'rows'      => [ $this->rows->row( $account->id(), array_merge( $card, [ 'wc_product_id' => $product['id'] ] ) ) ],
			'messages'  => [ 'action_status_done' => $text ],
		];
	}

	/**
	 * What «Это тот же товар» and «Нет, это разные» will do. Both read the report, which is local.
	 *
	 * @param Account $account Account.
	 * @param string  $action  {@see Remote_Cards_Definition::NEAR_CONFIRM} or {@see Remote_Cards_Definition::NEAR_DISMISS}.
	 * @param string  $row_id  Row id of the report.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the product cannot take the card, or the user may not change it.
	 */
	private function preview_near( Account $account, string $action, string $row_id ): array {
		$entry = $this->near_entry( $account, $row_id );

		if ( null === $entry ) {
			return $this->refused_preview( $this->pair_gone() );
		}

		$product_id = (int) $entry['wc_product_id'];
		$card       = (array) $entry['card'];

		if ( Remote_Cards_Definition::NEAR_DISMISS === $action ) {
			$this->require_editable( $product_id );

			return [
				'requested'      => 1,
				'eligible'       => 1,
				'skipped'        => 0,
				'scope_sentence' => __( 'Пара будет убрана из списка, товар и карточка останутся несвязанными. Эта пара не вернётся при следующем поиске по артикулу, пока артикул не изменится.', 'woodev-plugin-framework' ),
			];
		}

		$product = $this->near_product( $product_id );

		return [
			'requested'      => 1,
			'eligible'       => 1,
			'skipped'        => 0,
			'scope_sentence' => sprintf(
				/* translators: 1: the card's article, 2: the card's name, 3: the product's name, 4: what linking does on the marketplace. */
				__( 'Карточка %1$s «%2$s» будет связана с товаром «%3$s». %4$s', 'woodev-plugin-framework' ),
				(string) $card['offer_id'],
				(string) ( $card['name'] ?? '' ),
				$product['title'],
				$this->linker->remote_effect( $account, $this->unchanged() )
			),
		];
	}

	/**
	 * «Это тот же товар»: links the pair from the report's own copy of the card (no call to the marketplace) and settles it.
	 *
	 * @param Account $account Account.
	 * @param string  $row_id  Row id of the report.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the product cannot take the card, or the user may not change it.
	 */
	private function apply_near_confirm( Account $account, string $row_id ): array {
		$entry = $this->near_entry( $account, $row_id );

		if ( null === $entry ) {
			return $this->gone_apply( $row_id );
		}

		$card    = (array) $entry['card'];
		$offer   = (string) $card['offer_id'];
		$product = $this->near_product( (int) $entry['wc_product_id'] );

		try {
			$outcome = $this->linker->link( $account->id(), $product['id'], $product['parent_id'], $card );
		} catch ( Persistence_Exception $e ) {
			return $this->write_failed( $card );
		}

		if ( Card_Linker::BUSY === $outcome || Card_Linker::NOT_A_CARD === $outcome || Card_Linker::CLAIM_LOST === $outcome ) {
			return $this->refused_apply( Card_Linker::message( $outcome ), $offer );
		}

		// Linked, or one side was linked to something else meanwhile: either way the pair is no longer a question.
		$this->reports->settle_near_match( $account->id(), $product['id'], $offer, true );

		if ( Card_Linker::LINKED !== $outcome ) {
			return array_merge( $this->refused_apply( Card_Linker::message( $outcome ), $offer ), [ 'removed_ids' => [ $row_id ] ] );
		}

		return $this->settled_apply( $row_id, Card_Linker::message( Card_Linker::LINKED ) );
	}

	/**
	 * «Нет, это разные»: removes the pair and remembers the answer.
	 *
	 * @param Account $account Account.
	 * @param string  $row_id  Row id of the report.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the user may not change the product.
	 */
	private function apply_near_dismiss( Account $account, string $row_id ): array {
		$entry = $this->near_entry( $account, $row_id );

		if ( null === $entry ) {
			return $this->gone_apply( $row_id );
		}

		$product_id = (int) $entry['wc_product_id'];

		$this->require_editable( $product_id );
		$this->reports->dismiss_near_match( $account->id(), $product_id, (string) $entry['card']['offer_id'] );

		return $this->settled_apply( $row_id, __( 'Пара убрана из списка: товар и карточка остались несвязанными.', 'woodev-plugin-framework' ) );
	}

	/**
	 * The entry of the report a row id names.
	 *
	 * @param Account $account Account.
	 * @param string  $row_id  Row id.
	 * @return array<string,mixed>|null Null when the id is not a report row of this account, or the pair has been settled.
	 */
	private function near_entry( Account $account, string $row_id ): ?array {
		$ref = Remote_Cards_Ids::near_of( $row_id, $account->id() );

		return null === $ref ? null : Remote_Cards_Query::find_near( $this->reports->near_matches( $account->id() ), $ref['key'], $ref['product_id'] );
	}

	/**
	 * The product of a pair that «Это тот же товар» will link.
	 *
	 * @param int $product_id Product or variation id.
	 * @return array{id:int,parent_id:int,title:string}
	 * @throws Action_State_Exception When it is gone, may not be changed by the user or cannot take a card.
	 */
	private function near_product( int $product_id ): array {
		if ( ! wc_get_product( $product_id ) ) {
			throw new Action_State_Exception( __( 'Этого товара уже нет на сайте. Уберите пару кнопкой «Нет, это разные».', 'woodev-plugin-framework' ) );
		}

		try {
			return $this->products->pick( (string) $product_id, Remote_Cards_Definition::PRODUCT_FIELD );
		} catch ( Action_Input_Exception $e ) {
			throw new Action_State_Exception( (string) ( array_values( $e->get_errors() )[0] ?? Card_Linker::message( Card_Linker::NOT_A_CARD ) ) );
		}
	}

	/**
	 * A product that still exists must be one the user may change before its pair is decided; a product that is gone has nothing to protect.
	 *
	 * @param int $product_id Product or variation id.
	 * @return void
	 * @throws Action_State_Exception When the user may not change the product.
	 */
	private function require_editable( int $product_id ): void {
		if ( wc_get_product( $product_id ) && ! current_user_can( 'edit_post', $product_id ) ) {
			throw new Action_State_Exception( __( 'У вас нет прав на изменение этого товара.', 'woodev-plugin-framework' ) );
		}
	}

	/**
	 * @return string
	 */
	private function pair_gone(): string {
		return __( 'Эта пара уже решена или список похожих артикулов обновился. Обновите страницу.', 'woodev-plugin-framework' );
	}

	/**
	 * An apply for a pair that is no longer in the report: nothing to do, and the row goes.
	 *
	 * @param string $row_id Row id.
	 * @return array<string,mixed>
	 */
	private function gone_apply( string $row_id ): array {
		return array_merge( $this->refused_apply( $this->pair_gone(), '' ), [ 'removed_ids' => [ $row_id ] ] );
	}

	/**
	 * An apply that settled the pair: the row goes.
	 *
	 * @param string $row_id Row id.
	 * @param string $text   What happened.
	 * @return array<string,mixed>
	 */
	private function settled_apply( string $row_id, string $text ): array {
		return [
			'requested'   => 1,
			'accepted'    => 1,
			'skipped'     => 0,
			'failed'      => 0,
			'removed_ids' => [ $row_id ],
			'messages'    => [ 'action_status_done' => $text ],
		];
	}

	/**
	 * The database did not take a link.
	 *
	 * @param array<string,mixed> $card The card.
	 * @return array<string,mixed>
	 */
	private function write_failed( array $card ): array {
		return [
			'requested' => 1,
			'accepted'  => 0,
			'skipped'   => 0,
			'failed'    => 1,
			'groups'    => [ $this->group( __( 'Не удалось записать связь: база данных не ответила. Повторите чуть позже.', 'woodev-plugin-framework' ), 'failed', $card ) ],
		];
	}

	/**
	 * @param Account $account Account.
	 * @return array<string,mixed>
	 */
	private function preview_refresh( Account $account ): array {
		if ( $this->cards->is_refreshing( $account->id() ) ) {
			return $this->refused_preview( $this->already_refreshing() );
		}

		return [
			'requested' => 1,
			'eligible'  => 1,
			'skipped'   => 0,
		];
	}

	/**
	 * Marks the list as being refreshed, then queues the job: the mark comes first so that two clicks do not queue two reads.
	 *
	 * @param Account $account Account.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When Action Scheduler refuses the job.
	 */
	private function apply_refresh( Account $account ): array {
		if ( $this->cards->is_refreshing( $account->id() ) ) {
			return $this->refused_apply( $this->already_refreshing(), '' );
		}

		$this->cards->mark_refreshing( $account->id() );

		if ( ! $this->scheduler->refresh_cards( $account->id() ) ) {
			$text = __( 'Не удалось поставить загрузку списка в очередь. Проверьте, что в WooCommerce работает планировщик задач (Action Scheduler), и повторите.', 'woodev-plugin-framework' );
			$this->cards->fail( $account->id(), $text );

			throw new Action_State_Exception( $text );
		}

		$text = __( 'Список карточек загружается. Это займёт от нескольких секунд до пары минут. Таблица обновится сама, когда загрузка закончится.', 'woodev-plugin-framework' );

		return [
			'requested' => 1,
			'accepted'  => 1,
			'skipped'   => 0,
			'failed'    => 0,
			'queued'    => true,
			'messages'  => [ 'action_status_queued' => $text ],
		];
	}

	/**
	 * The card of a row, read from the account's stored list.
	 *
	 * @param Account $account Account.
	 * @param string  $row_id  Row id.
	 * @return array<string,mixed>|null Null when the list does not hold the card.
	 */
	private function card( Account $account, string $row_id ): ?array {
		$key      = Remote_Cards_Ids::key_of( $row_id, $account->id() );
		return null === $key ? null : $this->cards->find( $account->id(), $key );
	}

	/**
	 * Why a card cannot be linked from this list, or null when it can. A card whose details did not agree with the list is not
	 * linkable: its identifiers are not trustworthy. A card that is being imported is not linkable either: the import links it itself.
	 *
	 * @param Account                  $account Account.
	 * @param array<string,mixed>|null $card    Card.
	 * @return string|null
	 */
	private function reason_not_to_link( Account $account, ?array $card ): ?string {
		if ( null === $card ) {
			return __( 'Этой карточки нет в загруженном списке — он мог устареть. Нажмите «Обновить список» и повторите.', 'woodev-plugin-framework' );
		}

		if ( empty( $card['details_ok'] ) ) {
			return __( 'О карточке нет полных данных: при загрузке списка они не совпали. Обновите список и повторите.', 'woodev-plugin-framework' );
		}

		if ( null !== $this->importer && in_array( (string) $card['offer_id'], $this->importer->pending_offer_ids( $account->id() ), true ) ) {
			return __( 'Карточка сейчас импортируется — дождитесь результата.', 'woodev-plugin-framework' );
		}

		return null;
	}

	/**
	 * @return string
	 */
	private function already_refreshing(): string {
		return __( 'Список уже загружается. Дождитесь окончания: таблица обновится сама.', 'woodev-plugin-framework' );
	}

	/**
	 * A preview in which the one asked-for target is left out for a reason.
	 *
	 * @param string $reason Reason.
	 * @return array<string,mixed>
	 */
	private function refused_preview( string $reason ): array {
		return [
			'requested' => 1,
			'eligible'  => 0,
			'skipped'   => 1,
			'groups'    => [ $this->group( $reason, 'skipped', null ) ],
		];
	}

	/**
	 * An apply in which the one asked-for target is left out for a reason.
	 *
	 * @param string $reason   Reason.
	 * @param string $offer_id The card's article, for the example; '' for none.
	 * @return array<string,mixed>
	 */
	private function refused_apply( string $reason, string $offer_id ): array {
		return [
			'requested' => 1,
			'accepted'  => 0,
			'skipped'   => 1,
			'failed'    => 0,
			'groups'    => [ $this->group( $reason, 'skipped', '' === $offer_id ? null : [ 'offer_id' => $offer_id ] ) ],
		];
	}

	/**
	 * Builds one group of reasons for the one target.
	 *
	 * @param string                   $reason Reason.
	 * @param string                   $kind   `skipped` or `failed`.
	 * @param array<string,mixed>|null $card   The card, whose article is the example; null for none.
	 * @return array<string,mixed>
	 */
	private function group( string $reason, string $kind, ?array $card ): array {
		return [
			'kind'     => $kind,
			'reason'   => $reason,
			'count'    => 1,
			'examples' => null === $card || '' === (string) ( $card['offer_id'] ?? '' ) ? [] : [ (string) $card['offer_id'] ],
		];
	}

	/**
	 * Refuses an action that reaches the marketplace while the licence is not in force.
	 *
	 * @param string $action Action id.
	 * @return void
	 * @throws Action_State_Exception When the licence does not allow it.
	 */
	private function require_licence( string $action ): void {
		$phrases = [
			Remote_Cards_Definition::REFRESH         => __( 'загрузить список карточек с маркетплейса', 'woodev-plugin-framework' ),
			Site_Products_Bulk::LINK_BY_ARTICLE      => __( 'искать карточки на маркетплейсе', 'woodev-plugin-framework' ),
			Remote_Cards_Definition::IMPORT          => __( 'импортировать карточки с маркетплейса', 'woodev-plugin-framework' ),
			Remote_Cards_Definition::IMPORT_SELECTED => __( 'импортировать карточки с маркетплейса', 'woodev-plugin-framework' ),
		];

		$refusal = isset( $phrases[ $action ] ) ? $this->license->refusal_for( $phrases[ $action ] ) : null;

		if ( null !== $refusal ) {
			throw new Action_State_Exception( $refusal );
		}
	}
}
