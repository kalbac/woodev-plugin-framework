<?php
/**
 * Export profiles in the `profiles` table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Catalog\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes profiles: a name and a `settings` JSON (sources, fallbacks, units, VAT, currency rule, price rule).
 */
final class Wpdb_Profile_Store {
	/**
	 * Names the action fired with the profile id after its price rule or currency rule changed. One action per marketplace, so two
	 * marketplace plugins never recompute each other's prices.
	 *
	 * @since 2.0.29
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return string e.g. `woodev_ozon_profile_price_changed`.
	 */
	public static function price_changed_action( string $marketplace_id ): string {
		return 'woodev_' . $marketplace_id . '_profile_price_changed';
	}

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Profiles table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Action fired after a price rule or currency rule changed ({@see self::price_changed_action()}).
	 *
	 * @var string
	 */
	private $price_changed_action;

	/**
	 * Creates the store.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb  $db                   Database.
	 * @param string $table                Full table name.
	 * @param string $price_changed_action Action to fire when a price term changes, from {@see self::price_changed_action()}.
	 */
	public function __construct( \wpdb $db, string $table, string $price_changed_action ) {
		$this->db                   = $db;
		$this->table                = $table;
		$this->price_changed_action = $price_changed_action;
	}

	/**
	 * Inserts a profile with a given id unless that id exists (a migration keeps the old profile id).
	 *
	 * @param int                 $profile_id Profile id.
	 * @param string              $name       Merchant label.
	 * @param array<string,mixed> $settings   Profile settings.
	 * @return bool True when a row was inserted, false when the id already existed.
	 * @throws Persistence_Exception When the database refuses the insert.
	 */
	public function insert_if_absent( int $profile_id, string $name, array $settings ): bool {
		$changed = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
			$this->db->prepare(
				'INSERT IGNORE INTO %i (profile_id, name, settings) VALUES (%d, %s, %s)',
				$this->table,
				$profile_id,
				$name,
				[] === $settings ? '{}' : (string) wp_json_encode( $settings )
			)
		);

		if ( false === $changed ) {
			throw Persistence_Exception::database( 'profile ' . $profile_id, (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return 1 === $changed;
	}

	/**
	 * Loads a profile.
	 *
	 * @param int $profile_id Profile id.
	 * @return Profile|null
	 */
	public function get( int $profile_id ): ?Profile {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE profile_id = %d', $this->table, $profile_id ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

		if ( ! is_array( $row ) ) {
			return null;
		}

		$settings = json_decode( (string) ( $row['settings'] ?? '' ), true );

		return new Profile( (int) $row['profile_id'], (string) $row['name'], is_array( $settings ) ? $settings : [] );
	}

	/**
	 * Lists every profile, by name.
	 *
	 * @return Profile[]
	 */
	public function all(): array {
		$ids = $this->db->get_results( $this->db->prepare( 'SELECT profile_id FROM %i ORDER BY name ASC, profile_id ASC', $this->table ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

		$profiles = [];
		foreach ( is_array( $ids ) ? $ids : [] as $id ) {
			$profile = $this->get( (int) $id['profile_id'] );

			if ( null !== $profile ) {
				$profiles[] = $profile;
			}
		}

		return $profiles;
	}

	/**
	 * Creates a profile with a generated id.
	 *
	 * @param string              $name     Merchant label.
	 * @param array<string,mixed> $settings Profile settings.
	 * @return int New profile id.
	 * @throws \RuntimeException When the insert fails.
	 */
	public function create( string $name, array $settings ): int {
		$done = $this->db->insert(
			$this->table,
			[
				'name'     => $name,
				'settings' => $this->encode( $settings ),
			]
		);

		if ( false === $done ) {
			throw new \RuntimeException( 'The profile could not be saved.' );
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Replaces the name and settings of a profile. When its price rule or currency rule changed, the price-changed action fires so
	 * the prices of the cards that use the profile are worked out again.
	 *
	 * @param int                 $profile_id Profile id.
	 * @param string              $name       Merchant label.
	 * @param array<string,mixed> $settings   Profile settings.
	 * @return bool False when the profile does not exist or the database refused.
	 */
	public function update( int $profile_id, string $name, array $settings ): bool {
		$before = $this->get( $profile_id );

		if ( null === $before ) {
			return false;
		}

		$done = $this->db->update(
			$this->table,
			[
				'name'     => $name,
				'settings' => $this->encode( $settings ),
			],
			[ 'profile_id' => $profile_id ]
		);

		if ( false !== $done && $this->price_terms_differ( $before, $settings ) ) {
			/**
			 * Fires after a profile's price rule or currency rule was changed.
			 *
			 * @param int $profile_id Profile id.
			 */
			do_action( $this->price_changed_action, $profile_id );
		}

		return false !== $done;
	}

	/**
	 * Tells whether new settings change what a card of the profile costs on the marketplace.
	 *
	 * @param Profile             $before   The stored profile.
	 * @param array<string,mixed> $settings New settings.
	 * @return bool
	 */
	private function price_terms_differ( Profile $before, array $settings ): bool {
		foreach ( [ 'price_rule', 'currency_rule' ] as $key ) {
			if ( $before->setting( $key ) != ( $settings[ $key ] ?? null ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Arrays compared by content, not by key order.
				return true;
			}
		}

		return false;
	}

	/**
	 * Deletes a profile. The caller checks that no mapping uses it.
	 *
	 * @param int $profile_id Profile id.
	 * @return bool
	 */
	public function delete( int $profile_id ): bool {
		$done = $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE profile_id = %d', $this->table, $profile_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

		return 1 === $done;
	}

	/**
	 * Encodes the settings JSON.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string
	 */
	private function encode( array $settings ): string {
		return [] === $settings ? '{}' : (string) wp_json_encode( $settings );
	}
}
