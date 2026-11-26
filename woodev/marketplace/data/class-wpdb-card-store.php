<?php
/**
 * The stored list of an account's marketplace cards.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Ledger\Account_Rows;
use Woodev\Framework\Marketplace\Links\Unit_Key;

defined( 'ABSPATH' ) || exit;

/**
 * The «Карточки» screen lists the cabinet's cards from the `cards` table ({@see Schema}), never from the marketplace: the list job, a
 * daily run and the card notifications fill it, and it is kept for good (#1863). A cabinet can hold tens of thousands of cards, so
 * every read is one SQL page (filter, search, count) and every write is a batch of {@see self::BATCH} rows — the size measured on
 * 10 000 and 50 000 cards (#1863: one batch costs ~4 MB of PHP memory, a full 50 000-card write ~2 s of database time).
 *
 * **A full refresh never empties the screen.** It takes a generation ({@see self::begin_refresh()}), stamps every row it writes with
 * it, and only after its last page sweeps the rows it did not see ({@see self::finish_refresh()}): a card deleted on the marketplace
 * goes, the others stay visible the whole time. A card notification writes its row with the generation in progress (or the last
 * finished one), so a refresh that runs meanwhile does not sweep it.
 *
 * A card is *on the site* when a row of the link table carries its article; every read joins that table instead of guessing.
 *
 * A marketplace that sells units lists one row per unit; the units that name one card (`unit.card`) are read together under it
 * ({@see self::card_page()}). A row whose article is longer than the link table holds is not stored: it could never be linked.
 *
 * Per account, one option (no autoload) under the plugin's option family keeps the generations, the time of the last finished
 * refresh, the «refreshing» mark and the text of the last failure; the uninstaller removes it with the family, the account
 * deletion with {@see self::forget()}.
 *
 * @since 2.1.13
 */
final class Wpdb_Card_Store {
	/**
	 * Rows per `INSERT`: one marketplace page (Ozon pages by 1000). Measured in #1863 against 200: the same memory class (~4 MB
	 * against ~1 MB), a third less database time on 50 000 cards.
	 */
	public const BATCH = 1000;

	/**
	 * Seconds the «refreshing» mark holds without a sign of life; every page of a refresh renews it.
	 */
	public const REFRESHING_TTL = 900;

	/**
	 * Rows one statement of the sweep deletes, so a cabinet that lost thousands of cards never holds one long lock.
	 */
	private const SWEEP = 5000;

	private const META          = 'cards_list_';
	private const NAME_LENGTH   = 500;
	private const IMAGE_LENGTH  = 500;
	private const STATUS_LENGTH = 2000;
	private const IDS_LENGTH    = 255;
	private const GROUP_LENGTH  = 64;

	private const ORDER = ' ORDER BY c.position ASC, c.card_key ASC';

	/**
	 * The card a row belongs to: its units share the hash of the card's name, a row that stands alone is a card of its own.
	 */
	private const CARD_OF = "CASE WHEN c.unit_card = '' THEN c.card_key ELSE c.unit_card END";

	private const COLUMNS = [ 'account_id', 'card_key', 'offer_id', 'name', 'image', 'state', 'is_created', 'failed', 'archived', 'details_ok', 'status_text', 'price', 'stock', 'remote_ids', 'group_key', 'group_size', 'unit_card', 'unit_article', 'unit_data', 'position', 'seen_run', 'updated_at' ];

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full name of the `cards` table.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Full name of the `links` table, joined for «on the site».
	 *
	 * @var string
	 */
	private $links;

	/**
	 * Option family of the plugin.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Unix time: `function (): int`; the «refreshing» mark is set and read by it.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the store.
	 *
	 * @since 2.1.13
	 *
	 * @param \wpdb         $db            Database.
	 * @param string        $table         Full name of the `cards` table.
	 * @param string        $links_table   Full name of the `links` table.
	 * @param string        $option_prefix The plugin's option family, from {@see Uninstaller::option_prefix()}.
	 * @param callable|null $clock         `function (): int`; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $table, string $links_table, string $option_prefix, ?callable $clock = null ) {
		$this->db     = $db;
		$this->table  = $table;
		$this->links  = $links_table;
		$this->prefix = $option_prefix;
		$this->clock  = $clock ?? 'time';
	}

	/**
	 * Builds the key of a card: the hash of its article that the screen's row ids carry.
	 *
	 * @since 2.1.13
	 *
	 * @param string $offer_id Article.
	 * @return string 32 hexadecimal characters.
	 */
	public static function key( string $offer_id ): string {
		return substr( hash( 'sha256', $offer_id ), 0, 32 );
	}

	/**
	 * Whether the account has a list at all: a refresh finished once, or a row is there.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return bool
	 */
	public function has_list( int $account_id ): bool {
		if ( $this->refreshed_at( $account_id ) > 0 ) {
			return true;
		}

		return null !== $this->db->get_var( $this->db->prepare( 'SELECT 1 FROM %i WHERE account_id = %d LIMIT 1', $this->table, $account_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * When the last full refresh finished.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return int Unix time; 0 when none ever finished.
	 */
	public function refreshed_at( int $account_id ): int {
		return $this->meta( $account_id )['refreshed_at'];
	}

	/**
	 * How many cards the account's list holds.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return int
	 */
	public function count( int $account_id ): int {
		return Account_Rows::count( $this->db, $this->table, $account_id );
	}

	/**
	 * How many of the account's cards the marketplace holds in its archive.
	 *
	 * @since 2.1.16
	 *
	 * @param int $account_id Account id.
	 * @return int
	 */
	public function archived_count( int $account_id ): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE account_id = %d AND archived = 1', $this->table, $account_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A count of our own table, prepared.
	}

	/**
	 * Marks a refresh as queued, and forgets the last failure: the button that queues one calls it first, so two clicks queue one read.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function mark_refreshing( int $account_id ): void {
		$meta                  = $this->meta( $account_id );
		$meta['refreshing_at'] = $this->now();
		$meta['error']         = '';

		$this->save_meta( $account_id, $meta );
	}

	/**
	 * Whether a refresh is queued or running, and gave a sign of life within {@see self::REFRESHING_TTL}.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return bool
	 */
	public function is_refreshing( int $account_id ): bool {
		$at = $this->meta( $account_id )['refreshing_at'];

		return $at > 0 && $this->now() - $at < self::REFRESHING_TTL;
	}

	/**
	 * Records why a refresh failed and ends the «refreshing» mark. The rows stay: the screen keeps showing the last list.
	 *
	 * @since 2.1.13
	 *
	 * @param int    $account_id Account id.
	 * @param string $text       Text for the merchant.
	 * @return void
	 */
	public function fail( int $account_id, string $text ): void {
		$meta                  = $this->meta( $account_id );
		$meta['refreshing_at'] = 0;
		$meta['error']         = $text;

		$this->save_meta( $account_id, $meta );
	}

	/**
	 * The text of the last failed refresh.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return string '' when the last refresh did not fail.
	 */
	public function last_error( int $account_id ): string {
		return $this->meta( $account_id )['error'];
	}

	/**
	 * Starts a full refresh, or goes on with the one an interruption left: the generation its rows are stamped with.
	 *
	 * A generation belongs to one traversal of the cabinet. It is reused only when the caller resumes that traversal from its saved
	 * cursor (`$resume` is the generation the cursor was saved with, and it is still the one in progress); a traversal that starts
	 * over — after a give-up, an expired cursor, or a newer refresh that finished meanwhile — gets a new one above every earlier
	 * one. Reusing an abandoned generation would let the rows of the abandoned pages survive the new traversal's sweep (#1868).
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @param int $now        Unix time.
	 * @param int $resume     The generation of the saved cursor the caller goes on from; 0 for a traversal from the first page.
	 * @return int The generation: `$resume` when it may go on, else a new one above every earlier one.
	 */
	public function begin_refresh( int $account_id, int $now, int $resume = 0 ): int {
		$meta = $this->meta( $account_id );

		if ( $resume <= 0 || $resume !== $meta['running'] || $meta['running'] <= $meta['generation'] ) {
			$meta['running'] = max( $meta['generation'], $meta['running'] ) + 1;
		}

		$meta['refreshing_at'] = $now;

		$this->save_meta( $account_id, $meta );

		return $meta['running'];
	}

	/**
	 * Renews the «refreshing» mark: a long refresh gives a sign of life with every page.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @param int $now        Unix time.
	 * @return void
	 */
	public function touch( int $account_id, int $now ): void {
		$meta                  = $this->meta( $account_id );
		$meta['refreshing_at'] = $now;

		$this->save_meta( $account_id, $meta );
	}

	/**
	 * The generation a row written outside a full refresh gets: the refresh in progress, so it does not sweep the row, or else the
	 * last finished one.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return int
	 */
	public function current_run( int $account_id ): int {
		$meta = $this->meta( $account_id );

		return $meta['running'] > $meta['generation'] ? $meta['running'] : $meta['generation'];
	}

	/**
	 * Writes list rows: new cards are added, known ones replaced. Batches of {@see self::BATCH}.
	 *
	 * @since 2.1.13
	 *
	 * @param int                            $account_id Account id.
	 * @param array<int,array<string,mixed>> $cards      List rows ({@see \Woodev\Framework\Marketplace\Flows\Card_List_Source}).
	 * @param int                            $run        Generation the rows are stamped with.
	 * @param int                            $position   Place of the first card in the marketplace's order, from 1; 0 keeps the place a
	 *                                                   known card had (a notification), and a new one goes first.
	 * @param int                            $now        Unix time.
	 * @return int Rows written.
	 * @throws Persistence_Exception When the database refuses a batch.
	 */
	public function upsert( int $account_id, array $cards, int $run, int $position, int $now ): int {
		$written = 0;
		$updated = gmdate( 'Y-m-d H:i:s', $now );

		foreach ( array_chunk( array_values( $cards ), self::BATCH ) as $chunk ) {
			$values = [];

			foreach ( $chunk as $index => $card ) {
				$row = $this->row( $account_id, (array) $card, $run, $position > 0 ? $position + $written + $index : 0, $updated );

				if ( null !== $row ) {
					$values[] = $row;
				}
			}

			if ( [] === $values ) {
				$written += count( $chunk );
				continue;
			}

			if ( false === $this->db->query( $this->upsert_sql( $values ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Every value is prepared in self::row().
				throw Persistence_Exception::database( 'the cards list', (string) $this->db->last_error );
			}

			$written += count( $chunk );
		}

		return $written;
	}

	/**
	 * Ends a full refresh: sweeps the rows it did not see (the cards deleted on the marketplace), then records it as the last one.
	 *
	 * Nothing is swept or recorded for a generation that is not above the last finished one: a run that lost its account claim and
	 * came back after a newer refresh finished must not delete that refresh's rows or move the generation back. With a fence the
	 * sweep itself is conditional on the account claim, as the fenced writes of {@see Wpdb_Link_Store} are, and a run whose claim
	 * was taken over records nothing.
	 *
	 * @since 2.1.13
	 *
	 * @param int              $account_id Account id.
	 * @param int              $run        The refresh's generation.
	 * @param int              $now        Unix time.
	 * @param Claim_Fence|null $fence      The account claim the run holds, or null.
	 * @return int|null Rows swept; null when the run is obsolete or its claim was taken over, and nothing was recorded.
	 * @throws \RuntimeException When the database refuses the sweep; the refresh then stays unfinished and its next run sweeps.
	 */
	public function finish_refresh( int $account_id, int $run, int $now, ?Claim_Fence $fence = null ): ?int {
		if ( $run <= $this->meta( $account_id )['generation'] ) {
			return null;
		}

		$condition = 'account_id = %d AND seen_run <> %d';
		$values    = [ $account_id, $run ];

		if ( null !== $fence ) {
			$condition .= ' AND EXISTS (SELECT 1 FROM %i AS claim_owner WHERE claim_owner.account_id = %d AND claim_owner.claim_token = %s AND claim_owner.claim_until > %s)';
			$values     = array_merge( $values, [ $fence->table(), $account_id, $fence->token(), gmdate( 'Y-m-d H:i:s', $this->now() ) ] );
		}

		$swept = 0;

		do {
			$deleted = Account_Rows::delete_where( $this->db, $this->table, $condition, $values, self::SWEEP );
			$swept  += $deleted;
		} while ( $deleted >= self::SWEEP );

		if ( null !== $fence && ! $this->claim_held( $account_id, $fence ) ) {
			return null;
		}

		$meta                  = $this->meta( $account_id );
		$meta['generation']    = $run;
		$meta['running']       = 0;
		$meta['refreshed_at']  = $now;
		$meta['refreshing_at'] = 0;
		$meta['error']         = '';

		$this->save_meta( $account_id, $meta );

		return $swept;
	}

	/**
	 * Whether the run still holds the account claim.
	 *
	 * @param int         $account_id Account id.
	 * @param Claim_Fence $fence      The run's claim.
	 * @return bool
	 */
	private function claim_held( int $account_id, Claim_Fence $fence ): bool {
		$sql = $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE account_id = %d AND claim_token = %s AND claim_until > %s', $fence->table(), $account_id, $fence->token(), gmdate( 'Y-m-d H:i:s', $this->now() ) );

		return 1 === (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
	}

	/**
	 * Deletes up to `$limit` rows of an account (account deletion).
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows to delete.
	 * @return int Rows deleted; 0 when none are left.
	 */
	public function delete_account( int $account_id, int $limit ): int {
		return Account_Rows::delete( $this->db, $this->table, $account_id, $limit );
	}

	/**
	 * Forgets the account's generations, refresh time, mark and failure (account deletion; the rows go by {@see self::delete_account()}).
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function forget( int $account_id ): void {
		delete_option( $this->meta_name( $account_id ) );
	}

	/**
	 * One page of the list, in the marketplace's order.
	 *
	 * @since 2.1.13
	 *
	 * @param int       $account_id Account id.
	 * @param bool|null $on_site    True: only the cards a product carries; false: only the others; null: both.
	 * @param string    $search     Text looked for in the article and the name; '' for none.
	 * @param int       $page       Page, from 1.
	 * @param int       $per_page   Cards per page.
	 * @return array{cards:array<int,array<string,mixed>>,total:int} Cards with `wc_product_id` and `link_id` (both 0 when not on the site).
	 */
	public function page( int $account_id, ?bool $on_site, string $search, int $page, int $per_page ): array {
		$per_page = max( 1, $per_page );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$where    = $this->where( $account_id, $on_site, $search );
		$total    = (int) $this->db->get_var( 'SELECT COUNT(*) ' . $this->from() . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Built of prepared parts.
		$rows     = $this->select( 'c.*, l.wc_product_id, l.link_id', $where . $this->db->prepare( self::ORDER . ' LIMIT %d OFFSET %d', $per_page, $offset ) );

		return [
			'cards' => array_map( [ $this, 'card' ], $rows ),
			'total' => $total,
		];
	}

	/**
	 * One page of the list by card, for a marketplace that sells units: each card with **all** its units, in the marketplace's
	 * order. The filter and the search choose the cards (a card is listed when any of its units passes), never the units inside.
	 *
	 * @since 2.1.23
	 *
	 * @param int       $account_id Account id.
	 * @param bool|null $on_site    True: cards with a unit a product carries; false: cards with a unit no product carries; null: all.
	 * @param string    $search     Text looked for in the article and the name; '' for none.
	 * @param int       $page       Page, from 1.
	 * @param int       $per_page   Cards per page.
	 * @return array{cards:array<string,array<int,array<string,mixed>>>,total:int} Units with `wc_product_id`, by card reference.
	 */
	public function card_page( int $account_id, ?bool $on_site, string $search, int $page, int $per_page ): array {
		$per_page = max( 1, $per_page );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$where    = $this->where( $account_id, $on_site, $search );
		$total    = $this->card_count( $account_id, $on_site, $search );
		$rows     = $this->select( self::CARD_OF . ' AS card_ref, MIN(c.position) AS first_position', $where . ' GROUP BY card_ref' . $this->db->prepare( ' ORDER BY first_position ASC, card_ref ASC LIMIT %d OFFSET %d', $per_page, $offset ) );
		$refs     = array_map( 'strval', array_column( $rows, 'card_ref' ) );
		$units    = $this->units_of( $account_id, $refs );
		$cards    = [];

		foreach ( $refs as $ref ) {
			$cards[ $ref ] = $units[ $ref ] ?? [];
		}

		return [
			'cards' => $cards,
			'total' => $total,
		];
	}

	/**
	 * Reads every unit of some cards.
	 *
	 * @since 2.1.23
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $refs       Card references: the keys of {@see self::card_page()}.
	 * @return array<string,array<int,array<string,mixed>>> Units with `wc_product_id`, by card reference; a card the list does not hold is absent.
	 */
	public function units_of( int $account_id, array $refs ): array {
		$refs = array_values( array_unique( array_filter( array_map( 'strval', $refs ), [ self::class, 'is_key' ] ) ) );

		if ( [] === $refs ) {
			return [];
		}

		$in    = implode( ',', array_fill( 0, count( $refs ), '%s' ) );
		$where = $this->db->prepare( " WHERE c.account_id = %d AND (c.unit_card IN ({$in}) OR (c.unit_card = '' AND c.card_key IN ({$in})))", array_merge( [ $account_id ], $refs, $refs ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder per reference.
		$cards = [];

		foreach ( $this->select( 'c.*, l.wc_product_id, l.link_id', $where . self::ORDER ) as $row ) {
			$ref             = '' === (string) $row['unit_card'] ? (string) $row['card_key'] : (string) $row['unit_card'];
			$cards[ $ref ][] = $this->card( $row );
		}

		return $cards;
	}

	/**
	 * How many cards under the search text have a unit on the site and how many have a unit that is not.
	 *
	 * @since 2.1.23
	 *
	 * @param int    $account_id Account id.
	 * @param string $search     Search text; '' for none.
	 * @return array{on_site:int,not_on_site:int}
	 */
	public function card_on_site_counts( int $account_id, string $search ): array {
		$rows = $this->select(
			'COUNT(DISTINCT CASE WHEN l.link_id IS NOT NULL THEN ' . self::CARD_OF . ' END) AS on_site, COUNT(DISTINCT CASE WHEN l.link_id IS NULL THEN ' . self::CARD_OF . ' END) AS not_on_site',
			$this->where( $account_id, null, $search )
		);

		return [
			'on_site'     => (int) ( $rows[0]['on_site'] ?? 0 ),
			'not_on_site' => (int) ( $rows[0]['not_on_site'] ?? 0 ),
		];
	}

	/**
	 * How many cards the account's list holds, counting the units of one card once, and how many of them are in the archive.
	 *
	 * @since 2.1.23
	 *
	 * @param int $account_id Account id.
	 * @return array{cards:int,archived:int}
	 */
	public function card_totals( int $account_id ): array {
		$rows = $this->select(
			'COUNT(DISTINCT ' . self::CARD_OF . ') AS cards, COUNT(DISTINCT CASE WHEN c.archived = 1 THEN ' . self::CARD_OF . ' END) AS archived',
			$this->where( $account_id, null, '' )
		);

		return [
			'cards'    => (int) ( $rows[0]['cards'] ?? 0 ),
			'archived' => (int) ( $rows[0]['archived'] ?? 0 ),
		];
	}

	/**
	 * How many cards under the search text are on the site and how many are not.
	 *
	 * @since 2.1.13
	 *
	 * @param int    $account_id Account id.
	 * @param string $search     Search text; '' for none.
	 * @return array{on_site:int,not_on_site:int}
	 */
	public function on_site_counts( int $account_id, string $search ): array {
		$rows = $this->select( 'COUNT(l.link_id) AS on_site, COUNT(*) - COUNT(l.link_id) AS not_on_site', $this->where( $account_id, null, $search ) );

		return [
			'on_site'     => (int) ( $rows[0]['on_site'] ?? 0 ),
			'not_on_site' => (int) ( $rows[0]['not_on_site'] ?? 0 ),
		];
	}

	/**
	 * How many cards the filter reaches, the units of one card counted once.
	 *
	 * @since 2.1.23
	 *
	 * @param int       $account_id Account id.
	 * @param bool|null $on_site    The «on the site» filter, as in {@see self::card_page()}.
	 * @param string    $search     Search text; '' for none.
	 * @return int
	 */
	public function card_count( int $account_id, ?bool $on_site, string $search ): int {
		return (int) $this->db->get_var( 'SELECT COUNT(DISTINCT ' . self::CARD_OF . ') ' . $this->from() . $this->where( $account_id, $on_site, $search ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Built of prepared parts.
	}

	/**
	 * Every card the filter reaches, as the bulk import needs it: the article and group key of each card no product carries, and
	 * the articles of the ones a product carries. Two short columns per card, in the marketplace's order.
	 *
	 * For a marketplace that sells units the cards to import are still listed unit by unit, each naming its card as a third
	 * element, while `on_site` holds one entry — the card's current article — for each **card** none of whose units is left to
	 * import, so that what is counted is cards.
	 *
	 * @since 2.1.13
	 *
	 * @param int       $account_id Account id.
	 * @param bool|null $on_site    The «on the site» filter, as in {@see self::page()}.
	 * @param string    $search     Search text; '' for none.
	 * @return array{cards:array<int,array<int,string>>,on_site:string[]}
	 */
	public function entries( int $account_id, ?bool $on_site, string $search ): array {
		$rows  = $this->select( 'c.offer_id, c.group_key, c.unit_card, c.unit_article, l.link_id', $this->where( $account_id, $on_site, $search ) . self::ORDER );
		$found = [
			'cards'   => [],
			'on_site' => [],
		];

		$importable = []; // Cards with a unit to import, by card reference.
		$linked     = []; // Cards with a unit on the site, by card reference.

		foreach ( $rows as $row ) {
			$offer_id = (string) $row['offer_id'];
			$ref      = (string) $row['unit_card'];

			if ( '' === $offer_id ) {
				continue;
			}

			if ( null === $row['link_id'] ) {
				$entry              = [ $offer_id, trim( (string) $row['group_key'] ) ];
				$found['cards'][]   = '' === $ref ? $entry : array_merge( $entry, [ $ref ] );
				$importable[ $ref ] = true;
			} elseif ( '' === $ref ) {
				$found['on_site'][] = $offer_id;
			} else {
				$linked[ $ref ] = '' === (string) $row['unit_article'] ? $offer_id : (string) $row['unit_article'];
			}
		}

		$found['on_site'] = array_merge( $found['on_site'], array_values( array_diff_key( $linked, $importable ) ) );

		return $found;
	}

	/**
	 * Finds cards by the keys of their articles.
	 *
	 * @since 2.1.13
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $keys       Keys ({@see self::key()}).
	 * @return array<string,array<string,mixed>> Cards with `wc_product_id`, by key; a key the list does not hold is absent.
	 */
	public function find_many( int $account_id, array $keys ): array {
		$keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ), [ self::class, 'is_key' ] ) ) );

		if ( [] === $keys ) {
			return [];
		}

		$in    = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$where = $this->db->prepare( " WHERE c.account_id = %d AND c.card_key IN ({$in})", array_merge( [ $account_id ], $keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder per key.
		$cards = [];

		foreach ( $this->select( 'c.*, l.wc_product_id, l.link_id', $where ) as $row ) {
			$cards[ (string) $row['card_key'] ] = $this->card( $row );
		}

		return $cards;
	}

	/**
	 * Finds a card by the key of its article.
	 *
	 * @since 2.1.13
	 *
	 * @param int    $account_id Account id.
	 * @param string $key        Key ({@see self::key()}).
	 * @return array<string,mixed>|null Null when the list does not hold it.
	 */
	public function find( int $account_id, string $key ): ?array {
		return $this->find_many( $account_id, [ $key ] )[ $key ] ?? null;
	}

	/**
	 * Whether a string is a well-formed key.
	 *
	 * @param string $key Candidate.
	 * @return bool
	 */
	private static function is_key( string $key ): bool {
		return 1 === preg_match( '/^[0-9a-f]{32}$/D', $key );
	}

	/**
	 * Runs a read of the list joined with the links.
	 *
	 * @param string $columns Selected columns; ours, never request input.
	 * @param string $tail    `WHERE`, `ORDER BY` and `LIMIT`, prepared.
	 * @return array<int,array<string,mixed>> Rows; none when the read failed.
	 */
	private function select( string $columns, string $tail ): array {
		$rows = $this->db->get_results( 'SELECT ' . $columns . ' ' . $this->from() . $tail, 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Built of prepared parts.

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * `FROM` with the join that says whether a product carries the card.
	 *
	 * @return string
	 */
	private function from(): string {
		return $this->db->prepare( 'FROM %i AS c LEFT JOIN %i AS l ON l.account_id = c.account_id AND l.offer_id = c.offer_id AND l.kind = %s', $this->table, $this->links, Link_Record::KIND_OFFER );
	}

	/**
	 * `WHERE` of a filter.
	 *
	 * @param int       $account_id Account id.
	 * @param bool|null $on_site    The «on the site» filter.
	 * @param string    $search     Search text.
	 * @return string
	 */
	private function where( int $account_id, ?bool $on_site, string $search ): string {
		$where = $this->db->prepare( ' WHERE c.account_id = %d', $account_id );

		if ( null !== $on_site ) {
			$where .= $on_site ? ' AND l.link_id IS NOT NULL' : ' AND l.link_id IS NULL';
		}

		$needle = trim( $search );

		if ( '' !== $needle ) {
			$like   = '%' . $this->db->esc_like( $needle ) . '%';
			$where .= $this->db->prepare( ' AND (c.offer_id LIKE %s OR c.name LIKE %s OR c.unit_article LIKE %s)', $like, $like, $like );
		}

		return $where;
	}

	/**
	 * The `VALUES` tuple of one list row.
	 *
	 * @param int                 $account_id Account id.
	 * @param array<string,mixed> $card       List row.
	 * @param int                 $run        Generation.
	 * @param int                 $position   Place in the marketplace's order; 0 keeps the stored one.
	 * @param string              $updated    UTC date-time.
	 * @return string|null Null for a row with no article.
	 */
	private function row( int $account_id, array $card, int $run, int $position, string $updated ): ?string {
		$offer_id = trim( (string) ( $card['offer_id'] ?? '' ) );

		if ( '' === $offer_id || ! Unit_Key::fits( $offer_id ) ) {
			return null; // An article the link table cannot hold can never be linked, and one such row would refuse the whole batch.
		}

		$unit  = (array) ( $card['unit'] ?? [] );
		$ref   = (string) ( $unit['card'] ?? '' );
		$data  = $this->unit_data( $card );
		$group = (array) ( $card['group'] ?? [] );
		$ids   = wp_json_encode( array_filter( (array) ( $card['remote_ids'] ?? [] ), 'is_scalar' ) );
		$price = $card['price'] ?? null;
		$stock = $card['stock'] ?? null;

		return $this->db->prepare(
			'(%d,%s,%s,%s,%s,%s,%d,%d,%d,%d,%s,' . ( is_numeric( $price ) ? '%s' : 'NULL' ) . ',' . ( is_numeric( $stock ) ? '%d' : 'NULL' ) . ',%s,%s,%d,%s,%s,' . ( '' === $data ? 'NULL' : '%s' ) . ',%d,%d,%s)', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- NULL takes no placeholder.
			array_merge(
				[
					$account_id,
					self::key( $offer_id ),
					$offer_id,
					mb_substr( (string) ( $card['name'] ?? '' ), 0, self::NAME_LENGTH ),
					self::url( (string) ( $card['image_url'] ?? '' ) ),
					substr( (string) ( $card['state'] ?? '' ), 0, 24 ),
					empty( $card['is_created'] ) ? 0 : 1,
					empty( $card['failed'] ) ? 0 : 1,
					empty( $card['archived'] ) ? 0 : 1,
					empty( $card['details_ok'] ) ? 0 : 1,
					mb_substr( (string) ( $card['status_text'] ?? '' ), 0, self::STATUS_LENGTH ),
				],
				is_numeric( $price ) ? [ number_format( (float) $price, 2, '.', '' ) ] : [],
				is_numeric( $stock ) ? [ (int) $stock ] : [],
				[
					false === $ids || strlen( $ids ) > self::IDS_LENGTH ? '' : $ids,
					mb_substr( trim( (string) ( $group['key'] ?? '' ) ), 0, self::GROUP_LENGTH ),
					max( 0, (int) ( $group['size'] ?? 0 ) ),
					'' === $ref ? '' : self::key( $ref ),
					mb_substr( (string) ( $unit['article'] ?? '' ), 0, Unit_Key::MAX_LENGTH ),
				],
				'' === $data ? [] : [ $data ],
				[
					$position,
					max( 0, $run ),
					$updated,
				]
			)
		);
	}

	/**
	 * What a unit card carries besides the list columns — its barcodes and its `unit` — as JSON.
	 *
	 * @param array<string,mixed> $card List row.
	 * @return string '' for a card that carries neither.
	 */
	private function unit_data( array $card ): string {
		$data = array_filter(
			[
				'barcodes' => array_values( array_filter( (array) ( $card['barcodes'] ?? [] ), 'is_string' ) ),
				'unit'     => (array) ( $card['unit'] ?? [] ),
			]
		);

		return [] === $data ? '' : (string) wp_json_encode( $data );
	}

	/**
	 * The upsert of a batch. A notification (position 0) keeps the place a known card had in the marketplace's order.
	 *
	 * @param string[] $values Prepared `VALUES` tuples.
	 * @return string
	 */
	private function upsert_sql( array $values ): string {
		$update = [];

		foreach ( array_diff( self::COLUMNS, [ 'account_id', 'card_key', 'position' ] ) as $column ) {
			$update[] = $column . ' = VALUES(' . $column . ')';
		}

		$update[] = 'position = CASE WHEN VALUES(position) = 0 THEN position ELSE VALUES(position) END';

		return 'INSERT INTO ' . $this->table . ' (' . implode( ',', self::COLUMNS ) . ') VALUES ' . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $update );
	}

	/**
	 * A picture address the screen may show: http(s) and short enough, or ''.
	 *
	 * @param string $url Candidate.
	 * @return string
	 */
	private static function url( string $url ): string {
		$url = trim( $url );

		return strlen( $url ) <= self::IMAGE_LENGTH && 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	/**
	 * A stored row as the card the screen and the import read: the list row's keys plus `wc_product_id` and `link_id`.
	 *
	 * @param array<string,mixed> $row Row with the joined `wc_product_id` and `link_id`.
	 * @return array<string,mixed>
	 */
	private function card( array $row ): array {
		$ids  = json_decode( (string) $row['remote_ids'], true );
		$data = json_decode( (string) ( $row['unit_data'] ?? '' ), true );

		return ( is_array( $data ) ? array_intersect_key( $data, array_flip( [ 'barcodes', 'unit' ] ) ) : [] ) + [
			'offer_id'      => (string) $row['offer_id'],
			'remote_ids'    => is_array( $ids ) ? $ids : [],
			'name'          => (string) $row['name'],
			'image_url'     => (string) $row['image'],
			'state'         => (string) $row['state'],
			'is_created'    => 1 === (int) $row['is_created'],
			'failed'        => 1 === (int) $row['failed'],
			'archived'      => 1 === (int) $row['archived'],
			'details_ok'    => 1 === (int) $row['details_ok'],
			'status_text'   => (string) $row['status_text'],
			'price'         => null === $row['price'] ? null : number_format( (float) $row['price'], 2, '.', '' ),
			'stock'         => null === $row['stock'] ? null : (int) $row['stock'],
			'group'         => [
				'key'  => (string) $row['group_key'],
				'size' => (int) $row['group_size'],
			],
			'updated_at'    => (string) $row['updated_at'],
			'wc_product_id' => (int) ( $row['wc_product_id'] ?? 0 ),
			'link_id'       => (int) ( $row['link_id'] ?? 0 ),
		];
	}

	/**
	 * @return int Unix time.
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * The account's record, with every key.
	 *
	 * @param int $account_id Account id.
	 * @return array{generation:int,running:int,refreshed_at:int,refreshing_at:int,error:string}
	 */
	private function meta( int $account_id ): array {
		$stored = get_option( $this->meta_name( $account_id ), [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'generation'    => max( 0, (int) ( $stored['generation'] ?? 0 ) ),
			'running'       => max( 0, (int) ( $stored['running'] ?? 0 ) ),
			'refreshed_at'  => max( 0, (int) ( $stored['refreshed_at'] ?? 0 ) ),
			'refreshing_at' => max( 0, (int) ( $stored['refreshing_at'] ?? 0 ) ),
			'error'         => (string) ( $stored['error'] ?? '' ),
		];
	}

	/**
	 * Stores the account's record.
	 *
	 * @param int                 $account_id Account id.
	 * @param array<string,mixed> $meta       Record.
	 * @return void
	 */
	private function save_meta( int $account_id, array $meta ): void {
		update_option( $this->meta_name( $account_id ), $meta, false );
	}

	/**
	 * @param int $account_id Account id.
	 * @return string
	 */
	private function meta_name( int $account_id ): string {
		return $this->prefix . self::META . $account_id;
	}
}
