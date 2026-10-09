/**
 * The client-side wait for a carrier document (#1191): asks again on «pending», honours `retryAfter`, stops at the
 * cap, and can be cancelled. Fake timers throughout — no test waits in real time.
 *
 * @see src/shipping-orders-page/document-poll.ts
 */

import {
	POLL_CANCELLED_CODE,
	POLL_MAX_DELAY_MS,
	POLL_MAX_WAIT_MS,
	POLL_MIN_DELAY_MS,
	POLL_TIMEOUT_CODE,
	isPollCancelled,
	pollUntilReady,
} from '../../src/shipping-orders-page/document-poll';

type Answer = { kind: 'pending'; message: string; retryAfter: number } | { kind: 'file'; name: string };

const pending = ( retryAfter: number ): Answer => ( { kind: 'pending', message: '', retryAfter } );
const file: Answer = { kind: 'file', name: 'a.pdf' };

beforeEach( () => {
	jest.useFakeTimers();
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'pollUntilReady', () => {
	test( 'a document that is ready at once is returned without waiting or announcing anything', async () => {
		const fetchOnce = jest.fn().mockResolvedValue( file );
		const onPending = jest.fn();

		await expect( pollUntilReady( fetchOnce, { onPending } ) ).resolves.toBe( file );
		expect( fetchOnce ).toHaveBeenCalledTimes( 1 );
		expect( onPending ).not.toHaveBeenCalled();
	} );

	test( 'asks again after each retry_after and resolves with the first ready answer', async () => {
		const fetchOnce = jest.fn().mockResolvedValueOnce( pending( 3 ) ).mockResolvedValueOnce( pending( 5 ) ).mockResolvedValueOnce( file );
		const onPending = jest.fn();
		const result = pollUntilReady( fetchOnce, { onPending } );

		await jest.advanceTimersByTimeAsync( 0 );
		expect( fetchOnce ).toHaveBeenCalledTimes( 1 );
		expect( onPending ).toHaveBeenLastCalledWith( 1 );

		await jest.advanceTimersByTimeAsync( 2999 );
		expect( fetchOnce ).toHaveBeenCalledTimes( 1 );
		await jest.advanceTimersByTimeAsync( 1 );
		expect( fetchOnce ).toHaveBeenCalledTimes( 2 );
		expect( onPending ).toHaveBeenLastCalledWith( 2 );

		await jest.advanceTimersByTimeAsync( 5000 );
		await expect( result ).resolves.toBe( file );
		expect( fetchOnce ).toHaveBeenCalledTimes( 3 );
	} );

	test( 'a silly retry_after is kept inside the 1–10 s window', async () => {
		const fetchOnce = jest.fn().mockResolvedValueOnce( pending( 0 ) ).mockResolvedValueOnce( pending( 600 ) ).mockResolvedValueOnce( file );
		const result = pollUntilReady( fetchOnce );

		await jest.advanceTimersByTimeAsync( POLL_MIN_DELAY_MS - 1 );
		expect( fetchOnce ).toHaveBeenCalledTimes( 1 );
		await jest.advanceTimersByTimeAsync( 1 );
		expect( fetchOnce ).toHaveBeenCalledTimes( 2 );

		await jest.advanceTimersByTimeAsync( POLL_MAX_DELAY_MS - 1 );
		expect( fetchOnce ).toHaveBeenCalledTimes( 2 );
		await jest.advanceTimersByTimeAsync( 1 );
		await expect( result ).resolves.toBe( file );
	} );

	test( 'gives up once the next wait would pass the cap, with a sentence for the merchant, and stops asking', async () => {
		const fetchOnce = jest.fn().mockResolvedValue( pending( 10 ) );
		const result = pollUntilReady( fetchOnce );
		const settled = result.catch( ( error ) => error );

		await jest.advanceTimersByTimeAsync( POLL_MAX_WAIT_MS + 60000 );

		const error = await settled;

		expect( error.code ).toBe( POLL_TIMEOUT_CODE );
		expect( error.message ).toBe( 'Документ всё ещё формируется. Попробуйте ещё раз через минуту.' );
		// Attempts at 0, 10 and 20 s; the deadline is absolute, so nothing is asked at 30 s or after.
		expect( fetchOnce ).toHaveBeenCalledTimes( 3 );
	} );

	test( 'a rejection of the request itself is passed through untouched (the server\'s own message)', async () => {
		const failure = { code: 'woodev_document_failed', message: 'Не удалось получить документ у перевозчика.' };
		const fetchOnce = jest.fn().mockResolvedValueOnce( pending( 3 ) ).mockRejectedValueOnce( failure );
		const settled = pollUntilReady( fetchOnce ).catch( ( error ) => error );

		await jest.advanceTimersByTimeAsync( 3000 );

		expect( await settled ).toBe( failure );
	} );
} );

describe( 'cancelling the wait', () => {
	test( 'aborting during the pause stops the loop at once and never asks again', async () => {
		const controller = new AbortController();
		const fetchOnce = jest.fn().mockResolvedValue( pending( 3 ) );
		const settled = pollUntilReady( fetchOnce, { signal: controller.signal } ).catch( ( error ) => error );

		await jest.advanceTimersByTimeAsync( 1000 );
		controller.abort();

		const error = await settled;

		expect( isPollCancelled( error ) ).toBe( true );
		expect( error.code ).toBe( POLL_CANCELLED_CODE );
		await jest.advanceTimersByTimeAsync( 60000 );
		expect( fetchOnce ).toHaveBeenCalledTimes( 1 );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	test( 'aborting while a request is in flight drops its answer: a document that arrives late is not returned', async () => {
		const controller = new AbortController();
		let arrive: ( answer: Answer ) => void = () => {};
		const fetchOnce = jest.fn(
			() =>
				new Promise<Answer>( ( resolve ) => {
					arrive = resolve;
				} )
		);
		const settled = pollUntilReady( fetchOnce, { signal: controller.signal } ).catch( ( error ) => error );

		controller.abort();
		arrive( file );

		expect( isPollCancelled( await settled ) ).toBe( true );
	} );

	test( 'an already aborted signal never asks at all', async () => {
		const controller = new AbortController();
		const fetchOnce = jest.fn().mockResolvedValue( file );

		controller.abort();

		await expect( pollUntilReady( fetchOnce, { signal: controller.signal } ) ).rejects.toMatchObject( { code: POLL_CANCELLED_CODE } );
		expect( fetchOnce ).not.toHaveBeenCalled();
	} );

	test( 'isPollCancelled recognises only the cancel', () => {
		expect( isPollCancelled( { code: POLL_CANCELLED_CODE } ) ).toBe( true );
		expect( isPollCancelled( { code: POLL_TIMEOUT_CODE } ) ).toBe( false );
		expect( isPollCancelled( null ) ).toBe( false );
		expect( isPollCancelled( {} ) ).toBe( false );
	} );
} );

describe( 'the cap bounds a request in flight (#1191 fix round 1)', () => {
	test( 'a request that never answers is given up at the cap: the promise settles with the timeout and nothing lingers', async () => {
		let signalSeen: AbortSignal | undefined;
		const fetchOnce = jest.fn(
			( signal: AbortSignal ) =>
				new Promise<Answer>( () => {
					signalSeen = signal;
				} )
		);
		const settled = pollUntilReady( fetchOnce ).catch( ( error ) => error );
		let done = false;

		settled.then( () => {
			done = true;
		} );

		await jest.advanceTimersByTimeAsync( POLL_MAX_WAIT_MS - 1 );
		expect( done ).toBe( false );
		await jest.advanceTimersByTimeAsync( 1 );

		const error = await settled;

		expect( error.code ).toBe( POLL_TIMEOUT_CODE );
		// The transport was told to stop, and no timer is left behind.
		expect( signalSeen?.aborted ).toBe( true );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	test( 'a document that arrives after the cap is dropped, not returned', async () => {
		let arrive: ( answer: Answer ) => void = () => {};
		const fetchOnce = jest.fn(
			() =>
				new Promise<Answer>( ( resolve ) => {
					arrive = resolve;
				} )
		);
		const settled = pollUntilReady( fetchOnce ).catch( ( error ) => error );

		await jest.advanceTimersByTimeAsync( POLL_MAX_WAIT_MS + 60000 );
		arrive( file );

		expect( ( await settled ).code ).toBe( POLL_TIMEOUT_CODE );
	} );

	test( 'a late poll that starts near the deadline and hangs is cut at the deadline, not 30 s later', async () => {
		const fetchOnce = jest
			.fn()
			.mockResolvedValueOnce( pending( 10 ) )
			.mockResolvedValueOnce( pending( 10 ) )
			.mockImplementationOnce( () => new Promise<Answer>( () => {} ) );
		const settled = pollUntilReady( fetchOnce ).catch( ( error ) => error );

		// Third request goes out at 20 s and hangs; the cap is still 30 s from the START.
		await jest.advanceTimersByTimeAsync( 20000 );
		expect( fetchOnce ).toHaveBeenCalledTimes( 3 );
		await jest.advanceTimersByTimeAsync( 10000 );

		expect( ( await settled ).code ).toBe( POLL_TIMEOUT_CODE );
	} );

	test( 'the request is handed a signal that aborts when the caller cancels, and the cancel settles at once', async () => {
		const controller = new AbortController();
		let signalSeen: AbortSignal | undefined;
		const fetchOnce = jest.fn(
			( signal: AbortSignal ) =>
				new Promise<Answer>( () => {
					signalSeen = signal;
				} )
		);
		const settled = pollUntilReady( fetchOnce, { signal: controller.signal } ).catch( ( error ) => error );

		await jest.advanceTimersByTimeAsync( 1000 );
		controller.abort();

		expect( isPollCancelled( await settled ) ).toBe( true );
		expect( signalSeen?.aborted ).toBe( true );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );
} );
