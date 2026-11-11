<?php
/**
 * Activity fixtures: a definition that declares `activity`, and a provider that answers from canned state.
 *
 * No marketplace source — the states below are the shapes any provider can report.
 *
 * @package Woodev\Tests\Unit\Admin\Records
 */

namespace Woodev\Tests\Unit\Admin\Records;

require_once __DIR__ . '/Records_Fixtures.php';

/**
 * Canned activity states.
 */
final class Activity_States {

	/**
	 * `activity` as a definition declares it.
	 *
	 * @param array<string,int> $overrides Keys to replace.
	 * @return array<string,int>
	 */
	public static function declared( array $overrides = [] ): array {
		return array_merge(
			[
				'refresh_ms'     => 2000,
				'max_refresh_ms' => 30000,
				'stale_after_ms' => 10000,
				'id_limit'       => 5,
			],
			$overrides
		);
	}

	/**
	 * A definition with activity declared.
	 *
	 * @param array<string,mixed> $overrides Top-level keys to replace.
	 * @return array<string,mixed>
	 */
	public static function definition( array $overrides = [] ): array {
		return Records_Definitions::standard( array_merge( [ 'activity' => self::declared() ], $overrides ) );
	}

	/**
	 * A process.
	 *
	 * @param string              $id        Observation id.
	 * @param array<string,mixed> $overrides Keys to replace.
	 * @return array<string,mixed>
	 */
	public static function process( string $id, array $overrides = [] ): array {
		return array_merge(
			[
				'id'        => $id,
				'state'     => 'running',
				'label'     => 'Отправка остатков',
				'completed' => 2,
				'total'     => 8,
				'percent'   => 25,
				'text'      => '2 из 8',
			],
			$overrides
		);
	}

	/**
	 * A process whose size is not known: counts and percent stay null.
	 *
	 * @param string $id Observation id.
	 * @return array<string,mixed>
	 */
	public static function indeterminate( string $id ): array {
		return self::process( $id, [ 'completed' => null, 'total' => null, 'percent' => null, 'text' => 'Идёт обработка' ] );
	}

	/**
	 * A locked row: `sync` and the bulk `sync_all` cannot be taken; reading (`open`) still can.
	 *
	 * @param array<string,mixed> $processes Processes on the row.
	 * @return array<string,mixed>
	 */
	public static function locked( array $processes ): array {
		return [
			'locked'          => true,
			'reason'          => 'Товар уже отправляется на маркетплейс.',
			'blocked_actions' => [ 'sync', 'sync_all' ],
			'processes'       => $processes,
			'observed_at'     => '2026-11-11T10:00:00Z',
		];
	}

	/**
	 * A standard row carrying activity.
	 *
	 * @param string              $id       Row id.
	 * @param array<string,mixed> $activity Activity.
	 * @param array<string,mixed> $row      Other row keys to replace.
	 * @return array<string,mixed>
	 */
	public static function row( string $id, array $activity, array $row = [] ): array {
		return Records_Definitions::row( $id, array_merge( [ 'activity' => $activity ], $row ) );
	}
}

/**
 * Provider whose `get_activity()` answers from `$activity_result` and records what it was asked.
 */
class Activity_Fixture_Provider extends Fake_Provider {

	/** @var array<string,mixed> What get_activity() returns. */
	public $activity_result = [];

	/** @var \Throwable|null Thrown by get_activity(). */
	public $activity_throw = null;

	public function get_activity( array $context, array $ids ): array {
		$this->calls[]            = 'get_activity';
		$this->last['activity']   = [ $context, $ids ];
		if ( null !== $this->activity_throw ) {
			throw $this->activity_throw;
		}

		return $this->activity_result;
	}
}

/**
 * Activity route request: a JSON body, as `POST R/activity` receives it.
 */
class Activity_Test_Request extends Records_Test_Request {

	/** @var array<string,mixed>|null */
	private $json;

	/** @var array<string,mixed> */
	private $query_override;

	/**
	 * @param array<string,mixed>|null $json         Parsed JSON body.
	 * @param array<string,string>     $url_params   Route params.
	 * @param array<string,mixed>      $query_params URL query (must be empty for the route).
	 */
	public function __construct( $json, array $url_params = [], array $query_params = [] ) {
		parent::__construct( array_merge( [ 'owner' => 'ozon-seller', 'table' => 'products' ], $url_params ), $query_params );
		$this->json           = $json;
		$this->query_override = $query_params;
	}

	/** @return mixed */
	public function get_json_params() {
		return $this->json;
	}

	/** @param array<string,mixed> $params Query params. */
	public function set_query_params( $params ) {
		$this->query_override = $params;
	}

	/** @return array<string,mixed> */
	public function get_query_params() {
		return $this->query_override;
	}
}
