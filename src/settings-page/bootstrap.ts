/**
 * Where the settings body gets its REST root, nonce and placement.
 *
 * The Woodev → Настройки page mounts with no props and reads the `window.woodevSettings` global PHP printed. A body
 * embedded in a marketplace plugin's own page is handed the same three values as props (`placement` = the marketplace
 * plugin whose providers it lists) and registers them here, so the REST client reads one place either way.
 *
 * @package woodev-plugin-framework
 */

export interface SettingsBootstrap {
	/** The marketplace plugin whose providers to list; absent on the Woodev page. */
	placement?: string;
	restRoot?: string;
	nonce?: string;
	adminUrl?: string;
}

declare global {
	interface Window {
		woodevSettings?: SettingsBootstrap;
	}
}

let embedded: SettingsBootstrap | null = null;

/**
 * Registers the props of an embedded body, or clears them (`null`).
 *
 * @param props Bootstrap props of the embedding page.
 */
export function setBootstrap( props: SettingsBootstrap | null ): void {
	embedded = props;
}

/**
 * The bootstrap in force: the embedded props, else the page global.
 */
export function getBootstrap(): SettingsBootstrap {
	return embedded || window.woodevSettings || {};
}
