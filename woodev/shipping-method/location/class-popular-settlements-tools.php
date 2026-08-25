<?php
/**
 * Woodev Popular Settlements Tools
 *
 * Bridges the two D8 merchant actions («Проверить актуальность популярных
 * городов», «Очистить список популярных городов» — #488 D8, relocated by the
 * shipping-tools-section spec's D3/D6) into
 * {@see \Woodev\Framework\Shipping\Settings\Shipping_Tools_Registry} through
 * its public filter — the SAME seam any carrier plugin uses. This keeps the
 * tools subsystem free of any knowledge of locations, and the location layer
 * free of any knowledge of the settings tab's internals: this file is the only
 * thing that knows both.
 *
 * D3 (the capability gate, relocated from the popular-settlements design's
 * D4): only providers declaring {@see Location_Provider::CAPABILITY_RESOLVE_KEY}
 * are offered, the choice always defaults to the active provider but is always
 * shown, and the chosen provider is re-validated server-side at run time — a
 * selector's presence is a view, never an authorisation. When no provider
 * qualifies, both tools are absent entirely (never present-and-disabled).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

use Woodev\Framework\Shipping\Settings\Shipping_Tool;
use Woodev\Framework\Shipping\Settings\Tool_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Popular_Settlements_Tools' ) ) :

	/**
	 * Registers the two D8 tools through {@see \Woodev\Framework\Shipping\Settings\Shipping_Tools_Registry::FILTER_TOOLS}.
	 *
	 * Stateless — every method is static, wired as
	 * `add_filter( ..., [ self::class, 'register_tools' ] )` from
	 * {@see Location_Provider_Registry::add_hooks()}.
	 *
	 * @since 2.0.2
	 */
	final class Popular_Settlements_Tools {

		/** @var string tool id: sweep. */
		private const TOOL_ID_SWEEP = 'popular-settlements-sweep';

		/** @var string tool id: clear. */
		private const TOOL_ID_CLEAR = 'popular-settlements-clear';

		/** @var string selector field name carrying the chosen provider id. */
		private const SELECTOR_NAME = 'provider_id';

		/**
		 * Filter callback: appends the two tools when at least one registered
		 * provider declares the resolve-key capability (D3); otherwise returns
		 * `$tools` unchanged, so both tools are absent entirely.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Tool[] $tools tools registered so far.
		 *
		 * @return Shipping_Tool[]
		 */
		public static function register_tools( array $tools ): array {
			$capable = self::capable_providers();

			if ( [] === $capable ) {
				return $tools;
			}

			$selector = self::build_selector( $capable );

			$tools[] = Shipping_Tool::create(
				self::TOOL_ID_SWEEP,
				__( 'Проверить актуальность популярных городов', 'woodev-plugin-framework' ),
				__( 'Перепроверяет каждую запись списка популярных городов у выбранного провайдера через его API и обновляет или удаляет устаревшие.', 'woodev-plugin-framework' ),
				__( 'Проверить', 'woodev-plugin-framework' ),
				[ self::class, 'run_sweep' ],
				false,
				'',
				$selector
			);

			$tools[] = Shipping_Tool::create(
				self::TOOL_ID_CLEAR,
				__( 'Очистить список популярных городов', 'woodev-plugin-framework' ),
				__( 'Удаляет весь список популярных городов выбранного провайдера. Список начнёт заполняться заново по мере поступления новых заказов.', 'woodev-plugin-framework' ),
				__( 'Очистить', 'woodev-plugin-framework' ),
				[ self::class, 'run_clear' ],
				false,
				'',
				$selector
			);

			return $tools;
		}

		/**
		 * Runs the sweep tool.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $args selector args, keyed by selector name.
		 *
		 * @return Tool_Result
		 */
		public static function run_sweep( array $args ): Tool_Result {
			$provider = self::resolve_capable_provider( (string) ( $args[ self::SELECTOR_NAME ] ?? '' ) );

			if ( null === $provider ) {
				return Tool_Result::failure( __( 'Выбранный провайдер недоступен или не поддерживает эту операцию.', 'woodev-plugin-framework' ) );
			}

			$store  = Location_Provider_Registry::instance()->popular_settlement_store();
			$counts = ( new Popular_Settlement_Verifier( $store ) )->sweep( $provider );

			return Tool_Result::success(
				sprintf(
					/* translators: 1: checked count, 2: unchanged count, 3: updated count, 4: deleted count, 5: failed count. */
					__( 'Проверено: %1$d. Без изменений: %2$d. Обновлено: %3$d. Удалено: %4$d. Ошибок: %5$d.', 'woodev-plugin-framework' ),
					$counts['checked'],
					$counts['unchanged'],
					$counts['updated'],
					$counts['deleted'],
					$counts['failed']
				)
			);
		}

		/**
		 * Runs the clear tool.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $args selector args, keyed by selector name.
		 *
		 * @return Tool_Result
		 */
		public static function run_clear( array $args ): Tool_Result {
			$provider_id = (string) ( $args[ self::SELECTOR_NAME ] ?? '' );
			$provider    = self::resolve_capable_provider( $provider_id );

			if ( null === $provider ) {
				return Tool_Result::failure( __( 'Выбранный провайдер недоступен или не поддерживает эту операцию.', 'woodev-plugin-framework' ) );
			}

			$deleted = Location_Provider_Registry::instance()->popular_settlement_store()->clear_provider( $provider_id );

			return Tool_Result::success(
				sprintf(
					/* translators: %d: number of deleted rows. */
					__( 'Удалено записей: %d.', 'woodev-plugin-framework' ),
					$deleted
				)
			);
		}

		/**
		 * @since 2.0.2
		 * @return array<string, Location_Provider> provider id => instance, resolve-key capable only.
		 */
		private static function capable_providers(): array {
			$capable = [];

			foreach ( Location_Provider_Registry::instance()->get_providers() as $id => $provider ) {
				if ( in_array( Location_Provider::CAPABILITY_RESOLVE_KEY, $provider->get_capabilities(), true ) ) {
					$capable[ $id ] = $provider;
				}
			}

			return $capable;
		}

		/**
		 * Re-resolves and re-checks a provider id server-side (D3) — never
		 * trusts a requested id beyond using it as a lookup key into the
		 * freshly re-computed capable list.
		 *
		 * @since 2.0.2
		 *
		 * @param string $provider_id requested provider id.
		 *
		 * @return Location_Provider|null
		 */
		private static function resolve_capable_provider( string $provider_id ): ?Location_Provider {
			return self::capable_providers()[ $provider_id ] ?? null;
		}

		/**
		 * Builds the selector: a static provider option list, defaulting to the
		 * currently active provider when it is itself capable, otherwise the
		 * first capable provider (spec D2 — always visible, never hidden; the
		 * merchant states the provider explicitly, nothing is inferred).
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, Location_Provider> $capable id => instance, resolve-key capable only.
		 *
		 * @return array{description:string,name:string,placeholder:string,options:array<int,array{value:string,label:string}>,default:string}
		 */
		private static function build_selector( array $capable ): array {
			$options = [];
			foreach ( $capable as $id => $provider ) {
				$options[] = [
					'value' => $id,
					'label' => $provider->get_name(),
				];
			}

			$active  = Location_Provider_Registry::instance()->get_active_provider();
			$default = null !== $active && isset( $capable[ $active->get_id() ] )
				? $active->get_id()
				: (string) array_key_first( $capable );

			return [
				'description' => __( 'Провайдер', 'woodev-plugin-framework' ),
				'name'        => self::SELECTOR_NAME,
				'placeholder' => '',
				'options'     => $options,
				'default'     => $default,
			];
		}
	}

endif;
