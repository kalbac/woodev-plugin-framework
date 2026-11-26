<?php
/**
 * A marketplace that keeps notes about its links.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Admin\Shared\Link_Note;
use Woodev\Framework\Marketplace\Admin\Shared\Link_Notes;

/**
 * Answers the notes a test wrote down and records what it was asked.
 */
final class Fake_Link_Notes implements Link_Notes {
	/**
	 * Notes by link id.
	 *
	 * @var array<int,Link_Note>
	 */
	public $notes = [];

	/**
	 * Every question: `[ account id, link ids ]`.
	 *
	 * @var array<int,array{0:int,1:int[]}>
	 */
	public $asked = [];

	/**
	 * Notes of some links.
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Link ids.
	 * @return array<int,Link_Note>
	 */
	public function link_notes( int $account_id, array $link_ids ): array {
		$this->asked[] = [ $account_id, $link_ids ];

		return array_intersect_key( $this->notes, array_flip( $link_ids ) );
	}
}
