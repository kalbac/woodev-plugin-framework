<?php
/**
 * The marketplace group of the variation panel's «Bulk actions» select.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Selection;
use Woodev\Framework\Marketplace\Jobs\License_Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an optgroup named after the marketplace to the «Bulk actions» select of the Variations tab and runs its actions over every
 * variation of the product (WooCommerce hands a bulk action the whole list, not a selection). One optgroup per enabled account.
 *
 * The actions are the table's own ({@see Site_Products_Bulk}), so a variation is planned, queued and counted exactly as a row of the
 * «Товары» table is: exporting and sending prices, stock and the one-time price pull only record work for the queue, the sync
 * switches and unlinking write local data. No remote call happens in the request.
 *
 * It rides on WooCommerce's own flow: `woocommerce_bulk_edit_variations` fires after WooCommerce's handler, whatever the action, and
 * WooCommerce then syncs the product and reloads the variations. This class therefore never ends the request. It prints the notice
 * as a marked comment into the response (WooCommerce's script ignores the body); the script `variation-bulk.js` reads it and draws
 * the WooCommerce-style notice above the variations.
 *
 * @since 2.1.13
 */
final class Variation_Bulk_Actions {
	/**
	 * The table's actions offered here, in the order of v1's group.
	 */
	public const ACTIONS = [
		Site_Products_Bulk::EXPORT,
		Site_Products_Bulk::DETACH,
		Site_Products_Bulk::SYNC_PRICE_ON,
		Site_Products_Bulk::SYNC_PRICE_OFF,
		Site_Products_Bulk::SEND_PRICE,
		Site_Products_Bulk::PULL_PRICES,
		Site_Products_Bulk::SYNC_STOCK_ON,
		Site_Products_Bulk::SYNC_STOCK_OFF,
		Site_Products_Bulk::SEND_STOCK,
	];

	/**
	 * The actions a single variation's buttons run (the same as a simple product's buttons), in their order.
	 */
	public const BUTTONS = [
		Site_Products_Bulk::EXPORT,
		Site_Products_Bulk::ARCHIVE,
		Site_Products_Bulk::DETACH,
	];

	/**
	 * Handle of the script that draws the notice (one for every marketplace plugin on the screen).
	 */
	private const SCRIPT = 'woodev-marketplace-variation-bulk';

	/**
	 * The word that opens the comment carrying the notice in the response.
	 */
	private const MARK = 'woodev-bulk';

	/**
	 * Most reasons the notice names.
	 */
	private const REASONS = 3;

	/**
	 * @var Account_Store
	 */
	private $accounts;

	/**
	 * @var License_Gate
	 */
	private $license;

	/**
	 * @var Screen_Names
	 */
	private $names;

	/**
	 * Runs one action of the table: `function ( Account $account, string $action, int[] $offers, array $editable ): array`, the
	 * answer of {@see Site_Products_Bulk::apply()}.
	 *
	 * @var callable
	 */
	private $apply;

	/**
	 * @var array<string,string>
	 */
	private $urls;

	/**
	 * @var string
	 */
	private $version;

	/**
	 * @var string[]
	 */
	private $withheld;

	/**
	 * Writes a line to the plugin's log: `function ( string $line ): void`. Null for none.
	 *
	 * @var callable|null
	 */
	private $log;

	/**
	 * Creates the group.
	 *
	 * @since 2.1.13
	 *
	 * @param Account_Store        $accounts Accounts.
	 * @param License_Gate         $license  Licence.
	 * @param Screen_Names         $names    Names.
	 * @param callable             $apply    Runs an action of the table (see the property).
	 * @param array<string,string> $urls     `products` (the page that shows the progress), `assets` (the folder of the scripts).
	 * @param string               $version  Plugin version.
	 * @param string[]             $withheld Actions the marketplace cannot serve ({@see \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Module::withheld_actions()}).
	 * @param callable|null        $log      Writes a line to the log.
	 */
	public function __construct( Account_Store $accounts, License_Gate $license, Screen_Names $names, callable $apply, array $urls, string $version, array $withheld = [], ?callable $log = null ) {
		$this->accounts = $accounts;
		$this->license  = $license;
		$this->names    = $names;
		$this->apply    = $apply;
		$this->urls     = $urls;
		$this->version  = $version;
		$this->withheld = $withheld;
		$this->log      = $log;
	}

	/**
	 * Hooks the select, the handler and the script.
	 *
	 * @since 2.1.13
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_variable_product_bulk_edit_actions', [ $this, 'render_options' ] );
		add_action( 'woocommerce_bulk_edit_variations', [ $this, 'handle' ], 10, 4 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_' . $this->names->variations_action(), [ $this, 'handle_ajax' ] );
	}

	/**
	 * Tells the product tab what it may offer on a variable product: the select above the table of the variants (the same actions as the
	 * Variations tab's «Bulk actions»), the buttons of one variation, and the AJAX action and nonce both post with. An action the user may not
	 * run (a write to the marketplace needs the shop-wide capability) is left out, so the screen does not offer what the request would refuse.
	 *
	 * @since 2.1.13
	 *
	 * @return array{action:string,nonce:string,select:array<string,string>,buttons:string[]}
	 */
	public function panel_facts(): array {
		$select = [];

		foreach ( $this->offered() as $action ) {
			if ( $this->shop_may( $action ) ) {
				$select[ $action ] = Site_Products_Definition::label_of( $action );
			}
		}

		return [
			'action'  => $this->names->variations_action(),
			'nonce'   => wp_create_nonce( $this->names->variations_action() ),
			'select'  => $select,
			'buttons' => array_values( array_filter( self::BUTTONS, [ $this, 'is_allowed_button' ] ) ),
		];
	}

	/**
	 * Runs an action on the variations the merchant ticked in the table of the Ozon tab, or on the one variation whose button was pressed, and
	 * answers with the same notice the Variations tab draws. The work is {@see Site_Products_Bulk::apply()}, as for the select of that tab.
	 *
	 * @since 2.1.13
	 *
	 * @return void
	 */
	public function handle_ajax(): void {
		$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, $this->names->variations_action() ) ) {
			wp_send_json_error( [ 'message' => __( 'Страница устарела. Обновите её и повторите.', 'woodev-plugin-framework' ) ], 403 );
		}

		$action     = isset( $_POST['bulk_action'] ) && is_string( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$account_id = isset( $_POST['account_id'] ) ? absint( wp_unslash( $_POST['account_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint().
		$product    = isset( $_POST['product_id'] ) ? wc_get_product( absint( wp_unslash( $_POST['product_id'] ) ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint().
		$posted     = isset( $_POST['variations'] ) && is_array( $_POST['variations'] ) ? array_map( 'absint', wp_unslash( $_POST['variations'] ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() on every element.

		if ( ! in_array( $action, $this->ajax_actions(), true ) || ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error( [ 'message' => __( 'Запрос не удалось разобрать. Обновите страницу и повторите.', 'woodev-plugin-framework' ) ], 400 );
		}

		// Only the product's own variations: the ids come from the browser.
		$variations = array_values( array_intersect( $posted, array_map( 'intval', (array) $product->get_children() ) ) );

		if ( [] === $variations ) {
			wp_send_json_error( [ 'message' => __( 'Отметьте варианты, с которыми нужно что-то сделать.', 'woodev-plugin-framework' ) ], 400 );
		}

		wp_send_json_success( $this->run( $account_id, $action, (int) $product->get_id(), $variations ) );
	}

	/**
	 * Prints the optgroups: the marketplace's name, with the account's name added when there are several.
	 *
	 * @since 2.1.13
	 *
	 * @return void
	 */
	public function render_options(): void {
		$accounts = $this->accounts->all( $this->names->id(), true );

		foreach ( $accounts as $account ) {
			$label = 1 === count( $accounts ) ? $this->names->name() : $this->names->name() . ' — ' . $account->name();

			printf( '<optgroup label="%s">', esc_attr( $label ) );

			foreach ( $this->offered() as $action ) {
				printf( '<option value="%s">%s</option>', esc_attr( $this->value( $account->id(), $action ) ), esc_html( Site_Products_Definition::label_of( $action ) ) );
			}

			echo '</optgroup>';
		}
	}

	/**
	 * Runs the chosen action over the variations of the product and prints the answer. Does nothing for an action that is not this
	 * marketplace's, so WooCommerce's own actions and other plugins' are untouched.
	 *
	 * @since 2.1.13
	 *
	 * @param mixed $bulk_action The chosen option's value.
	 * @param mixed $data        Unused: the actions ask the merchant nothing.
	 * @param mixed $product_id  The variable product.
	 * @param mixed $variations  Every variation of the product.
	 * @return void
	 */
	public function handle( $bulk_action = '', $data = [], $product_id = 0, $variations = [] ): void {
		$target = $this->parse( (string) $bulk_action );

		if ( null === $target ) {
			return;
		}

		$notice = $this->run( $target['account_id'], $target['action'], (int) $product_id, array_map( 'absint', (array) $variations ) );

		// WooCommerce's script ignores the body; the comment is read by variation-bulk.js. JSON_HEX_TAG keeps `-->` out of the text.
		echo '<!--' . self::MARK . ':' . wp_json_encode( $notice, JSON_HEX_TAG | JSON_HEX_AMP ) . '-->'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with the tag characters escaped, inside a comment.
	}

	/**
	 * Reads the value of an option of this marketplace.
	 *
	 * @since 2.1.13
	 *
	 * @param string $bulk_action The chosen option's value.
	 * @return array{account_id:int,action:string}|null Null for any other value and for an action the marketplace cannot serve.
	 */
	public function parse( string $bulk_action ): ?array {
		$pattern = '/^' . preg_quote( $this->prefix(), '/' ) . '(\d+)_([a-z_]+)$/';

		if ( 1 !== preg_match( $pattern, $bulk_action, $found ) || ! in_array( $found[2], $this->offered(), true ) ) {
			return null;
		}

		return [
			'account_id' => (int) $found[1],
			'action'     => $found[2],
		];
	}

	/**
	 * Loads the script on the product edit screen and tells it which values are this marketplace's.
	 *
	 * @since 2.1.13
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'product' !== $screen->id ) {
			return;
		}

		wp_enqueue_style( self::SCRIPT, $this->urls['assets'] . 'css/admin/variation-bulk' . \Woodev_Plugin::get_assets_suffix() . '.css', [], $this->version );
		wp_enqueue_script( self::SCRIPT, $this->urls['assets'] . 'js/admin/variation-bulk' . \Woodev_Plugin::get_assets_suffix() . '.js', [ 'jquery' ], $this->version, true );

		$config = [
			'prefix'  => $this->prefix(),
			'failed'  => __( 'Не получилось: страница не ответила. Обновите её и повторите.', 'woodev-plugin-framework' ),
			'dismiss' => __( 'Скрыть это уведомление.', 'woodev-plugin-framework' ),
		];

		// One entry per marketplace plugin: the script is loaded once, whatever the number of plugins.
		wp_add_inline_script( self::SCRIPT, 'window.woodevMarketplaceVariationBulk = ( window.woodevMarketplaceVariationBulk || [] ).concat( [ ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ' ] );', 'before' );
	}

	/**
	 * Runs the action and says what happened, in the shape the script draws.
	 *
	 * @param int    $account_id Account.
	 * @param string $action     Action id.
	 * @param int    $product_id The variable product.
	 * @param int[]  $variations Its variations.
	 * @return array{tone:string,text:string,url:string,link:string}
	 */
	private function run( int $account_id, string $action, int $product_id, array $variations ): array {
		$account = $this->enabled_account( $account_id );

		if ( null === $account ) {
			return $this->notice( 'error', __( 'Аккаунт не найден или выключен.', 'woodev-plugin-framework' ) );
		}

		if ( ! $this->may( $action, $product_id ) ) {
			return $this->notice( 'error', __( 'У вас нет прав на это действие.', 'woodev-plugin-framework' ) );
		}

		$phrase  = Site_Products_Bulk::license_phrase( $action );
		$refusal = null === $phrase ? null : $this->license->refusal_for( $phrase );

		if ( null !== $refusal ) {
			return $this->notice( 'error', $refusal );
		}

		$offers = array_values( array_unique( array_filter( $variations ) ) );

		if ( count( $offers ) > Site_Products_Selection::MAX_OFFERS ) {
			return $this->notice(
				'error',
				sprintf( /* translators: %d: the most variations. */ __( 'У товара слишком много вариаций для одного раза: можно обработать не больше %d. Выполните действие на странице «Товары» — там оно идёт в фоне и справляется с любым числом.', 'woodev-plugin-framework' ), Site_Products_Selection::MAX_OFFERS ),
				true
			);
		}

		try {
			$result = call_user_func( $this->apply, $account, $action, $offers, array_fill_keys( $offers, $product_id ) );
		} catch ( Action_State_Exception $e ) {
			return $this->notice( 'error', $e->getMessage() );
		} catch ( \Throwable $e ) {
			// WooCommerce syncs the product after this hook: an exception here would skip that.
			if ( null !== $this->log ) {
				call_user_func( $this->log, 'Variation bulk action ' . $action . ' failed: ' . $e->getMessage() );
			}

			return $this->notice( 'error', __( 'Не удалось выполнить действие. Повторите чуть позже.', 'woodev-plugin-framework' ) );
		}

		return $this->describe( $result );
	}

	/**
	 * Turns the table's answer into a notice: what was queued or changed, why the rest was left out, and where progress is seen.
	 *
	 * @param array<string,mixed> $result Answer of {@see Site_Products_Bulk::apply()}.
	 * @return array{tone:string,text:string,url:string,link:string}
	 */
	private function describe( array $result ): array {
		$accepted = (int) ( $result['accepted'] ?? 0 );
		$left_out = (int) ( $result['skipped'] ?? 0 ) + (int) ( $result['failed'] ?? 0 );
		$text     = (string) ( $result['messages']['action_status_done'] ?? '' );
		$reasons  = [];

		foreach ( array_slice( (array) ( $result['groups'] ?? [] ), 0, self::REASONS ) as $group ) {
			$reasons[] = sprintf( '%1$s (%2$d)', (string) ( $group['reason'] ?? '' ), (int) ( $group['count'] ?? 0 ) );
		}

		if ( [] !== $reasons ) {
			/* translators: %s: reasons with the number of variations, separated by semicolons. */
			$text .= ' ' . sprintf( __( 'Не обработано: %s.', 'woodev-plugin-framework' ), implode( '; ', $reasons ) );
		}

		if ( 0 === $accepted ) {
			$tone = (int) ( $result['failed'] ?? 0 ) > 0 ? 'error' : 'warning';
		} else {
			$tone = $left_out > 0 ? 'warning' : 'success';
		}

		return $this->notice( $tone, trim( $text ), true === ( $result['queued'] ?? false ) );
	}

	/**
	 * @param string $tone   `success`, `warning` or `error`.
	 * @param string $text   The sentence.
	 * @param bool   $linked Whether the notice points to the page that shows the progress.
	 * @return array{tone:string,text:string,url:string,link:string}
	 */
	private function notice( string $tone, string $text, bool $linked = false ): array {
		$url = $linked ? (string) ( $this->urls['products'] ?? '' ) : '';

		return [
			'tone' => $tone,
			'text' => $text,
			'url'  => $url,
			'link' => '' === $url ? '' : __( 'Открыть «Товары»', 'woodev-plugin-framework' ),
		];
	}

	private function may( string $action, int $product_id ): bool {
		if ( ! current_user_can( 'edit_product', $product_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- The meta capability of the product post type.
			return false;
		}

		return $this->shop_may( $action );
	}

	/**
	 * Tells whether the user has the shop-wide right the action needs: a write to the marketplace needs `manage_woocommerce`, a local change
	 * only the right to edit the product.
	 *
	 * @param string $action Action id.
	 * @return bool
	 */
	private function shop_may( string $action ): bool {
		return null === Site_Products_Bulk::license_phrase( $action ) || current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's own capability.
	}

	/**
	 * Tells whether a variation's button may be offered: the marketplace serves the action and the user may run it.
	 *
	 * @param string $action Action id.
	 * @return bool
	 */
	private function is_allowed_button( string $action ): bool {
		return ! in_array( $action, $this->withheld, true ) && $this->shop_may( $action );
	}

	/**
	 * @return string[] The actions the tab's own AJAX serves: the select's and the buttons'.
	 */
	private function ajax_actions(): array {
		return array_values( array_unique( array_merge( $this->offered(), array_diff( self::BUTTONS, $this->withheld ) ) ) );
	}

	/**
	 * Loads an enabled account of this marketplace.
	 *
	 * @param int $account_id Account id.
	 * @return Account|null
	 */
	private function enabled_account( int $account_id ): ?Account {
		$account = $account_id > 0 ? $this->accounts->get( $account_id ) : null;

		return null !== $account && $account->adapter_id() === $this->names->id() && $account->is_enabled() ? $account : null;
	}

	/**
	 * @return string[] The actions of the group that the marketplace can serve.
	 */
	private function offered(): array {
		return array_values( array_diff( self::ACTIONS, $this->withheld ) );
	}

	/**
	 * @return string The start of every value of this marketplace; two marketplace plugins never share one.
	 */
	private function prefix(): string {
		return 'woodev_' . $this->names->id() . '_bulk_';
	}

	/**
	 * @param int    $account_id Account.
	 * @param string $action     Action id.
	 * @return string The value of the option.
	 */
	private function value( int $account_id, string $action ): string {
		return $this->prefix() . $account_id . '_' . $action;
	}
}
