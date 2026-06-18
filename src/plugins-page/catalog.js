/**
 * «Плагины» catalog UI — category chips, search box, responsive card grid.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- createElement/Fragment required by classic JSX runtime.
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatPrice } from './filter';

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
 * A single add-on card. The whole media/title/footer links to the store
 * product permalink (UTM-decorated server-side) in a new tab.
 *
 * @param {Object} props         Props.
 * @param {Object} props.product Normalized product.
 * @return {JSX.Element} The card.
 */
export function ExtensionCard( { product } ) {
	const buttonText = product.free
		? __( 'Бесплатно', 'woodev-plugin-framework' )
		: // translators: %s is a formatted RUB amount.
		  __( 'Купить за %s ₽', 'woodev-plugin-framework' ).replace(
				'%s',
				formatPrice( product.price )
		  );

	return (
		<div className="woodev-extension-card">
			<a
				className="woodev-extension-card__media"
				href={ product.permalink }
				target="_blank"
				rel="noreferrer"
			>
				{ product.thumbnail ? (
					<img src={ product.thumbnail } alt={ product.title } />
				) : null }
			</a>
			<div className="woodev-extension-card__body">
				<h3 className="woodev-extension-card__title">
					<a href={ product.permalink } target="_blank" rel="noreferrer">
						{ product.title }
					</a>
				</h3>
				<div
					className="woodev-extension-card__excerpt"
					// eslint-disable-next-line react/no-danger -- excerpt sanitized server-side with wp_kses_post.
					dangerouslySetInnerHTML={ { __html: product.excerpt } }
				/>
			</div>
			<div className="woodev-extension-card__footer">
				<a
					className="button button-primary"
					href={ product.permalink }
					target="_blank"
					rel="noreferrer"
				>
					{ buttonText }
				</a>
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
export function ExtensionGrid( { products } ) {
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
				<ExtensionCard key={ p.id } product={ p } />
			) ) }
		</div>
	);
}
