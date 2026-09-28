/**
 * Remote search box of the order wizard (#969) — a text input over a result list, for the
 * customer (①) and product (③) lookups.
 *
 * Deliberately not `@woocommerce/components`' `Search`: it is not in this page's typed WC
 * surface (`wc-globals.d.ts`), needs an autocompleter contract nobody here has measured, and
 * cannot run under jest. This one takes the search as a prop, so the wizard owns the
 * requests (`api.ts`) and the component owns only the typing, debouncing, empty / error /
 * loading states and the one level of drill-down a variable product needs.
 *
 * Mirrors the location picker's rules: a minimum query length before any request, a stale
 * response never overwrites a newer query, and a search that fails or finds nothing says so
 * instead of leaving a silently empty box.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Spinner, TextControl } from '@wordpress/components';
import type { SearchOption } from './api';

const DEBOUNCE_MS = 300;

/** Shorter than this never reaches the server. */
export const MIN_QUERY_LENGTH = 2;

type Status = 'idle' | 'loading' | 'error';

interface RemoteSearchProps {
	label: string;
	placeholder?: string;
	search: ( query: string ) => Promise<SearchOption[]>;
	/** Called with the chosen option's `value` (a variable product's variation, never the parent). */
	onPick: ( value: unknown ) => void;
	disabled?: boolean;
}

export default function RemoteSearch( { label, placeholder, search, onPick, disabled }: RemoteSearchProps ) {
	const [ query, setQuery ] = useState( '' );
	const [ options, setOptions ] = useState<SearchOption[]>( [] );
	const [ status, setStatus ] = useState<Status>( 'idle' );
	const [ searched, setSearched ] = useState( false );
	/** The variable product whose variations are on screen, with the list to return to. */
	const [ parent, setParent ] = useState<{ option: SearchOption; back: SearchOption[] } | null>( null );
	const generation = useRef( 0 );
	// The latest `search` without re-firing the effect when the parent re-renders with a new closure.
	const searchRef = useRef( search );
	searchRef.current = search;

	useEffect( () => {
		const text = query.trim();
		setParent( null );

		if ( text.length < MIN_QUERY_LENGTH ) {
			generation.current += 1;
			setOptions( [] );
			setStatus( 'idle' );
			setSearched( false );

			return undefined;
		}

		const mine = ++generation.current;
		setStatus( 'loading' );

		const timer = setTimeout( () => {
			searchRef.current( text )
				.then( ( found ) => {
					if ( mine !== generation.current ) {
						return;
					}
					setOptions( found );
					setStatus( 'idle' );
					setSearched( true );
				} )
				.catch( () => {
					if ( mine !== generation.current ) {
						return;
					}
					setOptions( [] );
					setStatus( 'error' );
					setSearched( true );
				} );
		}, DEBOUNCE_MS );

		return () => clearTimeout( timer );
	}, [ query ] );

	const reset = () => {
		generation.current += 1;
		setQuery( '' );
		setOptions( [] );
		setParent( null );
		setStatus( 'idle' );
		setSearched( false );
	};

	const choose = ( option: SearchOption ) => {
		if ( option.loadChildren ) {
			const mine = ++generation.current;
			setParent( { option, back: options } );
			setStatus( 'loading' );
			option.loadChildren()
				.then( ( children ) => {
					if ( mine === generation.current ) {
						setOptions( children );
						setStatus( 'idle' );
					}
				} )
				.catch( () => {
					if ( mine === generation.current ) {
						setOptions( [] );
						setStatus( 'error' );
					}
				} );

			return;
		}

		onPick( option.value );
		reset();
	};

	const backToResults = () => {
		generation.current += 1;
		setOptions( parent ? parent.back : [] );
		setParent( null );
		setStatus( 'idle' );
	};

	const showList = query.trim().length >= MIN_QUERY_LENGTH;

	return (
		<div className="woodev-order-wizard__search">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				value={ query }
				placeholder={ placeholder }
				disabled={ disabled }
				autoComplete="off"
				onChange={ setQuery }
			/>
			{ ! showList && '' !== query && (
				<p className="woodev-order-wizard__search-hint">{ __( 'Введите минимум 2 символа для поиска', 'woodev-plugin-framework' ) }</p>
			) }
			{ showList && (
				<div className="woodev-order-wizard__results" role="listbox" aria-label={ __( 'Результаты поиска', 'woodev-plugin-framework' ) }>
					{ parent && (
						<div className="woodev-order-wizard__results-parent">
							<Button variant="link" onClick={ backToResults }>
								{ __( '← К результатам поиска', 'woodev-plugin-framework' ) }
							</Button>
							<strong>{ parent.option.label }</strong>
						</div>
					) }
					{ 'loading' === status && (
						<div className="woodev-order-wizard__results-status">
							<Spinner />
							<span>{ __( 'Поиск…', 'woodev-plugin-framework' ) }</span>
						</div>
					) }
					{ 'error' === status && (
						<div className="woodev-order-wizard__results-status is-error">
							{ __( 'Не удалось выполнить поиск. Попробуйте ещё раз.', 'woodev-plugin-framework' ) }
						</div>
					) }
					{ 'idle' === status && searched && 0 === options.length && (
						<div className="woodev-order-wizard__results-status">{ __( 'Ничего не найдено', 'woodev-plugin-framework' ) }</div>
					) }
					{ 'idle' === status &&
						options.map( ( option ) => (
							<button
								key={ option.id }
								type="button"
								role="option"
								aria-selected={ false }
								className="woodev-order-wizard__result"
								onClick={ () => choose( option ) }
							>
								<span className="woodev-order-wizard__result-label">{ option.label }</span>
								{ option.hint && <span className="woodev-order-wizard__result-hint">{ option.hint }</span> }
							</button>
						) ) }
				</div>
			) }
		</div>
	);
}
