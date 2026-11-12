<?php
/**
 * SQLite versions of the tables the layer's stores write to.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

/**
 * Only the tables the layer's own classes read and write: the throttle, the error ring, the event and task ledgers, and the pieces of
 * `accounts`, `links` and `sync_state` the sync queues' selection joins. Their MySQL form lives with the persistence layer (a later
 * card); the names carry a neutral prefix because the layer knows no marketplace.
 */
final class Sqlite_Schema {
	public const THROTTLE   = 'wp_woodev_fake_throttle';
	public const API_ERRORS = 'wp_woodev_fake_api_errors';
	public const EVENTS     = 'wp_woodev_fake_events';
	public const ACCOUNTS   = 'wp_woodev_fake_accounts';
	public const LINKS      = 'wp_woodev_fake_links';
	public const TASKS      = 'wp_woodev_fake_tasks';
	public const TASK_ITEMS = 'wp_woodev_fake_task_items';
	public const SYNC_STATE = 'wp_woodev_fake_sync_state';

	/**
	 * Creates the tables in a database.
	 *
	 * @param Sqlite_Wpdb $db Database.
	 * @return void
	 */
	public static function install( Sqlite_Wpdb $db ): void {
		$db->define(
			'CREATE TABLE ' . self::THROTTLE . " (
				account_id INTEGER NOT NULL,
				bucket TEXT NOT NULL,
				state TEXT NULL,
				version INTEGER NOT NULL DEFAULT 0,
				disabled_until TEXT NULL DEFAULT NULL,
				disabled_reason TEXT NOT NULL DEFAULT '',
				PRIMARY KEY (account_id, bucket)
			)"
		);
		$db->define(
			'CREATE TABLE ' . self::API_ERRORS . " (
				error_id INTEGER PRIMARY KEY AUTOINCREMENT,
				account_id INTEGER NULL DEFAULT NULL,
				path TEXT NOT NULL DEFAULT '',
				http_status INTEGER NOT NULL DEFAULT 0,
				code TEXT NOT NULL DEFAULT '',
				message TEXT NULL,
				trace_id TEXT NOT NULL DEFAULT '',
				occurred_at TEXT NOT NULL,
				context TEXT NOT NULL DEFAULT ''
			)"
		);
		$db->define(
			'CREATE TABLE ' . self::EVENTS . " (
				event_id INTEGER PRIMARY KEY AUTOINCREMENT,
				account_id INTEGER NULL DEFAULT NULL,
				type TEXT NOT NULL,
				dedup_key TEXT NOT NULL,
				received_at TEXT NOT NULL,
				processed_at TEXT NULL DEFAULT NULL,
				status TEXT NOT NULL DEFAULT 'new',
				attempts INTEGER NOT NULL DEFAULT 0,
				claim_token TEXT NULL DEFAULT NULL,
				claim_until TEXT NULL DEFAULT NULL,
				next_attempt_at TEXT NULL DEFAULT NULL,
				payload TEXT NULL,
				result TEXT NULL,
				source_ip TEXT NOT NULL DEFAULT '',
				ip_trusted INTEGER NOT NULL DEFAULT 0,
				UNIQUE (account_id, dedup_key)
			)"
		);
		$db->define(
			'CREATE TABLE ' . self::TASKS . " (
				task_id INTEGER PRIMARY KEY AUTOINCREMENT,
				account_id INTEGER NOT NULL,
				kind TEXT NOT NULL,
				remote_task_id TEXT NULL DEFAULT NULL,
				status TEXT NOT NULL DEFAULT 'submitted',
				submitted_at TEXT NOT NULL,
				last_polled_at TEXT NULL DEFAULT NULL,
				finished_at TEXT NULL DEFAULT NULL,
				poll_count INTEGER NOT NULL DEFAULT 0,
				item_count INTEGER NOT NULL DEFAULT 0,
				request_hash TEXT NOT NULL DEFAULT '',
				result TEXT NULL
			)"
		);
		$db->define(
			'CREATE TABLE ' . self::TASK_ITEMS . " (
				task_id INTEGER NOT NULL,
				link_id INTEGER NOT NULL,
				offer_id TEXT NOT NULL DEFAULT '',
				status TEXT NOT NULL DEFAULT 'pending',
				errors TEXT NULL,
				PRIMARY KEY (task_id, link_id)
			)"
		);
		$db->define(
			'CREATE TABLE ' . self::ACCOUNTS . " (
				account_id INTEGER PRIMARY KEY AUTOINCREMENT,
				claim_token TEXT NULL DEFAULT NULL,
				claim_until TEXT NULL DEFAULT NULL
			)"
		);
		$db->define(
			'CREATE TABLE ' . self::LINKS . " (
				link_id INTEGER PRIMARY KEY AUTOINCREMENT,
				account_id INTEGER NOT NULL,
				kind TEXT NOT NULL DEFAULT 'offer',
				card_state TEXT NOT NULL DEFAULT 'unlinked',
				sync_price INTEGER NOT NULL DEFAULT 0
			)"
		);
		$db->define(
			'CREATE TABLE ' . self::SYNC_STATE . ' (
				account_id INTEGER NOT NULL,
				link_id INTEGER NOT NULL,
				warehouse_id INTEGER NOT NULL DEFAULT 0,
				kind TEXT NOT NULL,
				pending_payload TEXT NULL,
				pending_rev INTEGER NOT NULL DEFAULT 0,
				pending_since TEXT NULL DEFAULT NULL,
				in_flight_rev INTEGER NULL DEFAULT NULL,
				in_flight_payload TEXT NULL,
				sent_payload TEXT NULL,
				sent_rev INTEGER NOT NULL DEFAULT 0,
				sent_at TEXT NULL DEFAULT NULL,
				attempts INTEGER NOT NULL DEFAULT 0,
				next_attempt_at TEXT NULL DEFAULT NULL,
				last_error TEXT NULL,
				remote_present INTEGER NULL DEFAULT NULL,
				remote_reserved INTEGER NULL DEFAULT NULL,
				remote_read_at TEXT NULL DEFAULT NULL,
				PRIMARY KEY (account_id, link_id, warehouse_id, kind)
			)'
		);
	}
}
