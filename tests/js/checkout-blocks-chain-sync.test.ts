import { ChainSync, type Outcome, type SyncEffects } from '../../src/checkout-blocks/chain-sync';
import type { LocationRecord, SelectResult } from '../../src/checkout-blocks/types';

const record = ( key: string ): LocationRecord => ( {
	key,
	provider_id: 'dadata',
	level: 'settlement',
	country: 'RU',
} );

const flush = (): Promise< void > => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

interface Rig {
	sync: ChainSync;
	selects: string[];
	forgets: number;
	/** Resolves the oldest unanswered `/select`. */
	answerSelect: ( result?: SelectResult ) => Promise< void >;
	/** Lets the in-flight `refresh()` finish. */
	finishRefresh: () => Promise< void >;
}

function rig(): Rig {
	const pending: Array< ( result: SelectResult ) => void > = [];
	let finish: () => void = () => undefined;
	const state = { selects: [] as string[], forgets: 0 };

	const effects: SyncEffects = {
		select: ( rec ) => {
			state.selects.push( rec.key );

			return new Promise< SelectResult >( ( resolve ) => pending.push( resolve ) );
		},
		forget: () => {
			state.forgets++;

			return Promise.resolve( true );
		},
		refresh: () => new Promise< void >( ( resolve ) => ( finish = resolve ) ),
		gate: ( work ) => work(),
		retryDelayMs: 0,
	};

	return {
		sync: new ChainSync( effects ),
		get selects() {
			return state.selects;
		},
		get forgets() {
			return state.forgets;
		},
		answerSelect: async ( result = { ok: true, persisted: true } ) => {
			pending.shift()?.( result );
			await flush();
		},
		finishRefresh: async () => {
			finish();
			await flush();
		},
	};
}

describe( 'ChainSync.abandon()', () => {
	it( 'a select queued behind an applied, still-refreshing select: nothing is erased (#1102)', async () => {
		const r = rig();
		const outcomes: Record< string, Outcome[] > = { a: [], b: [] };

		r.sync.request( { kind: 'select', record: record( 'a' ) }, ( outcome ) => {
			outcomes.a.push( outcome );

			return true;
		} );
		await r.answerSelect();
		// A is applied and its rates are being recalculated (`refresh()` has not returned).

		r.sync.request( { kind: 'select', record: record( 'b' ) }, ( outcome ) => {
			outcomes.b.push( outcome );
		} );

		// B never reached the server — it has no record of its own — and A is still in the form.
		expect( r.sync.abandon() ).toBe( false );

		await r.finishRefresh();

		expect( r.selects ).toEqual( [ 'a' ] );
		expect( r.forgets ).toBe( 0 );
		expect( outcomes.a ).toEqual( [ { status: 'applied' } ] );
		expect( outcomes.b ).toEqual( [ { status: 'superseded' } ] );
	} );

	it( 'a pick that arrives after the dropped one is still queued and sent', async () => {
		const r = rig();

		r.sync.request( { kind: 'select', record: record( 'a' ) }, () => true );
		await r.answerSelect();
		r.sync.request( { kind: 'select', record: record( 'b' ) }, () => undefined );
		r.sync.abandon();
		r.sync.request( { kind: 'select', record: record( 'c' ) }, () => undefined );

		await r.finishRefresh();
		await r.answerSelect();

		expect( r.selects ).toEqual( [ 'a', 'c' ] );
		expect( r.forgets ).toBe( 0 );
	} );

	it( 'a select queued behind an UNAPPLIED select: the one that may have saved a record is erased', async () => {
		const r = rig();

		r.sync.request( { kind: 'select', record: record( 'a' ) }, () => true );
		r.sync.request( { kind: 'select', record: record( 'b' ) }, () => undefined );

		expect( r.sync.abandon() ).toBe( true );

		await r.answerSelect();

		expect( r.selects ).toEqual( [ 'a' ] );
		expect( r.forgets ).toBe( 1 );
	} );

	it( 'an applied select with nothing queued behind it: nothing to abandon', async () => {
		const r = rig();

		r.sync.request( { kind: 'select', record: record( 'a' ) }, () => true );
		await r.answerSelect();

		expect( r.sync.abandon() ).toBe( false );

		await r.finishRefresh();

		expect( r.forgets ).toBe( 0 );
	} );

	it( 'a select queued behind a forget: the select is dropped and the forget still completes', async () => {
		const r = rig();

		r.sync.request( { kind: 'forget' }, () => undefined );
		r.sync.request( { kind: 'select', record: record( 'a' ) }, () => undefined );

		expect( r.sync.abandon() ).toBe( true );

		await flush();
		await r.finishRefresh();
		await flush();

		expect( r.selects ).toEqual( [] );
	} );
} );
