<?php
/**
 * The «Источник» filter of the WooCommerce orders list.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps marketplace orders apart from the shop's own in WooCommerce → Orders, on the HPOS list and on the posts-table list alike.
 *
 * One per request, however many marketplace plugins are active: each hands over its adapter, and the list gets one set of
 * controls — the views «Все | Магазин | Маркетплейсы» with their counts, a dropdown that adds each marketplace by name, and, while
 * marketplace orders are hidden, a line that says so. The manager's choice is kept per user ({@see Order_Source_Choice}); the
 * default is the shop's own orders. There is no setting: an order came from a marketplace when its `created_via` is the one its
 * adapter stamps ({@see \Woodev\Framework\Marketplace\Flows\Order_Meta_Contract::created_via()}).
 *
 * The two lists are different WooCommerce screens, so the query is filtered twice: the HPOS list through
 * `woocommerce_shop_order_list_table_prepare_items_query_args`, the posts list through `pre_get_posts`. The views, the dropdown and
 * the notice are shared.
 *
 * @internal
 *
 * @since 2.1.13
 */
final class Order_Source_Filter {
	/**
	 * Screen id of the posts-table orders list.
	 */
	private const LEGACY_SCREEN = 'edit-shop_order';

	/**
	 * Screen id of the HPOS orders list.
	 */
	private const HPOS_SCREEN = 'woocommerce_page_wc-orders';

	/**
	 * The order type of the list.
	 */
	private const ORDER_TYPE = 'shop_order';

	/**
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Marketplace names by `created_via`.
	 *
	 * @var array<string,string>
	 */
	private $sources = [];

	/**
	 * @var bool
	 */
	private $hooked = false;

	/**
	 * The choice of this request, once resolved.
	 *
	 * @var string|null
	 */
	private $choice;

	/**
	 * Order counts of the views, once read.
	 *
	 * @var array{all:int,shop:int,marketplace:int}|null
	 */
	private $counts;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Adds a marketplace to the filter, in wp-admin only: the orders list lives there.
	 *
	 * @since 2.1.13
	 *
	 * @param Marketplace_Adapter $adapter The plugin's adapter.
	 * @return void
	 */
	public static function register( Marketplace_Adapter $adapter ): void {
		if ( ! is_admin() ) {
			return;
		}

		self::instance()->add_source( $adapter->order_meta()->created_via(), $adapter->settings_descriptor()->marketplace_name() );
	}

	/**
	 * Adds a marketplace's orders to the filter and hooks the filter the first time.
	 *
	 * @since 2.1.13
	 *
	 * @param string $created_via What the marketplace's orders are created via; a marketplace without one has no orders to tell apart.
	 * @param string $name        The marketplace's name, as the dropdown shows it.
	 * @return void
	 */
	public function add_source( string $created_via, string $name ): void {
		if ( '' === $created_via ) {
			return;
		}

		$this->sources[ $created_via ] = $name;
		$this->choice                  = null;
		$this->counts                  = null;

		$this->add_hooks();
	}

	/**
	 * Applies the choice to the HPOS orders list.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $args `wc_get_orders()` arguments of the list.
	 * @return array<string,mixed>
	 */
	public function filter_orders_table_args( $args ): array {
		return $this->query()->for_orders_table( (array) $args, $this->choice() );
	}

	/**
	 * Applies the choice to the posts-table orders list's main query.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @param \WP_Query $query The query.
	 * @return void
	 */
	public function filter_posts_query( $query ): void {
		global $pagenow;

		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || self::ORDER_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );

		$query->set( 'meta_query', $this->query()->for_posts( is_array( $meta_query ) ? $meta_query : [], $this->choice() ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one key; the same filter the list's other meta queries use.
	}

	/**
	 * Adds «Все | Магазин | Маркетплейсы» to the list's views, after the order statuses.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,string> $views The list's views.
	 * @return array<string,string>
	 */
	public function add_views( $views ): array {
		$views  = (array) $views;
		$counts = $this->counts();
		$items  = [
			Order_Source_Choice::ALL          => [ __( 'Все', 'woodev-plugin-framework' ), $counts['all'] ],
			Order_Source_Choice::SHOP         => [ __( 'Магазин', 'woodev-plugin-framework' ), $counts['shop'] ],
			Order_Source_Choice::MARKETPLACES => [ __( 'Маркетплейсы', 'woodev-plugin-framework' ), $counts['marketplace'] ],
		];
		$label  = '<span class="woodev-order-source-label">' . esc_html__( 'Источник:', 'woodev-plugin-framework' ) . '</span> ';

		foreach ( $items as $value => [ $text, $count ] ) {
			$views[ 'woodev_source_' . $value ] = $label . $this->view_link( $value, $text, $count );
			$label                              = '';
		}

		return $views;
	}

	/**
	 * Prints the dropdown next to the list's other filters.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @param string $order_type The list's order or post type.
	 * @param string $which      `top` or `bottom`.
	 * @return void
	 */
	public function render_dropdown( $order_type, $which = 'top' ): void {
		if ( self::ORDER_TYPE !== $order_type || 'top' !== $which ) {
			return;
		}

		$current = $this->choice();

		printf( '<select name="%s" id="woodev-order-source">', esc_attr( Order_Source_Choice::QUERY_ARG ) );

		foreach ( $this->dropdown_options() as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $value, $current, false ), esc_html( $label ) );
		}

		echo '</select>';
	}

	/**
	 * Says, on the orders list, that marketplace orders are hidden and how to show them.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @return void
	 */
	public function render_hidden_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, [ self::LEGACY_SCREEN, self::HPOS_SCREEN ], true ) ) {
			return;
		}

		if ( Order_Source_Choice::SHOP !== $this->choice() || $this->counts()['marketplace'] < 1 ) {
			return;
		}

		printf(
			'<div class="notice notice-info inline"><p>%s</p></div>',
			sprintf(
				/* translators: %s: the «показать» link. */
				esc_html__( 'Заказы маркетплейсов скрыты · %s', 'woodev-plugin-framework' ),
				'<a href="' . esc_url( $this->url_for( Order_Source_Choice::ALL ) ) . '">' . esc_html__( 'показать', 'woodev-plugin-framework' ) . '</a>'
			)
		);
	}

	/**
	 * Forgets everything; for tests, which share one process.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @return void
	 */
	public function reset_for_tests(): void {
		$this->sources = [];
		$this->hooked  = false;
		$this->choice  = null;
		$this->counts  = null;
	}

	/**
	 * Hooks the filter once.
	 *
	 * @return void
	 */
	private function add_hooks(): void {
		if ( $this->hooked ) {
			return;
		}

		$this->hooked = true;

		// The HPOS list.
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', [ $this, 'filter_orders_table_args' ] );
		add_filter( 'views_' . self::HPOS_SCREEN, [ $this, 'add_views' ] );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $this, 'render_dropdown' ], 10, 2 );

		// The posts-table list.
		add_action( 'pre_get_posts', [ $this, 'filter_posts_query' ] );
		add_filter( 'views_' . self::LEGACY_SCREEN, [ $this, 'add_views' ] );
		add_action( 'restrict_manage_posts', [ $this, 'render_dropdown' ], 10, 2 );

		add_action( 'admin_notices', [ $this, 'render_hidden_notice' ] );
	}

	/**
	 * The choice that applies to this request.
	 *
	 * @return string
	 */
	private function choice(): string {
		if ( null === $this->choice ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter and the user's own display preference.
			$requested = isset( $_GET[ Order_Source_Choice::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ Order_Source_Choice::QUERY_ARG ] ) ) : '';

			$this->choice = ( new Order_Source_Choice( array_keys( $this->sources ) ) )->resolve( get_current_user_id(), $requested );
		}

		return $this->choice;
	}

	/**
	 * @return Order_Source_Query
	 */
	private function query(): Order_Source_Query {
		return new Order_Source_Query( array_keys( $this->sources ) );
	}

	/**
	 * Counts the orders of each view with two `COUNT` queries: every order, and the marketplaces' ones; the shop's are the rest.
	 *
	 * @return array{all:int,shop:int,marketplace:int}
	 */
	private function counts(): array {
		if ( null === $this->counts ) {
			$all         = $this->count_orders( [] );
			$marketplace = $this->count_orders( $this->query()->marketplace_orders( \Woodev_Plugin_Compatibility::is_hpos_enabled() ) );

			$this->counts = [
				'all'         => $all,
				'shop'        => max( 0, $all - $marketplace ),
				'marketplace' => $marketplace,
			];
		}

		return $this->counts;
	}

	/**
	 * Counts the orders a constraint matches, in every status the list's «Все» view shows, without loading them.
	 *
	 * @param array<string,mixed> $constraint `wc_get_orders()` arguments that narrow the orders.
	 * @return int
	 */
	private function count_orders( array $constraint ): int {
		$result = wc_get_orders(
			array_merge(
				$constraint,
				[
					'type'     => self::ORDER_TYPE,
					'limit'    => 1,
					'paginate' => true,
					'return'   => 'ids',
				]
			)
		);

		return (int) ( $result->total ?? 0 );
	}

	/**
	 * The dropdown's options: every source the views offer, then each marketplace by name.
	 *
	 * @return array<string,string> Label by choice.
	 */
	private function dropdown_options(): array {
		return array_merge(
			[
				Order_Source_Choice::ALL          => __( 'Все источники', 'woodev-plugin-framework' ),
				Order_Source_Choice::SHOP         => __( 'Только магазин', 'woodev-plugin-framework' ),
				Order_Source_Choice::MARKETPLACES => __( 'Все маркетплейсы', 'woodev-plugin-framework' ),
			],
			$this->sources
		);
	}

	/**
	 * One link of the views.
	 *
	 * @param string $value Choice.
	 * @param string $text  Label.
	 * @param int    $count Orders in the view.
	 * @return string
	 */
	private function view_link( string $value, string $text, int $count ): string {
		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $this->url_for( $value ) ),
			$this->choice() === $value ? ' class="current" aria-current="page"' : '',
			esc_html( $text ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * The list's own URL with a choice, the status and the search kept, the page reset.
	 *
	 * @param string $value Choice.
	 * @return string
	 */
	private function url_for( string $value ): string {
		return add_query_arg( Order_Source_Choice::QUERY_ARG, $value, remove_query_arg( 'paged' ) );
	}
}
