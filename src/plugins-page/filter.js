/**
 * Pure helpers for the «Плагины» catalog — no React, no side effects.
 *
 * @package woodev-plugin-framework
 */

/**
 * Filters products by selected category slug and a free-text search.
 *
 * @param {Array}  products Normalized products.
 * @param {Object} opts     { category: 'all'|slug, search: string }.
 * @return {Array} Filtered products.
 */
export function filterProducts( products, { category = 'all', search = '' } = {} ) {
	const needle = search.trim().toLowerCase();

	return ( products || [] ).filter( ( p ) => {
		const inCategory =
			category === 'all' || ( p.categories || [] ).includes( category );
		const matches =
			! needle ||
			( p.title || '' ).toLowerCase().includes( needle ) ||
			( p.excerpt || '' ).toLowerCase().includes( needle );
		return inCategory && matches;
	} );
}

/**
 * Formats an integer RUB price with ru-RU thousands separators.
 *
 * @param {number} price Integer amount.
 * @return {string} e.g. "12 500".
 */
export function formatPrice( price ) {
	return Number( price || 0 ).toLocaleString( 'ru-RU' );
}
