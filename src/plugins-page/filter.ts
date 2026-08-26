/**
 * Pure helpers for the «Плагины» catalog — no React, no side effects.
 *
 * @package woodev-plugin-framework
 */

export interface CatalogProduct {
	title?: string;
	excerpt?: string;
	price?: number;
	thumbnail?: string;
	categories?: string[];
	[ key: string ]: unknown;
}

export interface FilterOptions {
	category?: string;
	search?: string;
}

/**
 * Filters products by selected category slug and a free-text search.
 *
 * @param products Normalized products.
 * @param opts     { category: 'all'|slug, search: string }.
 * @return Filtered products.
 */
export function filterProducts(
	products: CatalogProduct[] | null | undefined,
	{ category = 'all', search = '' }: FilterOptions = {}
): CatalogProduct[] {
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
 * @param price Integer amount.
 * @return e.g. "12 500".
 */
export function formatPrice( price: number | null | undefined ): string {
	return Number( price || 0 ).toLocaleString( 'ru-RU' );
}
