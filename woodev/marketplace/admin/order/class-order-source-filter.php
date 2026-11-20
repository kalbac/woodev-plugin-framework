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
 * the notice are shared. The status views' own counts follow the choice too, so «Processing (5)» is the number the table shows:
 * the HPOS list asks for them through `woocommerce_shop_order_list_table_order_count`, the posts list through `wp_count_posts`.
 *
 * An HPOS shop on a WooCommerce too old for the filter ({@see Order_Source_Query::supports_storage()}) gets none of it: the list
 * stays the way WooCommerce draws it.
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
	 * Test override of the storage and the WooCommerce version, in place of the real ones.
	 *
	 * @var array{hpos:bool,wc_version:string|null}|null
	 */
	private $environment;

	/**
	 * Status counts under the choice, by the statuses asked for.
	 *
	 * @var array<string,int>
	 */
	private $status_counts = [];

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
		$this->status_counts           = [];

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
	 * Makes a status view's count of the HPOS list the number of orders of that status the choice keeps.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @param int|mixed $count    WooCommerce's count of all orders in the statuses.
	 * @param string[]  $statuses The statuses counted.
	 * @return int
	 */
	public function filter_orders_table_count( $count, $statuses ): int {
		$count = (int) $count;

		if ( $count < 1 || Order_Source_Choice::ALL === $this->choice() ) {
			return $count;
		}

		return $this->count_status( (array) $statuses );
	}

	/**
	 * Keeps the HPOS list from taking itself for an empty shop: its blank-state check counts the orders through the filtered
	 * counts, and a shop whose orders are all the marketplaces' would otherwise lose its list, views included.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @param bool|null $render WooCommerce's answer; null leaves it to WooCommerce.
	 * @return bool|null
	 */
	public function filter_blank_state( $render ) {
		return $this->counts()['all'] > 0 ? false : $render;
	}

	/**
	 * Makes the status views' counts of the posts-table list the numbers of orders the choice keeps.
	 *
	 * Only the list's own call is answered (`readable`, on its screen): WooCommerce's blank-state check, the dashboard and every other
	 * caller of `wp_count_posts()` keep the real counts.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @param \stdClass|mixed $counts Counts by post status.
	 * @param string          $type   Post type.
	 * @param string          $perm   The permission the counts were read for.
	 * @return \stdClass|mixed
	 */
	public function filter_posts_counts( $counts, $type = '', $perm = '' ) {
		if ( self::ORDER_TYPE !== $type || 'readable' !== $perm || ! $counts instanceof \stdClass || ! $this->is_screen( self::LEGACY_SCREEN ) ) {
			return $counts;
		}

		if ( Order_Source_Choice::ALL === $this->choice() ) {
			return $counts;
		}

		$counts = clone $counts;

		foreach ( array_merge( array_keys( wc_get_order_statuses() ), [ 'trash' ] ) as $status ) {
			if ( ! empty( $counts->$status ) ) {
				$counts->$status = $this->count_status( [ $status ] );
			}
		}

		return $counts;
	}

	/**
	 * Adds «Все | Магазин | Маркетплейсы» to the list's views, after the order statuses, and, while marketplace orders are hidden,
	 * the line that says so. The line is one more view, a plain element: WooCommerce's order screens move every `.notice` into a
	 * hidden list, so a notice would never be seen.
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

		if ( Order_Source_Choice::SHOP === $this->choice() && $counts['marketplace'] > 0 ) {
			$views['woodev_source_hidden'] = $this->hidden_line();
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
	 * Forgets everything; for tests, which share one process.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @param bool|null   $hpos       Pretend orders live in the HPOS tables (true) or the posts table (false); the real storage when null.
	 * @param string|null $wc_version The WooCommerce version to pretend; only read with `$hpos`.
	 * @return void
	 */
	public function reset_for_tests( ?bool $hpos = null, ?string $wc_version = null ): void {
		$this->sources       = [];
		$this->hooked        = false;
		$this->choice        = null;
		$this->counts        = null;
		$this->status_counts = [];
		$this->environment   = null === $hpos ? null : [
			'hpos'       => $hpos,
			'wc_version' => $wc_version,
		];
	}

	/**
	 * Hooks the filter once.
	 *
	 * @return void
	 */
	private function add_hooks(): void {
		[ $hpos, $wc_version ] = $this->environment();

		if ( $this->hooked || ! Order_Source_Query::supports_storage( $hpos, $wc_version ) ) {
			return;
		}

		$this->hooked = true;

		// The HPOS list.
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', [ $this, 'filter_orders_table_args' ] );
		add_filter( 'views_' . self::HPOS_SCREEN, [ $this, 'add_views' ] );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $this, 'render_dropdown' ], 10, 2 );

		if ( Order_Source_Query::supports_orders_table_status_counts( $wc_version ) ) {
			add_filter( 'woocommerce_shop_order_list_table_order_count', [ $this, 'filter_orders_table_count' ], 10, 2 );
			add_filter( 'woocommerce_shop_order_list_table_should_render_blank_state', [ $this, 'filter_blank_state' ] );
		}

		// The posts-table list.
		add_action( 'pre_get_posts', [ $this, 'filter_posts_query' ] );
		add_filter( 'views_' . self::LEGACY_SCREEN, [ $this, 'add_views' ] );
		add_filter( 'wp_count_posts', [ $this, 'filter_posts_counts' ], 10, 3 );
		add_action( 'restrict_manage_posts', [ $this, 'render_dropdown' ], 10, 2 );
	}

	/**
	 * Whether orders live in the HPOS tables and the installed WooCommerce version.
	 *
	 * @return array{0:bool,1:string|null}
	 */
	private function environment(): array {
		if ( null !== $this->environment ) {
			return [ $this->environment['hpos'], $this->environment['wc_version'] ];
		}

		return [ \Woodev_Plugin_Compatibility::is_hpos_enabled(), \Woodev_Plugin_Compatibility::get_wc_version() ];
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
			$marketplace = $this->count_orders( $this->constraint( Order_Source_Choice::MARKETPLACES ) );

			$this->counts = [
				'all'         => $all,
				'shop'        => max( 0, $all - $marketplace ),
				'marketplace' => $marketplace,
			];
		}

		return $this->counts;
	}

	/**
	 * The `wc_get_orders()` arguments that select the orders a choice keeps.
	 *
	 * @param string $choice An {@see Order_Source_Choice} value.
	 * @return array<string,mixed>
	 */
	private function constraint( string $choice ): array {
		return $this->query()->matching( $this->environment()[0], $choice );
	}

	/**
	 * Counts the orders in some statuses that the current choice keeps, once per request.
	 *
	 * @param string[] $statuses Statuses, as WooCommerce names them (`wc-processing`, `trash`).
	 * @return int
	 */
	private function count_status( array $statuses ): int {
		$key = implode( ',', $statuses );

		if ( ! isset( $this->status_counts[ $key ] ) ) {
			$this->status_counts[ $key ] = $this->count_orders( array_merge( $this->constraint( $this->choice() ), [ 'status' => $statuses ] ) );
		}

		return $this->status_counts[ $key ];
	}

	/**
	 * Tells whether the current admin screen is a given one.
	 *
	 * @param string $id Screen id.
	 * @return bool
	 */
	private function is_screen( string $id ): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $id === $screen->id;
	}

	/**
	 * The order statuses WooCommerce's «All» view counts: those that show in the admin's all list, which leaves out the checkout draft
	 * (`wc_get_order_statuses()` lists it), trash and auto-draft.
	 *
	 * @return string[]
	 */
	private function all_statuses(): array {
		return array_values( array_intersect( array_keys( wc_get_order_statuses() ), get_post_stati( [ 'show_in_admin_all_list' => true ] ) ) );
	}

	/**
	 * Counts the orders a constraint matches, without loading them. Unless the constraint names statuses these are the ones
	 * WooCommerce's own «All» counts ({@see self::all_statuses()}).
	 *
	 * @param array<string,mixed> $constraint `wc_get_orders()` arguments that narrow the orders.
	 * @return int
	 */
	private function count_orders( array $constraint ): int {
		$result = wc_get_orders(
			array_merge(
				[ 'status' => $this->all_statuses() ],
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
	 * «Заказы маркетплейсов скрыты · показать».
	 *
	 * @return string
	 */
	private function hidden_line(): string {
		return sprintf(
			'<span class="woodev-order-source-hidden">%s</span>',
			sprintf(
				/* translators: %s: the «показать» link. */
				esc_html__( 'Заказы маркетплейсов скрыты · %s', 'woodev-plugin-framework' ),
				'<a href="' . esc_url( $this->url_for( Order_Source_Choice::ALL ) ) . '">' . esc_html__( 'показать', 'woodev-plugin-framework' ) . '</a>'
			)
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
