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
import AccountMenu from './account';
import PurchasesTab from './purchases';

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
	const [ tab, setTab ] = useState( 'catalog' );
	const [ purchases, setPurchases ] = useState( null );
	const [ installState, setInstallState ] = useState( {} );

	const connected = !! ( config.accountEnabled && config.account && config.account.connected );

	const installPlugin = ( downloadId ) => {
		const id = Number( downloadId );
		if ( ! id ) {
			return;
		}
		setInstallState( ( prev ) => ( { ...prev, [ id ]: 'installing' } ) );
		apiFetch( {
			path: '/woodev/v1/account/install',
			method: 'POST',
			data: { download_id: id },
		} )
			.then( () => setInstallState( ( prev ) => ( { ...prev, [ id ]: 'done' } ) ) )
			.catch( () => setInstallState( ( prev ) => ( { ...prev, [ id ]: 'error' } ) ) );
	};

	useEffect( () => {
		apiFetch( { path: '/woodev/v1/extensions' } )
			.then( ( res ) => setData( res ) )
			.catch( () => setError( true ) );
	}, [] );

	useEffect( () => {
		if ( ! connected ) {
			return;
		}
		apiFetch( { path: '/woodev/v1/account/purchases' } )
			.then( ( res ) => setPurchases( res ) )
			.catch( () => setPurchases( { stale: true, purchases: [], purchased: [] } ) );
	}, [ connected ] );

	const products = data ? filterProducts( data.products, { category, search } ) : [];
	const failed = error || ( data && data.stale );
	const ready = data && ! data.stale;
	const purchased = ( purchases && purchases.purchased ) || [];

	return (
		<div className="woodev-extensions">
			<p className="woodev-extensions__intro">
				{ __( 'Расширьте возможности магазина плагинами Woodev.', 'woodev-plugin-framework' ) }
			</p>

			<AccountMenu enabled={ !! config.accountEnabled } account={ config.account } />

			{ connected ? (
				<div className="woodev-extensions__tabs" role="tablist">
					<button
						type="button"
						role="tab"
						aria-selected={ tab === 'catalog' }
						className={
							'woodev-extensions__tab' +
							( tab === 'catalog' ? ' is-active' : '' )
						}
						onClick={ () => setTab( 'catalog' ) }
					>
						{ __( 'Каталог', 'woodev-plugin-framework' ) }
					</button>
					<button
						type="button"
						role="tab"
						aria-selected={ tab === 'purchases' }
						className={
							'woodev-extensions__tab' +
							( tab === 'purchases' ? ' is-active' : '' )
						}
						onClick={ () => setTab( 'purchases' ) }
					>
						{ __( 'Мои покупки', 'woodev-plugin-framework' ) }
					</button>
				</div>
			) : null }

			{ connected && tab === 'purchases' ? (
				<PurchasesTab
					data={ purchases }
					products={ data ? data.products : [] }
					installed={ config.installed || [] }
					installState={ installState }
					onInstall={ installPlugin }
				/>
			) : (
				<Fragment>
					<SearchBox value={ search } onChange={ setSearch } />

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
							<ExtensionGrid
								products={ products }
								installed={ config.installed || [] }
								purchased={ purchased }
								installState={ installState }
								onInstall={ installPlugin }
							/>
						</Fragment>
					) : null }
				</Fragment>
			) }
		</div>
	);
}
