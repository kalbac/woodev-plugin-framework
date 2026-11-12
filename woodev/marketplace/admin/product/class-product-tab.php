<?php
/**
 * The marketplace tab of the product edit screen and the marketplace fields of a variation.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Guard\Admin_Guard;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Category_Ancestry;
use Woodev\Framework\Marketplace\Flows\Export_Service;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;

defined( 'ABSPATH' ) || exit;

/**
 * The product edit screen's tab (one block per enabled account) and the per-variation panel.
 *
 * **Every mutation goes through {@see Admin_Guard}**:
 *
 * | Mutation | Capability | How it arrives | Nonce |
 * |---|---|---|---|
 * | the fields of the tab and of a variation (a product link change) | `edit_product` on the product (a variation: its parent) | the product's own «Обновить» (`woocommerce_process_product_meta`, `woocommerce_save_product_variation`) | {@see Screen_Names::save_nonce()}, in the field {@see Screen_Names::nonce_field()} (the form's `_wpnonce` belongs to WordPress) |
 * | «Снять связь» (a product link change) | `edit_product` on the product | AJAX {@see Screen_Names::product_action()}, `op` = `detach` | `{save nonce}_detach`, in `_wpnonce` |
 * | «Экспортировать» / «Обновить карточку», «Связать по артикулу», «Архивировать» (export and remote writes) | `manage_woocommerce` **and** `edit_product` on the product | AJAX, `op` = `export`, `link`, `archive` | `{save nonce}_{op}`, in `_wpnonce` |
 *
 * The account comes from the request but is never trusted alone: it must exist, belong to this marketplace **and** be enabled. A
 * posted body is read through {@see Link_Form_Input}, which refuses a body of the wrong shape, and written by
 * {@see Link_Settings_Writer}.
 *
 * Nothing here calls the marketplace. «Экспортировать» validates and queues through {@see Export_Service}; «Связать» queues the
 * linking job **for the product's category** (the job has no single-product mode), so it needs the shop-wide capability and the
 * button says which category it works on before it is pressed; «Архивировать» records the archive intent (stock zero, then the card
 * goes to the marketplace's archive); «Снять связь» is local ({@see Link_Detacher}).
 *
 * The plugin calls {@see self::register()} when it wires the marketplace; the tab is then on every product edit screen.
 *
 * @since 2.1.2
 */
final class Product_Tab {
	public const OPS = [ 'export', 'link', 'detach', 'archive' ];

	/**
	 * Operations that write to the marketplace or reach beyond the one product: they need the shop-wide capability as well.
	 */
	public const SHOP_OPS = [ 'export', 'link', 'archive' ];

	private const CAPABILITY   = 'edit_product';
	private const SHOP_CAP     = 'manage_woocommerce';
	private const ASSET_HANDLE = 'woodev-marketplace-product-tab';

	/**
	 * Authorisation.
	 *
	 * @var Admin_Guard
	 */
	private $guard;

	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Read side.
	 *
	 * @var Panel_Reader
	 */
	private $reader;

	/**
	 * Markup.
	 *
	 * @var Panel_View
	 */
	private $view;

	/**
	 * Write side.
	 *
	 * @var Link_Settings_Writer
	 */
	private $writer;

	/**
	 * Parses the posted body.
	 *
	 * @var Link_Form_Input
	 */
	private $input;

	/**
	 * Export.
	 *
	 * @var Export_Service
	 */
	private $export;

	/**
	 * Linking jobs.
	 *
	 * @var Link_Scheduler
	 */
	private $scheduler;

	/**
	 * Licence.
	 *
	 * @var License_Gate
	 */
	private $license;

	/**
	 * Detaches a card.
	 *
	 * @var Link_Detacher
	 */
	private $detacher;

	/**
	 * Stock and archive intents.
	 *
	 * @var Stock_Intents
	 */
	private $stock;

	/**
	 * Names.
	 *
	 * @var Screen_Names
	 */
	private $names;

	/**
	 * URLs the markup links to: `settings`, `mappings`, `assets` (folder URL of the module's assets, with a trailing slash).
	 *
	 * @var array<string,string>
	 */
	private $urls;

	/**
	 * Plugin version, for the cache of the assets.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Operations the marketplace cannot serve.
	 *
	 * @var string[]
	 */
	private $withheld;

	/**
	 * Creates the tab.
	 *
	 * @since 2.1.2
	 *
	 * @param Admin_Guard          $guard     Authorisation (built with {@see Admin_Guard::for_store()}).
	 * @param Wpdb_Account_Store   $accounts  Accounts.
	 * @param Wpdb_Link_Store      $links     Links.
	 * @param Panel_Reader         $reader    Read side.
	 * @param Panel_View           $view      Markup.
	 * @param Link_Settings_Writer $writer    Write side.
	 * @param Link_Form_Input      $input     Parses the posted body.
	 * @param Export_Service       $export    Export.
	 * @param Link_Scheduler       $scheduler Linking jobs.
	 * @param License_Gate         $license   Licence.
	 * @param Link_Detacher        $detacher  Detaches a card.
	 * @param Stock_Intents        $stock     Stock and archive intents.
	 * @param Screen_Names         $names     Names.
	 * @param array<string,string> $urls      `settings`, `mappings`, `assets`.
	 * @param string               $version   Plugin version.
	 * @param string[]             $withheld  Operations the marketplace cannot serve (`export`, `link`): no button, and the AJAX refuses them.
	 */
	public function __construct( Admin_Guard $guard, Wpdb_Account_Store $accounts, Wpdb_Link_Store $links, Panel_Reader $reader, Panel_View $view, Link_Settings_Writer $writer, Link_Form_Input $input, Export_Service $export, Link_Scheduler $scheduler, License_Gate $license, Link_Detacher $detacher, Stock_Intents $stock, Screen_Names $names, array $urls, string $version, array $withheld = [] ) {
		$this->guard     = $guard;
		$this->accounts  = $accounts;
		$this->links     = $links;
		$this->reader    = $reader;
		$this->view      = $view;
		$this->writer    = $writer;
		$this->input     = $input;
		$this->export    = $export;
		$this->scheduler = $scheduler;
		$this->license   = $license;
		$this->detacher  = $detacher;
		$this->stock     = $stock;
		$this->names     = $names;
		$this->urls      = $urls;
		$this->version   = $version;
		$this->withheld  = $withheld;
	}

	/**
	 * Hooks the tab, the variation panel, both saves, the AJAX endpoint and the assets.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_panel' ] );
		add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation' ], 10, 3 );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_product' ] );
		add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation' ], 10, 2 );
		add_action( 'wp_ajax_' . $this->names->product_action(), [ $this, 'handle_ajax' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Adds the tab to the product data box of simple and variable products.
	 *
	 * @since 2.1.2
	 *
	 * @param mixed $tabs Tabs.
	 * @return array<string,mixed>
	 */
	public function add_tab( $tabs ): array {
		$tabs                              = is_array( $tabs ) ? $tabs : [];
		$tabs[ $this->names->tab_key() ] = [
			'label'    => $this->names->name(),
			'target'   => $this->names->tab_key() . '_product_data',
			'class'    => [ 'show_if_simple', 'show_if_variable', 'woodev-mp-tab-link' ],
			'priority' => 75,
		];

		return $tabs;
	}

	/**
	 * Prints the panel of the tab.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function render_panel(): void {
		$product = $this->current_product();

		if ( null === $product || ! $this->may_edit( $product ) ) {
			return;
		}

		$blocks = [];

		foreach ( $this->accounts->all( $this->names->id(), true ) as $account ) {
			$blocks[] = $product->is_type( 'variable' ) ? $this->reader->parent_block( $account, $product ) : $this->reader->offer_block( $account, $product );
		}

		$this->view->render_tab(
			[
				'blocks'       => $blocks,
				'nonce'        => wp_create_nonce( $this->names->save_nonce() ),
				'ops'          => $this->op_nonces(),
				'link_scope'   => $this->link_scope_name( $product ),
				'settings_url' => $this->urls['settings'],
				'mappings_url' => $this->urls['mappings'],
			]
		);
	}

	/**
	 * Prints the marketplace fields of one variation.
	 *
	 * @since 2.1.2
	 *
	 * @param mixed $loop           Position of the variation in the form.
	 * @param mixed $variation_data Unused.
	 * @param mixed $variation      The variation's post.
	 * @return void
	 */
	public function render_variation( $loop = 0, $variation_data = [], $variation = null ): void {
		$product = $variation instanceof \WP_Post ? wc_get_product( $variation->ID ) : null;

		if ( ! $product instanceof \WC_Product || ! $this->may_edit( $product ) ) {
			return;
		}

		$blocks = [];

		foreach ( $this->accounts->all( $this->names->id(), true ) as $account ) {
			$blocks[] = $this->reader->offer_block( $account, $product );
		}

		$this->view->render_variation(
			[
				'blocks'       => $blocks,
				'nonce'        => wp_create_nonce( $this->names->save_nonce() ),
				'loop'         => (int) $loop,
				'settings_url' => $this->urls['settings'],
				'mappings_url' => $this->urls['mappings'],
			]
		);
	}

	/**
	 * Saves the fields of the tab with the product.
	 *
	 * @since 2.1.2
	 *
	 * @param mixed $post_id Product id.
	 * @return void
	 */
	public function save_product( $post_id ): void {
		$product = wc_get_product( (int) $post_id );
		$posted  = $this->posted( $this->names->post_key() );

		if ( $product instanceof \WC_Product && [] !== $posted ) {
			$this->save_blocks( $product, (int) $product->get_id(), $posted );
		}
	}

	/**
	 * Saves the marketplace fields of one variation.
	 *
	 * @since 2.1.2
	 *
	 * @param mixed $variation_id Variation id.
	 * @param mixed $loop         Position of the variation in the form.
	 * @return void
	 */
	public function save_variation( $variation_id, $loop = 0 ): void {
		$variation = wc_get_product( (int) $variation_id );
		$posted    = $this->posted( $this->names->variation_key() );
		$blocks    = isset( $posted[ (int) $loop ] ) && is_array( $posted[ (int) $loop ] ) ? $posted[ (int) $loop ] : [];

		if ( $variation instanceof \WC_Product && [] !== $blocks ) {
			$this->save_blocks( $variation, (int) $variation->get_parent_id(), $blocks );
		}
	}

	/**
	 * Answers the buttons: nonce and capability first, then the account, then the operation.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function handle_ajax(): void {
		$op         = $this->posted_op();
		$product_id = $this->posted_id( 'product_id' );
		$account_id = $this->posted_id( 'account_id' );

		if ( '' === $op || $product_id <= 0 || $account_id <= 0 || in_array( $op, $this->withheld, true ) ) {
			$this->fail( __( 'Запрос не удалось разобрать. Обновите страницу и повторите.', 'woodev-plugin-framework' ), 400 );
		}

		$this->require_ajax( self::CAPABILITY, $this->names->save_nonce() . '_' . $op, $product_id, $account_id );

		if ( in_array( $op, self::SHOP_OPS, true ) ) {
			$this->require_ajax( self::SHOP_CAP, $this->names->save_nonce() . '_' . $op, null, $account_id );
		}

		$account = $this->enabled_account( $account_id );
		$product = wc_get_product( $product_id );

		if ( null === $account || ! $product instanceof \WC_Product || ! in_array( $product->get_type(), [ 'simple', 'variable' ], true ) ) {
			$this->fail( __( 'Аккаунт выключен или товар не найден. Обновите страницу.', 'woodev-plugin-framework' ), 400 );
		}

		switch ( $op ) {
			case 'export':
				$this->export_product( $account, $product );
				break;
			case 'link':
				$this->link_product( $account, $product );
				break;
			case 'archive':
				$this->archive_product( $account, $product );
				break;
			default:
				$this->detach_product( $account, $product );
		}
	}

	/**
	 * Loads the stylesheet and the script on the product edit screen.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'product' !== $screen->id ) {
			return;
		}

		$suffix = \Woodev_Plugin::get_assets_suffix();

		wp_enqueue_style( self::ASSET_HANDLE, $this->urls['assets'] . 'css/admin/product-tab' . $suffix . '.css', [], $this->version );
		wp_enqueue_script( self::ASSET_HANDLE, $this->urls['assets'] . 'js/admin/product-tab' . $suffix . '.js', [ 'jquery' ], $this->version, true );
		wp_localize_script(
			self::ASSET_HANDLE,
			'woodevMarketplaceProduct',
			[
				'texts' => [
					'working' => __( 'Выполняем…', 'woodev-plugin-framework' ),
					'failed'  => __( 'Не получилось: страница не ответила. Обновите её и повторите.', 'woodev-plugin-framework' ),
				],
			]
		);
	}

	/**
	 * Verifies the nonce, the capability and the account of an AJAX request; answers 403 and ends the request when any fails.
	 *
	 * @param string   $capability   Capability.
	 * @param string   $nonce_action Nonce action.
	 * @param int|null $object_id    Object the capability is checked on.
	 * @param int      $account_id   Account id.
	 * @return void
	 */
	private function require_ajax( string $capability, string $nonce_action, ?int $object_id, int $account_id ): void {
		if ( ! $this->guard->check_request( $capability, $nonce_action, $object_id, $account_id ) ) {
			$this->fail( __( 'Страница устарела или у вас нет прав для этого действия. Обновите страницу и повторите.', 'woodev-plugin-framework' ), 403 );
		}
	}

	/**
	 * Writes the posted blocks of one product or variation.
	 *
	 * @param \WC_Product  $product   Product or variation being saved.
	 * @param int          $object_id Product the capability is checked on: the product, or a variation's parent.
	 * @param array<mixed> $blocks    `account id => fields`.
	 * @return void
	 */
	private function save_blocks( \WC_Product $product, int $object_id, array $blocks ): void {
		foreach ( $blocks as $account_key => $raw ) {
			$account_id = is_int( $account_key ) || ( is_string( $account_key ) && ctype_digit( $account_key ) ) ? (int) $account_key : 0;
			$account    = $this->enabled_account( $account_id );

			if ( null === $account || ! $this->guard->check_request( self::CAPABILITY, $this->names->save_nonce(), $object_id, $account_id, $this->names->nonce_field() ) ) {
				$this->report_save_problem( sprintf( /* translators: %s: marketplace name. */ __( 'Настройки «%s» не сохранены: страница устарела или у вас нет прав. Обновите страницу и повторите.', 'woodev-plugin-framework' ), $this->names->name() ) );
				continue;
			}

			$parsed = $this->input->parse( $raw );
			$errors = $parsed['errors'];

			if ( [] === $errors ) {
				try {
					$errors = $this->writer->save( $account, $product, $parsed['values'] );
				} catch ( \Throwable $e ) {
					$errors = [ sprintf( /* translators: %s: marketplace name. */ __( 'Не удалось сохранить настройки «%s». Повторите чуть позже.', 'woodev-plugin-framework' ), $this->names->name() ) ];
				}
			}

			foreach ( $errors as $error ) {
				$this->report_save_problem( sprintf( /* translators: 1: marketplace name, 2: account name, 3: what went wrong. */ __( '%1$s, аккаунт «%2$s»: %3$s', 'woodev-plugin-framework' ), $this->names->name(), $account->name(), $error ) );
			}
		}
	}

	/**
	 * «Экспортировать» / «Обновить карточку»: validates and queues, never calls the marketplace.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product (a variable one stands for its variations).
	 * @return void
	 */
	private function export_product( Account $account, \WC_Product $product ): void {
		$this->require_licence( __( 'отправить товар на маркетплейс', 'woodev-plugin-framework' ) );

		try {
			$report = $this->export->queue( $account, [ (int) $product->get_id() ] );
		} catch ( \Throwable $e ) {
			$this->fail( __( 'Не удалось поставить товар в очередь. Проверьте, что в WooCommerce работает планировщик задач (Action Scheduler), и повторите.', 'woodev-plugin-framework' ), 500 );
		}

		$queued  = count( $report->valid_ids() );
		$blocked = count( $report->blocked_ids() );

		if ( 0 === $queued ) {
			$this->fail(
				0 === $blocked ? __( 'Нечего отправлять: у товара нет вариантов, которые продаются на маркетплейсе.', 'woodev-plugin-framework' ) : sprintf( /* translators: %s: reasons. */ __( 'Товар не прошёл проверку и не отправлен. %s', 'woodev-plugin-framework' ), $report->summary() ),
				422
			);
		}

		$message = sprintf( /* translators: %d: number of cards. */ _n( 'Поставили в очередь на отправку: %d карточка.', 'Поставили в очередь на отправку: %d карточек.', $queued, 'woodev-plugin-framework' ), $queued );
		$message = 0 === $blocked ? $message : $message . ' ' . sprintf( /* translators: %s: reasons. */ __( 'Не прошли проверку: %s', 'woodev-plugin-framework' ), $report->summary() );

		$this->succeed( $message . ' ' . __( 'Состояние обновится через минуту-две — обновите страницу.', 'woodev-plugin-framework' ), $account, $product );
	}

	/**
	 * «Связать по артикулу»: queues the linking job for the product's category (the job has no single-product mode).
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	private function link_product( Account $account, \WC_Product $product ): void {
		$this->require_licence( __( 'искать карточки на маркетплейсе', 'woodev-plugin-framework' ) );

		$category = $this->link_scope_term( $product );

		if ( ! $this->scheduler->link_by_article( $account->id(), $category ) ) {
			$this->fail( __( 'Не удалось поставить поиск в очередь. Проверьте, что в WooCommerce работает планировщик задач (Action Scheduler), и повторите.', 'woodev-plugin-framework' ), 500 );
		}

		$this->succeed(
			null === $category
				? __( 'Ищем карточки маркетплейса с совпадающим артикулом среди всех товаров магазина: у этого товара нет категории. Результат появится через минуту-две; обновите страницу.', 'woodev-plugin-framework' )
				: __( 'Ищем карточки маркетплейса с совпадающим артикулом у товаров этой категории. Результат появится через минуту-две; обновите страницу. Похожие, но не совпавшие артикулы покажет раздел «Товары».', 'woodev-plugin-framework' ),
			$account,
			$product
		);
	}

	/**
	 * «Снять связь»: forgets the card on the site (a variable product: all its variations). Nothing is sent to the marketplace.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	private function detach_product( Account $account, \WC_Product $product ): void {
		$rows = [];

		foreach ( $this->offer_ids_of( $product ) as $id ) {
			$row = $this->links->find( $account->id(), $id );

			if ( null !== $row && $row->is_offer() && Link_Record::STATE_UNLINKED !== $row->card_state() ) {
				$rows[] = $row;
			}
		}

		if ( [] === $rows ) {
			$this->fail( __( 'Связи нет — снимать нечего.', 'woodev-plugin-framework' ), 409 );
		}

		try {
			$outcome = $this->detacher->detach( $account->id(), $rows );
		} catch ( \Throwable $e ) {
			$this->fail( __( 'Не удалось снять связь. Повторите чуть позже.', 'woodev-plugin-framework' ), 500 );
		}

		if ( $outcome['busy'] ) {
			$this->fail( __( 'Сейчас идёт отправка остатков и цен этого аккаунта на маркетплейс. Повторите через минуту.', 'woodev-plugin-framework' ), 409 );
		}

		if ( 0 === $outcome['detached'] ) {
			$this->fail( __( 'Товар сейчас выгружается на маркетплейс. Повторите чуть позже.', 'woodev-plugin-framework' ), 409 );
		}

		$message = __( 'Связь снята. Карточка на маркетплейсе не изменилась. Обновите страницу: после этого можно выбрать артикул и выгрузить товар заново.', 'woodev-plugin-framework' );
		$message = $outcome['kept'] > 0 ? $message . ' ' . __( 'Часть вариантов выгружается прямо сейчас — для них повторите позже.', 'woodev-plugin-framework' ) : $message;

		$this->succeed( $message, $account, $product );
	}

	/**
	 * «Архивировать»: records the archive intent of each created card; the account's sender sets the stock to zero and then archives
	 * the card. The product on the site is not changed.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product (a variable one stands for its variations).
	 * @return void
	 */
	private function archive_product( Account $account, \WC_Product $product ): void {
		$this->require_licence( __( 'архивировать карточку на маркетплейсе', 'woodev-plugin-framework' ) );

		$recorded = 0;

		try {
			foreach ( $this->offer_ids_of( $product ) as $id ) {
				$row = $this->links->find( $account->id(), $id );

				if ( null !== $row && $row->is_offer() && $this->stock->record_archive( $row ) ) {
					++$recorded;
				}
			}
		} catch ( \Throwable $e ) {
			$this->fail( __( 'Не удалось записать архивацию. Повторите чуть позже.', 'woodev-plugin-framework' ), 500 );
		}

		if ( 0 === $recorded ) {
			$this->fail( __( 'Архивировать нечего: на маркетплейсе нет созданной карточки этого товара.', 'woodev-plugin-framework' ), 409 );
		}

		$this->succeed( __( 'Архивация поставлена в очередь: остаток на маркетплейсе станет нулевым, затем карточка уйдёт в архив. Это происходит в течение нескольких минут; состояние обновится — обновите страницу. Товар на сайте не меняется.', 'woodev-plugin-framework' ), $account, $product );
	}

	/**
	 * Answers 403 and ends the request when the licence is not in force.
	 *
	 * @param string $action What the merchant tried to do, as an infinitive phrase.
	 * @return void
	 */
	private function require_licence( string $action ): void {
		$refusal = $this->license->refusal_for( $action );

		if ( null !== $refusal ) {
			$this->fail( $refusal, 403 );
		}
	}

	/**
	 * Lists the products whose rows a button acts on: the product, or a variable product's variations.
	 *
	 * @param \WC_Product $product Product.
	 * @return int[]
	 */
	private function offer_ids_of( \WC_Product $product ): array {
		return $product->is_type( 'variable' ) ? array_map( 'intval', (array) $product->get_children() ) : [ (int) $product->get_id() ];
	}

	/**
	 * Answers success with the product's state badge.
	 *
	 * @param string      $message Sentence for the merchant.
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	private function succeed( string $message, Account $account, \WC_Product $product ): void {
		$row = $this->links->find( $account->id(), (int) $product->get_id() );

		wp_send_json_success(
			[
				'message' => $message,
				'badge'   => $product->is_type( 'variable' ) || null === $row ? null : Card_State_Badge::of( $row->card_state() ),
			]
		);
	}

	/**
	 * Answers an error and ends the request.
	 *
	 * @param string $message Sentence for the merchant.
	 * @param int    $status  HTTP status.
	 * @return never
	 */
	private function fail( string $message, int $status ): void {
		wp_send_json_error( [ 'message' => $message ], $status );
	}

	/**
	 * Shows a save problem on the next product screen.
	 *
	 * @param string $message Sentence for the merchant.
	 * @return void
	 */
	private function report_save_problem( string $message ): void {
		if ( class_exists( '\WC_Admin_Meta_Boxes' ) ) {
			\WC_Admin_Meta_Boxes::add_error( $message );
		}
	}

	/**
	 * Names the category «Связать по артикулу» works on for a product, '' when the product has none (the job then takes the shop).
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private function link_scope_name( \WC_Product $product ): string {
		$term_id = $this->link_scope_term( $product );
		$term    = null === $term_id ? null : get_term( $term_id, 'product_cat' );

		return $term instanceof \WP_Term ? (string) $term->name : '';
	}

	/**
	 * Picks the category the linking job is queued for: the product's closest one.
	 *
	 * @param \WC_Product $product Product.
	 * @return int|null Null when the product has no category.
	 */
	private function link_scope_term( \WC_Product $product ): ?int {
		$terms = Category_Ancestry::for_product( $product );

		return [] === $terms ? null : (int) reset( $terms );
	}

	/**
	 * Returns the nonces of the buttons the user may press: the ones that need the shop-wide capability are left out for a user who
	 * has only `edit_product`, so the screen does not offer what the guard would refuse.
	 *
	 * @return array<string,string>
	 */
	private function op_nonces(): array {
		$nonces = [];
		$shop   = current_user_can( self::SHOP_CAP ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's own capability.

		foreach ( self::OPS as $op ) {
			if ( in_array( $op, $this->withheld, true ) ) {
				continue;
			}

			if ( $shop || ! in_array( $op, self::SHOP_OPS, true ) ) {
				$nonces[ $op ] = wp_create_nonce( $this->names->save_nonce() . '_' . $op );
			}
		}

		return $nonces;
	}

	/**
	 * Loads an enabled account of this marketplace.
	 *
	 * @param int $account_id Account id.
	 * @return Account|null Null when it does not exist, belongs to another marketplace or is switched off.
	 */
	private function enabled_account( int $account_id ): ?Account {
		$account = $account_id > 0 ? $this->accounts->get( $account_id ) : null;

		return null !== $account && $account->adapter_id() === $this->names->id() && $account->is_enabled() ? $account : null;
	}

	/**
	 * Tells whether the user may edit the product (a variation: its parent).
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return bool
	 */
	private function may_edit( \WC_Product $product ): bool {
		$id = $product->get_parent_id() > 0 ? (int) $product->get_parent_id() : (int) $product->get_id();

		return current_user_can( self::CAPABILITY, $id ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined,WordPress.WP.Capabilities.Unknown -- The meta capability of the product post type.
	}

	/**
	 * Returns the product the data box is printed for.
	 *
	 * @return \WC_Product|null
	 */
	private function current_product(): ?\WC_Product {
		global $product_object, $post;

		if ( $product_object instanceof \WC_Product ) {
			return $product_object;
		}

		$product = $post instanceof \WP_Post ? wc_get_product( $post->ID ) : null;

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Reads a posted array of plain text.
	 *
	 * @param string $key Field name.
	 * @return array<mixed>
	 */
	private function posted( string $key ): array {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked per account by Admin_Guard before anything is written.
			return [];
		}

		return map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- As above; sanitised by map_deep.
	}

	/**
	 * Reads the operation of an AJAX request, '' when it is not one of ours.
	 *
	 * @return string
	 */
	private function posted_op(): string {
		$op = isset( $_POST['op'] ) && is_string( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked by Admin_Guard right after, for the operation read here.

		return in_array( $op, self::OPS, true ) ? $op : '';
	}

	/**
	 * Reads a positive integer of an AJAX request, 0 when it is not one.
	 *
	 * @param string $key Field name.
	 * @return int
	 */
	private function posted_id( string $key ): int {
		$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked by Admin_Guard right after.

		return 1 === preg_match( '/^[1-9]\d{0,17}$/', $value ) ? (int) $value : 0;
	}
}
