<?php
/**
 * The «Карточки на маркетплейсе» table of the records engine.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Activity_Normalizer;
use Woodev\Framework\Admin\Records\Provides_Field_Options;
use Woodev\Framework\Admin\Records\Records_Provider;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Jobs\Import_Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * The cards the marketplace has in the cabinet, as a records provider: the list the «Обновить список» job stored, each card marked with
 * the product that carries its article. It is the small coordinator: filtering and paging are in {@see Remote_Cards_Query}, rows in
 * {@see Remote_Cards_Rows}, the product picker in {@see Remote_Cards_Products} and the actions in {@see Remote_Cards_Actions}. The «Показать → Похожие артикулы» view lists the
 * near-match report of the last «Связать по артикулу» run instead of the cards ({@see Link_Report_Store}).
 *
 * **A read of this table never calls the marketplace.** It reads the snapshot (a transient the job fills) and our link table; the only
 * way to a fresh list is the guarded job «Обновить список».
 *
 * @since 2.1.5
 */
final class Remote_Cards_Provider implements Records_Provider, Provides_Field_Options {
	/**
	 * Table id: the products page declares `tables => [ 'products', 'remote_cards' ]`.
	 */
	public const TABLE = 'remote_cards';

	/**
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * @var Cards_Snapshot_Store
	 */
	private $snapshots;

	/**
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * @var Link_Report_Store
	 */
	private $reports;

	/**
	 * @var Remote_Cards_Rows
	 */
	private $rows;

	/**
	 * @var Remote_Cards_Products
	 */
	private $products;

	/**
	 * @var Remote_Cards_Actions
	 */
	private $actions;

	/**
	 * `adapter_id` and `marketplace` (merchant-facing name).
	 *
	 * @var array{adapter_id:string,marketplace:string}
	 */
	private $config;

	/**
	 * Reads the articles being imported; null when the adapter cannot import cards.
	 *
	 * @var Import_Scheduler|null
	 */
	private $importer;

	/**
	 * Creates the provider.
	 *
	 * @since 2.1.5
	 *
	 * @param Wpdb_Account_Store    $accounts  Accounts.
	 * @param Cards_Snapshot_Store  $snapshots Snapshot of the cabinet's cards.
	 * @param Wpdb_Link_Store       $links     Links.
	 * @param Link_Report_Store     $reports   The last link run's summary and near-match report.
	 * @param Remote_Cards_Rows     $rows      Row builder.
	 * @param Remote_Cards_Products $products  The shop's products.
	 * @param Remote_Cards_Actions  $actions   The table's actions.
	 * @param array<string,mixed>   $config    `adapter_id` and `marketplace`.
	 * @param Import_Scheduler|null $importer  Reads the import queue; null when the adapter cannot import cards, and the table then offers no «Импортировать».
	 */
	public function __construct( Wpdb_Account_Store $accounts, Cards_Snapshot_Store $snapshots, Wpdb_Link_Store $links, Link_Report_Store $reports, Remote_Cards_Rows $rows, Remote_Cards_Products $products, Remote_Cards_Actions $actions, array $config, ?Import_Scheduler $importer = null ) {
		$this->accounts  = $accounts;
		$this->snapshots = $snapshots;
		$this->links     = $links;
		$this->reports   = $reports;
		$this->rows      = $rows;
		$this->products  = $products;
		$this->actions   = $actions;
		$this->config    = [
			'adapter_id'  => (string) ( $config['adapter_id'] ?? '' ),
			'marketplace' => (string) ( $config['marketplace'] ?? '' ),
		];
		$this->importer  = $importer;
	}

	/**
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::TABLE;
	}

	/**
	 * @since 2.1.5
	 *
	 * @param array<string,string> $context Requested context.
	 * @return array<string,mixed>
	 */
	public function get_definition( array $context ): array {
		return Remote_Cards_Definition::build( $this->account_options(), $this->config['marketplace'], null !== $this->importer );
	}

	/**
	 * Every row id must be well formed and name this account. Whether the card is still in the list is the action's business: a card
	 * that has left it is reported as a stale list, with the reason, rather than as an unknown id.
	 *
	 * @since 2.1.5
	 *
	 * @param string               $operation `definition`, `list`, `options`, `activity` or an action id.
	 * @param array<string,string> $context   Resolved context.
	 * @param string[]             $ids       Row ids.
	 * @return bool
	 */
	public function authorize( string $operation, array $context, array $ids ): bool {
		$account_id = $this->account_id( $context );

		foreach ( $ids as $id ) {
			if ( null === Remote_Cards_Ids::key_of( (string) $id, $account_id ) && null === Remote_Cards_Ids::near_of( (string) $id, $account_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Reads one page of the snapshot, filtered. Local reads only: the snapshot and the links.
	 *
	 * @since 2.1.5
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

		$filters = (array) ( $query['filters'] ?? [] );

		if ( Remote_Cards_Query::SHOW_NEAR === ( $filters[ Remote_Cards_Definition::SHOW_FILTER ] ?? '' ) ) {
			return $this->near_matches( $account, (string) ( $filters['q'] ?? $query['search'] ?? '' ), (int) $query['page'], (int) $query['per_page'] );
		}

		$snapshot = $this->snapshots->get( $account->id() );
		$state    = $this->import_state( $account->id() );
		$cards    = null === $snapshot ? [] : $snapshot['cards'];
		$linked   = $this->linked_offers( $account->id() );
		$search   = (string) ( $filters['q'] ?? $query['search'] ?? '' );
		$page     = Remote_Cards_Query::page( $cards, $linked, (string) ( $filters['on_site'] ?? '' ), $search, (int) $query['page'], (int) $query['per_page'] );

		return [
			'rows'             => array_map(
				function ( array $card ) use ( $account, $state ): array {
					return $this->card_row( $account->id(), $card, $state );
				},
				$page['cards']
			),
			'total'            => $page['total'],
			'facets'           => [ 'on_site' => $this->on_site_facets( Remote_Cards_Query::on_site_counts( $cards, $linked, $search ) ) ],
			'account_activity' => $this->account_activity( $account->id(), $state['importing'] ),
			'messages'         => [ 'summary' => $this->summary( $account->id(), $snapshot ) ],
		];
	}

	/**
	 * The numbers of the «На сайте» view row.
	 *
	 * @param array<string,int> $counts {@see Remote_Cards_Query::on_site_counts()}.
	 * @return array<int,array{value:string,label:string,count:int}>
	 */
	private function on_site_facets( array $counts ): array {
		return [
			[
				'value' => Remote_Cards_Query::NOT_ON_SITE,
				'label' => __( 'Нет на сайте', 'woodev-plugin-framework' ),
				'count' => $counts[ Remote_Cards_Query::NOT_ON_SITE ],
			],
			[
				'value' => Remote_Cards_Query::ON_SITE,
				'label' => __( 'Уже на сайте', 'woodev-plugin-framework' ),
				'count' => $counts[ Remote_Cards_Query::ON_SITE ],
			],
		];
	}

	/**
	 * The table has no children.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,string> $context   Resolved context.
	 * @param string               $parent_id Row id.
	 * @param array<string,mixed>  $query     Validated query.
	 * @return array<string,mixed>
	 */
	public function get_children( array $context, string $parent_id, array $query ): array {
		return [
			'rows'  => [],
			'total' => 0,
		];
	}

	/**
	 * The plan of an action. Writes nothing.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the account is gone or the licence is not in force.
	 */
	public function preview( array $context, string $action, array $ids, array $payload ): array {
		return $this->actions->preview( $this->require_account( $context ), $action, $ids, $payload );
	}

	/**
	 * Replans from the current state and does the action.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the account is gone, the licence is not in force or the queue refuses the job.
	 */
	public function apply( array $context, string $action, array $ids, array $payload ): array {
		return $this->actions->apply( $this->require_account( $context ), $action, $ids, $payload );
	}

	/**
	 * The current rows of the asked ids and the account line: a row's import lock, its import note and where it now stands on the site.
	 * Local reads only: the snapshot, the links, the notes and the import queue.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string[]             $ids     Visible row ids.
	 * @return array<string,mixed>
	 */
	public function get_activity( array $context, array $ids ): array {
		$account = $this->account( $context );

		if ( null === $account ) {
			return [
				'rows'             => [],
				'removed_ids'      => [],
				'account_activity' => Activity_Normalizer::idle_account(),
			];
		}

		$state = $this->import_state( $account->id() );

		return [
			'rows'             => $this->current_rows( $account, $ids, $state ),
			'removed_ids'      => [],
			'account_activity' => $this->account_activity( $account->id(), $state['importing'] ),
		];
	}

	/**
	 * The options of the product field: the shop's products that match what the merchant typed.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Action id.
	 * @param string                                           $field   Field id.
	 * @param array{row_id:string,search:string,cursor:string} $query   Validated input.
	 * @return array{options:array<int,array{value:string,label:string}>,next_cursor:null}
	 */
	public function get_field_options( array $context, string $action, string $field, array $query ): array {
		return [
			'options'     => $this->products->options( $query['search'] ),
			'next_cursor' => null,
		];
	}

	/**
	 * The account line: «Обновить список» is blocked while its job runs, and a pending import is announced.
	 *
	 * @param int      $account_id    Account id.
	 * @param string[] $importing_ids Articles being imported, read once per request by the caller ({@see self::import_state()}).
	 * @return array<string,mixed> Shape of {@see Activity_Normalizer::account()}.
	 */
	private function account_activity( int $account_id, array $importing_ids ): array {
		$refreshing = $this->snapshots->is_refreshing( $account_id );
		$importing  = [] !== $importing_ids;

		if ( ! $refreshing && ! $importing ) {
			return Activity_Normalizer::idle_account();
		}

		$text = [];

		if ( $refreshing ) {
			$text[] = __( 'Список карточек загружается с маркетплейса. Когда загрузка закончится, обновите страницу.', 'woodev-plugin-framework' );
		}

		if ( $importing ) {
			$text[] = __( 'Импортируем карточки…', 'woodev-plugin-framework' );
		}

		return [
			'active'          => true,
			'text'            => implode( ' ', $text ),
			'blocked_actions' => $refreshing ? [ Remote_Cards_Definition::REFRESH ] : [],
			'observed_at'     => Activity_Normalizer::now(),
		];
	}

	/**
	 * What the rows need besides the card: the import notes of the account, by article, and the articles being imported.
	 *
	 * @param int $account_id Account id.
	 * @return array{notes:array<string,array{offer_id:string,text:string,detail:string}>,importing:string[]}
	 */
	private function import_state( int $account_id ): array {
		$notes = [];

		foreach ( $this->reports->import_notes( $account_id ) as $note ) {
			$notes[ $note['offer_id'] ] = $note;
		}

		return [
			'notes'     => $notes,
			'importing' => null === $this->importer ? [] : $this->importer->pending_offer_ids( $account_id ),
		];
	}

	/**
	 * One row of a card.
	 *
	 * @param int                 $account_id Account id.
	 * @param array<string,mixed> $card       Snapshot card with `wc_product_id`.
	 * @param array<string,mixed> $state      {@see self::import_state()}.
	 * @return array<string,mixed>
	 */
	private function card_row( int $account_id, array $card, array $state ): array {
		$offer_id = (string) $card['offer_id'];

		return $this->rows->row( $account_id, $card, $state['notes'][ $offer_id ] ?? null, in_array( $offer_id, $state['importing'], true ) );
	}

	/**
	 * The rows of the asked ids that are cards of the snapshot, as the table would list them now.
	 *
	 * @param Account             $account Account.
	 * @param string[]            $ids     Row ids.
	 * @param array<string,mixed> $state   {@see self::import_state()}.
	 * @return array<int,array<string,mixed>> No row for the id of a near-match pair or of a card the list no longer holds.
	 */
	private function current_rows( Account $account, array $ids, array $state ): array {
		$snapshot = $this->snapshots->get( $account->id() );

		if ( null === $snapshot ) {
			return [];
		}

		$linked = $this->linked_offers( $account->id() );
		$rows   = [];

		foreach ( $ids as $row_id ) {
			$key  = Remote_Cards_Ids::key_of( (string) $row_id, $account->id() );
			$card = null === $key ? null : Remote_Cards_Query::find( $snapshot['cards'], $key );

			if ( null !== $card ) {
				$rows[] = $this->card_row( $account->id(), array_merge( $card, [ 'wc_product_id' => $linked[ (string) $card['offer_id'] ] ?? 0 ] ), $state );
			}
		}

		return $rows;
	}

	/**
	 * One sentence about the list: when it was loaded and how many cards it holds, why it could not be loaded, or that there is none;
	 * then the summary of the last «link by article» run and the number of look-alike pairs that wait.
	 *
	 * @param int                                                             $account_id Account id.
	 * @param array{fetched_at:int,cards:array<int,array<string,mixed>>}|null $snapshot   The stored list.
	 * @return string
	 */
	private function summary( int $account_id, ?array $snapshot ): string {
		$sentences = [];
		$error     = $this->snapshots->last_error( $account_id );

		if ( '' !== $error ) {
			/* translators: %s: the reason the list could not be loaded. */
			$sentences[] = sprintf( __( 'Не удалось загрузить список: %s', 'woodev-plugin-framework' ), $error );
		}

		if ( null !== $snapshot ) {
			$sentences[] = sprintf(
				/* translators: 1: time the list was loaded, 2: number of cards in it. */
				_n( 'Список загружен %1$s: %2$d карточка.', 'Список загружен %1$s: карточек — %2$d.', count( $snapshot['cards'] ), 'woodev-plugin-framework' ),
				wp_date( 'd.m.Y H:i', $snapshot['fetched_at'] ),
				count( $snapshot['cards'] )
			);
		} elseif ( ! $this->snapshots->is_refreshing( $account_id ) ) {
			$sentences[] = __( 'Список карточек не загружен или устарел. Нажмите «Обновить список».', 'woodev-plugin-framework' );
		}

		$sentences[] = Remote_Cards_Summary::last_run( $this->reports->last_run( $account_id ) );
		$sentences[] = Remote_Cards_Summary::waiting( count( $this->reports->near_matches( $account_id ) ) );

		return implode( ' ', array_filter( $sentences ) );
	}

	/**
	 * One page of the near-match report: the pairs of a shop product and a card whose articles look alike, each waiting for the
	 * merchant's answer. A local read of the report the last «Связать по артикулу» run stored.
	 *
	 * @param Account $account  The account the table is open for.
	 * @param string  $search   Search text.
	 * @param int     $page     Page number.
	 * @param int     $per_page Rows per page.
	 * @return array<string,mixed>
	 */
	private function near_matches( Account $account, string $search, int $page, int $per_page ): array {
		$found = Remote_Cards_Query::near_page( $this->reports->near_matches( $account->id() ), $search, $page, $per_page );

		return [
			'rows'             => array_map(
				function ( array $entry ) use ( $account ): array {
					return $this->rows->near_row( $account->id(), $entry );
				},
				$found['entries']
			),
			'total'            => $found['total'],
			'account_activity' => $this->account_activity( $account->id(), $this->import_state( $account->id() )['importing'] ),
			'messages'         => [ 'summary' => trim( Remote_Cards_Summary::near_explained() . ' ' . Remote_Cards_Summary::last_run( $this->reports->last_run( $account->id() ) ) ) ],
		];
	}

	/**
	 * Articles our link table knows: the product that carries each.
	 *
	 * @param int $account_id Account id.
	 * @return array<string,int> Product id by article.
	 */
	private function linked_offers( int $account_id ): array {
		return Remote_Cards_Query::linked_offers( $this->links->identity_map( $account_id ) );
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
	 * @param array<string,string> $context Resolved context.
	 * @return Account
	 * @throws Action_State_Exception When the account is gone or switched off.
	 */
	private function require_account( array $context ): Account {
		$account = $this->account( $context );

		if ( null === $account ) {
			throw new Action_State_Exception( __( 'Аккаунт не найден или выключен.', 'woodev-plugin-framework' ) );
		}

		return $account;
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
