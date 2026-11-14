<?php
/**
 * The `link_cards` and `refresh_cards` jobs.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Article_Policy;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Progress_Store;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Flows\Card_Identity;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Card_Matcher;
use Woodev\Framework\Marketplace\Flows\Link_Resolve_Exception;
use Woodev\Framework\Marketplace\Flows\Link_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Both jobs read the marketplace and write only our tables.
 *
 * **`link_cards( account_id, category_id, attempt )`**
 *
 * 1. The job gate (account enabled, licence, account pause) and the account claim, as for every account-wide run. No write gate:
 *    nothing is sent to the marketplace.
 * 2. The cabinet's cards by the adapter's {@see Link_Resolver}, then the shop's products with an article, then {@see Card_Matcher}.
 * 3. The details of the matched cards; every **exact** pair is linked through {@see Card_Linker}; the **near** pairs go to the report
 *    the merchant confirms one by one ({@see Link_Report_Store}).
 * 4. A summary of the run is stored.
 *
 * **`refresh_cards( account_id, attempt )`** fills the snapshot of the tab that lists the marketplace's cards
 * ({@see Cards_Snapshot_Store}).
 *
 * Failures follow the adapter's {@see Error_Classifier}: a refused credential pauses the account, a wait is not an attempt, a
 * server error retries on the {@see Backoff} ladder up to {@see self::MAX_ATTEMPTS}; any other refusal ends the run with its text
 * shown to the merchant. Nothing is thrown out of the job.
 *
 * @since 2.1.0
 */
final class Link_Jobs {
	public const MAX_ATTEMPTS = Account_Job::MAX_ATTEMPTS;

	private const FAILURE_CODE = 'link_cards_failed';
	private const RENEW_EVERY  = 100;

	/**
	 * The gate → claim → work → classify skeleton.
	 *
	 * @var Account_Job
	 */
	private $job;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Linker.
	 *
	 * @var Card_Linker
	 */
	private $linker;

	/**
	 * Lists the shop's products with an article: `function ( ?int $category_id ): array`, see `Shop_Sku_Source::products()`.
	 *
	 * @var callable
	 */
	private $shop_products;

	/**
	 * Reports.
	 *
	 * @var Link_Report_Store
	 */
	private $reports;

	/**
	 * Snapshot.
	 *
	 * @var Cards_Snapshot_Store
	 */
	private $snapshots;

	/**
	 * Scheduler.
	 *
	 * @var Link_Scheduler
	 */
	private $scheduler;

	/**
	 * The marketplace's side of the cabinet reads.
	 *
	 * @var Link_Resolver
	 */
	private $resolver;

	/**
	 * The marketplace's article rules.
	 *
	 * @var Article_Policy
	 */
	private $articles;

	/**
	 * Unix time: `function (): int`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Where an interrupted run left off.
	 *
	 * @var Link_Progress_Store
	 */
	private $progress;

	/**
	 * Creates the jobs.
	 *
	 * @since 2.1.0
	 *
	 * @param Job_Gate             $gate       Job gate.
	 * @param Wpdb_Link_Store      $links      Links.
	 * @param Card_Linker          $linker     Linker.
	 * @param callable             $shop       `function ( ?int $category_id ): array`, the shop's products with an article (`Shop_Sku_Source::products()`).
	 * @param Link_Report_Store    $reports    Reports.
	 * @param Cards_Snapshot_Store $snapshots  Snapshot.
	 * @param Link_Scheduler       $scheduler  Scheduler.
	 * @param Link_Resolver        $resolver   The adapter's cabinet reader.
	 * @param Article_Policy       $articles   The adapter's article rules.
	 * @param Error_Classifier     $classifier The adapter's error classifier.
	 * @param Api_Error_Ring       $ring       Error ring.
	 * @param callable             $log_error  `function ( string $line ): void`.
	 * @param Link_Progress_Store  $progress   Progress of interrupted runs.
	 * @param callable|null        $clock      `function (): int`; the system clock when null.
	 */
	public function __construct( Job_Gate $gate, Wpdb_Link_Store $links, Card_Linker $linker, callable $shop, Link_Report_Store $reports, Cards_Snapshot_Store $snapshots, Link_Scheduler $scheduler, Link_Resolver $resolver, Article_Policy $articles, Error_Classifier $classifier, Api_Error_Ring $ring, callable $log_error, Link_Progress_Store $progress, ?callable $clock = null ) {
		$this->links         = $links;
		$this->linker        = $linker;
		$this->shop_products = $shop;
		$this->reports       = $reports;
		$this->snapshots     = $snapshots;
		$this->scheduler     = $scheduler;
		$this->resolver      = $resolver;
		$this->articles      = $articles;
		$this->progress      = $progress;
		$this->clock         = $clock ?? 'time';
		$this->job           = new Account_Job( $gate, $classifier, $ring, $log_error, $resolver->list_path(), self::FAILURE_CODE, [ Link_Resolve_Exception::class ] );
	}

	/**
	 * Hooks both jobs.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->link_hook(), [ $this, 'link_cards' ], 10, 3 );
		add_action( $this->scheduler->refresh_hook(), [ $this, 'refresh_cards' ], 10, 2 );
	}

	/**
	 * Runs `link_cards`.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $account_id  Account id.
	 * @param mixed $category_id Product category, 0 for the whole shop.
	 * @param mixed $attempt     Failed attempts so far.
	 * @return void
	 */
	public function link_cards( $account_id = 0, $category_id = 0, $attempt = 0 ): void {
		$account_id  = (int) $account_id;
		$category_id = (int) $category_id;
		$attempt     = (int) $attempt;

		$this->job->run(
			$account_id,
			$this->scheduler->link_hook(),
			function ( int $delay, int $failures ) use ( $account_id, $category_id ): bool {
				return $this->scheduler->retry_link( $account_id, $category_id, $failures, $delay );
			},
			function ( string $text ) use ( $account_id ): void {
				$this->progress->clear( $account_id, Link_Progress_Store::KIND_LINK );
				$this->reports->save_run(
					$account_id,
					[
						'finished_at' => (int) call_user_func( $this->clock ),
						'error'       => $text,
					]
				);
			},
			$attempt,
			function ( Account $account, Claim_Fence $fence ) use ( $category_id ): void {
				$this->link_run( $account, $fence, $category_id > 0 ? $category_id : null );
			}
		);
	}

	/**
	 * Runs `refresh_cards`.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $account_id Account id.
	 * @param mixed $attempt    Failed attempts so far.
	 * @return void
	 */
	public function refresh_cards( $account_id = 0, $attempt = 0 ): void {
		$account_id = (int) $account_id;
		$attempt    = (int) $attempt;

		$this->job->run(
			$account_id,
			$this->scheduler->refresh_hook(),
			function ( int $delay, int $failures ) use ( $account_id ): bool {
				return $this->scheduler->retry_refresh( $account_id, $failures, $delay );
			},
			function ( string $text ) use ( $account_id ): void {
				$this->progress->clear( $account_id, Link_Progress_Store::KIND_REFRESH );
				$this->snapshots->fail( $account_id, $text );
			},
			$attempt,
			function ( Account $account, Claim_Fence $fence ): void {
				$this->refresh_run( $account, $fence );
			}
		);
	}

	/**
	 * Links the cards of the cabinet that match the shop's products.
	 *
	 * @param Account     $account     Account.
	 * @param Claim_Fence $fence       The run's claim.
	 * @param int|null    $category_id Product category to link, or null for the whole shop.
	 * @return void
	 * @throws \Throwable Whatever interrupted the run (a governor wait, a lost claim), after its progress was kept.
	 */
	private function link_run( Account $account, Claim_Fence $fence, ?int $category_id ): void {
		$account_id = $account->id();
		$context    = $this->scheduler->link_hook();
		$renew      = function () use ( $account_id, $fence ): void {
			$this->job->hold( $account_id, $fence );
		};
		$record     = $this->progress->load( $account_id, Link_Progress_Store::KIND_LINK, (int) $category_id, (int) call_user_func( $this->clock ) );

		$state = $record['state'] + [
			'catalogue' => [],
			'details'   => [],
		];

		try {
			$cards   = $this->resolver->catalogue( $account, $context, $renew, $state['catalogue'] );
			$products = call_user_func( $this->shop_products, $category_id );
			$matched  = Card_Matcher::match( $cards, $products, $this->links->identity_map( $account_id ), $this->articles );

			$renew();
			$details = $this->resolver->details(
				$account,
				$context,
				array_merge( array_column( $matched['exact'], 'card' ), array_column( $matched['near'], 'card' ) ),
				$renew,
				$state['details']
			);

			$tally = $this->link_exact( $account_id, $matched, $details, $fence, $renew );
		} catch ( \Throwable $e ) {
			// A governor wait or a lost claim comes back later: keep the pages and detail requests already answered.
			$record['state'] = $state;
			$this->progress->save( $account_id, Link_Progress_Store::KIND_LINK, $record );

			throw $e;
		}

		$entries = $this->near_entries( $matched['near'], $details );

		if ( null !== $category_id ) {
			$entries = array_merge( $this->near_entries_outside( $account_id, $products ), $entries );
		}

		$dropped = $this->reports->replace_near_matches( $account_id, $entries );

		$this->progress->clear( $account_id, Link_Progress_Store::KIND_LINK );
		$this->reports->save_run(
			$account_id,
			[
				'finished_at'    => (int) call_user_func( $this->clock ),
				'cards'          => count( $cards ),
				'linked'         => $tally['linked'],
				'already_linked' => $tally['already_linked'],
				'busy'           => $tally['busy'],
				'near_matches'   => count( $matched['near'] ),
				'near_dropped'   => $dropped,
				'ambiguous'      => $matched['ambiguous'],
				'category_id'    => (int) $category_id,
				'error'          => '',
			]
		);
	}

	/**
	 * Links every exact pair.
	 *
	 * @param int                               $account_id Account id.
	 * @param array<string,mixed>               $matched    Result of the matcher.
	 * @param array<string,array<string,mixed>> $details Card details by `offer_id`.
	 * @param Claim_Fence                       $fence      The run's claim.
	 * @param callable                          $renew      Extends the claim or throws.
	 * @return array{linked:int,already_linked:int,busy:int}
	 * @throws Claim_Lost When a write finds the claim taken over.
	 */
	private function link_exact( int $account_id, array $matched, array $details, Claim_Fence $fence, callable $renew ): array {
		$tally = [
			'linked'         => 0,
			'already_linked' => $matched['already_linked_cards'] + $matched['already_linked_products'],
			'busy'           => 0,
		];

		foreach ( $matched['exact'] as $index => $pair ) {
			if ( 0 === $index % self::RENEW_EVERY ) {
				$renew();
			}

			$this->link_pair( $account_id, $pair, $details, $fence, $tally );
		}

		return $tally;
	}

	/**
	 * Links one exact pair and counts the outcome.
	 *
	 * @param int                                           $account_id Account id.
	 * @param array<string,mixed>                           $pair       `product` and `card`.
	 * @param array<string,array<string,mixed>>             $details    Card details by `offer_id`.
	 * @param Claim_Fence                                   $fence      The run's claim.
	 * @param array{linked:int,already_linked:int,busy:int} $tally      Counters, updated.
	 * @return void
	 * @throws Claim_Lost When the write finds the claim taken over.
	 */
	private function link_pair( int $account_id, array $pair, array $details, Claim_Fence $fence, array &$tally ): void {
		$card = $this->confirm( $pair['card'], $details );

		// The marketplace did not return the card's detail, or returned another article for it: the next run tries again.
		if ( null === $card ) {
			++$tally['busy'];
			return;
		}

		if ( ! empty( $pair['card']['archived'] ) ) {
			$card['state'] = Link_Record::STATE_ARCHIVED;
		}

		$outcome = $this->linker->link( $account_id, (int) $pair['product']['id'], (int) $pair['product']['parent_id'], $card, $fence );

		if ( Card_Linker::CLAIM_LOST === $outcome ) {
			throw new Claim_Lost( 'The account claim was taken over before a link was written.' );
		}

		if ( Card_Linker::LINKED === $outcome ) {
			++$tally['linked'];
		} elseif ( Card_Linker::PRODUCT_LINKED === $outcome || Card_Linker::CARD_LINKED === $outcome ) {
			++$tally['already_linked'];
		} else {
			++$tally['busy'];
		}
	}

	/**
	 * Confirms a summary card with its detail: the adapter's answer, and the framework's own check that it is still the same remote
	 * card. A card replaced between the list and the detail by another one with the same article is refused here, whatever the
	 * adapter's {@see Link_Resolver::confirmed()} made of the article.
	 *
	 * @param array<string,mixed>               $summary Summary card.
	 * @param array<string,array<string,mixed>> $details Card details by `offer_id`.
	 * @return array<string,mixed>|null Null when there is no detail, it names another article, or it is another remote card.
	 */
	private function confirm( array $summary, array $details ): ?array {
		$card = $this->resolver->confirmed( $summary, $details[ (string) $summary['offer_id'] ] ?? null );

		return null !== $card && Card_Identity::same( $summary, $card ) ? $card : null;
	}

	/**
	 * The entries of the stored report that a run over some products did not look at: a run of one category must not wipe the
	 * pairs of the others.
	 *
	 * @param int                            $account_id Account id.
	 * @param array<int,array<string,mixed>> $products   The shop products the run covered.
	 * @return array<int,array<string,mixed>>
	 */
	private function near_entries_outside( int $account_id, array $products ): array {
		$covered = array_map( 'intval', array_column( $products, 'id' ) );

		return array_values(
			array_filter(
				$this->reports->near_matches( $account_id ),
				static function ( array $entry ) use ( $covered ): bool {
					return ! in_array( (int) ( $entry['wc_product_id'] ?? 0 ), $covered, true );
				}
			)
		);
	}

	/**
	 * Builds the entries of the near-match report.
	 *
	 * @param array<int,array<string,mixed>>    $near    Near pairs of the matcher.
	 * @param array<string,array<string,mixed>> $details Card details by `offer_id`.
	 * @return array<int,array<string,mixed>>
	 */
	private function near_entries( array $near, array $details ): array {
		$entries = [];

		foreach ( $near as $pair ) {
			$card = $this->confirm( $pair['card'], $details );

			if ( null === $card ) {
				continue; // No detail, or a detail that names another article: the pair would store a card that is not one.
			}

			$entries[] = [
				'wc_product_id' => (int) $pair['product']['id'],
				'wc_parent_id'  => (int) $pair['product']['parent_id'],
				'wc_sku'        => (string) $pair['product']['sku'],
				'relation'      => (string) $pair['relation'],
				'card'          => $card,
			];
		}

		return $entries;
	}

	/**
	 * Reads the cabinet with names and states and stores it for the tab.
	 *
	 * @param Account     $account Account.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return void
	 * @throws \Throwable Whatever interrupted the run (a governor wait, a lost claim), after its progress was kept.
	 */
	private function refresh_run( Account $account, Claim_Fence $fence ): void {
		$account_id = $account->id();
		$record     = $this->progress->load( $account_id, Link_Progress_Store::KIND_REFRESH, 0, (int) call_user_func( $this->clock ) );
		$state      = $record['state'];

		try {
			$cards = $this->resolver->snapshot(
				$account,
				$this->scheduler->refresh_hook(),
				function () use ( $account_id, $fence ): void {
					$this->job->hold( $account_id, $fence );
				},
				$state
			);
		} catch ( \Throwable $e ) {
			$record['state'] = $state;
			$this->progress->save( $account_id, Link_Progress_Store::KIND_REFRESH, $record );

			throw $e;
		}

		$this->progress->clear( $account_id, Link_Progress_Store::KIND_REFRESH );

		if ( ! $this->snapshots->put( $account_id, $cards, (int) call_user_func( $this->clock ) ) ) {
			$this->snapshots->fail( $account_id, __( 'Список карточек получен, но сохранить его не удалось: в нём слишком много товаров для просмотра на этой странице.', 'woodev-plugin-framework' ) );
		}
	}
}
