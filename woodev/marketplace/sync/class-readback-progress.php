<?php
/**
 * How far one readback of a page got.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * What {@see Stock_Readback::reconcile_page()} did with the links of a page: the entries it changed, and the links of the
 * warehouse groups it did not reach because the run's request or time budget ran out first. The job resumes at the first of
 * those and reads nothing it already finished.
 *
 * @since 2.1.8
 */
final class Readback_Progress {
	/**
	 * Entries changed (mirrored or drifted), by link id.
	 *
	 * @var array<int,array{link_id:int,wc_stock:int,expected:int,present:int,reserved:int}>
	 */
	private $changed;

	/**
	 * Link ids of the groups that were not read.
	 *
	 * @var int[]
	 */
	private $unread;

	/**
	 * Creates the progress.
	 *
	 * @since 2.1.8
	 *
	 * @param array<int,array{link_id:int,wc_stock:int,expected:int,present:int,reserved:int}> $changed Entries changed, by link id.
	 * @param int[]                                                                            $unread  Link ids not reached.
	 */
	public function __construct( array $changed, array $unread ) {
		$this->changed = $changed;
		$this->unread  = array_values( array_map( 'intval', $unread ) );
	}

	/**
	 * Returns the entries changed.
	 *
	 * @since 2.1.8
	 *
	 * @return array<int,array{link_id:int,wc_stock:int,expected:int,present:int,reserved:int}>
	 */
	public function changed(): array {
		return $this->changed;
	}

	/**
	 * Tells whether every link of the page that can be read was reached.
	 *
	 * @since 2.1.8
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return [] === $this->unread;
	}

	/**
	 * Returns the cursor from which the page continues: the link before the first link not reached.
	 *
	 * @since 2.1.8
	 *
	 * @return int|null Null when the page is complete.
	 */
	public function resume_cursor(): ?int {
		return [] === $this->unread ? null : max( 0, min( $this->unread ) - 1 );
	}
}
