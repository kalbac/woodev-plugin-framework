/**
 * «Плагины» catalog UI — category chips, search box, responsive card grid.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- createElement/Fragment required by classic JSX runtime.
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatPrice } from './filter';
import InstallButton from './install';

/**
 * The catalog search input.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.value    Current search term.
 * @param {Function} props.onChange Change handler (receives the new term).
 * @return {JSX.Element} The search box.
 */
export function SearchBox( { value, onChange } ) {
	return (
		<div className="woodev-extensions__search">
			<input
				type="search"
				value={ value }
				placeholder={ __( 'Поиск плагинов', 'woodev-plugin-framework' ) }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		</div>
	);
}

/**
 * Category filter chips («Все» + one per store category).
 *
 * @param {Object}   props            Props.
 * @param {Array}    props.categories [{ slug, label }].
 * @param {string}   props.selected   Selected slug ('all' by default).
 * @param {Function} props.onSelect   Select handler (receives the slug).
 * @return {JSX.Element} The chip row.
 */
export function CategoryFilter( { categories, selected, onSelect } ) {
	const chips = [
		{ slug: 'all', label: __( 'Все', 'woodev-plugin-framework' ) },
		...categories,
	];

	return (
		<div className="woodev-extensions__chips">
			{ chips.map( ( c ) => (
				<button
					key={ c.slug }
					type="button"
					className={
						'woodev-extensions__chip' +
						( c.slug === selected ? ' is-active' : '' )
					}
					onClick={ () => onSelect( c.slug ) }
				>
					{ c.label }
				</button>
			) ) }
		</div>
	);
}

/**
 * A 0–5 star rating with its numeric value. Rendered only when a product has a
 * rating; stars are decorative (the numeric value carries the accessible label).
 *
 * @param {Object} props        Props.
 * @param {number} props.rating Rating on a 0–5 scale.
 * @return {JSX.Element} The rating row.
 */
export function RatingStars( { rating } ) {
	const full = Math.round( rating );
	const label = __( 'Рейтинг: %s из 5', 'woodev-plugin-framework' ).replace(
		'%s',
		rating.toFixed( 1 )
	);

	return (
		<div className="woodev-extension-card__rating" title={ label }>
			<span className="woodev-extension-card__stars" aria-hidden="true">
				{ '★★★★★'.slice( 0, full ) + '☆☆☆☆☆'.slice( 0, 5 - full ) }
			</span>
			<span className="woodev-extension-card__rating-value">
				{ rating.toFixed( 1 ) }
			</span>
		</div>
	);
}

/**
 * A single add-on card. The whole media/title/footer links to the store
 * product permalink (UTM-decorated server-side) in a new tab.
 *
 * @param {Object} props         Props.
 * @param {Object} props.product Normalized product.
 * @return {JSX.Element} The card.
 */
export function ExtensionCard( {
	product,
	isInstalled = false,
	isPurchased = false,
	installState = 'idle',
	onInstall,
} ) {
	const buttonText = isInstalled
		? __( 'Посмотреть', 'woodev-plugin-framework' )
		: product.free
		? __( 'Бесплатно', 'woodev-plugin-framework' )
		: // translators: %s is a formatted RUB amount.
		  __( 'Купить за %s ₽', 'woodev-plugin-framework' ).replace(
				'%s',
				formatPrice( product.price )
		  );

	return (
		<div
			className={
				'woodev-extension-card' + ( isInstalled ? ' is-installed' : '' )
			}
		>
			<div className="woodev-extension-card__head">
				<a
					className="woodev-extension-card__icon"
					href={ product.permalink }
					target="_blank"
					rel="noreferrer"
				>
					{ product.thumbnail ? (
						<img src={ product.thumbnail } alt={ product.title } loading="lazy" />
					) : (
						<span
							className="woodev-extension-card__icon-placeholder"
							aria-hidden="true"
						>
							{ ( product.title || '?' ).trim().charAt( 0 ).toUpperCase() }
						</span>
					) }
				</a>
				<h3 className="woodev-extension-card__title">
					<a href={ product.permalink } target="_blank" rel="noreferrer">
						{ product.title }
					</a>
				</h3>
				{ isInstalled ? (
					<span className="woodev-extension-card__badge">
						{ __( 'Установлен', 'woodev-plugin-framework' ) }
					</span>
				) : isPurchased ? (
					<span className="woodev-extension-card__badge woodev-extension-card__badge--purchased">
						{ __( 'Куплено', 'woodev-plugin-framework' ) }
					</span>
				) : null }
			</div>
			{ product.rating ? <RatingStars rating={ product.rating } /> : null }
			<div
				className="woodev-extension-card__excerpt"
				// eslint-disable-next-line react/no-danger -- excerpt sanitized server-side with wp_kses_post.
				dangerouslySetInnerHTML={ { __html: product.excerpt } }
			/>
			<div className="woodev-extension-card__footer">
				{ isPurchased && ! isInstalled ? (
					<InstallButton
						state={ installState }
						onInstall={ () => onInstall && onInstall( product.id ) }
					/>
				) : (
					<a
						className={
							'woodev-extension-card__buy' +
							( product.free && ! isInstalled ? ' is-free' : '' ) +
							( isInstalled ? ' is-installed' : '' )
						}
						href={ product.permalink }
						target="_blank"
						rel="noreferrer"
					>
						{ buttonText }
					</a>
				) }
			</div>
		</div>
	);
}

/**
 * The responsive card grid, or an empty-state message.
 *
 * @param {Object} props          Props.
 * @param {Array}  props.products Filtered products.
 * @return {JSX.Element} The grid.
 */
export function ExtensionGrid( {
	products,
	installed = [],
	purchased = [],
	installState = {},
	onInstall,
} ) {
	if ( ! products.length ) {
		return (
			<p className="woodev-extensions__empty">
				{ __( 'Ничего не найдено', 'woodev-plugin-framework' ) }
			</p>
		);
	}

	return (
		<div className="woodev-extensions__grid">
			{ products.map( ( p ) => (
				<ExtensionCard
					key={ p.id }
					product={ p }
					isInstalled={ installed.includes( p.id ) }
					isPurchased={ purchased.includes( p.id ) }
					installState={ installState[ p.id ] || 'idle' }
					onInstall={ onInstall }
				/>
			) ) }
		</div>
	);
}
