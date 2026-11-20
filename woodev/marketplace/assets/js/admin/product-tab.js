/**
 * The marketplace tab of the product edit screen: the buttons of a block, the dialogs (the state badge's problems, the help), the article's
 * «изменить» / «отменить», the attention pulse of a badge with problems and the account select. For a variable product also the actions on its
 * variations: the select above the table of the variants, and the buttons of a row or of an opened variation.
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
	 * Builds the one dialog of the page, the first time it is needed: the shared `WoodevDialog` (header with «✕», body, footer with «Закрыть»).
	 *
	 * @return {Object} The `WoodevDialog` handle.
	 */
	function ensureDialog() {
		if ( dialog && dialog.element.isConnected ) {
			return dialog;
		}

		var closer = document.createElement( 'button' );

		dialog = window.WoodevDialog.build( { closeLabel: settings.texts.close || 'OK', footer: true } );

		closer.type        = 'button';
		closer.className   = 'button';
		closer.textContent = settings.texts.close || 'OK';
		closer.setAttribute( 'data-woodev-dialog-close', '' );
		dialog.footer.appendChild( closer );

		return dialog;
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

		var handle = ensureDialog();

		handle.title.textContent = template.getAttribute( 'data-title' ) || '';
		handle.body.replaceChildren( template.content.cloneNode( true ) );

		// The buttons inside the dialog act on the block the dialog was opened from.
		$( handle.element ).data( 'origin', $trigger.closest( '.woodev-mp-block' ) );
		handle.open();
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

	/**
	 * Finds the parts of an article control from any of its buttons.
	 *
	 * @param {Element} button «изменить» or «отменить».
	 * @return {{line: jQuery, editor: jQuery, input: jQuery}} The line with the article, the editor that replaces it, the input inside.
	 */
	function articleParts( button ) {
		var $input  = $( '#' + $.escapeSelector( $( button ).attr( 'aria-controls' ) ) );
		var $editor = $input.closest( '.woodev-mp-article__editor' );

		return { line: $editor.prevAll( '.woodev-mp-article' ).first(), editor: $editor, input: $input };
	}

	// «Артикул»: the line with «изменить» gives way to the input.
	$( document ).on(
		'click',
		'.woodev-mp-article__edit',
		function ( event ) {
			var parts = articleParts( this );

			event.preventDefault();
			parts.line.prop( 'hidden', true );
			parts.editor.prop( 'hidden', false );
			parts.input.trigger( 'focus' );
		}
	);

	// «отменить»: the input gives way to the line again, and what was typed is dropped (the input goes back to the article it started with).
	$( document ).on(
		'click',
		'.woodev-mp-article__cancel',
		function ( event ) {
			var parts = articleParts( this );

			event.preventDefault();
			parts.input.val( parts.input.prop( 'defaultValue' ) );
			parts.editor.prop( 'hidden', true );
			parts.line.prop( 'hidden', false );
		}
	);

	/**
	 * Makes the badges with problems inside a scope pulse a few times (the stylesheet does the pulsing and stops it by itself; it does nothing
	 * for a visitor who asked the system for less motion). Only badges that can be seen are pulsed.
	 *
	 * @param {jQuery} $scope Where to look for badges.
	 */
	function pulse( $scope ) {
		$scope.find( '.woodev-mp-badge--problem' ).filter( ':visible' ).addClass( 'is-pulsing' );
	}

	$( document ).on(
		'animationend',
		'.woodev-mp-badge--problem',
		function () {
			$( this ).removeClass( 'is-pulsing' );
		}
	);

	// In the capture phase, before WooCommerce marks the clicked tab active: only a tab that was not open pulses, not every click on it.
	document.addEventListener(
		'click',
		function ( event ) {
			var $tab = $( event.target.closest ? event.target.closest( '#woocommerce-product-data ul.wc-tabs li' ) : [] );

			if ( ! $tab.length || $tab.hasClass( 'active' ) ) {
				return;
			}

			if ( $tab.hasClass( 'woodev-mp-tab-link' ) ) {
				window.setTimeout( function () { pulse( $( '.woodev-mp-tab' ) ); }, 0 );
			} else if ( $tab.hasClass( 'variations_tab' ) ) {
				window.setTimeout( function () { pulse( $( '#variable_product_options' ) ); }, 0 );
			}
		},
		true
	);

	// The variations arrive after the tab is opened (and again after a save or a page): their badges pulse when they are on screen.
	$( document ).on(
		'woocommerce_variations_loaded',
		function () {
			pulse( $( '#variable_product_options' ) );
		}
	);

	// An opened variation shows its own badge: it pulses once the variation is open.
	$( document ).on(
		'click',
		'.woocommerce_variation > h3',
		function () {
			var $variation = $( this ).closest( '.woocommerce_variation' );

			window.setTimeout(
				function () {
					if ( ! $variation.hasClass( 'closed' ) ) {
						pulse( $variation );
					}
				},
				0
			);
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
			var $block  = $dialog.length ? $( dialog.element ).data( 'origin' ) : $button.closest( '.woodev-mp-block' );
			var $result = $block.find( '.woodev-mp-result' );

			event.preventDefault();

			// A button of the help dialog works on the block it was opened from, and shows its answer there.
			if ( $dialog.length ) {
				dialog.close();
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

	/**
	 * Draws the answer of an action on variations in the line beside the buttons: the sentence, in red when it is a refusal, and the link to the page
	 * that shows the progress when the work went to the queue.
	 *
	 * @param {jQuery} $result Result line.
	 * @param {Object} notice  `{ tone, text, url, link }`.
	 */
	function showNotice( $result, notice ) {
		$result.toggleClass( 'woodev-mp-result--error', 'error' === notice.tone ).text( notice.text );

		if ( notice.url ) {
			$result.append( ' ', $( '<a>' ).attr( 'href', notice.url ).text( notice.link ) );
		}
	}

	/**
	 * Runs one action on some variations of a variable product and shows the answer. The server runs the table's own bulk action (the same
	 * code as the Variations tab's «Bulk actions»).
	 *
	 * @param {Object} request `{ source, action, variations, $result, $controls }`: the element that carries `data-action`, `data-nonce`,
	 *                         `data-account` and `data-product`; the action id; the variation ids; the result line; the controls to lock meanwhile.
	 */
	function runOnVariations( request ) {
		request.$controls.prop( 'disabled', true );
		request.$result.removeClass( 'woodev-mp-result--error' ).text( settings.texts.working );

		$.post(
			window.ajaxurl,
			{
				action: request.source.data( 'action' ),
				_wpnonce: request.source.data( 'nonce' ),
				bulk_action: request.action,
				account_id: request.source.data( 'account' ),
				product_id: request.source.data( 'product' ),
				variations: request.variations
			}
		)
		.done(
			function ( response ) {
				showNotice( request.$result, response.data );
			}
		)
		.fail(
			function ( xhr ) {
				var data = xhr.responseJSON && xhr.responseJSON.data;

				request.$result.addClass( 'woodev-mp-result--error' ).text( data && data.message ? data.message : settings.texts.failed );
			}
		)
		.always(
			function () {
				request.$controls.prop( 'disabled', false );
			}
		);
	}

	// A button of a variation (in a row of the table or in the opened variation): the same buttons as a simple product's, acting on this variation.
	$( document ).on(
		'click',
		'[data-woodev-mp-vop]',
		function ( event ) {
			var $button = $( this );
			var $scope  = $button.closest( '.woodev-mp-variants, .woodev-mp-buttons' );
			var $result = $scope.find( '.woodev-mp-result' ).first();

			event.preventDefault();

			// The result line is shared by the buttons around it: whatever is armed elsewhere loses its prompt now.
			$scope.find( '[data-woodev-mp-vop]' ).not( $button ).each(
				function () {
					disarm( $( this ) );
				}
			);

			if ( ! confirmed( $button, $result ) ) {
				return;
			}

			disarm( $button );
			runOnVariations(
				{
					source: $button,
					action: $button.data( 'woodev-mp-vop' ),
					variations: [ $button.data( 'variation' ) ],
					$result: $result,
					$controls: $button
				}
			);
		}
	);

	// «Выберите действие» + «Применить» above the table: the chosen action on every ticked variant.
	$( document ).on(
		'click',
		'.woodev-mp-variants__apply',
		function () {
			var $table   = $( this ).closest( '.woodev-mp-variants' );
			var $select  = $table.find( '.woodev-mp-variants__select' );
			var $result  = $table.find( '.woodev-mp-result' ).first();
			var ticked   = $table.find( '.woodev-mp-variants__check:checked' ).map(
				function () {
					return Number( this.value );
				}
			).get();

			if ( ! $select.val() ) {
				$result.addClass( 'woodev-mp-result--error' ).text( settings.texts.pickAction );
				return;
			}

			if ( ! ticked.length ) {
				$result.addClass( 'woodev-mp-result--error' ).text( settings.texts.pickVariants );
				return;
			}

			runOnVariations( { source: $table, action: $select.val(), variations: ticked, $result: $result, $controls: $( this ) } );
		}
	);

	// The tick in the heading ticks every variant; a variant ticked or unticked keeps the heading's tick true.
	$( document ).on(
		'change',
		'.woodev-mp-variants__all',
		function () {
			$( this ).closest( '.woodev-mp-variants' ).find( '.woodev-mp-variants__check' ).prop( 'checked', this.checked );
		}
	);

	$( document ).on(
		'change',
		'.woodev-mp-variants__check',
		function () {
			var $checks = $( this ).closest( '.woodev-mp-variants' ).find( '.woodev-mp-variants__check' );

			$( this ).closest( '.woodev-mp-variants' ).find( '.woodev-mp-variants__all' ).prop( 'checked', $checks.length === $checks.filter( ':checked' ).length );
		}
	);
}( window.jQuery ) );
