/**
 * The browser-side half of a guarded download. The server only ever returns an address on this site's own hosts; the
 * browser checks again before it offers the link, so an address that slipped through a provider bug is a dead
 * message, never a navigation to someone else's page.
 *
 * @package woodev-plugin-framework
 */

/**
 * A path on this site, or an http(s) address on the page's own origin.
 *
 * @param url    The address from the server.
 * @param origin The page's origin (`window.location.origin`).
 */
export function isOwnDownloadUrl( url: string, origin: string ): boolean {
	if ( /[\u0000- \u007f\\]/.test( url ) ) {
		return false;
	}
	if ( url.startsWith( '/' ) ) {
		return ! url.startsWith( '//' );
	}

	try {
		const parsed = new URL( url );

		return ( parsed.protocol === 'https:' || parsed.protocol === 'http:' ) && parsed.origin === origin;
	} catch {
		return false;
	}
}

/**
 * Whether a fix link may be rendered: http(s) or a path on this site.
 *
 * @param url The address from the server.
 */
export function isLinkUrl( url: string ): boolean {
	if ( /[\u0000- \u007f\\]/.test( url ) ) {
		return false;
	}

	return url.startsWith( '/' ) ? ! url.startsWith( '//' ) : /^https?:\/\/[^/?#]+/i.test( url );
}
