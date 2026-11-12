<?php
/**
 * The product link store in the `links` table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Links\Link_Store;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Price\Price_Override;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the links table.
 *
 * Two audiences share it:
 *
 * - the marketplace layer, through {@see Link_Store} and the shared {@see Product_Link} value (the remote identifiers travel in its
 *   `remote_ids` bag, whatever its names and values: they are kept whole in the `link_ids` table and found by name and value);
 * - the flows and screens, through {@see Link_Record}, which also carries the overrides, the mapping and the row kind.
 *
 * **The migration writes only through {@see self::insert_if_absent()}**: an existing link is never overwritten, so a re-run cannot
 * undo what the plugin has done since.
 */
final class Wpdb_Link_Store implements Link_Store {
	/**
	 * Flags of {@see Product_Link} that are columns.
	 *
	 * @var string[]
	 */
	private const FLAG_COLUMNS = [ 'sync_stock', 'sync_price', 'is_oversize' ];

	/**
	 * Outcomes of {@see self::insert_card()}.
	 */
	public const INSERTED   = 'inserted';
	public const EXISTS     = 'exists';
	public const FENCED_OUT = 'fenced_out';

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
	 * Full name of the remote identifiers table.
	 *
	 * @var string
	 */
	private $ids_table;

	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the store.
	 *
	 * @param \wpdb         $db        Database.
	 * @param string        $table     Full name of the links table.
	 * @param string        $ids_table Full name of the remote identifiers table (`link_ids`).
	 * @param callable|null $clock     Returns the Unix time; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, string $ids_table, ?callable $clock = null ) {
		$this->db        = $db;
		$this->table     = $table;
		$this->ids_table = $ids_table;
		$this->clock     = $clock ?? 'time';
	}

	/**
	 * Finds the row of a product or variation, whatever its kind.
	 *
	 * @param int $account_id    Account id.
	 * @param int $wc_product_id Product or variation id.
	 * @return Link_Record|null
	 */
	public function find( int $account_id, int $wc_product_id ): ?Link_Record {
		return $this->one(
			$this->db->prepare( 'SELECT * FROM %i WHERE account_id = %d AND wc_product_id = %d', $this->table, $account_id, $wc_product_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
		);
	}

	/**
	 * Finds the offer row that owns an `offer_id`.
	 *
	 * @param int    $account_id Account id.
	 * @param string $offer_id   Seller article.
	 * @return Link_Record|null
	 */
	public function find_by_offer_id( int $account_id, string $offer_id ): ?Link_Record {
		return $this->one(
			$this->db->prepare( 'SELECT * FROM %i WHERE account_id = %d AND offer_id = %s', $this->table, $account_id, $offer_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
		);
	}

	/**
	 * Lists what each offer row of an account already says about its card: the article and whether the marketplace has given it any
	 * identifier. Linking by article reads it to leave every product that already has an identity alone.
	 *
	 * @param int $account_id Account id.
	 * @return array<int,array{offer_id:?string,has_remote_ids:bool,card_state:string}> By WooCommerce product id.
	 */
	public function identity_map( int $account_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT wc_product_id, offer_id, card_state, EXISTS (SELECT 1 FROM %i AS known WHERE known.link_id = %i.link_id) AS has_remote_ids FROM %i WHERE account_id = %d AND kind = %s ORDER BY wc_product_id ASC', $this->ids_table, $this->table, $this->table, $account_id, Link_Record::KIND_OFFER ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
			'ARRAY_A'
		);
		$map  = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$offer_id = $row['offer_id'];

			$map[ (int) $row['wc_product_id'] ] = [
				'offer_id'       => null === $offer_id || '' === $offer_id ? null : (string) $offer_id,
				'has_remote_ids' => 1 === (int) $row['has_remote_ids'],
				'card_state'     => (string) $row['card_state'],
			];
		}

		return $map;
	}

	/**
	 * Attaches an existing marketplace card to an offer row that has no card yet: the article, the remote ids, the state the
	 * marketplace reports and, for a failed card, its text.
	 *
	 * Only a row that is idle (`unlinked`, no article, no remote identifier, no task) is touched, so a row an export has queued or sent
	 * is never rewritten; with a fence the write also depends on the account claim.
	 *
	 * @param int                 $link_id Link id.
	 * @param array<string,mixed> $card    `offer_id`, `remote_ids` (name => id), `state`, `is_created`, `error_text`.
	 * @param Claim_Fence|null    $fence   The account claim the run holds, or null for a request-time link.
	 * @return bool True when the row now holds the card.
	 */
	public function adopt_card( int $link_id, array $card, ?Claim_Fence $fence = null ): bool {
		list( $fence_sql, $fence_args ) = $this->fence_clause( $fence );

		$error         = (string) ( $card['error_text'] ?? '' );
		$has_oversize  = array_key_exists( 'is_oversize', $card );
		$oversize_sql  = $has_oversize ? ', is_oversize = %d' : '';
		$oversize_args = $has_oversize ? [ (int) $card['is_oversize'] ] : [];

		$sql = $this->db->prepare(
			'UPDATE %i SET offer_id = %s, card_state = %s, is_created = %d' . $oversize_sql . ', last_error_code = NULL, last_error_text = ' . ( '' === $error ? 'NULL' : '%s' ) . ', updated_at = %s WHERE link_id = %d AND kind = %s AND card_state = %s AND NOT EXISTS (SELECT 1 FROM %i AS known WHERE known.link_id = %i.link_id) AND offer_id IS NULL AND last_task_id IS NULL' . $fence_sql, // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
			array_merge(
				[ $this->table, (string) $card['offer_id'], (string) $card['state'], ! empty( $card['is_created'] ) ? 1 : 0 ],
				$oversize_args,
				'' === $error ? [] : [ $error ],
				[ $this->format_time( $this->now() ), $link_id, Link_Record::KIND_OFFER, Link_Record::STATE_UNLINKED, $this->ids_table, $this->table ],
				$fence_args
			)
		);

		return $this->atomically(
			function () use ( $sql, $link_id, $card ): bool {
				if ( 1 !== $this->affected( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
					return false;
				}

				$this->write_ids( $link_id, $this->account_of( $link_id ), (array) ( $card['remote_ids'] ?? [] ), false );

				return true;
			}
		);
	}

	/**
	 * Inserts a row unless the account already has one for that product or that `offer_id`.
	 *
	 * Insert-only on the natural keys: an existing row, whatever it holds, is left alone.
	 *
	 * @param Link_Record $record Row to insert; its link id is ignored.
	 * @return bool True when a row was inserted, false when a row already existed.
	 * @throws Persistence_Exception When the database refuses the insert.
	 */
	public function insert_if_absent( Link_Record $record ): bool {
		if ( [] === $record->remote_ids() ) {
			return $this->insert_ignore( $record );
		}

		return $this->atomically(
			function () use ( $record ): bool {
				if ( ! $this->insert_ignore( $record ) ) {
					return false;
				}

				$this->write_ids( (int) $this->db->insert_id, $record->account_id(), $record->remote_ids(), false );

				return true;
			}
		);
	}

	/**
	 * Inserts the row of a link without its identifiers.
	 *
	 * @param Link_Record $record Row to insert.
	 * @return bool True when a row was inserted, false when a row already existed.
	 * @throws Persistence_Exception When the database refuses the insert.
	 */
	private function insert_ignore( Link_Record $record ): bool {
		$columns               = $record->to_columns();
		$columns['updated_at'] = $this->format_time( $this->now() );
		$columns['card_state'] = '' === $columns['card_state'] ? Link_Record::STATE_UNLINKED : $columns['card_state'];
		$names                 = array_keys( $columns );
		$placeholders          = [];
		$values                = [];

		foreach ( $columns as $value ) {
			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}

			$placeholders[] = is_int( $value ) ? '%d' : '%s';
			$values[]       = $value;
		}

		$sql = 'INSERT IGNORE INTO %i (' . implode( ', ', array_fill( 0, count( $names ), '%i' ) ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';

		$changed = $this->db->query( $this->db->prepare( $sql, array_merge( [ $this->table ], $names, $values ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- Only fixed fragments and placeholders are concatenated.

		if ( false === $changed ) {
			throw Persistence_Exception::database( 'the link of product ' . $record->wc_product_id(), (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return 1 === $changed;
	}

	/**
	 * Inserts the row of an existing marketplace card, with the marketplace's text for a declined card in the same statement.
	 *
	 * With a fence the statement itself is conditional on the account claim — `INSERT … SELECT … FROM accounts WHERE the token is
	 * still held` — so a run whose claim was taken over inserts nothing; without one (a request-time link) it is the
	 * plain insert-if-absent. An existing row for the product or the article is never touched.
	 *
	 * @param Link_Record      $record     Row to insert; its link id is ignored.
	 * @param string           $error_text The marketplace's text for a declined card, '' for none.
	 * @param Claim_Fence|null $fence      The account claim the run holds, or null.
	 * @return string {@see self::INSERTED}, {@see self::EXISTS} (a row for the product or the article is there) or {@see self::FENCED_OUT}.
	 * @throws Persistence_Exception When the database refuses the insert.
	 */
	public function insert_card( Link_Record $record, string $error_text = '', ?Claim_Fence $fence = null ): string {
		if ( [] === $record->remote_ids() ) {
			return $this->insert_card_row( $record, $error_text, $fence );
		}

		return $this->atomically(
			function () use ( $record, $error_text, $fence ): string {
				$outcome = $this->insert_card_row( $record, $error_text, $fence );

				if ( self::INSERTED === $outcome ) {
					$this->write_ids( (int) $this->db->insert_id, $record->account_id(), $record->remote_ids(), false );
				}

				return $outcome;
			}
		);
	}

	/**
	 * Inserts the row of an existing card without its identifiers.
	 *
	 * @param Link_Record      $record     Row to insert.
	 * @param string           $error_text The marketplace's text for a declined card, '' for none.
	 * @param Claim_Fence|null $fence      The account claim the run holds, or null.
	 * @return string {@see self::INSERTED}, {@see self::EXISTS} or {@see self::FENCED_OUT}.
	 * @throws Persistence_Exception When the database refuses the insert.
	 */
	private function insert_card_row( Link_Record $record, string $error_text, ?Claim_Fence $fence ): string {
		$columns               = $record->to_columns();
		$columns['updated_at'] = $this->format_time( $this->now() );
		$columns['card_state'] = '' === $columns['card_state'] ? Link_Record::STATE_UNLINKED : $columns['card_state'];

		if ( '' !== $error_text ) {
			$columns['last_error_text'] = $error_text;
		}

		$names        = array_keys( $columns );
		$placeholders = [];
		$values       = [];

		foreach ( $columns as $value ) {
			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}

			$placeholders[] = is_int( $value ) ? '%d' : '%s';
			$values[]       = $value;
		}

		$head = 'INSERT IGNORE INTO %i (' . implode( ', ', array_fill( 0, count( $names ), '%i' ) ) . ') ';
		$args = array_merge( [ $this->table ], $names, $values );

		if ( null === $fence ) {
			$sql = $head . 'VALUES (' . implode( ', ', $placeholders ) . ')';
		} else {
			$sql    = $head . 'SELECT ' . implode( ', ', $placeholders ) . ' FROM %i AS claim_owner WHERE claim_owner.account_id = %d AND claim_owner.claim_token = %s AND claim_owner.claim_until > %s';
			$args[] = $fence->table();
			$args[] = $record->account_id();
			$args[] = $fence->token();
			$args[] = $this->format_time( $this->now() );
		}

		$changed = $this->db->query( $this->db->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- Only fixed fragments and placeholders are concatenated.

		if ( false === $changed ) {
			throw Persistence_Exception::database( 'the link of product ' . $record->wc_product_id(), (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		if ( 1 === $changed ) {
			return self::INSERTED;
		}

		return null !== $fence && ! $this->claim_held( $record->account_id(), $fence ) ? self::FENCED_OUT : self::EXISTS;
	}

	/**
	 * Tells whether a run still holds the claim of an account.
	 *
	 * @param int         $account_id Account id.
	 * @param Claim_Fence $fence      The run's claim.
	 * @return bool
	 */
	public function claim_held( int $account_id, Claim_Fence $fence ): bool {
		$sql = $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE account_id = %d AND claim_token = %s AND claim_until > %s', $fence->table(), $account_id, $fence->token(), $this->format_time( $this->now() ) );

		return 1 === (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
	}

	/**
	 * Lists rows of one account in link id order, after a cursor.
	 *
	 * @param int $account_id    Account id.
	 * @param int $after_link_id Last link id already seen; 0 starts from the beginning.
	 * @param int $limit         Rows per page.
	 * @return Link_Record[]
	 */
	public function page( int $account_id, int $after_link_id, int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT * FROM %i WHERE account_id = %d AND link_id > %d ORDER BY link_id ASC LIMIT %d', $this->table, $account_id, $after_link_id, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
			'ARRAY_A'
		);

		return $this->with_ids( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Counts the rows of an account.
	 *
	 * @param int         $account_id Account id.
	 * @param string|null $kind       `offer` or `parent`; null counts both.
	 * @return int
	 */
	public function count( int $account_id, ?string $kind = null ): int {
		$sql = null === $kind
			? $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE account_id = %d', $this->table, $account_id )
			: $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE account_id = %d AND kind = %s', $this->table, $account_id, $kind );

		return (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
	}

	/**
	 * Records what the marketplace says about a card the link did not know yet, without overwriting what is known.
	 *
	 * An identifier is written only while the link does not have one of that name (an identifier never changes once the marketplace
	 * assigned it), so a stale answer cannot erase a newer one. Used by a validation pass that runs under the claim of the link's
	 * account row: the state write carries that claim in its `WHERE`, and the identifiers are written only while it is still held, so
	 * a validator whose claim was taken over changes nothing.
	 *
	 * @param int                      $link_id        Link id.
	 * @param array<string,int|string> $remote_ids     Identifiers the marketplace reported; an empty value is «not known yet».
	 * @param string                   $card_state     New card state.
	 * @param bool                     $is_created     Whether the marketplace says the card is created.
	 * @param string                   $accounts_table Full name of the accounts table.
	 * @param string                   $claim_token    Token that must still own the link's account row.
	 * @return bool False when the claim is no longer held (or the link does not exist).
	 */
	public function fill_remote_state( int $link_id, array $remote_ids, string $card_state, bool $is_created, string $accounts_table, string $claim_token ): bool {
		$now = $this->format_time( $this->now() );
		$sql = $this->db->prepare(
			'UPDATE %i SET card_state = %s, is_created = %d, updated_at = %s WHERE link_id = %d AND EXISTS (SELECT 1 FROM %i AS claim_owner WHERE claim_owner.account_id = %i.account_id AND claim_owner.claim_token = %s AND claim_owner.claim_until > %s)',
			$this->table,
			$card_state,
			$is_created ? 1 : 0,
			$now,
			$link_id,
			$accounts_table,
			$this->table,
			$claim_token,
			$now
		);

		return $this->atomically(
			function () use ( $sql, $link_id, $remote_ids, $accounts_table, $claim_token ): bool {
				$account_id = $this->account_of( $link_id );
				$changed    = $this->affected( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

				// MySQL counts a row whose new values equal the old ones as unchanged: then the claim must still be visible.
				if ( 1 !== $changed && ! $this->claim_held( $account_id, new Claim_Fence( $accounts_table, $claim_token ) ) ) {
					return false;
				}

				$this->write_ids( $link_id, $account_id, $remote_ids, true );

				return true;
			}
		);
	}

	/**
	 * Finds the link of a WooCommerce product or variation.
	 *
	 * @param int $account_id    Account id.
	 * @param int $wc_product_id Product or variation id.
	 * @return Product_Link|null Null for a missing row and for a parent row (a parent is never a card).
	 */
	public function by_product( int $account_id, int $wc_product_id ): ?Product_Link {
		$record = $this->find( $account_id, $wc_product_id );

		return null !== $record && $record->is_offer() ? $this->to_product_link( $record ) : null;
	}

	/**
	 * Finds a link by the seller article (`offer_id`).
	 *
	 * @param int    $account_id Account id.
	 * @param string $sku        Seller article.
	 * @return Product_Link|null
	 */
	public function by_seller_sku( int $account_id, string $sku ): ?Product_Link {
		$record = $this->find_by_offer_id( $account_id, $sku );

		return null === $record ? null : $this->to_product_link( $record );
	}

	/**
	 * Finds a link by one of its remote identifiers, whatever the adapter calls it (`product_id`, `nmID`, …). The value is compared as text.
	 *
	 * @param int        $account_id Account id.
	 * @param string     $key        Identifier name.
	 * @param int|string $value      Identifier value.
	 * @return Product_Link|null
	 */
	public function by_remote_id( int $account_id, string $key, $value ): ?Product_Link {
		$value = (string) $value;

		if ( '' === $key || '' === $value || '0' === $value ) {
			return null;
		}

		$link_id = $this->db->get_var(
			$this->db->prepare( 'SELECT link_id FROM %i WHERE account_id = %d AND id_name = %s AND id_value = %s ORDER BY link_id ASC LIMIT 1', $this->ids_table, $account_id, $key, $value ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
		);

		if ( null === $link_id ) {
			return null;
		}

		$record = $this->get( (int) $link_id );

		return null !== $record && $record->is_offer() ? $this->to_product_link( $record ) : null;
	}

	/**
	 * Inserts or updates the shared part of a link (identifiers, state, switches, warehouse). The identifiers it carries are written; one it does not carry is left as it is.
	 *
	 * An update leaves the overrides, the mapping and the row kind alone: they belong to the flows that hold a {@see Link_Record}.
	 *
	 * @param Product_Link $link Link to save; a null link id inserts.
	 * @return int Saved link id.
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function save( Product_Link $link ): int {
		$existing = null === $link->link_id() ? $this->find( $link->account_id(), $link->wc_product_id() ) : null;
		$link_id  = $link->link_id() ?? ( null === $existing ? null : $existing->link_id() );
		$shared   = $this->shared_columns( $link );

		if ( null === $link_id ) {
			$shared['remote_ids'] = $link->remote_ids();
			$record               = Link_Record::offer( $link->account_id(), $link->wc_product_id(), $link->wc_parent_id(), $link->seller_sku(), $shared );

			if ( ! $this->insert_if_absent( $record ) ) {
				throw new \RuntimeException( 'The link could not be saved.' );
			}

			return (int) $this->find( $link->account_id(), $link->wc_product_id() )->link_id();
		}

		$shared['updated_at'] = $this->format_time( $this->now() );

		$this->atomically(
			function () use ( $shared, $link_id, $link ): void {
				if ( false === $this->db->update( $this->table, $shared, [ 'link_id' => $link_id ] ) ) {
					throw new \RuntimeException( 'The link could not be saved.' );
				}

				$this->write_ids( $link_id, $link->account_id(), $link->remote_ids(), false );
			}
		);

		return $link_id;
	}

	/**
	 * Changes the state of a link and records the last error text.
	 *
	 * @param int         $link_id Link id.
	 * @param string      $state   New state.
	 * @param string|null $error   Marketplace error text, or null to clear it.
	 * @return void
	 */
	public function set_state( int $link_id, string $state, ?string $error = null ): void {
		$this->db->update(
			$this->table,
			[
				'card_state'      => $state,
				'last_error_text' => $error,
				'updated_at'      => $this->format_time( $this->now() ),
			],
			[ 'link_id' => $link_id ]
		);
	}

	/**
	 * Puts a validated offer row into the export queue (`unlinked` → `queued`) and records its article. The
	 * article is written only while it is not frozen. A row being submitted right now and an archived card are left alone; any
	 * other row (a created card included: an update resends the whole card) is queued and loses its task fence, so a poll of an
	 * older task no longer moves it.
	 *
	 * @param int    $link_id  Link id.
	 * @param string $offer_id Article the card will be sent with.
	 * @return bool True when the row is now queued.
	 */
	public function queue( int $link_id, string $offer_id ): bool {
		$now = $this->format_time( $this->now() );
		$sql = $this->db->prepare(
			'UPDATE %i SET card_state = %s, offer_id = CASE WHEN NOT EXISTS (SELECT 1 FROM %i AS known WHERE known.link_id = %i.link_id) AND card_state IN (%s, %s, %s) THEN %s ELSE offer_id END, last_task_id = NULL, last_error_code = NULL, last_error_text = NULL, updated_at = %s WHERE link_id = %d AND kind = %s AND card_state NOT IN (%s, %s)',
			$this->table,
			Link_Record::STATE_QUEUED,
			$this->ids_table,
			$this->table,
			Link_Record::STATE_UNLINKED,
			Link_Record::STATE_QUEUED,
			Link_Record::STATE_FAILED,
			$offer_id,
			$now,
			$link_id,
			Link_Record::KIND_OFFER,
			Link_Record::STATE_SUBMITTED,
			Link_Record::STATE_ARCHIVED
		);

		return 1 === $this->affected( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Stores the validation result of a product that was not queued without changing its state.
	 *
	 * @param int    $link_id Link id.
	 * @param string $code    First error code.
	 * @param string $text    The report, for the merchant.
	 * @return void
	 */
	public function record_blocked( int $link_id, string $code, string $text ): void {
		$this->db->update(
			$this->table,
			[
				'last_error_code' => $code,
				'last_error_text' => $text,
				'updated_at'      => $this->format_time( $this->now() ),
			],
			[ 'link_id' => $link_id ]
		);
	}

	/**
	 * Takes queued rows for a task: `queued` → `submitted` with `last_task_id`, in one UPDATE, then reads back the rows it won.
	 *
	 * A second runner holding the same link ids wins nothing, so a card is never sent twice in parallel; `last_task_id` is the
	 * generation every later write of this task carries, and the account claim (when given) the run's ownership.
	 *
	 * @param int              $task_id  Task id.
	 * @param int[]            $link_ids Link ids.
	 * @param Claim_Fence|null $fence    The account claim the run holds, or null.
	 * @return Link_Record[] The rows now owned by the task.
	 */
	public function claim_for_task( int $task_id, array $link_ids, ?Claim_Fence $fence = null ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $link_ids ) ) ) );

		if ( [] === $ids ) {
			return [];
		}

		list( $fence_sql, $fence_args ) = $this->fence_clause( $fence );

		$in  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql = $this->db->prepare(
			'UPDATE %i SET card_state = %s, last_task_id = %d, updated_at = %s WHERE kind = %s AND card_state = %s AND link_id IN (' . $in . ')' . $fence_sql, // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
			array_merge( [ $this->table, Link_Record::STATE_SUBMITTED, $task_id, $this->format_time( $this->now() ), Link_Record::KIND_OFFER, Link_Record::STATE_QUEUED ], $ids, $fence_args )
		);
		$this->affected( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		return $this->by_task( $task_id, [ Link_Record::STATE_SUBMITTED ] );
	}

	/**
	 * Lists the tasks that still own unfinished offer rows: rows in one of the given states whose task fence is set. The recovery
	 * sweep reads it, so such work is found whatever the age of its task.
	 *
	 * @param string[] $states Card states.
	 * @param int      $limit  Most task ids, oldest first.
	 * @return int[]
	 */
	public function owning_task_ids( array $states, int $limit ): array {
		if ( [] === $states ) {
			return [];
		}

		$sql = $this->db->prepare(
			'SELECT DISTINCT last_task_id FROM %i WHERE kind = %s AND last_task_id IS NOT NULL AND card_state IN (' . implode( ', ', array_fill( 0, count( $states ), '%s' ) ) . ') ORDER BY last_task_id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
			array_merge( [ $this->table, Link_Record::KIND_OFFER ], array_values( $states ), [ $limit ] )
		);

		$rows = $this->db->get_results( $sql, 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.

		return array_map( 'intval', array_column( is_array( $rows ) ? $rows : [], 'last_task_id' ) );
	}

	/**
	 * Tells whether a task still owns a row in the state a run read: the read side of {@see self::advance()}'s fence, for a side
	 * effect that must not follow a reset or a newer task.
	 *
	 * @param int    $link_id Link id.
	 * @param int    $task_id Task that must own the row.
	 * @param string $state   State the run read.
	 * @return bool
	 */
	public function still_owns( int $link_id, int $task_id, string $state ): bool {
		$sql = $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE link_id = %d AND last_task_id = %d AND card_state = %s', $this->table, $link_id, $task_id, $state );

		return 1 === (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
	}

	/**
	 * Lists the rows a task owns.
	 *
	 * @param int      $task_id Task id.
	 * @param string[] $states  Card states to keep; empty keeps every state.
	 * @return Link_Record[]
	 */
	public function by_task( int $task_id, array $states = [] ): array {
		$sql = $this->db->prepare( 'SELECT * FROM %i WHERE last_task_id = %d', $this->table, $task_id );

		if ( [] !== $states ) {
			$sql .= $this->db->prepare( ' AND card_state IN (' . implode( ', ', array_fill( 0, count( $states ), '%s' ) ) . ')', $states ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
		}

		$rows = $this->db->get_results( $sql . ' ORDER BY link_id ASC', 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.

		return $this->with_ids( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Lists the offer rows of a product in every account: the product's own and, for a variable product, its variations'.
	 *
	 * @param int $wc_product_id Product id.
	 * @return Link_Record[]
	 */
	public function offers_of_product( int $wc_product_id ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read fresh.
			$this->db->prepare( 'SELECT * FROM %i WHERE kind = %s AND (wc_product_id = %d OR wc_parent_id = %d) ORDER BY link_id ASC', $this->table, Link_Record::KIND_OFFER, $wc_product_id, $wc_product_id ),
			'ARRAY_A'
		);

		return $this->with_ids( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Records what the marketplace answered for a link's stock: the quantity it accepted (`last_pushed_stock`, for diagnostics and
	 * reconcile) and its last per-item error verbatim, or clears the error. Fenced by the account claim of the sender.
	 *
	 * @param int         $link_id  Link id.
	 * @param int|null    $quantity Quantity the marketplace accepted; null leaves it.
	 * @param string|null $code     The marketplace's code; null clears code and text.
	 * @param string|null $text     The marketplace's text.
	 * @param Claim_Fence $fence    The sender's account claim.
	 * @return bool False when the claim was lost.
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function record_stock_outcome( int $link_id, ?int $quantity, ?string $code, ?string $text, Claim_Fence $fence ): bool {
		$columns = [
			'last_error_code' => null === $code ? null : substr( $code, 0, 64 ),
			'last_error_text' => null === $code ? null : $text,
		];

		if ( null !== $quantity ) {
			$columns['last_pushed_stock']    = $quantity;
			$columns['last_pushed_stock_at'] = $this->format_time( $this->now() );
		}

		return $this->update_fenced( $link_id, $columns, $fence );
	}

	/**
	 * Marks a link archived on the marketplace, fenced by the account claim of the sender.
	 *
	 * @param int         $link_id Link id.
	 * @param string|null $text    Reason, or null.
	 * @param Claim_Fence $fence   The sender's account claim.
	 * @return bool False when the claim was lost.
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function mark_archived( int $link_id, ?string $text, Claim_Fence $fence ): bool {
		return $this->update_fenced(
			$link_id,
			[
				'card_state'      => Link_Record::STATE_ARCHIVED,
				'last_error_text' => $text,
				'updated_at'      => $this->format_time( $this->now() ),
			],
			$fence
		);
	}

	/**
	 * Updates columns of one link only while the fence's claim is held; an SQL error throws instead of reading as a lost claim.
	 *
	 * @param int                 $link_id Link id.
	 * @param array<string,mixed> $columns Column => value (null writes NULL).
	 * @param Claim_Fence         $fence   Account claim.
	 * @return bool False when the claim was lost or the link does not exist.
	 * @throws \RuntimeException When the database refuses the write.
	 */
	private function update_fenced( int $link_id, array $columns, Claim_Fence $fence ): bool {
		$set    = [];
		$values = [ $this->table ];

		foreach ( $columns as $name => $value ) {
			$set[]    = null === $value ? '%i = NULL' : ( is_int( $value ) ? '%i = %d' : '%i = %s' );
			$values[] = $name;

			if ( null !== $value ) {
				$values[] = $value;
			}
		}

		$values[] = $link_id;

		list( $fence_sql, $fence_args ) = $this->fence_clause( $fence );

		$changed = $this->db->query( $this->db->prepare( 'UPDATE %i SET ' . implode( ', ', $set ) . ' WHERE link_id = %d' . $fence_sql, array_merge( $values, $fence_args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Only fixed fragments and placeholders are concatenated.

		if ( false === $changed ) {
			throw new \RuntimeException( 'A link write was refused by the database: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		if ( 1 === $changed ) {
			return true;
		}

		// MySQL counts a row whose new values equal the old ones as unchanged: then the fenced row must still be visible.
		$held = $this->db->get_var( $this->db->prepare( 'SELECT 1 FROM %i WHERE link_id = %d' . $fence_sql, array_merge( [ $this->table, $link_id ], $fence_args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Only fixed fragments and placeholders are concatenated.

		return null !== $held;
	}

	/**
	 * Moves a row of a task to a new state, only while the task still owns it and, when given, while the run still holds the
	 * account's claim and the row is still in the state the run read.
	 *
	 * @param int                 $link_id    Link id.
	 * @param int                 $task_id    Task that must own the row.
	 * @param string              $state      New card state.
	 * @param array<string,mixed> $columns    Other columns to write (`is_created`, `last_error_code`, `last_error_text`, `last_task_id`) and, under `remote_ids`, identifiers to set (name => id).
	 * @param Claim_Fence|null    $fence      The account claim the run must still hold, or null.
	 * @param string|null         $from_state The state the run read; the write happens only if the row still has it.
	 * @return bool False when another task owns the row now, a reset took it back, the claim was lost or the state moved on.
	 */
	public function advance( int $link_id, int $task_id, string $state, array $columns = [], ?Claim_Fence $fence = null, ?string $from_state = null ): bool {
		$remote_ids = (array) ( $columns['remote_ids'] ?? [] );
		unset( $columns['remote_ids'] );

		$columns['card_state'] = $state;
		$columns['updated_at'] = $this->format_time( $this->now() );
		$set                   = [];
		$values                = [ $this->table ];

		foreach ( $columns as $name => $value ) {
			$set[]    = null === $value ? '%i = NULL' : ( is_int( $value ) ? '%i = %d' : '%i = %s' );
			$values[] = $name;

			if ( null !== $value ) {
				$values[] = $value;
			}
		}

		$where    = 'link_id = %d AND last_task_id = %d AND card_state <> %s';
		$values[] = $link_id;
		$values[] = $task_id;
		$values[] = Link_Record::STATE_UNLINKED;

		if ( null !== $from_state ) {
			$where   .= ' AND card_state = %s';
			$values[] = $from_state;
		}

		list( $fence_sql, $fence_args ) = $this->fence_clause( $fence );

		$sql = $this->db->prepare( 'UPDATE %i SET ' . implode( ', ', $set ) . ' WHERE ' . $where . $fence_sql, array_merge( $values, $fence_args ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.

		return $this->atomically(
			function () use ( $sql, $link_id, $remote_ids ): bool {
				if ( 1 !== $this->affected( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
					return false;
				}

				$this->write_ids( $link_id, $this->account_of( $link_id ), $remote_ids, false );

				return true;
			}
		);
	}

	/**
	 * Builds the `AND EXISTS (…)` clause that makes a write depend on the account claim (the form of {@see self::fill_remote_state()}).
	 *
	 * @param Claim_Fence|null $fence Claim, or null for none.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function fence_clause( ?Claim_Fence $fence ): array {
		if ( null === $fence ) {
			return [ '', [] ];
		}

		return [
			' AND EXISTS (SELECT 1 FROM %i AS claim_owner WHERE claim_owner.account_id = %i.account_id AND claim_owner.claim_token = %s AND claim_owner.claim_until > %s)',
			[ $fence->table(), $this->table, $fence->token(), $this->format_time( $this->now() ) ],
		];
	}

	/**
	 * «Сбросить состояние» puts offer rows back to `unlinked` without touching the marketplace. The remote ids and the article
	 * stay, so a later export updates the same card; the task fence and the error are cleared, so a poll still running for an old
	 * task changes nothing.
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Link ids.
	 * @return int Rows reset.
	 */
	public function reset_state( int $account_id, array $link_ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $link_ids ) ) ) );

		if ( [] === $ids ) {
			return 0;
		}

		$sql = $this->db->prepare(
			'UPDATE %i SET card_state = %s, last_task_id = NULL, last_error_code = NULL, last_error_text = NULL, updated_at = %s WHERE account_id = %d AND kind = %s AND link_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
			array_merge( [ $this->table, Link_Record::STATE_UNLINKED, $this->format_time( $this->now() ), $account_id, Link_Record::KIND_OFFER ], $ids )
		);

		return $this->affected( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Lists the offer rows of products: a simple product's own row, a variable product's variation rows.
	 *
	 * @param int   $account_id  Account id.
	 * @param int[] $product_ids Product or variation ids.
	 * @return Link_Record[]
	 */
	public function offers_of( int $account_id, array $product_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$in   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM %i WHERE account_id = %d AND kind = %s AND (wc_product_id IN (' . $in . ') OR wc_parent_id IN (' . $in . ')) ORDER BY link_id ASC', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->table, $account_id, Link_Record::KIND_OFFER ], $ids, $ids )
			),
			'ARRAY_A'
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		return $this->with_ids( is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Switches the stock or the price synchronisation of offer rows.
	 *
	 * @param int    $account_id Account id.
	 * @param int[]  $link_ids   Link ids.
	 * @param string $column     `sync_stock` or `sync_price`.
	 * @param bool   $enabled    New value.
	 * @return int Rows changed.
	 */
	public function set_sync_flag( int $account_id, array $link_ids, string $column, bool $enabled ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $link_ids ) ) ) );

		if ( [] === $ids || ! in_array( $column, [ 'sync_stock', 'sync_price' ], true ) ) {
			return 0;
		}

		$sql = $this->db->prepare(
			'UPDATE %i SET %i = %d, updated_at = %s WHERE account_id = %d AND kind = %s AND %i <> %d AND link_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
			array_merge( [ $this->table, $column, $enabled ? 1 : 0, $this->format_time( $this->now() ), $account_id, Link_Record::KIND_OFFER, $column, $enabled ? 1 : 0 ], $ids )
		);

		return $this->affected( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Sets the warehouse override of offer rows.
	 *
	 * @param int      $account_id   Account id.
	 * @param int[]    $link_ids     Link ids.
	 * @param int|null $warehouse_id Warehouse, or null for the account default.
	 * @return int Rows changed.
	 */
	public function set_warehouse( int $account_id, array $link_ids, ?int $warehouse_id ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $link_ids ) ) ) );

		if ( [] === $ids ) {
			return 0;
		}

		$value = null === $warehouse_id ? 'NULL' : '%d';
		$args  = [ $this->table ];

		if ( null !== $warehouse_id ) {
			$args[] = $warehouse_id;
		}

		$sql = $this->db->prepare(
			'UPDATE %i SET warehouse_id = ' . $value . ', updated_at = %s WHERE account_id = %d AND kind = %s AND link_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders and NULL are concatenated.
			array_merge( $args, [ $this->format_time( $this->now() ), $account_id, Link_Record::KIND_OFFER ], $ids )
		);

		return $this->affected( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Counts offer rows by card state: the progress line of the products screen.
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Link ids.
	 * @return array<string,int> State => rows.
	 */
	public function state_counts( int $account_id, array $link_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $link_ids ) ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$rows   = $this->db->get_results(
			$this->db->prepare(
				'SELECT card_state, COUNT(*) AS total FROM %i WHERE account_id = %d AND kind = %s AND link_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY card_state', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->table, $account_id, Link_Record::KIND_OFFER ], $ids )
			),
			'ARRAY_A'
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$counts = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$counts[ (string) $row['card_state'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * «Снять связь» on the product tab: forgets the marketplace card of one offer row, locally. The row goes back to `unlinked` with no
	 * remote identifiers (their rows are deleted) and no article, so the product can be exported afresh or linked to another card; its switches, warehouse,
	 * price override and mapping stay. Nothing is sent to the marketplace and the card there is untouched.
	 *
	 * A row an export is submitting right now is left alone (the request carries its article).
	 *
	 * @param int $account_id Account id.
	 * @param int $link_id    Link id.
	 * @return bool True when the row was detached.
	 */
	public function detach( int $account_id, int $link_id ): bool {
		$sql = $this->db->prepare(
			'UPDATE %i SET card_state = %s, offer_id = NULL, is_created = 0, last_task_id = NULL, last_error_code = NULL, last_error_text = NULL, updated_at = %s WHERE link_id = %d AND account_id = %d AND kind = %s AND card_state <> %s',
			$this->table,
			Link_Record::STATE_UNLINKED,
			$this->format_time( $this->now() ),
			$link_id,
			$account_id,
			Link_Record::KIND_OFFER,
			Link_Record::STATE_SUBMITTED
		);

		return $this->atomically(
			function () use ( $sql, $link_id ): bool {
				if ( 1 !== $this->affected( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
					return false;
				}

				$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE link_id = %d', $this->ids_table, $link_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.

				return true;
			}
		);
	}

	/**
	 * Stores the per-product settings of the product tab. Only these columns can be written here: the two switches, the oversize
	 * flag, the warehouse override and the explicit mapping. The price override has its own writer ({@see self::set_price_override()}).
	 *
	 * @param int                 $link_id Link id.
	 * @param array<string,mixed> $values  `sync_stock`, `sync_price`, `is_oversize` (bool), `warehouse_id`, `mapping_id` (int or null); absent keys stay.
	 * @return void
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function update_settings( int $link_id, array $values ): void {
		$columns = [];

		foreach ( [ 'sync_stock', 'sync_price', 'is_oversize' ] as $flag ) {
			if ( array_key_exists( $flag, $values ) ) {
				$columns[ $flag ] = $values[ $flag ] ? 1 : 0;
			}
		}

		foreach ( [ 'warehouse_id', 'mapping_id' ] as $nullable ) {
			if ( array_key_exists( $nullable, $values ) ) {
				$columns[ $nullable ] = null === $values[ $nullable ] || 0 === (int) $values[ $nullable ] ? null : (int) $values[ $nullable ];
			}
		}

		if ( [] === $columns ) {
			return;
		}

		$columns['updated_at'] = $this->format_time( $this->now() );

		if ( false === $this->db->update( $this->table, $columns, [ 'link_id' => $link_id ] ) ) {
			throw new \RuntimeException( 'The product settings could not be saved.' );
		}
	}

	/**
	 * Reads one row by link id.
	 *
	 * @param int $link_id Link id.
	 * @return Link_Record|null
	 */
	public function get( int $link_id ): ?Link_Record {
		return $this->one( $this->db->prepare( 'SELECT * FROM %i WHERE link_id = %d', $this->table, $link_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
	}

	/**
	 * Stores the link's price rule override, in its canonical form. Nothing else of the row changes.
	 *
	 * @param int                      $link_id  Link id.
	 * @param array<string,mixed>|null $override `{ price: {kind, value}, old_price: {...}, min_price: {...} }`; null or empty clears it.
	 * @return void
	 * @throws \RuntimeException When the database refuses the write. A malformed override throws `InvalidArgumentException` first.
	 */
	public function set_price_override( int $link_id, ?array $override ): void {
		$canonical = Price_Override::from_array( $override )->to_array();
		$updated   = $this->db->update(
			$this->table,
			[
				'price_rule_override' => null === $canonical ? null : (string) wp_json_encode( $canonical ),
				'updated_at'          => $this->format_time( $this->now() ),
			],
			[ 'link_id' => $link_id ]
		);

		if ( false === $updated ) {
			throw new \RuntimeException( 'The price override could not be saved.' );
		}
	}

	/**
	 * Reads the stored error of a row.
	 *
	 * @param int $link_id Link id.
	 * @return array{code:string,text:string}
	 */
	public function last_error( int $link_id ): array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT last_error_code, last_error_text FROM %i WHERE link_id = %d', $this->table, $link_id ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.

		return [
			'code' => (string) ( is_array( $row ) ? $row['last_error_code'] : '' ),
			'text' => (string) ( is_array( $row ) ? $row['last_error_text'] : '' ),
		];
	}

	/**
	 * Columns {@see self::save()} writes for a shared link value.
	 *
	 * @param Product_Link $link Link.
	 * @return array<string,mixed>
	 */
	private function shared_columns( Product_Link $link ): array {
		$columns = [
			'offer_id'        => $link->seller_sku(),
			'wc_parent_id'    => $link->wc_parent_id(),
			'card_state'      => $link->state(),
			'is_created'      => $link->is_created() ? 1 : 0,
			'warehouse_id'    => $link->warehouse_id(),
		];

		foreach ( self::FLAG_COLUMNS as $flag ) {
			$columns[ $flag ] = $link->flag( $flag ) ? 1 : 0;
		}

		return $columns;
	}

	/**
	 * Projects a record onto the shared link value.
	 *
	 * @param Link_Record $record Row.
	 * @return Product_Link
	 */
	public function to_product_link( Link_Record $record ): Product_Link {
		return new Product_Link(
			$record->account_id(),
			$record->wc_product_id(),
			$record->wc_parent_id(),
			(string) $record->offer_id(),
			$record->remote_ids(),
			$record->card_state(),
			[
				'sync_stock'  => $record->sync_stock(),
				'sync_price'  => $record->sync_price(),
				'is_oversize' => $record->is_oversize(),
			],
			$record->warehouse_id(),
			$record->link_id()
		);
	}

	/**
	 * Longest identifier name and value the `link_ids` columns hold.
	 *
	 * @var int
	 */
	private const ID_NAME_LENGTH  = 32;
	private const ID_VALUE_LENGTH = 191;

	/**
	 * Runs a write that touches the link and its identifiers as one unit: both land or neither does.
	 *
	 * @param callable $work The writes; whatever it returns is returned. An exception rolls back and goes up.
	 * @return mixed
	 * @throws Persistence_Exception When the commit fails.
	 */
	private function atomically( callable $work ) {
		$this->db->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables.

		try {
			$result = $work();
		} catch ( \Throwable $exception ) {
			$this->db->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables.

			throw $exception;
		}

		if ( false === $this->db->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables.
			throw Persistence_Exception::database( 'the identifiers of a link', (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $result;
	}

	/**
	 * Writes the identifiers of a link.
	 *
	 * @param int                      $link_id    Link id.
	 * @param int                      $account_id Account id of the link.
	 * @param array<string,int|string> $ids        Name => identifier; an empty one is «not known yet» and is skipped.
	 * @param bool                     $keep_known True keeps an identifier the link already has; false sets it.
	 * @return void
	 * @throws \InvalidArgumentException When a name or value does not fit its column.
	 * @throws Persistence_Exception     When the database refuses a write.
	 */
	private function write_ids( int $link_id, int $account_id, array $ids, bool $keep_known ): void {
		foreach ( Link_Record::clean_ids( $ids ) as $name => $value ) {
			if ( strlen( (string) $name ) > self::ID_NAME_LENGTH || strlen( (string) $value ) > self::ID_VALUE_LENGTH ) {
				throw new \InvalidArgumentException( 'A remote identifier does not fit its column.' );
			}

			$type = is_int( $value ) ? 'i' : 's';

			if ( $keep_known ) {
				$done = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
					$this->db->prepare( 'INSERT IGNORE INTO %i (link_id, account_id, id_name, id_value, id_type) VALUES (%d, %d, %s, %s, %s)', $this->ids_table, $link_id, $account_id, (string) $name, (string) $value, $type )
				);
			} else {
				$done = $this->db->replace(
					$this->ids_table,
					[
						'link_id'    => $link_id,
						'account_id' => $account_id,
						'id_name'    => (string) $name,
						'id_value'   => (string) $value,
						'id_type'    => $type,
					]
				);
			}

			if ( false === $done ) {
				throw Persistence_Exception::database( 'the identifier ' . $name . ' of link ' . $link_id, (string) $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}
		}
	}

	/**
	 * Reads the account of a link.
	 *
	 * @param int $link_id Link id.
	 * @return int 0 when the link does not exist.
	 */
	private function account_of( int $link_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT account_id FROM %i WHERE link_id = %d', $this->table, $link_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared here.
	}

	/**
	 * Builds the records of rows of the links table, with their identifiers read in one query.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows of the links table (with `link_id`).
	 * @return Link_Record[] The identifiers of a record are in the order of their names.
	 */
	private function with_ids( array $rows ): array {
		if ( [] === $rows ) {
			return [];
		}

		$link_ids = array_map( 'intval', array_column( $rows, 'link_id' ) );
		$found    = $this->db->get_results(
			$this->db->prepare(
				'SELECT link_id, id_name, id_value, id_type FROM %i WHERE link_id IN (' . implode( ', ', array_fill( 0, count( $link_ids ), '%d' ) ) . ') ORDER BY link_id ASC, id_name ASC', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->ids_table ], $link_ids )
			),
			'ARRAY_A'
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$by_link  = [];

		foreach ( is_array( $found ) ? $found : [] as $id ) {
			$by_link[ (int) $id['link_id'] ][ (string) $id['id_name'] ] = 'i' === $id['id_type'] ? (int) $id['id_value'] : (string) $id['id_value'];
		}

		return array_map(
			static function ( array $row ) use ( $by_link ): Link_Record {
				$row['remote_ids'] = $by_link[ (int) $row['link_id'] ] ?? [];

				return Link_Record::from_row( $row );
			},
			$rows
		);
	}

	/**
	 * Reads one row.
	 *
	 * @param string $sql Prepared statement.
	 * @return Link_Record|null
	 */
	private function one( string $sql ): ?Link_Record {
		$row = $this->db->get_row( $sql, 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared by the caller.

		return is_array( $row ) ? $this->with_ids( [ $row ] )[0] : null;
	}

	/**
	 * Runs a write and returns the number of rows it changed.
	 *
	 * @param string $sql Prepared statement.
	 * @return int
	 */
	private function affected( string $sql ): int {
		$changed = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared by the caller.

		return is_int( $changed ) ? $changed : 0;
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
}
