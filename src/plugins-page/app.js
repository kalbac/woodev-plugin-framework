/**
 * «Плагины» page root: fetches the catalog once via the woodev/v1 REST proxy,
 * holds search/category state, and renders the account scaffold + catalog.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- createElement/Fragment required by classic JSX runtime.
import { createElement, Fragment, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { filterProducts } from './filter';
import { SearchBox, CategoryFilter, ExtensionGrid } from './catalog';
import AccountPanel from './account-panel';

/**
 * The «Плагины» application root.
 *
 * @param {Object} props        Props.
 * @param {Object} props.config window.woodevExtensions ({ restRoot, restNonce, accountEnabled }).
 * @return {JSX.Element} The page.
 */
export default function App( { config } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ category, setCategory ] = useState( 'all' );

	useEffect( () => {
		apiFetch( { path: '/woodev/v1/extensions' } )
			.then( ( res ) => setData( res ) )
			.catch( () => setError( true ) );
	}, [] );

	const products = data ? filterProducts( data.products, { category, search } ) : [];
	const failed = error || ( data && data.stale );
	const ready = data && ! data.stale;

	return (
		<div className="woodev-extensions">
			<p className="woodev-extensions__intro">
				{ __( 'Расширьте возможности магазина плагинами Woodev.', 'woodev-plugin-framework' ) }
			</p>

			<SearchBox value={ search } onChange={ setSearch } />

			<AccountPanel enabled={ !! config.accountEnabled } />

			{ ! data && ! error ? (
				<p className="woodev-extensions__loading">
					{ __( 'Загрузка…', 'woodev-plugin-framework' ) }
				</p>
			) : null }

			{ failed ? (
				<p className="woodev-extensions__error">
					{ __( 'Не удалось загрузить каталог, попробуйте позже.', 'woodev-plugin-framework' ) }
				</p>
			) : null }

			{ ready ? (
				<Fragment>
					<CategoryFilter
						categories={ data.categories || [] }
						selected={ category }
						onSelect={ setCategory }
					/>
					<ExtensionGrid products={ products } />
				</Fragment>
			) : null }
		</div>
	);
}
