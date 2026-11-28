<?php
/**
 * The actions on the seller's shipments.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Error_Classifier;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;
use Woodev\Framework\Marketplace\Settings\Shipment_Noun;
use Woodev\Framework\Marketplace\Shipments\Shipment;
use Woodev\Framework\Marketplace\Shipments\Shipment_Batches;
use Woodev\Framework\Marketplace\Shipments\Shipment_Candidate;
use Woodev\Framework\Marketplace\Shipments\Shipment_Contents;
use Woodev\Framework\Marketplace\Shipments\Shipment_File;
use Woodev\Framework\Marketplace\Shipments\Shipment_Refused;
use Woodev\Framework\Marketplace\Shipments\Shipment_Result;
use Woodev\Framework\Marketplace\Shipments\Shipment_Unit;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a shipment, adds and moves units, chooses where it goes, adds cargo places, hands it over and reads its documents.
 *
 * Every change asks the account's write right and the licence first, judges the request from the stored copy before anything is
 * sent (a request the marketplace refuses can cost many), holds the shipment while it is at the marketplace, and reads the shipment
 * again afterwards so the screen shows what happened. A hand-over reads the shipment first: what is missing is judged from what the
 * marketplace says now.
 *
 * **A change is sent once.** Before it leaves the shop it is claimed in the table of the seller actions
 * ({@see Wpdb_Seller_Action_Store}), under an identity that says what was meant: the name of a new shipment, where a unit goes,
 * the chosen values, the number of cargo places wanted, the hand-over. While the claim holds — on its way, accepted, or
 * unanswered — the same change is refused instead of sent again, so a double click or a repeated request does nothing twice;
 * a change that means something else goes through. A change the marketplace refused, or one that never reached it, frees its claim.
 *
 * Refusals are thrown as {@see Shipment_Refused} with the sentence for the merchant. A lost answer is reported as such, never as
 * success and never as a safe retry.
 *
 * @since 2.1.28
 */
final class Shipment_Service {
	/**
	 * Longest name of a new shipment, in characters.
	 */
	public const MAX_NAME_LENGTH = 128;

	/**
	 * Most missing items a refused hand-over names.
	 */
	private const NAMED_BLOCKERS = 3;

	/**
	 * Names of the changes in the claims table. A seller action of an adapter cannot hold a dot, so they never meet.
	 */
	private const CLAIM_CREATE    = 'shipment.create';
	private const CLAIM_UNIT      = 'shipment.unit';
	private const CLAIM_SHIPPING  = 'shipment.shipping';
	private const CLAIM_CARGO     = 'shipment.cargo';
	private const CLAIM_HAND_OVER = 'shipment.handover';

	/**
	 * @var Shipment_Batches
	 */
	private $marketplace;

	/**
	 * @var Wpdb_Shipment_Store
	 */
	private $store;

	/**
	 * @var Shipment_Snapshot
	 */
	private $snapshot;

	/**
	 * The account's write right.
	 *
	 * @var Posting_Action_Gate
	 */
	private $gate;

	/**
	 * @var License_Gate
	 */
	private $license;

	/**
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * @var Account_Pause
	 */
	private $pause;

	/**
	 * @var Shipment_Noun
	 */
	private $noun;

	/**
	 * Queues a refresh of an account's shipments: `function ( int $account_id ): void`.
	 *
	 * @var callable
	 */
	private $queue_refresh;

	/**
	 * Writes a line to the plugin's log: `function ( string $line ): void`.
	 *
	 * @var callable|null
	 */
	private $log;

	/**
	 * What was sent, so it is not sent twice.
	 *
	 * @var Wpdb_Seller_Action_Store
	 */
	private $sent;

	/**
	 * @since 2.1.28
	 *
	 * @param Shipment_Batches         $marketplace   The marketplace's shipments.
	 * @param Wpdb_Shipment_Store      $store         The shop's copy.
	 * @param Shipment_Snapshot        $snapshot      The reads.
	 * @param Posting_Action_Gate      $gate          The account's write right.
	 * @param License_Gate             $license       Licence gate.
	 * @param Error_Classifier         $classifier    Error classifier of the marketplace.
	 * @param Account_Pause            $pause         Account pause.
	 * @param Shipment_Noun            $noun          The marketplace's words.
	 * @param callable                 $queue_refresh `function ( int $account_id ): void`.
	 * @param Wpdb_Seller_Action_Store $sent          The claims of what was sent.
	 * @param callable|null            $log           `function ( string $line ): void`.
	 */
	public function __construct( Shipment_Batches $marketplace, Wpdb_Shipment_Store $store, Shipment_Snapshot $snapshot, Posting_Action_Gate $gate, License_Gate $license, Error_Classifier $classifier, Account_Pause $pause, Shipment_Noun $noun, callable $queue_refresh, Wpdb_Seller_Action_Store $sent, ?callable $log = null ) {
		$this->marketplace   = $marketplace;
		$this->store         = $store;
		$this->snapshot      = $snapshot;
		$this->gate          = $gate;
		$this->license       = $license;
		$this->classifier    = $classifier;
		$this->pause         = $pause;
		$this->noun          = $noun;
		$this->queue_refresh = $queue_refresh;
		$this->sent          = $sent;
		$this->log           = $log;
	}

	/**
	 * Why the account cannot change its shipments now.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $what    What the merchant tries to do, an infinitive phrase for the licence sentence.
	 * @return string Empty when it can.
	 */
	public function refusal( Account $account, string $what ): string {
		if ( ! $account->is_enabled() ) {
			return self::switched_off();
		}

		$licence = $this->license->refusal_for( $what );

		return null !== $licence ? $licence : $this->gate->write_refusal( $account );
	}

	/**
	 * Why the account cannot read a document now: it is switched off, or the licence does not allow a new request. Reading a
	 * document changes nothing, so the account's write right is not asked.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @return string Empty when it can.
	 */
	public function download_refusal( Account $account ): string {
		if ( ! $account->is_enabled() ) {
			return self::switched_off();
		}

		return (string) $this->license->refusal_for( __( 'скачать документ с маркетплейса', 'woodev-plugin-framework' ) );
	}

	/**
	 * Why an open shipment cannot be handed over: it is not open, it is empty, something required is missing, or the marketplace
	 * says so.
	 *
	 * @since 2.1.28
	 *
	 * @param Shipment_Contents $contents The shipment.
	 * @return string Empty when it can be handed over.
	 */
	public function hand_over_reason( Shipment_Contents $contents ): string {
		if ( ! $contents->shipment()->is_open() ) {
			return $this->closed_sentence();
		}

		if ( [] === $contents->units() ) {
			/* translators: %s: the marketplace's word for a shipment in the prepositional case, «поставке». */
			return sprintf( __( 'В %s нет товаров.', 'woodev-plugin-framework' ), $this->noun->to_one() );
		}

		$blockers = $contents->blockers();

		if ( [] !== $blockers ) {
			return $this->blockers_sentence( $blockers );
		}

		return $this->marketplace->hand_over_refusal( $contents->shipment(), $contents );
	}

	/**
	 * Creates an empty shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $name    Name; not empty.
	 * @return string Key of the new shipment.
	 * @throws Shipment_Refused When it was not created.
	 * @throws \Throwable       When the copy cannot be written.
	 */
	public function create( Account $account, string $name ): string {
		/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
		$this->require_write( $account, sprintf( __( 'создать %s на маркетплейсе', 'woodev-plugin-framework' ), $this->noun->acc() ) );

		/* translators: %s: the marketplace's word for a shipment, capitalised: «Поставка». */
		$created  = sprintf( __( '%s с таким названием уже создана. Если нужна ещё одна, дайте ей другое название или повторите через час.', 'woodev-plugin-framework' ), Posting_Noun::capitalised( $this->noun->one() ) );
		$shipment = $this->once(
			$account,
			self::CLAIM_CREATE,
			$name,
			[ $name ],
			function () use ( $account, $name ) {
				return $this->marketplace->create_shipment( $account, $name );
			},
			'Creating a shipment',
			$created
		);

		$this->store->save_header( $account->id(), $shipment );
		$this->refresh_shipment( $account, Wpdb_Shipment_Store::key( $shipment->id() ) );
		$this->queue( $account );

		return Wpdb_Shipment_Store::key( $shipment->id() );
	}

	/**
	 * The units of orders as a shipment would take them, from stored data.
	 *
	 * @since 2.1.28
	 *
	 * @param Account       $account Account.
	 * @param \WC_Order[]   $orders  Orders of the account.
	 * @param Shipment|null $target  The shipment; null for a new one.
	 * @return Shipment_Candidate[]
	 */
	public function candidates( Account $account, array $orders, ?Shipment $target = null ): array {
		return array_values( $this->marketplace->shipment_candidates( $account, $orders, $target ) );
	}

	/**
	 * Finds an open shipment of an account.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $key     Shipment key.
	 * @return array<string,mixed> Its row.
	 * @throws Shipment_Refused When it is gone or handed over.
	 */
	public function open_row( Account $account, string $key ): array {
		$row = $this->row( $account, $key );

		if ( 1 !== (int) $row['is_open'] ) {
			throw new Shipment_Refused( $this->closed_sentence() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $row;
	}

	/**
	 * Finds a shipment of an account.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $key     Shipment key.
	 * @return array<string,mixed> Its row.
	 * @throws Shipment_Refused When it is gone.
	 */
	public function row( Account $account, string $key ): array {
		$row = $this->store->find( $account->id(), $key );

		if ( null === $row ) {
			throw new Shipment_Refused( $this->not_found_sentence() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $row;
	}

	/**
	 * Adds the units of orders to an open shipment, or to a new one made for them.
	 *
	 * @since 2.1.28
	 *
	 * @param Account     $account Account.
	 * @param \WC_Order[] $orders  Orders of the account.
	 * @param string      $key     Open shipment; empty to create a new one named `$name`.
	 * @param string      $name    Name of the new shipment.
	 * @return Shipment_Report
	 * @throws Shipment_Refused When nothing was done.
	 * @throws \Throwable       When the copy cannot be written.
	 */
	public function add( Account $account, array $orders, string $key, string $name = '' ): Shipment_Report {
		/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
		$this->require_write( $account, sprintf( __( 'добавить товары в %s на маркетплейсе', 'woodev-plugin-framework' ), $this->noun->acc() ) );

		$row        = '' === $key ? null : $this->open_row( $account, $key );
		$target     = null === $row ? null : $this->store->shipment( $row );
		$report     = new Shipment_Report( $key );
		$candidates = $this->candidates( $account, $orders, $target );
		$eligible   = [];
		$examples   = [];

		foreach ( $candidates as $candidate ) {
			$example = self::example( $orders, $candidate );

			if ( $candidate->is_eligible() ) {
				$eligible[]                        = $candidate;
				$examples[ $candidate->unit_id() ] = $example;
			} else {
				$report->add_skipped( $candidate->refusal(), $example );
			}
		}

		if ( [] === $eligible ) {
			return $report;
		}

		if ( null === $row ) {
			$key = $this->create( $account, $name );
			$row = $this->open_row( $account, $key );
		}

		$report->into( $key );

		$this->locked(
			$account,
			[ $key ],
			function () use ( $account, $key, $row, $eligible, $examples, $report ): void {
				$units = [];

				foreach ( $eligible as $candidate ) {
					$units[ $candidate->unit_id() ] = $candidate->order_id();
				}

				$tokens = $this->claim_units( $account, $units, [ '', $key ], $examples, $report );
				$taken  = array_values(
					array_filter(
						$eligible,
						static function ( Shipment_Candidate $candidate ) use ( $tokens ): bool {
							return isset( $tokens[ $candidate->unit_id() ] );
						}
					)
				);

				if ( [] === $taken ) {
					return;
				}

				$shipment = $this->store->shipment( $row );
				$results  = $this->send(
					$account,
					function () use ( $account, $shipment, $taken ) {
						return $this->marketplace->add_units( $account, $shipment, $taken );
					},
					'Adding units to a shipment',
					$tokens
				);

				$this->answered( $report, $results, array_intersect_key( $examples, $tokens ), $tokens );
			}
		);

		return $report;
	}

	/**
	 * Moves units out of a shipment into an open one.
	 *
	 * @since 2.1.28
	 *
	 * @param Account  $account Account.
	 * @param string   $from    Key of the shipment they are in.
	 * @param string[] $units   Unit keys.
	 * @param string   $to      Key of the open shipment.
	 * @return Shipment_Report
	 * @throws Shipment_Refused When nothing was done.
	 * @throws \Throwable       When the copy cannot be written.
	 */
	public function move( Account $account, string $from, array $units, string $to ): Shipment_Report {
		/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
		$this->require_write( $account, sprintf( __( 'перенести товары в другую %s', 'woodev-plugin-framework' ), $this->noun->acc() ) );

		list( $source, $target, $eligible, $examples, $report ) = $this->move_plan( $account, $from, $units, $to );

		if ( [] === $eligible ) {
			return $report;
		}

		$this->locked(
			$account,
			[ $from, $to ],
			function () use ( $account, $from, $to, $source, $target, $eligible, $examples, $report ): void {
				$units = [];

				foreach ( $eligible as $unit ) {
					$row                  = $this->store->unit( $account->id(), $from, Wpdb_Shipment_Store::key( $unit->id() ) );
					$units[ $unit->id() ] = null === $row ? 0 : (int) $row['order_id'];
				}

				$tokens = $this->claim_units( $account, $units, [ $from, $to ], $examples, $report );
				$taken  = array_values(
					array_filter(
						$eligible,
						static function ( Shipment_Unit $unit ) use ( $tokens ): bool {
							return isset( $tokens[ $unit->id() ] );
						}
					)
				);

				if ( [] === $taken ) {
					return;
				}

				$results = $this->send(
					$account,
					function () use ( $account, $source, $target, $taken ) {
						return $this->marketplace->move_units( $account, $this->store->shipment( $source ), $this->store->shipment( $target ), $taken );
					},
					'Moving units between shipments',
					$tokens
				);

				$this->answered( $report, $results, array_intersect_key( $examples, $tokens ), $tokens );
			}
		);

		return $report;
	}

	/**
	 * Judges a move from stored data. Calls no marketplace.
	 *
	 * @since 2.1.28
	 *
	 * @param Account  $account Account.
	 * @param string   $from    Key of the shipment the units are in.
	 * @param string[] $units   Unit keys.
	 * @param string   $to      Key of the open shipment.
	 * @return array{0:array<string,mixed>,1:array<string,mixed>,2:Shipment_Unit[],3:array<string,string>,4:Shipment_Report}
	 *         Source row, target row, the units that can move, those units as the merchant knows them (by unit id), and the report
	 *         holding the ones that cannot.
	 * @throws Shipment_Refused When no unit could move at all: a shipment is gone, the target is not open or is the source.
	 */
	public function move_plan( Account $account, string $from, array $units, string $to ): array {
		$source = $this->row( $account, $from );
		$target = $this->open_row( $account, $to );

		if ( $from === $to ) {
			/* translators: %s: the marketplace's word for a shipment in the prepositional case, «поставке». */
			throw new Shipment_Refused( sprintf( __( 'Товар уже в этой %s.', 'woodev-plugin-framework' ), $this->noun->to_one() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$report   = new Shipment_Report( $to );
		$eligible = [];
		$examples = [];

		foreach ( $units as $unit_key ) {
			$row = $this->store->unit( $account->id(), $from, $unit_key );

			if ( null === $row ) {
				continue;
			}

			$unit    = $this->store->unit_of( $row );
			$refusal = $unit->is_movable()
				? $this->marketplace->move_refusal( $this->store->shipment( $source ), $this->store->shipment( $target ), $unit )
				: __( 'Маркетплейс не даёт перенести этот товар.', 'woodev-plugin-framework' );

			if ( '' === $refusal ) {
				$eligible[]              = $unit;
				$examples[ $unit->id() ] = self::unit_example( $row );
			} else {
				$report->add_skipped( $refusal, self::unit_example( $row ) );
			}
		}

		return [ $source, $target, $eligible, $examples, $report ];
	}

	/**
	 * Reads one shipment again, after something changed a unit of it elsewhere. A failure is only logged.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $key     Shipment key.
	 * @return void
	 */
	public function reread( Account $account, string $key ): void {
		$this->refresh_shipment( $account, $key );
		$this->queue( $account );
	}

	/**
	 * Sets the pickup point and the date of an open shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param Account              $account Account.
	 * @param string               $key     Shipment key.
	 * @param array<string,string> $values  Checked values by field id.
	 * @return void
	 * @throws Shipment_Refused When nothing was done.
	 * @throws \Throwable       When the copy cannot be written.
	 */
	public function choose_shipping( Account $account, string $key, array $values ): void {
		$this->require_write( $account, __( 'выбрать пункт и дату отгрузки', 'woodev-plugin-framework' ) );
		$this->open_row( $account, $key );

		$intent = $values;
		ksort( $intent ); // The order of the fields means nothing.

		$this->locked(
			$account,
			[ $key ],
			function () use ( $account, $key, $values, $intent ): void {
				$shipment = $this->store->shipment( $this->open_row( $account, $key ) );

				$this->once(
					$account,
					self::CLAIM_SHIPPING,
					$key,
					$intent,
					function () use ( $account, $shipment, $values ): void {
						$this->marketplace->choose_shipping( $account, $shipment, $values );
					},
					'Choosing the shipping of a shipment'
				);
			}
		);
	}

	/**
	 * Why no cargo place can be added to a shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param Shipment_Contents $contents The shipment.
	 * @return string Empty when places can be added.
	 */
	public function cargo_reason( Shipment_Contents $contents ): string {
		if ( ! $contents->shipment()->is_open() ) {
			return $this->closed_sentence();
		}

		return $this->marketplace->cargo_place_refusal( $contents->shipment(), $contents );
	}

	/**
	 * Why a shipment cannot be brought to a number of cargo places: it has more already, or one request cannot add that many.
	 *
	 * @since 2.1.28
	 *
	 * @param Shipment_Contents $contents The shipment.
	 * @param int               $total    Cargo places wanted in all.
	 * @return string Empty when it can, one that has exactly that many included: nothing is left to add.
	 */
	public function cargo_total_reason( Shipment_Contents $contents, int $total ): string {
		$present = count( $contents->cargo_places() );

		if ( $total === $present ) {
			return '';
		}

		$refusal = $this->cargo_reason( $contents );

		if ( '' !== $refusal ) {
			return $refusal;
		}

		if ( $total < $present ) {
			/* translators: 1: the marketplace's word for cargo places, capitalised: «Грузовые места», 2: how many there are. */
			return sprintf( __( '%1$s уже есть в количестве %2$d. Укажите, сколько их должно быть всего: убрать лишние отсюда нельзя.', 'woodev-plugin-framework' ), Posting_Noun::capitalised( $this->noun->places() ), $present );
		}

		$most = $this->marketplace->max_cargo_places();

		if ( $total - $present > $most ) {
			/* translators: 1: most places one request adds, 2: how many there are. */
			return sprintf( __( 'За один раз можно добавить не больше %1$d, а уже есть %2$d. Укажите меньшее общее количество.', 'woodev-plugin-framework' ), $most, $present );
		}

		return '';
	}

	/**
	 * Brings an open shipment to a number of cargo places: reads it again and adds the ones it lacks.
	 *
	 * The merchant says how many there must be in all, not how many to add, so a repeated request adds nothing: either the
	 * shipment has that many already, or the claim of that number still holds.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $key     Shipment key.
	 * @param int     $total   Cargo places wanted in all.
	 * @return int How many were asked for; 0 when the shipment has that many already.
	 * @throws Shipment_Refused When nothing was done.
	 * @throws \Throwable       When the copy cannot be written.
	 */
	public function fill_cargo_places( Account $account, string $key, int $total ): int {
		/* translators: %s: the marketplace's word for cargo places, «грузовые места». */
		$this->require_write( $account, sprintf( __( 'добавить %s', 'woodev-plugin-framework' ), $this->noun->places() ) );
		$this->open_row( $account, $key );

		$added = 0;

		$this->locked(
			$account,
			[ $key ],
			function () use ( $account, $key, $total, &$added ): void {
				$contents = $this->read_open( $account, $key, 'Reading a shipment before adding cargo places' );
				$refusal  = $this->cargo_total_reason( $contents, $total );

				if ( '' !== $refusal ) {
					throw new Shipment_Refused( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
				}

				$missing = $total - count( $contents->cargo_places() );

				if ( 0 === $missing ) {
					return;
				}

				$this->once(
					$account,
					self::CLAIM_CARGO,
					$key,
					[ $total ],
					function () use ( $account, $contents, $missing ): void {
						$this->marketplace->add_cargo_places( $account, $contents->shipment(), $missing );
					},
					'Adding cargo places'
				);

				$added = $missing;
			}
		);

		return $added;
	}

	/**
	 * Hands an open shipment over: first reads it again, refuses it when something is missing now, then sends it.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $key     Shipment key.
	 * @return void
	 * @throws Shipment_Refused When it was not handed over.
	 * @throws \Throwable       When the copy cannot be written.
	 */
	public function hand_over( Account $account, string $key ): void {
		/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
		$this->require_write( $account, sprintf( __( 'передать %s маркетплейсу', 'woodev-plugin-framework' ), $this->noun->acc() ) );
		$this->open_row( $account, $key );

		$this->locked(
			$account,
			[ $key ],
			function () use ( $account, $key ): void {
				$contents = $this->read_open( $account, $key, 'Reading a shipment before the hand-over' );
				$refusal  = $this->hand_over_reason( $contents );

				if ( '' !== $refusal ) {
					throw new Shipment_Refused( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
				}

				$this->once(
					$account,
					self::CLAIM_HAND_OVER,
					$key,
					[],
					function () use ( $account, $contents ): void {
						$this->marketplace->hand_over( $account, $contents->shipment() );
					},
					'Handing a shipment over'
				);
			}
		);
	}

	/**
	 * Reads a document of a shipment from the marketplace.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account     Account.
	 * @param string  $key         Shipment key.
	 * @param string  $document_id Document id.
	 * @return Shipment_File
	 * @throws Shipment_Refused When it cannot be had.
	 */
	public function document( Account $account, string $key, string $document_id ): Shipment_File {
		$refused = $this->download_refusal( $account );

		if ( '' !== $refused ) {
			throw new Shipment_Refused( $refused ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$contents = $this->store->contents( $this->row( $account, $key ) );
		$refusal  = $this->marketplace->document_refusal( $contents->shipment(), $contents, $document_id );

		if ( '' !== $refusal ) {
			throw new Shipment_Refused( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $this->ask(
			$account,
			function () use ( $account, $contents, $document_id ) {
				return $this->marketplace->shipment_document( $account, $contents->shipment(), $document_id );
			},
			'Reading a document of a shipment'
		);
	}

	/**
	 * Reads a held open shipment from the marketplace, before a change that depends on what is in it now.
	 *
	 * @param Account $account Account.
	 * @param string  $key     Shipment key.
	 * @param string  $what    Why it is read, for the log.
	 * @return Shipment_Contents
	 * @throws Shipment_Refused When it is gone, not open, or the marketplace does not answer.
	 */
	private function read_open( Account $account, string $key, string $what ): Shipment_Contents {
		$row = $this->open_row( $account, $key );

		return $this->ask(
			$account,
			function () use ( $account, $row ) {
				return $this->snapshot->read( $account, $row );
			},
			$what
		);
	}

	/**
	 * Sends one change unless it was already sent: claims it, sends it, keeps what became of it.
	 *
	 * @param Account      $account Account.
	 * @param string       $action  Name of the change in the claims table.
	 * @param string       $subject What is changed: a shipment key, or the name of a new shipment.
	 * @param array<mixed> $intent  What is meant by the change; another intent is another change.
	 * @param callable     $call    The remote change.
	 * @param string       $what    What is done, for the log.
	 * @param string       $already The sentence for a change the marketplace has accepted; empty for the usual one.
	 * @return mixed What the call returned.
	 * @throws Shipment_Refused When it is held, was refused or got no answer.
	 */
	private function once( Account $account, string $action, string $subject, array $intent, callable $call, string $what, string $already = '' ) {
		$identity = self::identity( $intent );
		$this->forget_expired( $account );
		$token = $this->claim( $account, 0, $subject, $action, $identity );

		if ( null === $token ) {
			throw new Shipment_Refused( $this->held_sentence( $account, 0, $subject, $action, $identity, $already ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$result = $this->send( $account, $call, $what, [ $token ] );

		$this->settle( [ $token ], Wpdb_Seller_Action_Store::SENT );

		return $result;
	}

	/**
	 * Claims the units about to be sent. One already sent the same way is left out and reported.
	 *
	 * The claim of a unit remembers where it was last sent, so it can be sent elsewhere, and back again.
	 *
	 * @param Account              $account  Account.
	 * @param array<string,int>    $units    Unit id => its order (0 for none).
	 * @param string[]             $route    Key of the shipment they leave (empty for none) and of the one they join.
	 * @param array<string,string> $examples Unit id => the unit as the merchant knows it.
	 * @param Shipment_Report      $report   Where the units left out are reported.
	 * @return array<string,string> Unit id => claim token, for every unit to send.
	 * @throws Shipment_Refused When the claims cannot be written; nothing is sent.
	 */
	private function claim_units( Account $account, array $units, array $route, array $examples, Shipment_Report $report ): array {
		$identity = self::identity( $route );
		$tokens   = [];

		$this->forget_expired( $account );

		foreach ( $units as $unit_id => $order_id ) {
			$unit_id = (string) $unit_id;

			try {
				$token = $this->claim( $account, $order_id, $unit_id, self::CLAIM_UNIT, $identity );
			} catch ( Shipment_Refused $e ) {
				$this->settle( $tokens, null );

				throw $e;
			}

			if ( null === $token ) {
				$report->add_skipped( $this->held_sentence( $account, $order_id, $unit_id, self::CLAIM_UNIT, $identity ), $examples[ $unit_id ] );
			} else {
				$tokens[ $unit_id ] = $token;
			}
		}

		return $tokens;
	}

	/**
	 * @param Account $account  Account.
	 * @param int     $order_id Order; 0 for a change of a shipment.
	 * @param string  $subject  What is changed.
	 * @param string  $action   Name of the change.
	 * @param string  $identity Identity of what is sent.
	 * @return string|null The claim token; null when it must not be sent now.
	 * @throws Shipment_Refused When the claim cannot be written.
	 */
	private function claim( Account $account, int $order_id, string $subject, string $action, string $identity ): ?string {
		try {
			return $this->sent->claim( $account->id(), $order_id, $subject, $action, $identity );
		} catch ( \RuntimeException $e ) {
			$this->write_log( sprintf( 'Claiming a shipment action failed: %s', $e->getMessage() ) );

			throw new Shipment_Refused( __( 'Не удалось записать действие в базе данных сайта, поэтому на маркетплейс ничего не отправлено. Повторите чуть позже.', 'woodev-plugin-framework' ), 500 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Removes the claims that hold nothing any more; a failure only leaves them for the next time.
	 *
	 * @param Account $account Account.
	 * @return void
	 */
	private function forget_expired( Account $account ): void {
		try {
			$this->sent->forget_expired( $account->id() );
		} catch ( \RuntimeException $e ) {
			$this->write_log( sprintf( 'Removing old shipment claims failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Closes claims: keeps them under a state, or frees them. A write the database refuses here is only logged: the marketplace
	 * has already answered, and the claim runs out by itself.
	 *
	 * @param string[]    $tokens Claim tokens.
	 * @param string|null $state  {@see Wpdb_Seller_Action_Store::SENT} or {@see Wpdb_Seller_Action_Store::UNKNOWN}; null to free.
	 * @return void
	 */
	private function settle( array $tokens, ?string $state ): void {
		foreach ( $tokens as $token ) {
			try {
				if ( null === $state ) {
					$this->sent->release( $token );
				} else {
					$this->sent->keep( $token, $state );
				}
			} catch ( \RuntimeException $e ) {
				$this->write_log( sprintf( 'Closing a shipment claim failed: %s', $e->getMessage() ) );
			}
		}
	}

	/**
	 * Why a change that is held is not sent again.
	 *
	 * @param Account $account  Account.
	 * @param int     $order_id Order; 0 for a change of a shipment.
	 * @param string  $subject  What is changed.
	 * @param string  $action   Name of the change.
	 * @param string  $identity Identity of what would be sent.
	 * @param string  $already  The sentence for an accepted change; empty for the usual one.
	 * @return string
	 */
	private function held_sentence( Account $account, int $order_id, string $subject, string $action, string $identity, string $already = '' ): string {
		$state = $this->sent->blocking_state( $account->id(), $order_id, $subject, $action, $identity );

		if ( Wpdb_Seller_Action_Store::SENT === $state ) {
			return '' !== $already ? $already : __( 'Это действие уже отправлено. Дождитесь, пока список обновится.', 'woodev-plugin-framework' );
		}

		if ( Wpdb_Seller_Action_Store::UNKNOWN === $state ) {
			return __( 'Это действие уже отправлялось, но ответа не было: оно могло выполниться. Проверьте результат в кабинете маркетплейса; повторить можно через час.', 'woodev-plugin-framework' );
		}

		return __( 'Это действие уже отправляется. Дождитесь ответа.', 'woodev-plugin-framework' );
	}

	/**
	 * @param array<mixed> $intent What is meant by a change.
	 * @return string 32 characters.
	 */
	private static function identity( array $intent ): string {
		return md5( serialize( $intent ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Hashed, never stored or read back.
	}

	/**
	 * Holds the shipments, runs the action, reads them again whatever happened, and queues a refresh.
	 *
	 * @param Account  $account Account.
	 * @param string[] $keys    Shipment keys.
	 * @param callable $action  The action.
	 * @return void
	 * @throws Shipment_Refused When another action holds a shipment, or the action refused.
	 * @throws \Throwable       When the copy cannot be written.
	 */
	private function locked( Account $account, array $keys, callable $action ): void {
		$tokens = [];

		try {
			foreach ( $keys as $key ) {
				$token = $this->store->lock( $account->id(), $key );

				if ( null === $token ) {
					throw new Shipment_Refused( $this->busy_sentence() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
				}

				$tokens[ $key ] = $token;
			}

			try {
				$action();
			} finally {
				foreach ( array_keys( $tokens ) as $key ) {
					$this->refresh_shipment( $account, (string) $key );
				}

				$this->queue( $account );
			}
		} finally {
			foreach ( $tokens as $token ) {
				$this->store->unlock( $token );
			}
		}
	}

	/**
	 * Makes a change on the marketplace. A technical failure leaves its outcome unknown, so the sentence promises nothing.
	 *
	 * @param Account  $account Account.
	 * @param callable $call    The call.
	 * @param string   $what    What is done, for the log.
	 * @param string[] $tokens  Claims of the change: freed when it did not happen, kept as unanswered when it may have.
	 * @return mixed What the call returned.
	 * @throws Shipment_Refused When the marketplace refused or did not answer.
	 */
	private function send( Account $account, callable $call, string $what, array $tokens ) {
		/* translators: %s: the marketplace's word for a shipment in the accusative, «поставку». */
		return $this->remote( $account, $call, $what, sprintf( __( 'Маркетплейс не ответил: действие могло выполниться. Проверьте %s, прежде чем повторять.', 'woodev-plugin-framework' ), $this->noun->acc() ), $tokens );
	}

	/**
	 * Asks the marketplace for something; nothing changes there.
	 *
	 * @param Account  $account Account.
	 * @param callable $call    The call.
	 * @param string   $what    What is read, for the log.
	 * @return mixed What the call returned.
	 * @throws Shipment_Refused When the marketplace refused or did not answer.
	 */
	private function ask( Account $account, callable $call, string $what ) {
		return $this->remote( $account, $call, $what, __( 'Маркетплейс не ответил. Повторите позже.', 'woodev-plugin-framework' ) );
	}

	/**
	 * @param Account  $account    Account.
	 * @param callable $call       The call.
	 * @param string   $what       For the log.
	 * @param string   $unanswered The sentence when no answer came.
	 * @param string[] $tokens     Claims of a change.
	 * @return mixed What the call returned.
	 * @throws Shipment_Refused When the marketplace refused or did not answer.
	 */
	private function remote( Account $account, callable $call, string $what, string $unanswered, array $tokens = [] ) {
		try {
			return $call();
		} catch ( Shipment_Refused $e ) {
			$this->settle( $tokens, null ); // Nothing was done: it can be sent again.

			throw $e;
		} catch ( \Throwable $e ) {
			$this->write_log( sprintf( '%s failed: %s: %s', $what, get_class( $e ), $e->getMessage() ) );

			$error = $this->classifier->classify( $e );

			if ( null !== $error && $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
				$this->pause->apply( $account, $error );
			}

			$untouched = null !== $error && ( $error->is( Remote_Error::THROTTLE ) || ! $error->was_sent() );

			$this->settle( $tokens, $untouched ? null : Wpdb_Seller_Action_Store::UNKNOWN );

			if ( null !== $error && $error->is( Remote_Error::THROTTLE ) ) {
				throw new Shipment_Refused( __( 'Маркетплейс ограничил число запросов. Повторите через несколько минут.', 'woodev-plugin-framework' ), 429 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}

			throw new Shipment_Refused( $unanswered, 502 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Reads one shipment again after an action. A failure is logged and leaves the shipment marked, so a refresh reads it later,
	 * a handed-over one included.
	 *
	 * @param Account $account Account.
	 * @param string  $key     Shipment key.
	 * @return void
	 */
	private function refresh_shipment( Account $account, string $key ): void {
		try {
			$row = $this->store->find( $account->id(), $key );

			if ( null !== $row ) {
				$this->snapshot->read( $account, $row );
			}
		} catch ( \Throwable $e ) {
			$this->write_log( sprintf( 'Reading a shipment after an action failed: %s', $e->getMessage() ) );
			$this->mark_unread( $account, $key );
		}
	}

	/**
	 * @param Account $account Account.
	 * @param string  $key     Shipment key.
	 * @return void
	 */
	private function mark_unread( Account $account, string $key ): void {
		try {
			$this->store->mark_unread( $account->id(), $key );
		} catch ( \Throwable $e ) {
			$this->write_log( sprintf( 'Marking a shipment for reading failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * @param Account $account Account.
	 * @return void
	 */
	private function queue( Account $account ): void {
		try {
			call_user_func( $this->queue_refresh, $account->id() );
		} catch ( \Throwable $e ) {
			$this->write_log( sprintf( 'Queueing the refresh of the shipments failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Counts the marketplace's answers; a unit it did not mention is failed, never done.
	 *
	 * @param Shipment_Report      $report  Report.
	 * @param Shipment_Result[]    $results Answers.
	 * @param array<string,string> $sent    Unit id => the unit as the merchant knows it, for every unit that was sent.
	 * @param array<string,string> $tokens  Unit id => its claim: kept for a unit that was taken or not answered for, freed for a
	 *                                      refused one.
	 * @return void
	 */
	private function answered( Shipment_Report $report, array $results, array $sent, array $tokens ): void {
		$answers = [];

		foreach ( $results as $result ) {
			$answers[ $result->unit_id() ] = $result;
		}

		foreach ( $sent as $unit_id => $example ) {
			$answer = $answers[ (string) $unit_id ] ?? null;

			if ( null === $answer ) {
				$state = Wpdb_Seller_Action_Store::UNKNOWN;
				$report->add_failed( __( 'Маркетплейс не ответил по этому товару: проверьте его, прежде чем повторять.', 'woodev-plugin-framework' ), $example );
			} elseif ( $answer->is_done() ) {
				$state = Wpdb_Seller_Action_Store::SENT;
				$report->add_done();
			} else {
				$state = null;
				$report->add_failed( $answer->reason(), $example );
			}

			$this->settle( [ $tokens[ (string) $unit_id ] ], $state );
		}
	}

	/**
	 * @param Account $account Account.
	 * @param string  $what    What the merchant tries to do.
	 * @return void
	 * @throws Shipment_Refused When the account or the licence does not allow it.
	 */
	private function require_write( Account $account, string $what ): void {
		$refusal = $this->refusal( $account, $what );

		if ( '' !== $refusal ) {
			throw new Shipment_Refused( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * What the merchant reads for a shipment that is no longer in the shop's copy.
	 *
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function not_found_sentence(): string {
		/* translators: %s: the marketplace's word for a shipment, capitalised: «Поставка». */
		return sprintf( __( '%s не найдена. Обновите страницу.', 'woodev-plugin-framework' ), Posting_Noun::capitalised( $this->noun->one() ) );
	}

	/**
	 * What the merchant reads while another action holds a shipment.
	 *
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function busy_sentence(): string {
		/* translators: %s: the marketplace's word for a shipment, «поставка». */
		return sprintf( __( 'Другое действие ещё выполняется: %s занята. Подождите немного и повторите.', 'woodev-plugin-framework' ), $this->noun->one() );
	}

	/**
	 * @return string Why a switched-off account cannot reach the marketplace.
	 */
	private static function switched_off(): string {
		return __( 'Подключение к маркетплейсу выключено в настройках плагина. Включите его и повторите.', 'woodev-plugin-framework' );
	}

	/**
	 * What the merchant reads for a change to a handed-over shipment.
	 *
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function closed_sentence(): string {
		/* translators: %s: the marketplace's word for a shipment, capitalised: «Поставка». */
		return sprintf( __( '%s уже передана, изменить её нельзя.', 'woodev-plugin-framework' ), Posting_Noun::capitalised( $this->noun->one() ) );
	}

	/**
	 * @param array<int,array{unit:\Woodev\Framework\Marketplace\Shipments\Shipment_Unit,requirement:\Woodev\Framework\Marketplace\Shipments\Shipment_Requirement}> $blockers Blockers.
	 * @return string «Не хватает обязательных данных: …».
	 */
	private function blockers_sentence( array $blockers ): string {
		$named = [];

		foreach ( array_slice( $blockers, 0, self::NAMED_BLOCKERS ) as $blocker ) {
			$named[] = '' === $blocker['unit']->label()
				? $blocker['requirement']->label()
				: $blocker['unit']->label() . ' — ' . $blocker['requirement']->label();
		}

		$list = implode( '; ', $named );
		$rest = count( $blockers ) - count( $named );

		if ( $rest > 0 ) {
			/* translators: %d: number of further missing items. */
			$list .= ' ' . sprintf( __( 'и ещё %d', 'woodev-plugin-framework' ), $rest );
		}

		/* translators: %s: list of goods with what each misses. */
		return sprintf( __( 'Не хватает обязательных данных: %s.', 'woodev-plugin-framework' ), $list );
	}

	/**
	 * A candidate as the merchant knows it.
	 *
	 * @since 2.1.28
	 *
	 * @param \WC_Order[]        $orders    Orders the candidate comes from.
	 * @param Shipment_Candidate $candidate Candidate.
	 * @return string «№ 123: Футболка, M».
	 */
	public static function example( array $orders, Shipment_Candidate $candidate ): string {
		$number = (string) $candidate->order_id();

		foreach ( $orders as $order ) {
			if ( (int) $order->get_id() === $candidate->order_id() ) {
				$number = (string) $order->get_order_number();
				break;
			}
		}

		return self::numbered( $number, $candidate->label() );
	}

	/**
	 * @param array<string,mixed> $row Unit row.
	 * @return string The stored unit as the merchant knows it.
	 */
	private static function unit_example( array $row ): string {
		return (int) $row['order_id'] > 0 ? self::numbered( (string) $row['order_id'], (string) $row['label'] ) : (string) $row['label'];
	}

	/**
	 * @param string $number Order number.
	 * @param string $label  Goods.
	 * @return string
	 */
	private static function numbered( string $number, string $label ): string {
		return '№ ' . $number . ( '' === $label ? '' : ': ' . $label );
	}

	/**
	 * @param string $line Log line.
	 * @return void
	 */
	private function write_log( string $line ): void {
		if ( null !== $this->log ) {
			call_user_func( $this->log, $line );
		}
	}
}
