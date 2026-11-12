<?php
/**
 * Marketplace accounts in the `accounts` table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Ledger\Claimable;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the accounts table of one marketplace plugin.
 *
 * - **The secrets are encrypted** with {@see Secret_Cipher}: the secret credential fields the adapter declares
 *   ({@see Marketplace_Adapter::credential_fields()}) are stored as one encrypted JSON object in `secret_enc`, never in `wp_options` and never in clear.
 *   A set that cannot be opened (the WordPress salts changed) comes back with every secret '' and `secrets_unreadable` true in the
 *   credentials.
 * - **`is_enabled` is the merchant's switch and no code touches it**: {@see self::save()} writes it only when it inserts a row,
 *   {@see self::set_health()} never, and the one writer of an existing row is {@see self::set_enabled()}, which the settings
 *   handler calls when the merchant flips the toggle.
 * - **The columns of the table are `Account::settings()` keys** next to the keys of the `settings` JSON, so a caller reads
 *   `$account->setting( 'stock_mode' )` whichever way the value is stored.
 * - **The credentials of an `Account`** are keyed by the adapter's field names: the identifier, every secret, and the two keys
 *   this store adds ({@see self::CREDENTIAL_UNREADABLE}, {@see self::CREDENTIAL_UNREADABLE_ENC}).
 *
 * @since 2.0.29
 */
final class Wpdb_Account_Store implements Account_Store {
	use Claimable;

	/**
	 * Stock modes: the quantity the marketplace shows comes from the shop, or the marketplace keeps its own.
	 *
	 * @var string
	 */
	public const STOCK_SITE        = 'site';
	public const STOCK_MARKETPLACE = 'marketplace';
	public const STOCK_OFF         = 'off';

	/**
	 * Price directions: to the marketplace, from it, or not synchronised.
	 *
	 * @var string
	 */
	public const PRICE_TO_MARKETPLACE   = 'to_marketplace';
	public const PRICE_FROM_MARKETPLACE = 'from_marketplace';
	public const PRICE_OFF              = 'off';

	/**
	 * Credential key that is true when the stored secrets could not be decrypted.
	 *
	 * @var string
	 */
	public const CREDENTIAL_UNREADABLE = 'secrets_unreadable';

	/**
	 * Credential key that holds the stored ciphertext that could not be decrypted ('' when it could).
	 *
	 * @var string
	 */
	public const CREDENTIAL_UNREADABLE_ENC = 'secrets_unreadable_enc';

	/**
	 * Columns {@see self::update_settings()} can fence on.
	 *
	 * @var string[]
	 */
	private const FENCE_COLUMNS = [ 'external_id', 'secret_fingerprint', 'secret_enc', 'claim_token' ];

	/**
	 * Setting keys that are columns of the table, not entries of the `settings` JSON.
	 *
	 * @var string[]
	 */
	private const KNOB_COLUMNS = [ 'warehouse_id', 'stock_mode', 'price_direction', 'orders_enabled', 'import_enabled', 'currency' ];

	/**
	 * Columns that are read into `Account::settings()` but only written by the code that owns them (the notification intake).
	 *
	 * @var string[]
	 */
	private const READ_ONLY_COLUMNS = [ 'notification_token', 'notification_state', 'seller_id', 'secret_fingerprint' ];

	/**
	 * Attempts of a compare-and-swap on the `settings` JSON.
	 *
	 * @var int
	 */
	private const SWAP_ATTEMPTS = 3;

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full table name, prefix included.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Secret cipher.
	 *
	 * @var Secret_Cipher
	 */
	private $cipher;

	/**
	 * Adapter id stored on the accounts of this store.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Credential fields the adapter declared.
	 *
	 * @var Credential_Fields
	 */
	private $fields;

	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the store.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb             $db         Database.
	 * @param string            $table      Full table name.
	 * @param Secret_Cipher     $cipher     Secret cipher.
	 * @param string            $adapter_id The adapter's id; {@see self::all()} lists nothing for another one.
	 * @param Credential_Fields $fields     The adapter's credential fields.
	 * @param callable|null     $clock      Returns the Unix time; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, Secret_Cipher $cipher, string $adapter_id, Credential_Fields $fields, ?callable $clock = null ) {
		$this->db         = $db;
		$this->table      = $table;
		$this->cipher     = $cipher;
		$this->adapter_id = $adapter_id;
		$this->fields     = $fields;
		$this->clock      = $clock ?? 'time';
	}

	/**
	 * Builds the store of an adapter: its id and the credential fields it declares decide which values are encrypted.
	 *
	 * @since 2.0.29
	 *
	 * @param \wpdb               $db      Database.
	 * @param string              $table   Full table name.
	 * @param Secret_Cipher       $cipher  Secret cipher.
	 * @param Marketplace_Adapter $adapter The plugin's adapter.
	 * @param callable|null       $clock   Returns the Unix time; the system clock when null.
	 * @return self
	 */
	public static function for_adapter( \wpdb $db, string $table, Secret_Cipher $cipher, Marketplace_Adapter $adapter, ?callable $clock = null ): self {
		return new self( $db, $table, $cipher, $adapter->id(), $adapter->credential_fields(), $clock );
	}

	/**
	 * Loads one account.
	 *
	 * @since 2.0.29
	 *
	 * @param int $id Account id.
	 * @return Account|null Null when it does not exist.
	 */
	public function get( int $id ): ?Account {
		$row = $this->row( $id );

		return null === $row ? null : $this->to_account( $row );
	}

	/**
	 * Lists the accounts, oldest first.
	 *
	 * @since 2.0.29
	 *
	 * @param string $adapter_id   Adapter id; anything but this store's adapter gives an empty list.
	 * @param bool   $enabled_only Skip accounts the merchant switched off.
	 * @return Account[]
	 */
	public function all( string $adapter_id, bool $enabled_only ): array {
		if ( $this->adapter_id !== $adapter_id ) {
			return [];
		}

		$where = $enabled_only ? ' WHERE is_enabled = 1' : '';
		$rows  = $this->db->get_results( "SELECT * FROM {$this->table}{$where} ORDER BY account_id ASC", 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- The table name is ours; the statement has no input.

		return array_map( [ $this, 'to_account' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Finds the account of an identifier.
	 *
	 * @since 2.0.29
	 *
	 * @param string $external_id The identifier credential (what the adapter's {@see Credential_Fields::identifier()} names).
	 * @return Account|null
	 */
	public function find_by_identifier( string $external_id ): ?Account {
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->table} WHERE external_id = %s", $external_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is ours.
			'ARRAY_A'
		);

		return is_array( $row ) ? $this->to_account( $row ) : null;
	}

	/**
	 * Inserts or updates an account.
	 *
	 * Writes the whole account from the value it is given, so call it with an account that was just read, or for an insert. A
	 * settings save that changes one field must use {@see self::update_fields()}, {@see self::replace_secrets()} and the other
	 * targeted writers instead: this method writes the secrets of its argument back, and a stale argument would undo a rotation.
	 *
	 * An update never writes `is_enabled`. An empty secret never erases a stored one (rotate by giving the new value). Changing the
	 * identifier or a secret resets the health to `unknown` and forgets the expiry, because both described the old credentials.
	 *
	 * @since 2.0.29
	 *
	 * @param Account $account Account to save; id 0 inserts, an unknown positive id inserts with that id (a migration keeps the old id).
	 * @return int Saved account id.
	 * @throws \InvalidArgumentException When the identifier is empty or belongs to another account. A write the database refuses
	 *                                   throws a `RuntimeException`.
	 */
	public function save( Account $account ): int {
		$credentials = $account->credentials();
		$external_id = trim( (string) ( $credentials[ $this->fields->identifier() ] ?? '' ) );
		$secrets     = $this->fields->secrets_of( $credentials );

		if ( '' === $external_id ) {
			throw new \InvalidArgumentException( 'An account needs its ' . $this->fields->identifier() . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$owner = $this->find_by_identifier( $external_id );
		if ( null !== $owner && $owner->id() !== $account->id() ) {
			throw new \InvalidArgumentException( 'This ' . $this->fields->identifier() . ' belongs to another account.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$existing = $account->id() > 0 ? $this->row( $account->id() ) : null;

		return null === $existing
			? $this->insert( $account, $external_id, $secrets )
			: $this->update( $account, $existing, $external_id, $secrets );
	}

	/**
	 * Records the outcome of a credential check without touching the merchant's switch.
	 *
	 * An expiry the result does not know is kept (a 401 knows nothing about it, and the stored date is what tells an expired key
	 * from a wrong one); the currency likewise.
	 *
	 * **Unfenced: it exists only because `Account_Store` requires it.** It writes whatever credentials the row has now, so an
	 * outcome computed for older credentials can land on newer ones. Use {@see self::set_health_for_credentials()} or
	 * {@see self::set_health_for_ciphertext()}.
	 *
	 * @since 2.0.29
	 *
	 * @param int           $id     Account id.
	 * @param Health_Result $result Check outcome.
	 * @return void
	 */
	public function set_health( int $id, Health_Result $result ): void {
		$this->db->update( $this->table, $this->health_columns( $result ), [ 'account_id' => $id ] );
	}

	/**
	 * Records the outcome of a check only if the account still has the identifier and secrets that were tested.
	 *
	 * The comparison is part of the `UPDATE`, so credentials rotated while the check was in flight cannot receive the old
	 * credentials' outcome (and an untested replacement cannot be marked `ok` by the old one). A lost comparison leaves the
	 * current health alone.
	 *
	 * @since 2.0.29
	 *
	 * @param int                 $id          Account id.
	 * @param array<string,mixed> $credentials The tested credentials, keyed by the adapter's field names.
	 * @param Health_Result       $result      Check outcome.
	 * @return bool False when the account no longer has those credentials (or the same outcome was already stored this second).
	 */
	public function set_health_for_credentials( int $id, array $credentials, Health_Result $result ): bool {
		$external_id = trim( (string) ( $credentials[ $this->fields->identifier() ] ?? '' ) );
		$secrets     = $this->fields->secrets_of( $credentials );

		$changed = $this->db->update(
			$this->table,
			$this->health_columns( $result ),
			[
				'account_id'         => $id,
				'external_id'        => $external_id,
				'secret_fingerprint' => $this->fingerprint( $secrets ),
			]
		);

		if ( ! is_int( $changed ) || $changed < 1 ) {
			return false;
		}

		$this->remember_limits( $id, $external_id, $secrets, (array) ( $result->extra()['limits'] ?? [] ) );

		return true;
	}

	/**
	 * Records the outcome for secrets that could not be decrypted, only if the account still holds exactly that ciphertext.
	 *
	 * The fingerprint cannot fence this case: the plaintext is unknown, and a merchant who re-enters the same secrets keeps the
	 * fingerprint while the ciphertext (new nonce) changes. Credentials repaired in the meantime therefore keep their own health.
	 *
	 * @since 2.0.29
	 *
	 * @param int           $id         Account id.
	 * @param string        $ciphertext The stored value that failed to decrypt (`credentials()[ self::CREDENTIAL_UNREADABLE_ENC ]`).
	 * @param Health_Result $result     Outcome.
	 * @return bool False when the account no longer holds that ciphertext.
	 */
	public function set_health_for_ciphertext( int $id, string $ciphertext, Health_Result $result ): bool {
		$changed = $this->db->update(
			$this->table,
			$this->health_columns( $result ),
			[
				'account_id' => $id,
				'secret_enc' => $ciphertext,
			]
		);

		return is_int( $changed ) && $changed > 0;
	}

	/**
	 * Writes some of the account's own fields (the name and the knob columns) and nothing else.
	 *
	 * A settings save changes one field at a time. Going through {@see self::save()} with a snapshot of the whole account would
	 * write every other field back from that snapshot, including the secrets, and undo what another request saved in between.
	 *
	 * @since 2.0.29
	 *
	 * @param int                 $id     Account id.
	 * @param array<string,mixed> $fields Field => value; `name`, `warehouse_id`, `stock_mode`, `price_direction`, `orders_enabled`, `import_enabled` or `currency`.
	 * @return void
	 * @throws \InvalidArgumentException On any other field: credentials and the merchant's switch have their own writers.
	 */
	public function update_fields( int $id, array $fields ): void {
		$data = [];

		foreach ( $fields as $field => $value ) {
			if ( 'name' === $field ) {
				$data['name'] = trim( (string) $value );
			} elseif ( in_array( $field, self::KNOB_COLUMNS, true ) ) {
				$data[ $field ] = self::stored( $field, $value );
			} else {
				throw new \InvalidArgumentException( 'This field has its own writer.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}
		}

		if ( [] !== $data ) {
			$this->db->update( $this->table, $data, [ 'account_id' => $id ] );
		}
	}

	/**
	 * Changes the identifier; the stored health and expiry described the old one, so they are forgotten. Nothing happens when the
	 * identifier is already the saved one (the comparison is in the `UPDATE`).
	 *
	 * @since 2.0.29
	 *
	 * @param int    $id          Account id.
	 * @param string $external_id New identifier.
	 * @return void
	 * @throws \RuntimeException When the database refuses the write (the identifier belongs to another account).
	 */
	public function replace_identifier( int $id, string $external_id ): void {
		$sql = $this->db->prepare(
			"UPDATE {$this->table} SET external_id = %s, health = 'unknown', health_message = NULL, health_checked_at = NULL, key_expires_at = NULL WHERE account_id = %d AND external_id <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name is ours.
			trim( $external_id ),
			$id,
			trim( $external_id )
		);

		$changed = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.

		if ( false === $changed ) {
			throw new \RuntimeException( 'The account identifier could not be saved.' );
		}

		if ( (int) $changed > 0 ) {
			$this->forget_limits( $id );
		}
	}

	/**
	 * Replaces secret fields. The old credentials' health and expiry are forgotten. Only the merchant's own action calls this,
	 * with the values the merchant just typed.
	 *
	 * @since 2.0.29
	 *
	 * @param int                 $id      Account id.
	 * @param array<string,mixed> $secrets Secret field => new value. An empty value keeps the stored one (an empty field in a form
	 *                                     means "unchanged"); names that are not secret fields are ignored.
	 * @return bool False when nothing was written: no new value, no such account, or another request changed the secrets first.
	 */
	public function replace_secrets( int $id, array $secrets ): bool {
		$given = array_filter( $this->fields->secrets_of( $secrets ), 'strlen' );
		$row   = [] === $given ? null : $this->row( $id );

		if ( null === $row ) {
			return false;
		}

		$merged = array_merge( $this->stored_secrets( (string) $row['secret_enc'] ) ?? $this->fields->secrets_of( [] ), $given );

		$changed = $this->db->update(
			$this->table,
			array_merge( $this->secret_columns( $merged ), $this->forgotten_health() ),
			[
				'account_id' => $id,
				'secret_enc' => (string) $row['secret_enc'],
			]
		);

		if ( ! is_int( $changed ) || $changed < 1 ) {
			return false;
		}

		$this->forget_limits( $id );

		return true;
	}

	/**
	 * Changes the `settings` JSON atomically: the change is applied to what the row holds now and written only if the row still
	 * holds exactly that (compare-and-swap on the serialised column), else it is re-read and applied again, up to three times.
	 * Entries another request or job wrote in between survive.
	 *
	 * @since 2.0.29
	 *
	 * @param int                  $id     Account id.
	 * @param callable             $change Receives the decoded JSON entries and returns the new ones; it may run more than once.
	 * @param array<string,string> $fence  Optional column values the row must still have, part of the `UPDATE`: `external_id`,
	 *                                     `secret_fingerprint`, `secret_enc` (unreadable secrets) and `claim_token` (the claim fence).
	 * @return bool False when the account does not exist, no longer matches the fence, or three swaps in a row were lost.
	 */
	public function update_settings( int $id, callable $change, array $fence = [] ): bool {
		$guard      = '';
		$guard_args = [];
		$fence      = array_intersect_key( $fence, array_flip( self::FENCE_COLUMNS ) );

		foreach ( $fence as $column => $value ) {
			$guard       .= " AND {$column} = %s";
			$guard_args[] = (string) $value;
		}

		for ( $attempt = 0; $attempt < self::SWAP_ATTEMPTS; $attempt++ ) {
			$row = $this->row( $id );

			if ( null === $row || ! self::matches_fence( $row, $fence ) ) {
				return false;
			}

			$old = isset( $row['settings'] ) ? (string) $row['settings'] : null;
			$new = $this->encode( (array) call_user_func( $change, $this->decode( $old ) ) );

			if ( $new === $old ) {
				return true;
			}

			$sql = null === $old
				? $this->db->prepare( "UPDATE {$this->table} SET settings = %s WHERE account_id = %d AND settings IS NULL{$guard}", array_merge( [ $new, $id ], $guard_args ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name and the fence are ours.
				: $this->db->prepare( "UPDATE {$this->table} SET settings = %s WHERE account_id = %d AND settings = %s{$guard}", array_merge( [ $new, $id, $old ], $guard_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name and the fence are ours.

			if ( 1 === (int) $this->db->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
				return true;
			}
		}

		return false;
	}

	/**
	 * Claims the account row: the single per-account flusher and the validator run under it.
	 *
	 * @since 2.0.29
	 *
	 * @param int $id  Account id.
	 * @param int $ttl Seconds the claim lasts.
	 * @return string|null The token, or null when another holder owns the account.
	 */
	public function claim( int $id, int $ttl ): ?string {
		return $this->acquire( $this->table, [ 'account_id' => $id ], $ttl );
	}

	/**
	 * Tells whether a token still owns the account row.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $id    Account id.
	 * @param string $token Claim token.
	 * @return bool
	 */
	public function holds_claim( int $id, string $token ): bool {
		return $this->holds( $this->table, [ 'account_id' => $id ], $token );
	}

	/**
	 * Extends a claim on the account row.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $id    Account id.
	 * @param string $token Claim token.
	 * @param int    $ttl   Seconds from now.
	 * @return bool False when the token no longer owns the row.
	 */
	public function renew_claim( int $id, string $token, int $ttl ): bool {
		return $this->renew( $this->table, [ 'account_id' => $id ], $token, $ttl );
	}

	/**
	 * Releases a claim on the account row; a caller that is not the holder changes nothing.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $id    Account id.
	 * @param string $token Claim token.
	 * @return void
	 */
	public function release_claim( int $id, string $token ): void {
		$this->release( $this->table, [ 'account_id' => $id ], $token );
	}

	/**
	 * Full name of the accounts table, for a write elsewhere that must carry the account row's claim in its `WHERE`.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->table;
	}

	/**
	 * Writes the merchant's on/off switch. Call it only from the merchant's own action (the toggle on the settings page).
	 *
	 * @since 2.0.29
	 *
	 * @param int  $id      Account id.
	 * @param bool $enabled Switch value.
	 * @return void
	 */
	public function set_enabled( int $id, bool $enabled ): void {
		$this->db->update( $this->table, [ 'is_enabled' => $enabled ? 1 : 0 ], [ 'account_id' => $id ] );
	}

	/**
	 * Deletes the account row. The very last step of deleting an account, after every table that refers to it is empty.
	 *
	 * Two conditions are part of the `DELETE`: the caller holds the account's claim (a run that lost it deletes nothing), and the
	 * merchant's switch is off (an account switched on again while its data was being deleted keeps its row).
	 *
	 * @since 2.1.5
	 *
	 * @param int    $id    Account id.
	 * @param string $token The caller's claim token ({@see self::claim()}).
	 * @return bool False when no row matched: the account is gone, enabled, or the claim is no longer the caller's.
	 */
	public function delete( int $id, string $token ): bool {
		$sql = $this->db->prepare( "DELETE FROM {$this->table} WHERE account_id = %d AND is_enabled = 0 AND claim_token = %s", $id, $token ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is ours.

		return 1 === (int) $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
	}

	/**
	 * Returns the stored outcome of the last check, or null when the account does not exist or was never checked (or its
	 * credentials changed since).
	 *
	 * `extra` carries `checked_at` (a `DateTimeImmutable` or null) and `currency`.
	 *
	 * @since 2.0.29
	 *
	 * @param int $id Account id.
	 * @return Health_Result|null
	 */
	public function health( int $id ): ?Health_Result {
		$row = $this->row( $id );

		if ( null === $row || ! in_array( (string) $row['health'], Health_Result::statuses(), true ) ) {
			return null;
		}

		return new Health_Result(
			(string) $row['health'],
			(string) ( $row['health_message'] ?? '' ),
			self::parse_time( $row['key_expires_at'] ?? null ),
			[
				'checked_at' => self::parse_time( $row['health_checked_at'] ?? null ),
				'currency'   => (string) ( $row['currency'] ?? '' ),
			]
		);
	}

	/**
	 * Returns the database to the claim trait.
	 *
	 * @return \wpdb
	 */
	protected function claim_db(): \wpdb {
		return $this->db;
	}

	/**
	 * Returns the current Unix time to the claim trait.
	 *
	 * @return int
	 */
	protected function claim_now(): int {
		return $this->now();
	}

	/**
	 * Keeps the quotas the connection test just read, so diagnostics can show them without calling the marketplace.
	 *
	 * What is kept is what the adapter put in `Health_Result::extra()['limits']`: the adapter shapes it for display, the store
	 * adds the identifier and the time. Entries another request wrote into the account settings survive.
	 *
	 * The write carries the tested identifier and secret fingerprint in its SQL (like the health write), so a snapshot of cabinet A
	 * cannot land on an account that was switched to cabinet B while the check was in flight. The snapshot also names its
	 * identifier, and {@see self::replace_identifier()} and {@see self::replace_secrets()} drop it.
	 *
	 * @param int                  $id          Account id.
	 * @param string               $external_id Tested identifier.
	 * @param array<string,string> $secrets     Tested secrets.
	 * @param array<mixed>         $limits      Quotas the adapter read; empty when the check did not read any.
	 * @return void
	 */
	private function remember_limits( int $id, string $external_id, array $secrets, array $limits ): void {
		if ( [] === $limits ) {
			return;
		}

		$snapshot = [
			'external_id' => $external_id,
			'fetched_at'  => $this->now(),
			'limits'      => $limits,
		];

		$this->update_settings(
			$id,
			static function ( array $settings ) use ( $snapshot ): array {
				$settings['limits_snapshot'] = $snapshot;

				return $settings;
			},
			[
				'external_id'        => $external_id,
				'secret_fingerprint' => $this->fingerprint( $secrets ),
			]
		);
	}

	/**
	 * Drops the quota snapshot: it described an identifier or secrets the account no longer has.
	 *
	 * @param int $id Account id.
	 * @return void
	 */
	private function forget_limits( int $id ): void {
		$this->update_settings(
			$id,
			static function ( array $settings ): array {
				unset( $settings['limits_snapshot'] );

				return $settings;
			}
		);
	}

	/**
	 * Tells whether a row still holds every fenced value.
	 *
	 * @param array<string,mixed>  $row   Row.
	 * @param array<string,string> $fence Column => value.
	 * @return bool
	 */
	private static function matches_fence( array $row, array $fence ): bool {
		foreach ( $fence as $column => $value ) {
			if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Inserts a new account row.
	 *
	 * @param Account              $account     Account.
	 * @param string               $external_id Trimmed identifier.
	 * @param array<string,string> $secrets     Secret fields, in the declared order.
	 * @return int New account id.
	 * @throws \RuntimeException When the insert fails.
	 */
	private function insert( Account $account, string $external_id, array $secrets ): int {
		list( $columns, $json ) = $this->split_settings( $account->settings() );

		$data = array_merge(
			$columns,
			$this->secret_columns( $secrets ),
			[
				'name'               => $account->name(),
				'external_id'        => $external_id,
				'is_enabled'         => $account->is_enabled() ? 1 : 0,
				'notification_token' => bin2hex( random_bytes( 16 ) ),
				'settings'           => $this->encode( $json ),
			]
		);

		if ( $account->id() > 0 ) {
			$data['account_id'] = $account->id();
		}

		if ( false === $this->db->insert( $this->table, $data ) ) {
			throw new \RuntimeException( 'The account could not be saved.' );
		}

		return $account->id() > 0 ? $account->id() : (int) $this->db->insert_id;
	}

	/**
	 * Updates an existing account row; `is_enabled` is not part of it.
	 *
	 * @param Account              $account     Account.
	 * @param array<string,mixed>  $existing    The stored row.
	 * @param string               $external_id Trimmed identifier.
	 * @param array<string,string> $secrets     Secret fields; an empty one keeps the stored value.
	 * @return int Account id.
	 * @throws \RuntimeException When the update fails.
	 */
	private function update( Account $account, array $existing, string $external_id, array $secrets ): int {
		list( $columns, $json ) = $this->split_settings( $account->settings() );

		$data = array_merge(
			$columns,
			[
				'name'        => $account->name(),
				'external_id' => $external_id,
				'settings'    => $this->encode( $json ),
			]
		);

		$stored         = $this->stored_secrets( (string) $existing['secret_enc'] );
		$given          = array_filter( $secrets, 'strlen' );
		$merged         = array_merge( $stored ?? $this->fields->secrets_of( [] ), $given );
		$secrets_change = [] !== $given && $merged !== $stored;

		if ( $secrets_change ) {
			$data = array_merge( $data, $this->secret_columns( $merged ) );
		}

		if ( $secrets_change || (string) $existing['external_id'] !== $external_id ) {
			$data = array_merge( $data, $this->forgotten_health() );
		}

		if ( false === $this->db->update( $this->table, $data, [ 'account_id' => $account->id() ] ) ) {
			throw new \RuntimeException( 'The account could not be saved.' );
		}

		return $account->id();
	}

	/**
	 * Column values of a health outcome.
	 *
	 * @param Health_Result $result Check outcome.
	 * @return array<string,mixed>
	 */
	private function health_columns( Health_Result $result ): array {
		$data = [
			'health'            => $result->status(),
			'health_message'    => $result->message(),
			'health_checked_at' => $this->format_time( $this->now() ),
		];

		if ( null !== $result->expires_at() ) {
			$data['key_expires_at'] = $this->format_time( $result->expires_at()->getTimestamp() );
		}

		$currency = (string) ( $result->extra()['currency'] ?? '' );
		if ( '' !== $currency ) {
			$data['currency'] = $currency;
		}

		return $data;
	}

	/**
	 * Column values of an account whose credentials were never checked.
	 *
	 * @return array<string,mixed>
	 */
	private function forgotten_health(): array {
		return [
			'health'            => 'unknown',
			'health_message'    => null,
			'health_checked_at' => null,
			'key_expires_at'    => null,
		];
	}

	/**
	 * Column values that store a set of secrets.
	 *
	 * @param array<string,string> $secrets Secret fields, in the declared order.
	 * @return array<string,string>
	 */
	private function secret_columns( array $secrets ): array {
		return [
			'secret_enc'         => $this->cipher->encrypt( (string) wp_json_encode( $secrets ) ),
			'secret_fingerprint' => $this->fingerprint( $secrets ),
		];
	}

	/**
	 * Fingerprint of a set of secrets, comparable without opening the ciphertext.
	 *
	 * @param array<string,string> $secrets Secret fields.
	 * @return string
	 */
	private function fingerprint( array $secrets ): string {
		return Secret_Cipher::fingerprint( (string) wp_json_encode( $secrets ) );
	}

	/**
	 * Opens stored secrets.
	 *
	 * @param string $stored The `secret_enc` value.
	 * @return array<string,string>|null Secret field => value in the declared order; null when the ciphertext cannot be opened.
	 */
	private function stored_secrets( string $stored ): ?array {
		$plain   = $this->cipher->decrypt( $stored );
		$decoded = null === $plain ? null : json_decode( $plain, true );

		return is_array( $decoded ) ? $this->fields->secrets_of( $decoded ) : null;
	}

	/**
	 * Reads one row.
	 *
	 * @param int $id Account id.
	 * @return array<string,mixed>|null
	 */
	private function row( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->table} WHERE account_id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is ours.
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Builds the account value of a row.
	 *
	 * @param array<string,mixed> $row Table row.
	 * @return Account
	 */
	private function to_account( array $row ): Account {
		$stored   = (string) $row['secret_enc'];
		$secrets  = $this->stored_secrets( $stored );
		$settings = $this->decode( $row['settings'] ?? null );

		foreach ( self::KNOB_COLUMNS as $column ) {
			$settings[ $column ] = self::typed( $column, $row[ $column ] ?? null );
		}

		$settings['notification_token']  = (string) ( $row['notification_token'] ?? '' );
		$settings['notification_state']  = $this->decode( $row['notification_state'] ?? null );
		$settings['seller_id']           = null === ( $row['seller_id'] ?? null ) ? null : (int) $row['seller_id'];
		$settings['secret_fingerprint']  = (string) ( $row['secret_fingerprint'] ?? '' );

		return new Account(
			(int) $row['account_id'],
			$this->adapter_id,
			(string) $row['name'],
			1 === (int) $row['is_enabled'],
			array_merge(
				[ $this->fields->identifier() => (string) $row['external_id'] ],
				$secrets ?? $this->fields->secrets_of( [] ),
				[
					self::CREDENTIAL_UNREADABLE     => null === $secrets,
					self::CREDENTIAL_UNREADABLE_ENC => null === $secrets ? $stored : '',
				]
			),
			self::parse_time( $row['key_expires_at'] ?? null ),
			$settings
		);
	}

	/**
	 * Splits an account's settings into the columns that store them and the JSON of the rest.
	 *
	 * @param array<string,mixed> $settings `Account::settings()`.
	 * @return array{0:array<string,mixed>,1:array<string,mixed>} Column values and the remaining entries.
	 */
	private function split_settings( array $settings ): array {
		$columns = [];

		foreach ( self::KNOB_COLUMNS as $column ) {
			if ( array_key_exists( $column, $settings ) ) {
				$columns[ $column ] = self::stored( $column, $settings[ $column ] );
			}
			unset( $settings[ $column ] );
		}

		foreach ( self::READ_ONLY_COLUMNS as $column ) {
			unset( $settings[ $column ] );
		}

		return [ $columns, $settings ];
	}

	/**
	 * Converts a column value into the type a caller reads from `Account::settings()`.
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Raw value.
	 * @return mixed
	 */
	private static function typed( string $column, $value ) {
		switch ( $column ) {
			case 'warehouse_id':
				return null === $value || '' === $value ? null : (int) $value;
			case 'orders_enabled':
			case 'import_enabled':
				return 1 === (int) $value;
		}

		return (string) $value;
	}

	/**
	 * Converts a setting into the value its column stores.
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Setting value.
	 * @return mixed
	 */
	private static function stored( string $column, $value ) {
		switch ( $column ) {
			case 'warehouse_id':
				return null === $value || '' === $value || 0 === (int) $value ? null : (int) $value;
			case 'orders_enabled':
			case 'import_enabled':
				return $value ? 1 : 0;
		}

		return (string) $value;
	}

	/**
	 * Encodes the JSON column.
	 *
	 * @param array<string,mixed> $value Entries.
	 * @return string
	 */
	private function encode( array $value ): string {
		return [] === $value ? '{}' : (string) wp_json_encode( $value );
	}

	/**
	 * Decodes a JSON column.
	 *
	 * @param mixed $value Column value.
	 * @return array<string,mixed>
	 */
	private function decode( $value ): array {
		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Current Unix time.
	 *
	 * @return int
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * Formats a Unix time for a datetime column (UTC).
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function format_time( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Reads a datetime column (UTC).
	 *
	 * @param mixed $value Column value.
	 * @return \DateTimeImmutable|null
	 */
	private static function parse_time( $value ): ?\DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		$time = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );

		return false === $time ? null : $time;
	}
}
