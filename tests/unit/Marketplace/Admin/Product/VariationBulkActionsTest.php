<?php
/**
 * Unit tests for the marketplace group of the variation panel's «Bulk actions» select.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Product;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Selection;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Admin\Product\Variation_Bulk_Actions;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Product\Variation_Bulk_Actions
 */
final class VariationBulkActionsTest extends TestCase {
	private const PRODUCT = 77;

	/**
	 * Capabilities the user holds.
	 *
	 * @var string[]
	 */
	private $caps;

	/**
	 * Whether the licence is in force.
	 *
	 * @var bool
	 */
	private $licensed;

	/**
	 * What the table's `apply()` was asked: one entry per call.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $calls;

	/**
	 * What the table's `apply()` answers, or an exception to throw.
	 *
	 * @var array<string,mixed>|\Throwable
	 */
	private $answer;

	/**
	 * Accounts the store knows, by id.
	 *
	 * @var array<int,Account>
	 */
	private $accounts;

	/**
	 * Lines written to the log.
	 *
	 * @var string[]
	 */
	private $logged;

	protected function setUp(): void {
		parent::setUp();

		$this->caps     = [ 'edit_product', 'manage_woocommerce' ];
		$this->licensed = true;
		$this->calls    = [];
		$this->logged   = [];
		$this->answer   = $this->queued_answer( 3 );
		$this->accounts = [
			1 => new Account( 1, 'fake', 'Основной', true ),
			2 => new Account( 2, 'fake', 'Запасной', true ),
			3 => new Account( 3, 'fake', 'Выключен', false ),
			4 => new Account( 4, 'other', 'Чужой', true ),
		];

		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, $this->caps, true );
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'product' ] );
	}

	public function test_it_hooks_the_two_actions_of_woocommerces_own_flow_and_the_script(): void {
		Actions\expectAdded( 'woocommerce_variable_product_bulk_edit_actions' )->once();
		Actions\expectAdded( 'woocommerce_bulk_edit_variations' )->once();
		Actions\expectAdded( 'admin_enqueue_scripts' )->once();

		$this->group()->register();
	}

	public function test_one_account_gets_one_group_named_after_the_marketplace_with_v1s_nine_actions(): void {
		$this->accounts = [ 1 => $this->accounts[1] ];

		ob_start();
		$this->group()->render_options();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, '<optgroup label="Fake Market">' ) );
		$this->assertSame( 9, substr_count( $html, '<option ' ) );
		$this->assertStringContainsString( '<option value="woodev_fake_bulk_1_export">Экспортировать / Обновить карточку</option>', $html );
		$this->assertStringContainsString( 'value="woodev_fake_bulk_1_detach">Снять связь', $html );
		$this->assertStringContainsString( 'value="woodev_fake_bulk_1_pull_prices">Загрузить цены', $html );
		$this->assertStringContainsString( 'value="woodev_fake_bulk_1_sync_stock_off">Выключить синхронизацию остатков', $html );
	}

	public function test_several_accounts_get_a_group_each_and_a_disabled_one_none(): void {
		ob_start();
		$this->group( [ 1, 2 ] )->render_options();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<optgroup label="Fake Market — Основной">', $html );
		$this->assertStringContainsString( '<optgroup label="Fake Market — Запасной">', $html );
		$this->assertStringContainsString( 'value="woodev_fake_bulk_2_send_stock"', $html );
		$this->assertStringNotContainsString( 'Выключен', $html );
	}

	public function test_a_marketplace_with_no_enabled_account_prints_nothing(): void {
		ob_start();
		$this->group( [] )->render_options();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_withheld_actions_are_not_offered_and_not_run(): void {
		$group = $this->group( [ 1 ], [ Site_Products_Bulk::EXPORT, Site_Products_Bulk::PULL_PRICES ] );

		ob_start();
		$group->render_options();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '_export"', $html );
		$this->assertStringNotContainsString( '_pull_prices"', $html );
		$this->assertSame( 7, substr_count( $html, '<option ' ) );
		$this->assertNull( $group->parse( 'woodev_fake_bulk_1_export' ) );

		ob_start();
		$group->handle( 'woodev_fake_bulk_1_export', [], self::PRODUCT, [ 1 ] );

		$this->assertSame( '', (string) ob_get_clean() );
		$this->assertSame( [], $this->calls );
	}

	public function test_a_value_that_is_not_ours_is_left_alone_and_prints_nothing(): void {
		foreach ( [ 'variable_regular_price', 'delete_all', 'woodev_other_bulk_1_export', 'woodev_fake_bulk_1_archive', 'woodev_fake_bulk_x_export', '' ] as $value ) {
			$this->assertNull( $this->group()->parse( $value ), $value );
			$this->assertSame( '', $this->handle( $value ), $value );
		}

		$this->assertSame( [], $this->calls );
	}

	public function test_an_action_queues_every_variation_of_the_product_through_the_tables_apply(): void {
		$notice = $this->notice( $this->handle( 'woodev_fake_bulk_2_send_price', [ 101, 102, 103 ] ) );

		$this->assertCount( 1, $this->calls );
		$this->assertSame( 2, $this->calls[0]['account']->id() );
		$this->assertSame( Site_Products_Bulk::SEND_PRICE, $this->calls[0]['action'] );
		$this->assertSame( [ 101, 102, 103 ], $this->calls[0]['offers'] );
		$this->assertSame( [ 101 => self::PRODUCT, 102 => self::PRODUCT, 103 => self::PRODUCT ], $this->calls[0]['editable'] );
		$this->assertSame( 'success', $notice['tone'] );
		$this->assertStringContainsString( 'Принято в работу: 3 товара', $notice['text'] );
		$this->assertSame( 'https://example.test/products', $notice['url'] );
		$this->assertSame( 'Открыть «Товары»', $notice['link'] );
	}

	public function test_the_comment_cannot_be_closed_early_by_the_text(): void {
		$this->answer['messages']['action_status_done'] = 'A --> <script>alert(1)</script>';

		$raw = $this->handle( 'woodev_fake_bulk_1_export', [ 101 ] );

		$this->assertSame( 1, substr_count( $raw, '-->' ), 'The only `-->` closes the comment.' );
		$this->assertStringNotContainsString( '<script>', $raw );
		$this->assertSame( 'A --> <script>alert(1)</script>', $this->notice( $raw )['text'] );
	}

	public function test_what_was_left_out_is_named_with_its_reasons_and_the_notice_warns(): void {
		$this->answer = [
			'requested' => 5,
			'accepted'  => 3,
			'skipped'   => 2,
			'failed'    => 0,
			'queued'    => true,
			'groups'    => [
				[ 'kind' => 'skipped', 'reason' => 'не выбран склад', 'count' => 2 ],
			],
			'messages'  => [ 'action_status_done' => 'Принято в работу: 3 товара.' ],
		];

		$notice = $this->notice( $this->handle( 'woodev_fake_bulk_1_send_stock', [ 1, 2, 3, 4, 5 ] ) );

		$this->assertSame( 'warning', $notice['tone'] );
		$this->assertSame( 'Принято в работу: 3 товара. Не обработано: не выбран склад (2).', $notice['text'] );
	}

	public function test_nothing_accepted_is_a_warning_and_a_failed_write_an_error_and_neither_links_to_the_progress(): void {
		$this->answer = [
			'accepted' => 0,
			'skipped'  => 4,
			'failed'   => 0,
			'queued'   => false,
			'groups'   => [ [ 'reason' => 'уже настроено так', 'count' => 4 ] ],
			'messages' => [ 'action_status_done' => 'Ничего не изменилось.' ],
		];

		$warning = $this->notice( $this->handle( 'woodev_fake_bulk_1_sync_stock_on', [ 1, 2, 3, 4 ] ) );

		$this->assertSame( 'warning', $warning['tone'] );
		$this->assertSame( '', $warning['url'] );

		$this->answer['failed'] = 4;

		$this->assertSame( 'error', $this->notice( $this->handle( 'woodev_fake_bulk_1_sync_stock_on', [ 1, 2, 3, 4 ] ) )['tone'] );
	}

	public function test_a_local_action_needs_only_edit_product_and_no_licence(): void {
		$this->caps     = [ 'edit_product' ];
		$this->licensed = false;

		foreach ( [ 'sync_stock_on', 'sync_stock_off', 'sync_price_on', 'sync_price_off', 'detach' ] as $action ) {
			$this->assertNotSame( 'error', $this->notice( $this->handle( 'woodev_fake_bulk_1_' . $action, [ 1 ] ) )['tone'], $action );
		}

		$this->assertCount( 5, $this->calls );
	}

	public function test_a_write_to_the_marketplace_needs_the_shop_capability_and_a_licence(): void {
		$remote = [ 'export', 'send_stock', 'send_price', 'pull_prices' ];

		$this->caps = [ 'edit_product' ];

		foreach ( $remote as $action ) {
			$this->assertSame( 'У вас нет прав на это действие.', $this->notice( $this->handle( 'woodev_fake_bulk_1_' . $action, [ 1 ] ) )['text'], $action );
		}

		$this->caps     = [ 'edit_product', 'manage_woocommerce' ];
		$this->licensed = false;

		foreach ( $remote as $action ) {
			$notice = $this->notice( $this->handle( 'woodev_fake_bulk_1_' . $action, [ 1 ] ) );

			$this->assertSame( 'error', $notice['tone'], $action );
			$this->assertStringContainsString( 'Лицензия плагина не действует', $notice['text'], $action );
		}

		$this->assertSame( [], $this->calls, 'Nothing reached the table.' );
	}

	public function test_a_user_who_cannot_edit_the_product_changes_nothing(): void {
		$this->caps = [ 'manage_woocommerce' ];

		$this->assertSame( 'error', $this->notice( $this->handle( 'woodev_fake_bulk_1_detach', [ 1 ] ) )['tone'] );
		$this->assertSame( [], $this->calls );
	}

	public function test_a_missing_foreign_or_switched_off_account_is_refused(): void {
		foreach ( [ 99, 3, 4, 0 ] as $account ) {
			$notice = $this->notice( $this->handle( 'woodev_fake_bulk_' . $account . '_detach', [ 1 ] ) );

			$this->assertSame( 'Аккаунт не найден или выключен.', $notice['text'], (string) $account );
		}

		$this->assertSame( [], $this->calls );
	}

	public function test_more_variations_than_the_table_takes_are_refused_with_a_pointer_to_the_products_page(): void {
		$notice = $this->notice( $this->handle( 'woodev_fake_bulk_1_detach', range( 1, Site_Products_Selection::MAX_OFFERS + 1 ) ) );

		$this->assertSame( 'error', $notice['tone'] );
		$this->assertStringContainsString( (string) Site_Products_Selection::MAX_OFFERS, $notice['text'] );
		$this->assertSame( 'https://example.test/products', $notice['url'] );
		$this->assertSame( [], $this->calls );
	}

	public function test_the_queue_refusing_the_work_is_told_in_its_own_words(): void {
		$this->answer = new Action_State_Exception( 'Планировщик задач не работает.' );

		$notice = $this->notice( $this->handle( 'woodev_fake_bulk_1_export', [ 1 ] ) );

		$this->assertSame( 'error', $notice['tone'] );
		$this->assertSame( 'Планировщик задач не работает.', $notice['text'] );
	}

	public function test_an_unexpected_failure_never_escapes_into_woocommerces_request(): void {
		$this->answer = new \RuntimeException( 'database gone away' );

		$notice = $this->notice( $this->handle( 'woodev_fake_bulk_1_export', [ 1 ] ) );

		$this->assertSame( 'error', $notice['tone'] );
		$this->assertStringNotContainsString( 'database', $notice['text'] );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( 'database gone away', $this->logged[0] );
	}

	public function test_duplicates_and_zeros_in_the_list_are_dropped(): void {
		$this->handle( 'woodev_fake_bulk_1_detach', [ 5, 5, 0, 6 ] );

		$this->assertSame( [ 5, 6 ], $this->calls[0]['offers'] );
	}

	/**
	 * Runs the handler the way WooCommerce's hook does and returns what it printed.
	 *
	 * @param string $value      The chosen option.
	 * @param int[]  $variations The product's variations.
	 * @return string
	 */
	private function handle( string $value, array $variations = [ 1 ] ): string {
		ob_start();
		$this->group()->handle( $value, [], self::PRODUCT, $variations );

		return (string) ob_get_clean();
	}

	/**
	 * Reads the notice out of the comment the handler printed.
	 *
	 * @param string $raw The printed output.
	 * @return array<string,string>
	 */
	private function notice( string $raw ): array {
		$this->assertSame( 1, preg_match( '/^<!--woodev-bulk:(.*)-->$/s', $raw, $found ), $raw );

		return (array) json_decode( $found[1], true );
	}

	/**
	 * @param int $done Offers accepted.
	 * @return array<string,mixed>
	 */
	private function queued_answer( int $done ): array {
		return [
			'requested' => $done,
			'accepted'  => $done,
			'skipped'   => 0,
			'failed'    => 0,
			'queued'    => true,
			'groups'    => [],
			'messages'  => [ 'action_status_done' => 'Принято в работу: ' . $done . ' товара. Ход работы виден в столбце «Состояние».' ],
		];
	}

	/**
	 * @param int[]|null $enabled  Ids `all()` lists as enabled; the enabled accounts of the marketplace when null.
	 * @param string[]   $withheld Withheld actions.
	 * @return Variation_Bulk_Actions
	 */
	private function group( ?array $enabled = null, array $withheld = [] ): Variation_Bulk_Actions {
		$accounts = $this->accounts;
		$store    = Mockery::mock( Account_Store::class );

		$store->shouldReceive( 'get' )->andReturnUsing(
			static function ( int $id ) use ( $accounts ) {
				return $accounts[ $id ] ?? null;
			}
		);
		$store->shouldReceive( 'all' )->andReturnUsing(
			static function ( string $adapter_id ) use ( $accounts, $enabled ): array {
				return array_values(
					array_filter(
						$accounts,
						static function ( Account $account ) use ( $adapter_id, $enabled ): bool {
							return $account->adapter_id() === $adapter_id && $account->is_enabled() && ( null === $enabled || in_array( $account->id(), $enabled, true ) );
						}
					)
				);
			}
		);

		return new Variation_Bulk_Actions(
			$store,
			new License_Gate(
				function (): bool {
					return $this->licensed;
				}
			),
			new Screen_Names( 'fake', 'Fake Market' ),
			function ( Account $account, string $action, array $offers, array $editable ): array {
				$this->calls[] = compact( 'account', 'action', 'offers', 'editable' );

				if ( $this->answer instanceof \Throwable ) {
					throw $this->answer;
				}

				return $this->answer;
			},
			[
				'products' => 'https://example.test/products',
				'assets'   => 'https://example.test/assets/',
			],
			'1.0',
			$withheld,
			function ( string $line ): void {
				$this->logged[] = $line;
			}
		);
	}
}
