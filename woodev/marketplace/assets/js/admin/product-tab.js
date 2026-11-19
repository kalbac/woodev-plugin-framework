/**
 * The marketplace tab of the product edit screen: the buttons of a block, the dialogs (the state badge's problems, the help), the article's
 * «изменить» and the account select.
 *
 * Each button posts to `admin-ajax.php` with its own nonce and shows the answer in the line beside it. «Архивировать» and «Снять связь» ask twice:
 * the first click shows the question and arms the button, a second click within five seconds does it.
 */
( function ( $ ) {
	'use strict';

	var settings   = window.woodevMarketplaceProduct || { texts: {} };
	var CONFIRM_MS = 5000;
	var dialog     = null;

	/**
	 * Paints the state badge of a block with the state the server answered. A badge that carries problems keeps its orange: the problems are
	 * those of the page as it was loaded, and the merchant reloads the page to see them again.
	 *
	 * @param {jQuery} $block Block of one account.
	 * @param {Object|null} badge `{ label, tone }`.
	 */
	function paintBadge( $block, badge ) {
		if ( ! badge ) {
			return;
		}

		var $badge = $block.find( '.woodev-mp-block__title .woodev-mp-badge' ).first();

		if ( ! $badge.hasClass( 'woodev-mp-badge--problem' ) ) {
			$badge.attr( 'class', 'woodev-mp-badge woodev-mp-badge--' + badge.tone );
		}

		$badge.find( '.woodev-mp-badge__label' ).text( badge.label );
	}

	/**
	 * Drops the pending confirmation of a button and cancels its timer, so the timer cannot act on a later prompt.
	 *
	 * @param {jQuery} $button The button.
	 */
	function disarm( $button ) {
		window.clearTimeout( $button.data( 'armTimer' ) );
		$button.data( { armed: false, armTimer: null } ).removeClass( 'is-armed' );
	}

	/**
	 * Asks for a second click on «Архивировать» and «Снять связь».
	 *
	 * The question goes to the result line beside the buttons (a button may be showing only its icon, so a question written on it
	 * would not be seen) and the button turns red until the time is up.
	 *
	 * @param {jQuery} $button The button.
	 * @param {jQuery} $result The result line of the block.
	 * @return {boolean} True when the second click came in time.
	 */
	function confirmed( $button, $result ) {
		var question = $button.data( 'confirm' );

		if ( ! question || $button.data( 'armed' ) ) {
			return true;
		}

		$button.data( 'armed', true ).addClass( 'is-armed' );
		$result.removeClass( 'woodev-mp-result--error' ).text( question );
		$button.data(
			'armTimer',
			window.setTimeout(
				function () {
					disarm( $button );
					if ( $result.text() === question ) {
						$result.text( '' );
					}
				},
				CONFIRM_MS
			)
		);

		return false;
	}

	/**
	 * Builds the one dialog of the page, the first time it is needed (native `<dialog>`: Esc and the backdrop come with it).
	 *
	 * @return {HTMLDialogElement} The dialog; its parts are `.woodev-mp-dialog__title` and `.woodev-mp-dialog__body`.
	 */
	function ensureDialog() {
		if ( dialog && dialog.isConnected ) {
			return dialog;
		}

		dialog = document.createElement( 'dialog' );
		dialog.className = 'woodev-mp-dialog';
		dialog.setAttribute( 'aria-labelledby', 'woodev-mp-dialog-title' );

		var head  = document.createElement( 'div' );
		var title = document.createElement( 'h2' );
		var close = document.createElement( 'button' );
		var body  = document.createElement( 'div' );

		head.className  = 'woodev-mp-dialog__head';
		title.className = 'woodev-mp-dialog__title';
		title.id        = 'woodev-mp-dialog-title';
		body.className  = 'woodev-mp-dialog__body';
		close.type      = 'button';
		close.autofocus = true; // The dialog opens on its close button, not on the first link in the text.
		close.className = 'button woodev-mp-dialog__close';
		close.textContent = settings.texts.close || 'OK';

		head.appendChild( title );
		dialog.appendChild( head );
		dialog.appendChild( body );
		dialog.appendChild( close );
		document.body.appendChild( dialog );

		close.addEventListener(
			'click',
			function () {
				closeDialog();
			}
		);

		// A click on the backdrop lands on the dialog element itself, not on its content.
		dialog.addEventListener(
			'click',
			function ( event ) {
				if ( event.target === dialog ) {
					closeDialog();
				}
			}
		);

		return dialog;
	}

	/**
	 * Closes the dialog.
	 */
	function closeDialog() {
		if ( ! dialog ) {
			return;
		}

		if ( typeof dialog.close === 'function' ) {
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
		}
	}

	/**
	 * Opens the dialog with the content of the `<template>` that follows a trigger (a badge with problems, the «?»).
	 *
	 * @param {jQuery} $trigger The element that carries `data-woodev-mp-dialog`.
	 */
	function openDialog( $trigger ) {
		var template = $trigger.next( 'template' ).get( 0 );

		if ( ! template ) {
			return;
		}

		var element = ensureDialog();

		element.querySelector( '.woodev-mp-dialog__title' ).textContent = template.getAttribute( 'data-title' ) || '';
		element.querySelector( '.woodev-mp-dialog__body' ).replaceChildren( template.content.cloneNode( true ) );

		// The buttons inside the dialog act on the block the dialog was opened from.
		$( element ).data( 'origin', $trigger.closest( '.woodev-mp-block' ) );

		if ( typeof element.showModal === 'function' ) {
			if ( ! element.open ) {
				element.showModal();
			}
		} else {
			element.setAttribute( 'open', '' );
		}
	}

	// In the capture phase: a badge in a variation's collapsed header sits in the `<h3>` that WooCommerce opens and closes on a click, and the click
	// must open the dialog only.
	document.addEventListener(
		'click',
		function ( event ) {
			var trigger = event.target.closest ? event.target.closest( '[data-woodev-mp-dialog]' ) : null;

			if ( ! trigger ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			openDialog( $( trigger ) );
		},
		true
	);

	// «Артикул»: the line with «изменить» gives way to the input.
	$( document ).on(
		'click',
		'.woodev-mp-article__edit',
		function ( event ) {
			var $line  = $( this ).closest( '.woodev-mp-article' );
			var $input = $( '#' + $.escapeSelector( $( this ).attr( 'aria-controls' ) ) );

			event.preventDefault();
			$line.prop( 'hidden', true );
			$input.prop( 'hidden', false ).trigger( 'focus' );
		}
	);

	/**
	 * Picks the account the tab shows: its block is shown, the others are hidden (all of them stay in the form, so «Обновить» saves each).
	 *
	 * @param {jQuery} $panel  The tab's panel.
	 * @param {string} account Account id.
	 */
	function showAccount( $panel, account ) {
		$panel.find( '.woodev-mp-block[data-account]' ).each(
			function () {
				$( this ).prop( 'hidden', String( $( this ).data( 'account' ) ) !== String( account ) );
			}
		);
	}

	/**
	 * Sets up the account select, when the tab has one (two or more accounts): moves it into the header of «Product data», right of
	 * Virtual / Downloadable, shows it only while this tab is open, and starts on the account the merchant used last in this browser
	 * (else the first one).
	 */
	function setUpAccountSwitch() {
		var $switch = $( '.woodev-mp-account-switch' );

		if ( ! $switch.length ) {
			return;
		}

		var $panel  = $switch.closest( '.woodev-mp-tab' );
		var $select = $switch.find( 'select' );
		var key     = 'woodev_mp_' + $switch.data( 'remember' );
		var $header = $( '#woocommerce-product-data .hndle' ).first();
		var $link   = $( '.woodev-mp-tab-link' );
		var last    = '';

		try {
			last = window.localStorage.getItem( key ) || '';
		} catch ( error ) {
			last = '';
		}

		if ( last && $select.find( 'option[value="' + last + '"]' ).length ) {
			$select.val( last );
		}

		showAccount( $panel, $select.val() );

		$select.on(
			'change',
			function () {
				showAccount( $panel, $select.val() );

				try {
					window.localStorage.setItem( key, $select.val() );
				} catch ( error ) {
					// Private window: the choice simply is not remembered.
					return;
				}
			}
		);

		if ( ! $header.length ) {
			return; // The header is not where WooCommerce used to put it: the select stays at the top of the tab, still usable.
		}

		$switch.addClass( 'woodev-mp-account-switch--header' ).appendTo( $header );

		if ( ! $link.length ) {
			return; // No tab link to follow: the select simply stays in the header.
		}

		var sync = function () {
			$switch.prop( 'hidden', ! $link.hasClass( 'active' ) );
		};

		sync();
		$( document ).on(
			'click',
			'#woocommerce-product-data ul.wc-tabs a',
			function () {
				// WooCommerce marks the clicked tab active in its own handler; read it after that.
				window.setTimeout( sync, 0 );
			}
		);
	}

	// After every ready handler of the page: WooCommerce moves its own Virtual / Downloadable into the header on ready, and the select goes after them.
	$(
		function () {
			window.setTimeout( setUpAccountSwitch, 0 );
		}
	);

	$( document ).on(
		'click',
		'[data-woodev-mp-op]',
		function ( event ) {
			var $button = $( this );
			var $dialog = $button.closest( 'dialog' );
			var $block  = $dialog.length ? $( dialog ).data( 'origin' ) : $button.closest( '.woodev-mp-block' );
			var $result = $block.find( '.woodev-mp-result' );

			event.preventDefault();

			// A button of the help dialog works on the block it was opened from, and shows its answer there.
			if ( $dialog.length ) {
				closeDialog();
			}

			// The result line is shared by the block's buttons: whatever is armed elsewhere in the block loses its prompt now.
			$block.find( '[data-woodev-mp-op]' ).not( $button ).each(
				function () {
					disarm( $( this ) );
				}
			);

			if ( ! confirmed( $button, $result ) ) {
				return;
			}

			disarm( $button );
			$button.prop( 'disabled', true );
			$result.removeClass( 'woodev-mp-result--error' ).text( settings.texts.working );

			$.post(
				window.ajaxurl,
				{
					action: $button.data( 'action' ),
					op: $button.data( 'woodev-mp-op' ),
					product_id: $button.data( 'product' ),
					account_id: $button.data( 'account' ),
					_wpnonce: $button.data( 'nonce' )
				}
			)
			.done(
				function ( response ) {
					$result.text( response.data.message );
					paintBadge( $block, response.data.badge );
				}
			)
			.fail(
				function ( xhr ) {
					var data = xhr.responseJSON && xhr.responseJSON.data;

					$result.addClass( 'woodev-mp-result--error' ).text( data && data.message ? data.message : settings.texts.failed );
				}
			)
			.always(
				function () {
					$button.prop( 'disabled', false );
				}
			);
		}
	);
}( window.jQuery ) );
