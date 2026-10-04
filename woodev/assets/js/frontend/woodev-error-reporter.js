/**
 * Woodev Error Reporter — the browser half of the opt-in error reporter (#1081).
 *
 * Plain ES5, no build step: enqueued directly in the `<head>` by
 * `Woodev\Framework\Error_Reporting\Browser_Script` — and ONLY while reporting is active (the
 * merchant consented and a receiver is configured). It never talks to the receiver: it POSTs to
 * a public REST route of the SAME site, where PHP re-validates, anonymises and queues the event
 * next to the PHP ones (docs-internal/specs/2026-10-04-error-reporter-design.md, D7).
 *
 * WHAT IT REPORTS, AND NOTHING ELSE
 *   - an uncaught error or unhandled rejection whose stack has a frame in OUR scripts (a URL under
 *     one of `config.bases`, the asset base URL of every registered Woodev plugin);
 *   - the `woodev_pickup_error` event: only `pluginId`, `fieldId` and `code`.
 * An error that touches none of our scripts is ignored here and again on the server.
 *
 * WHAT IT NEVER SENDS: `error.message`, the raw `stack` string, a function name, a URL's query or
 * fragment, a frame of a foreign script, the page address. The stack is parsed into frames in the
 * browser; Chrome's header (`Name: message`, possibly several lines) must be matched against the
 * error's CURRENT name and message and removed whole — if it cannot be, the stack is not parsed at all
 * (fail closed) and only the `ErrorEvent` location is used. This is defence in depth: the server
 * exports only a frame URL that is a real script file of a registered plugin, plus numbers.
 *
 * It listens with `addEventListener` and never replaces `window.onerror`, sends at most
 * {@link MAX_REPORTS} reports per page view and one per signature, and swallows its own failures:
 * reporting must never break the page it reports on.
 *
 * UMD-ish dual export (matches woodev-modal.js):
 *   - Browser: starts itself when `window.woodevErrorReporting` is present
 *   - CommonJS: module.exports = { start, parseStack, ourFrames, isOurs, stripUrl }  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	/** @type {number} reports sent per page view. */
	var MAX_REPORTS = 5;

	/** @type {number} frames sent, innermost first. */
	var MAX_FRAMES = 30;

	/** @type {number} body size the client keeps under — the server refuses more than 8192 bytes. */
	var MAX_BODY = 7500;

	var TOKEN = /^[A-Za-z0-9_.-]{1,64}$/;
	var ERROR_NAME = /^[A-Za-z_$][\w$]{0,63}$/;

	/** Chrome / V8: `    at fn (url:1:2)` or `    at url:1:2`. */
	var V8_FRAME = /^\s*at (?:(.+?) \()?(.+?):(\d+):(\d+)\)?\s*$/;

	/** Firefox / Safari: `fn@url:1:2` or `@url:1:2`. */
	var GECKO_FRAME = /^(.*?)@(.+?):(\d+)(?::(\d+))?\s*$/;

	/**
	 * A URL without its query string and fragment.
	 *
	 * @param {*} url
	 * @returns {string}
	 */
	function stripUrl( url ) {
		return String( url ).replace( /[?#][\s\S]*$/, '' );
	}

	/**
	 * `//host/path` — scheme-less, no trailing slash, the authority lowercased (the path is case-sensitive),
	 * so `http` and `https` spellings of one site, and `SHOP.example.ru` and `shop.example.ru`, compare equal.
	 * The server normalises the same way (`Plugin_Scope::normalize_url()`).
	 *
	 * @param {*} url
	 * @returns {string}
	 */
	function schemeless( url ) {
		var bare = stripUrl( url ).replace( /^https?:/i, '' ).replace( /\/+$/, '' );
		var match = /^\/\/([^\/]*)([\s\S]*)$/.exec( bare );

		return match ? '//' + match[ 1 ].toLowerCase() + match[ 2 ] : bare;
	}

	/**
	 * Whether a script URL lies under one of the plugin base URLs.
	 *
	 * @param {*}        url
	 * @param {string[]} bases
	 * @returns {boolean}
	 */
	function isOurs( url, bases ) {
		if ( 'string' !== typeof url || '' === url ) {
			return false;
		}

		var candidate = schemeless( url );

		for ( var i = 0; i < bases.length; i++ ) {
			var root = schemeless( bases[ i ] ) + '/';

			if ( 0 === candidate.indexOf( root ) && candidate.length > root.length ) {
				return true;
			}
		}

		return false;
	}

	/** Safari: `fn@[native code]`, or a bare `[native code]`. */
	var NATIVE_FRAME = /^(?:.*@)?\[native code\]\s*$/;

	/**
	 * V8's `Error.prototype.toString()` shape: `Name: message`, or just one of them when the other is empty.
	 *
	 * @param {string} name
	 * @param {string} message
	 * @returns {string}
	 */
	function v8Header( name, message ) {
		if ( '' === name ) {
			return message;
		}

		return '' === message ? name : name + ': ' + message;
	}

	/**
	 * A frame line's location: group 2 is the URL, 3 the line, 4 the column — the same in both shapes.
	 *
	 * @param {string} line
	 * @param {RegExp} shape
	 * @returns {?{url: string, line: number, col: number}}
	 */
	function frameOf( line, shape ) {
		var match = shape.exec( line );

		if ( ! match ) {
			return null;
		}

		return {
			url: stripUrl( match[ 2 ] ),
			line: parseInt( match[ 3 ], 10 ) || 0,
			col: parseInt( match[ 4 ], 10 ) || 0,
		};
	}

	/**
	 * Parses an `Error.stack` string into frames, innermost first. Fails CLOSED.
	 *
	 * A string a script can still change is not evidence of where code ran, so the stack is parsed only
	 * when its shape proves that no free text is in it:
	 *   - V8 (Chrome, Edge): the stack must open with `Name: message` built from the error's CURRENT name
	 *     and message, and that header must end exactly at a line break — it is then removed whole. A message
	 *     changed or deleted after the stack was captured, a changed name, a header that cannot be matched:
	 *     nothing is parsed (a multi-line message cannot pose as frames);
	 *   - Firefox / Safari: there is no header, so EVERY line must be a frame (or Safari's native marker).
	 * Otherwise `[]` — the caller falls back to the `ErrorEvent` location, or drops the report.
	 * The function name of a frame is never read.
	 *
	 * @param {*}      stack
	 * @param {Object} error The error the stack came from — its CURRENT name and message are used to find the V8 header.
	 * @returns {Array.<{url: string, line: number, col: number}>}
	 */
	function parseStack( stack, error ) {
		if ( 'string' !== typeof stack || ! error || 'string' !== typeof error.name || 'string' !== typeof error.message ) {
			return [];
		}

		var header = v8Header( error.name, error.message );
		var lines;
		var gecko = false;

		if ( '' !== header && 0 === stack.indexOf( header ) ) {
			var rest = stack.slice( header.length );

			if ( '' !== rest && '\n' !== rest.charAt( 0 ) ) {
				return []; // The header does not end where the message ends: something else is in there.
			}

			lines = rest.split( '\n' );
		} else {
			gecko = true;
			lines = stack.split( '\n' );

			for ( var i = 0; i < lines.length; i++ ) {
				if ( '' !== lines[ i ].trim() && ! GECKO_FRAME.test( lines[ i ] ) && ! NATIVE_FRAME.test( lines[ i ] ) ) {
					return []; // Neither a V8 stack with our header nor a header-less stack of frames.
				}
			}
		}

		var frames = [];

		lines.forEach( function( line ) {
			var frame = frameOf( line, gecko ? GECKO_FRAME : V8_FRAME );

			if ( frame ) {
				frames.push( frame );
			}
		} );

		return frames;
	}

	/**
	 * The frames of OUR scripts only, at most {@link MAX_FRAMES}.
	 *
	 * @param {Array}    frames
	 * @param {string[]} bases
	 * @returns {Array}
	 */
	function ourFrames( frames, bases ) {
		return frames.filter( function( frame ) {
			return isOurs( frame.url, bases ) && frame.url.length <= 500;
		} ).slice( 0, MAX_FRAMES );
	}

	/**
	 * The error's name when it is a plain identifier, else `Error`. Never the message.
	 *
	 * @param {*} error
	 * @returns {string}
	 */
	function errorName( error ) {
		return error && 'string' === typeof error.name && ERROR_NAME.test( error.name ) ? error.name : 'Error';
	}

	/**
	 * @param {*} value
	 * @returns {boolean}
	 */
	function isToken( value ) {
		return 'string' === typeof value && TOKEN.test( value );
	}

	/**
	 * Starts listening. Returns `null` when the config is unusable, otherwise a handle.
	 *
	 * @param {{endpoint: string, nonce: string, bases: string[]}} config
	 * @returns {?{stop: Function}}
	 */
	function start( config ) {
		if ( ! config || 'string' !== typeof config.endpoint || '' === config.endpoint || ! config.bases || ! config.bases.length ) {
			return null;
		}

		var bases = config.bases;
		var sent = 0;
		var seen = {};

		/**
		 * @param {Object} payload
		 * @returns {void}
		 */
		function submit( payload ) {
			try {
				var signature = JSON.stringify( payload );

				if ( sent >= MAX_REPORTS || seen[ signature ] ) {
					return;
				}

				seen[ signature ] = true;
				sent++;

				var body = signature;

				// Outermost frames go first when the report is too big.
				while ( body.length > MAX_BODY && payload.frames && payload.frames.length > 1 ) {
					payload.frames.pop();
					body = JSON.stringify( payload );
				}

				var xhr = new XMLHttpRequest();

				xhr.open( 'POST', config.endpoint, true );
				xhr.setRequestHeader( 'Content-Type', 'application/json' );
				xhr.setRequestHeader( 'X-WP-Nonce', String( config.nonce || '' ) );
				xhr.send( body );
			} catch ( e ) {
				// Reporting must never break the page.
			}
		}

		/**
		 * @param {ErrorEvent} event
		 * @returns {void}
		 */
		function onError( event ) {
			try {
				var error = event && event.error;
				var frames = ourFrames( parseStack( error && error.stack, error ), bases );

				// No trustworthy stack (a thrown string, an unmatched header, an old engine): use the event's own file:line:col.
				if ( ! frames.length && event && isOurs( event.filename, bases ) ) {
					frames = [ {
						url: stripUrl( event.filename ),
						line: parseInt( event.lineno, 10 ) || 0,
						col: parseInt( event.colno, 10 ) || 0,
					} ];
				}

				if ( frames.length ) {
					submit( { source: 'error', type: errorName( error ), frames: frames } );
				}
			} catch ( e ) {
				// Reporting must never break the page.
			}
		}

		/**
		 * @param {PromiseRejectionEvent} event
		 * @returns {void}
		 */
		function onRejection( event ) {
			try {
				var reason = event && event.reason;

				if ( ! reason || 'object' !== typeof reason ) {
					return;
				}

				var frames = ourFrames( parseStack( reason.stack, reason ), bases );

				if ( frames.length ) {
					submit( { source: 'unhandledrejection', type: errorName( reason ), frames: frames } );
				}
			} catch ( e ) {
				// Reporting must never break the page.
			}
		}

		/**
		 * `woodev_pickup_error` `{ fieldId, code, message, pluginId }` — three tokens are read, `message` never is.
		 *
		 * @param {CustomEvent} event
		 * @returns {void}
		 */
		function onPickupError( event ) {
			try {
				var detail = event && event.detail;

				if ( detail && isToken( detail.pluginId ) && isToken( detail.fieldId ) && isToken( detail.code ) ) {
					submit( { source: 'pickup', pluginId: detail.pluginId, fieldId: detail.fieldId, code: detail.code } );
				}
			} catch ( e ) {
				// Reporting must never break the page.
			}
		}

		window.addEventListener( 'error', onError );
		window.addEventListener( 'unhandledrejection', onRejection );
		document.addEventListener( 'woodev_pickup_error', onPickupError );

		return {
			stop: function() {
				window.removeEventListener( 'error', onError );
				window.removeEventListener( 'unhandledrejection', onRejection );
				document.removeEventListener( 'woodev_pickup_error', onPickupError );
			},
		};
	}

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	if ( 'undefined' !== typeof window && window.woodevErrorReporting ) {
		start( window.woodevErrorReporting );
	}

	if ( 'undefined' !== typeof module && module.exports ) {
		module.exports = { start: start, parseStack: parseStack, ourFrames: ourFrames, isOurs: isOurs, stripUrl: stripUrl };
	}
}() );
