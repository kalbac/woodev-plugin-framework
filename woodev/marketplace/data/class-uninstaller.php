<?php
/**
 * Removal of a marketplace plugin's data when the merchant deletes the plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Drops the plugin's tables, its product meta, its option family and its transients — those in the options table and those a
 * persistent object cache holds alone.
 *
 * Called only by the plugin's `uninstall.php`, which WordPress runs when the plugin is deleted from the Plugins screen.
 * Deactivation never reaches this class. It needs no loaded plugin: the caller gives the marketplace id and the plugin id.
 *
 * The options go with the tables: `woodev_{plugin id}_version` would otherwise survive and make a later reinstall look like an
 * installed plugin, so the tables would not be created again.
 *
 * @since 2.0.29
 */
final class Uninstaller {
	/**
	 * Names the option family of a plugin: `woodev_` plus the plugin id, which is also what `Woodev_Plugin` uses.
	 *
	 * @since 2.0.29
	 *
	 * @param string $plugin_id Plugin id, e.g. `ozon-seller`.
	 * @return string e.g. `woodev_ozon-seller_`.
	 */
	public static function option_prefix( string $plugin_id ): string {
		return 'woodev_' . $plugin_id . '_';
	}

	/**
	 * Drops the tables and deletes the product meta and the options.
	 *
	 * Options go through `delete_option()`, not a direct `DELETE`: with a persistent object cache the cached `…_version` would
	 * survive the row, a reinstall would read it as "already installed" and never recreate the tables.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb    $wpdb         Database handle.
	 * @param Schema   $schema       The marketplace's schema.
	 * @param string   $plugin_id    Plugin id (the option family).
	 * @param string[] $extra_tables Full names of tables the plugin owns beyond the framework's set (its v1 tables).
	 * @return void
	 */
	public static function run( $wpdb, Schema $schema, string $plugin_id, array $extra_tables = [] ): void {
		self::clear_transients( $wpdb, $schema, $plugin_id );

		foreach ( array_merge( $schema->table_names( $wpdb->prefix ), $extra_tables ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		// The articles chosen on the product tab live on the products; they go with the plugin.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( Pinned_Offer_Id::meta_prefix( $schema->marketplace_id() ) ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$family = $wpdb->esc_like( self::option_prefix( $plugin_id ) ) . '%';
		$names  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $family, '\\_transient\\_' . $family, '\\_transient\\_timeout\\_' . $family ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $names as $name ) {
			delete_option( $name );
		}
	}

	/**
	 * Deletes the per-account transients through the transient API, while the accounts table that names the accounts still exists.
	 *
	 * With a persistent object cache WordPress keeps a transient only in the cache, so the `LIKE` on the options table below cannot
	 * find it; a reinstall that reuses an account id would show the deleted cabinet's snapshot.
	 *
	 * @param \wpdb  $wpdb      Database handle.
	 * @param Schema $schema    The marketplace's schema.
	 * @param string $plugin_id Plugin id (the option family).
	 * @return void
	 */
	private static function clear_transients( $wpdb, Schema $schema, string $plugin_id ): void {
		$snapshots = new Cards_Snapshot_Store( self::option_prefix( $plugin_id ) );
		$previous  = $wpdb->suppress_errors( true );
		$ids       = $wpdb->get_col( $wpdb->prepare( 'SELECT account_id FROM %i', $schema->table_name( 'accounts', $wpdb->prefix ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->suppress_errors( $previous );

		foreach ( (array) $ids as $account_id ) {
			$snapshots->clear( (int) $account_id );
		}
	}
}
