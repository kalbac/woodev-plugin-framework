<?php
/**
 * A cabinet reader that pages the list, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Card_List_Source;
use Woodev\Framework\Marketplace\Flows\Link_Resolver;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

/**
 * Answers {@see self::list_page()} with the pages the test put in {@see self::$pages}, one per call, by the page number it keeps in the
 * cursor; {@see self::list_cards()} with the rows of {@see self::$single}. A `\Throwable` in {@see self::$failures} under a page number
 * is thrown once when that page is asked for. The linking reads are not used by the tests of this double.
 */
final class Paged_Link_Resolver implements Link_Resolver, Card_List_Source {
	/**
	 * Pages of list rows, in order.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	public $pages = [];

	/**
	 * List rows by article, for single reads.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public $single = [];

	/**
	 * Thrown once when the page with this index is asked for.
	 *
	 * @var array<int,\Throwable>
	 */
	public $failures = [];

	/**
	 * Runs after a page was read, before it is answered: `function ( int $index ): void` (a test can take the account claim away there).
	 *
	 * @var callable|null
	 */
	public $on_page;

	/**
	 * Reads: `[ kind, cursor as handed in, offer ids asked for ]`.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>,2:string[]}>
	 */
	public $reads = [];

	/**
	 * @return string
	 */
	public function list_path(): string {
		return '/fake/cards';
	}

	/**
	 * @param Account             $account Account.
	 * @param string              $context Job.
	 * @param callable            $renew   Extends the claim.
	 * @param array<string,mixed> $state   Cursor.
	 * @return array<int,array<string,mixed>>
	 */
	public function catalogue( Account $account, string $context, callable $renew, array &$state ): array {
		return [];
	}

	/**
	 * @param Account                        $account Account.
	 * @param string                         $context Job.
	 * @param array<int,array<string,mixed>> $cards   Cards.
	 * @param callable                       $renew   Extends the claim.
	 * @param array<string,mixed>            $state   Cursor.
	 * @return array<string,array<string,mixed>>
	 */
	public function details( Account $account, string $context, array $cards, callable $renew, array &$state ): array {
		return [];
	}

	/**
	 * @param array<string,mixed>      $card   Card.
	 * @param array<string,mixed>|null $detail Detail.
	 * @return array<string,mixed>|null
	 */
	public function confirmed( array $card, ?array $detail ): ?array {
		return null;
	}

	/**
	 * Never used: the framework pages a {@see Card_List_Source}.
	 *
	 * @param Account             $account Account.
	 * @param string              $context Job.
	 * @param callable            $renew   Extends the claim.
	 * @param array<string,mixed> $state   Cursor.
	 * @return array<int,array<string,mixed>>
	 * @throws \LogicException Always.
	 */
	public function snapshot( Account $account, string $context, callable $renew, array &$state ): array {
		throw new \LogicException( 'A paged resolver is not read in one piece.' );
	}

	/**
	 * @param Account             $account Account.
	 * @param string              $context Job.
	 * @param callable            $renew   Extends the claim.
	 * @param array<string,mixed> $state   Cursor: `next`, the index of the page to read.
	 * @return array{cards:array<int,array<string,mixed>>,done:bool}
	 * @throws \Throwable The scripted failure of that page.
	 */
	public function list_page( Account $account, string $context, callable $renew, array &$state ): array {
		$index         = (int) ( $state['next'] ?? 0 );
		$this->reads[] = [ 'page', $state, [] ];

		$renew();

		if ( isset( $this->failures[ $index ] ) ) {
			$failure = $this->failures[ $index ];
			unset( $this->failures[ $index ] );

			throw $failure;
		}

		$state['next'] = $index + 1;

		if ( null !== $this->on_page ) {
			call_user_func( $this->on_page, $index );
		}

		return [
			'cards' => $this->pages[ $index ] ?? [],
			'done'  => $index + 1 >= count( $this->pages ),
		];
	}

	/**
	 * @param Account  $account   Account.
	 * @param string   $context   Job.
	 * @param string[] $offer_ids Articles.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_cards( Account $account, string $context, array $offer_ids ): array {
		$this->reads[] = [ 'cards', [], $offer_ids ];

		return array_values( array_intersect_key( $this->single, array_flip( $offer_ids ) ) );
	}

	/**
	 * @return string[]
	 */
	public function card_event_types(): array {
		return [ 'card_changed' ];
	}

	/**
	 * @param Inbound_Event $event Event.
	 * @return string
	 */
	public function event_article( Inbound_Event $event ): string {
		return (string) ( $event->payload()['offer_id'] ?? '' );
	}
}
