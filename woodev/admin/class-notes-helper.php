<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Notes_Helper' ) ) :

/**
* Helper class for WooCommerce enhanced admin notes.
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

		} catch ( Exception $exception ) {}

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

}

endif;