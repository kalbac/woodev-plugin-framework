/**
 * Tests for the dative «всем / любым» labels of the advanced-filters header (#941).
 *
 * The fake below has the DOM shape WooCommerce 11.0.1's `AdvancedFilters.getTitle()`
 * produces — a `SelectControl` carrying `woocommerce-filters-advanced__title-select`, whose
 * options are the module-level `[ { value: 'all', label: 'All' }, { value: 'any', … } ]`
 * constant — so the assertion is on what a merchant reads, not on a helper's return value.
 *
 * @see src/shipping-orders-page/match-labels.tsx
 */

import '@testing-library/jest-dom';
import { act, render, screen } from '@testing-library/react';
import {
	MATCH_SELECT_SELECTOR,
	MatchLabelScope,
	getMatchLabels,
	relabelMatchOptions,
} from '../../src/shipping-orders-page/match-labels';

/** WooCommerce's own control, labels in the NOMINATIVE — what its Russian pack ships. */
function WcMatchSelect( { nominative = [ 'Все', 'Любое' ] } ) {
	return (
		<div className="woocommerce-filters-advanced__title-select">
			<select aria-label="Choose to apply any or all filters" defaultValue="all">
				<option value="all">{ nominative[ 0 ] }</option>
				<option value="any">{ nominative[ 1 ] }</option>
			</select>
		</div>
	);
}

function optionTexts( container ) {
	return [ ...container.querySelectorAll( `${ MATCH_SELECT_SELECTOR } option` ) ].map(
		( option ) => option.textContent
	);
}

describe( 'getMatchLabels', () => {
	test( 'pins the two dative labels', () => {
		expect( getMatchLabels() ).toEqual( { all: 'всем', any: 'любым' } );
	} );
} );

describe( 'relabelMatchOptions', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'matches on the option value, not on the translated text', () => {
		document.body.innerHTML = `
			<div class="${ MATCH_SELECT_SELECTOR.slice( 1 ) }">
				<select><option value="all">All</option><option value="any">Any</option></select>
			</div>`;

		relabelMatchOptions( document.body );

		expect( optionTexts( document.body ) ).toEqual( [ 'всем', 'любым' ] );
	} );

	test( 'leaves every other option and select alone', () => {
		document.body.innerHTML = `
			<div class="${ MATCH_SELECT_SELECTOR.slice( 1 ) }">
				<select><option value="all">All</option><option value="none">Nothing</option></select>
			</div>
			<select id="other"><option value="all">Все</option><option value="any">Любое</option></select>`;

		relabelMatchOptions( document.body );

		expect( optionTexts( document.body ) ).toEqual( [ 'всем', 'Nothing' ] );
		expect(
			[ ...document.querySelectorAll( '#other option' ) ].map( ( o ) => o.textContent )
		).toEqual( [ 'Все', 'Любое' ] );
	} );
} );

describe( 'MatchLabelScope', () => {
	test( 'turns WooCommerce\'s «Все / Любое» into «всем / любым»', () => {
		const { container } = render(
			<MatchLabelScope>
				<WcMatchSelect />
			</MatchLabelScope>
		);

		expect( optionTexts( container ) ).toEqual( [ 'всем', 'любым' ] );
		expect( screen.getByRole( 'combobox' ) ).toHaveValue( 'all' );
	} );

	test( 'relabels a select that appears after the first paint (the toggle remounts the block)', async () => {
		function Harness( { shown } ) {
			return <MatchLabelScope>{ shown && <WcMatchSelect /> }</MatchLabelScope>;
		}

		const { container, rerender } = render( <Harness shown={ false } /> );

		expect( optionTexts( container ) ).toEqual( [] );

		rerender( <Harness shown /> );
		// The observer callback is a microtask.
		await act( async () => {
			await Promise.resolve();
		} );

		expect( optionTexts( container ) ).toEqual( [ 'всем', 'любым' ] );
	} );

	test( 'does not touch a select outside its own container', () => {
		const { container } = render(
			<>
				<div data-testid="outside">
					<WcMatchSelect />
				</div>
				<MatchLabelScope>
					<WcMatchSelect />
				</MatchLabelScope>
			</>
		);

		expect( optionTexts( container.querySelector( '[data-testid="outside"]' ) ) ).toEqual( [
			'Все',
			'Любое',
		] );
	} );
} );
