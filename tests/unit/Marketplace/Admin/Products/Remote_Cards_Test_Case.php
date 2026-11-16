<?php
/**
 * Harness of the «Карточки на маркетплейсе» tests: the products harness plus the snapshot in an in-memory transient store, the shop's
 * product search, and the provider wired as the module wires it.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Actions;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Import_Plan;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Products;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Provider;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Rows;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;
use Woodev\Framework\Marketplace\Jobs\Import_Scheduler;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

/**
 * Cards of the snapshot are plain arrays shaped like the adapter's `snapshot()`; see {@see self::card()}.
 */
abstract class Remote_Cards_Test_Case extends Products_Test_Case {
	/**
	 * Transients, name => value.
	 *
	 * @var array<string,mixed>
	 */
	protected $transients = [];

	/**
	 * Options, name => value.
	 *
	 * @var array<string,mixed>
	 */
	protected $options = [];

	/**
	 * Ids the shop's product search answers with.
	 *
	 * @var int[]
	 */
	protected $found = [];

	/**
	 * What the product search was asked: `[ term, limit ]`.
	 *
	 * @var array<int,array{0:string,1:int}>
	 */
	protected $searches = [];

	/**
	 * Whether the provider is wired as for an adapter that can import cards.
	 *
	 * @var bool
	 */
	protected $importable = false;

	/**
	 * What the shop's SKU lookup answers, by article: one of the `Remote_Cards_Import_Plan::SKU_*` kinds; an article not listed has no product.
	 *
	 * @var array<string,string>
	 */
	protected $sku_kinds = [];

	/**
	 * What a group has on the site, by `{account id}:{group key}`: one of the `Group_Assembler` states; a group not listed has nothing.
	 *
	 * @var array<string,string>
	 */
	protected $group_states = [];

	protected function setUp(): void {
		parent::setUp();

		$this->importable = false;
		$this->sku_kinds  = [];
		$this->group_states = [];

		// The queue as the import scheduler reads it: the pending and running actions of a hook, as objects with their arguments.
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $query = [] ): array {
				if ( isset( $query['args'] ) ) {
					return array_keys( $this->scheduler->matching( (string) $query['hook'], (array) $query['args'], (string) ( $query['status'] ?? '' ) ) );
				}

				$pending = array_filter(
					$this->scheduler->of( (string) $query['hook'] ),
					static function ( array $action ) use ( $query ): bool {
						return in_array( $action['status'], (array) $query['status'], true );
					}
				);

				return array_map(
					static function ( array $action ) {
						return new class( $action['args'] ) {
							/** @var array<int,mixed> */
							private $args;

							/** @param array<int,mixed> $args Arguments. */
							public function __construct( array $args ) {
								$this->args = $args;
							}

							/** @return array<int,mixed> */
							public function get_args(): array {
								return $this->args;
							}
						};
					},
					array_values( $pending )
				);
			}
		);

		$this->transients = [];
		$this->options    = [];
		$this->found      = [];
		$this->searches   = [];

		Functions\when( 'get_transient' )->alias(
			function ( string $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->transients[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				unset( $this->transients[ $name ] );

				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'get_edit_post_link' )->alias(
			static function ( $id ): string {
				return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit';
			}
		);
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, int $timestamp ): string {
				return gmdate( $format, $timestamp );
			}
		);
	}

	/**
	 * A snapshot card, shaped like the adapter's `snapshot()` answer.
	 *
	 * @param string              $offer_id Article.
	 * @param string              $name     Name.
	 * @param array<string,mixed> $more     Keys to override.
	 * @return array<string,mixed>
	 */
	protected function card( string $offer_id, string $name = '', array $more = [] ): array {
		return array_merge(
			[
				'offer_id'    => $offer_id,
				'name'        => '' === $name ? 'Карточка ' . $offer_id : $name,
				'remote_ids'  => [
					'product_id' => 5001,
					'sku'        => 7001,
				],
				'state'       => 'created',
				'is_created'  => true,
				'failed'      => false,
				'archived'    => false,
				'details_ok'  => true,
				'status_text' => '',
			],
			$more
		);
	}

	/**
	 * Stores a snapshot for the harness' account.
	 *
	 * @param array<int,array<string,mixed>> $cards Cards.
	 * @param int                            $at    Unix time of the load.
	 * @return void
	 */
	protected function put_snapshot( array $cards, int $at = 1700000000 ): void {
		$this->assertTrue( $this->snapshots()->put( $this->account_id, $cards, $at ) );
	}

	/**
	 * @return Cards_Snapshot_Store
	 */
	protected function snapshots(): Cards_Snapshot_Store {
		return new Cards_Snapshot_Store( 'woodev_test_' );
	}

	/**
	 * @return Link_Report_Store
	 */
	protected function reports(): Link_Report_Store {
		return new Link_Report_Store( 'woodev_test_' );
	}

	/**
	 * An entry of the near-match report: a shop product and a card whose article looks like the product's.
	 *
	 * @param int    $product_id The shop product.
	 * @param string $sku        The product's article.
	 * @param string $offer_id   The card's article.
	 * @param string $relation   How they differ.
	 * @return array<string,mixed>
	 */
	protected function near_entry( int $product_id, string $sku, string $offer_id, string $relation = 'case' ): array {
		return [
			'wc_product_id' => $product_id,
			'wc_parent_id'  => 0,
			'wc_sku'        => $sku,
			'relation'      => $relation,
			'card'          => $this->card( $offer_id ),
		];
	}

	/**
	 * Row id of a near-match pair in the harness' account.
	 *
	 * @param string $offer_id   Article of the card.
	 * @param int    $product_id The shop product.
	 * @return string
	 */
	protected function near_row_id( string $offer_id, int $product_id ): string {
		return \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Ids::near_row_id( $this->account_id, $offer_id, $product_id );
	}

	/**
	 * The shop's product helper, searching {@see self::$found}.
	 *
	 * @return Remote_Cards_Products
	 */
	protected function shop_products(): Remote_Cards_Products {
		return new Remote_Cards_Products(
			function ( string $term, int $limit ): array {
				$this->searches[] = [ $term, $limit ];

				return $this->found;
			}
		);
	}

	/**
	 * The provider, wired like the module wires it.
	 *
	 * @return Remote_Cards_Provider
	 */
	protected function cards_provider(): Remote_Cards_Provider {
		$products = $this->shop_products();
		$logging  = new Logging(
			'woodev_test_advanced_logging',
			function ( string $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		$importer = $this->importable ? $this->import_scheduler() : null;
		$rows     = new Remote_Cards_Rows( [ $products, 'describe' ], $logging, $this->importable );
		$license  = new License_Gate(
			function (): bool {
				return $this->licensed;
			}
		);

		return new Remote_Cards_Provider(
			$this->accounts(),
			$this->snapshots(),
			$this->links(),
			$this->reports(),
			$rows,
			$products,
			new Remote_Cards_Actions(
				$this->snapshots(),
				$this->links(),
				$this->reports(),
				$this->linker ?? new Card_Linker( $this->links() ),
				new Link_Scheduler( $this->names() ),
				$this->bulk(),
				$license,
				$products,
				$rows,
				$importer,
				null === $importer ? null : $this->import_plan()
			),
			[
				'adapter_id'  => Sqlite_Tables::MARKETPLACE_ID,
				'marketplace' => 'Тестмаркет',
			],
			$importer
		);
	}

	/**
	 * @return Import_Scheduler
	 */
	protected function import_scheduler(): Import_Scheduler {
		return new Import_Scheduler( $this->names() );
	}

	/**
	 * The import plan over the harness' shop: {@see self::$sku_kinds} answers the SKU lookup, {@see self::$group_states} the group lookup.
	 *
	 * @return Remote_Cards_Import_Plan
	 */
	protected function import_plan(): Remote_Cards_Import_Plan {
		return new Remote_Cards_Import_Plan(
			function ( string $sku ): string {
				return $this->sku_kinds[ $sku ] ?? Remote_Cards_Import_Plan::SKU_NONE;
			},
			function ( int $account_id, string $key ): string {
				return $this->group_states[ $account_id . ':' . $key ] ?? Group_Assembler::NONE;
			}
		);
	}

	/**
	 * @return Records_Definition
	 */
	protected function cards_definition(): Records_Definition {
		return Records_Definition::from_array( $this->cards_provider()->get_definition( $this->context() ) );
	}

	/**
	 * Row id of a card in the harness' account.
	 *
	 * @param string $offer_id Article.
	 * @return string
	 */
	protected function card_row_id( string $offer_id ): string {
		return \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Ids::row_id( $this->account_id, $offer_id );
	}
}
