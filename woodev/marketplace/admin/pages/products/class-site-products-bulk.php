<?php
/**
 * The bulk actions of the «Товары сайта» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Bulk_Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Export_Service;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
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
 * @since 2.1.3
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
	 * Names examples under each reason in a group.
	 */
	private const EXAMPLES = 5;

	/**
	 * Actions whose work runs in the background after the request: the answer says «queued».
	 */
	private const BACKGROUND = [ self::EXPORT, self::LINK_BY_ARTICLE, self::SEND_STOCK, self::SEND_PRICE, self::ARCHIVE ];

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
	 * Counts the shop's products the linking job would try to match, in the whole account: `function ( int $account_id ): int`.
	 *
	 * @var callable
	 */
	private $link_candidates;

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
	 * Where each kind of fix is done: `Validation_Report::FIX_*` => address.
	 *
	 * @var array<string,string>
	 */
	private $fix_urls;

	/**
	 * Creates the planner.
	 *
	 * @since 2.1.3
	 *
	 * @param Export_Service      $export          Export service.
	 * @param Wpdb_Link_Store     $links           Links.
	 * @param Stock_Intents       $stock           Stock intents (and the archive intent).
	 * @param Price_Intents       $prices          Price intents.
	 * @param Price_Context       $contexts        Reads a product and works out its price.
	 * @param Link_Detacher       $detacher        Detaches cards.
	 * @param Link_Scheduler      $scheduler       Queues the linking job.
	 * @param array<string,mixed> $callbacks       `link_candidates`, `can_edit`, `describe` (see the properties) and `fix_urls` (FIX_* => address).
	 */
	public function __construct( Export_Service $export, Wpdb_Link_Store $links, Stock_Intents $stock, Price_Intents $prices, Price_Context $contexts, Link_Detacher $detacher, Link_Scheduler $scheduler, array $callbacks ) {
		$this->export          = $export;
		$this->links           = $links;
		$this->stock           = $stock;
		$this->prices          = $prices;
		$this->contexts        = $contexts;
		$this->detacher        = $detacher;
		$this->scheduler       = $scheduler;
		$this->link_candidates = $callbacks['link_candidates'];
		$this->can_edit        = $callbacks['can_edit'];
		$this->describe        = $callbacks['describe'];
		$this->fix_urls        = (array) ( $callbacks['fix_urls'] ?? [] );
	}

	/**
	 * Every action id, in the order of the toolbar.
	 *
	 * @since 2.1.3
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
		];
	}

	/**
	 * What the action would do. Writes nothing.
	 *
	 * @since 2.1.3
	 *
	 * @param Account        $account   Account.
	 * @param string         $action    Action id.
	 * @param array<int,int> $offers    Distinct offers, from {@see Site_Products_Selection}.
	 * @param array<int,int> $editable  Offer => the product whose edit right decides it.
	 * @param int|null       $warehouse Warehouse for {@see self::SET_WAREHOUSE}; 0 for the account default.
	 * @return array<string,mixed> The preview answer of the records engine.
	 */
	public function preview( Account $account, string $action, array $offers, array $editable, ?int $warehouse = null ): array {
		$plan = $this->plan( $account, $action, $offers, $editable, $warehouse );

		if ( self::LINK_BY_ARTICLE === $action ) {
			return [
				'requested'      => $plan['will'],
				'eligible'       => $plan['will'],
				'skipped'        => 0,
				'scope_sentence' => $this->linking_sentence( $plan['will'] ),
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
	 * @since 2.1.3
	 *
	 * @param Account        $account   Account.
	 * @param string         $action    Action id.
	 * @param array<int,int> $offers    Distinct offers.
	 * @param array<int,int> $editable  Offer => the product whose edit right decides it.
	 * @param int|null       $warehouse Warehouse for {@see self::SET_WAREHOUSE}.
	 * @return array<string,mixed> The apply answer of the records engine.
	 * @throws Action_State_Exception When the queue or the scheduler refuses the work.
	 */
	public function apply( Account $account, string $action, array $offers, array $editable, ?int $warehouse = null ): array {
		$plan = $this->plan( $account, $action, $offers, $editable, $warehouse );
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
	 * Plans an action without writing.
	 *
	 * @param Account        $account   Account.
	 * @param string         $action    Action id.
	 * @param array<int,int> $offers    Offers.
	 * @param array<int,int> $editable  Offer => product whose right decides.
	 * @param int|null       $warehouse Warehouse.
	 * @return array{targets:Link_Record[],will:int,warnings:int,groups:array<int,array<string,mixed>>}
	 */
	private function plan( Account $account, string $action, array $offers, array $editable, ?int $warehouse ): array {
		if ( self::LINK_BY_ARTICLE === $action ) {
			return $this->plan_linking( $account );
		}

		list( $allowed, $denied ) = $this->by_right( $offers, $editable );

		$plan           = self::EXPORT === $action ? $this->plan_export( $account, $allowed ) : $this->plan_links( $account, $action, $allowed, $warehouse );
		$plan['offers'] = $allowed;

		if ( [] !== $denied ) {
			$plan['groups'][] = $this->group( __( 'у вас нет прав менять этот товар', 'woodev-plugin-framework' ), $denied );
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
			$out = $this->export->enqueue( $account, $offers );
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
	 * Plans the linking by article. The job matches the whole account, so the preview counts the account's candidates (shop products
	 * with an article that have no identity yet), never the ticked subset.
	 *
	 * @param Account $account Account.
	 * @return array{targets:Link_Record[],will:int,warnings:int,groups:array<int,array<string,mixed>>}
	 */
	private function plan_linking( Account $account ): array {
		$plan         = $this->empty_plan();
		$plan['will'] = (int) call_user_func( $this->link_candidates, $account->id() );

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
		$reasons = [];

		foreach ( $records as $record ) {
			$reason = $this->reason_to_skip( $account, $action, $record, $warehouse );

			if ( null === $reason ) {
				$targets[] = $record;
				continue;
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
				return count( $right['ids'] ) <=> count( $left['ids'] );
			}
		);

		return [
			'targets'  => $targets,
			'will'     => count( $targets ),
			'warnings' => 0,
			'groups'   => $groups,
		];
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
			return __( 'не выбран склад', 'woodev-plugin-framework' );
		}

		$product = $this->contexts->product( $record->wc_product_id() );

		if ( null === $product ) {
			return __( 'товар не найден в магазине', 'woodev-plugin-framework' );
		}

		$managed = $product->managing_stock() || (int) $product->get_stock_managed_by_id() !== $product->get_id();

		return $managed && null !== $product->get_stock_quantity() ? null : __( 'для товара не ведётся учёт остатков', 'woodev-plugin-framework' );
	}

	/**
	 * Asks what the price send would store, without storing, and names the gap.
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

		return null === $pair ? __( 'у товара нет цены, которую можно отправить', 'woodev-plugin-framework' ) : null;
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
	 * @return array{done:int,groups:array<int,array<string,mixed>>} Offers really changed, and the ones the write left out.
	 * @throws Action_State_Exception When the queue or the scheduler refuses the work.
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
			default:
				return [
					'done'   => $this->run_flags( $account, $action, $plan['targets'], $warehouse ),
					'groups' => [],
				];
		}
	}

	/**
	 * Applies a flag, warehouse or reset change.
	 *
	 * @param Account       $account   Account.
	 * @param string        $action    Action id.
	 * @param Link_Record[] $targets   Rows.
	 * @param int|null      $warehouse Warehouse.
	 * @return int Rows changed.
	 */
	private function run_flags( Account $account, string $action, array $targets, ?int $warehouse ): int {
		$link_ids = array_map(
			static function ( Link_Record $record ): int {
				return (int) $record->link_id();
			},
			$targets
		);

		switch ( $action ) {
			case self::SYNC_STOCK_ON:
			case self::SYNC_STOCK_OFF:
				return $this->links->set_sync_flag( $account->id(), $link_ids, 'sync_stock', self::SYNC_STOCK_ON === $action );
			case self::SYNC_PRICE_ON:
			case self::SYNC_PRICE_OFF:
				return $this->links->set_sync_flag( $account->id(), $link_ids, 'sync_price', self::SYNC_PRICE_ON === $action );
			case self::SET_WAREHOUSE:
				return $this->links->set_warehouse( $account->id(), $link_ids, (int) $warehouse > 0 ? (int) $warehouse : null );
			default:
				return $this->links->reset_state( $account->id(), $link_ids );
		}
	}

	/**
	 * Queues the linking of the account's cards by article. It covers the whole account, not only the ticked products.
	 *
	 * @param Account             $account Account.
	 * @param array<string,mixed> $plan    The plan.
	 * @return array{done:int,groups:array<int,array<string,mixed>>}
	 * @throws Action_State_Exception When Action Scheduler refuses the job.
	 */
	private function run_linking( Account $account, array $plan ): array {
		if ( ! $this->scheduler->link_by_article( $account->id() ) ) {
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
	 * Records the archive intent of created cards: the account's sender sets the stock to zero and then archives the card.
	 *
	 * @param Link_Record[] $targets Created cards.
	 * @return array{done:int,groups:array<int,array<string,mixed>>}
	 */
	private function run_archive( array $targets ): array {
		$recorded = 0;
		$missed   = [];

		foreach ( $targets as $record ) {
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
	 * @return array{done:int,groups:array<int,array<string,mixed>>}
	 */
	private function run_detach( Account $account, array $targets ): array {
		if ( [] === $targets ) {
			return [
				'done'   => 0,
				'groups' => [],
			];
		}

		try {
			$outcome = $this->detacher->detach( $account->id(), $targets );
		} catch ( \RuntimeException $e ) {
			throw new Action_State_Exception( __( 'Не удалось снять связь. Повторите чуть позже.', 'woodev-plugin-framework' ) );
		}

		$ids = array_map( [ $this, 'product_of' ], $targets );

		if ( $outcome['busy'] ) {
			return [
				'done'   => 0,
				'groups' => [ $this->group( __( 'сейчас идёт отправка остатков и цен этого аккаунта — повторите через минуту', 'woodev-plugin-framework' ), $ids ) ],
			];
		}

		return [
			'done'   => $outcome['detached'],
			'groups' => $outcome['kept'] > 0 ? [ $this->group( __( 'товар сейчас выгружается на маркетплейс — повторите позже', 'woodev-plugin-framework' ), array_slice( $ids, 0, $outcome['kept'] ) ) ] : [],
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
	 * @return array{reason:string,ids:int[],fix:?string}
	 */
	private function group( string $reason, array $ids, ?string $fix = null ): array {
		return [
			'reason' => $reason,
			'ids'    => array_values( $ids ),
			'fix'    => $fix,
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
				'count'     => count( $group['ids'] ),
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
	 * The sentence under the title of the confirmation window for the account-wide linking.
	 *
	 * @param int $candidates Shop products the job would try to match.
	 * @return string
	 */
	private function linking_sentence( int $candidates ): string {
		/* translators: %s: «120 товаров». */
		return sprintf( __( 'Весь аккаунт — %s магазина без связи с маркетплейсом. Связывание идёт по всему магазину, не только по отмеченным товарам; на маркетплейсе ничего не записывается.', 'woodev-plugin-framework' ), $candidates . ' ' . Bulk_Validation_Report::products( $candidates ) );
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
