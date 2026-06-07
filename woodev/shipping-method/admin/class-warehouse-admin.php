<?php
/**
 * Woodev Shipping — warehouse CRUD admin UI.
 *
 * @package Woodev\Framework\Shipping\Admin
 */

namespace Woodev\Framework\Shipping\Admin;

use Woodev\Framework\Shipping\Pickup\Warehouse;
use Woodev\Framework\Shipping\Pickup\Warehouse_Store;
use Woodev\Framework\Shipping\Shipping_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Woodev\Framework\Shipping\Admin\Warehouse_Admin' ) ) {

	/**
	 * List / create / edit / delete warehouses through a {@see Warehouse_Store}.
	 *
	 * Storage-agnostic by construction: the UI talks only to the {@see Warehouse_Store}
	 * contract and the {@see Warehouse} value object, so it works over any store
	 * implementation (a carrier's existing table, a plugin's
	 * {@see \Woodev\Framework\Shipping\Pickup\Abstract_Warehouse_Store}, or an
	 * in-memory test double) without assuming a schema.
	 *
	 * The store CRUD is keyed by an opaque integer *storage row id* — distinct from
	 * {@see Warehouse::get_id()} (the carrier id). The list view derives that row id
	 * for each warehouse through a plugin-overridable resolver (default: the `id`
	 * key in {@see Warehouse::get_raw()}), so a store with a different primary-key
	 * layout can supply its own resolver instead of being assumed.
	 *
	 * The carrier wires this object into the {@see Shipping_Admin} bootstrap twice:
	 * once as a handler (so {@see self::register()} runs on `admin_init`) and once as
	 * the `callback` of its warehouse admin page (so {@see self::render_page()} draws
	 * the screen under the plugin-supplied slug). The framework registers no menu and
	 * derives no slug of its own.
	 *
	 * @since 1.5.0
	 */
	class Warehouse_Admin {

		/** @var Shipping_Plugin the plugin instance this admin surface belongs to */
		private Shipping_Plugin $plugin;

		/** @var Warehouse_Store the storage-agnostic warehouse store this UI manages */
		private Warehouse_Store $store;

		/** @var array<string, string> form fields to display: logical Warehouse field => label */
		private array $fields;

		/** @var callable(Warehouse): int resolves a warehouse's storage row id (default: raw['id']) */
		private $id_resolver;

		/** @var string admin page slug this UI lives on — the plugin-supplied installed-site contract */
		private string $page_slug;

		/** @var string parent menu of the admin page, used to build list/edit URLs */
		private string $page_parent;

		/** @var string capability required to manage warehouses */
		private string $capability;

		/** @var string forward-only, plugin-namespaced admin-post action the save form posts to */
		private string $save_action;

		/** @var string forward-only, plugin-namespaced admin-post action the delete form posts to */
		private string $delete_action;

		/** @var string handle used for the enqueued admin script */
		private string $script_handle;

		/**
		 * Constructor.
		 *
		 * Stores the collaborators and resolves display/wiring defaults; it adds no
		 * hooks — call {@see self::register()} (the {@see Shipping_Admin} bootstrap does
		 * this on `admin_init`).
		 *
		 * @since 1.5.0
		 *
		 * @param Shipping_Plugin      $plugin the plugin instance
		 * @param Warehouse_Store      $store  the warehouse store to manage
		 * @param array<string, mixed> $args {
		 *     Optional display/wiring overrides.
		 *
		 *     @type array<string, string>     $fields      logical Warehouse field => label shown in the form
		 *     @type callable(Warehouse): int  $id_resolver resolves a warehouse's storage row id
		 *     @type string                    $page_slug   the plugin-supplied admin page slug this UI lives on
		 *     @type string                    $page_parent parent menu used to build list/edit URLs
		 *     @type string                    $capability  capability required to manage warehouses
		 * }
		 */
		public function __construct( Shipping_Plugin $plugin, Warehouse_Store $store, array $args = [] ) {

			$this->plugin = $plugin;
			$this->store  = $store;

			$this->fields = isset( $args['fields'] ) && is_array( $args['fields'] )
				? $args['fields']
				: [
					'id'            => __( 'Carrier ID', 'woodev-plugin-framework' ),
					'name'          => __( 'Name', 'woodev-plugin-framework' ),
					'address'       => __( 'Address', 'woodev-plugin-framework' ),
					'lat'           => __( 'Latitude', 'woodev-plugin-framework' ),
					'lng'           => __( 'Longitude', 'woodev-plugin-framework' ),
					'contact_name'  => __( 'Contact name', 'woodev-plugin-framework' ),
					'contact_phone' => __( 'Contact phone', 'woodev-plugin-framework' ),
					'contact_email' => __( 'Contact email', 'woodev-plugin-framework' ),
				];

			$this->id_resolver = isset( $args['id_resolver'] ) && is_callable( $args['id_resolver'] )
				? $args['id_resolver']
				: static function ( Warehouse $warehouse ): int {
					$raw = $warehouse->get_raw();

					return isset( $raw['id'] ) ? (int) $raw['id'] : 0;
				};

			$this->page_slug   = isset( $args['page_slug'] ) && is_string( $args['page_slug'] ) ? $args['page_slug'] : '';
			$this->page_parent = isset( $args['page_parent'] ) && is_string( $args['page_parent'] ) ? $args['page_parent'] : 'admin.php';
			$this->capability  = isset( $args['capability'] ) && is_string( $args['capability'] ) ? $args['capability'] : 'manage_woocommerce';

			$this->save_action   = 'woodev_shipping_' . $plugin->get_id() . '_warehouse_save';
			$this->delete_action = 'woodev_shipping_' . $plugin->get_id() . '_warehouse_delete';
			$this->script_handle = $plugin->get_id() . '_warehouse_admin';
		}

		/**
		 * Registers the warehouse-admin hooks.
		 *
		 * Wires the save/delete `admin-post.php` handlers and the page-scoped script
		 * enqueue. Called by the {@see Shipping_Admin} bootstrap on `admin_init`.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register(): void {

			add_action( 'admin_post_' . $this->save_action, [ $this, 'handle_save' ] );
			add_action( 'admin_post_' . $this->delete_action, [ $this, 'handle_delete' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		}

		/**
		 * Enqueues the warehouse-admin script on this UI's page only.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function enqueue_assets(): void {

			if ( '' === $this->page_slug || $this->get_current_page() !== $this->page_slug ) {
				return;
			}

			wp_enqueue_script(
				$this->script_handle,
				$this->plugin->get_shipping_framework_assets_url() . '/js/admin/warehouse-admin.js',
				[],
				(string) $this->plugin->get_assets_version(),
				true
			);
		}

		/**
		 * Renders the warehouse admin page.
		 *
		 * Shows the edit form for a single warehouse when a row id is present in the
		 * request, otherwise the full list plus the create form. Passed to the
		 * {@see Shipping_Admin} bootstrap as the page `callback`.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function render_page(): void {

			if ( ! current_user_can( $this->capability ) ) {
				wp_die( esc_html__( 'You do not have permission to manage warehouses.', 'woodev-plugin-framework' ) );
			}

			$edit_id   = isset( $_GET['warehouse'] ) ? absint( wp_unslash( $_GET['warehouse'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$warehouse = $edit_id > 0 ? $this->store->get( $edit_id ) : null;

			echo '<div class="wrap woodev-warehouse-admin">';
			echo '<h1>' . esc_html__( 'Warehouses', 'woodev-plugin-framework' ) . '</h1>';

			$this->render_notice();

			if ( $warehouse instanceof Warehouse ) {
				$this->render_form( $warehouse, $edit_id );
			} else {
				$this->render_list();
				$this->render_form( null, 0 );
			}

			echo '</div>';
		}

		/**
		 * Renders the warehouse list table.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		private function render_list(): void {

			$warehouses = $this->store->all();

			echo '<h2>' . esc_html__( 'Stored warehouses', 'woodev-plugin-framework' ) . '</h2>';

			echo '<table class="widefat striped woodev-warehouse-list">';
			echo '<thead><tr>';
			foreach ( $this->fields as $label ) {
				echo '<th scope="col">' . esc_html( (string) $label ) . '</th>';
			}
			echo '<th scope="col">' . esc_html__( 'Actions', 'woodev-plugin-framework' ) . '</th>';
			echo '</tr></thead><tbody>';

			if ( empty( $warehouses ) ) {

				echo '<tr><td colspan="' . esc_attr( (string) ( count( $this->fields ) + 1 ) ) . '">';
				echo esc_html__( 'No warehouses stored yet.', 'woodev-plugin-framework' );
				echo '</td></tr>';

			} else {

				foreach ( $warehouses as $warehouse ) {
					$this->render_list_row( $warehouse );
				}
			}

			echo '</tbody></table>';
		}

		/**
		 * Renders a single warehouse list row.
		 *
		 * @since 1.5.0
		 *
		 * @param Warehouse $warehouse warehouse to render
		 * @return void
		 */
		private function render_list_row( Warehouse $warehouse ): void {

			$row_id = ( $this->id_resolver )( $warehouse );
			$values = $warehouse->to_array();

			echo '<tr>';

			foreach ( array_keys( $this->fields ) as $logical ) {
				$value = $values[ $logical ] ?? '';
				echo '<td>' . ( is_scalar( $value ) && '' !== (string) $value ? esc_html( (string) $value ) : '&ndash;' ) . '</td>';
			}

			echo '<td>';

			if ( $row_id > 0 ) {
				echo '<a href="' . esc_url( $this->get_page_url( [ 'warehouse' => $row_id ] ) ) . '" class="button">' . esc_html__( 'Edit', 'woodev-plugin-framework' ) . '</a> ';

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="woodev-warehouse-delete" style="display:inline;">';
				echo '<input type="hidden" name="action" value="' . esc_attr( $this->delete_action ) . '" />';
				echo '<input type="hidden" name="warehouse_id" value="' . esc_attr( (string) $row_id ) . '" />';
				wp_nonce_field( $this->delete_action );
				echo '<button type="submit" class="button woodev-warehouse-delete-button">' . esc_html__( 'Delete', 'woodev-plugin-framework' ) . '</button>';
				echo '</form>';
			} else {
				echo '&ndash;';
			}

			echo '</td></tr>';
		}

		/**
		 * Renders the create or edit form.
		 *
		 * @since 1.5.0
		 *
		 * @param Warehouse|null $warehouse warehouse being edited, or null when creating
		 * @param int            $row_id    storage row id when editing, 0 when creating
		 * @return void
		 */
		private function render_form( ?Warehouse $warehouse, int $row_id ): void {

			$values  = $warehouse instanceof Warehouse ? $warehouse->to_array() : [];
			$heading = $row_id > 0
				? __( 'Edit warehouse', 'woodev-plugin-framework' )
				: __( 'Add warehouse', 'woodev-plugin-framework' );

			echo '<h2>' . esc_html( $heading ) . '</h2>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="woodev-warehouse-form">';
			echo '<input type="hidden" name="action" value="' . esc_attr( $this->save_action ) . '" />';
			echo '<input type="hidden" name="warehouse_id" value="' . esc_attr( (string) $row_id ) . '" />';
			wp_nonce_field( $this->save_action );

			echo '<table class="form-table"><tbody>';

			foreach ( $this->fields as $logical => $label ) {

				$value = $values[ $logical ] ?? '';
				$field = 'warehouse[' . $logical . ']';
				$id    = 'woodev-warehouse-' . $logical;
				$type  = 'contact_email' === $logical ? 'email' : 'text';

				echo '<tr>';
				echo '<th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( (string) $label ) . '</label></th>';
				echo '<td><input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '" class="regular-text" /></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save warehouse', 'woodev-plugin-framework' ) . '</button>';

			if ( $row_id > 0 ) {
				echo ' <a href="' . esc_url( $this->get_page_url() ) . '" class="button">' . esc_html__( 'Cancel', 'woodev-plugin-framework' ) . '</a>';
			}

			echo '</p></form>';
		}

		/**
		 * Handles a create/update submission from the warehouse form.
		 *
		 * Verifies the nonce and capability, rebuilds the {@see Warehouse} from the
		 * submitted fields (preserving the edited row's raw payload so the store
		 * updates rather than inserts) and persists it through the store.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function handle_save(): void {

			check_admin_referer( $this->save_action );

			if ( ! current_user_can( $this->capability ) ) {
				wp_die( esc_html__( 'You do not have permission to manage warehouses.', 'woodev-plugin-framework' ) );
			}

			$row_id   = isset( $_POST['warehouse_id'] ) ? absint( wp_unslash( $_POST['warehouse_id'] ) ) : 0;
			$existing = $row_id > 0 ? $this->store->get( $row_id ) : null;

			$posted = isset( $_POST['warehouse'] ) && is_array( $_POST['warehouse'] )
				? wp_unslash( $_POST['warehouse'] )
				: [];

			$data = [
				'work_hours' => $existing instanceof Warehouse ? $existing->get_work_hours() : [],
				'raw'        => $existing instanceof Warehouse ? $existing->get_raw() : [],
			];

			foreach ( array_keys( $this->fields ) as $logical ) {

				$raw_value = is_array( $posted ) && isset( $posted[ $logical ] ) ? (string) $posted[ $logical ] : '';

				$data[ $logical ] = 'contact_email' === $logical
					? sanitize_email( $raw_value )
					: sanitize_text_field( $raw_value );
			}

			$this->store->save( Warehouse::from_array( $data ) );

			wp_safe_redirect( $this->get_page_url( [ 'message' => 'saved' ] ) );
			exit;
		}

		/**
		 * Handles a delete submission from the warehouse list.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function handle_delete(): void {

			check_admin_referer( $this->delete_action );

			if ( ! current_user_can( $this->capability ) ) {
				wp_die( esc_html__( 'You do not have permission to manage warehouses.', 'woodev-plugin-framework' ) );
			}

			$row_id = isset( $_POST['warehouse_id'] ) ? absint( wp_unslash( $_POST['warehouse_id'] ) ) : 0;

			if ( $row_id > 0 ) {
				$this->store->delete( $row_id );
			}

			wp_safe_redirect( $this->get_page_url( [ 'message' => 'deleted' ] ) );
			exit;
		}

		/**
		 * Renders an admin notice reflecting the last save/delete outcome.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		private function render_notice(): void {

			$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( 'saved' === $message ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Warehouse saved.', 'woodev-plugin-framework' ) . '</p></div>';
			} elseif ( 'deleted' === $message ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Warehouse deleted.', 'woodev-plugin-framework' ) . '</p></div>';
			}
		}

		/**
		 * Builds the admin URL for this UI's page, optionally with extra query args.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, scalar> $args extra query args to merge onto the page URL
		 * @return string
		 */
		private function get_page_url( array $args = [] ): string {

			$query = array_merge( [ 'page' => $this->page_slug ], $args );

			// A submenu page's URL is `<parent>?page=...` only when the parent is an admin
			// PHP file (e.g. edit.php); for a top-level menu SLUG parent (e.g. 'woocommerce')
			// it is admin.php?page=... -- passing the slug straight to admin_url() yields
			// /wp-admin/woocommerce?page=... which 404s. Mirror WordPress's own rule.
			$parent = ( false !== strpos( $this->page_parent, '.php' ) ) ? $this->page_parent : 'admin.php';

			return add_query_arg( $query, admin_url( $parent ) );
		}

		/**
		 * Gets the current admin `page` request value.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		private function get_current_page(): string {

			return isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		/**
		 * Gets the plugin instance this admin surface belongs to.
		 *
		 * @since 1.5.0
		 *
		 * @return Shipping_Plugin
		 */
		public function get_plugin(): Shipping_Plugin {
			return $this->plugin;
		}

		/**
		 * Gets the warehouse store this UI manages.
		 *
		 * @since 1.5.0
		 *
		 * @return Warehouse_Store
		 */
		public function get_store(): Warehouse_Store {
			return $this->store;
		}
	}
}
