/**
 * The settings body inside a marketplace plugin's own «Настройки» page.
 *
 * The marketplace routing bundle (`src/marketplace-admin/`) owns the route and asks for a body through the
 * `woodev.marketplace.page_view` filter; this bundle answers for pages of kind `settings`. The body is the same
 * React app the Woodev page mounts, told which plugin's providers to list.
 *
 * @package woodev-plugin-framework
 */

import { createElement } from '@wordpress/element';
import type { ComponentType, ReactElement } from 'react';
import App from './app';

export const PAGE_VIEW_FILTER = 'woodev.marketplace.page_view';
export const SETTINGS_PAGE_KIND = 'settings';

interface SettingsPageViewProps {
	/** The manifest menu the page belongs to; `pluginId` names the placement. */
	menu: { pluginId: string };
}

// The routing bundle's view props are its own; this bundle only hands a component back.
type PageView = ComponentType< any >;

/**
 * The body of a settings page: the app, bootstrapped for the menu's plugin.
 *
 * `key` remounts the app when wc-admin moves from one plugin's settings to another's, so it never shows the first
 * plugin's tabs under the second's route.
 *
 * @param props      The view props the routing bundle passes.
 * @param props.menu The manifest menu the page belongs to.
 */
export function SettingsPageView( {
	menu,
}: SettingsPageViewProps ): ReactElement {
	const { restRoot, nonce } = window.woodevSettings || {};

	return createElement( App, {
		key: menu.pluginId,
		bootstrap: { placement: menu.pluginId, restRoot, nonce },
	} );
}

/**
 * `woodev.marketplace.page_view` callback: supplies the body for a `settings` page, leaves every other page alone.
 *
 * @param view The view an earlier callback chose, if any.
 * @param page The page being opened.
 */
export function resolveSettingsView(
	view: PageView | null,
	page: { kind?: string } | null
): PageView | null {
	if ( view || ! page || SETTINGS_PAGE_KIND !== page.kind ) {
		return view;
	}

	return SettingsPageView;
}
