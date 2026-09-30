<?php
/**
 * Shipping orders — «exports in progress» notice
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Order\Export_Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Export_Queue_Notice' ) ) :

	/**
	 * «Сейчас выгружаются N заказов перевозчику» (#1007): the notice that tells a manager orders are
	 * leaving for a carrier in the background, on the WooCommerce orders list and — through the page's
	 * bootstrap and the same heartbeat key — on the «Заказы доставки» page.
	 *
	 * The count and its Russian plural form are worded HERE, on the server ({@see Export_Queue}): the
	 * page's JavaScript carries no translations (gotcha `russian-source-i18n-plural-n`), so the heartbeat
	 * hands it the finished sentence. The count follows the WordPress heartbeat, which the orders page
	 * already relies on for the order wizard's edit lock — no polling of its own, no REST route.
	 *
	 * Not `final`, so a unit test can override the one screen seam ({@see self::is_orders_list_screen()}) —
	 * `get_current_screen()` is WordPress, which the unit tier does not load.
	 *
	 * @since 2.0.2
	 */
	class Export_Queue_Notice {

		/** @var string the heartbeat payload key: the client sends `true`, the server answers `{count, text}` */
		public const HEARTBEAT_KEY = 'woodev-exports-in-progress';

		/** @var Orders_Registry */
		private Orders_Registry $registry;

		/** @var bool whether the hooks were added */
		private bool $hooked = false;

		/**
		 * @param Orders_Registry $registry the registry whose page capability gates the notice.
		 */
		public function __construct( Orders_Registry $registry ) {
			$this->registry = $registry;
		}

		/**
		 * Adds the heartbeat answer and the orders-list notice, once.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function add_hooks(): void {

			if ( $this->hooked ) {
				return;
			}

			$this->hooked = true;

			add_filter( 'heartbeat_received', [ $this, 'answer_heartbeat' ], 10, 2 );
			add_action( 'admin_notices', [ $this, 'render_list_notice' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_list_script' ] );
		}

		/**
		 * Removes what {@see self::add_hooks()} added. Test-only.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function remove_hooks(): void {
			remove_filter( 'heartbeat_received', [ $this, 'answer_heartbeat' ], 10 );
			remove_action( 'admin_notices', [ $this, 'render_list_notice' ] );
			remove_action( 'admin_enqueue_scripts', [ $this, 'enqueue_list_script' ] );

			$this->hooked = false;
		}

		/**
		 * The state the notice shows now: how many orders are being exported and the sentence for it.
		 *
		 * @since 2.0.2
		 *
		 * @return array{count:int,text:string,heartbeatKey:string}
		 */
		public function state(): array {

			$count = Export_Queue::count_in_progress();

			return [
				'count'        => $count,
				'text'         => Export_Queue::notice_text( $count ),
				'heartbeatKey' => self::HEARTBEAT_KEY,
			];
		}

		/**
		 * The `heartbeat_received` filter: answers a client that asked for the export count.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $response heartbeat response so far.
		 * @param mixed $data     heartbeat request data.
		 * @return mixed
		 */
		public function answer_heartbeat( $response, $data ) {

			if ( ! is_array( $response ) || ! is_array( $data ) || empty( $data[ self::HEARTBEAT_KEY ] ) ) {
				return $response;
			}

			if ( ! current_user_can( $this->registry->get_page_capability() ) ) {
				return $response;
			}

			$state = $this->state();

			$response[ self::HEARTBEAT_KEY ] = [
				'count' => $state['count'],
				'text'  => $state['text'],
			];

			return $response;
		}

		/**
		 * The `admin_notices` action: the notice on the WooCommerce orders list.
		 *
		 * Always printed on that screen and hidden while nothing is being exported, so the heartbeat can
		 * show it the moment the first export is queued — without a reload.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function render_list_notice(): void {

			if ( ! $this->should_show_on_screen() ) {
				return;
			}

			$state = $this->state();

			printf(
				'<div class="notice notice-info woodev-exports-notice"%1$s><p class="woodev-exports-notice__text">%2$s</p></div>',
				$state['count'] > 0 ? '' : ' style="display:none"',
				esc_html( $state['text'] )
			);
		}

		/**
		 * The `admin_enqueue_scripts` action: the few lines that keep the list notice current.
		 *
		 * The only logic is «swap the text the server sent and show or hide the box» — the sentence, the
		 * plural form and the count all arrive finished from {@see self::answer_heartbeat()}.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function enqueue_list_script(): void {

			if ( ! $this->should_show_on_screen() ) {
				return;
			}

			wp_enqueue_script( 'heartbeat' );

			wp_add_inline_script(
				'heartbeat',
				'(function($){var key=' . wp_json_encode( self::HEARTBEAT_KEY ) . ';$(function(){var $n=$(".woodev-exports-notice");if(!$n.length){return;}'
				. '$(document).on("heartbeat-send.woodevExports",function(e,data){data[key]=true;})'
				. '.on("heartbeat-tick.woodevExports",function(e,data){var s=data[key];if(!s){return;}'
				. '$n.find(".woodev-exports-notice__text").text(s.text);$n.toggle(s.count>0);'
				. 'if(s.count>0&&window.wp&&wp.heartbeat){wp.heartbeat.interval("fast");}});});})(jQuery);'
			);
		}

		/**
		 * Whether the notice belongs on the current screen: the WooCommerce orders list, for a manager
		 * who may open the shipping orders page.
		 *
		 * @return bool
		 */
		private function should_show_on_screen(): bool {
			return $this->registry->has_providers()
				&& current_user_can( $this->registry->get_page_capability() )
				&& $this->is_orders_list_screen();
		}

		/**
		 * Whether the current admin screen is the WooCommerce orders LIST — the legacy post list, or the
		 * HPOS list page (the single-order screen shares the HPOS screen id and is told apart by its
		 * `action`).
		 *
		 * A protected seam, for the same reason as {@see Orders_Registry::is_wc_admin_screen()}.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		protected function is_orders_list_screen(): bool {

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			if ( ! $screen instanceof \WP_Screen ) {
				return false;
			}

			if ( 'edit-shop_order' === $screen->id ) {
				return true;
			}

			// The HPOS orders screen id (`wc_get_page_screen_id( 'shop-order' )` when HPOS is on).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which admin screen this is, changes nothing.
			return 'woocommerce_page_wc-orders' === $screen->id && empty( $_GET['action'] );
		}
	}

endif;
