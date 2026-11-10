<?php
/**
 * Database schema of a marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The tables of one marketplace plugin as `dbDelta`-ready `CREATE TABLE` statements.
 *
 * **One table set per marketplace.** Every table is named `{$wpdb->prefix}woodev_{marketplace id}_{table}` (marketplace id = the
 * adapter's id, e.g. `ozon`), so two marketplace plugins on one site never share a row. The id is the only thing that tells the
 * sets apart; no column or enum value names a marketplace.
 *
 * Conventions, all of them forced by how `dbDelta` compares an existing table with a statement:
 *
 * - A "json" column is `longtext`. MariaDB reports its `json` alias as `longtext`, so a `json` column would be "changed" by every
 *   `dbDelta` run and the second run would no longer be a no-op. The value is still JSON; the code that reads it decodes it.
 * - No `DEFAULT CURRENT_TIMESTAMP`: MariaDB reports it as `current_timestamp()`, the same endless "change". Times are UTC and
 *   supplied by the code; a column the row cannot exist without is `NOT NULL` with no default.
 * - Integer columns state their display width (`bigint(20) unsigned`), as WordPress core's own tables do; the tests pin every
 *   integer type to that form.
 * - Two spaces after `PRIMARY KEY`, `KEY` (never `INDEX`), one column per line, no `IF NOT EXISTS`.
 * - `lines` is a reserved word of MySQL, so every statement quotes it.
 *
 * The class has no WordPress dependency except the `$wpdb` values it is handed, so `uninstall.php` can use the table lists while
 * the plugin itself is not loaded.
 *
 * @since 2.0.29
 */
final class Schema {
	/**
	 * Longest marketplace id: the longest table name (`{prefix}woodev_{id}_catalog_cache`) must stay under MySQL's 64 characters
	 * for a site prefix of up to 20.
	 *
	 * @var int
	 */
	public const MAX_ID_LENGTH = 16;

	/**
	 * Names of the tables, without any prefix, in creation order.
	 *
	 * @var string[]
	 */
	private const TABLES = [
		'accounts',
		'links',
		'tasks',
		'task_items',
		'events',
		'sync_state',
		'postings',
		'documents',
		'throttle',
		'api_errors',
		'mappings',
		'mapping_terms',
		'profiles',
		'catalog_cache',
	];

	/**
	 * Marketplace id.
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * Creates the schema of one marketplace.
	 *
	 * @since 2.0.29
	 *
	 * @param string $marketplace_id The adapter's id: lower-case letters, digits and `_`, starting with a letter.
	 * @throws \InvalidArgumentException When the id cannot be part of a table name.
	 */
	public function __construct( string $marketplace_id ) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $marketplace_id ) || strlen( $marketplace_id ) > self::MAX_ID_LENGTH ) {
			throw new \InvalidArgumentException( 'A marketplace id is lower-case letters, digits and "_", starts with a letter and has at most ' . (int) self::MAX_ID_LENGTH . ' characters.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$this->marketplace_id = $marketplace_id;
	}

	/**
	 * Marketplace id.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function marketplace_id(): string {
		return $this->marketplace_id;
	}

	/**
	 * Prefix every table gets after `$wpdb->prefix`.
	 *
	 * @since 2.0.29
	 *
	 * @return string e.g. `woodev_ozon_`.
	 */
	public function table_prefix(): string {
		return 'woodev_' . $this->marketplace_id . '_';
	}

	/**
	 * Returns the full name of a table.
	 *
	 * @since 2.0.29
	 *
	 * @param string $table  Table name without any prefix, e.g. `links`.
	 * @param string $prefix `$wpdb->prefix`.
	 * @return string
	 */
	public function table_name( string $table, string $prefix ): string {
		return $prefix . $this->table_prefix() . $table;
	}

	/**
	 * Returns the full names of all tables, in creation order.
	 *
	 * @since 2.0.29
	 *
	 * @param string $prefix `$wpdb->prefix`.
	 * @return string[]
	 */
	public function table_names( string $prefix ): array {
		$names = [];

		foreach ( self::TABLES as $table ) {
			$names[] = $this->table_name( $table, $prefix );
		}

		return $names;
	}

	/**
	 * Returns one `CREATE TABLE` statement per table.
	 *
	 * @since 2.0.29
	 *
	 * @param string $prefix  `$wpdb->prefix`.
	 * @param string $charset `$wpdb->get_charset_collate()`.
	 * @return array<string,string> Full table name => statement, in creation order.
	 */
	public function statements( string $prefix, string $charset ): array {
		$statements = [];

		foreach ( self::TABLES as $table ) {
			$name = $this->table_name( $table, $prefix );

			$statements[ $name ] = $this->$table( $name, $charset );
		}

		return $statements;
	}

	/**
	 * One row per seller cabinet. `external_id` is the cabinet's identifier from the adapter's credential fields (unique);
	 * `secret_enc` is the encrypted JSON of its secret fields, and `secret_fingerprint` identifies that set without opening it.
	 * `claim_*` is the account-level claim of the run model (the single stock / price flusher and the validator).
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function accounts( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			account_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL DEFAULT '',
			external_id varchar(64) NOT NULL,
			secret_enc text NOT NULL,
			secret_fingerprint char(16) NOT NULL DEFAULT '',
			is_enabled tinyint(1) NOT NULL DEFAULT 0,
			health varchar(16) NOT NULL DEFAULT 'unknown',
			health_message text NULL,
			health_checked_at datetime NULL DEFAULT NULL,
			key_expires_at datetime NULL DEFAULT NULL,
			seller_id bigint(20) unsigned NULL DEFAULT NULL,
			currency char(3) NOT NULL DEFAULT '',
			warehouse_id bigint(20) unsigned NULL DEFAULT NULL,
			stock_mode varchar(16) NOT NULL DEFAULT 'site',
			price_direction varchar(16) NOT NULL DEFAULT 'to_marketplace',
			orders_enabled tinyint(1) NOT NULL DEFAULT 1,
			import_enabled tinyint(1) NOT NULL DEFAULT 0,
			notification_token char(32) NOT NULL,
			notification_state longtext NULL,
			settings longtext NULL,
			claim_token char(32) NULL DEFAULT NULL,
			claim_until datetime NULL DEFAULT NULL,
			PRIMARY KEY  (account_id),
			UNIQUE KEY external_id (external_id)
		) {$charset};";
	}

	/**
	 * The product link store. The two per-product switches default to off: a link creator turns them on explicitly, so the schema
	 * never makes a product push to a live marketplace on its own. The remote ids are the three slots of the link value
	 * (`product_id`, `sku`, `model_id`); an adapter maps its own identifiers onto them. `offer_id` is the seller article, as wide
	 * as the widest article policy an adapter may declare.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function links( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			link_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_id bigint(20) unsigned NOT NULL,
			wc_product_id bigint(20) unsigned NOT NULL,
			wc_parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(8) NOT NULL DEFAULT 'offer',
			offer_id varchar(191) NULL DEFAULT NULL,
			remote_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			remote_sku bigint(20) unsigned NOT NULL DEFAULT 0,
			remote_model_id bigint(20) unsigned NOT NULL DEFAULT 0,
			card_state varchar(24) NOT NULL DEFAULT 'unlinked',
			is_created tinyint(1) NOT NULL DEFAULT 0,
			sync_stock tinyint(1) NOT NULL DEFAULT 0,
			sync_price tinyint(1) NOT NULL DEFAULT 0,
			warehouse_id bigint(20) unsigned NULL DEFAULT NULL,
			is_oversize tinyint(1) NOT NULL DEFAULT 0,
			price_rule_override longtext NULL,
			attribute_overrides longtext NULL,
			mapping_id bigint(20) unsigned NULL DEFAULT NULL,
			last_task_id bigint(20) unsigned NULL DEFAULT NULL,
			last_error_code varchar(64) NULL DEFAULT NULL,
			last_error_text text NULL,
			last_pushed_stock int(11) NULL DEFAULT NULL,
			last_pushed_stock_at datetime NULL DEFAULT NULL,
			last_pushed_price decimal(12,2) NULL DEFAULT NULL,
			last_pushed_price_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (link_id),
			UNIQUE KEY account_product (account_id,wc_product_id),
			UNIQUE KEY account_offer (account_id,offer_id),
			KEY account_state (account_id,card_state),
			KEY account_sku (account_id,remote_sku),
			KEY account_remote_product (account_id,remote_product_id),
			KEY wc_parent (wc_parent_id)
		) {$charset};";
	}

	/**
	 * The async task ledger (card imports, price tasks and label tasks).
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function tasks( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			task_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_id bigint(20) unsigned NOT NULL,
			kind varchar(16) NOT NULL,
			remote_task_id varchar(64) NULL DEFAULT NULL,
			status varchar(24) NOT NULL DEFAULT 'submitted',
			submitted_at datetime NOT NULL,
			last_polled_at datetime NULL DEFAULT NULL,
			finished_at datetime NULL DEFAULT NULL,
			poll_count int(10) unsigned NOT NULL DEFAULT 0,
			item_count int(10) unsigned NOT NULL DEFAULT 0,
			request_hash char(40) NOT NULL DEFAULT '',
			result longtext NULL,
			PRIMARY KEY  (task_id),
			KEY account_status (account_id,status)
		) {$charset};";
	}

	/**
	 * Per-item results of a task. The unique `(task_id, link_id)` is the primary key.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function task_items( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			task_id bigint(20) unsigned NOT NULL,
			link_id bigint(20) unsigned NOT NULL,
			offer_id varchar(191) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'pending',
			errors longtext NULL,
			PRIMARY KEY  (task_id,link_id),
			KEY link (link_id)
		) {$charset};";
	}

	/**
	 * The inbound notification ledger. `account_id` is null when the URL token matched no account.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function events( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_id bigint(20) unsigned NULL DEFAULT NULL,
			type varchar(48) NOT NULL,
			dedup_key char(40) NOT NULL,
			received_at datetime NOT NULL,
			processed_at datetime NULL DEFAULT NULL,
			status varchar(16) NOT NULL DEFAULT 'new',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			claim_token char(32) NULL DEFAULT NULL,
			claim_until datetime NULL DEFAULT NULL,
			next_attempt_at datetime NULL DEFAULT NULL,
			payload mediumtext NULL,
			result text NULL,
			source_ip varchar(45) NOT NULL DEFAULT '',
			ip_trusted tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (event_id),
			UNIQUE KEY account_dedup (account_id,dedup_key),
			KEY account_status (account_id,status,next_attempt_at),
			KEY received_at (received_at)
		) {$charset};";
	}

	/**
	 * The stock / price coalescing queue, one row per (account, link, warehouse, kind).
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function sync_state( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			account_id bigint(20) unsigned NOT NULL,
			link_id bigint(20) unsigned NOT NULL,
			warehouse_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(8) NOT NULL,
			pending_payload longtext NULL,
			pending_rev int(10) unsigned NOT NULL DEFAULT 0,
			pending_since datetime NULL DEFAULT NULL,
			in_flight_rev int(10) unsigned NULL DEFAULT NULL,
			in_flight_payload longtext NULL,
			sent_payload longtext NULL,
			sent_rev int(10) unsigned NOT NULL DEFAULT 0,
			sent_at datetime NULL DEFAULT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NULL DEFAULT NULL,
			last_error text NULL,
			remote_present int(11) NULL DEFAULT NULL,
			remote_reserved int(11) NULL DEFAULT NULL,
			remote_read_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (account_id,link_id,warehouse_id,kind),
			KEY account_due (account_id,kind,next_attempt_at)
		) {$charset};";
	}

	/**
	 * The remote-order registry, one row per (account, group key). `lines` is quoted: it is a reserved word.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function postings( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			account_id bigint(20) unsigned NOT NULL,
			external_key varchar(100) NOT NULL,
			wc_order_id bigint(20) unsigned NULL DEFAULT NULL,
			members longtext NULL,
			status_raw varchar(32) NOT NULL DEFAULT '',
			is_terminal tinyint(1) NOT NULL DEFAULT 0,
			claim_token char(32) NULL DEFAULT NULL,
			claim_until datetime NULL DEFAULT NULL,
			`lines` longtext NULL,
			baseline tinyint(1) NOT NULL DEFAULT 0,
			first_seen_at datetime NOT NULL,
			refreshed_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (account_id,external_key),
			KEY wc_order (wc_order_id),
			KEY account_terminal (account_id,is_terminal)
		) {$charset};";
	}

	/**
	 * Label files, kept in the database and never under the uploads folder. `external_keys` is the JSON list of the remote-order
	 * keys the file covers.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function documents( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			document_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_id bigint(20) unsigned NOT NULL,
			task_id bigint(20) unsigned NULL DEFAULT NULL,
			external_keys longtext NULL,
			content_type varchar(100) NOT NULL DEFAULT '',
			body longblob NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (document_id),
			KEY created_at (created_at)
		) {$charset};";
	}

	/**
	 * Governor buckets, one row per (account, bucket); `version` is the compare-and-swap counter.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function throttle( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			account_id bigint(20) unsigned NOT NULL,
			bucket varchar(32) NOT NULL,
			state longtext NULL,
			version int(10) unsigned NOT NULL DEFAULT 0,
			disabled_until datetime NULL DEFAULT NULL,
			disabled_reason varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (account_id,bucket)
		) {$charset};";
	}

	/**
	 * The ring buffer of failed calls. `error_id` exists so the ring can be trimmed oldest-first.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function api_errors( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			error_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_id bigint(20) unsigned NULL DEFAULT NULL,
			path varchar(191) NOT NULL DEFAULT '',
			http_status smallint(5) unsigned NOT NULL DEFAULT 0,
			code varchar(64) NOT NULL DEFAULT '',
			message text NULL,
			trace_id varchar(64) NOT NULL DEFAULT '',
			occurred_at datetime NOT NULL,
			context varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (error_id),
			KEY occurred_at (occurred_at)
		) {$charset};";
	}

	/**
	 * Category mappings. `category` is the adapter's category descriptor as JSON (`Mapping::remote_category()`); `wc_product_id` is
	 * null for a term-bound mapping and set for a product-scoped one.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function mappings( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			mapping_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL DEFAULT '',
			wc_product_id bigint(20) unsigned NULL DEFAULT NULL,
			category longtext NULL,
			profile_id bigint(20) unsigned NULL DEFAULT NULL,
			attributes longtext NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (mapping_id),
			KEY wc_product (wc_product_id)
		) {$charset};";
	}

	/**
	 * Term to mapping. A WooCommerce category belongs to at most one mapping, hence the primary key on the term.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function mapping_terms( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			term_id bigint(20) unsigned NOT NULL,
			mapping_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (term_id),
			KEY mapping (mapping_id)
		) {$charset};";
	}

	/**
	 * Export profiles.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function profiles( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			profile_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL DEFAULT '',
			settings longtext NULL,
			PRIMARY KEY  (profile_id)
		) {$charset};";
	}

	/**
	 * Catalogue cache (category tree, attributes, warehouses), gzip JSON.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function catalog_cache( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			cache_key varchar(191) NOT NULL,
			payload longblob NULL,
			fetched_at datetime NOT NULL,
			PRIMARY KEY  (cache_key)
		) {$charset};";
	}
}
