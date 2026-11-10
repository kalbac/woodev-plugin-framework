/**
 * Search box for the marketplace category: types, waits, lists what the server found.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';
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
			<div>
				<strong>{ value.label }</strong>{ ' ' }
				<Button variant="link" onClick={ () => setSearching( true ) }>
					{ messages.categoryChange }
				</Button>
				{ ! value.available && <p className="description">{ messages.categoryUnavailable }</p> }
			</div>
		);
	}

	return (
		<div>
			<TextControl __next40pxDefaultSize
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
			{ results !== null && results.length === 0 && <p className="description">{ messages.noResults }</p> }
			<ul style={ { margin: 0, maxHeight: 220, overflowY: 'auto' } }>
				{ ( results ?? [] ).map( ( choice ) => (
					<li key={ choice.key }>
						<Button variant="tertiary" onClick={ () => choose( choice ) }>
							{ choice.label }
						</Button>
					</li>
				) ) }
			</ul>
		</div>
	);
}
