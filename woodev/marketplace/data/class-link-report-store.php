<?php
/**
 * What the last «link by article» run found, per account.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Two small records per account, kept as options (no autoload), named under the plugin's option family ({@see Uninstaller::option_prefix()}) so that the
 * uninstaller removes them with everything else:
 *
 * - the **near-match report** pairs of a shop product and a marketplace card whose articles differ only in case or in
 *   look-alike letters, waiting for the merchant to confirm each one. An entry carries the card as the run read it, so
 *   confirming it is a local write — a request never calls the marketplace;
 * - the **summary of the last run**, for the line the tools page and the tab show.
 *
 * A new run replaces the report: it is recomputed from the current state of both sides. A pair the merchant answered «no» to
 * ({@see self::dismiss_near_match()}) is remembered, so the next run does not offer it again; the memory is by the product, the
 * product's article and the card's article, so a pair whose article changed on either side is offered again. A run that does
 * not find the pair (it covered one category, or a card detail did not come back) does not forget the answer; the memory is
 * bounded by {@see self::MAX_ENTRIES}, oldest first.
 */
final class Link_Report_Store {
	public const NEAR_OPTION         = 'near_matches_';
	public const DISMISSED_OPTION    = 'near_dismissed_';
	public const RUN_OPTION          = 'link_run_';
	public const IMPORT_NOTES_OPTION = 'import_notes_';

	/**
	 * Most entries a report keeps. A shop with more look-alike pairs than this has a systematic problem a list cannot fix; the
	 * summary says how many were left out.
	 */
	public const MAX_ENTRIES = 500;

	/**
	 * Option family of the plugin.
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
	 * Removes the near-match report, the dismissed pairs, the import notes and the last run summary of an account: the account is being deleted.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function forget_account( int $account_id ): void {
		delete_option( $this->near_option( $account_id ) );
		delete_option( $this->prefix . self::RUN_OPTION . $account_id );
		delete_option( $this->prefix . self::DISMISSED_OPTION . $account_id );
		delete_option( $this->prefix . self::IMPORT_NOTES_OPTION . $account_id );
	}

	/**
	 * Replaces the near-match report of an account with the pairs of a run, minus the pairs the merchant answered «no» to.
	 *
	 * @param int                            $account_id Account id.
	 * @param array<int,array<string,mixed>> $entries    Entries: `wc_product_id`, `wc_parent_id`, `wc_sku`, `relation`, `card`.
	 * @return int Offered entries dropped for the limit.
	 */
	public function replace_near_matches( int $account_id, array $entries ): int {
		$dismissed = $this->dismissed( $account_id );
		$offered   = array_values(
			array_filter(
				$entries,
				static function ( array $entry ) use ( $dismissed ): bool {
					return ! in_array( self::fingerprint( $entry ), $dismissed, true );
				}
			)
		);
		$kept      = array_slice( $offered, 0, self::MAX_ENTRIES );

		if ( [] === $kept ) {
			delete_option( $this->near_option( $account_id ) );
		} else {
			update_option( $this->near_option( $account_id ), $kept, false );
		}

		return count( $offered ) - count( $kept );
	}

	/**
	 * Returns the near-match report of an account.
	 *
	 * @param int $account_id Account id.
	 * @return array<int,array<string,mixed>>
	 */
	public function near_matches( int $account_id ): array {
		$stored = get_option( $this->near_option( $account_id ), [] );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : [];
	}

	/**
	 * Finds one entry of the report.
	 *
	 * @param int    $account_id    Account id.
	 * @param int    $wc_product_id Product id.
	 * @param string $offer_id      Article of the card.
	 * @return array<string,mixed>|null
	 */
	public function find_near_match( int $account_id, int $wc_product_id, string $offer_id ): ?array {
		foreach ( $this->near_matches( $account_id ) as $entry ) {
			if ( (int) ( $entry['wc_product_id'] ?? 0 ) === $wc_product_id && (string) ( $entry['card']['offer_id'] ?? '' ) === $offer_id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Removes the entries a decision settles: the pair itself, and every other entry of the same product or the same card (a
	 * product can be linked to one card and a card to one product).
	 *
	 * @param int    $account_id    Account id.
	 * @param int    $wc_product_id Product id.
	 * @param string $offer_id      Article of the card.
	 * @param bool   $whole_pair    True after a link: the product's and the card's other entries go too. False after «нет»: only the pair.
	 * @return void
	 */
	public function settle_near_match( int $account_id, int $wc_product_id, string $offer_id, bool $whole_pair ): void {
		$left = [];

		foreach ( $this->near_matches( $account_id ) as $entry ) {
			$same_product = (int) ( $entry['wc_product_id'] ?? 0 ) === $wc_product_id;
			$same_card    = (string) ( $entry['card']['offer_id'] ?? '' ) === $offer_id;
			$settled      = $whole_pair ? ( $same_product || $same_card ) : ( $same_product && $same_card );

			if ( ! $settled ) {
				$left[] = $entry;
			}
		}

		if ( [] === $left ) {
			delete_option( $this->near_option( $account_id ) );
		} else {
			update_option( $this->near_option( $account_id ), $left, false );
		}
	}

	/**
	 * «Нет, это разные»: takes the pair out of the report and remembers the answer, so that the next «link by article» run does not
	 * offer the same pair again. Only the pair goes: the product and the card keep their other look-alikes.
	 *
	 * @since 2.1.5
	 *
	 * @param int    $account_id    Account id.
	 * @param int    $wc_product_id Product id.
	 * @param string $offer_id      Article of the card.
	 * @return bool False when the report holds no such pair (it was settled already).
	 */
	public function dismiss_near_match( int $account_id, int $wc_product_id, string $offer_id ): bool {
		$entry = $this->find_near_match( $account_id, $wc_product_id, $offer_id );

		if ( null === $entry ) {
			return false;
		}

		$dismissed   = $this->dismissed( $account_id );
		$dismissed[] = self::fingerprint( $entry );
		$this->remember_dismissed( $account_id, array_slice( array_values( array_unique( $dismissed ) ), -self::MAX_ENTRIES ) );

		$this->settle_near_match( $account_id, $wc_product_id, $offer_id, false );

		return true;
	}

	/**
	 * Stores the summary of the last run.
	 *
	 * @param int                 $account_id Account id.
	 * @param array<string,mixed> $summary    `finished_at`, `linked`, `already_linked`, `near_matches`, `ambiguous`, `busy`, `error`.
	 * @return void
	 */
	public function save_run( int $account_id, array $summary ): void {
		update_option( $this->prefix . self::RUN_OPTION . $account_id, $summary, false );
	}

	/**
	 * Returns the summary of the last run.
	 *
	 * @param int $account_id Account id.
	 * @return array<string,mixed>|null Null when no run has finished.
	 */
	public function last_run( int $account_id ): ?array {
		$stored = get_option( $this->prefix . self::RUN_OPTION . $account_id, null );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Records why a card was not imported, or what went wrong with it, for the merchant to read in the card's status cell. One note
	 * per article: a note for the same article replaces the earlier one and moves to the newest end. Past {@see self::MAX_ENTRIES}
	 * the oldest is dropped.
	 *
	 * The note keeps two fields apart: `text`, the sentence the merchant reads, and `detail`, the technical tail (exception class,
	 * HTTP status), which may be empty. The store stores both as given and never reads the logging switch: the caller shows the
	 * detail when it renders the row, so the switch decides what the merchant sees at that moment.
	 *
	 * @since 2.1.6
	 *
	 * @param int    $account_id Account id.
	 * @param string $offer_id   Article of the card.
	 * @param string $text       The sentence the merchant reads.
	 * @param string $detail     Technical tail, or ''.
	 * @return void
	 */
	public function set_import_note( int $account_id, string $offer_id, string $text, string $detail = '' ): void {
		$notes = array_values(
			array_filter(
				$this->import_notes( $account_id ),
				static function ( array $note ) use ( $offer_id ): bool {
					return $note['offer_id'] !== $offer_id;
				}
			)
		);

		$notes[] = [
			'offer_id' => $offer_id,
			'text'     => $text,
			'detail'   => $detail,
		];

		update_option( $this->import_notes_option( $account_id ), array_slice( $notes, -self::MAX_ENTRIES ), false );
	}

	/**
	 * Returns the import notes of an account, oldest first.
	 *
	 * @since 2.1.6
	 *
	 * @param int $account_id Account id.
	 * @return array<int,array{offer_id:string,text:string,detail:string}>
	 */
	public function import_notes( int $account_id ): array {
		$stored = get_option( $this->import_notes_option( $account_id ), [] );

		$notes = [];
		foreach ( is_array( $stored ) ? $stored : [] as $note ) {
			if ( is_array( $note ) && isset( $note['offer_id'] ) ) {
				$notes[] = [
					'offer_id' => (string) $note['offer_id'],
					'text'     => (string) ( $note['text'] ?? '' ),
					'detail'   => (string) ( $note['detail'] ?? '' ),
				];
			}
		}

		return $notes;
	}

	/**
	 * Removes the import note of one article, after a later import of it succeeded.
	 *
	 * @since 2.1.6
	 *
	 * @param int    $account_id Account id.
	 * @param string $offer_id   Article of the card.
	 * @return void
	 */
	public function clear_import_note( int $account_id, string $offer_id ): void {
		$notes = array_values(
			array_filter(
				$this->import_notes( $account_id ),
				static function ( array $note ) use ( $offer_id ): bool {
					return $note['offer_id'] !== $offer_id;
				}
			)
		);

		if ( [] === $notes ) {
			delete_option( $this->import_notes_option( $account_id ) );
		} else {
			update_option( $this->import_notes_option( $account_id ), $notes, false );
		}
	}

	/**
	 * Names the option of an account's import notes.
	 *
	 * @param int $account_id Account id.
	 * @return string
	 */
	private function import_notes_option( int $account_id ): string {
		return $this->prefix . self::IMPORT_NOTES_OPTION . $account_id;
	}

	/**
	 * Names the option of an account's near-match report.
	 *
	 * @param int $account_id Account id.
	 * @return string
	 */
	private function near_option( int $account_id ): string {
		return $this->prefix . self::NEAR_OPTION . $account_id;
	}

	/**
	 * What identifies a pair for a «no»: the product, its article and the card's article.
	 *
	 * @param array<string,mixed> $entry Report entry.
	 * @return string
	 */
	private static function fingerprint( array $entry ): string {
		return (int) ( $entry['wc_product_id'] ?? 0 ) . '|' . (string) ( $entry['wc_sku'] ?? '' ) . '|' . (string) ( $entry['card']['offer_id'] ?? '' );
	}

	/**
	 * The pairs the merchant answered «no» to.
	 *
	 * @param int $account_id Account id.
	 * @return string[] Fingerprints.
	 */
	private function dismissed( int $account_id ): array {
		$stored = get_option( $this->prefix . self::DISMISSED_OPTION . $account_id, [] );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : [];
	}

	/**
	 * Stores the answered pairs; nothing to store removes the option.
	 *
	 * @param int      $account_id  Account id.
	 * @param string[] $fingerprints Fingerprints.
	 * @return void
	 */
	private function remember_dismissed( int $account_id, array $fingerprints ): void {
		if ( [] === $fingerprints ) {
			delete_option( $this->prefix . self::DISMISSED_OPTION . $account_id );
		} else {
			update_option( $this->prefix . self::DISMISSED_OPTION . $account_id, $fingerprints, false );
		}
	}
}
