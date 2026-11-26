/**
 * The «Этикетка» button of a marketplace posting on the order screens (the order metabox, either order storage).
 *
 * It asks nothing the «Заказы маркетплейсов» table does not: the `label` row action and the activity read of the table's own records
 * routes (`data-url`), with the REST nonce. The table answers with the ready labels, or queues the label and says so; then the button
 * waits on the activity read until the marketplace has made it and asks for the file again. Nothing is asked of the marketplace
 * more than once per click. A file is saved at once; a page to print (`download.open`) gets a link beside the button, which opens
 * it in a new tab. Plain DOM calls only (`textContent`, never `innerHTML`).
 */
( function () {
	'use strict';

	var ACTION = 'label';
	var POLL_MS = 3000;
	var POLL_LIMIT = 40; // About two minutes; after that the merchant is told to come back.

	/**
	 * One button and the status line beside it.
	 *
	 * @param {HTMLElement} button The button.
	 */
	function attach( button ) {
		var status = button.parentNode.querySelector( '.woodev-mp-label__status' );
		var base = button.getAttribute( 'data-url' );
		var context = { account_id: button.getAttribute( 'data-account' ) };
		var row = button.getAttribute( 'data-row' );
		var offTitle = button.getAttribute( 'title' ) || '';
		var running = false;

		function say( text ) {
			status.textContent = text;
		}

		function text( name ) {
			return button.getAttribute( 'data-' + name );
		}

		function setOff( off, reason ) {
			button.classList.toggle( 'disabled', off );
			button.setAttribute( 'aria-disabled', off ? 'true' : 'false' );

			if ( off && reason ) {
				button.setAttribute( 'title', reason );
			} else if ( offTitle ) {
				button.setAttribute( 'title', offTitle );
			} else {
				button.removeAttribute( 'title' );
			}
		}

		function post( path, body ) {
			return fetch( base + path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': text( 'nonce' ) },
				body: JSON.stringify( body )
			} ).then( function ( response ) {
				return response.json().then( function ( answer ) {
					return { ok: response.ok, answer: answer };
				} );
			} );
		}

		// What the table says about the posting now: { blocked: whether the label is being made, reason, failure }.
		function activity() {
			return post( '/activity', { context: context, ids: [ row ] } ).then( function ( reply ) {
				var item = reply.ok && reply.answer.rows && reply.answer.rows[ 0 ] ? reply.answer.rows[ 0 ].activity : null;
				var processes = item && item.processes ? item.processes : [];
				var failed = processes.filter( function ( process ) {
					return 'failed' === process.state;
				} )[ 0 ];

				return {
					blocked: !! item && item.blocked_actions.indexOf( ACTION ) !== -1,
					reason: item ? item.reason : '',
					failure: failed ? failed.text : ''
				};
			} );
		}

		function request() {
			return post( '/actions/' + ACTION, { context: context, ids: [ row ], payload: {}, confirmed: true } );
		}

		// A path on this site or an address on one of the origins the server named as the site's own.
		function isOwnUrl( download ) {
			return 0 === download.url.indexOf( '/' ) ? 0 !== download.url.indexOf( '//' ) : ( download.origins || [] ).some( function ( origin ) {
				return 0 === download.url.indexOf( origin + '/' );
			} );
		}

		function download( file ) {
			var link = document.createElement( 'a' );

			if ( ! isOwnUrl( file ) ) {
				say( text( 'failed' ) );
				return;
			}

			link.href = file.url;

			// The merchant's own click opens the page: a tab asked for after a wait is blocked by the browser.
			if ( file.open ) {
				link.target = '_blank';
				link.rel = 'noopener';
				link.textContent = text( 'open' );
				status.appendChild( link );
				return;
			}

			link.setAttribute( 'download', file.filename || '' );
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
		}

		function failure( reply ) {
			say( reply.answer && reply.answer.message ? reply.answer.message : text( 'failed' ) );
		}

		function finish() {
			running = false;
			setOff( false );
		}

		// The answer of the table to a request: the labels to hand over, the label being made, or a sentence for the merchant.
		function handle( reply, afterQueue ) {
			if ( ! reply.ok ) {
				failure( reply );
				finish();
			} else if ( reply.answer.download ) {
				say( '' );
				download( reply.answer.download );
				finish();
			} else {
				afterQueue();
			}
		}

		function wait( tries ) {
			say( text( 'preparing' ) );
			setOff( true, text( 'preparing' ) );
			running = true;

			window.setTimeout( function () {
				activity().then( function ( state ) {
					if ( state.blocked && tries < POLL_LIMIT ) {
						wait( tries + 1 );
					} else if ( state.blocked ) {
						say( text( 'waiting' ) );
						finish();
					} else if ( state.failure ) {
						say( state.failure );
						finish();
					} else {
						request().then( function ( reply ) {
							handle( reply, function () {
								say( text( 'waiting' ) );
								finish();
							} );
						} ).catch( fail );
					}
				} ).catch( fail );
			}, POLL_MS );
		}

		function fail() {
			say( text( 'failed' ) );
			finish();
		}

		button.addEventListener( 'click', function () {
			if ( running || 'true' === button.getAttribute( 'aria-disabled' ) ) {
				return;
			}

			running = true;
			say( '' );
			request().then( function ( reply ) {
				handle( reply, function () {
					wait( 0 );
				} );
			} ).catch( fail );
		} );

		// A label that is already being made when the page opens: off, with the table's own sentence, until it is ready.
		if ( 'false' === button.getAttribute( 'aria-disabled' ) ) {
			activity().then( function ( state ) {
				if ( state.blocked ) {
					wait( 0 );
				}
			} ).catch( function () {} );
		}
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.woodev-mp-label' ), attach );
}() );
