<?php
/**
 * Reads the latest notification of an account for the settings page.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * «Последнее уведомление: …, 2 мин назад, обработано». It reads our events table, never the marketplace, so rendering the page
 * costs one query and no remote call.
 *
 * @since 2.1.0
 */
final class Last_Notification_Reader {
	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full name of the events table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.0
	 *
	 * @param \wpdb  $db    Database.
	 * @param string $table Full name of the events table.
	 */
	public function __construct( \wpdb $db, string $table ) {
		$this->db    = $db;
		$this->table = $table;
	}

	/**
	 * Tells whether any notification came on the old address without a token since a moment. The «site is behind a proxy» notice
	 * shows only while such notifications still arrive: they are the only ones a proxy can make the shop refuse.
	 *
	 * @since 2.1.2
	 *
	 * @param int $since Unix time.
	 * @return bool
	 */
	public function legacy_url_seen_since( int $since ): bool {
		$found = $this->db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				'SELECT event_id FROM %i WHERE legacy_url = 1 AND received_at >= %s LIMIT 1',
				$this->table,
				gmdate( 'Y-m-d H:i:s', $since )
			)
		);

		return null !== $found && false !== $found && '' !== (string) $found;
	}

	/**
	 * Returns the newest event of an account that was meant to be processed.
	 *
	 * @since 2.1.0
	 *
	 * @param int $account_id Account id.
	 * @return array{type:string,received_at:int,status:string}|null Null when no notification arrived yet.
	 */
	public function latest( int $account_id ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->db->prepare(
				"SELECT type, received_at, status FROM %i WHERE account_id = %d AND status <> 'ignored' ORDER BY event_id DESC LIMIT 1",
				$this->table,
				$account_id
			),
			'ARRAY_A'
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$time = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $row['received_at'], new \DateTimeZone( 'UTC' ) );

		return [
			'type'        => (string) $row['type'],
			'received_at' => false === $time ? 0 : $time->getTimestamp(),
			'status'      => (string) $row['status'],
		];
	}
}
