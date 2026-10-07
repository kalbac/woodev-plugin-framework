<?php
/**
 * Woodev Status Sync Tool
 *
 * The «Обновить статусы сейчас» button of a carrier's «Выгрузка заказов» section: runs the carrier's own
 * delivery-status refresh on demand.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Settings;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Order\Delivery_Sync_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Status_Sync_Tool' ) ) :

	/**
	 * Builds the on-demand status refresh {@see Shipping_Tool}.
	 *
	 * The seam is the carrier's CRON HOOK, declared on its {@see Orders_Provider} (`cron_hook`): the action
	 * WordPress cron fires to refresh delivery statuses, with the carrier's callback attached to it. Firing the
	 * same action by hand IS the carrier's own refresh — the framework knows nothing about how it polls. A carrier
	 * with no cron hook (webhook-only) gets no button; one whose hook has no callback gets a disabled one.
	 *
	 * @since 2.0.2
	 */
	final class Status_Sync_Tool {

		/** @var string the tool id inside the section */
		public const TOOL_ID = 'sync_delivery_statuses';

		/**
		 * Builds the tool for a plugin's providers.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider[] $providers the carrier plugin's providers.
		 * @param callable|null     $logger    optional error logger — `fn( string $message ): void`.
		 *
		 * @return Shipping_Tool|null null when no provider declared a cron hook (nothing to refresh by hand).
		 */
		public static function create( array $providers, ?callable $logger = null ): ?Shipping_Tool {

			$hooks     = [];
			$carrier   = [];
			$any_hook  = false;

			foreach ( $providers as $provider ) {

				$hook = $provider->get_cron_hook();

				if ( null === $hook || '' === $hook ) {
					continue;
				}

				$any_hook             = true;
				$hooks[ $hook ]       = $hook;
				$carrier[ $hook ]     = $provider->get_id();
			}

			if ( ! $any_hook ) {
				return null;
			}

			$runnable = array_filter(
				$hooks,
				static function ( string $hook ): bool {
					return (bool) has_action( $hook );
				}
			);

			return Shipping_Tool::create(
				self::TOOL_ID,
				__( 'Статусы доставки', 'woodev-plugin-framework' ),
				__( 'Статусы заказов обновляются сами по расписанию. Нажмите, чтобы получить их у перевозчика прямо сейчас.', 'woodev-plugin-framework' ),
				__( 'Обновить статусы сейчас', 'woodev-plugin-framework' ),
				static function () use ( $runnable, $carrier, $logger ): Tool_Result {
					return self::run( $runnable, $carrier, $logger );
				},
				[] === $runnable,
				[] === $runnable ? __( 'Плагин не поддерживает обновление статусов вручную.', 'woodev-plugin-framework' ) : ''
			);
		}

		/**
		 * Fires every runnable hook, one carrier at a time; one failing carrier does not stop the rest.
		 *
		 * @param array<string,string> $hooks   hook => hook, the ones with a callback attached.
		 * @param array<string,string> $carrier hook => the provider id it refreshes.
		 * @param callable|null        $logger  optional error logger.
		 *
		 * @return Tool_Result
		 */
		private static function run( array $hooks, array $carrier, ?callable $logger ): Tool_Result {

			$failed = false;
			$before = self::last_updated( $hooks, $carrier );

			foreach ( $hooks as $hook ) {

				try {
					do_action( $hook );
				} catch ( \Throwable $error ) {
					$failed = true;

					if ( null !== $logger ) {
						$logger( sprintf( 'manual delivery-status refresh (%1$s) threw %2$s: %3$s', $hook, get_class( $error ), \Woodev_API_Base::redact_secret_log_text( $error->getMessage() ) ) );
					}
				}
			}

			if ( $failed ) {
				return Tool_Result::failure( __( 'Не удалось обновить статусы. Подробности записаны в лог.', 'woodev-plugin-framework' ) );
			}

			// The refresh time is the carrier's to record ({@see Delivery_Sync_Status}); it is quoted only when this run moved it.
			$updated = self::last_updated( $hooks, $carrier );

			if ( $updated > $before ) {
				return Tool_Result::success(
					sprintf(
						/* translators: %s: date and time of the last refresh */
						__( 'Статусы обновлены. Последнее обновление: %s.', 'woodev-plugin-framework' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated )
					)
				);
			}

			return Tool_Result::success( __( 'Статусы обновлены.', 'woodev-plugin-framework' ) );
		}

		/**
		 * The latest recorded refresh time over the carriers behind the given hooks; 0 when none ever refreshed.
		 *
		 * @param array<string,string> $hooks   hook => hook.
		 * @param array<string,string> $carrier hook => the provider id it refreshes.
		 *
		 * @return int
		 */
		private static function last_updated( array $hooks, array $carrier ): int {

			$latest = 0;

			foreach ( $carrier as $hook => $provider_id ) {
				if ( isset( $hooks[ $hook ] ) ) {
					$latest = max( $latest, (int) Delivery_Sync_Status::get_last_updated( $provider_id ) );
				}
			}

			return $latest;
		}
	}

endif;
