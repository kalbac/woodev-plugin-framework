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
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The cards the marketplace has in the cabinet, as a records provider: the list the «Обновить список» job stored, each card marked with
 * the product that carries its article. It is the small coordinator: filtering and paging are in {@see Remote_Cards_Query}, rows in
 * {@see Remote_Cards_Rows}, the product picker in {@see Remote_Cards_Products} and the three actions in {@see Remote_Cards_Actions}.
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
	 * Creates the provider.
	 *
	 * @since 2.1.5
	 *
	 * @param Wpdb_Account_Store    $accounts  Accounts.
	 * @param Cards_Snapshot_Store  $snapshots Snapshot of the cabinet's cards.
	 * @param Wpdb_Link_Store       $links     Links.
	 * @param Remote_Cards_Rows     $rows      Row builder.
	 * @param Remote_Cards_Products $products  The shop's products.
	 * @param Remote_Cards_Actions  $actions   The table's actions.
	 * @param array<string,mixed>   $config    `adapter_id` and `marketplace`.
	 */
	public function __construct( Wpdb_Account_Store $accounts, Cards_Snapshot_Store $snapshots, Wpdb_Link_Store $links, Remote_Cards_Rows $rows, Remote_Cards_Products $products, Remote_Cards_Actions $actions, array $config ) {
		$this->accounts  = $accounts;
		$this->snapshots = $snapshots;
		$this->links     = $links;
		$this->rows      = $rows;
		$this->products  = $products;
		$this->actions   = $actions;
		$this->config    = [
			'adapter_id'  => (string) ( $config['adapter_id'] ?? '' ),
			'marketplace' => (string) ( $config['marketplace'] ?? '' ),
		];
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
		return Remote_Cards_Definition::build( $this->account_options(), $this->config['marketplace'] );
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
			if ( null === Remote_Cards_Ids::key_of( (string) $id, $account_id ) ) {
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

		$snapshot = $this->snapshots->get( $account->id() );
		$filters  = (array) ( $query['filters'] ?? [] );
		$page     = Remote_Cards_Query::page(
			null === $snapshot ? [] : $snapshot['cards'],
			$this->linked_offers( $account->id() ),
			(string) ( $filters['on_site'] ?? '' ),
			(string) ( $filters['q'] ?? $query['search'] ?? '' ),
			(int) $query['page'],
			(int) $query['per_page']
		);

		return [
			'rows'             => array_map(
				function ( array $card ) use ( $account ): array {
					return $this->rows->row( $account->id(), $card );
				},
				$page['cards']
			),
			'total'            => $page['total'],
			'account_activity' => $this->account_activity( $account->id() ),
			'messages'         => [ 'summary' => $this->summary( $account->id(), $snapshot ) ],
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
	 * The account line and nothing else: no row of this table has a process of its own.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string[]             $ids     Visible row ids.
	 * @return array<string,mixed>
	 */
	public function get_activity( array $context, array $ids ): array {
		$account = $this->account( $context );

		return [
			'rows'             => [],
			'removed_ids'      => [],
			'account_activity' => null === $account ? Activity_Normalizer::idle_account() : $this->account_activity( $account->id() ),
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
	 * «Обновить список» is blocked while the job runs.
	 *
	 * @param int $account_id Account id.
	 * @return array<string,mixed> Shape of {@see Activity_Normalizer::account()}.
	 */
	private function account_activity( int $account_id ): array {
		if ( ! $this->snapshots->is_refreshing( $account_id ) ) {
			return Activity_Normalizer::idle_account();
		}

		return [
			'active'          => true,
			'text'            => __( 'Список карточек загружается с маркетплейса. Когда загрузка закончится, обновите страницу.', 'woodev-plugin-framework' ),
			'blocked_actions' => [ Remote_Cards_Definition::REFRESH ],
			'observed_at'     => Activity_Normalizer::now(),
		];
	}

	/**
	 * One sentence about the list: when it was loaded and how many cards it holds, why it could not be loaded, or that there is none.
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

		return implode( ' ', $sentences );
	}

	/**
	 * Articles our link table knows: the product that carries each.
	 *
	 * @param int $account_id Account id.
	 * @return array<string,int> Product id by article.
	 */
	private function linked_offers( int $account_id ): array {
		$linked = [];

		foreach ( $this->links->identity_map( $account_id ) as $product_id => $identity ) {
			if ( null !== $identity['offer_id'] ) {
				$linked[ $identity['offer_id'] ] = $product_id;
			}
		}

		return $linked;
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
