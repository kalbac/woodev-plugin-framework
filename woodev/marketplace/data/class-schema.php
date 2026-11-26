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
	 * The revision of the whole table set, owned by the framework and independent of any plugin's version: it is raised whenever a
	 * statement below changes (a table or a column is added), so that {@see Lifecycle} brings a site that already has the tables up to
	 * date on its next request — whichever plugin's bundled framework copy won the bootstrap, and whether or not the plugin that owns
	 * the tables was itself updated. A site that never stored one is at revision 0.
	 *
	 * 1: `bulk_runs` and `sync_state.pending_source` (2.1.8–2.1.10).
	 * 2: `cards`, the stored list of the marketplace's cards (#1863).
	 * 3: `links.pending_offer_id`, `links.rename_error` and `links.rename_confirmed` (the article changed after export, #1860).
	 * 4: `postings.processing_since`, when the marketplace says the posting entered processing (the stuck postings of the analytics, #1933).
	 * 5: `link_barcodes`, the barcodes of a unit, and `cards.unit_card` / `cards.unit_article` / `cards.unit_data`, the card a unit
	 * belongs to and its current article (#2000).
	 * 6: `sync_state.task_id`, `sync_state.refused_payload` and `sync_state.error_code`, a price the marketplace decides on later
	 * (#1998).
	 * 7: `seller_actions`, the actions sent to the marketplace for the units of orders, so none is sent twice (#2003).
	 * 8: `shipments` and `shipment_units`, the shop's copy of the seller's shipments (#2006); the changes of shipments are
	 * claimed in `seller_actions`.
	 *
	 * @since 2.1.10
	 *
	 * @var int
	 */
	public const REVISION = 8;

	/**
	 * The columns that a table of an earlier revision lacks and a revision above added, by table name without any prefix. {@see Lifecycle}
	 * looks every one of them up before it stores a revision: `dbDelta` returns normally when its `ALTER TABLE … ADD COLUMN` is refused
	 * and the table still exists, so the table names alone cannot tell a finished upgrade from a failed one. A new table needs no entry
	 * (its existence is checked); a column added to an existing table gets one in the same change that raises {@see self::REVISION}.
	 *
	 * 1: `sync_state.pending_source`.
	 * 3: `links.pending_offer_id`, `links.rename_error`, `links.rename_confirmed`.
	 * 4: `postings.processing_since`.
	 * 5: `cards.unit_card`, `cards.unit_article`, `cards.unit_data`.
	 * 6: `sync_state.task_id`, `sync_state.refused_payload`, `sync_state.error_code`.
	 *
	 * @since 2.1.10
	 *
	 * @var array<string,string[]>
	 */
	private const REQUIRED_COLUMNS = [
		'sync_state' => [ 'pending_source', 'task_id', 'refused_payload', 'error_code' ],
		'links'      => [ 'pending_offer_id', 'rename_error', 'rename_confirmed' ],
		'postings'   => [ 'processing_since' ],
		'cards'      => [ 'unit_card', 'unit_article', 'unit_data' ],
	];

	/**
	 * Names of the tables, without any prefix, in creation order.
	 *
	 * @var string[]
	 */
	private const TABLES = [
		'accounts',
		'links',
		'link_ids',
		'link_barcodes',
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
		'bulk_runs',
		'cards',
		'seller_actions',
		'shipments',
		'shipment_units',
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
	 * Returns the columns a revision-current table must have beyond its creation, see {@see self::REQUIRED_COLUMNS}.
	 *
	 * @since 2.1.10
	 *
	 * @param string $prefix `$wpdb->prefix`.
	 * @return array<string,string[]> Full table name => column names.
	 */
	public function required_columns( string $prefix ): array {
		$columns = [];

		foreach ( self::REQUIRED_COLUMNS as $table => $names ) {
			$columns[ $this->table_name( $table, $prefix ) ] = $names;
		}

		return $columns;
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
	 * never makes a product push to a live marketplace on its own. The marketplace's identifiers are in `link_ids`. `offer_id` is
	 * the seller article, as wide as the widest article policy an adapter may declare. `pending_offer_id` is the article the merchant
	 * changed a sent card to and the marketplace has not confirmed yet; `rename_error` is the marketplace's answer when it refused (the
	 * old `offer_id` stays in force), kept apart from `last_error_*` so an export or a poll never wipes it; `rename_confirmed` says the marketplace already changed the card, so only the shop's own move is still to do and the request is never sent again.
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
			pending_offer_id varchar(191) NULL DEFAULT NULL,
			rename_error text NULL,
			rename_confirmed tinyint(1) NOT NULL DEFAULT 0,
			last_pushed_stock int(11) NULL DEFAULT NULL,
			last_pushed_stock_at datetime NULL DEFAULT NULL,
			last_pushed_price decimal(12,2) NULL DEFAULT NULL,
			last_pushed_price_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (link_id),
			UNIQUE KEY account_product (account_id,wc_product_id),
			UNIQUE KEY account_offer (account_id,offer_id),
			KEY account_state (account_id,card_state),
			KEY wc_parent (wc_parent_id)
		) {$charset};";
	}

	/**
	 * The remote identifiers of a link, one row per name, whatever names the adapter uses (`product_id`, `nmID`, …). `id_value` is
	 * text and `id_type` (`i` or `s`) says whether the caller gave an integer or a string, so a value comes back as it went in.
	 * `account_id` repeats the link's account so that the lookup by identifier is one indexed read. Name and value are compared
	 * byte for byte (`utf8mb4_bin`, charset spelled out so a site on another charset still accepts it): the site's usual collation
	 * is case-insensitive and would make `shop-A` and `shop-a` one identifier. `dbDelta` reads only the type and the default, so
	 * the clause does not make a second run change anything.
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function link_ids( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			link_id bigint(20) unsigned NOT NULL,
			account_id bigint(20) unsigned NOT NULL,
			id_name varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			id_value varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			id_type char(1) NOT NULL DEFAULT 's',
			PRIMARY KEY  (link_id,id_name),
			KEY account_lookup (account_id,id_name,id_value)
		) {$charset};";
	}

	/**
	 * The barcodes of a link whose marketplace gives a unit several (a size of a card): the whole list in the marketplace's order,
	 * kept byte for byte (`utf8mb4_bin`) — a barcode is a free string, and only some are GTINs. A barcode names one unit of an
	 * account, hence the primary key; `position` orders the list.
	 *
	 * @since 2.1.23
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function link_barcodes( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			link_id bigint(20) unsigned NOT NULL,
			account_id bigint(20) unsigned NOT NULL,
			barcode varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			position int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (account_id,barcode),
			KEY link (link_id,position)
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
			pending_source longtext NULL,
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
			task_id bigint(20) unsigned NULL DEFAULT NULL,
			refused_payload longtext NULL,
			error_code varchar(32) NULL DEFAULT NULL,
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
			processing_since datetime NULL DEFAULT NULL,
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

	/**
	 * Bulk runs: one row per account, the active run or the last finished one (#1795). `tick` is the token of the tick that owns the
	 * run now and fences every save; `cancel` is written only by «Остановить». `payload`, `filter` and `cursor` are JSON; `items` is
	 * the frozen card list of a remote-cards run. `cursor` is a reserved word of MySQL, so the statement quotes it.
	 *
	 * @since 2.1.8
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function bulk_runs( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			account_id bigint(20) unsigned NOT NULL,
			run_id char(32) NOT NULL,
			state varchar(16) NOT NULL DEFAULT 'running',
			cancel tinyint(1) NOT NULL DEFAULT 0,
			tick char(32) NULL DEFAULT NULL,
			action varchar(64) NOT NULL,
			payload longtext NULL,
			filter longtext NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			`cursor` longtext NULL,
			items longtext NULL,
			counts longtext NULL,
			report longtext NULL,
			started_at datetime NOT NULL,
			finished_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (account_id)
		) {$charset};";
	}

	/**
	 * The marketplace's cards as the «Карточки» screen lists them, one lean row per card (#1863): only what the screen filters, searches,
	 * sorts and shows. Kept for good, refreshed by the list job, a daily run and the card notifications ({@see Wpdb_Card_Store}).
	 *
	 * `card_key` is the hash of the article the screen's row ids carry, so a row is found by its id through the primary key. `seen_run` is
	 * the generation of the full refresh that last saw the card: a refresh sweeps the rows it did not see only after its last page, so the
	 * screen never empties mid-refresh. `position` keeps the marketplace's order of the last full refresh. `remote_ids` is the JSON of the
	 * card's identifiers, `price` and `stock` are null when the marketplace did not say.
	 *
	 * A marketplace that sells units lists one row per unit: `unit_card` is the hash of the card the unit belongs to ('' for a row
	 * that stands alone), `unit_article` the card's seller article as it is now (`offer_id` keeps the key frozen at linking) and
	 * `unit_data` the JSON of the unit's `barcodes` and `unit`, which the match keys read.
	 *
	 * @since 2.1.13
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function cards( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			account_id bigint(20) unsigned NOT NULL,
			card_key char(32) NOT NULL,
			offer_id varchar(191) NOT NULL,
			name varchar(500) NOT NULL DEFAULT '',
			image varchar(500) NOT NULL DEFAULT '',
			state varchar(24) NOT NULL DEFAULT '',
			is_created tinyint(1) NOT NULL DEFAULT 0,
			failed tinyint(1) NOT NULL DEFAULT 0,
			archived tinyint(1) NOT NULL DEFAULT 0,
			details_ok tinyint(1) NOT NULL DEFAULT 0,
			status_text text NULL,
			price decimal(14,2) NULL DEFAULT NULL,
			stock int(11) NULL DEFAULT NULL,
			remote_ids varchar(255) NOT NULL DEFAULT '',
			group_key varchar(64) NOT NULL DEFAULT '',
			group_size int(10) unsigned NOT NULL DEFAULT 0,
			unit_card varchar(32) NOT NULL DEFAULT '',
			unit_article varchar(191) NOT NULL DEFAULT '',
			unit_data text NULL,
			position int(10) unsigned NOT NULL DEFAULT 0,
			seen_run int(10) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (account_id,card_key),
			KEY account_position (account_id,position),
			KEY account_state (account_id,state),
			KEY account_seen (account_id,seen_run),
			KEY account_unit (account_id,unit_card)
		) {$charset};";
	}

	/**
	 * One row per action sent to the marketplace for a unit of an order ({@see Wpdb_Seller_Action_Store}): the identity of what
	 * was sent, its state, and until when it keeps the same action from being sent again. `unit_key` is the MD5 of the
	 * marketplace's unit id.
	 *
	 * @since 2.1.27
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function seller_actions( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			account_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			unit_key char(32) NOT NULL,
			action varchar(32) NOT NULL,
			identity char(32) NOT NULL,
			state varchar(8) NOT NULL,
			claim_token char(32) NOT NULL,
			claim_until datetime NOT NULL,
			PRIMARY KEY  (account_id,order_id,unit_key,action),
			KEY claim_token (claim_token),
			KEY account_expiry (account_id,claim_until)
		) {$charset};";
	}

	/**
	 * The shop's copy of the seller's shipments ({@see Wpdb_Shipment_Store}), one row per (account, shipment), so that the screen
	 * never reads the marketplace. `shipment_key` is the MD5 of the marketplace's shipment id, which the screen's row ids carry.
	 * `seen_run` is the generation of the full refresh that last listed it: a shipment the marketplace no longer lists goes after
	 * the last page. `is_stale` marks a shipment whose units still have to be read; `read_attempts` / `retry_at` put off one that
	 * could not be read. `lock_token` / `lock_until` hold a shipment
	 * while an action on it is at the marketplace.
	 *
	 * @since 2.1.28
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function shipments( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			account_id bigint(20) unsigned NOT NULL,
			shipment_key char(32) NOT NULL,
			remote_id varchar(191) NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			is_open tinyint(1) NOT NULL DEFAULT 0,
			status_label varchar(191) NOT NULL DEFAULT '',
			kind_label varchar(191) NOT NULL DEFAULT '',
			shipping_label varchar(500) NOT NULL DEFAULT '',
			created_at datetime NULL DEFAULT NULL,
			closed_at datetime NULL DEFAULT NULL,
			facts text NULL,
			cargo longtext NULL,
			unit_count int(10) unsigned NOT NULL DEFAULT 0,
			blocking_count int(10) unsigned NOT NULL DEFAULT 0,
			warning_count int(10) unsigned NOT NULL DEFAULT 0,
			reship_count int(10) unsigned NOT NULL DEFAULT 0,
			is_stale tinyint(1) NOT NULL DEFAULT 1,
			read_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			retry_at datetime NULL DEFAULT NULL,
			read_at datetime NULL DEFAULT NULL,
			seen_run int(10) unsigned NOT NULL DEFAULT 0,
			lock_token char(32) NOT NULL DEFAULT '',
			lock_until datetime NULL DEFAULT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (account_id,shipment_key),
			KEY account_list (account_id,is_open,created_at),
			KEY account_seen (account_id,seen_run),
			KEY account_stale (account_id,is_stale)
		) {$charset};";
	}

	/**
	 * The units of each shipment ({@see Wpdb_Shipment_Store}), with what is missing on each (`requirements`, JSON) and the
	 * WooCommerce order it belongs to (0 when the shop has none).
	 *
	 * @since 2.1.28
	 *
	 * @param string $table   Full table name.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private function shipment_units( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
			account_id bigint(20) unsigned NOT NULL,
			shipment_key char(32) NOT NULL,
			unit_key char(32) NOT NULL,
			unit_id varchar(191) NOT NULL,
			posting_key varchar(191) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			label varchar(500) NOT NULL DEFAULT '',
			status_label varchar(191) NOT NULL DEFAULT '',
			is_movable tinyint(1) NOT NULL DEFAULT 0,
			needs_reshipment tinyint(1) NOT NULL DEFAULT 0,
			requirements longtext NULL,
			facts text NULL,
			position int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (account_id,shipment_key,unit_key),
			KEY account_unit (account_id,unit_key),
			KEY account_order (account_id,order_id)
		) {$charset};";
	}
}
