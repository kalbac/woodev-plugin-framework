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
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Actions;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Products;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Provider;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Rows;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
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

	protected function setUp(): void {
		parent::setUp();

		$this->transients = [];
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
		$rows     = new Remote_Cards_Rows( [ $products, 'describe' ] );
		$license  = new License_Gate(
			function (): bool {
				return $this->licensed;
			}
		);

		return new Remote_Cards_Provider(
			$this->accounts(),
			$this->snapshots(),
			$this->links(),
			$rows,
			$products,
			new Remote_Cards_Actions(
				$this->snapshots(),
				$this->links(),
				new Card_Linker( $this->links() ),
				new Link_Scheduler( $this->names() ),
				$this->bulk(),
				$license,
				$products,
				$rows
			),
			[
				'adapter_id'  => Sqlite_Tables::MARKETPLACE_ID,
				'marketplace' => 'Тестмаркет',
			]
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
