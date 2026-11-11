<?php
/**
 * The cabinet reader, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Link_Resolver;

/**
 * Answers with whatever the test put in {@see self::$cards}, {@see self::$details} and {@see self::$snapshot}. Every read bumps a
 * counter in the cursor it was handed, so a test can see that an interrupted run keeps its position and the next run gets it back. A
 * `\Throwable` in {@see self::$failure} is thrown by the next read, once.
 */
final class Scripted_Link_Resolver implements Link_Resolver {
	/**
	 * Summary cards the cabinet holds.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $cards = [];

	/**
	 * Details by `offer_id`.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public $details = [];

	/**
	 * The tab's snapshot.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $snapshot = [];

	/**
	 * Thrown by the next read when set.
	 *
	 * @var \Throwable|null
	 */
	public $failure;

	/**
	 * Runs on every read before it answers: `function ( string $kind ): void` (a test can take the account claim away there).
	 *
	 * @var callable|null
	 */
	public $on_read;

	/**
	 * Reads: `[ kind, context, cursor as handed in, offer ids asked for ]`.
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>,3:string[]}>
	 */
	public $reads = [];

	/**
	 * Names the request path the card list is read from.
	 *
	 * @return string
	 */
	public function list_path(): string {
		return '/fake/cards';
	}

	/**
	 * Reads the cabinet.
	 *
	 * @param Account             $account Account.
	 * @param string              $context Job.
	 * @param callable            $renew   Extends the claim.
	 * @param array<string,mixed> $state   Cursor.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable The scripted failure.
	 */
	public function catalogue( Account $account, string $context, callable $renew, array &$state ): array {
		$this->reads[] = [ 'catalogue', $context, $state, [] ];
		$this->before_answer( 'catalogue', $renew, $state );

		return $this->cards;
	}

	/**
	 * Reads the details of cards.
	 *
	 * @param Account                        $account Account.
	 * @param string                         $context Job.
	 * @param array<int,array<string,mixed>> $cards   Summary cards.
	 * @param callable                       $renew   Extends the claim.
	 * @param array<string,mixed>            $state   Cursor.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable The scripted failure.
	 */
	public function details( Account $account, string $context, array $cards, callable $renew, array &$state ): array {
		$asked         = array_map( 'strval', array_column( $cards, 'offer_id' ) );
		$this->reads[] = [ 'details', $context, $state, $asked ];
		$this->before_answer( 'details', $renew, $state );

		return array_intersect_key( $this->details, array_flip( $asked ) );
	}

	/**
	 * Merges a card with its detail; a detail that names another article is no confirmation.
	 *
	 * @param array<string,mixed>      $card   Summary card.
	 * @param array<string,mixed>|null $detail Detail.
	 * @return array<string,mixed>|null
	 */
	public function confirmed( array $card, ?array $detail ): ?array {
		if ( null === $detail || (string) ( $detail['offer_id'] ?? '' ) !== (string) $card['offer_id'] ) {
			return null;
		}

		return array_merge( $card, $detail );
	}

	/**
	 * Reads the snapshot.
	 *
	 * @param Account             $account Account.
	 * @param string              $context Job.
	 * @param callable            $renew   Extends the claim.
	 * @param array<string,mixed> $state   Cursor.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable The scripted failure.
	 */
	public function snapshot( Account $account, string $context, callable $renew, array &$state ): array {
		$this->reads[] = [ 'snapshot', $context, $state, [] ];
		$this->before_answer( 'snapshot', $renew, $state );

		return $this->snapshot;
	}

	/**
	 * Applies the script of a read: the hook, the claim renewal, the cursor, the failure.
	 *
	 * @param string              $kind  Read.
	 * @param callable            $renew Extends the claim.
	 * @param array<string,mixed> $state Cursor.
	 * @return void
	 * @throws \Throwable The scripted failure.
	 */
	private function before_answer( string $kind, callable $renew, array &$state ): void {
		if ( null !== $this->on_read ) {
			call_user_func( $this->on_read, $kind );
		}

		$renew();

		$state['reads'] = (int) ( $state['reads'] ?? 0 ) + 1;

		if ( null !== $this->failure ) {
			$failure       = $this->failure;
			$this->failure = null;

			throw $failure;
		}
	}
}
