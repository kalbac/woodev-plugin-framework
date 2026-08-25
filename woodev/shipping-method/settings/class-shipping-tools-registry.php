<?php
/**
 * Woodev Shipping Tools Registry
 *
 * The «Инструменты» section's registry (issue #505, D6). Contributors are
 * independent, uncoordinated parties — the framework itself and any carrier
 * plugin — exactly the situation
 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry} already
 * solves for location providers, so this registry mirrors it: a filter
 * ({@see self::FILTER_TOOLS}) carrying typed {@see Shipping_Tool} instances,
 * `_doing_it_wrong()` on a non-conforming entry (one bad entry does not poison
 * the rest), private internal registration. The framework's own two D8 tools
 * ({@see \Woodev\Framework\Shipping\Location\Popular_Settlements_Tools})
 * register through this SAME public seam — dogfooded, never special-cased.
 *
 * Collection is LAZY and synchronous (triggered by the first call to
 * {@see self::get_tools()}, {@see self::has_tools()} or {@see self::run()}),
 * not hooked onto `init` the way `Location_Provider_Registry::collect()` is.
 * This is a deliberate deviation from that registry's shape: the only
 * consumer is {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::build_sections()},
 * itself already called lazily/idempotently (mirroring its own
 * `get_field_settings()` / `get_map_settings()`) from `init` priority 25 — by
 * which point every filter callback has had a chance to `add_filter()`. Never
 * hooking an action here means {@see self::reset_for_tests()} needs no
 * `remove_action()` half at all: there is no hook table entry bound to a
 * specific instance to leak across a reset, sidestepping the exact
 * WP_UnitTestCase-hook-snapshot trap {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::reset_for_tests()}'s
 * own docblock documents at length.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Shipping_Tools_Registry' ) ) :

	/**
	 * Singleton registry of {@see Shipping_Tool} descriptors.
	 *
	 * @since 2.0.2
	 */
	final class Shipping_Tools_Registry {

		/**
		 * Filter tag: lets a plugin register its own {@see Shipping_Tool}
		 * instance(s) alongside the framework's own. Receives (and must
		 * return) a plain list — any entry not implementing {@see Shipping_Tool}
		 * is rejected and logged (`_doing_it_wrong`), the rest of the list
		 * still registers.
		 *
		 * Registration deadline: `init` priority 25. This filter is applied once
		 * per request, on the registry's first access, and the result is
		 * memoized. The first access in a normal request is
		 * {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::register()}
		 * at `init` priority 25, so a callback added later than that is silently
		 * never collected. Register from `plugins_loaded` or from `init` at a
		 * priority below 25.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_TOOLS = 'woodev_shipping_tools';

		/** @var self|null */
		private static $instance = null;

		/** @var array<string, Shipping_Tool> */
		private array $tools = [];

		/** @var bool whether collect() has already run this request. */
		private bool $collected = false;

		/**
		 * Returns the singleton instance.
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Resets all state. Test-only.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset_for_tests(): void {
			self::$instance = null;
		}

		/**
		 * Collects tools from {@see self::FILTER_TOOLS}. Idempotent — a repeat
		 * call is a no-op.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function collect(): void {
			if ( $this->collected ) {
				return;
			}
			$this->collected = true;

			/**
			 * Filters the list of registered shipping tools.
			 *
			 * A plugin appends its own {@see Shipping_Tool} instance(s) here. Any
			 * returned entry that does not implement {@see Shipping_Tool} is
			 * rejected and logged via `_doing_it_wrong()`; the rest of the list
			 * still registers.
			 *
			 * Registration deadline: `init` priority 25. This filter is applied
			 * once per request, on the registry's first access, and the result is
			 * memoized. The first access in a normal request is
			 * {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::register()}
			 * at `init` priority 25, so a callback added later than that is
			 * silently never collected. Register from `plugins_loaded` or from
			 * `init` at a priority below 25.
			 *
			 * @since 2.0.2
			 *
			 * @param Shipping_Tool[] $tools tools to register.
			 */
			$candidates = (array) apply_filters( self::FILTER_TOOLS, [] );

			foreach ( $candidates as $candidate ) {
				if ( ! $candidate instanceof Shipping_Tool ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf(
							'A "%s" filter callback returned a value that does not implement Shipping_Tool; it was ignored.',
							self::FILTER_TOOLS
						),
						'2.0.2'
					);
					continue;
				}

				$this->register_tool( $candidate );
			}
		}

		/**
		 * Registers one tool. A duplicate id is rejected — the FIRST
		 * registration wins and `_doing_it_wrong()` reports the conflict, same
		 * discipline as `Location_Provider_Registry::register_provider()`
		 * (independent, uncoordinated parties — a collision is more likely an
		 * accidental id clash than a deliberate override).
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Tool $tool tool to register.
		 *
		 * @return void
		 */
		private function register_tool( Shipping_Tool $tool ): void {
			$id = $tool->get_id();

			if ( isset( $this->tools[ $id ] ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'A second shipping tool was registered under id "%s"; the first registration wins.',
						$id
					),
					'2.0.2'
				);

				return;
			}

			$this->tools[ $id ] = $tool;
		}

		/**
		 * Gets every registered tool.
		 *
		 * @since 2.0.2
		 * @return Shipping_Tool[]
		 */
		public function get_tools(): array {
			$this->collect();

			return array_values( $this->tools );
		}

		/**
		 * Whether at least one tool is registered — the section exists only
		 * when this is true.
		 *
		 * @since 2.0.2
		 * @return bool
		 */
		public function has_tools(): bool {
			$this->collect();

			return [] !== $this->tools;
		}

		/**
		 * Runs one tool by id, re-checking everything server-side: the tool
		 * must exist, must not be disabled, and only its own declared selector
		 * arg names ever reach its callback — a crafted request cannot widen
		 * the args a tool receives.
		 *
		 * Does NOT catch exceptions the callback throws — the REST caller
		 * wraps this exactly like `Woodev_REST_API_Settings_Page::test_connection()`
		 * wraps `test_connection()`.
		 *
		 * @since 2.0.2
		 *
		 * @param string              $tool_id tool id.
		 * @param array<string,mixed> $args    selector values keyed by selector name.
		 *
		 * @return Tool_Result
		 */
		public function run( string $tool_id, array $args ): Tool_Result {
			$this->collect();

			if ( ! isset( $this->tools[ $tool_id ] ) ) {
				return Tool_Result::failure( __( 'Инструмент не найден.', 'woodev-plugin-framework' ) );
			}

			$tool = $this->tools[ $tool_id ];

			if ( $tool->is_disabled() ) {
				$message = '' !== $tool->get_status_text()
					? $tool->get_status_text()
					: __( 'Инструмент недоступен.', 'woodev-plugin-framework' );

				return Tool_Result::failure( $message );
			}

			$scoped_args = array_intersect_key( $args, array_flip( $tool->get_selector_names() ) );
			$result      = call_user_func( $tool->get_callback(), $scoped_args );

			if ( ! $result instanceof Tool_Result ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'The callback for shipping tool "%s" did not return a Tool_Result; treated as failure.',
						$tool_id
					),
					'2.0.2'
				);

				return Tool_Result::failure( __( 'Внутренняя ошибка сервера.', 'woodev-plugin-framework' ) );
			}

			return $result;
		}
	}

endif;
