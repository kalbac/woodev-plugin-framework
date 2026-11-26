<?php
/**
 * The «Товары сайта» table of the records engine.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Applies_To_Matching;
use Woodev\Framework\Admin\Records\Provides_Field_Options;
use Woodev\Framework\Admin\Records\Records_Provider;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Shop_Categories;
use Woodev\Framework\Marketplace\Admin\Shared\Link_Notes;
use Woodev\Framework\Marketplace\Admin\Shared\Russian_Plural;
use Woodev\Framework\Marketplace\Catalog\Bulk_Validation_Report;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Runner;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Marketplace\Flows\Article_Display;
use Woodev\Framework\Marketplace\Flows\Unit_Products;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce products joined to the links of one account, as a records provider. It is the small coordinator: SQL is in
 * {@see Site_Products_Reader}, cells in {@see Site_Products_Rows}, locks in {@see Site_Products_Activity}, the selection in
 * {@see Site_Products_Selection} and the twelve actions in {@see Site_Products_Bulk}.
 *
 * Variable products are parent rows that expand into variations ({@see self::get_children()}); every row is `{account}:{product}`.
 * Sorting, filtering and paging are done in SQL over the whole table, never over one page. Nothing here calls the marketplace.
 *
 * @since 2.1.4
 */
final class Site_Products_Provider implements Records_Provider, Provides_Field_Options, Applies_To_Matching {
	/**
	 * Table id: the page declares `tables => [ 'products' ]`.
	 */
	public const TABLE = 'products';

	/**
	 * Most categories the filter offers.
	 */
	private const MAX_CATEGORIES = 200;

	/**
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * @var Site_Products_Reader
	 */
	private $reader;

	/**
	 * @var Site_Products_Selection
	 */
	private $selection;

	/**
	 * @var Site_Products_Activity
	 */
	private $activity;

	/**
	 * @var Site_Products_Bulk
	 */
	private $bulk;

	/**
	 * @var Warehouse_Options
	 */
	private $warehouses;

	/**
	 * @var License_Gate
	 */
	private $license;

	/**
	 * Starts, stops and recovers the bulk runs of the «все по фильтру» mode; the mode is refused when null.
	 *
	 * @var Bulk_Run_Runner|null
	 */
	private $runs;

	/**
	 * The bulk runs, for the report and the «already running» sentence.
	 *
	 * @var Bulk_Run_Store|null
	 */
	private $run_store;

	/**
	 * `adapter_id`, `marketplace` (merchant-facing name), `withheld` (action ids the adapter cannot serve), `articles` (how a
	 * marketplace that sells units shows an article, else null), `notes` (what the plugin says about a link, else null).
	 *
	 * @var array{adapter_id:string,marketplace:string,withheld:string[],articles:Article_Display|null,notes:Link_Notes|null}
	 */
	private $config;

	/**
	 * Creates the provider.
	 *
	 * @since 2.1.4
	 *
	 * @param Wpdb_Account_Store      $accounts   Accounts.
	 * @param Site_Products_Reader    $reader     Reader.
	 * @param Site_Products_Selection $selection  Selection resolver.
	 * @param Site_Products_Activity  $activity   Activity reader.
	 * @param Site_Products_Bulk      $bulk       Bulk actions.
	 * @param Warehouse_Options       $warehouses Warehouses of an account.
	 * @param License_Gate            $license    Licence gate.
	 * @param array<string,mixed>     $config     `adapter_id`, `marketplace`, `withheld`; for an adapter that sells units, `articles`; for one that keeps notes about links, `notes`.
	 * @param Bulk_Run_Runner|null    $runs       Bulk runs of the «все по фильтру» mode.
	 * @param Bulk_Run_Store|null     $run_store  Their store.
	 */
	public function __construct( Wpdb_Account_Store $accounts, Site_Products_Reader $reader, Site_Products_Selection $selection, Site_Products_Activity $activity, Site_Products_Bulk $bulk, Warehouse_Options $warehouses, License_Gate $license, array $config, ?Bulk_Run_Runner $runs = null, ?Bulk_Run_Store $run_store = null ) {
		$this->accounts   = $accounts;
		$this->reader     = $reader;
		$this->selection  = $selection;
		$this->activity   = $activity;
		$this->bulk       = $bulk;
		$this->warehouses = $warehouses;
		$this->license    = $license;
		$this->runs       = $runs;
		$this->run_store  = $run_store;
		$this->config     = [
			'adapter_id'  => (string) ( $config['adapter_id'] ?? '' ),
			'marketplace' => (string) ( $config['marketplace'] ?? '' ),
			'withheld'    => (array) ( $config['withheld'] ?? [] ),
			'articles'    => ( $config['articles'] ?? null ) instanceof Article_Display ? $config['articles'] : null,
			'notes'       => ( $config['notes'] ?? null ) instanceof Link_Notes ? $config['notes'] : null,
		];
	}

	/**
	 * @since 2.1.4
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::TABLE;
	}

	/**
	 * @since 2.1.4
	 *
	 * @param array<string,string> $context Requested context.
	 * @return array<string,mixed>
	 */
	public function get_definition( array $context ): array {
		return Site_Products_Definition::build( $this->account_options(), $this->config['marketplace'], $this->category_options(), $this->config['withheld'] );
	}

	/**
	 * Every id must be a row of this account's table; an id of another account or a product that is not listed is refused like an
	 * unknown id. The edit right is not asked here: a product the user may not edit is reported by the action as skipped, with its reason.
	 *
	 * @since 2.1.4
	 *
	 * @param string               $operation `definition`, `list`, `children`, `options`, `activity` or an action id.
	 * @param array<string,string> $context   Resolved context.
	 * @param string[]             $ids       Row ids.
	 * @return bool
	 */
	public function authorize( string $operation, array $context, array $ids ): bool {
		if ( [] === $ids ) {
			return true;
		}

		$account_id = $this->account_id( $context );

		if ( 'activity' === $operation ) {
			// The refresh asks about rows that were on screen; one that has vanished since is answered in `removed_ids`, not refused.
			return $this->are_ids_of_account( $account_id, $ids );
		}

		if ( 'children' === $operation ) {
			return 1 === count( $ids ) && null !== $this->selection->expandable( $account_id, (string) $ids[0] );
		}

		return null !== $this->selection->resolve( $account_id, $ids );
	}

	/**
	 * Whether every row id is well formed and belongs to the account; says nothing about whether the product still exists.
	 *
	 * @param int      $account_id Account.
	 * @param string[] $ids        Row ids.
	 * @return bool
	 */
	private function are_ids_of_account( int $account_id, array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( null === Site_Products_Ids::product_id( (string) $id, $account_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Reads one page, sorted and filtered over the whole table.
	 *
	 * @since 2.1.4
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param array<string,mixed>  $query   Validated query.
	 * @return array<string,mixed>
	 */
	public function query( array $context, array $query ): array {
		$account = $this->account( $context );

		if ( null === $account ) {
			return [
				'rows'  => [],
				'total' => 0,
			];
		}

		$filter = Site_Products_Filter::from_query( $account->id(), $query );
		$page   = $this->reader->page( $filter, (int) $query['page'], (int) $query['per_page'] );

		return [
			'rows'             => $this->parent_rows( $account, $page['ids'] ),
			'total'            => $page['total'],
			'facets'           => [ 'state' => $this->state_facets( $filter, $page['total'] ) ],
			'account_activity' => $this->activity->account( $account->id() ),
			'messages'         => [ 'summary_label' => Russian_Plural::pick( $page['total'], __( 'товар', 'woodev-plugin-framework' ), __( 'товара', 'woodev-plugin-framework' ), __( 'товаров', 'woodev-plugin-framework' ) ) ],
		];
	}

	/**
	 * One count per state group under every other filter: the numbers of the view row. A variable product with variations in two
	 * groups is counted in both, so the entry with the empty value carries the real number of products, for «Все». While no state
	 * is chosen that is the total of the read itself; otherwise it costs one more `COUNT`.
	 *
	 * @param Site_Products_Filter $filter The filters of the read.
	 * @param int                  $total  The total of the read.
	 * @return array<int,array{value:string,label:string,count:int}>
	 */
	private function state_facets( Site_Products_Filter $filter, int $total ): array {
		$counts = $this->reader->state_counts( $filter );
		$facets = [
			[
				'value' => '',
				'label' => '',
				'count' => '' === $filter->state() ? $total : $this->reader->count_without_state( $filter ),
			],
		];

		foreach ( State_Groups::labels() as $group => $label ) {
			$facets[] = [
				'value' => $group,
				'label' => $label,
				'count' => $counts[ $group ] ?? 0,
			];
		}

		return $facets;
	}

	/**
	 * Reads one page of the variations of a variable product.
	 *
	 * @since 2.1.4
	 *
	 * @param array<string,string> $context   Resolved context.
	 * @param string               $parent_id Row id of the variable product.
	 * @param array<string,mixed>  $query     Validated query.
	 * @return array<string,mixed>
	 */
	public function get_children( array $context, string $parent_id, array $query ): array {
		$account = $this->account( $context );
		$product = null === $account ? null : $this->selection->expandable( $account->id(), $parent_id );

		if ( null === $account || null === $product ) {
			return [
				'rows'  => [],
				'total' => 0,
			];
		}

		$page = $this->reader->variation_page( $account->id(), $product, (int) $query['page'], (int) $query['per_page'] );

		return [
			'rows'  => $this->child_rows( $account, $product, $page['offers'] ),
			'total' => $page['total'],
		];
	}

	/**
	 * The validation report, or the plan, of an action. Writes nothing.
	 *
	 * @since 2.1.4
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the licence is not in force or the selection is too large.
	 * @throws Action_Input_Exception When the warehouse is not one of the account's, or the category of the linking is not a product category.
	 */
	public function preview( array $context, string $action, array $ids, array $payload ): array {
		if ( Site_Products_Bulk::RUN_REPORT === $action ) {
			return $this->report_preview( $context, $payload );
		}

		if ( Site_Products_Bulk::RUN_STOP === $action ) {
			return $this->stop_preview( $context, $payload );
		}

		$call = $this->prepare( $context, $action, $ids, $payload );

		return $this->bulk->preview( $call['account'], $action, $call['offers'], $call['editable'], $call['warehouse'], $call['category'] );
	}

	/**
	 * Replans from current state and applies an action.
	 *
	 * @since 2.1.4
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the licence is not in force, the selection is too large or the queue refuses the work.
	 * @throws Action_Input_Exception When the warehouse is not one of the account's, or the category of the linking is not a product category.
	 */
	public function apply( array $context, string $action, array $ids, array $payload ): array {
		if ( Site_Products_Bulk::RUN_STOP === $action ) {
			return $this->stop_apply( $context, $payload );
		}

		if ( Site_Products_Bulk::RUN_REPORT === $action ) {
			return $this->report_preview( $context, $payload );
		}

		$call = $this->prepare( $context, $action, $ids, $payload );

		return $this->bulk->apply( $call['account'], $action, $call['offers'], $call['editable'], $call['warehouse'], $call['category'] );
	}

	/**
	 * The plan of an action over every product under the filter (#1795): one count and a sentence. It does not plan the products — that is
	 * the work the run spreads over parts — and the skip reasons arrive in the report.
	 *
	 * @since 2.1.8
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Action id.
	 * @param array{search:string,filters:array<string,mixed>} $query   Validated filter and search.
	 * @param array<string,mixed>                              $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the licence is not in force or a run of this account is still going.
	 * @throws Action_Input_Exception When the warehouse is not one of the account's.
	 */
	public function preview_matching( array $context, string $action, array $query, array $payload ): array {
		$call  = $this->prepare_matching( $context, $action, $query, $payload );
		$total = $this->reader->count_filtered( $call['filter'] );
		$name  = $this->config['marketplace'];
		$words = $total . ' ' . Bulk_Validation_Report::products( $total );
		$out   = [
			'requested'      => $total,
			'eligible'       => $total,
			'skipped'        => 0,
			'scope_sentence' => Site_Products_Run_Line::reach( $total ) . ' ' . Site_Products_Run_Line::effect( $action, $name ) . ' ' . Site_Products_Run_Line::background(),
		];

		if ( Site_Products_Bulk::ARCHIVE === $action ) {
			/* translators: %s: «120 товаров». */
			$out['confirm_label'] = sprintf( __( 'Архивировать %s', 'woodev-plugin-framework' ), $words );
		} elseif ( Site_Products_Bulk::DETACH === $action ) {
			/* translators: %s: «120 товаров». */
			$out['confirm_label'] = sprintf( __( 'Снять связь у %s', 'woodev-plugin-framework' ), $words );
		}

		return $out;
	}

	/**
	 * Starts the run of an action over every product under the filter and queues its first tick. Nothing is processed in the request.
	 *
	 * @since 2.1.8
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Action id.
	 * @param array{search:string,filters:array<string,mixed>} $query   Validated filter and search.
	 * @param array<string,mixed>                              $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the licence is not in force, a run is still going, there is nothing under the filter or the queue refuses the work.
	 * @throws Action_Input_Exception When the warehouse is not one of the account's.
	 */
	public function apply_matching( array $context, string $action, array $query, array $payload ): array {
		$call  = $this->prepare_matching( $context, $action, $query, $payload );
		$total = $this->reader->count_filtered( $call['filter'] );

		if ( 0 === $total ) {
			throw new Action_State_Exception( __( 'По этому фильтру товаров нет.', 'woodev-plugin-framework' ) );
		}

		try {
			$run_id = $this->runs->start(
				$call['account']->id(),
				$action,
				$call['payload'],
				[
					'search' => $query['search'],
					'filters' => $query['filters'],
				],
				get_current_user_id(),
				$total
			);
		} catch ( \RuntimeException $e ) {
			throw new Action_State_Exception( __( 'Не удалось поставить действие в очередь. Проверьте, что в WooCommerce работает планировщик задач (Action Scheduler), и повторите.', 'woodev-plugin-framework' ) );
		}

		if ( null === $run_id ) {
			throw new Action_State_Exception( $this->running_sentence( $call['account']->id() ) );
		}

		/* translators: %s: «12 345 товаров». */
		$text = sprintf( __( 'Действие запущено в фоне: %s. Ход работы — в строке аккаунта над таблицей.', 'woodev-plugin-framework' ), $total . ' ' . Bulk_Validation_Report::products( $total ) );

		return [
			'requested' => $total,
			'accepted'  => $total,
			'skipped'   => 0,
			'failed'    => 0,
			'queued'    => true,
			'run'       => [
				'id'             => $run_id,
				'products_total' => $total,
			],
			'messages'  => [
				'action_status_done'    => $text,
				'action_status_partial' => $text,
				'action_status_queued'  => $text,
			],
		];
	}

	/**
	 * The current rows of the asked ids and the account line. A local read.
	 *
	 * @since 2.1.4
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string[]             $ids     Visible or expanded row ids.
	 * @return array<string,mixed>
	 */
	public function get_activity( array $context, array $ids ): array {
		$account = $this->account( $context );

		if ( null === $account ) {
			return [ 'removed_ids' => $ids ];
		}

		$products = $this->products_of( $account->id(), $ids );
		$rows     = array_merge( $this->parent_rows( $account, $products['listed'] ), $this->variation_rows( $account, $products['variations'] ) );
		$found    = array_column( $rows, 'id' );

		return [
			'rows'             => $rows,
			'removed_ids'      => array_values( array_diff( $ids, $found ) ),
			'account_activity' => $this->activity->account( $account->id() ),
		];
	}

	/**
	 * The options of a field the server lists: the categories of «Связать по артикулу» (a page at a time, searched by name) or, for
	 * every other action, the warehouse field: «по умолчанию» (0) and the account's warehouses.
	 *
	 * @since 2.1.4
	 * @since 2.1.8 The category of the linking.
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Action id.
	 * @param string                                           $field   Field id.
	 * @param array{row_id:string,search:string,cursor:string} $query   Validated input.
	 * @return array{options:array<int,array{value:string,label:string}>,next_cursor:string|null}
	 */
	public function get_field_options( array $context, string $action, string $field, array $query ): array {
		if ( Site_Products_Bulk::LINK_BY_ARTICLE === $action && Site_Products_Bulk::LINK_CATEGORY === $field ) {
			return Shop_Categories::page( $query['search'], $query['cursor'] );
		}

		$options = [
			[
				'value' => '0',
				'label' => __( 'По умолчанию (склад аккаунта)', 'woodev-plugin-framework' ),
			],
		];

		foreach ( $this->warehouses->for_account( $this->account_id( $context ) ) as $id => $name ) {
			if ( '' === $query['search'] || false !== mb_stripos( $name, $query['search'] ) ) {
				$options[] = [
					'value' => (string) $id,
					'label' => $name,
				];
			}
		}

		return [
			'options'     => array_slice( $options, 0, 100 ),
			'next_cursor' => null,
		];
	}

	/**
	 * Checks an action request and resolves it: the account, the distinct offers, the warehouse.
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array{account:Account,offers:int[],editable:array<int,int>,warehouse:int|null,category:int|null}
	 * @throws Action_State_Exception When the licence is not in force or the selection is too large.
	 * @throws Action_Input_Exception When the warehouse is not one of the account's, or the category is not a product category.
	 */
	private function prepare( array $context, string $action, array $ids, array $payload ): array {
		$account = $this->account( $context );

		if ( null === $account ) {
			throw new Action_State_Exception( __( 'Аккаунт не найден или выключен.', 'woodev-plugin-framework' ) );
		}

		$refusal = $this->license_refusal( $action );

		if ( null !== $refusal ) {
			throw new Action_State_Exception( $refusal );
		}

		$resolved = $this->selection->resolve( $account->id(), $ids ) ?? [
			'offers'   => [],
			'editable' => [],
		];

		if ( count( $resolved['offers'] ) > Site_Products_Selection::MAX_OFFERS ) {
			throw new Action_State_Exception(
				sprintf( /* translators: %d: the most offers. */ __( 'В выборе слишком много товаров и вариантов: за один раз можно обработать не больше %d. Отметьте меньше товаров.', 'woodev-plugin-framework' ), Site_Products_Selection::MAX_OFFERS )
			);
		}

		return [
			'account'   => $account,
			'offers'    => $resolved['offers'],
			'editable'  => $resolved['editable'],
			'warehouse' => Site_Products_Bulk::SET_WAREHOUSE === $action ? $this->warehouse( $account, $payload ) : null,
			'category'  => Site_Products_Bulk::LINK_BY_ARTICLE === $action ? $this->bulk->link_category( $payload ) : null,
		];
	}

	/**
	 * Checks a matching request and resolves it: the account, the filter, the fields.
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Action id.
	 * @param array{search:string,filters:array<string,mixed>} $query   Validated filter and search.
	 * @param array<string,mixed>                              $payload Validated fields.
	 * @return array{account:Account,filter:Site_Products_Filter,payload:array<string,mixed>}
	 * @throws Action_State_Exception When the mode is not wired, the account is gone, the licence is not in force or a run is going.
	 * @throws Action_Input_Exception When the warehouse is not one of the account's.
	 */
	private function prepare_matching( array $context, string $action, array $query, array $payload ): array {
		$account = $this->account( $context );

		if ( null === $account || null === $this->runs || null === $this->run_store ) {
			throw new Action_State_Exception( __( 'Аккаунт не найден или выключен.', 'woodev-plugin-framework' ) );
		}

		$refusal = $this->license_refusal( $action );

		if ( null !== $refusal ) {
			throw new Action_State_Exception( $refusal );
		}

		$current = $this->run_store->find( $account->id() );

		if ( null !== $current && $current->is_running() ) {
			throw new Action_State_Exception( $this->running_sentence( $account->id() ) );
		}

		if ( Site_Products_Bulk::SET_WAREHOUSE === $action ) {
			$payload = [ 'warehouse' => $this->warehouse( $account, $payload ) ];
		}

		return [
			'account' => $account,
			'filter'  => Site_Products_Filter::from_query( $account->id(), $query ),
			'payload' => $payload,
		];
	}

	/**
	 * «Уже выполняется «Отправить остатки» по фильтру — дождитесь окончания или остановите его».
	 *
	 * @param int $account_id Account.
	 * @return string
	 */
	private function running_sentence( int $account_id ): string {
		$run = null === $this->run_store ? null : $this->run_store->find( $account_id );

		/* translators: %s: the action's name. */
		return sprintf( __( 'Уже выполняется «%s» по фильтру — дождитесь окончания или остановите его.', 'woodev-plugin-framework' ), null === $run ? '' : Site_Products_Definition::label_of( $run->action() ) );
	}

	/**
	 * «Подробнее»: the stored report of the run on the line, through the preview contract. A read; nothing is changed.
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param array<string,mixed>  $payload Validated fields: the id of the run the button belongs to.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When that run is not the account's current one any more.
	 */
	private function report_preview( array $context, array $payload ): array {
		$run    = $this->shown_run( $context, $payload );
		$counts = $run->counts();

		return [
			'requested'      => $counts['offers_accepted'] + $counts['offers_skipped'] + $counts['offers_failed'],
			'eligible'       => $counts['offers_accepted'],
			'skipped'        => $counts['offers_skipped'] + $counts['offers_failed'],
			'scope_sentence' => $run->is_running() ? Site_Products_Run_Line::running( $run ) : Site_Products_Run_Line::finished( $run ),
			'groups'         => $run->report(),
			'can_apply'      => false,
		];
	}

	/**
	 * «Остановить»: what stopping does.
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param array<string,mixed>  $payload Validated fields: the id of the run the button belongs to.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When that run is not the account's current one any more.
	 */
	private function stop_preview( array $context, array $payload ): array {
		$this->shown_run( $context, $payload );

		return [
			'requested' => 1,
			'eligible'  => 1,
			'skipped'   => 0,
		];
	}

	/**
	 * Stops the run the merchant saw. A run id that is not the current running run changes nothing: a second tab must never stop a newer run.
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param array<string,mixed>  $payload Validated fields: the id of the run the button belongs to.
	 * @return array<string,mixed>
	 */
	private function stop_apply( array $context, array $payload ): array {
		$account = $this->account( $context );
		$outcome = null === $account || null === $this->runs ? Bulk_Run_Runner::STOP_GONE : $this->runs->stop( $account->id(), (string) ( $payload[ Site_Products_Bulk::RUN_FIELD ] ?? '' ) );

		if ( Bulk_Run_Runner::STOP_GONE === $outcome ) {
			$gone = __( 'Это действие уже закончилось — новое действие не остановлено.', 'woodev-plugin-framework' );

			return [
				'requested' => 1,
				'accepted'  => 0,
				'skipped'   => 1,
				'failed'    => 0,
				'messages'  => [ 'action_status_failed' => $gone ],
			];
		}

		$text = Bulk_Run_Runner::STOP_CANCELLED === $outcome
			? __( 'Действие остановлено.', 'woodev-plugin-framework' )
			: __( 'Обработка остановится после текущей части. То, что уже поставлено в очередь, будет отправлено.', 'woodev-plugin-framework' );

		return [
			'requested' => 1,
			'accepted'  => 1,
			'skipped'   => 0,
			'failed'    => 0,
			'messages'  => [
				'action_status_done'    => $text,
				'action_status_partial' => $text,
				'action_status_queued'  => $text,
			],
		];
	}

	/**
	 * The run a button of the account line belongs to, when it is still the account's current one.
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return Bulk_Run
	 * @throws Action_State_Exception When the account has no such run any more.
	 */
	private function shown_run( array $context, array $payload ): Bulk_Run {
		$account = $this->account( $context );
		$run     = null === $account || null === $this->run_store ? null : $this->run_store->find( $account->id() );

		if ( null === $run || $run->run_id() !== (string) ( $payload[ Site_Products_Bulk::RUN_FIELD ] ?? '' ) ) {
			throw new Action_State_Exception( __( 'Это действие уже закончилось или заменено новым — смотрите строку аккаунта над таблицей.', 'woodev-plugin-framework' ) );
		}

		return $run;
	}

	/**
	 * Reads the warehouse out of the payload and checks it against the account's own list.
	 *
	 * @param Account             $account Account.
	 * @param array<string,mixed> $payload Fields.
	 * @return int 0 for the account default.
	 * @throws Action_Input_Exception When the warehouse is not in the list.
	 */
	private function warehouse( Account $account, array $payload ): int {
		$chosen = (string) ( $payload['warehouse'] ?? '' );
		$known  = array_map( 'strval', array_keys( $this->warehouses->for_account( $account->id() ) ) );

		if ( '0' !== $chosen && ! in_array( $chosen, $known, true ) ) {
			throw new Action_Input_Exception( [ 'warehouse' => __( 'Выберите склад из списка.', 'woodev-plugin-framework' ) ] );
		}

		return (int) $chosen;
	}

	/**
	 * The refusal of the licence for an action that writes to the marketplace, or null.
	 *
	 * @param string $action Action id.
	 * @return string|null
	 */
	private function license_refusal( string $action ): ?string {
		$phrase = Site_Products_Bulk::license_phrase( $action );

		return null === $phrase ? null : $this->license->refusal_for( $phrase );
	}

	/**
	 * Builds the rows of listed products, with the activity of each.
	 *
	 * @param Account $account Account.
	 * @param int[]   $ids     Product ids, in display order.
	 * @return array<int,array<string,mixed>>
	 */
	private function parent_rows( Account $account, array $ids ): array {
		$products = $this->reader->rows( $account->id(), $ids );
		$offers   = [];

		foreach ( $products as $product ) {
			foreach ( $product['offers'] as $offer ) {
				$offers[ $offer['product_id'] ] = $offer;
			}
		}

		// The plugin is asked once for the whole page.
		$offers = $this->with_notes( $account->id(), $offers );

		foreach ( $products as $index => $product ) {
			foreach ( $product['offers'] as $position => $offer ) {
				$products[ $index ]['offers'][ $position ] = $offers[ $offer['product_id'] ];
			}
		}

		$activity = $this->activity->for_offers( $account->id(), $offers );
		$builder  = $this->row_builder( $account );
		$rows     = [];

		foreach ( $products as $product ) {
			$own = array_map(
				static function ( array $offer ) use ( $activity ): array {
					return $activity[ $offer['product_id'] ];
				},
				$product['offers']
			);

			$rows[] = $builder->parent( $account->id(), $product, $product['is_variable'] ? $this->activity->for_parent( $own ) : $own[0] );
		}

		return $rows;
	}

	/**
	 * Builds the rows of variations of one variable product.
	 *
	 * @param Account                        $account   Account.
	 * @param int                            $parent_id Variable product.
	 * @param array<int,array<string,mixed>> $offers    Offers of its variations.
	 * @return array<int,array<string,mixed>>
	 */
	private function child_rows( Account $account, int $parent_id, array $offers ): array {
		$offers   = $this->with_notes( $account->id(), $offers );
		$activity = $this->activity->for_offers( $account->id(), array_column( $offers, null, 'product_id' ) );
		$builder  = $this->row_builder( $account );

		return array_map(
			static function ( array $offer ) use ( $builder, $account, $parent_id, $activity ): array {
				return $builder->child( $account->id(), $parent_id, $offer, $activity[ $offer['product_id'] ] );
			},
			$offers
		);
	}

	/**
	 * Builds the rows of variations named by their own ids, each under its own parent.
	 *
	 * @param Account $account Account.
	 * @param int[]   $ids     Variation ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function variation_rows( Account $account, array $ids ): array {
		if ( [] === $ids ) {
			return [];
		}

		$offers   = $this->with_notes( $account->id(), $this->reader->variation_offers( $account->id(), $ids ) );
		$kinds    = $this->reader->kinds( $ids );
		$activity = $this->activity->for_offers( $account->id(), $offers );
		$builder  = $this->row_builder( $account );
		$rows     = [];

		foreach ( $offers as $product_id => $offer ) {
			$rows[] = $builder->child( $account->id(), (int) ( $kinds[ $product_id ]['parent'] ?? 0 ), $offer, $activity[ $product_id ] );
		}

		return $rows;
	}

	/**
	 * Adds to each offer the note the plugin keeps about its link ({@see Link_Notes}), one question for all of them.
	 *
	 * @param int                            $account_id Account.
	 * @param array<int,array<string,mixed>> $offers     Offers.
	 * @return array<int,array<string,mixed>> The same offers, under the same keys.
	 */
	private function with_notes( int $account_id, array $offers ): array {
		$notes    = $this->config['notes'];
		$link_ids = array_values( array_filter( array_column( $offers, 'link_id' ) ) );

		if ( null === $notes || [] === $link_ids ) {
			return $offers;
		}

		$found = $notes->link_notes( $account_id, $link_ids );

		foreach ( $offers as $key => $offer ) {
			$offers[ $key ]['note'] = $found[ (int) $offer['link_id'] ] ?? null;
		}

		return $offers;
	}

	/**
	 * Splits asked row ids into listed products and variations.
	 *
	 * @param int      $account_id Account.
	 * @param string[] $row_ids    Row ids.
	 * @return array{listed:int[],variations:int[]}
	 */
	private function products_of( int $account_id, array $row_ids ): array {
		$product_ids = array_filter(
			array_map(
				static function ( string $row_id ) use ( $account_id ): ?int {
					return Site_Products_Ids::product_id( $row_id, $account_id );
				},
				$row_ids
			)
		);
		$kinds       = $this->reader->kinds( $product_ids );
		$out         = [
			'listed'     => [],
			'variations' => [],
		];

		foreach ( $kinds as $product_id => $kind ) {
			$out[ $kind['is_variation'] ? 'variations' : 'listed' ][] = $product_id;
		}

		return $out;
	}

	/**
	 * @param Account $account Account.
	 * @return Site_Products_Rows
	 */
	private function row_builder( Account $account ): Site_Products_Rows {
		$articles   = $this->config['articles'];
		$adapter_id = $this->config['adapter_id'];
		$account_id = $account->id();

		return new Site_Products_Rows(
			$this->config['marketplace'],
			$this->warehouses->for_account( $account->id() ),
			static function ( int $product_id ): string {
				return current_user_can( 'edit_post', $product_id ) ? (string) get_edit_post_link( $product_id, 'raw' ) : '';
			},
			static function ( int $product_id ): string {
				$product = wc_get_product( $product_id );
				$photo   = $product instanceof \WC_Product && $product->get_image_id() ? wp_get_attachment_image_url( (int) $product->get_image_id(), 'thumbnail' ) : '';

				return is_string( $photo ) && '' !== $photo ? $photo : wc_placeholder_img_src( 'thumbnail' );
			},
			null === $articles ? null : static function ( string $offer_id ) use ( $articles, $account_id ): string {
				return $articles->of_link( $account_id, $offer_id );
			},
			null === $articles ? null : static function ( int $product_id ) use ( $adapter_id ): string {
				$product = wc_get_product( $product_id );

				return $product instanceof \WC_Product ? Unit_Products::sku_conflict_text( $adapter_id, $product ) : '';
			}
		);
	}

	/**
	 * The account selector's options: the enabled accounts of the marketplace.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function account_options(): array {
		$options = [];

		foreach ( $this->accounts->all( $this->config['adapter_id'], true ) as $account ) {
			$options[] = [
				'value' => (string) $account->id(),
				'label' => '' === $account->name() ? '#' . $account->id() : $account->name(),
			];
		}

		return $options;
	}

	/**
	 * Product categories for the filter, parents before children, capped.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function category_options(): array {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => self::MAX_CATEGORIES,
				'orderby'    => 'name',
			]
		);

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$options = [];

		foreach ( $terms as $term ) {
			$options[] = [
				'value' => (string) $term->term_id,
				'label' => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
			];
		}

		return $options;
	}

	/**
	 * The account the table is open for: enabled, and of this marketplace.
	 *
	 * @param array<string,string> $context Resolved context.
	 * @return Account|null
	 */
	private function account( array $context ): ?Account {
		$id      = $this->account_id( $context );
		$account = $id > 0 ? $this->accounts->get( $id ) : null;

		return null !== $account && $account->is_enabled() && $account->adapter_id() === $this->config['adapter_id'] ? $account : null;
	}

	/**
	 * @param array<string,string> $context Resolved context.
	 * @return int 0 when the table has no account.
	 */
	private function account_id( array $context ): int {
		return (int) ( $context['account_id'] ?? 0 );
	}
}
