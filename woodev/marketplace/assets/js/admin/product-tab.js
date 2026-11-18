/**
 * The marketplace tab of the product edit screen: the buttons of a block.
 *
 * Each button posts to `admin-ajax.php` with its own nonce and shows the answer in the line beside it. «Архивировать» and «Снять связь» ask twice:
 * the first click shows the question and arms the button, a second click within five seconds does it.
 */
( function ( $ ) {
	'use strict';

	var settings   = window.woodevMarketplaceProduct || { texts: {} };
	var CONFIRM_MS = 5000;

	/**
	 * Paints the state badge of a block with the state the server answered.
	 *
	 * @param {jQuery} $block Block of one account.
	 * @param {Object|null} badge `{ label, tone }`.
	 */
	function paintBadge( $block, badge ) {
		if ( ! badge ) {
			return;
		}

		$block
			.find( '.woodev-mp-block__title .woodev-mp-badge' )
			.attr( 'class', 'woodev-mp-badge woodev-mp-badge--' + badge.tone )
			.text( badge.label );
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

	$( document ).on(
		'click',
		'[data-woodev-mp-op]',
		function ( event ) {
			var $button = $( this );
			var $block  = $button.closest( '.woodev-mp-block' );
			var $result = $block.find( '.woodev-mp-result' );

			event.preventDefault();

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
