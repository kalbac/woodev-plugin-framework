<?php
/**
 * Woodev_Test_List_Location_Provider — the rig's `list`-capable fixture Location_Provider.
 *
 * Location Provider layer, Task 13 (docs-internal/plans/2026-08-12-location-provider-plan.md,
 * spec D7): the bundled {@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider}
 * has NO {@see \Woodev\Framework\Shipping\Location\Location_Provider::CAPABILITY_LIST}
 * capability — DaData is a query-driven API, it cannot enumerate — so `related-list` and
 * `ajax-select2` field modes are never offered on a DaData-only store and both the unit suite
 * and the rig need a fake provider that CAN enumerate to exercise those modes at all.
 *
 * Deliberately tiny, static, fixture-shaped data — this is not a real dictionary, it exists to
 * make {@see self::list_localities()} observably work: two RU regions, with a couple of
 * settlements enumerable within each once a region is chosen. `suggest()` is implemented too
 * (required of every provider) as a trivial case-insensitive substring match over the SAME
 * static data, so this provider is usable as the active provider outright, not merely as a
 * `list`-only add-on.
 *
 * Registered via the {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::FILTER_PROVIDERS}
 * filter — see `woodev_test_shipping_method_plugin_init()`'s own wiring — NOT made active by
 * default: the store's `active_provider` setting still defaults to `dadata`
 * ({@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::DEFAULT_PROVIDER_ID}),
 * so an operator opts into it explicitly on the "Локация" settings page to see `related-list`/
 * `ajax-select2` on the rig.
 *
 * Required/declared HERE (inside the plugin's own init callback), not at file top level — same
 * reasoning as `class-test-location-adapter.php`'s own docblock (gotcha
 * `fixture-classes-must-live-inside-plugin-init`): `Abstract_Location_Provider` is only
 * autoloadable once `Woodev_Plugin_Bootstrap` has selected the highest-version framework copy
 * and registered its autoloader.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_List_Location_Provider' ) ) {

	/**
	 * Class Woodev_Test_List_Location_Provider
	 */
	class Woodev_Test_List_Location_Provider extends \Woodev\Framework\Shipping\Location\Abstract_Location_Provider {

		/**
		 * Provider id — the {@see \Woodev\Framework\Shipping\Location\Locality_Key}
		 * namespace prefix every record this fixture produces carries.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const PROVIDER_ID = 'test-list';

		/**
		 * Static region data: `native_id => name`. Fixture-shaped, not a real
		 * dictionary.
		 *
		 * @since 2.0.2
		 * @var array<string, string>
		 */
		private const REGIONS = [
			'mo' => 'Московская область',
			'lo' => 'Ленинградская область',
		];

		/**
		 * Static settlement data per region: `region_native_id => [ native_id => name ]`.
		 *
		 * @since 2.0.2
		 * @var array<string, array<string, string>>
		 */
		private const SETTLEMENTS = [
			'mo' => [
				'moscow'     => 'Москва',
				'zelenograd' => 'Зеленоград',
			],
			'lo' => [
				'spb'    => 'Санкт-Петербург',
				'pushkin' => 'Пушкин',
			],
		];

		/**
		 * @inheritDoc
		 *
		 * @since 2.0.2
		 */
		public function get_id(): string {
			return self::PROVIDER_ID;
		}

		/**
		 * @inheritDoc
		 *
		 * @since 2.0.2
		 */
		public function get_name(): string {
			return __( 'Тестовый список (Task 13, только для рига)', 'woodev-plugin-framework' );
		}

		/**
		 * @inheritDoc
		 *
		 * @since 2.0.2
		 */
		public function get_countries(): array {
			return [ 'RU' ];
		}

		/**
		 * @inheritDoc
		 *
		 * @since 2.0.2
		 */
		protected function declare_suggest_levels(): array {
			return [
				\Woodev\Framework\Shipping\Location\Location_Record::LEVEL_REGION,
				\Woodev\Framework\Shipping\Location\Location_Record::LEVEL_SETTLEMENT,
			];
		}

		/**
		 * @inheritDoc
		 *
		 * Trivial case-insensitive substring match over the same static data
		 * {@see self::list_localities()} enumerates — a real provider would call
		 * out to its own API; this fixture never does any I/O.
		 *
		 * @since 2.0.2
		 */
		public function suggest( string $query, \Woodev\Framework\Shipping\Location\Location_Scope $scope ): array {
			$needle = mb_strtolower( trim( $query ) );

			if ( '' === $needle ) {
				return [];
			}

			$matches = [];

			foreach ( $this->list_localities( $scope ) as $record ) {
				$name = \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_REGION === $record->level()
					? ( $record->region()['name'] ?? '' )
					: ( $record->settlement()['name'] ?? '' );

				if ( false !== mb_strpos( mb_strtolower( $name ), $needle ) ) {
					$matches[] = $record;
				}
			}

			return $matches;
		}

		/**
		 * @inheritDoc
		 *
		 * Region-level scope (no parent — `region` is never given one, spec
		 * {@see \Woodev\Framework\Shipping\Location\Location_Scope::within()}):
		 * every fixture region. Settlement-level scope: the settlements under the
		 * parent region named by {@see \Woodev\Framework\Shipping\Location\Location_Scope::parent_record()}
		 * (falling back to {@see \Woodev\Framework\Shipping\Location\Location_Scope::parent_components()}'s
		 * `region.name`-derived native id when the caller supplied raw components
		 * instead of a record) — or nothing when no parent constraint narrows to a
		 * known region. `address` level: this fixture has no street data, matching
		 * the "list is region/settlement only" shape the plan's Task 13 description
		 * uses CDEK's own dictionary as a reference for.
		 *
		 * @since 2.0.2
		 */
		public function list_localities( \Woodev\Framework\Shipping\Location\Location_Scope $scope ): array {
			if ( \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_REGION === $scope->level() ) {
				return $this->region_records();
			}

			if ( \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_SETTLEMENT === $scope->level() ) {
				return $this->settlement_records_for( $scope );
			}

			return [];
		}

		/**
		 * Builds every fixture region as a {@see \Woodev\Framework\Shipping\Location\Location_Record}.
		 *
		 * @since 2.0.2
		 *
		 * @return \Woodev\Framework\Shipping\Location\Location_Record[]
		 */
		private function region_records(): array {
			$records = [];

			foreach ( self::REGIONS as $native_id => $name ) {
				$records[] = \Woodev\Framework\Shipping\Location\Location_Record::from_array(
					[
						'key'         => self::PROVIDER_ID . ':' . $native_id,
						'provider_id' => self::PROVIDER_ID,
						'level'       => \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_REGION,
						'country'     => 'RU',
						'region'      => [ 'name' => $name, 'type' => 'область' ],
						'label'       => $name,
					]
				);
			}

			return $records;
		}

		/**
		 * Builds the settlement records for the region `$scope` is narrowed to, or
		 * `[]` when the scope names no region this fixture recognizes.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Scope $scope Settlement-level scope.
		 *
		 * @return \Woodev\Framework\Shipping\Location\Location_Record[]
		 */
		private function settlement_records_for( \Woodev\Framework\Shipping\Location\Location_Scope $scope ): array {
			$region_native_id = $this->region_native_id_from_scope( $scope );

			if ( null === $region_native_id || ! isset( self::SETTLEMENTS[ $region_native_id ] ) ) {
				return [];
			}

			$region_name = self::REGIONS[ $region_native_id ];
			$records     = [];

			foreach ( self::SETTLEMENTS[ $region_native_id ] as $native_id => $name ) {
				$records[] = \Woodev\Framework\Shipping\Location\Location_Record::from_array(
					[
						'key'         => self::PROVIDER_ID . ':' . $native_id,
						'provider_id' => self::PROVIDER_ID,
						'level'       => \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_SETTLEMENT,
						'country'     => 'RU',
						'region'      => [ 'name' => $region_name, 'type' => 'область' ],
						'settlement'  => [ 'name' => $name, 'type' => 'г' ],
						'label'       => $name,
					]
				);
			}

			return $records;
		}

		/**
		 * Resolves the region native id a scope's parent constraint names, in
		 * either shape {@see \Woodev\Framework\Shipping\Location\Location_Scope}
		 * carries a parent (a full record's own key, or raw `region.name`
		 * components) — `null` when the scope has no parent, or the parent does
		 * not match a fixture region this provider knows about.
		 *
		 * REPORTS NARROWING (#358) on `$scope` the same way the CDEK fixture's
		 * {@see \Woodev_Test_Cdek_Location_Provider::region_code_from_scope()} does:
		 * `exact` for the own-provider key, `degraded` for a components name found in
		 * {@see self::REGIONS}, `none` for a foreign key or an unmatched name. Nothing
		 * is reported when the scope carries no parent at all.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Reports the narrowing verdict on `$scope` (#358).
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Scope $scope Settlement-level scope.
		 *
		 * @return string|null
		 */
		private function region_native_id_from_scope( \Woodev\Framework\Shipping\Location\Location_Scope $scope ): ?string {
			if ( ! $scope->has_parent() ) {
				return null;
			}

			$parent_record = $scope->parent_record();

			if ( null !== $parent_record ) {
				[ $provider_id, $native_id ] = \Woodev\Framework\Shipping\Location\Locality_Key::parse( $parent_record->key() );

				$resolved = self::PROVIDER_ID === $provider_id && isset( self::REGIONS[ $native_id ] ) ? $native_id : null;

				$scope->report_narrowing(
					null !== $resolved
						? \Woodev\Framework\Shipping\Location\Location_Provider::NARROWING_EXACT
						: \Woodev\Framework\Shipping\Location\Location_Provider::NARROWING_NONE
				);

				return $resolved;
			}

			$components = $scope->parent_components();
			$region     = $components['region']['name'] ?? null;

			if ( null === $region ) {
				$scope->report_narrowing( \Woodev\Framework\Shipping\Location\Location_Provider::NARROWING_NONE );

				return null;
			}

			$match = array_search( $region, self::REGIONS, true );

			$scope->report_narrowing(
				false !== $match
					? \Woodev\Framework\Shipping\Location\Location_Provider::NARROWING_DEGRADED
					: \Woodev\Framework\Shipping\Location\Location_Provider::NARROWING_NONE
			);

			return false !== $match ? (string) $match : null;
		}
	}
}
