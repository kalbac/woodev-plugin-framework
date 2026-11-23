<?php
/**
 * REST route of the «Аналитика» tab of «Заказы маркетплейсов».
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Admin\Records\Records_Error;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Controller;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Groups;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Sets;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Framework\Shipping\Admin\Orders\Analytics_Aggregator;

defined( 'ABSPATH' ) || exit;

/**
 * `GET woodev/v1/admin/marketplace/{plugin id}/orders/analytics` — the figures of the tab, for one marketplace plugin.
 *
 * A read of financial totals, so it takes the capability of the plugin's «Заказы» page (403 otherwise; the cookie nonce of the
 * admin's `apiFetch` proves the request is the user's own). Its answer is kept for {@see self::CACHE_SECONDS} per request
 * (`refresh=1` reads again), so a tab opened ten times reads the tables once.
 *
 * | param | rule |
 * |---|---|
 * | `after`/`before` | required `Y-m-d`, `after <= before`, at most {@see self::MAX_RANGE_DAYS} days |
 * | `compare_after`/`compare_before` | both or neither, same rule |
 * | `interval` | `day`, `week`, `month`, `auto` (default) |
 * | `account` | `all` (default) or the id of an enabled account of the marketplace |
 * | `refresh` | true: skip the cache |
 *
 * @since 2.1.13
 */
final class Orders_Analytics_Controller {
	/**
	 * Longest range a request may ask for, in days.
	 */
	public const MAX_RANGE_DAYS = 800;

	/**
	 * Lifetime of a kept answer, in seconds.
	 */
	public const CACHE_SECONDS = 300;

	/**
	 * Version of the answer's shape and SQL: part of the cache key, raised when either changes.
	 */
	public const SCHEMA_VERSION = 2;

	/**
	 * Prefix of the kept answers.
	 */
	private const CACHE_PREFIX = 'woodev_mp_an_';

	/**
	 * @var string
	 */
	private $owner;

	/**
	 * @var string
	 */
	private $capability;

	/**
	 * Builds the report for the request's accounts: `function ( array<int,string> $accounts ): Orders_Analytics_Report`.
	 *
	 * @var callable
	 */
	private $report;

	/**
	 * Enabled accounts of the marketplace: `function (): array<int,string>`, labels by id.
	 *
	 * @var callable
	 */
	private $accounts;

	/**
	 * Creates the controller.
	 *
	 * @since 2.1.13
	 *
	 * @param string   $owner      Plugin id.
	 * @param string   $capability Capability of the plugin's «Заказы» page.
	 * @param callable $accounts   `function (): array<int,string>`, the enabled accounts' labels by id.
	 * @param callable $report     `function ( array<int,string> $accounts ): Orders_Analytics_Report`.
	 */
	public function __construct( string $owner, string $capability, callable $accounts, callable $report ) {
		$this->owner      = $owner;
		$this->capability = $capability;
		$this->accounts   = $accounts;
		$this->report     = $report;
	}

	/**
	 * Builds the controller of a plugin.
	 *
	 * @since 2.1.13
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @return self|null Null when the plugin has no «Заказы» page or its id cannot be a route segment.
	 */
	public static function for_plugin( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): ?self {
		$menu = $plugin->get_marketplace_admin_menu();
		$page = null === $menu ? null : $menu->get_page( Site_Orders_Module::PAGE );

		if ( null === $menu || null === $page || 1 !== preg_match( Mappings_Controller::OWNER_PATTERN, $plugin->get_id() ) ) {
			return null;
		}

		$adapter = $plugin->get_marketplace_adapter();
		$table   = ( new Schema( $adapter->id() ) )->table_name( 'postings', $db->prefix );

		return new self(
			$plugin->get_id(),
			$page->get_capability( $menu->get_capability() ),
			static function () use ( $accounts, $adapter ): array {
				$labels = [];

				foreach ( $accounts->all( $adapter->id(), true ) as $account ) {
					$labels[ $account->id() ] = '' === $account->name() ? '#' . $account->id() : $account->name();
				}

				return $labels;
			},
			static function ( array $labels ) use ( $db, $table, $adapter ): Orders_Analytics_Report {
				$groups = $adapter instanceof Posting_Status_Groups ? $adapter : null;

				return new Orders_Analytics_Report(
					new Orders_Analytics_Query( $db, $table, Site_Orders_Storage::current(), Posting_Status_Sets::rejected( $groups ) ),
					$labels,
					$adapter instanceof Posting_Status_Names ? $adapter : null,
					$adapter->order_meta()->created_via(),
					static function ( int $order_id ): string {
						return current_user_can( 'edit_shop_order', $order_id ) ? \Woodev_Order_Compatibility::get_edit_order_url( $order_id ) : '';
					},
					static function ( int $product_id ): string {
						return current_user_can( 'edit_post', $product_id ) ? admin_url( 'post.php?post=' . $product_id . '&action=edit' ) : '';
					},
					$groups
				);
			}
		);
	}

	/**
	 * The words of the tab. PHP owns the copy; the tab only places it.
	 *
	 * @since 2.1.13
	 *
	 * @return array<string,string>
	 */
	public static function messages(): array {
		return [
			'intro'           => __( 'На этой странице отображаются заказы маркетплейса за выбранный период: выручка и отмены по аккаунтам, проданные товары, статусы отправлений и отправления, которые ждут упаковки или отгрузки.', 'woodev-plugin-framework' ),
			'account'         => __( 'Аккаунт', 'woodev-plugin-framework' ),
			'allAccounts'     => __( 'Все аккаунты', 'woodev-plugin-framework' ),
			'refresh'         => __( 'Обновить', 'woodev-plugin-framework' ),
			'retry'           => __( 'Повторить', 'woodev-plugin-framework' ),
			'loadFailed'      => __( 'Не удалось загрузить аналитику.', 'woodev-plugin-framework' ),
			'unavailable'     => __( 'Аналитика недоступна: эта версия WooCommerce не предоставляет нужные компоненты.', 'woodev-plugin-framework' ),
			'empty'           => __( 'За выбранный период заказов с маркетплейса нет. Выберите период шире.', 'woodev-plugin-framework' ),
			'previous'        => __( 'Предыдущий период:', 'woodev-plugin-framework' ),
			'orders'          => __( 'Заказы', 'woodev-plugin-framework' ),
			'revenue'         => __( 'Выручка', 'woodev-plugin-framework' ),
			'cancelShare'     => __( 'Отменено', 'woodev-plugin-framework' ),
			'avgOrder'        => __( 'Средний чек', 'woodev-plugin-framework' ),
			'unitsPerOrder'   => __( 'Товаров в заказе', 'woodev-plugin-framework' ),
			'share'           => __( 'Доля в выручке магазина', 'woodev-plugin-framework' ),
			/* translators: %s: how many orders were cancelled. */
			'cancelledNote'   => __( 'отменено заказов: %s', 'woodev-plugin-framework' ),
			/* translators: %s: the shop's revenue in the period. */
			'shareNote'       => __( 'выручка магазина: %s', 'woodev-plugin-framework' ),
			'chartOrders'     => __( 'Заказы по аккаунтам', 'woodev-plugin-framework' ),
			'chartRevenue'    => __( 'Выручка по аккаунтам', 'woodev-plugin-framework' ),
			'chartItems'      => __( 'Аккаунты', 'woodev-plugin-framework' ),
			'chartEmpty'      => __( 'За этот период данных нет.', 'woodev-plugin-framework' ),
			'byAccount'       => __( 'По аккаунтам', 'woodev-plugin-framework' ),
			'cancelled'       => __( 'Отменено', 'woodev-plugin-framework' ),
			'statuses'        => __( 'Отправления по статусам', 'woodev-plugin-framework' ),
			'statusesHint'    => __( 'Все отправления по их статусу на маркетплейсе сейчас, завершённые тоже. Число открывает список заказов с этим статусом.', 'woodev-plugin-framework' ),
			'status'          => __( 'Статус на маркетплейсе', 'woodev-plugin-framework' ),
			'count'           => __( 'Отправлений', 'woodev-plugin-framework' ),
			'total'           => __( 'Всего', 'woodev-plugin-framework' ),
			'statusesEmpty'   => __( 'Отправлений пока нет.', 'woodev-plugin-framework' ),
			'stuck'           => __( 'Зависшие отправления', 'woodev-plugin-framework' ),
			'stuckEmpty'      => __( 'Зависших отправлений нет.', 'woodev-plugin-framework' ),
			'order'           => __( 'Заказ', 'woodev-plugin-framework' ),
			'posting'         => __( 'Отправление', 'woodev-plugin-framework' ),
			'waiting'         => __( 'Ждёт, ч', 'woodev-plugin-framework' ),
			'products'        => __( 'Топ товаров', 'woodev-plugin-framework' ),
			'product'         => __( 'Товар', 'woodev-plugin-framework' ),
			'quantity'        => __( 'Продано, шт.', 'woodev-plugin-framework' ),
			'productsEmpty'   => __( 'За этот период товары не продавались.', 'woodev-plugin-framework' ),
			'unlinked'        => __( 'Продано, но не связано с сайтом', 'woodev-plugin-framework' ),
			'unlinkedHint'    => __( 'Позиции заказов маркетплейса, для которых не нашёлся товар магазина. Свяжите их на странице «Товары», чтобы остатки и цены синхронизировались.', 'woodev-plugin-framework' ),
			'unlinkedName'    => __( 'Название на маркетплейсе', 'woodev-plugin-framework' ),
			'unlinkedEmpty'   => __( 'Все проданные позиции связаны с товарами магазина.', 'woodev-plugin-framework' ),
			'footnote'        => __( 'Отменённые — отменённые заказы и отправления, которые маркетплейс отменил или не принял; они входят в число заказов. Выручка — сумма заказов без отменённых и возвращённых; средний чек и товары в заказе считаются по ним же.', 'woodev-plugin-framework' ),
			'footnoteOrders'  => __( 'Отменённые — заказы со статусом «Отменён»; они входят в число заказов. Выручка — сумма заказов без отменённых и возвращённых; средний чек и товары в заказе считаются по ним же.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Registers the route.
	 *
	 * @since 2.1.13
	 *
	 * @internal
	 * @return void
	 */
	public function register_routes(): void {
		$date = [
			'type'              => 'string',
			'validate_callback' => [ self::class, 'validate_date' ],
		];

		register_rest_route(
			\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
			'/admin/marketplace/' . $this->owner . '/orders/analytics',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_report' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'after'          => $date + [ 'required' => true ],
						'before'         => $date + [ 'required' => true ],
						'compare_after'  => $date,
						'compare_before' => $date,
						'interval'       => [
							'type'    => 'string',
							'enum'    => [ 'day', 'week', 'month', 'auto' ],
							'default' => 'auto',
						],
						'account'        => [
							'type'    => 'string',
							'default' => 'all',
						],
						'refresh'        => [
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				],
			]
		);
	}

	/**
	 * Gate of the route.
	 *
	 * @since 2.1.13
	 *
	 * @return true|\WP_Error
	 */
	public function check_permission() {
		return current_user_can( $this->capability ) ? true : Records_Error::forbidden();
	}

	/**
	 * Validates a `Y-m-d` argument; an empty optional value passes.
	 *
	 * @since 2.1.13
	 *
	 * @param mixed $value The value.
	 * @return bool
	 */
	public static function validate_date( $value ): bool {
		if ( '' === $value || null === $value ) {
			return true;
		}

		return is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * The stuck threshold of the plugin, in hours: {@see Orders_Analytics_Report::STUCK_HOURS} unless a filter says otherwise.
	 *
	 * @since 2.1.13
	 *
	 * @return int At least 1.
	 */
	public function stuck_hours(): int {
		/**
		 * Filters how many hours a posting may wait for packing or shipping before the «Аналитика» tab counts it as stuck.
		 *
		 * @since 2.1.13
		 *
		 * @param int    $hours     Hours since the marketplace took the posting into processing (the shop first saw it, when the adapter does not say); 24 by default.
		 * @param string $plugin_id The marketplace plugin.
		 */
		$hours = apply_filters( 'woodev_marketplace_stuck_posting_hours', Orders_Analytics_Report::STUCK_HOURS, $this->owner );

		return is_numeric( $hours ) && (int) $hours >= 1 ? (int) $hours : Orders_Analytics_Report::STUCK_HOURS;
	}

	/**
	 * Answers the request.
	 *
	 * @since 2.1.13
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_report( $request ) {
		$accounts = (array) call_user_func( $this->accounts );
		$params   = $this->read_params( $request, $accounts );

		if ( $params instanceof \WP_Error ) {
			return $params;
		}

		$key = $this->cache_key( $params, $accounts );

		if ( ! $params['refresh'] ) {
			$kept = get_transient( $key );

			if ( is_array( $kept ) ) {
				return new \WP_REST_Response( $kept + [ 'cached' => true ] );
			}
		}

		try {
			$report = call_user_func( $this->report, $accounts );
			$answer = $report->build( $params ) + [
				'currency'     => get_woocommerce_currency(),
				'generated_at' => gmdate( 'c', $params['now'] ),
			];
		} catch ( \RuntimeException $e ) {
			error_log( '[woodev] Marketplace analytics query failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- For the server log; the client gets a generic message.

			return new \WP_Error( 'woodev_marketplace_analytics_failed', __( 'Не удалось собрать аналитику. Попробуйте позже.', 'woodev-plugin-framework' ), [ 'status' => 500 ] );
		}

		if ( is_array( $answer['stuck'] ) ) {
			$answer['stuck']['hint'] = self::stuck_hint( $params['stuck_hours'] );
			$answer['stuck']['more'] = self::stuck_more( count( $answer['stuck']['items'] ), $answer['stuck']['count'] );
		}

		set_transient( $key, $answer, self::CACHE_SECONDS );

		return new \WP_REST_Response( $answer + [ 'cached' => false ] );
	}

	/**
	 * The sentence under «Зависшие отправления».
	 *
	 * @since 2.1.13
	 *
	 * @param int $hours The threshold.
	 * @return string
	 */
	public static function stuck_hint( int $hours ): string {
		return sprintf(
			/* translators: %d: hours. */
			_n(
				'Отправления, которые ждут упаковки или отгрузки дольше %d часа с момента, когда они поступили в обработку.',
				'Отправления, которые ждут упаковки или отгрузки дольше %d часов с момента, когда они поступили в обработку.',
				$hours,
				'woodev-plugin-framework'
			),
			$hours
		);
	}

	/**
	 * The line under a stuck list that does not show every stuck posting; empty when it does.
	 *
	 * @since 2.1.13
	 *
	 * @param int $shown How many are listed.
	 * @param int $count How many are stuck.
	 * @return string
	 */
	public static function stuck_more( int $shown, int $count ): string {
		if ( $shown >= $count ) {
			return '';
		}

		/* translators: 1: postings listed, 2: postings stuck. */
		return sprintf( __( 'Показаны самые старые: %1$d из %2$d.', 'woodev-plugin-framework' ), $shown, $count );
	}

	/**
	 * The name of the kept answer: everything the answer depends on. «Now» is left out, so an answer lives its whole lifetime; the
	 * user is in, because the order and product links depend on what the user may edit.
	 *
	 * @param array<string,mixed> $params   The validated request.
	 * @param array<int,string>   $accounts Enabled accounts.
	 * @return string
	 */
	private function cache_key( array $params, array $accounts ): string {
		$request = $params;

		unset( $request['timezone'], $request['now'], $request['refresh'] );

		return self::CACHE_PREFIX . md5(
			(string) wp_json_encode(
				[
					self::SCHEMA_VERSION,
					$this->owner,
					get_current_user_id(),
					$accounts,
					$request,
					$params['timezone']->getName(),
					\Woodev_Plugin_Compatibility::is_hpos_enabled(),
				]
			)
		);
	}

	/**
	 * Reads the request into what {@see Orders_Analytics_Report::build()} takes, or answers a 400.
	 *
	 * @param \WP_REST_Request  $request  Request.
	 * @param array<int,string> $accounts Enabled accounts.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function read_params( $request, array $accounts ) {
		$account = (string) $request->get_param( 'account' );
		$params  = [
			'after'          => (string) $request->get_param( 'after' ),
			'before'         => (string) $request->get_param( 'before' ),
			'compare_after'  => (string) $request->get_param( 'compare_after' ),
			'compare_before' => (string) $request->get_param( 'compare_before' ),
			'interval'       => (string) ( $request->get_param( 'interval' ) ?: 'auto' ),
			'account'        => '' === $account || 'all' === $account ? null : (int) $account,
			'refresh'        => (bool) $request->get_param( 'refresh' ),
			'week_start'     => (int) get_option( 'start_of_week', 1 ),
			'timezone'       => wp_timezone(),
			'now'            => time(),
			'stuck_hours'    => $this->stuck_hours(),
		];

		if ( null !== $params['account'] && ( ! ctype_digit( $account ) || ! isset( $accounts[ $params['account'] ] ) ) ) {
			return self::bad_request( 'woodev_marketplace_analytics_unknown_account', __( 'Такого аккаунта нет.', 'woodev-plugin-framework' ) );
		}

		$error = self::range_error( $params['after'], $params['before'] );

		if ( null === $error && ( '' !== $params['compare_after'] || '' !== $params['compare_before'] ) ) {
			$error = self::range_error( $params['compare_after'], $params['compare_before'] );
		}

		return $error ?? $params;
	}

	/**
	 * A 400 for a missing, reversed or too wide range; null when it is usable.
	 *
	 * @param string $after  First day.
	 * @param string $before Last day.
	 * @return \WP_Error|null
	 */
	private static function range_error( string $after, string $before ): ?\WP_Error {
		if ( '' === $after || '' === $before || $after > $before ) {
			return self::bad_request( 'woodev_marketplace_analytics_bad_range', __( 'Укажите период: начало не может быть позже конца.', 'woodev-plugin-framework' ) );
		}

		if ( Analytics_Aggregator::days_in_range( $after, $before ) > self::MAX_RANGE_DAYS ) {
			return self::bad_request( 'woodev_marketplace_analytics_range_too_wide', __( 'Период слишком длинный — выберите не больше 800 дней.', 'woodev-plugin-framework' ) );
		}

		return null;
	}

	/**
	 * @param string $code    Error code.
	 * @param string $message Merchant message.
	 * @return \WP_Error
	 */
	private static function bad_request( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => 400 ] );
	}
}
