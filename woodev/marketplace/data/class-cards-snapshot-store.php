<?php
/**
 * The ten-minute snapshot of an account's marketplace cards.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The remote-cards screen lists the adapter's snapshot of the cabinet, stored in a transient per account for ten minutes and
 * refreshed by a button. A screen reads our storage during render, never the marketplace, so the button only starts a job and the
 * job fills the transient.
 *
 * Three transients per account: the snapshot, a «refreshing» mark (so the screen can say so, and two clicks do not queue two
 * reads), and the text of the last failed refresh.
 */
final class Cards_Snapshot_Store {
	public const TTL            = 600;
	public const REFRESHING_TTL = 900;
	public const ERROR_TTL      = 900;

	private const SNAPSHOT_KEY   = 'cards_';
	private const REFRESHING_KEY = 'cards_refreshing_';
	private const ERROR_KEY      = 'cards_error_';

	/**
	 * Option family of the plugin; the transient names start with it, which is how the uninstaller finds them.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Creates the store.
	 *
	 * @since 2.0.29
	 *
	 * @param string $option_prefix The plugin's option family, from {@see Uninstaller::option_prefix()}.
	 */
	public function __construct( string $option_prefix ) {
		$this->prefix = $option_prefix;
	}

	/**
	 * Returns the snapshot.
	 *
	 * @param int $account_id Account id.
	 * @return array{fetched_at:int,cards:array<int,array<string,mixed>>}|null Null when none is stored or it has expired.
	 */
	public function get( int $account_id ): ?array {
		$stored = get_transient( $this->prefix . self::SNAPSHOT_KEY . $account_id );

		if ( ! is_array( $stored ) || ! isset( $stored['fetched_at'], $stored['cards'] ) || ! is_array( $stored['cards'] ) ) {
			return null;
		}

		return [
			'fetched_at' => (int) $stored['fetched_at'],
			'cards'      => $stored['cards'],
		];
	}

	/**
	 * Stores a snapshot and clears the «refreshing» mark and the last error.
	 *
	 * @param int                            $account_id Account id.
	 * @param array<int,array<string,mixed>> $cards      Cards.
	 * @param int                            $now        Unix time.
	 * @return bool False when storage refused it (a catalogue too large for the options table).
	 */
	public function put( int $account_id, array $cards, int $now ): bool {
		$stored = set_transient(
			$this->prefix . self::SNAPSHOT_KEY . $account_id,
			[
				'fetched_at' => $now,
				'cards'      => $cards,
			],
			self::TTL
		);

		delete_transient( $this->prefix . self::REFRESHING_KEY . $account_id );

		if ( $stored ) {
			delete_transient( $this->prefix . self::ERROR_KEY . $account_id );
		}

		return (bool) $stored;
	}

	/**
	 * Forgets everything kept for an account: the snapshot, the «refreshing» mark and the last error.
	 *
	 * Goes through the transient API, so it reaches the value wherever WordPress keeps it — in the options table, or only in a
	 * persistent object cache, where no SQL on `wp_options` can find it.
	 *
	 * @since 2.0.29
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function clear( int $account_id ): void {
		foreach ( [ self::SNAPSHOT_KEY, self::REFRESHING_KEY, self::ERROR_KEY ] as $key ) {
			delete_transient( $this->prefix . $key . $account_id );
		}
	}

	/**
	 * Marks a refresh as queued or running.
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function mark_refreshing( int $account_id ): void {
		set_transient( $this->prefix . self::REFRESHING_KEY . $account_id, 1, self::REFRESHING_TTL );
		delete_transient( $this->prefix . self::ERROR_KEY . $account_id );
	}

	/**
	 * Tells whether a refresh is queued or running.
	 *
	 * @param int $account_id Account id.
	 * @return bool
	 */
	public function is_refreshing( int $account_id ): bool {
		return false !== get_transient( $this->prefix . self::REFRESHING_KEY . $account_id );
	}

	/**
	 * Records why a refresh failed, and ends the «refreshing» mark.
	 *
	 * @param int    $account_id Account id.
	 * @param string $text       Text for the merchant.
	 * @return void
	 */
	public function fail( int $account_id, string $text ): void {
		delete_transient( $this->prefix . self::REFRESHING_KEY . $account_id );
		set_transient( $this->prefix . self::ERROR_KEY . $account_id, $text, self::ERROR_TTL );
	}

	/**
	 * Returns the text of the last failed refresh.
	 *
	 * @param int $account_id Account id.
	 * @return string '' when the last refresh did not fail.
	 */
	public function last_error( int $account_id ): string {
		$text = get_transient( $this->prefix . self::ERROR_KEY . $account_id );

		return is_string( $text ) ? $text : '';
	}
}
