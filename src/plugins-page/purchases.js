/**
 * «Мои покупки» tab. Lists the connected account's owned downloads (icon, title,
 * purchase date), cross-referencing the already-loaded catalog by download id for
 * a per-row store link (the connector payload carries no permalink). Renders
 * loading / error / empty / list states. Shown only in the connected state.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- createElement required by classic JSX runtime.
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import InstallButton, { InstallOverlay } from './install';

/**
 * Formats a connector date string ("Y-m-d H:i:s") to "DD.MM.YYYY", or '' when
 * unparseable. Pure string slicing — no Date parsing / timezone surprises.
 *
 * @param {string} raw The raw connector date.
 * @return {string} The display date.
 */
function formatDate( raw ) {
	const m = /^(\d{4})-(\d{2})-(\d{2})/.exec( String( raw || '' ) );
	return m ? `${ m[ 3 ] }.${ m[ 2 ] }.${ m[ 1 ] }` : '';
}

/**
 * The «Мои покупки» tab.
 *
 * @param {Object}      props              Props.
 * @param {Object|null} props.data         { purchases, purchased, stale } or null while loading.
 * @param {Array}       props.products     Loaded catalog products (for the id→permalink cross-ref).
 * @param {Array}       props.installed    Installed download ids.
 * @param {Object}      props.installState Per-download install lifecycle state.
 * @param {Function}    props.onInstall    Install handler (receives a download id).
 * @return {JSX.Element} The tab.
 */
export default function PurchasesTab( {
	data,
	products = [],
	installed = [],
	installState = {},
	onInstall,
} ) {
	if ( ! data ) {
		return (
			<p className="woodev-extensions__loading">
				{ __( 'Загрузка покупок…', 'woodev-plugin-framework' ) }
			</p>
		);
	}

	if ( data.stale ) {
		return (
			<p className="woodev-extensions__error">
				{ __( 'Не удалось загрузить покупки, попробуйте позже.', 'woodev-plugin-framework' ) }
			</p>
		);
	}

	const purchases = data.purchases || [];

	if ( ! purchases.length ) {
		return (
			<p className="woodev-extensions__empty">
				{ __( 'У вас пока нет покупок на woodev.ru.', 'woodev-plugin-framework' ) }
			</p>
		);
	}

	const linkById = {};
	products.forEach( ( p ) => {
		if ( p && p.id && p.permalink ) {
			linkById[ p.id ] = p.permalink;
		}
	} );

	return (
		<ul className="woodev-purchases">
			{ purchases.map( ( item ) => {
				const link = linkById[ item.id ] || null;
				const date = formatDate( item.date );
				const installing = 'installing' === installState[ item.id ];

				return (
					<li
						key={ item.id }
						className={
							'woodev-purchases__row' +
							( installing ? ' is-installing' : '' )
						}
						aria-busy={ installing }
					>
						<InstallOverlay active={ installing } />
						<span className="woodev-purchases__icon">
							{ item.icon ? (
								<img src={ item.icon } alt="" loading="lazy" />
							) : (
								<span
									className="woodev-purchases__icon-placeholder"
									aria-hidden="true"
								>
									{ ( item.title || '?' ).trim().charAt( 0 ).toUpperCase() }
								</span>
							) }
						</span>
						<span className="woodev-purchases__title">
							{ link ? (
								<a href={ link } target="_blank" rel="noreferrer">
									{ item.title }
								</a>
							) : (
								item.title
							) }
						</span>
						{ date ? (
							<span className="woodev-purchases__date">{ date }</span>
						) : null }
						<span className="woodev-purchases__action">
							{ installed.includes( item.id ) ? (
								<span className="woodev-purchases__installed">
									{ __( 'Установлен', 'woodev-plugin-framework' ) }
								</span>
							) : (
								<InstallButton
									state={ installState[ item.id ] || 'idle' }
									onInstall={ () => onInstall && onInstall( item.id ) }
								/>
							) }
						</span>
					</li>
				);
			} ) }
		</ul>
	);
}
