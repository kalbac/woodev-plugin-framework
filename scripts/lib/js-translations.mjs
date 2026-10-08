/**
 * Shared knowledge of how the framework's admin JS bundles get their translations (#1032).
 *
 * `@wordpress/i18n` in a built bundle only knows what `wp_set_script_translations()` handed it,
 * and WordPress looks for that JSON by HANDLE first: `{domain}-{locale}-{handle}.json` (see
 * `_load_script_textdomain_from_src()` in wp-includes/l10n.php, `$handle_filename`). The md5-of-path
 * name `wp i18n make-json` produces can never match here, because the framework is vendored at a
 * different relative path in every plugin. So the files are named by handle, and this table is
 * the one place that says which bundle directory belongs to which handle.
 *
 * Consumers: `scripts/build-js-translations.mjs` (writes the files) and
 * `scripts/lint-js-i18n.mjs` (checks them against the `.po`).
 *
 * @package woodev-plugin-framework
 */

import { join } from 'node:path';

export const DOMAIN = 'woodev-plugin-framework';

/** Built bundle directory (`woodev/assets/build/<dir>/index.js`) => the handle PHP enqueues it as. */
export const BUNDLE_HANDLES = {
	'license-page': 'woodev-license-app',
	'plugins-page': 'woodev-plugins-app',
	'setup-wizard': 'woodev-setup-wizard',
	'settings-page': 'woodev-settings-page',
	'shipping-orders-page': 'woodev-shipping-orders-page',
	'shipping-zone-city-limit': 'woodev-city-limit',
	'ui-kit-gallery': 'woodev-ui-kit-gallery',
};

/** `assets/build/settings-page/index.js` (or with a `:line` suffix) => `settings-page`, else null. */
export function bundleOfReference( reference ) {
	const m = /^assets\/build\/([^/]+)\/index\.js(?::\d+)?$/.exec( reference );
	return m ? m[ 1 ] : null;
}

/** The locale a catalogue file name carries: `woodev-plugin-framework-ru_RU.po` => `ru_RU`. */
export function localeOfPo( poPath ) {
	const m = /-([a-z]{2,3}(?:_[A-Z]{2})?)\.po$/.exec( poPath );
	if ( ! m ) {
		throw new Error( `Cannot read the locale from "${ poPath }".` );
	}
	return m[ 1 ];
}

export function jsonFileName( locale, handle ) {
	return `${ DOMAIN }-${ locale }-${ handle }.json`;
}

export function jsonFilePath( root, locale, handle ) {
	return join( root, 'woodev/languages', jsonFileName( locale, handle ) );
}

/**
 * The Jed `locale_data.messages` body (header excluded) each bundle must ship, computed straight
 * from the parsed `.po`: an entry belongs to a bundle when one of its `#:` references points at
 * that bundle's `index.js`, and only TRANSLATED entries are kept — an empty msgstr in the JSON
 * would mean "no translation" and is worth nothing but bytes on every admin page load.
 *
 * Returns `Map<bundleDir, { [key]: string[] }>`; a bundle with nothing translated is absent.
 * Keys follow Jed/`make-json`: `msgctxt + "\x04" + msgid`, or the bare msgid.
 */
export function expectedBundleMessages( { entries } ) {
	const byBundle = new Map();

	for ( const entry of entries ) {
		const forms = entry.msgidPlural !== null ? entry.msgstrPlural || [] : [ entry.msgstr || '' ];

		if ( forms.every( ( s ) => ! s ) ) continue;

		const key = entry.msgctxt ? `${ entry.msgctxt }\x04${ entry.msgid }` : entry.msgid;
		const bundles = new Set();

		for ( const line of entry.references ) {
			for ( const ref of line.split( /\s+/ ).filter( Boolean ) ) {
				const dir = bundleOfReference( ref );
				if ( dir ) bundles.add( dir );
			}
		}

		for ( const dir of bundles ) {
			if ( ! byBundle.has( dir ) ) byBundle.set( dir, {} );
			byBundle.get( dir )[ key ] = forms.map( ( s ) => s || '' );
		}
	}

	return byBundle;
}
