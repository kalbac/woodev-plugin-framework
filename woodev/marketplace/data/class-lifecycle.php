<?php
/**
 * Lifecycle of a marketplace plugin: installs and upgrades its database schema.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Marketplace_Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the plugin's tables on install and brings them up to date on every upgrade.
 *
 * It is a `Woodev_Lifecycle` like any other: the framework calls {@see self::install()} on a first run and
 * {@see self::upgrade()} when the stored version is older than the plugin's. `dbDelta` changes only the difference between a
 * statement and the live table, so running it on every upgrade is how a later framework release adds a column.
 *
 * Plugin-specific work (a data migration from an older plugin version) hooks the `woodev_{plugin id}_installed` and
 * `woodev_{plugin id}_updated` actions the parent fires **after** the tables exist; it does not subclass this class.
 *
 * Deactivation drops nothing: tables are removed only by {@see Uninstaller}, when the merchant deletes the plugin.
 *
 * @since 2.0.29
 */
final class Lifecycle extends \Woodev_Lifecycle {
	/**
	 * Suffix of the id of the admin notice shown while the tables cannot be created; the plugin id goes in front.
	 *
	 * @var string
	 */
	public const NOTICE_SCHEMA_FAILED = 'schema_failed';

	/**
	 * Seconds after which a first-install lock counts as left by a crashed request.
	 *
	 * @var int
	 */
	private const INSTALL_LOCK_TTL = 60;

	/**
	 * Whether the last {@see self::run_lifecycle()} ended in a refused CREATE.
	 *
	 * @var bool
	 */
	private $install_failed = false;

	/**
	 * Schema; resolved from the plugin's adapter on first use when none was given.
	 *
	 * @var Schema|null
	 */
	private $schema;

	/**
	 * Creates the handler.
	 *
	 * @since 2.0.29
	 *
	 * @param \Woodev_Plugin $plugin Plugin.
	 * @param Schema|null    $schema The tables to create; by default the set of the plugin's adapter id (the plugin must then be
	 *                               a {@see Marketplace_Plugin}).
	 */
	public function __construct( \Woodev_Plugin $plugin, ?Schema $schema = null ) {
		$this->schema = $schema;

		parent::__construct( $plugin );
	}

	/**
	 * Runs the framework lifecycle.
	 *
	 * A {@see Schema_Exception} (a table could not be created) leaves before the parent stores the new version, so the install or
	 * upgrade is tried again on the next admin page load and no `…_installed` action runs against missing tables. The merchant
	 * gets an error notice. Any other exception is not ours and goes up.
	 *
	 * The FIRST install runs under a per-plugin lock: two requests arriving right after activation (a REST call and a cron
	 * event, or two admin tabs) would otherwise both run `dbDelta` and the `…_installed` callbacks. The request that does not get
	 * the lock skips the install (readers of the tables already tolerate their absence) and the winner stores the version.
	 *
	 * @since 2.0.29
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->is_installed() ) {
			$this->run_lifecycle();

			return;
		}

		$token = $this->acquire_install_lock();

		if ( null === $token ) {
			return;
		}

		try {
			// Another request may have finished between the check above and the lock.
			if ( ! $this->is_installed() ) {
				$this->run_lifecycle();
			}
		} finally {
			$this->release_install_lock( $token );
		}
	}

	/**
	 * Also installs from a request that never reaches wp-admin.
	 *
	 * The parent runs the lifecycle only on an admin page load, but a marketplace plugin reads its tables from a REST
	 * request, a WP-CLI command or a cron event, and the first of those after a fresh activation arrives before any admin
	 * page: without tables every read fails, and with `WP_DEBUG_DISPLAY` the database error is printed into the REST
	 * response. So when no version is stored yet, the install runs on an early `init`. Once a version is stored this is
	 * one `get_option()` and nothing else; an admin page load is left to the parent's own hook.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	protected function add_hooks() {
		parent::add_hooks();

		add_action( 'init', [ $this, 'install_outside_admin' ], 1 );
	}

	/**
	 * Runs {@see self::init()} on a request that is not an admin page load, until a version is stored.
	 *
	 * A failed install (a database user without CREATE) stores no version, so every such request would try `dbDelta` again;
	 * a short mark keeps that to once in a few minutes. An admin page load is not held back by the mark.
	 *
	 * @internal Hook callback.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function install_outside_admin(): void {
		if ( ( is_admin() && ! wp_doing_ajax() ) || '' !== (string) $this->get_installed_version() ) {
			return;
		}

		$mark = 'woodev_' . $this->get_plugin()->get_id() . '_schema_retry';

		if ( get_transient( $mark ) ) {
			return;
		}

		$this->install_failed = false;

		$this->init();

		// Only a failed CREATE is marked: a request that lost the install lock to another one is not a failure.
		if ( $this->install_failed ) {
			set_transient( $mark, 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Whether the lifecycle has stored a version, that is, the tables were created at least once.
	 *
	 * Cheap (one option read): the menu uses it to leave the tables alone on a site that is not installed yet.
	 *
	 * @since 2.1.2
	 *
	 * @return bool
	 */
	public function is_installed(): bool {
		return '' !== (string) $this->get_installed_version();
	}

	/**
	 * The parent lifecycle with a refused CREATE turned into a log line and a notice.
	 *
	 * @return void
	 */
	private function run_lifecycle(): void {
		try {
			parent::init();
		} catch ( Schema_Exception $exception ) {
			$this->install_failed = true;
			$this->report_schema_failure( $exception );
		}
	}

	/**
	 * Takes the first-install lock: an `options` row that `INSERT IGNORE` can create for one request only, the way core's
	 * `WP_Upgrader::create_lock()` does it. A row older than the TTL is a crashed request's; of two requests finding it, the
	 * compare-and-set lets one take it over.
	 *
	 * @return string|null The token to release it with, or null when another request holds it.
	 */
	private function acquire_install_lock(): ?string {
		global $wpdb;

		$name  = $this->install_lock_name();
		$now   = time();
		$token = $now . '|' . bin2hex( random_bytes( 8 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- An atomic lock cannot go through the options API.
		$inserted = $wpdb->query(
			$wpdb->prepare( "INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' )", $name, $token )
		);

		if ( $inserted ) {
			return $token;
		}

		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $name ) );

		if ( '' === $held || (int) $held > $now - self::INSTALL_LOCK_TTL ) {
			return null;
		}

		$taken = $wpdb->query(
			$wpdb->prepare( "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $held )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $taken ? $token : null;
	}

	/**
	 * Releases the first-install lock, only when this request still owns it.
	 *
	 * @param string $token What {@see self::acquire_install_lock()} returned.
	 * @return void
	 */
	private function release_install_lock( string $token ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND option_value = %s", $this->install_lock_name(), $token ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * @return string The `options` row that holds the lock of this plugin.
	 */
	private function install_lock_name(): string {
		return 'woodev_' . $this->get_plugin()->get_id() . '_install_lock';
	}

	/**
	 * First install: creates the tables.
	 *
	 * @since 2.0.29
	 *
	 * @return void
	 */
	protected function install(): void {
		$this->create_tables();
	}

	/**
	 * Upgrade: brings the tables up to date, then runs the plugin's own `upgrade_to_*` routines, if any.
	 *
	 * @since 2.0.29
	 *
	 * @param string $installed_version Version that was installed before.
	 * @return void
	 */
	protected function upgrade( string $installed_version ): void {
		$this->create_tables();

		parent::upgrade( $installed_version );
	}

	/**
	 * Creates or brings up to date every table, and proves they exist.
	 *
	 * `dbDelta` compares each statement with the live table and changes only the difference, so calling this again on an
	 * unchanged schema writes nothing and loses no rows. It also returns normally when a `CREATE` fails (for example a database
	 * user without the CREATE privilege), so its return value proves nothing: the tables are looked up afterwards.
	 *
	 * @since 2.0.29
	 *
	 * @return void
	 * @throws Schema_Exception When a table is still missing after `dbDelta`.
	 */
	public function create_tables(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( array_values( $this->get_schema()->statements( $wpdb->prefix, $wpdb->get_charset_collate() ) ) );

		$missing = $this->missing_tables();
		if ( [] !== $missing ) {
			// The message only reaches the plugin log; the merchant sees a fixed notice.
			throw new Schema_Exception( 'Tables missing after dbDelta: ' . implode( ', ', $missing ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Returns the schema this handler creates.
	 *
	 * @return Schema
	 * @throws \LogicException When no schema was given and the plugin is not a marketplace plugin.
	 */
	private function get_schema(): Schema {
		if ( null === $this->schema ) {
			$plugin = $this->get_plugin();

			if ( ! $plugin instanceof Marketplace_Plugin ) {
				throw new \LogicException( 'The marketplace lifecycle needs a Schema or a Marketplace_Plugin.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}

			$this->schema = new Schema( $plugin->get_marketplace_adapter()->id() );
		}

		return $this->schema;
	}

	/**
	 * Lists the plugin tables the database does not have.
	 *
	 * Names are compared in lower case: on a server with `lower_case_table_names` the database reports them lowered while the site
	 * prefix may carry capitals.
	 *
	 * @return string[] Full names of the missing tables.
	 */
	private function missing_tables(): array {
		global $wpdb;

		$schema = $this->get_schema();
		$like   = $wpdb->esc_like( $wpdb->prefix . $schema->table_prefix() ) . '%';
		$found  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found  = array_map( 'strtolower', is_array( $found ) ? $found : [] );

		$missing = [];
		foreach ( $schema->table_names( $wpdb->prefix ) as $table ) {
			if ( ! in_array( strtolower( $table ), $found, true ) ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	/**
	 * Logs a failed schema creation and tells the merchant, once per page load, that it will be retried.
	 *
	 * @param Schema_Exception $exception What failed.
	 * @return void
	 */
	private function report_schema_failure( Schema_Exception $exception ): void {
		$plugin = $this->get_plugin();

		$plugin->log( 'Schema creation failed and will run again on the next admin page load. ' . $exception->getMessage() );

		$plugin->get_admin_notice_handler()->add_admin_notice(
			sprintf(
				/* translators: %s: the plugin's name. */
				__( '<strong>%s:</strong> не удалось создать таблицы плагина в базе данных. Чаще всего у пользователя базы данных нет права CREATE — попросите хостинг его выдать. Попытка повторится при следующем открытии админки; если сообщение не исчезает, напишите в поддержку.', 'woodev-plugin-framework' ),
				esc_html( $plugin->get_plugin_name() )
			),
			'woodev_' . $plugin->get_id() . '_' . self::NOTICE_SCHEMA_FAILED,
			[
				'notice_class'            => 'notice-error',
				'dismissible'             => false,
				'always_show_on_settings' => true,
			]
		);
	}
}
