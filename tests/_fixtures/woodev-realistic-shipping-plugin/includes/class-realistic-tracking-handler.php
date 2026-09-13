<?php
/**
 * Deterministic tracking history for the realistic shipping fixture.
 *
 * @package Woodev_Realistic_Shipping_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Supplies fixture-only tracking history without making a network request.
 */
final class Woodev_Realistic_Tracking_Handler extends \Woodev\Framework\Shipping\Order\Abstract_Tracking_Handler {

	/**
		 * Sets the hook prefix used by the inherited display seam.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
	public function __construct() {
		$this->hook_prefix = 'realistic';
	}

	/**
	 * Returns a stable, realistic history for a supplied fixture tracking number.
	 *
	 * The rig's `RL200100…` numbers encode their seed position; using that position
	 * makes the terminal event agree with the seeded delivery status. Other tracking
	 * numbers still choose one of the same histories deterministically.
	 *
	 * @since 2.0.2
	 *
	 * @param string $tracking_number carrier tracking number.
	 * @return array<int, array{status:string, description:string, timestamp:int, location:string}>
	 */
	public function get_history( string $tracking_number ): array {
		if ( '' === $tracking_number ) {
			return [];
		}

		$index = $this->tracking_index( $tracking_number );

		// Anchored to NOW, not to a fixed epoch: a hardcoded base put every event in
		// July 2024 while the seeded orders are dated 2026, so on the rig the history
		// predated its own order by two years and read as broken. The SHAPE stays
		// deterministic per tracking number — which history, how many events, and the
		// offsets between them — only the anchor follows the clock, exactly as a real
		// carrier's timestamps do. Events land inside the last few days and never in
		// the future.
		$time = time() - 3 * DAY_IN_SECONDS + ( $index % 24 ) * HOUR_IN_SECONDS;

		if ( 0 === $index % 8 ) {
			return [
				$this->event( 'Создано', 'Заявка на доставку создана', $time, 'Москва' ),
				$this->event( 'Проверено', 'Данные отправления проверены перевозчиком', $time + HOUR_IN_SECONDS, 'Москва' ),
				$this->event( 'Принято', 'Отправление принято в пункте отправки', $time + 2 * HOUR_IN_SECONDS, 'Москва' ),
			];
		}

		$base  = [
			$this->event( 'Принято', 'Отправление принято в пункте отправки', $time, 'Москва' ),
			$this->event( 'В пути', 'Отправление направлено в регион получателя', $time + 6 * HOUR_IN_SECONDS, 'Распределительный центр Москва' ),
		];

		switch ( $index % 8 ) {
			case 3:
				return array_merge( $base, [ $this->event( 'К выдаче', 'Отправление ожидает получателя в пункте выдачи', $time + 30 * HOUR_IN_SECONDS, 'Воронеж' ) ] );
			case 4:
				return array_merge( $base, [
					$this->event( 'К выдаче', 'Отправление поступило в пункт выдачи', $time + 30 * HOUR_IN_SECONDS, 'Воронеж' ),
					$this->event( 'Вручено', 'Отправление вручено получателю', $time + 34 * HOUR_IN_SECONDS, 'Воронеж' ),
				] );
			case 5:
				return array_merge( $base, [ $this->event( 'Возвращено', 'Отправление возвращено отправителю', $time + 34 * HOUR_IN_SECONDS, 'Москва' ) ] );
			case 6:
				return [
					$this->event( 'Создано', 'Заявка на доставку создана', $time, 'Москва' ),
					$this->event( 'Отменено', 'Отправка отменена до передачи перевозчику', $time + HOUR_IN_SECONDS, 'Москва' ),
					$this->event( 'Отменено', 'Отправление не передано в доставку', $time + 2 * HOUR_IN_SECONDS, 'Москва' ),
				];
			case 7:
				return array_merge( $base, [ $this->event( 'Задержано', 'Отправление проходит дополнительную проверку', $time + 30 * HOUR_IN_SECONDS, 'Таможенный пост Москва' ) ] );
			default:
				return array_merge( $base, [ $this->event( 'В пути', 'Отправление следует в город получателя', $time + 30 * HOUR_IN_SECONDS, 'Воронеж' ) ] );
		}
	}

	/**
	 * Maps an API response when the inherited base path is used. This fixture never
	 * calls it because {@see self::get_history()} has no network dependency.
	 *
	 * @since 2.0.2
	 *
	 * @param \Woodev_API_Response $response unused fixture response.
	 * @return array<int, array{status:string, description:string, timestamp:int, location:string}>
	 */
	protected function map_events( \Woodev_API_Response $response ): array {
		return [];
	}

	/**
	 * Builds an event in the framework's normalized tracking shape.
	 *
	 * @since 2.0.2
	 *
	 * @param string $status event status.
	 * @param string $description event description.
	 * @param int    $timestamp event time.
	 * @param string $location event location.
	 * @return array{status:string, description:string, timestamp:int, location:string}
	 */
	private function event( string $status, string $description, int $timestamp, string $location ): array {
		return self::make_event( $status, $description, $timestamp, $location );
	}

	/**
	 * Derives a stable seed index from a fixture tracking number.
	 *
	 * @since 2.0.2
	 *
	 * @param string $tracking_number tracking number.
	 * @return int non-negative deterministic index.
	 */
	private function tracking_index( string $tracking_number ): int {
		if ( preg_match( '/^RL(\d+)$/', $tracking_number, $matches ) ) {
			return max( 0, (int) $matches[1] - 200100000 );
		}

		return array_sum( unpack( 'C*', $tracking_number ) ) % 120;
	}
}
