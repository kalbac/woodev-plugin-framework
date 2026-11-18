/**
 * Search box for the marketplace category: types, waits, lists what the server found. It is a `SearchControl` (magnifier
 * icon) so it does not read as a free-text field.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import { Button, SearchControl } from '@wordpress/components';
import { errorLines, type MappingsApi } from './api';
import { Notices } from './notices';
import type { CategoryChoice, MappingCategory, Messages } from './types';

const SEARCH_DELAY_MS = 250;

interface Props {
	api: MappingsApi;
	messages: Messages;
	value: MappingCategory | null;
	onChange: ( category: MappingCategory | null ) => void;
}

export function CategoryPicker( { api, messages, value, onChange }: Props ) {
	const [ searching, setSearching ] = useState( value === null );
	const [ term, setTerm ] = useState( '' );
	const [ results, setResults ] = useState< CategoryChoice[] | null >( null );
	const [ failure, setFailure ] = useState< string[] | null >( null );

	useEffect( () => {
		setFailure( null );

		if ( ! searching || term.trim() === '' ) {
			setResults( null );
			return undefined;
		}

		let current = true;
		const timer = setTimeout( () => {
			api.searchCategories( term )
				.then( ( response ) => current && setResults( response.results ) )
				.catch( ( error ) => {
					if ( current ) {
						setResults( null );
						setFailure( errorLines( error, messages.requestFailed ) );
					}
				} );
		}, SEARCH_DELAY_MS );

		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ api, searching, term, messages.requestFailed ] );

	const choose = ( choice: CategoryChoice ) => {
		setSearching( false );
		setTerm( '' );
		onChange( { ...choice, available: true } );
	};

	if ( value && ! searching ) {
		return (
			<div className="woodev-category-picker">
				<div className="woodev-category-picker__chosen">
					<span className="woodev-category-picker__value">{ value.label }</span>
					<Button variant="link" onClick={ () => setSearching( true ) }>
						{ messages.categoryChange }
					</Button>
				</div>
				{ ! value.available && <p className="woodev-category-picker__note">{ messages.categoryUnavailable }</p> }
			</div>
		);
	}

	return (
		<div className="woodev-category-picker">
			<SearchControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ messages.marketplaceCategory }
				hideLabelFromVision
				placeholder={ messages.categorySearch }
				value={ term }
				onChange={ setTerm }
			/>
			<Notices
				notice={ failure ? { status: 'error', lines: failure } : null }
				onDismiss={ () => setFailure( null ) }
			/>
			{ results !== null && results.length === 0 && <p className="woodev-category-picker__note">{ messages.noResults }</p> }
			{ ( results ?? [] ).length > 0 && (
				<ul className="woodev-category-picker__results">
					{ ( results ?? [] ).map( ( choice ) => (
						<li key={ choice.key }>
							<button type="button" className="woodev-category-picker__option" onClick={ () => choose( choice ) }>
								{ choice.label }
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
