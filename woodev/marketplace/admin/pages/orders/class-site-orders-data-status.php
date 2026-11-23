<?php
/**
 * The «Статус данных» block of the orders table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Marketplace\Admin\Shared\Date_Text;
use Woodev\Framework\Marketplace\Jobs\Posting_Poll_Times;

defined( 'ABSPATH' ) || exit;

/**
 * When the site last polled the marketplace for the account's orders and when it polls again — the shape of the «Статус данных»
 * block of «Заказы доставки». «Последний опрос» is the last poll ATTEMPT that ended without an error ({@see Posting_Poll_Times});
 * it does not certify that every change was read — a marketplace handler may stop within its time budget and go on in the next
 * poll, and the void `catch_up()` does not say so — and the words promise no more than that. «Следующий опрос» is the next run of
 * the account's recurring poll in Action Scheduler. Nothing here calls the marketplace.
 *
 * @since 2.1.13
 */
final class Site_Orders_Data_Status {
	/**
	 * @var Posting_Poll_Times
	 */
	private $times;

	/**
	 * `function ( int $account_id ): int|bool` — the next run of the account's poll: a Unix time, `true` while it runs, `false`
	 * when none is scheduled (Action Scheduler's `as_next_scheduled_action()` answer).
	 *
	 * @var callable
	 */
	private $next_run;

	/**
	 * Creates the block builder.
	 *
	 * @since 2.1.13
	 *
	 * @param Posting_Poll_Times $times    Finished polls.
	 * @param callable           $next_run `function ( int $account_id ): int|bool`, see {@see self::$next_run}.
	 */
	public function __construct( Posting_Poll_Times $times, callable $next_run ) {
		$this->times    = $times;
		$this->next_run = $next_run;
	}

	/**
	 * The builder over the plugin's poll job in Action Scheduler.
	 *
	 * @since 2.1.13
	 *
	 * @param Posting_Poll_Times $times Finished polls.
	 * @param string             $hook  Hook of the poll job (`Posting_Jobs::poll_hook()`).
	 * @param string             $group Action Scheduler group of the plugin.
	 * @return self
	 */
	public static function over_scheduler( Posting_Poll_Times $times, string $hook, string $group ): self {
		return new self(
			$times,
			static function ( int $account_id ) use ( $hook, $group ) {
				return function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( $hook, [ $account_id ], $group ) : false;
			}
		);
	}

	/**
	 * The block of one account, in the records engine's `data_status` shape.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return array{items:array<int,array{label:string,value:string}>,title:string}
	 */
	public function for_account( int $account_id ): array {
		$last = $this->times->last( $account_id );
		$next = ( $this->next_run )( $account_id );

		return [
			'items' => [
				[
					'label' => __( 'Последний опрос', 'woodev-plugin-framework' ),
					'value' => null === $last ? __( 'Ни разу', 'woodev-plugin-framework' ) : self::date( $last ),
				],
				[
					'label' => __( 'Следующий опрос', 'woodev-plugin-framework' ),
					'value' => self::next_value( $next ),
				],
			],
			'title' => __( 'Когда сайт в последний раз опрашивал маркетплейс о заказах аккаунта и когда опросит снова. За один опрос сайт может прочитать не все изменения — остальное он дочитает в следующих.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * @param int|bool $next Action Scheduler's answer.
	 * @return string
	 */
	private static function next_value( $next ): string {
		if ( true === $next ) {
			return __( 'Сейчас', 'woodev-plugin-framework' );
		}

		return is_int( $next ) && $next > 0 ? self::date( $next ) : __( 'Не по расписанию', 'woodev-plugin-framework' );
	}

	/**
	 * @param int $time Unix time.
	 * @return string The site's local time.
	 */
	private static function date( int $time ): string {
		return Date_Text::datetime( $time );
	}
}
