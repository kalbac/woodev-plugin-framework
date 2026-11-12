<?php
/**
 * Where an interrupted link or refresh run left off.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A run that reads a big catalogue meets the governor's wait (a wait is not a failure, the job comes back). Without a
 * record of the pages and detail requests already answered, every comeback starts at the first page and spends the same requests
 * again, so a catalogue that needs more requests than the bucket holds is never finished.
 *
 * One option per account and kind (`link` or `refresh`), no autoload, under the plugin's option family ({@see Uninstaller::option_prefix()}) so the
 * uninstaller removes it. A record is written only when a run is interrupted and removed when the run ends, whether it finished or
 * gave up. It is used only by a run of the same scope (the category of a link run) that started less than {@see self::MAX_AGE}
 * seconds ago: an older cursor is not trusted.
 */
final class Link_Progress_Store {
	public const OPTION       = 'run_progress_';
	public const KIND_LINK    = 'link';
	public const KIND_REFRESH = 'refresh';
	public const MAX_AGE      = 21600;

	/**
	 * Option family of the plugin.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Creates the store.
	 *
	 * @since 2.0.29
	 *
	 * @param string $option_prefix The plugin's option family, from {@see Uninstaller::option_prefix()}.
	 */
	public function __construct( string $option_prefix ) {
		$this->prefix = $option_prefix;
	}

	/**
	 * Removes the records of an account: the account is being deleted.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function forget_account( int $account_id ): void {
		foreach ( [ self::KIND_LINK, self::KIND_REFRESH ] as $kind ) {
			$this->clear( $account_id, $kind );
		}
	}

	/**
	 * Loads the record a run continues, or starts a new one.
	 *
	 * @param int    $account_id Account id.
	 * @param string $kind       {@see self::KIND_LINK} or {@see self::KIND_REFRESH}.
	 * @param int    $scope      Category id of a link run, 0 for the whole shop and for a refresh.
	 * @param int    $now        Unix time.
	 * @return array{started_at:int,scope:int,state:array<string,mixed>} `state` is empty for a new record.
	 */
	public function load( int $account_id, string $kind, int $scope, int $now ): array {
		$stored = get_option( $this->option_name( $kind, $account_id ), null );

		if ( is_array( $stored ) && (int) ( $stored['scope'] ?? -1 ) === $scope && $now - (int) ( $stored['started_at'] ?? 0 ) < self::MAX_AGE && is_array( $stored['state'] ?? null ) ) {
			return [
				'started_at' => (int) $stored['started_at'],
				'scope'      => $scope,
				'state'      => $stored['state'],
			];
		}

		return [
			'started_at' => $now,
			'scope'      => $scope,
			'state'      => [],
		];
	}

	/**
	 * Keeps a record for the run that continues it.
	 *
	 * @param int                                                       $account_id Account id.
	 * @param string                                                    $kind       Kind.
	 * @param array{started_at:int,scope:int,state:array<string,mixed>} $record     Record from {@see self::load()} with the state as the run left it.
	 * @return void
	 */
	public function save( int $account_id, string $kind, array $record ): void {
		update_option( $this->option_name( $kind, $account_id ), $record, false );
	}

	/**
	 * Removes the record: the run ended.
	 *
	 * @param int    $account_id Account id.
	 * @param string $kind       Kind.
	 * @return void
	 */
	public function clear( int $account_id, string $kind ): void {
		delete_option( $this->option_name( $kind, $account_id ) );
	}

	/**
	 * Names the option of a record.
	 *
	 * @param string $kind       Kind.
	 * @param int    $account_id Account id.
	 * @return string
	 */
	private function option_name( string $kind, int $account_id ): string {
		return $this->prefix . self::OPTION . $kind . '_' . $account_id;
	}
}
