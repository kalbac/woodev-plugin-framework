<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Notes_Helper' ) ) :

	/**
	Helper class for WooCommerce enhanced admin notes.
	 */
	class Woodev_Notes_Helper {

		/** Conditional methods */


		/**
		 * Determines if any notes with the given name exist.
		 *
		 * @param string $name note name
		 * @return bool
		 */
		public static function note_with_name_exists( $name ) {
			return ! empty( self::get_note_ids_with_name( $name ) );
		}


		/** Getter methods */


		/**
		 * Gets a note with the given name.
		 *
		 * @param string $name name of the note to get
		 *
		 * @return Automattic\WooCommerce\Admin\Notes\Note|null
		 */
		public static function get_note_with_name( $name ) {

			$note     = null;
			$note_ids = self::get_note_ids_with_name( $name );

			if ( ! empty( $note_ids ) ) {

				$note_id = current( $note_ids );

				$note = Automattic\WooCommerce\Admin\Notes\Notes::get_note( $note_id );
			}

			return $note ?: null;
		}


		/**
		 * Gets all notes with the given name.
		 *
		 * @param string $name note name
		 * @return int[]
		 */
		public static function get_note_ids_with_name( $name ) {

			$note_ids = [];

			try {

				/** @var Automattic\WooCommerce\Admin\Notes\DataStore $data_store */
				$data_store = WC_Data_Store::load( 'admin-note' );

				$note_ids = $data_store->get_notes_with_name( $name );

			} catch ( Exception $exception ) {
			}

			return $note_ids;
		}


		/**
		 * Gets all note IDs from the given source.
		 *
		 * @param string $source note source
		 * @return int[]
		 */
		public static function get_note_ids_with_source( $source ) {
			global $wpdb;

			return $wpdb->get_col(
				$wpdb->prepare(
					"SELECT note_id FROM {$wpdb->prefix}wc_admin_notes WHERE source = %s ORDER BY note_id ASC",
					$source
				)
			);
		}


		/**
		 * Deletes all notes from the given source.
		 *
		 * @param string $source source name
		 */
		public static function delete_notes_with_source( $source ) {

			foreach ( self::get_note_ids_with_source( $source ) as $note_id ) {

				if ( $note = Automattic\WooCommerce\Admin\Notes\Notes::get_note( $note_id ) ) {
					$note->delete();
				}
			}
		}


		/** Setter methods */


		/**
		 * Creates (or updates, by name) a single WooCommerce Admin inbox note.
		 *
		 * Best-effort and self-guarding: returns false without side effects when the
		 * WooCommerce Admin Notes API is absent, so callers (incl. the heavily
		 * unit-tested remote-deactivation command) can call it unconditionally in any
		 * environment. An existing note with the same name is revived and updated
		 * rather than duplicated. Each action is `[ 'name' => , 'label' => , 'url' => ]`.
		 *
		 * @since 2.0.2
		 *
		 * @param string                                                       $name    Unique note name (used for dedup).
		 * @param string                                                       $source  Note source (plugin id-dasherized).
		 * @param string                                                       $title   Note title.
		 * @param string                                                       $content Note content.
		 * @param array<int, array{name: string, label: string, url?: string}> $actions Optional action buttons.
		 * @return bool True when the note was saved, false when WC Admin is unavailable or saving failed.
		 */
		public static function add_note( string $name, string $source, string $title, string $content, array $actions = [] ): bool {

			if ( ! class_exists( '\Automattic\WooCommerce\Admin\Notes\Note' ) ) {
				return false;
			}

			try {

				$note = self::get_note_with_name( $name );

				if ( ! $note ) {
					$note = new Automattic\WooCommerce\Admin\Notes\Note();
					$note->set_name( $name );
				}

				$note->set_source( $source );
				$note->set_type( Automattic\WooCommerce\Admin\Notes\Note::E_WC_ADMIN_NOTE_ERROR );
				$note->set_title( $title );
				$note->set_content( $content );
				$note->set_status( Automattic\WooCommerce\Admin\Notes\Note::E_WC_ADMIN_NOTE_UNACTIONED );

				$note->set_actions( [] );

				foreach ( $actions as $action ) {

					if ( empty( $action['name'] ) || empty( $action['label'] ) ) {
						continue;
					}

					$note->add_action( $action['name'], $action['label'], $action['url'] ?? '' );
				}

				// WC_Data::save() returns the saved note id (> 0); cast so a silent
				// non-throwing save failure (id 0) is reported as false per the contract.
				return (bool) $note->save();

			} catch ( \Throwable $exception ) {
				return false;
			}
		}
	}

endif;
