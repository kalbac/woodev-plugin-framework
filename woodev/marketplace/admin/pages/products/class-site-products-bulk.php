<?php
/**
 * The bulk actions of the «Товары сайта» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Shop_Categories;
use Woodev\Framework\Marketplace\Catalog\Bulk_Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Export_Service;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Lost;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Report;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Framework\Marketplace\Price\Price_Refusal;
use Woodev\Framework\Marketplace\Sync\Price_Context;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;
use Woodev\Framework\Marketplace\Sync\Stock_Mode;
use Woodev\Framework\Marketplace\Sync\Stock_Warehouse;

defined( 'ABSPATH' ) || exit;

/**
 * «Each bulk action first shows the validation report (counts by reason) and then enqueues.» Both halves are here:
 *
 * - {@see self::preview()} answers «what would happen» and writes nothing: how many offers the action reaches, and the rest grouped by the
 *   reason they are left out;
 * - {@see self::apply()} does it. It never trusts the preview (the shop may have changed while the window was open): it plans again
 *   from the stored links and acts on the targets of that second plan.
 *
 * An offer is a simple product or one variation; the caller has already normalised the selection to distinct offers
 * ({@see Site_Products_Selection}). An action counts an offer as done only when its write really happened: an offer the queue
 * refused, or a sync intent another writer overwrote, is reported as failed, never as accepted.
 *
 * Plan shape: `targets` (link rows the action changes; empty for export and for linking), `will` (offers the action reaches), `groups`
 * (left out, by reason: `reason`, `ids`, `fix`), `warnings` (offers that go on with a warning).
 *
 * @since 2.1.4
 */
final class Site_Products_Bulk {
	public const EXPORT          = 'export';
	public const LINK_BY_ARTICLE = 'link_by_article';
	public const SEND_STOCK      = 'send_stock';
	public const SEND_PRICE      = 'send_price';
	public const SYNC_STOCK_ON   = 'sync_stock_on';
	public const SYNC_STOCK_OFF  = 'sync_stock_off';
	public const SYNC_PRICE_ON   = 'sync_price_on';
	public const SYNC_PRICE_OFF  = 'sync_price_off';
	public const SET_WAREHOUSE   = 'set_warehouse';
	public const ARCHIVE         = 'archive';
	public const DETACH          = 'detach';
	public const RESET           = 'reset';

	/**
	 * The two price actions of #1797: drop a link's own price (a v1 migration leaves them on thousands of products), and pull the
	 * marketplace's prices into the shop once.
	 *
	 * @since 2.1.10
	 */
	public const RESET_PRICE = 'reset_price';
	public const PULL_PRICES = 'pull_prices';

	/**
	 * The two account actions the account line of a bulk run runs (#1795): stop the run it shows, and show its report. Both carry the
	 * run's id in the hidden field {@see self::RUN_FIELD}.
	 *
	 * @since 2.1.8
	 */
	public const RUN_STOP   = 'bulk_stop';
	public const RUN_REPORT = 'bulk_report';
	public const RUN_FIELD  = 'run_id';

	/**
	 * The two fields of «Связать по артикулу»: the switch «Весь магазин» and, while it is off, the category to link.
	 *
	 * @since 2.1.8
	 * @since 2.1.13 The switch replaced the select «Где связывать» (`where`).
	 */
	public const LINK_WHOLE_SHOP = 'whole_shop';
	public const LINK_CATEGORY   = 'category';

	/**
	 * Names examples under each reason in a group.
	 */
	private const EXAMPLES = 5;

	/**
	 * Most reasons a plan names on its own: every refused price quotes its own marketplace text, and the records engine's answer
	 * carries at most 50 groups, the ones added after the plan included.
	 */
	private const REASONS = 40;

	/**
	 * Longest marketplace text quoted in the reason of a refused price, in characters.
	 */
	private const REFUSAL_TEXT_LENGTH = 110;

	/**
	 * Actions whose work runs in the background after the request: the answer says «queued».
	 */
	private const BACKGROUND = [ self::EXPORT, self::LINK_BY_ARTICLE, self::SEND_STOCK, self::SEND_PRICE, self::ARCHIVE, self::PULL_PRICES ];

	/**
	 * @var Export_Service
	 */
	private $export;

	/**
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * @var Stock_Intents
	 */
	private $stock;

	/**
	 * @var Price_Intents
	 */
	private $prices;

	/**
	 * @var Price_Context
	 */
	private $contexts;

	/**
	 * @var Link_Detacher
	 */
	private $detacher;

	/**
	 * @var Link_Scheduler
	 */
	private $scheduler;

	/**
	 * @var Task_Ledger
	 */
	private $ledger;

	/**
	 * Counts the shop's products the linking job would try to match, in the whole shop or one category with its subcategories:
	 * `function ( int $account_id, ?int $category_id ): int`.
	 *
	 * @var callable
	 */
	private $link_candidates;

	/**
	 * Says what linking does on the marketplace: `function ( Account $account, string $unchanged ): string`, the sentence of {@see Card_Linker::remote_effect()}.
	 *
	 * @var callable
	 */
	private $link_effect;

	/**
	 * Tells whether the current user may edit a product: `function ( int $product_id ): bool`.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Names a product for an example in the report: `function ( int $product_id ): string`.
	 *
	 * @var callable
	 */
	private $describe;

	/**
	 * Tells whether the bulk run tick that is working a part still holds its run: `function (): bool`. Null outside a run. Asked before
	 * every product write ({@see self::guard()}).
	 *
	 * @var callable|null
	 */
	private $owns;

	/**
	 * Where each kind of fix is done: `Validation_Report::FIX_*` => address.
	 *
	 * @var array<string,string>
	 */
	private $fix_urls;

	/**
	 * Queues the one-time pull of prices for some articles: `function ( Account $account, string[] $offer_ids ): int`, the number of
	 * batches queued. Null when the adapter reads no prices, which also withholds the action.
	 *
	 * @var callable|null
	 */
	private $pull_prices;

	/**
	 * Creates the planner.
	 *
	 * @since 2.1.4
	 *
	 * @param Export_Service      $export          Export service.
	 * @param Wpdb_Link_Store     $links           Links.
	 * @param Stock_Intents       $stock           Stock intents (and the archive intent).
	 * @param Price_Intents       $prices          Price intents.
	 * @param Price_Context       $contexts        Reads a product and works out its price.
	 * @param Link_Detacher       $detacher        Detaches cards.
	 * @param Link_Scheduler      $scheduler       Queues the linking job.
	 * @param array<string,mixed> $callbacks       `link_candidates`, `can_edit`, `describe` (see the properties), `fix_urls` (FIX_* => address) and, optionally, `link_effect` and `pull_prices`.
	 */
	public function __construct( Export_Service $export, Wpdb_Link_Store $links, Stock_Intents $stock, Price_Intents $prices, Price_Context $contexts, Link_Detacher $detacher, Link_Scheduler $scheduler, Task_Ledger $ledger, array $callbacks ) {
		$this->export          = $export;
		$this->links           = $links;
		$this->stock           = $stock;
		$this->prices          = $prices;
		$this->contexts        = $contexts;
		$this->detacher        = $detacher;
		$this->scheduler       = $scheduler;
		$this->ledger          = $ledger;
		$this->link_candidates = $callbacks['link_candidates'];
		$this->link_effect     = $callbacks['link_effect'] ?? static function ( Account $account, string $unchanged ): string {
			return $unchanged;
		};
		$this->can_edit        = $callbacks['can_edit'];
		$this->describe        = $callbacks['describe'];
		$this->fix_urls        = (array) ( $callbacks['fix_urls'] ?? [] );
		$this->pull_prices     = $callbacks['pull_prices'] ?? null;
	}

	/**
	 * Every action id, in the order of the toolbar.
	 *
	 * @since 2.1.4
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return [
			self::EXPORT,
			self::LINK_BY_ARTICLE,
			self::SEND_STOCK,
			self::SEND_PRICE,
			self::SYNC_STOCK_ON,
			self::SYNC_STOCK_OFF,
			self::SYNC_PRICE_ON,
			self::SYNC_PRICE_OFF,
			self::SET_WAREHOUSE,
			self::ARCHIVE,
			self::DETACH,
			self::RESET,
			self::RESET_PRICE,
			self::PULL_PRICES,
		];
	}

	/**
	 * What a lapsed licence stops, in the words of the refusal: the actions that write to the marketplace.
	 *
	 * @since 2.1.8
	 *
	 * @param string $action Action id.
	 * @return string|null Null for an action that writes only on the site.
	 */
	public static function license_phrase( string $action ): ?string {
		$phrases = [
			self::EXPORT          => __( 'отправить товар на маркетплейс', 'woodev-plugin-framework' ),
			self::LINK_BY_ARTICLE => __( 'искать карточки на маркетплейсе', 'woodev-plugin-framework' ),
			self::SEND_STOCK      => __( 'отправить остатки на маркетплейс', 'woodev-plugin-framework' ),
			self::SEND_PRICE      => __( 'отправить цены на маркетплейс', 'woodev-plugin-framework' ),
			self::ARCHIVE         => __( 'архивировать карточку на маркетплейсе', 'woodev-plugin-framework' ),
			self::PULL_PRICES     => __( 'загрузить цены с маркетплейса', 'woodev-plugin-framework' ),
		];

		return $phrases[ $action ] ?? null;
	}

	/**
	 * Reads the reach of «Связать по артикулу» out of the dialog's fields: no category when «Весь магазин» is on, otherwise the term
	 * id of the chosen category, which must be a product category. Both tables that offer the action read it here.
	 *
	 * @since 2.1.8
	 *
	 * @param array<string,mixed> $payload Validated fields.
	 * @return int|null Null for the whole shop.
	 * @throws Action_Input_Exception When the switch is off and there is no category, or one that is not a product category.
	 */
	public function link_category( array $payload ): ?int {
		if ( true === ( $payload[ self::LINK_WHOLE_SHOP ] ?? false ) ) {
			return null;
		}

		$chosen = (string) ( $payload[ self::LINK_CATEGORY ] ?? '' );
		$term   = ctype_digit( $chosen ) ? (int) $chosen : 0;

		if ( $term <= 0 || ! Shop_Categories::exists( $term ) ) {
			throw new Action_Input_Exception( [ self::LINK_CATEGORY => __( 'Выберите категорию из списка.', 'woodev-plugin-framework' ) ] );
		}

		return $term;
	}

	/**
	 * What the action would do. Writes nothing.
	 *
	 * @since 2.1.4
	 *
	 * @param Account        $account   Account.
	 * @param string         $action    Action id.
	 * @param array<int,int> $offers    Distinct offers, from {@see Site_Products_Selection}.
	 * @param array<int,int> $editable  Offer => the product whose edit right decides it.
	 * @param int|null       $warehouse Warehouse for {@see self::SET_WAREHOUSE}; 0 for the account default.
	 * @param int|null       $category  Category (`product_cat` term id) {@see self::LINK_BY_ARTICLE} is limited to; null for the whole shop.
	 * @return array<string,mixed> The preview answer of the records engine.
	 */
	public function preview( Account $account, string $action, array $offers, array $editable, ?int $warehouse = null, ?int $category = null ): array {
		$plan = $this->plan( $account, $action, $offers, $editable, $warehouse, $category );

		if ( self::LINK_BY_ARTICLE === $action ) {
			return [
				'requested'      => $plan['will'],
				'eligible'       => $plan['will'],
				'skipped'        => 0,
				'scope_sentence' => $this->linking_sentence( $account, $plan['will'], $category ),
			];
		}

		$requested = count( $offers );
		$result    = [
			'requested' => $requested,
			'eligible'  => $plan['will'],
			'skipped'   => $requested - $plan['will'],
			'groups'    => $this->engine_groups( $plan['groups'], 'skipped' ),
		];

		if ( $plan['warnings'] > 0 ) {
			$result['warnings'] = [ $this->warning_sentence( $plan['warnings'] ) ];
		}

		return $result;
	}

	/**
	 * Does the action.
	 *
	 * @since 2.1.4
	 *
	 * @param Account        $account   Account.
	 * @param string         $action    Action id.
	 * @param array<int,int> $offers    Distinct offers.
	 * @param array<int,int> $editable  Offer => the product whose edit right decides it.
	 * @param int|null       $warehouse Warehouse for {@see self::SET_WAREHOUSE}.
	 * @param int|null       $category  Category {@see self::LINK_BY_ARTICLE} is limited to; null for the whole shop.
	 * @return array<string,mixed> The apply answer of the records engine.
	 * @throws Action_State_Exception When the queue or the scheduler refuses the work.
	 */
	public function apply( Account $account, string $action, array $offers, array $editable, ?int $warehouse = null, ?int $category = null ): array {
		$plan = $this->plan( $account, $action, $offers, $editable, $warehouse, $category );
		$ran  = $this->run( $account, $action, $plan, $warehouse );

		if ( self::LINK_BY_ARTICLE === $action ) {
			return [
				'requested' => $plan['will'],
				'accepted'  => $plan['will'],
				'skipped'   => 0,
				'failed'    => 0,
				'queued'    => true,
				'messages'  => $this->messages( $action, $plan['will'] ),
			];
		}

		$requested = count( $offers );
		$skipped   = $requested - $plan['will'];
		$failed    = $plan['will'] - $ran['done'];

		return [
			'requested' => $requested,
			'accepted'  => $ran['done'],
			'skipped'   => $skipped,
			'failed'    => $failed,
			'queued'    => in_array( $action, self::BACKGROUND, true ) && $ran['done'] > 0,
			'groups'    => array_merge( $this->engine_groups( $plan['groups'], 'skipped' ), $this->engine_groups( $ran['groups'], 'failed' ) ),
			'messages'  => $this->messages( $action, $ran['done'] ),
		];
	}

	/**
	 * Does one part of a bulk run: the same planning and writing the ticked mode uses, over the offers the run's cursor read (#1795).
	 * It replans from the stored links and never trusts a preview.
	 *
	 * @since 2.1.8
	 *
	 * @param Account        $account   Account.
	 * @param string         $action    Action id (any but {@see self::LINK_BY_ARTICLE}, which is an account action).
	 * @param array<int,int> $offers    Distinct offers of the part.
	 * @param array<int,int> $editable  Offer => the product whose edit right decides it.
	 * @param int|null       $warehouse Warehouse for {@see self::SET_WAREHOUSE}; 0 for the account default.
	 * @param callable       $owns      `function (): bool` — whether the tick still holds its run; asked before every product write.
	 * @return array{busy:bool,accepted:int,skipped:int,failed:int,groups:array<int,array<string,mixed>>} `busy` when the account's sender holds
	 *         the claim and nothing was changed: the part is tried again unchanged.
	 * @throws Action_State_Exception When the queue or the scheduler refuses the work.
	 * @throws Bulk_Run_Lost When `$owns` says the tick lost its run.
	 */
	public function run_part( Account $account, string $action, array $offers, array $editable, ?int $warehouse, callable $owns ): array {
		$this->owns = $owns;

		try {
			$plan = $this->plan( $account, $action, $offers, $editable, $warehouse, null );
			$ran  = $this->run( $account, $action, $plan, $warehouse );
		} finally {
			$this->owns = null;
		}

		$skipped = count( $offers ) - $plan['will'];
		$failed  = $plan['will'] - $ran['done'];

		return [
			'busy'     => true === ( $ran['busy'] ?? false ),
			'accepted' => $ran['done'],
			'skipped'  => $skipped,
			'failed'   => $failed,
			'groups'   => array_merge(
				$this->engine_groups( $this->explained( $plan['groups'], $skipped ), 'skipped' ),
				$this->engine_groups( $this->explained( $ran['groups'], $failed ), 'failed' )
			),
		];
	}

	/**
	 * Makes a part's groups explain every offer it left out, so the report's counts add up to the run's skipped and failed offers.
	 *
	 * @param array<int,array<string,mixed>> $groups   Groups the plan or the write gave.
	 * @param int                            $expected Offers the groups must account for.
	 * @return array<int,array<string,mixed>>
	 */
	private function explained( array $groups, int $expected ): array {
		$missing = $expected - (int) array_sum( array_column( $groups, 'count' ) );

		if ( $missing > 0 ) {
			$groups[] = $this->group( __( 'причина не указана', 'woodev-plugin-framework' ), [], null, $missing );
		}

		return $groups;
	}

	/**
	 * Refuses a product write once the bulk run tick that works the part has lost its run (the connection that held the named lock is
	 * gone). Outside a bulk run there is nothing to lose. Public only so that it can be handed to the services whose loops write products
	 * ({@see Export_Service::enqueue()}, {@see Link_Detacher::detach()}).
	 *
	 * @return void
	 * @throws Bulk_Run_Lost When the tick no longer owns the run.
	 */
	public function guard(): void {
		if ( null !== $this->owns && ! call_user_func( $this->owns ) ) {
			throw new Bulk_Run_Lost();
		}
	}

	/**
	 * Plans an action without writing.
	 *
	 * @param Account        $account   Account.
	 * @param string         $action    Action id.
	 * @param array<int,int> $offers    Offers.
	 * @param array<int,int> $editable  Offer => product whose right decides.
	 * @param int|null       $warehouse Warehouse.
	 * @param int|null       $category  Category of the linking; null for the whole shop.
	 * @return array{targets:Link_Record[],will:int,warnings:int,groups:array<int,array<string,mixed>>}
	 */
	private function plan( Account $account, string $action, array $offers, array $editable, ?int $warehouse, ?int $category ): array {
		if ( self::LINK_BY_ARTICLE === $action ) {
			return $this->plan_linking( $account, $category );
		}

		list( $allowed, $denied ) = $this->by_right( $offers, $editable );
		$busy                     = [];

		if ( in_array( $action, [ self::EXPORT, self::DETACH ], true ) ) {
			list( $allowed, $busy ) = $this->without_open_tasks( $account, $allowed );
		}

		$plan           = self::EXPORT === $action ? $this->plan_export( $account, $allowed ) : $this->plan_links( $account, $action, $allowed, $warehouse );
		$plan['offers'] = $allowed;

		if ( [] !== $denied ) {
			$plan['groups'][] = $this->group( __( 'у вас нет прав менять этот товар', 'woodev-plugin-framework' ), $denied );
		}

		if ( [] !== $busy ) {
			$plan['groups'][] = $this->group( __( 'карточка сейчас отправляется на маркетплейс — дождитесь результата', 'woodev-plugin-framework' ), $busy );
		}

		return $plan;
	}

	/**
	 * Splits offers by the current user's right to edit them.
	 *
	 * @param array<int,int> $offers   Offers.
	 * @param array<int,int> $editable Offer => product whose right decides.
	 * @return array{0:int[],1:int[]} Allowed, denied.
	 */
	private function by_right( array $offers, array $editable ): array {
		$allowed = [];
		$denied  = [];

		foreach ( $offers as $offer ) {
			if ( call_user_func( $this->can_edit, (int) ( $editable[ $offer ] ?? $offer ) ) ) {
				$allowed[] = $offer;
			} else {
				$denied[] = $offer;
			}
		}

		return [ $allowed, $denied ];
	}

	/**
	 * Splits offers by whether an export task of theirs is still open. The result of that task is applied to the link by id; queueing
	 * or detaching the card meanwhile would drop it, so the activity column's lock is enforced here too.
	 *
	 * @param Account $account Account.
	 * @param int[]   $offers  Offers.
	 * @return array{0:int[],1:int[]} Free, busy.
	 */
	private function without_open_tasks( Account $account, array $offers ): array {
		$by_link = [];

		foreach ( $this->links->offers_of( $account->id(), $offers ) as $record ) {
			$by_link[ (int) $record->link_id() ] = $record->wc_product_id();
		}

		$open = [] === $by_link ? [] : $this->ledger->open_items( $account->id(), array_keys( $by_link ) );
		$busy = array_values( array_intersect_key( $by_link, $open ) );

		return [ array_values( array_diff( $offers, $busy ) ), $busy ];
	}

	/**
	 * Plans the export: the validator decides what is valid, the link store decides what it will accept.
	 *
	 * @param Account $account Account.
	 * @param int[]   $offers  Offers.
	 * @return array{targets:Link_Record[],will:int,warnings:int,groups:array<int,array<string,mixed>>}
	 */
	private function plan_export( Account $account, array $offers ): array {
		if ( [] === $offers ) {
			return $this->empty_plan();
		}

		$report  = $this->export->preview( $account, $offers );
		$refused = [];
		$will    = 0;

		foreach ( $report->valid_ids() as $product_id ) {
			$link  = $this->links->find( $account->id(), $product_id );
			$state = null === $link ? '' : $link->card_state();

			if ( null !== $this->refusal( $state ) ) {
				$refused[ $product_id ] = $state;
				continue;
			}

			++$will;
		}

		return [
			'targets'  => [],
			'will'     => $will,
			'warnings' => count( $report->warned_ids() ),
			'groups'   => array_merge( $this->report_groups( $report, $offers ), $this->refusal_groups( $refused ) ),
		];
	}

	/**
	 * Turns the validator's groups into «left out» groups, and names the offers the marketplace does not sell.
	 *
	 * @param Bulk_Validation_Report $report Report.
	 * @param int[]                  $offers Offers asked about.
	 * @return array<int,array<string,mixed>>
	 */
	private function report_groups( Bulk_Validation_Report $report, array $offers ): array {
		$groups = [];

		foreach ( $report->groups() as $group ) {
			$groups[] = $this->group( $group['summary'], $group['product_ids'], $group['fix'] );
		}

		$not_sold = array_values( array_diff( $offers, $report->valid_ids(), $report->blocked_ids() ) );

		if ( [] !== $not_sold ) {
			$groups[] = $this->group( __( 'этот товар не продаётся на маркетплейсе (например, виртуальный или скачиваемый)', 'woodev-plugin-framework' ), $not_sold );
		}

		return $groups;
	}

	/**
	 * Does the export and counts what the queue accepted, not what the validator passed.
	 *
	 * @param Account $account Account.
	 * @param int[]   $offers  Offers.
	 * @return array{done:int,groups:array<int,array<string,mixed>>}
	 * @throws Action_State_Exception When Action Scheduler or the database refuses the work.
	 */
	private function run_export( Account $account, array $offers ): array {
		if ( [] === $offers ) {
			return [
				'done'   => 0,
				'groups' => [],
			];
		}

		try {
			$out = $this->export->enqueue( $account, $offers, [ $this, 'guard' ] );
		} catch ( \RuntimeException $e ) {
			throw new Action_State_Exception( __( 'Не удалось поставить товары в очередь. Проверьте, что в WooCommerce работает планировщик задач (Action Scheduler), и повторите.', 'woodev-plugin-framework' ) );
		}

		return [
			'done'   => count( $out['queued'] ),
			'groups' => $this->refusal_groups( $out['refused'] ),
		];
	}

	/**
	 * Groups the valid offers the queue refuses by the reason.
	 *
	 * @param array<int,string> $refused Product id => card state.
	 * @return array<int,array<string,mixed>>
	 */
	private function refusal_groups( array $refused ): array {
		$by_reason = [];

		foreach ( $refused as $product_id => $state ) {
			// The state was read before the queue refused: another request may have moved the card since, so there may be no state reason.
			$by_reason[ $this->refusal( $state ) ?? __( 'карточку одновременно изменил другой запрос — повторите действие', 'woodev-plugin-framework' ) ][] = $product_id;
		}

		$groups = [];

		foreach ( $by_reason as $reason => $ids ) {
			$groups[] = $this->group( (string) $reason, $ids );
		}

		return $groups;
	}

	/**
	 * Says why the shared queue refuses a card in this state (`Wpdb_Link_Store::queue()` leaves submitted and archived cards alone).
	 *
	 * @param string $state Card state.
	 * @return string|null Reason, or null when the queue accepts it.
	 */
	private function refusal( string $state ): ?string {
		if ( Link_Record::STATE_SUBMITTED === $state ) {
			return __( 'карточка сейчас отправляется на маркетплейс — дождитесь результата', 'woodev-plugin-framework' );
		}

		return Link_Record::STATE_ARCHIVED === $state ? __( 'карточка в архиве маркетплейса — отправить её снова нельзя', 'woodev-plugin-framework' ) : null;
	}

	/**
	 * Plans the linking by article. The job reads the whole cabinet, so the preview counts the shop's candidates (products with an
	 * article that have no identity yet) in the chosen reach — the whole shop or one category with its subcategories — never the
	 * ticked subset.
	 *
	 * @param Account  $account  Account.
	 * @param int|null $category Category the job is limited to; null for the whole shop.
	 * @return array{targets:Link_Record[],will:int,warnings:int,groups:array<int,array<string,mixed>>,category:int|null}
	 */
	private function plan_linking( Account $account, ?int $category ): array {
		$plan             = $this->empty_plan();
		$plan['will']     = (int) call_user_func( $this->link_candidates, $account->id(), $category );
		$plan['category'] = $category;

		return $plan;
	}

	/**
	 * Plans an action on stored links.
	 *
	 * @param Account  $account   Account.
	 * @param string   $action    Action id.
	 * @param int[]    $offers    Offers the user may edit.
	 * @param int|null $warehouse Warehouse for {@see self::SET_WAREHOUSE}.
	 * @return array{targets:Link_Record[],will:int,warnings:int,groups:array<int,array<string,mixed>>}
	 */
	private function plan_links( Account $account, string $action, array $offers, ?int $warehouse ): array {
		$records = $this->links->offers_of( $account->id(), $offers );
		$targets = [];
		$enabled = [];
		$reasons = [];

		foreach ( $records as $record ) {
			$reason = $this->reason_to_skip( $account, $action, $record, $warehouse );

			if ( null === $reason ) {
				$targets[] = $record;
				continue;
			}

			// A switch-on or a warehouse choice that was interrupted after the rows changed (a lost tick, a refused intent write)
			// leaves cards that are so and have no first send; the retry must still record it, though «already so» keeps them out
			// of the targets.
			if ( $this->is_already_so( $action, $record, $warehouse ) ) {
				$enabled[] = $record;
			}

			$reasons[ $reason ][] = $record->wc_product_id();
		}

		$unlinked = array_values( array_diff( $offers, array_map( [ $this, 'product_of' ], $records ) ) );

		if ( [] !== $unlinked ) {
			$reasons[ __( 'не отправлялся на маркетплейс — сначала экспортируйте товар', 'woodev-plugin-framework' ) ] = $unlinked;
		}

		$groups = [];

		foreach ( $reasons as $text => $ids ) {
			$groups[] = $this->group( (string) $text, $ids );
		}

		usort(
			$groups,
			static function ( array $left, array $right ): int {
				return $right['count'] <=> $left['count'];
			}
		);

		return [
			'targets'  => $targets,
			'enabled'  => $enabled,
			'will'     => count( $targets ),
			'warnings' => 0,
			'groups'   => $this->bounded( $groups ),
		];
	}

	/**
	 * Folds the smallest groups into one «other reasons» group, so that the counts still add up.
	 *
	 * @param array<int,array<string,mixed>> $groups Groups, the biggest first.
	 * @return array<int,array<string,mixed>>
	 */
	private function bounded( array $groups ): array {
		if ( count( $groups ) <= self::REASONS ) {
			return $groups;
		}

		$named = array_slice( $groups, 0, self::REASONS - 1 );
		$rest  = (int) array_sum( array_column( array_slice( $groups, self::REASONS - 1 ), 'count' ) );

		$named[] = $this->group( Bulk_Run_Report::overflow_reason(), [], null, $rest );

		return $named;
	}

	/**
	 * Tells whether a switch-on or a warehouse choice finds the card already so (a created one: only those have a first send to
	 * record).
	 *
	 * @param string      $action    Action id.
	 * @param Link_Record $record    Link row.
	 * @param int|null    $warehouse Warehouse for {@see self::SET_WAREHOUSE}.
	 * @return bool
	 */
	private function is_already_so( string $action, Link_Record $record, ?int $warehouse ): bool {
		if ( Link_Record::STATE_CREATED !== $record->card_state() ) {
			return false;
		}

		if ( self::SET_WAREHOUSE === $action ) {
			return null !== $warehouse && ( $record->warehouse_id() ?? 0 ) === $warehouse;
		}

		return ( self::SYNC_STOCK_ON === $action && $record->sync_stock() ) || ( self::SYNC_PRICE_ON === $action && $record->sync_price() );
	}

	/**
	 * @param Link_Record $record Link row.
	 * @return int
	 */
	private function product_of( Link_Record $record ): int {
		return $record->wc_product_id();
	}

	/**
	 * Tells why a link row is left out of an action, or null when the action reaches it.
	 *
	 * @param Account     $account   Account.
	 * @param string      $action    Action id.
	 * @param Link_Record $record    Link row.
	 * @param int|null    $warehouse Warehouse for {@see self::SET_WAREHOUSE}.
	 * @return string|null Reason.
	 */
	private function reason_to_skip( Account $account, string $action, Link_Record $record, ?int $warehouse ): ?string {
		switch ( $action ) {
			case self::SYNC_STOCK_ON:
			case self::SYNC_STOCK_OFF:
				return $record->sync_stock() === ( self::SYNC_STOCK_ON === $action ) ? __( 'уже настроено так', 'woodev-plugin-framework' ) : null;
			case self::SYNC_PRICE_ON:
			case self::SYNC_PRICE_OFF:
				return $record->sync_price() === ( self::SYNC_PRICE_ON === $action ) ? __( 'уже настроено так', 'woodev-plugin-framework' ) : null;
			case self::SET_WAREHOUSE:
				return null !== $warehouse && ( $record->warehouse_id() ?? 0 ) === $warehouse ? __( 'этот склад уже выбран', 'woodev-plugin-framework' ) : null;
			case self::RESET:
				return $this->reason_not_to_reset( $record );
			case self::RESET_PRICE:
				return null === $record->price_rule_override() ? __( 'своей цены нет', 'woodev-plugin-framework' ) : null;
			case self::PULL_PRICES:
				return Link_Record::STATE_CREATED === $record->card_state() ? null : __( 'карточка ещё не создана на маркетплейсе', 'woodev-plugin-framework' );
			case self::ARCHIVE:
				return Link_Record::STATE_CREATED === $record->card_state() ? null : __( 'на маркетплейсе нет созданной карточки этого товара', 'woodev-plugin-framework' );
			case self::DETACH:
				return $this->reason_not_to_detach( $record );
			default:
				return $this->reason_not_to_send( $account, $action, $record );
		}
	}

	/**
	 * Tells why a reset would change nothing: the card is `unlinked` and carries no stored error either. An `unlinked` card that still
	 * shows the validation error of a blocked export is reset, because that clears the error column.
	 *
	 * @param Link_Record $record Link row.
	 * @return string|null
	 */
	private function reason_not_to_reset( Link_Record $record ): ?string {
		if ( Link_Record::STATE_UNLINKED !== $record->card_state() ) {
			return null;
		}

		$error = $this->links->last_error( (int) $record->link_id() );

		return '' === trim( $error['text'] ) && '' === trim( $error['code'] ) ? __( 'состояние и так «Не связан», ошибок нет', 'woodev-plugin-framework' ) : null;
	}

	/**
	 * Tells why a detach would change nothing or would be refused.
	 *
	 * @param Link_Record $record Link row.
	 * @return string|null
	 */
	private function reason_not_to_detach( Link_Record $record ): ?string {
		if ( Link_Record::STATE_UNLINKED === $record->card_state() ) {
			return __( 'связи нет — снимать нечего', 'woodev-plugin-framework' );
		}

		return Link_Record::STATE_SUBMITTED === $record->card_state() ? __( 'карточка сейчас отправляется на маркетплейс — дождитесь результата', 'woodev-plugin-framework' ) : null;
	}

	/**
	 * Tells why stock or price of a card is not sent, in the order a merchant fixes it.
	 *
	 * @param Account     $account Account.
	 * @param string      $action  {@see self::SEND_STOCK} or {@see self::SEND_PRICE}.
	 * @param Link_Record $record  Link row.
	 * @return string|null
	 */
	private function reason_not_to_send( Account $account, string $action, Link_Record $record ): ?string {
		$is_stock = self::SEND_STOCK === $action;

		if ( Link_Record::STATE_CREATED !== $record->card_state() ) {
			return __( 'карточка ещё не создана на маркетплейсе', 'woodev-plugin-framework' );
		}

		if ( ! ( $is_stock ? $record->sync_stock() : $record->sync_price() ) ) {
			return $is_stock ? __( 'синхронизация остатков выключена', 'woodev-plugin-framework' ) : __( 'синхронизация цен выключена', 'woodev-plugin-framework' );
		}

		if ( $is_stock && ! Stock_Mode::is_site( $account ) ) {
			return __( 'в настройках аккаунта остатки не отправляются на маркетплейс', 'woodev-plugin-framework' );
		}

		if ( ! $is_stock && ! Price_Intents::pushes( $account ) ) {
			return __( 'в настройках аккаунта цены на маркетплейс не отправляются', 'woodev-plugin-framework' );
		}

		return $is_stock ? $this->reason_without_stock( $account, $record ) : $this->reason_without_price( $account, $record );
	}

	/**
	 * Asks what the stock send would store, without storing, and names the gap, so the preview and the write agree.
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $record  Link row.
	 * @return string|null
	 */
	private function reason_without_stock( Account $account, Link_Record $record ): ?string {
		if ( null === Stock_Warehouse::for_link( $account, $record ) ) {
			return null === Stock_Warehouse::chosen( $account, $record )
				? __( 'не выбран склад', 'woodev-plugin-framework' )
				: __( 'выбранный склад отключён в маркетплейсе', 'woodev-plugin-framework' );
		}

		$product = $this->contexts->product( $record->wc_product_id() );

		if ( null === $product ) {
			return __( 'товар не найден в магазине', 'woodev-plugin-framework' );
		}

		$managed = $product->managing_stock() || (int) $product->get_stock_managed_by_id() !== $product->get_id();

		return $managed && null !== $product->get_stock_quantity() ? null : __( 'для товара не ведётся учёт остатков', 'woodev-plugin-framework' );
	}

	/**
	 * Asks what the price send would store, without storing, and names the gap: a product with no price to send, and a price the
	 * marketplace already turned down, which would not go out.
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $record  Link row.
	 * @return string|null
	 */
	private function reason_without_price( Account $account, Link_Record $record ): ?string {
		$product = $this->contexts->product( $record->wc_product_id() );

		if ( null === $product ) {
			return __( 'товар не найден в магазине', 'woodev-plugin-framework' );
		}

		try {
			$pair = $this->contexts->price_pair( $account, $product, $record );
		} catch ( \InvalidArgumentException $e ) {
			return $this->unreadable_price();
		}

		if ( null === $pair ) {
			return __( 'у товара нет цены, которую можно отправить', 'woodev-plugin-framework' );
		}

		$held = $this->prices->held( $account, $record, $pair );

		return null === $held ? null : $this->held_price_reason( $held['code'], $held['message'] );
	}

	/**
	 * Says why a price the marketplace turned down is not sent again, and what to do. The sentence stays short enough for the report
	 * of a bulk run, so a long marketplace text is cut, never the advice before it.
	 *
	 * @param string $code    `Price_Refusal` code; '' when only the refusal itself is known.
	 * @param string $message The stored text of the refusal.
	 * @return string
	 */
	private function held_price_reason( string $code, string $message ): string {
		if ( Price_Refusal::QUARANTINED === $code ) {
			/* translators: %s: the sentence about the quarantine, which names the marketplace. */
			return sprintf( __( '%s. Не отправится, пока вы не измените цену; карантин снимается и в кабинете маркетплейса', 'woodev-plugin-framework' ), $message );
		}

		if ( Price_Refusal::EXPIRED === $code ) {
			return $message;
		}

		if ( '' === $message ) {
			return __( 'маркетплейс уже не принял эту цену — измените её, чтобы отправить', 'woodev-plugin-framework' );
		}

		if ( mb_strlen( $message ) > self::REFUSAL_TEXT_LENGTH ) {
			$message = mb_substr( $message, 0, self::REFUSAL_TEXT_LENGTH - 1 ) . '…';
		}

		/* translators: %s: the marketplace's reason. */
		return sprintf( __( 'маркетплейс не принял эту цену — измените её, чтобы отправить: %s', 'woodev-plugin-framework' ), $message );
	}

	/**
	 * @return string
	 */
	private function unreadable_price(): string {
		return __( 'цену не удаётся рассчитать: проверьте правило цены и курс', 'woodev-plugin-framework' );
	}

	/**
	 * Runs a plan.
	 *
	 * @param Account             $account   Account.
	 * @param string              $action    Action id.
	 * @param array<string,mixed> $plan      The plan.
	 * @param int|null            $warehouse Warehouse.
	 * @return array{done:int,groups:array<int,array<string,mixed>>,busy?:bool} Offers really changed, and the ones the write left out.
	 * @throws Action_State_Exception When the queue or the scheduler refuses the work.
	 * @throws Bulk_Run_Lost When a bulk run tick lost its run before a write.
	 */
	private function run( Account $account, string $action, array $plan, ?int $warehouse ): array {
		switch ( $action ) {
			case self::LINK_BY_ARTICLE:
				return $this->run_linking( $account, $plan );
			case self::EXPORT:
				return $this->run_export( $account, $plan['offers'] );
			case self::SEND_STOCK:
			case self::SEND_PRICE:
				return $this->run_send( $account, $plan['targets'], self::SEND_STOCK === $action );
			case self::ARCHIVE:
				return $this->run_archive( $plan['targets'] );
			case self::DETACH:
				return $this->run_detach( $account, $plan['targets'] );
			case self::RESET:
				return $this->run_reset( $account, $plan['targets'] );
			case self::RESET_PRICE:
				return $this->run_reset_price( $account, $plan['targets'] );
			case self::PULL_PRICES:
				return $this->run_pull_prices( $account, $plan['targets'] );
			default:
				return [
					'done'   => $this->run_flags( $account, $action, $plan['targets'], $warehouse, $plan['enabled'] ?? [] ),
					'groups' => [],
				];
		}
	}

	/**
	 * Applies a flag or warehouse change. Switching a flag on also records the first send of the cards it reached (#1795, D1),
	 * and choosing a warehouse records their stock for it.
	 *
	 * @param Account       $account   Account.
	 * @param string        $action    Action id.
	 * @param Link_Record[] $targets   Rows.
	 * @param int|null      $warehouse Warehouse.
	 * @param Link_Record[] $enabled   Created cards a switch-on found already on, or a warehouse choice found already on that
	 *                                 warehouse: their first send is recorded too, so a part that was interrupted after the rows
	 *                                 changed is finished by its retry.
	 * @return int Rows changed.
	 * @throws Bulk_Run_Lost When a bulk run tick lost its run before a write.
	 * @throws Action_State_Exception When a first send could not be stored: the flags are on, and repeating the action records it.
	 */
	private function run_flags( Account $account, string $action, array $targets, ?int $warehouse, array $enabled = [] ): int {
		$link_ids = array_map(
			static function ( Link_Record $record ): int {
				return (int) $record->link_id();
			},
			$targets
		);

		$this->guard();

		switch ( $action ) {
			case self::SYNC_STOCK_ON:
			case self::SYNC_STOCK_OFF:
				$changed = $this->links->set_sync_flag( $account->id(), $link_ids, 'sync_stock', self::SYNC_STOCK_ON === $action );
				break;
			case self::SYNC_PRICE_ON:
			case self::SYNC_PRICE_OFF:
				$changed = $this->links->set_sync_flag( $account->id(), $link_ids, 'sync_price', self::SYNC_PRICE_ON === $action );
				break;
			default: // self::SET_WAREHOUSE, the one action left.
				$changed = $this->links->set_warehouse( $account->id(), $link_ids, (int) $warehouse > 0 ? (int) $warehouse : null );
		}

		if ( in_array( $action, [ self::SYNC_STOCK_ON, self::SYNC_PRICE_ON, self::SET_WAREHOUSE ], true ) ) {
			$this->record_first_send( $account, array_merge( $targets, $enabled ), $action );
		}

		return $changed;
	}

	/**
	 * Puts cards back to «Не связан». A reset the database refused changes nothing, so the merchant can run it again.
	 *
	 * @param Account       $account Account.
	 * @param Link_Record[] $targets Rows.
	 * @return array{done:int,groups:array<int,array<string,mixed>>}
	 * @throws Bulk_Run_Lost When a bulk run tick lost its run before the write.
	 */
	private function run_reset( Account $account, array $targets ): array {
		$link_ids = array_map(
			static function ( Link_Record $record ): int {
				return (int) $record->link_id();
			},
			$targets
		);

		$this->guard();

		try {
			return [
				'done'   => $this->export->reset_links( $account, $link_ids ),
				'groups' => [],
			];
		} catch ( Persistence_Exception $e ) {
			$ids = array_map( [ $this, 'product_of' ], $targets );

			return [
				'done'   => 0,
				'groups' => [ $this->group( __( 'состояние не сброшено: изменения не записались в базу данных — повторите действие', 'woodev-plugin-framework' ), $ids ) ],
			];
		}
	}

	/**
	 * Records the first send of cards whose synchronisation was just switched on, as the product tab does on switch-on (#1795, D1):
	 * a merchant who switched stock sync on for three thousand products expects their stock on the marketplace, not an empty column
	 * until each product happens to change. A chosen warehouse is the same case: it gets the stock, and the left one zero. The intent
	 * decides whether anything goes (the account's stock mode, a warehouse, managed stock; the price direction) and records nothing
	 * otherwise, so a card with nothing to send is simply left alone. Only created cards have anything to send to.
	 *
	 * @param Account       $account Account.
	 * @param Link_Record[] $targets Rows the action reached, and the created ones it found already so.
	 * @param string        $action  {@see self::SYNC_STOCK_ON}, {@see self::SYNC_PRICE_ON} or {@see self::SET_WAREHOUSE}.
	 * @return void
	 * @throws Bulk_Run_Lost When a bulk run tick lost its run.
	 * @throws Action_State_Exception When an intent could not be stored.
	 */
	private function record_first_send( Account $account, array $targets, string $action ): void {
		$created = [];

		foreach ( $targets as $record ) {
			if ( Link_Record::STATE_CREATED === $record->card_state() ) {
				$created[] = $record->wc_product_id();
			}
		}

		if ( [] === $created ) {
			return;
		}

		// The rows as the switch left them: the intent reads the switch.
		foreach ( $this->links->offers_of( $account->id(), $created ) as $fresh ) {
			$this->guard();

			try {
				if ( self::SYNC_PRICE_ON === $action ) {
					$this->prices->record_for_link( $account, $fresh );
				} else {
					$this->stock->record_stock( $fresh );
				}
			} catch ( \RuntimeException $e ) {
				// Never reported as done: a card that is on with no first send would be skipped as «already so» by every later try.
				throw new Action_State_Exception(
					self::SET_WAREHOUSE === $action
						? __( 'Склад выбран, но отправку остатков записать не удалось. Повторите действие — оно запишет недостающее.', 'woodev-plugin-framework' )
						: __( 'Синхронизация включена, но первую отправку записать не удалось. Повторите действие — оно запишет недостающее.', 'woodev-plugin-framework' )
				);
			}
		}
	}

	/**
	 * Queues the linking of the account's cards by article. It covers the whole shop or the plan's category, not only the ticked
	 * products.
	 *
	 * @param Account             $account Account.
	 * @param array<string,mixed> $plan    The plan (`category`: the term id, or null for the whole shop).
	 * @return array{done:int,groups:array<int,array<string,mixed>>}
	 * @throws Action_State_Exception When Action Scheduler refuses the job.
	 */
	private function run_linking( Account $account, array $plan ): array {
		if ( ! $this->scheduler->link_by_article( $account->id(), $plan['category'] ?? null ) ) {
			throw new Action_State_Exception( __( 'Не удалось поставить поиск в очередь. Проверьте, что в WooCommerce работает планировщик задач (Action Scheduler), и повторите.', 'woodev-plugin-framework' ) );
		}

		return [
			'done'   => (int) $plan['will'],
			'groups' => [],
		];
	}

	/**
	 * Marks stock or price of cards as wanted on the marketplace; the sender picks them up. An offer counts only when its intent was
	 * stored: one with nothing to send, or whose intent another request kept overwriting, is reported instead.
	 *
	 * @param Account       $account  Account.
	 * @param Link_Record[] $targets  Created cards.
	 * @param bool          $is_stock Stock when true, price when false.
	 * @return array{done:int,groups:array<int,array<string,mixed>>}
	 */
	private function run_send( Account $account, array $targets, bool $is_stock ): array {
		$marked  = 0;
		$reasons = [];

		foreach ( $targets as $record ) {
			$this->guard();

			try {
				$reason = $is_stock ? $this->record_stock( $account, $record ) : $this->record_price( $account, $record );
			} catch ( \RuntimeException $e ) {
				$reason = __( 'данные одновременно менял другой запрос — повторите действие', 'woodev-plugin-framework' );
			}

			if ( null === $reason ) {
				++$marked;
				continue;
			}

			$reasons[ $reason ][] = $record->wc_product_id();
		}

		return [
			'done'   => $marked,
			'groups' => $this->groups_of( $reasons ),
		];
	}

	/**
	 * @param Account     $account Account.
	 * @param Link_Record $record  Link row.
	 * @return string|null Why nothing was stored, null when the intent was.
	 */
	private function record_stock( Account $account, Link_Record $record ): ?string {
		if ( $this->stock->record_stock( $record ) ) {
			return null;
		}

		return $this->reason_without_stock( $account, $record ) ?? __( 'нечего отправлять', 'woodev-plugin-framework' );
	}

	/**
	 * @param Account     $account Account.
	 * @param Link_Record $record  Link row.
	 * @return string|null Why nothing was stored, null when the intent was.
	 */
	private function record_price( Account $account, Link_Record $record ): ?string {
		$outcome = $this->prices->record_for_link( $account, $record );

		$reasons = [
			Price_Intents::OFF         => __( 'синхронизация цен выключена', 'woodev-plugin-framework' ),
			Price_Intents::NOT_CREATED => __( 'карточка ещё не создана на маркетплейсе', 'woodev-plugin-framework' ),
			Price_Intents::NO_PRICE    => __( 'у товара нет цены, которую можно отправить', 'woodev-plugin-framework' ),
			Price_Intents::INVALID     => $this->unreadable_price(),
		];

		return Price_Intents::RECORDED === $outcome ? null : ( $reasons[ $outcome ] ?? __( 'нечего отправлять', 'woodev-plugin-framework' ) );
	}

	/**
	 * Removes the link's own price (#1797, D2): the price for the marketplace is worked out by the account's rule again, and the new
	 * price is recorded as an intent for the sender when the account pushes prices. A v1 shop that set prices per category leaves such
	 * overrides on thousands of migrated links, and the product tab clears them one by one.
	 *
	 * @param Account       $account Account.
	 * @param Link_Record[] $targets Links that carry an override.
	 * @return array{done:int,groups:array<int,array<string,mixed>>}
	 * @throws Bulk_Run_Lost When a bulk run tick lost its run before a write.
	 */
	private function run_reset_price( Account $account, array $targets ): array {
		$cleared = 0;
		$missed  = [];

		foreach ( $targets as $record ) {
			$this->guard();

			try {
				$this->prices->change_override( $account, $record, null );
				++$cleared;
			} catch ( \RuntimeException $e ) {
				$missed[] = $record->wc_product_id();
			}
		}

		return [
			'done'   => $cleared,
			'groups' => [] === $missed ? [] : [ $this->group( __( 'не удалось сбросить цену — повторите действие', 'woodev-plugin-framework' ), $missed ) ],
		];
	}

	/**
	 * Queues the one-time pull of the marketplace's prices of created cards (#1797, D3). The job reads the prices and writes them into
	 * the products; what it skips (a price the rule cannot be undone for) goes to the log, not to this report, which only knows what
	 * was queued.
	 *
	 * @param Account       $account Account.
	 * @param Link_Record[] $targets Created cards.
	 * @return array{done:int,groups:array<int,array<string,mixed>>}
	 * @throws Bulk_Run_Lost When a bulk run tick lost its run before the write.
	 * @throws Action_State_Exception When Action Scheduler refuses the batches, or the adapter reads no prices.
	 */
	private function run_pull_prices( Account $account, array $targets ): array {
		if ( null === $this->pull_prices ) {
			throw new Action_State_Exception( __( 'Этот маркетплейс не отдаёт цены для загрузки.', 'woodev-plugin-framework' ) );
		}

		$offers = [];

		foreach ( $targets as $record ) {
			$offers[] = (string) $record->offer_id();
		}

		if ( [] === $offers ) {
			return [
				'done'   => 0,
				'groups' => [],
			];
		}

		$this->guard();

		try {
			call_user_func( $this->pull_prices, $account, $offers );
		} catch ( \RuntimeException $e ) {
			throw new Action_State_Exception( __( 'Не удалось поставить загрузку цен в очередь. Проверьте, что в WooCommerce работает планировщик задач (Action Scheduler), и повторите.', 'woodev-plugin-framework' ) );
		}

		return [
			'done'   => count( $offers ),
			'groups' => [],
		];
	}

	/**
	 * Records the archive intent of created cards: the account's sender sets the stock to zero and then archives the card.
	 *
	 * @param Link_Record[] $targets Created cards.
	 * @return array{done:int,groups:array<int,array<string,mixed>>}
	 */
	private function run_archive( array $targets ): array {
		$recorded = 0;
		$missed   = [];

		foreach ( $targets as $record ) {
			$this->guard();

			try {
				$done = $this->stock->record_archive( $record );
			} catch ( \RuntimeException $e ) {
				$done = false;
			}

			if ( $done ) {
				++$recorded;
			} else {
				$missed[] = $record->wc_product_id();
			}
		}

		return [
			'done'   => $recorded,
			'groups' => [] === $missed ? [] : [ $this->group( __( 'не удалось записать архивацию — повторите действие', 'woodev-plugin-framework' ), $missed ) ],
		];
	}

	/**
	 * Forgets the cards on the site; nothing is sent to the marketplace. The detacher takes the account's claim, so while the sender holds it
	 * nothing changes.
	 *
	 * @param Account       $account Account.
	 * @param Link_Record[] $targets Linked cards.
	 * @return array{done:int,groups:array<int,array<string,mixed>>,busy?:bool} `busy` when the account's sender holds the claim and nothing was changed.
	 * @throws Bulk_Run_Lost When a bulk run tick lost its run before the write.
	 */
	private function run_detach( Account $account, array $targets ): array {
		if ( [] === $targets ) {
			return [
				'done'   => 0,
				'groups' => [],
			];
		}

		try {
			$outcome = $this->detacher->detach( $account->id(), $targets, [ $this, 'guard' ] );
		} catch ( \RuntimeException $e ) {
			throw new Action_State_Exception( __( 'Не удалось снять связь. Повторите чуть позже.', 'woodev-plugin-framework' ) );
		}

		$ids = array_map( [ $this, 'product_of' ], $targets );

		if ( $outcome['busy'] ) {
			return [
				'done'   => 0,
				'busy'   => true,
				'groups' => [ $this->group( __( 'сейчас идёт отправка остатков и цен этого аккаунта — повторите через минуту', 'woodev-plugin-framework' ), $ids ) ],
			];
		}

		return [
			'done'   => $outcome['detached'],
			// The detacher says how many it kept, not which: naming the first N targets would blame cards that were detached.
			'groups' => $outcome['kept'] > 0 ? [ $this->group( __( 'товар сейчас выгружается на маркетплейс — повторите позже', 'woodev-plugin-framework' ), [], null, $outcome['kept'] ) ] : [],
		];
	}

	/**
	 * @return array{targets:Link_Record[],will:int,warnings:int,groups:array<int,array<string,mixed>>}
	 */
	private function empty_plan(): array {
		return [
			'offers'   => [],
			'targets'  => [],
			'will'     => 0,
			'warnings' => 0,
			'groups'   => [],
		];
	}

	/**
	 * Builds one «left out» group.
	 *
	 * @param string      $reason Reason.
	 * @param int[]       $ids    Products with this reason.
	 * @param string|null $fix    `Validation_Report::FIX_*`.
	 * @param int|null    $count  How many offers, when it is known without naming them; the number of ids when null.
	 * @return array{reason:string,ids:int[],fix:?string,count:int}
	 */
	private function group( string $reason, array $ids, ?string $fix = null, ?int $count = null ): array {
		return [
			'reason' => $reason,
			'ids'    => array_values( $ids ),
			'fix'    => $fix,
			'count'  => $count ?? count( $ids ),
		];
	}

	/**
	 * @param array<string,int[]> $reasons Reason => product ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function groups_of( array $reasons ): array {
		$groups = [];

		foreach ( $reasons as $text => $ids ) {
			$groups[] = $this->group( (string) $text, $ids );
		}

		return $groups;
	}

	/**
	 * Gives groups the shape the records engine reads.
	 *
	 * @param array<int,array<string,mixed>> $groups Groups.
	 * @param string                         $kind   `skipped` or `failed`.
	 * @return array<int,array<string,mixed>>
	 */
	private function engine_groups( array $groups, string $kind ): array {
		$out = [];

		foreach ( $groups as $group ) {
			$fix   = $group['fix'];
			$label = null === $fix ? '' : $this->fix_label( $fix );
			$url   = null === $fix ? '' : (string) ( $this->fix_urls[ $fix ] ?? '' );

			$out[] = [
				'kind'      => $kind,
				'reason'    => $group['reason'],
				'count'     => $group['count'],
				'fix_label' => '' === $url ? '' : $label,
				'fix_url'   => $url,
				'examples'  => $this->examples( $group['ids'] ),
			];
		}

		return $out;
	}

	/**
	 * @param int[] $ids Products.
	 * @return string[]
	 */
	private function examples( array $ids ): array {
		return array_map(
			function ( int $id ): string {
				return mb_substr( (string) call_user_func( $this->describe, $id ), 0, 190 );
			},
			array_slice( $ids, 0, self::EXAMPLES )
		);
	}

	/**
	 * Says where a report line is fixed.
	 *
	 * @param string $fix `Validation_Report::FIX_*`.
	 * @return string
	 */
	private function fix_label( string $fix ): string {
		$labels = [
			Validation_Report::FIX_MAPPING    => __( 'Исправить в «Сопоставлении»', 'woodev-plugin-framework' ),
			Validation_Report::FIX_PROFILE    => __( 'Исправить в профиле выгрузки', 'woodev-plugin-framework' ),
			Validation_Report::FIX_DICTIONARY => __( 'Найти значение в «Сопоставлении»', 'woodev-plugin-framework' ),
			Validation_Report::FIX_ACCOUNT    => __( 'Исправить в настройках аккаунта', 'woodev-plugin-framework' ),
		];

		return $labels[ $fix ] ?? '';
	}

	/**
	 * The sentence under the title of the confirmation window for the linking: the whole shop or one category.
	 *
	 * @param Account  $account    Account.
	 * @param int      $candidates Shop products the job would try to match.
	 * @param int|null $category   Category the job is limited to; null for the whole shop.
	 * @return string
	 */
	private function linking_sentence( Account $account, int $candidates, ?int $category ): string {
		$effect = call_user_func( $this->link_effect, $account, __( 'На маркетплейсе ничего не записывается.', 'woodev-plugin-framework' ) );
		$count  = $candidates . ' ' . Bulk_Validation_Report::products( $candidates );

		if ( null !== $category ) {
			/* translators: 1: the category name, 2: «120 товаров», 3: what linking does on the marketplace. */
			return sprintf(
				__( 'Категория «%1$s» и её подкатегории — %2$s без связи с маркетплейсом. Карточки аккаунта читаются целиком, а связываются только товары этой категории. %3$s', 'woodev-plugin-framework' ),
				Shop_Categories::name_of( $category ),
				$count,
				$effect
			);
		}

		/* translators: 1: «120 товаров», 2: what linking does on the marketplace. */
		return sprintf(
			__( 'Весь аккаунт — %1$s магазина без связи с маркетплейсом. Связывание идёт по всему магазину, не только по отмеченным товарам. %2$s', 'woodev-plugin-framework' ),
			$count,
			$effect
		);
	}

	/**
	 * @param int $count Offers that go on with a warning.
	 * @return string
	 */
	private function warning_sentence( int $count ): string {
		/* translators: %s: «3 товара». */
		return sprintf( __( 'Уйдут с предупреждением: %s. Подробности — в карточке товара.', 'woodev-plugin-framework' ), $count . ' ' . Bulk_Validation_Report::products( $count ) );
	}

	/**
	 * The sentences that report a finished action, for the three non-failing outcomes.
	 *
	 * @param string $action Action id.
	 * @param int    $done   Offers changed.
	 * @return array<string,string>
	 */
	private function messages( string $action, int $done ): array {
		if ( self::LINK_BY_ARTICLE === $action ) {
			$text = __( 'Связывание по артикулу поставлено в очередь. Результат появится в таблице через минуту-две.', 'woodev-plugin-framework' );
		} elseif ( 0 === $done ) {
			$text = __( 'Ничего не изменилось: выбранные товары уже в нужном состоянии или это действие для них недоступно.', 'woodev-plugin-framework' );
		} elseif ( self::PULL_PRICES === $action ) {
			/* translators: %s: «3 товара». */
			$text = sprintf( __( 'Загрузка цен поставлена в очередь: %s. Цены на сайте обновятся в течение нескольких минут.', 'woodev-plugin-framework' ), $done . ' ' . Bulk_Validation_Report::products( $done ) );
		} elseif ( in_array( $action, self::BACKGROUND, true ) ) {
			/* translators: %s: «3 товара». */
			$text = sprintf( __( 'Принято в работу: %s. Ход работы виден в столбце «Состояние».', 'woodev-plugin-framework' ), $done . ' ' . Bulk_Validation_Report::products( $done ) );
		} else {
			/* translators: %s: «3 товара». */
			$text = sprintf( __( 'Готово: %s.', 'woodev-plugin-framework' ), $done . ' ' . Bulk_Validation_Report::products( $done ) );
		}

		return [
			'action_status_done'    => $text,
			'action_status_partial' => $text,
			'action_status_queued'  => $text,
		];
	}
}
