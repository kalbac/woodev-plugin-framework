<?php
/**
 * Realistic shipping fixture — its own pickup Point_Source.
 *
 * WHY THIS EXISTS (card #734, s112). The rig used to hang BOTH of its pickup methods off a
 * single fixture plugin, and the framework keys a `Pickup_Handler` — and therefore a
 * `Point_Source` — to a PLUGIN, not to a method: the REST route is
 * `/shipping/pickup/{plugin_id}/points` and `Pickup_Controller` builds a distinct one per
 * plugin on purpose (see its own `register_routes()` comment). So two methods of one plugin
 * can never serve different points, and with `WOODEV_TEST_PICKUP_LIVE_YANDEX` on — the rig's
 * standard state — the static fixture data was unreachable through the UI entirely.
 *
 * Giving this SECOND fixture plugin its own source is the shape the framework already
 * expects, and it needs no product-code change at all. It also makes the rig able to show
 * TWO CARRIERS side by side for the first time, each with its own points and its own REST
 * route — the ordinary production arrangement (several carrier plugins on one shop), which
 * the rig previously could not reproduce in any way.
 *
 * ITS POINTS ARE ITS OWN, deliberately, rather than shared with
 * `Woodev_Test_Bulk_Point_Source`. Two carriers showing an identical list would be a poor
 * oscilloscope, and sharing the dataset would couple two fixtures that are otherwise
 * independent.
 *
 * ⚠ КРАСНОДАР CARRIES EXACTLY ONE POINT, and that is not an accident: card #150 («одна точка
 * в городе — зум ломает тайлы») describes a degenerate bounding box that collapses to zero
 * area, and until now no fixture anywhere could produce that shape. Do not "tidy" it by
 * adding a second Krasnodar point.
 *
 * @package Woodev_Realistic_Shipping_Fixture
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Realistic_Point_Source' ) ) {

	/**
	 * Static bulk pickup-point source for the realistic shipping fixture.
	 */
	final class Woodev_Realistic_Point_Source implements \Woodev\Framework\Shipping\Pickup\Point_Source {

		/**
		 * Canonical locality name => accepted spellings.
		 *
		 * Matching is case- and whitespace-insensitive but otherwise EXACT: no
		 * transliteration and no partial matching, so an unknown locality still yields an
		 * empty list and the checkout's `emptyLocality` state stays reachable on the rig.
		 *
		 * The English spellings exist for the same reason the sibling fixture's do: this
		 * rig's DaData account answers RU settlement suggestions with English display names,
		 * so a Cyrillic-only match would make the record-addressed path unreachable in
		 * practice while remaining correct in unit tests.
		 *
		 * @var array<string, string[]>
		 */
		private const LOCALITY_ALIASES = [
			'Москва'    => [ 'Москва', 'Moscow' ],
			'Краснодар' => [ 'Краснодар', 'Krasnodar' ],
		];

		/**
		 * @inheritDoc
		 */
		public function get_strategy(): string {
			return self::STRATEGY_BULK;
		}

		/**
		 * @inheritDoc
		 */
		public function fetch_points( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): array {

			$locality = $this->resolve_locality( $this->requested_locality_name( $query ) );

			if ( null === $locality ) {
				return [];
			}

			$matching = array_filter(
				$this->all_points(),
				static function ( array $payload ) use ( $locality ): bool {
					return $locality === ( $payload['locality'] ?? null );
				}
			);

			return array_values(
				array_filter(
					array_map(
						[ \Woodev\Framework\Shipping\Pickup\Pickup_Point::class, 'from_array' ],
						$matching
					)
				)
			);
		}

		/**
		 * @inheritDoc
		 */
		public function fetch_details( string $point_id ): ?\Woodev\Framework\Shipping\Pickup\Pickup_Point {

			foreach ( $this->all_points() as $payload ) {

				if ( ( $payload['id'] ?? null ) === $point_id ) {
					return \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array( $payload );
				}
			}

			return null;
		}

		/**
		 * Resolves the locality name the query is asking about.
		 *
		 * Prefers the attached Location Provider record's own settlement name — the browser
		 * sends an opaque locality KEY and the framework resolves the record server-side —
		 * and falls back to the bare `locality` parameter for a caller that sends one.
		 *
		 * @param \Woodev\Framework\Shipping\Pickup\Point_Query $query The dispatched query.
		 *
		 * @return string|null
		 */
		private function requested_locality_name( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): ?string {

			$record = $query->get_record();

			if ( null === $record ) {
				return $query->get_locality();
			}

			$settlement = $record->settlement();

			if ( null !== $settlement && '' !== $settlement['name'] ) {
				return $settlement['name'];
			}

			return '' !== $record->label() ? $record->label() : null;
		}

		/**
		 * Resolves a requested spelling to one of this fixture's canonical locality names.
		 *
		 * @param string|null $locality Requested locality name.
		 *
		 * @return string|null The canonical name, or null when it matches none.
		 */
		private function resolve_locality( ?string $locality ): ?string {

			if ( null === $locality || '' === trim( $locality ) ) {
				return null;
			}

			$needle = mb_strtolower( trim( $locality ) );

			foreach ( self::LOCALITY_ALIASES as $canonical => $aliases ) {

				foreach ( $aliases as $alias ) {

					if ( mb_strtolower( $alias ) === $needle ) {
						return $canonical;
					}
				}
			}

			return null;
		}

		/**
		 * The fixture's static points.
		 *
		 * Coordinates are real for their cities, so the map's bounding-box filtering and its
		 * clustering behave the way they would against a live carrier rather than collapsing
		 * on nonsense geometry.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function all_points(): array {

			return [
				[
					'id'       => 'REAL-MSK-1',
					'name'     => 'Реалистичный ПВЗ — Тверская',
					'locality' => 'Москва',
					'address'  => 'Москва, Тверская улица, 7',
					'lat'      => 55.760186,
					'lng'      => 37.609868,
					'type'     => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи' ],
				],
				[
					'id'       => 'REAL-MSK-2',
					'name'     => 'Реалистичный постамат — Арбат',
					'locality' => 'Москва',
					'address'  => 'Москва, улица Арбат, 24',
					'lat'      => 55.749100,
					'lng'      => 37.591000,
					'type'     => [ 'code' => 'POSTAMAT', 'label' => 'Постамат' ],
				],
				[
					'id'       => 'REAL-MSK-3',
					'name'     => 'Реалистичный ПВЗ — Бауманская',
					'locality' => 'Москва',
					'address'  => 'Москва, Бауманская улица, 35',
					'lat'      => 55.772500,
					'lng'      => 37.679400,
					'type'     => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи' ],
				],
				// ⚠ THE ONLY Краснодар point — see this file's header. Card #150 needs a city
				// whose bounds degenerate to a single coordinate; adding a second one here
				// silently removes the only fixture in the repo that can reproduce it.
				[
					'id'       => 'REAL-KRD-1',
					'name'     => 'Реалистичный ПВЗ — Красная',
					'locality' => 'Краснодар',
					'address'  => 'Краснодар, Красная улица, 176',
					'lat'      => 45.048100,
					'lng'      => 38.976200,
					'type'     => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи' ],
				],
			];
		}
	}
}
