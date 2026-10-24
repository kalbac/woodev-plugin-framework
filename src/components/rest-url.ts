/**
 * Appends a query string to a REST URL that may already hold one.
 *
 * On plain permalinks `rest_url()` is `…/index.php?rest_route=/woodev/v1/…`; a second `?` would end up INSIDE the
 * `rest_route` value and the route would not match, so the separator is `&` when a query is already there.
 *
 * @package woodev-plugin-framework
 */

export function withQuery( url: string, query: string ): string {
	return `${ url }${ url.includes( '?' ) ? '&' : '?' }${ query }`;
}
