<?php
/**
 * Woodev Abstract Warehouse Store
 *
 * Optional table-backed default implementation of {@see Warehouse_Store} that a
 * plugin MAY extend (decision §6b). The framework deliberately does NOT mint a
 * shared warehouse table: the concrete subclass supplies the table name and the
 * schema, so each plugin keeps full ownership of its storage (yandex extends
 * this over its existing `wc_yandex_delivery_warehouses` table byte-for-byte).
 *
 * The `dbDelta()` helper here is a NEW framework mechanism, not an existing data
 * contract — the subclass remains responsible for the schema's stability.
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §6b.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Abstract_Warehouse_Store' ) ) :

	/**
	 * Table-backed warehouse store base.
	 *
	 * Subclasses provide the table name + schema via {@see get_table_name()} /
	 * {@see get_schema()} and the row<->object mapping via {@see to_row()} /
	 * {@see from_row()}; the CRUD plumbing below is provider-agnostic.
	 *
	 * @since 1.5.0
	 */
	abstract class Abstract_Warehouse_Store implements Warehouse_Store {

		/** @var \wpdb WordPress database access layer */
		protected \wpdb $wpdb;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param \wpdb|null $wpdb database layer; defaults to the global `$wpdb`
		 */
		public function __construct( ?\wpdb $wpdb = null ) {
			if ( null === $wpdb ) {
				global $wpdb;
			}

			$this->wpdb = $wpdb;
		}

		/**
		 * Gets the fully-qualified table name (prefix included) backing this store.
		 *
		 * The framework mints no permanent table; the subclass owns this name.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		abstract protected function get_table_name(): string;

		/**
		 * Gets the `CREATE TABLE` statement handed to `dbDelta()` by {@see install()}.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		abstract protected function get_schema(): string;

		/**
		 * Maps a warehouse to a `column => value` row for insert/update.
		 *
		 * Subclasses own the column layout, so they own this mapping.
		 *
		 * @since 1.5.0
		 *
		 * @param Warehouse $warehouse warehouse to serialize
		 *
		 * @return array<string, mixed>
		 */
		abstract protected function to_row( Warehouse $warehouse ): array;

		/**
		 * Maps a raw database row back into a warehouse value object.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $row raw database row
		 *
		 * @return Warehouse
		 */
		abstract protected function from_row( array $row ): Warehouse;

		/**
		 * Gets the primary-key column name. Override when it is not `id`.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		protected function get_id_column(): string {
			return 'id';
		}

		/**
		 * Creates or migrates the backing table from {@see get_schema()}.
		 *
		 * A new framework mechanism, not an existing contract: the subclass is
		 * responsible for keeping the schema byte-for-byte stable.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function install(): void {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}

			dbDelta( $this->get_schema() );
		}

		/**
		 * Gets a single warehouse by its storage row id.
		 *
		 * @since 1.5.0
		 *
		 * @param int $id storage row id
		 *
		 * @return Warehouse|null the warehouse, or null when no row matches
		 */
		public function get( int $id ): ?Warehouse {
			$table  = $this->get_table_name();
			$id_col = $this->get_id_column();

			$row = $this->wpdb->get_row(
				$this->wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$id_col}` = %d", $id ),
				ARRAY_A
			);

			return is_array( $row ) ? $this->from_row( $row ) : null;
		}

		/**
		 * Gets every stored warehouse.
		 *
		 * @since 1.5.0
		 *
		 * @return Warehouse[] all stored warehouses (possibly empty)
		 */
		public function all(): array {
			$table = $this->get_table_name();

			$rows = $this->wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );

			if ( ! is_array( $rows ) ) {
				return [];
			}

			$warehouses = [];

			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					$warehouses[] = $this->from_row( $row );
				}
			}

			return $warehouses;
		}

		/**
		 * Persists a warehouse, inserting or updating as needed.
		 *
		 * When {@see to_row()} carries a positive id column the existing row is
		 * updated; otherwise a new row is inserted and its id returned.
		 *
		 * @since 1.5.0
		 *
		 * @param Warehouse $warehouse warehouse to store
		 *
		 * @return int storage row id of the saved warehouse
		 */
		public function save( Warehouse $warehouse ): int {
			$table  = $this->get_table_name();
			$id_col = $this->get_id_column();
			$data   = $this->to_row( $warehouse );

			$existing_id = isset( $data[ $id_col ] ) ? (int) $data[ $id_col ] : 0;

			if ( $existing_id > 0 ) {
				$this->wpdb->update( $table, $data, [ $id_col => $existing_id ] );

				return $existing_id;
			}

			$this->wpdb->insert( $table, $data );

			return (int) $this->wpdb->insert_id;
		}

		/**
		 * Deletes a warehouse by its storage row id.
		 *
		 * @since 1.5.0
		 *
		 * @param int $id storage row id
		 *
		 * @return bool true when a row was deleted, false otherwise
		 */
		public function delete( int $id ): bool {
			$table  = $this->get_table_name();
			$id_col = $this->get_id_column();

			$result = $this->wpdb->delete( $table, [ $id_col => $id ] );

			return false !== $result && $result > 0;
		}
	}

endif;
