/**
 * Reads the page manifest PHP published as `window.woodevMarketplaceAdmin`.
 *
 * @package woodev-plugin-framework
 */

import type { Manifest } from './types';

/**
 * The manifest, or an empty one when PHP published none (the bundle then adds no route).
 *
 * @param source The window to read from; a parameter so a test can pass its own.
 */
export function readManifest( source: Pick< Window, 'woodevMarketplaceAdmin' > = window ): Manifest {
	const manifest = source.woodevMarketplaceAdmin;

	if ( ! manifest || ! Array.isArray( manifest.menus ) ) {
		return { menus: [] };
	}

	return manifest;
}
