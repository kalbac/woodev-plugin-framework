/**
 * @jest-environment jsdom
 */

/**
 * Tests for woodev-error-reporter.js (#1081, spec D7).
 *
 * Covers the script-URL filter (only OUR scripts, authority case-insensitive), the stack parser (Chrome
 * and Firefox shapes; fails closed when the header cannot be matched, no function names), what a report carries (no message, no query/fragment, no
 * foreign frame), the pickup event reading three tokens only, and the per-page limits.
 *
 * @see woodev/assets/js/frontend/woodev-error-reporter.js
 */

'use strict';

const reporter = require( '../../woodev/assets/js/frontend/woodev-error-reporter' );

const BASE = 'https://shop.example.ru/wp-content/plugins/acme-delivery';
const OUR = BASE + '/assets/js/map.js';
const FOREIGN = 'https://shop.example.ru/wp-content/plugins/other-plugin/x.js';
const CONFIG = { endpoint: 'https://shop.example.ru/wp-json/woodev/v1/error-reporting/browser', nonce: 'n0nce', bases: [ BASE ] };

let requests;
let handle;

class FakeXhr {
	constructor() {
		this.headers = {};
		requests.push( this );
	}

	open( method, url ) {
		this.method = method;
		this.url = url;
	}

	setRequestHeader( name, value ) {
		this.headers[ name ] = value;
	}

	send( body ) {
		this.body = body;
	}
}

beforeEach( () => {
	requests = [];
	global.XMLHttpRequest = FakeXhr;
	handle = reporter.start( CONFIG );
} );

afterEach( () => {
	handle.stop();
} );

/** An ErrorEvent-like dispatch on window: jsdom's own ErrorEvent, so `addEventListener( 'error' )` sees it. */
function fireError( { error, filename = '', lineno = 0, colno = 0, message = 'ignored' } ) {
	window.dispatchEvent( new ErrorEvent( 'error', { error, filename, lineno, colno, message } ) );
}

function errorWithStack( name, message, stack ) {
	const error = new Error( message );
	error.name = name;
	error.stack = stack;

	return error;
}

function sentBodies() {
	return requests.map( ( request ) => JSON.parse( request.body ) );
}

describe( 'isOurs', () => {
	test( 'matches a URL under a base, ignoring scheme, query and fragment', () => {
		expect( reporter.isOurs( OUR + '?ver=1#x', [ BASE ] ) ).toBe( true );
		expect( reporter.isOurs( 'http://shop.example.ru/wp-content/plugins/acme-delivery/a.js', [ BASE ] ) ).toBe( true );
	} );

	test( 'compares the authority case-insensitively on both sides, and the path case-sensitively', () => {
		expect( reporter.isOurs( 'https://SHOP.Example.ru/wp-content/plugins/acme-delivery/a.js', [ BASE ] ) ).toBe( true );
		expect( reporter.isOurs( OUR, [ 'https://SHOP.example.RU/wp-content/plugins/acme-delivery' ] ) ).toBe( true );
		expect( reporter.isOurs( 'HTTP://Shop.EXAMPLE.ru/wp-content/plugins/acme-delivery/a.js', [ 'https://SHOP.example.ru/wp-content/plugins/acme-delivery/' ] ) ).toBe( true );
		expect( reporter.isOurs( 'https://shop.example.ru/wp-content/plugins/Acme-Delivery/a.js', [ BASE ] ) ).toBe( false );
	} );

	test( 'refuses a foreign script, a sibling directory with the same prefix, the base itself and junk', () => {
		expect( reporter.isOurs( FOREIGN, [ BASE ] ) ).toBe( false );
		expect( reporter.isOurs( BASE + '-evil/a.js', [ BASE ] ) ).toBe( false );
		expect( reporter.isOurs( BASE, [ BASE ] ) ).toBe( false );
		expect( reporter.isOurs( '', [ BASE ] ) ).toBe( false );
		expect( reporter.isOurs( undefined, [ BASE ] ) ).toBe( false );
	} );
} );

describe( 'parseStack', () => {
	test( 'parses Chrome frames and cuts the header, so a multi-line message never becomes a frame', () => {
		const message = 'Cannot read x\n    at secret (' + OUR + ':9:9)';
		const error = errorWithStack(
			'TypeError',
			message,
			'TypeError: ' + message + '\n    at draw (' + OUR + ':10:20)\n    at async run (' + OUR + '?ver=2:30:4)\n    at ' + FOREIGN + ':1:1'
		);

		expect( reporter.parseStack( error.stack, error ) ).toEqual( [
			{ url: OUR, line: 10, col: 20 },
			{ url: OUR, line: 30, col: 4 },
			{ url: FOREIGN, line: 1, col: 1 },
		] );
	} );

	test( 'never reads a function name', () => {
		const error = errorWithStack( 'Error', 'm', 'Error: m\n    at John.Smith (' + OUR + ':1:1)' );

		expect( JSON.stringify( reporter.parseStack( error.stack, error ) ) ).not.toMatch( /John|Smith|fn/ );
	} );

	test( 'parses a V8 stack whose message is empty (the header is the bare name)', () => {
		const error = errorWithStack( 'Error', '', 'Error\n    at f (' + OUR + ':3:4)' );

		expect( reporter.parseStack( error.stack, error ) ).toEqual( [ { url: OUR, line: 3, col: 4 } ] );
	} );

	test( 'parses Firefox / Safari frames: a header-less stack where every line is a frame', () => {
		const frames = reporter.parseStack(
			'draw@' + OUR + ':10:20\n@' + FOREIGN + ':2:3\nforEach@[native code]\n',
			{ name: 'Error', message: 'x' }
		);

		expect( frames ).toEqual( [
			{ url: OUR, line: 10, col: 20 },
			{ url: FOREIGN, line: 2, col: 3 },
		] );
	} );

	describe( 'fails closed when the V8 header cannot be matched and removed whole', () => {
		// The critic's repro: the original message poses as a frame, then the message is changed.
		const forged = 'provider failed\n    at John_Smith (' + OUR + ':9:9)';

		function forgedStack( mutate ) {
			const error = new Error( forged );
			const stack = 'Error: ' + forged + '\n    at real (' + OUR + ':1:1)';

			error.stack = stack;
			mutate( error );

			return { stack, error };
		}

		test( 'the message was changed after the stack was captured', () => {
			const { stack, error } = forgedStack( ( e ) => {
				e.message = 'Checkout failed';
			} );

			expect( reporter.parseStack( stack, error ) ).toEqual( [] );
		} );

		test( 'the message was deleted', () => {
			const { stack, error } = forgedStack( ( e ) => {
				e.message = '';
			} );

			expect( reporter.parseStack( stack, error ) ).toEqual( [] );
		} );

		test( 'the name was changed', () => {
			const { stack, error } = forgedStack( ( e ) => {
				e.name = 'CheckoutError';
			} );

			expect( reporter.parseStack( stack, error ) ).toEqual( [] );
		} );

		test( 'a stack with no header and a line that is not a frame is not parsed', () => {
			expect( reporter.parseStack( 'free text\n    at f (' + OUR + ':1:1)', { name: 'Error', message: 'm' } ) ).toEqual( [] );
			expect( reporter.parseStack( 'note\ndraw@' + OUR + ':10:20', { name: 'Error', message: 'm' } ) ).toEqual( [] );
		} );

		test( 'a thing without a string name and message is not parsed', () => {
			expect( reporter.parseStack( 'Error: m\n    at f (' + OUR + ':1:1)', {} ) ).toEqual( [] );
			expect( reporter.parseStack( 'Error: m\n    at f (' + OUR + ':1:1)', null ) ).toEqual( [] );
		} );

		test( 'end to end: the forged frame is not reported, the ErrorEvent location is', () => {
			const { error } = forgedStack( ( e ) => {
				e.message = 'Checkout failed';
			} );

			fireError( { error, filename: OUR + '?ver=1', lineno: 7, colno: 8 } );

			expect( sentBodies() ).toEqual( [ { source: 'error', type: 'Error', frames: [ { url: OUR, line: 7, col: 8 } ] } ] );
			expect( requests[ 0 ].body ).not.toMatch( /John|Smith|9:9/ );
		} );

		test( 'end to end: a rejection with a mutated header is dropped (no location to fall back to)', () => {
			const { error } = forgedStack( ( e ) => {
				e.message = 'Checkout failed';
			} );
			const event = new Event( 'unhandledrejection' );

			event.reason = error;
			window.dispatchEvent( event );

			expect( requests ).toHaveLength( 0 );
		} );
	} );

	test( 'a non-string stack gives no frames', () => {
		expect( reporter.parseStack( undefined, null ) ).toEqual( [] );
	} );
} );

describe( 'window errors', () => {
	test( 'reports an error with a frame in our script: the name and the frames, nothing else', () => {
		const error = errorWithStack( 'TypeError', 'John Smith, +7 900 123-45-67', 'TypeError: John Smith, +7 900 123-45-67\n    at draw (' + OUR + '?ver=3#frag:10:20)' );

		fireError( { error, message: 'John Smith, +7 900 123-45-67' } );

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].method ).toBe( 'POST' );
		expect( requests[ 0 ].url ).toBe( CONFIG.endpoint );
		expect( requests[ 0 ].headers[ 'X-WP-Nonce' ] ).toBe( 'n0nce' );
		expect( requests[ 0 ].body ).not.toMatch( /John|900|Smith/ );
		expect( sentBodies()[ 0 ] ).toEqual( {
			source: 'error',
			type: 'TypeError',
			frames: [ { url: OUR, line: 10, col: 20 } ],
		} );
	} );

	test( 'a URL carries neither query nor fragment', () => {
		fireError( { error: errorWithStack( 'Error', 'm', 'Error: m\n    at f (' + OUR + '?token=abc#x:1:2)' ) } );

		expect( requests[ 0 ].body ).not.toMatch( /token|abc|#/ );
	} );

	test( 'keeps only the frames of our scripts', () => {
		fireError( { error: errorWithStack( 'Error', 'm', 'Error: m\n    at a (' + FOREIGN + ':1:1)\n    at b (' + OUR + ':2:2)' ) } );

		expect( sentBodies()[ 0 ].frames ).toEqual( [ { url: OUR, line: 2, col: 2 } ] );
	} );

	test( 'ignores an error that touches none of our scripts', () => {
		fireError( { error: errorWithStack( 'Error', 'm', 'Error: m\n    at a (' + FOREIGN + ':1:1)' ), filename: FOREIGN, lineno: 1, colno: 1 } );
		fireError( { error: undefined, message: 'Script error.' } );

		expect( requests ).toHaveLength( 0 );
	} );

	test( 'falls back to the event file:line:col when there is no usable stack', () => {
		fireError( { error: 'a thrown string', filename: OUR + '?ver=1', lineno: 7, colno: 8 } );

		expect( sentBodies()[ 0 ] ).toEqual( { source: 'error', type: 'Error', frames: [ { url: OUR, line: 7, col: 8 } ] } );
	} );

	test( 'a hostile error name is replaced, not sent', () => {
		fireError( { error: errorWithStack( 'Bad name: secret@example.com', 'm', 'Bad name: secret@example.com: m\n    at f (' + OUR + ':1:1)' ) } );

		expect( sentBodies()[ 0 ].type ).toBe( 'Error' );
		expect( requests[ 0 ].body ).not.toMatch( /secret|example\.com/ );
	} );

	test( 'reports an unhandled rejection whose reason is an error from our script', () => {
		const reason = errorWithStack( 'RangeError', 'm', 'RangeError: m\n    at f (' + OUR + ':3:4)' );
		const event = new Event( 'unhandledrejection' );
		event.reason = reason;
		window.dispatchEvent( event );

		expect( sentBodies()[ 0 ] ).toMatchObject( { source: 'unhandledrejection', type: 'RangeError' } );
	} );

	test( 'ignores a rejection with a non-error reason', () => {
		const event = new Event( 'unhandledrejection' );
		event.reason = 'string reason';
		window.dispatchEvent( event );

		expect( requests ).toHaveLength( 0 );
	} );
} );

describe( 'woodev_pickup_error', () => {
	function firePickup( detail ) {
		document.body.dispatchEvent( new CustomEvent( 'woodev_pickup_error', { detail, bubbles: true } ) );
	}

	test( 'sends pluginId, fieldId and code — never the message', () => {
		firePickup( { pluginId: 'acme-delivery', fieldId: 'pickup_point', code: 'map_script', message: 'Иван Иванов, ул. Ленина 1' } );

		expect( sentBodies() ).toEqual( [ { source: 'pickup', pluginId: 'acme-delivery', fieldId: 'pickup_point', code: 'map_script' } ] );
		expect( requests[ 0 ].body ).not.toMatch( /Иван|Ленина|message/ );
	} );

	test( 'ignores an event whose tokens are missing or malformed', () => {
		firePickup( { fieldId: 'pickup_point', code: 'map_script' } );
		firePickup( { pluginId: 'acme-delivery', fieldId: 'pickup point', code: 'map_script' } );
		firePickup( { pluginId: 'acme-delivery', fieldId: 'pickup_point', code: 'x'.repeat( 65 ) } );
		firePickup( undefined );

		expect( requests ).toHaveLength( 0 );
	} );
} );

describe( 'limits', () => {
	test( 'one report per signature per page view', () => {
		const error = errorWithStack( 'Error', 'm', 'Error: m\n    at f (' + OUR + ':1:1)' );

		fireError( { error } );
		fireError( { error } );

		expect( requests ).toHaveLength( 1 );
	} );

	test( 'at most five reports per page view', () => {
		for ( let line = 1; line <= 9; line++ ) {
			fireError( { error: errorWithStack( 'Error', 'm', 'Error: m\n    at f (' + OUR + ':' + line + ':1)' ) } );
		}

		expect( requests ).toHaveLength( 5 );
	} );

	test( 'a report over the size budget loses its outermost frames first', () => {
		const long = BASE + '/' + 'a'.repeat( 400 ) + '.js';
		const lines = [];

		for ( let i = 1; i <= 30; i++ ) {
			lines.push( '    at f' + i + ' (' + long + ':' + i + ':1)' );
		}

		fireError( { error: errorWithStack( 'Error', 'm', 'Error: m\n' + lines.join( '\n' ) ) } );

		expect( requests[ 0 ].body.length ).toBeLessThanOrEqual( 7500 );
		expect( sentBodies()[ 0 ].frames[ 0 ] ).toEqual( { url: long, line: 1, col: 1 } );
	} );

	test( 'start() refuses an unusable config', () => {
		expect( reporter.start( null ) ).toBeNull();
		expect( reporter.start( { endpoint: 'x', nonce: 'n', bases: [] } ) ).toBeNull();
		expect( reporter.start( { nonce: 'n', bases: [ BASE ] } ) ).toBeNull();
	} );

	test( 'a failing transport never throws into the page', () => {
		global.XMLHttpRequest = class {
			open() {
				throw new Error( 'blocked' );
			}
		};

		expect( () => fireError( { error: errorWithStack( 'Error', 'm', 'Error: m\n    at f (' + OUR + ':1:1)' ) } ) ).not.toThrow();
	} );
} );
