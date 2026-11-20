<?php
/**
 * The «Статус данных» block of the orders table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Marketplace\Jobs\Posting_Poll_Times;

defined( 'ABSPATH' ) || exit;

/**
 * When the account's orders were last read from the marketplace and when they will be read again — the two columns «Заказы
 * доставки» shows in its own «Статус данных» block. «Обновлено» is the last poll that read the postings to the end
 * ({@see Posting_Poll_Times}); «Обновится» is the next run of the account's recurring poll in Action Scheduler. Nothing here calls
 * the marketplace.
 *
 * @since 2.1.13
 */
final class Site_Orders_Data_Status {
	/**
	 * Date format of both values: the one the delivery block shows in full.
	 */
	private const DATE_FORMAT = 'j M Y H:i';

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
					'label' => __( 'Обновлено', 'woodev-plugin-framework' ),
					'value' => null === $last ? __( 'Ни разу', 'woodev-plugin-framework' ) : self::date( $last ),
				],
				[
					'label' => __( 'Обновится', 'woodev-plugin-framework' ),
					'value' => self::next_value( $next ),
				],
			],
			'title' => __( 'Когда заказы аккаунта в последний раз проверялись на маркетплейсе и когда проверятся снова.', 'woodev-plugin-framework' ),
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
		return (string) wp_date( self::DATE_FORMAT, $time );
	}
}
