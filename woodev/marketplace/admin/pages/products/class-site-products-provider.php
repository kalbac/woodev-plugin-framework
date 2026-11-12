<?php
/**
 * The «Товары сайта» table of the records engine.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Provides_Field_Options;
use Woodev\Framework\Admin\Records\Records_Provider;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;

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
final class Site_Products_Provider implements Records_Provider, Provides_Field_Options {
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
	 * `adapter_id`, `marketplace` (merchant-facing name), `withheld` (action ids the adapter cannot serve).
	 *
	 * @var array{adapter_id:string,marketplace:string,withheld:string[]}
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
	 * @param array<string,mixed>     $config     `adapter_id`, `marketplace` and `withheld`.
	 */
	public function __construct( Wpdb_Account_Store $accounts, Site_Products_Reader $reader, Site_Products_Selection $selection, Site_Products_Activity $activity, Site_Products_Bulk $bulk, Warehouse_Options $warehouses, License_Gate $license, array $config ) {
		$this->accounts   = $accounts;
		$this->reader     = $reader;
		$this->selection  = $selection;
		$this->activity   = $activity;
		$this->bulk       = $bulk;
		$this->warehouses = $warehouses;
		$this->license    = $license;
		$this->config     = [
			'adapter_id'  => (string) ( $config['adapter_id'] ?? '' ),
			'marketplace' => (string) ( $config['marketplace'] ?? '' ),
			'withheld'    => (array) ( $config['withheld'] ?? [] ),
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

		$page  = $this->reader->page( Site_Products_Filter::from_query( $account->id(), $query ), (int) $query['page'], (int) $query['per_page'] );
		$total = $page['total'];

		return [
			'rows'             => $this->parent_rows( $account, $page['ids'] ),
			'total'            => $total,
			'account_activity' => $this->activity->account( $account->id() ),
			'messages'         => [
				/* translators: %d: number of products. */
				'summary' => sprintf( _n( 'Найден %d товар', 'Найдено товаров: %d', $total, 'woodev-plugin-framework' ), $total ),
			],
		];
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
	 * @throws Action_Input_Exception When the warehouse is not one of the account's.
	 */
	public function preview( array $context, string $action, array $ids, array $payload ): array {
		$call = $this->prepare( $context, $action, $ids, $payload );

		return $this->bulk->preview( $call['account'], $action, $call['offers'], $call['editable'], $call['warehouse'] );
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
	 * @throws Action_Input_Exception When the warehouse is not one of the account's.
	 */
	public function apply( array $context, string $action, array $ids, array $payload ): array {
		$call = $this->prepare( $context, $action, $ids, $payload );

		return $this->bulk->apply( $call['account'], $action, $call['offers'], $call['editable'], $call['warehouse'] );
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
	 * The options of the warehouse field: «по умолчанию» (0) and the account's warehouses.
	 *
	 * @since 2.1.4
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Action id.
	 * @param string                                           $field   Field id.
	 * @param array{row_id:string,search:string,cursor:string} $query   Validated input.
	 * @return array{options:array<int,array{value:string,label:string}>,next_cursor:null}
	 */
	public function get_field_options( array $context, string $action, string $field, array $query ): array {
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
	 * @return array{account:Account,offers:int[],editable:array<int,int>,warehouse:int|null}
	 * @throws Action_State_Exception When the licence is not in force or the selection is too large.
	 * @throws Action_Input_Exception When the warehouse is not one of the account's.
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
		];
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
		$phrases = [
			Site_Products_Bulk::EXPORT          => __( 'отправить товар на маркетплейс', 'woodev-plugin-framework' ),
			Site_Products_Bulk::LINK_BY_ARTICLE => __( 'искать карточки на маркетплейсе', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SEND_STOCK      => __( 'отправить остатки на маркетплейс', 'woodev-plugin-framework' ),
			Site_Products_Bulk::SEND_PRICE      => __( 'отправить цены на маркетплейс', 'woodev-plugin-framework' ),
			Site_Products_Bulk::ARCHIVE         => __( 'архивировать карточку на маркетплейсе', 'woodev-plugin-framework' ),
		];

		return isset( $phrases[ $action ] ) ? $this->license->refusal_for( $phrases[ $action ] ) : null;
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

		$offers   = $this->reader->variation_offers( $account->id(), $ids );
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
		return new Site_Products_Rows(
			$this->config['marketplace'],
			$this->warehouses->for_account( $account->id() ),
			static function ( int $product_id ): string {
				return current_user_can( 'edit_post', $product_id ) ? (string) get_edit_post_link( $product_id, 'raw' ) : '';
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
