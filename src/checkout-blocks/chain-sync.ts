/**
 * The ONE queue every write to the customer's saved location chain goes through (SP-11 C-1, #1087).
 *
 * The chooser has two server writes — `/location/select` and `/location/forget` — and the shopper
 * can cause them in any order, faster than the network answers. Sent independently they race: a slow
 * `/forget` lands after a newer `/select` and erases it; a `/select` that completes after the shopper
 * moved on leaves a record nobody asked for. So:
 *
 * - **Serialized.** At most one request is on the wire; the next starts only when it has settled, so
 *   the server sees the writes in the order the shopper made them.
 * - **Generation counter.** Every intent takes the next generation. A reply that belongs to an older
 *   generation is never acted on (`superseded`) — the newest intent is already queued behind it and
 *   overwrites (`select`) or erases (`forget`) whatever that reply left on the server. Should that
 *   newest intent be a `/select` the server REFUSES, it overwrote nothing: the queue remembers the
 *   record the stale one may have left (`residue`) and reports the refusal as `failed`, which the
 *   caller answers with a `/forget`. A completed stale `/select` therefore always gets its
 *   compensating write.
 * - **Latest intent wins.** An intent still waiting to start is replaced by a newer one; only the
 *   request already on the wire is waited for.
 * - **Gated.** While anything is queued or on the wire the whole drain runs inside `gate()`
 *   (WooCommerce's `disableCheckoutFor`), so an order cannot be placed against a chain that is about
 *   to change — nor, after a pick, against rates that have not been recalculated for it yet.
 * - **One per page.** The queue and its generation counter belong to the PAGE, not to a chooser:
 *   WooCommerce unmounts and remounts the address block, and a queue per mount would let the old
 *   mount's cleanup `/forget` run beside — and after — the new mount's `/select`
 *   ({@link sharedChainSync}). With one queue the cleanup is just an older intent: the new mount's
 *   pick supersedes it.
 *
 * Pure: no React, no store, no network of its own — the effects are injected.
 *
 * @package woodev-plugin-framework
 */

import type { LocationRecord, SelectResult } from './types';

export type Intent = { kind: 'select'; record: LocationRecord } | { kind: 'forget' };

export type Outcome =
	/** The write landed and is still what the shopper wants. */
	| { status: 'applied' }
	/** A newer intent took over; whatever this one left behind is being overwritten or erased. */
	| { status: 'superseded' }
	/** The server answered and kept nothing — its chain is where it was. */
	| { status: 'refused'; reason: 'not-persisted' | 'cancelled' | 'refused'; message?: string }
	/** No usable answer: the write may or may not have landed. */
	| { status: 'failed' };

/**
 * Told how the intent ended. For an `applied` intent, returning `true` asks the queue to refresh
 * the cart's rates before it lets the checkout go.
 */
export type Settle = ( outcome: Outcome ) => boolean | void;

export interface SyncEffects {
	select: ( record: LocationRecord ) => Promise< SelectResult >;
	forget: () => Promise< boolean >;
	/** Recalculates the cart's rates against the chain as it now stands. Never rejects. */
	refresh: () => Promise< void >;
	/** Runs `work` with Place Order blocked. */
	gate: ( work: () => Promise< void > ) => Promise< void >;
	/** Pause before a failed `/forget` is tried once more. */
	retryDelayMs: number;
}

interface Job {
	generation: number;
	intent: Intent;
	settle: Settle;
}

const wait = ( ms: number ): Promise< void > => new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );

export class ChainSync {
	private generation = 0;

	/** The newest intent that has not started yet. */
	private waiting: Job | null = null;

	/** The intent whose request is on the wire. */
	private flying: Job | null = null;

	private draining = false;

	/** A superseded `/select` may have left a record nothing has overwritten or erased yet. */
	private residue = false;

	public constructor( private effects: SyncEffects ) {}

	/** The chooser that mounts next brings its own (equivalent) effects. */
	public use( effects: SyncEffects ): void {
		this.effects = effects;
	}

	/** Queues `intent` as the shopper's latest word and returns its generation. */
	public request( intent: Intent, settle: Settle ): number {
		const generation = ++this.generation;

		this.waiting?.settle( { status: 'superseded' } );
		this.waiting = { generation, intent, settle };

		if ( ! this.draining ) {
			this.draining = true;
			// `drain()` never rejects; a gate that does must not leave an unhandled rejection behind.
			void this.effects.gate( () => this.drain() ).catch( () => undefined );
		}

		return generation;
	}

	/**
	 * The chooser is going away. A `/select` that is queued or on the wire can no longer be finished
	 * — nobody is left to write the native address it belongs to — so it is erased. Answers whether
	 * there was one.
	 */
	public abandon(): boolean {
		if ( this.flying?.intent.kind !== 'select' && this.waiting?.intent.kind !== 'select' ) {
			return false;
		}

		this.request( { kind: 'forget' }, () => undefined );

		return true;
	}

	private isCurrent( job: Job ): boolean {
		return job.generation === this.generation;
	}

	private async drain(): Promise< void > {
		try {
			while ( this.waiting ) {
				this.flying = this.waiting;
				this.waiting = null;

				try {
					await this.run( this.flying );
				} catch {
					// An effect or a settle callback threw: the queue itself must keep draining.
				}

				this.flying = null;
			}
		} finally {
			this.flying = null;
			this.draining = false;
		}
	}

	private async run( job: Job ): Promise< void > {
		if ( job.intent.kind === 'select' ) {
			const result = await this.effects.select( job.intent.record );

			if ( ! this.isCurrent( job ) ) {
				// Saved, or unanswered and so possibly saved: the newer intent has to remove it.
				this.residue ||= result.ok || result.reason === 'unreachable';
				job.settle( { status: 'superseded' } );

				return;
			}

			if ( ! result.ok ) {
				// A refusal wrote nothing — so it did not overwrite a stale record either.
				job.settle(
					result.reason === 'unreachable' || this.residue
						? { status: 'failed' }
						: { status: 'refused', reason: result.reason, message: result.message }
				);

				return;
			}

			this.residue = false;

			// The caller writes the native address here, synchronously with the freshness check above.
			if ( job.settle( { status: 'applied' } ) === true && this.isCurrent( job ) ) {
				await this.effects.refresh();
			}

			return;
		}

		let forgotten = await this.effects.forget();

		if ( ! forgotten && this.isCurrent( job ) ) {
			await wait( this.effects.retryDelayMs );

			if ( this.isCurrent( job ) ) {
				forgotten = await this.effects.forget();
			}
		}

		if ( forgotten ) {
			this.residue = false;
		}

		if ( ! this.isCurrent( job ) ) {
			job.settle( { status: 'superseded' } );

			return;
		}

		job.settle( forgotten ? { status: 'applied' } : { status: 'failed' } );

		if ( forgotten && this.isCurrent( job ) ) {
			await this.effects.refresh();
		}
	}
}

let shared: ChainSync | null = null;

/**
 * The page's ONE queue. Every chooser mount asks for it here instead of making its own, so the
 * serialization and the generation counter outlive an unmount: a cleanup `/forget` the old mount
 * queued and a pick the new mount makes are two intents of the same queue, in order.
 */
export function sharedChainSync( effects: SyncEffects ): ChainSync {
	if ( shared === null ) {
		shared = new ChainSync( effects );
	} else {
		shared.use( effects );
	}

	return shared;
}

/** Drops the page's queue. Tests only. */
export function resetSharedChainSync(): void {
	shared = null;
}
